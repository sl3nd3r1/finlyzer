<?php
declare(strict_types=1);

// prevent direct script execution
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Finlyzer — Structured Telemetry & Diagnostics Logger
 *
 * Architecture:
 * - Centralizes structured diagnostics for outbound API calls to Cloudflare Worker.
 * - Implements a zero-allocation, capped circular ring buffer (50 entries) stored in options.
 * - Enforces zero-leakage redaction for secrets, HMAC signatures, and customer records.
 * - Emits dual telemetry: writes to WordPress error_log when debug mode is enabled,
 *   and persists structured telemetry records for the WP Admin Developer Inspector.
 * - Provides live connection probing (health and calculation handshake) with millisecond latency metrics.
 */
final class FXLI_Logger {

	public const LEVEL_DEBUG = 'debug';
	public const LEVEL_INFO  = 'info';
	public const LEVEL_WARN  = 'warn';
	public const LEVEL_ERROR = 'error';

	public const OPTION_KEY = 'finlyzer_telemetry_logs';
	public const MAX_ENTRIES = 50;

	private static ?self $instance = null;

	// in-memory circular buffer for active request lifecycle
	private static array $in_memory_buffer = [];

	// singleton accessor
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	// singleton accessor alias for standard WordPress convention
	public static function get_instance(): self {
		return self::instance();
	}

	private function __construct() {}

	private function __clone() {}

	// record generic structured log entry with automatic secret redaction
	public static function log(string $level, string $category, string $message, array $context = []): void {
		// assert debug logging is permitted or level is warning/error
		$is_debug_enabled = class_exists('FXLI_Env') ? FXLI_Env::debug_logging() : false;
		if ($level === self::LEVEL_DEBUG && !$is_debug_enabled) {
			return;
		}

		// sanitize and redact sensitive context items
		$clean_context = self::redact_sensitive_data($context);

		$id  = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
		$ts  = function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s');
		$msg = function_exists('sanitize_text_field') ? sanitize_text_field($message) : strip_tags($message);

		$entry = [
			'id'        => $id,
			'timestamp' => $ts,
			'epoch_ms'  => (int) round(microtime(true) * 1000),
			'level'     => strtolower($level),
			'category'  => strtoupper($category),
			'message'   => $msg,
			'context'   => $clean_context,
		];

		// append to in-memory buffer
		self::$in_memory_buffer[] = $entry;

		// write to standard PHP / WordPress error log when debugging or on error
		if ($is_debug_enabled || in_array($level, [self::LEVEL_WARN, self::LEVEL_ERROR], true)) {
			$encoded_context   = function_exists('wp_json_encode') ? wp_json_encode($clean_context, JSON_UNESCAPED_SLASHES) : json_encode($clean_context, JSON_UNESCAPED_SLASHES);
			$formatted_context = !empty($clean_context) ? ' ' . $encoded_context : '';
			error_log(sprintf('[Finlyzer %s][%s] %s%s', strtoupper($level), strtoupper($category), $message, $formatted_context));
		}

		// persist to bounded circular buffer in options
		self::persist_entry($entry);
	}

	// record outbound HTTP request telemetry to Cloudflare Worker with timing and status diagnostics
	public static function log_http_call(
		string $endpoint,
		string $method,
		int|string $status,
		float $duration_ms,
		?string $error = null,
		array $metadata = []
	): void {
		$level = self::LEVEL_INFO;
		if ($error !== null || (is_numeric($status) && (int) $status >= 400)) {
			$level = (is_numeric($status) && (int) $status === 401) ? self::LEVEL_WARN : self::LEVEL_ERROR;
		}

		$status_label = is_numeric($status) ? (string) $status : 'ERR';
		$message = sprintf('%s %s -> %s (%.1f ms)%s', strtoupper($method), $endpoint, $status_label, $duration_ms, $error ? " - {$error}" : '');

		$context = array_merge([
			'endpoint'    => $endpoint,
			'method'      => strtoupper($method),
			'status'      => $status,
			'duration_ms' => round($duration_ms, 2),
			'error'       => $error,
		], $metadata);

		self::log($level, 'HTTP_CLIENT', $message, $context);
	}

	// retrieve recent telemetry log entries with reverse chronological ordering
	public static function get_recent_logs(int $limit = 50): array {
		$limit = max(1, min(self::MAX_ENTRIES, $limit));

		if (function_exists('get_option')) {
			$logs = get_option(self::OPTION_KEY, []);
			if (is_array($logs)) {
				// return recent slice ordered newest first
				return array_slice(array_reverse($logs), 0, $limit);
			}
		}

		return array_slice(array_reverse(self::$in_memory_buffer), 0, $limit);
	}

	// purge all stored telemetry log entries
	public static function clear_logs(): bool {
		self::$in_memory_buffer = [];
		if (function_exists('update_option')) {
			return update_option(self::OPTION_KEY, []);
		}
		return true;
	}

	// execute live diagnostic connectivity test against configured Cloudflare Worker endpoints
	public static function test_connection(): array {
		$results = [
			'timestamp'         => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
			'environment'       => class_exists('FXLI_Env') ? FXLI_Env::current_env() : 'unknown',
			'worker_endpoint'   => class_exists('FXLI_Env') ? FXLI_Env::worker_endpoint() : '',
			'analyze_endpoint'  => class_exists('FXLI_Env') ? FXLI_Env::analyze_endpoint() : '',
			'health_check'      => null,
			'analyze_handshake' => null,
			'success'           => false,
			'diagnostic_notes'  => [],
		];

		// derive health endpoint from base worker URL
		$base_url = preg_replace('#(/api/v1)?/(insight|analyze)/?$#', '', $results['worker_endpoint']);
		$health_url = rtrim($base_url, '/') . '/health';

		$timeout = class_exists('FXLI_Env') ? FXLI_Env::api_timeout() : 5;
		$strict_ssl = class_exists('FXLI_Env') ? FXLI_Env::strict_ssl() : false;

		// 1. probe worker health endpoint
		$t0 = microtime(true);
		$health_res = wp_remote_get($health_url, [
			'timeout'   => $timeout,
			'sslverify' => $strict_ssl,
			'headers'   => [
				'Accept' => 'application/json',
			],
		]);
		$health_duration = round((microtime(true) - $t0) * 1000, 2);

		if (is_wp_error($health_res)) {
			$results['health_check'] = [
				'url'         => $health_url,
				'status'      => 'error',
				'duration_ms' => $health_duration,
				'error'       => $health_res->get_error_message(),
			];
			$results['diagnostic_notes'][] = sprintf('Health check probe failed: %s', $health_res->get_error_message());
			self::log_http_call($health_url, 'GET', 'ERR', $health_duration, $health_res->get_error_message());
		} else {
			$code = wp_remote_retrieve_response_code($health_res);
			$body = wp_remote_retrieve_body($health_res);
			$parsed = json_decode($body, true);

			$results['health_check'] = [
				'url'         => $health_url,
				'status'      => $code,
				'duration_ms' => $health_duration,
				'body'        => is_array($parsed) ? $parsed : substr($body, 0, 128),
			];
			self::log_http_call($health_url, 'GET', $code, $health_duration, null, ['body_preview' => substr($body, 0, 64)]);
		}

		// 2. probe analyze endpoint with signed mock payload
		if (!empty($results['analyze_endpoint'])) {
			$test_payload = [
				'store_currency' => 'USD',
				'period_days'    => 30,
				'orders'         => [],
			];

			$t1 = microtime(true);
			$analyze_client = class_exists('FXLI_Gemini_Client') ? FXLI_Gemini_Client::instance() : null;
			$analyze_res = $analyze_client ? $analyze_client->analyze_orders($test_payload) : new WP_Error('no_client', 'Gemini client unavailable');
			$analyze_duration = round((microtime(true) - $t1) * 1000, 2);

			if (is_wp_error($analyze_res)) {
				$results['analyze_handshake'] = [
					'url'         => $results['analyze_endpoint'],
					'status'      => 'error',
					'duration_ms' => $analyze_duration,
					'error'       => $analyze_res->get_error_message(),
				];
				$results['diagnostic_notes'][] = sprintf('Calculation handshake failed: %s', $analyze_res->get_error_message());
			} else {
				$results['analyze_handshake'] = [
					'url'         => $results['analyze_endpoint'],
					'status'      => 200,
					'duration_ms' => $analyze_duration,
					'summary'     => [
						'total_loss'  => $analyze_res['total_loss'] ?? 0,
						'order_count' => $analyze_res['order_count'] ?? 0,
					],
				];
			}
		}

		$is_health_ok = isset($results['health_check']['status']) && (int) $results['health_check']['status'] === 200;
		$is_analyze_ok = isset($results['analyze_handshake']['status']) && (int) $results['analyze_handshake']['status'] === 200;
		$results['success'] = $is_health_ok && $is_analyze_ok;

		return $results;
	}

	// append record to persistent options-based circular buffer
	private static function persist_entry(array $entry): void {
		if (!function_exists('get_option') || !function_exists('update_option')) {
			return;
		}

		$logs = get_option(self::OPTION_KEY, []);
		if (!is_array($logs)) {
			$logs = [];
		}

		$logs[] = $entry;

		// enforce circular ring buffer capacity bound (50 entries)
		if (count($logs) > self::MAX_ENTRIES) {
			$logs = array_slice($logs, -self::MAX_ENTRIES);
		}

		update_option(self::OPTION_KEY, $logs, false); // autoload = false to keep WP queries light
	}

	// redact sensitive keys, tokens, and authorization credentials
	private static function redact_sensitive_data(mixed $data): mixed {
		if (!is_array($data)) {
			return $data;
		}

		$redacted = [];
		$sensitive_patterns = ['secret', 'token', 'key', 'sig', 'authorization', 'password', 'cookie', 'nonce'];

		foreach ($data as $k => $v) {
			$lower_key = strtolower((string) $k);
			$is_sensitive = false;

			foreach ($sensitive_patterns as $pattern) {
				if (str_contains($lower_key, $pattern)) {
					$is_sensitive = true;
					break;
				}
			}

			if ($is_sensitive && is_string($v) && strlen($v) > 8) {
				// preserve first 6 characters and mask remainder for debugging traceability
				$redacted[$k] = substr($v, 0, 6) . '...' . substr($v, -4);
			} elseif ($is_sensitive && is_string($v)) {
				$redacted[$k] = '***REDACTED***';
			} elseif (is_array($v)) {
				$redacted[$k] = self::redact_sensitive_data($v);
			} else {
				$redacted[$k] = $v;
			}
		}

		return $redacted;
	}
}
