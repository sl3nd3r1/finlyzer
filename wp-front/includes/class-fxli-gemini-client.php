<?php
declare(strict_types=1);

// prevent direct script access
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Client for communicating with the serverless Gemini AI Worker proxy.
 * Transmits strictly aggregated metrics (never PII or customer records),
 * enforces HMAC authentication, and delivers an intelligent warning heuristic fallback
 * if the Worker is unconfigured or offline.
 */
final class FXLI_Gemini_Client {

	private static ?self $instance = null;

	// singleton accessor
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	// resolve Cloudflare Worker endpoint URL via FXLI_Env with security validation
	private function worker_endpoint(): string {
		$endpoint = class_exists('FXLI_Env') ? FXLI_Env::worker_endpoint() : '';

		if ($endpoint === '') {
			$endpoint = defined('FINLYZER_WORKER_ENDPOINT') ? (string) FINLYZER_WORKER_ENDPOINT : (defined('FXLI_WORKER_ENDPOINT') ? (string) FXLI_WORKER_ENDPOINT : '');
			// auto-detect local development environment (e.g. XAMPP on localhost or 127.0.0.1)
			if ($endpoint === '') {
				$is_local = (function_exists('wp_get_environment_type') && in_array(wp_get_environment_type(), ['development', 'local'], true))
					|| (function_exists('home_url') && (str_contains(home_url(), 'localhost') || str_contains(home_url(), '127.0.0.1')));
				if ($is_local) {
					$endpoint = 'http://127.0.0.1:8787/insight';
				}
			}
		}

		// validate URL against SSRF and protocol policies
		if ($endpoint !== '' && class_exists('FXLI_Env')) {
			$validation = FXLI_Env::validate_endpoint_url($endpoint);
			if (is_wp_error($validation)) {
				error_log('[Finlyzer Security] Blocked insecure worker endpoint: ' . $validation->get_error_message());
				return '';
			}
		}

		return apply_filters('finlyzer_worker_endpoint', apply_filters('fxli_worker_endpoint', $endpoint));
	}

	// request or generate an AI risk warning insight based on order aggregates and order mutation state
	public function summarize(array $summary, bool $force_refresh = false): string|WP_Error {
		$days = max(7, min(90, (int) ($summary['period_days'] ?? 30)));
		$option_key = "finlyzer_ai_insight_{$days}";

		// inspect query parameters for administrative refresh trigger
		if (!$force_refresh && (!empty($_GET['refresh']) || !empty($_GET['force']))) {
			$force_refresh = true;
		}

		// calculate current order state fingerprint to detect newly arrived or modified orders
		$current_fingerprint = class_exists('FXLI_Order_Analyzer')
			? FXLI_Order_Analyzer::instance()->get_order_state_fingerprint($days)
			: hash('sha256', (string) ($summary['order_count'] ?? 0) . ':' . (string) ($summary['total_loss'] ?? 0));

		// if refresh is not forced, evaluate persisted analysis for unchanged order state
		if (!$force_refresh) {
			$persisted = get_option($option_key);
			if (is_array($persisted) && !empty($persisted['insight']) && isset($persisted['order_fingerprint'])) {
				// if store orders have NOT changed since last analysis, return persisted insight (0 API calls, 0 tokens)
				if ($persisted['order_fingerprint'] === $current_fingerprint) {
					$cached_text = (string) $persisted['insight'];
					$is_fallback = str_contains($cached_text, 'in processor conversion fees') || str_contains($cached_text, 'All transactions in the last');
					$is_dev = class_exists('FXLI_Env') && FXLI_Env::is_development();
					if (!$is_dev || !$is_fallback) {
						return $cached_text;
					}
				}
			}
		}

		// generate deterministic transient key based on aggregated summary values
		$cache_key = 'finlyzer_insight_' . md5(wp_json_encode($summary));

		$endpoint = $this->worker_endpoint();

		// if worker is unconfigured, handle according to environment policies
		if ($endpoint === '') {
			// in production with forced API calculation, fail closed
			if (class_exists('FXLI_Env') && FXLI_Env::is_production() && FXLI_Env::force_api_calculation()) {
				return new WP_Error('worker_unconfigured', __('Finlyzer AI Risk Sentinel API endpoint is not configured.', 'finlyzer'));
			}
			$fallback = $this->generate_heuristic_warning($summary);
			$ttl = (class_exists('FXLI_Env') && FXLI_Env::is_development()) ? 10 : 6 * HOUR_IN_SECONDS;
			set_transient($cache_key, $fallback, $ttl);

			// persist fallback with fingerprint
			update_option($option_key, [
				'insight'           => $fallback,
				'order_fingerprint' => $current_fingerprint,
				'order_count'       => (int) ($summary['order_count'] ?? 0),
				'total_loss'        => (float) ($summary['total_loss'] ?? 0.0),
				'generated_at'      => time(),
			], false);

			return $fallback;
		}

		// sanitize and structure the outgoing request payload strictly
		$by_currency_rows = [];
		if (!empty($summary['by_currency']) && is_array($summary['by_currency'])) {
			foreach (array_slice($summary['by_currency'], 0, 10) as $row) {
				$by_currency_rows[] = [
					'currency' => FXLI_Security::sanitize_prompt_scalar($row['currency'] ?? ''),
					'orders'   => (int) ($row['orders'] ?? 0),
					'loss'     => (float) ($row['loss'] ?? 0.0),
				];
			}
		}

		$site_url = function_exists('home_url') ? home_url() : '';
		$plugin_version = defined('FINLYZER_VERSION') ? FINLYZER_VERSION : '1.29.0';

		$payload = [
			'site_id'        => self::site_id(),
			'site_url'       => $site_url,
			'plugin_version' => $plugin_version,
			'store_currency' => FXLI_Security::sanitize_prompt_scalar($summary['store_currency'] ?? 'USD'),
			'period_days'    => (int) ($summary['period_days'] ?? 30),
			'total_loss'     => (float) ($summary['total_loss'] ?? 0.0),
			'order_count'    => (int) ($summary['order_count'] ?? 0),
			'by_currency'    => $by_currency_rows,
		];

		// encode body as JSON
		$body = wp_json_encode($payload);
		if ($body === false) {
			return $this->generate_heuristic_warning($summary);
		}

		// generate timestamped HMAC signature
		$timestamp = time();
		$signature = FXLI_Security::sign_worker_payload($body, $timestamp);
		if ($signature === '') {
			// fallback cleanly without breaking merchant view
			return $this->generate_heuristic_warning($summary);
		}

		$timeout = class_exists('FXLI_Env') ? FXLI_Env::api_timeout() : 8;
		$strict_ssl = class_exists('FXLI_Env') ? FXLI_Env::strict_ssl() : true;

		$t0 = microtime(true);
		// dispatch server-to-server POST request to the Cloudflare Worker
		$response = wp_remote_post($endpoint, [
			'timeout'   => $timeout,
			'sslverify' => $strict_ssl,
			'headers'   => [
				'Content-Type'      => 'application/json',
				'X-FXLI-Site'       => self::site_id(),
				'X-FXLI-Site-Url'   => $site_url,
				'X-FXLI-Version'    => $plugin_version,
				'X-FXLI-Time'       => (string) $timestamp,
				'X-FXLI-Sig'        => $signature,
			],
			'body'      => $body,
		]);
		$duration = round((microtime(true) - $t0) * 1000, 2);

		// on network or HTTP error, evaluate environment calculation policies
		if (is_wp_error($response)) {
			if (class_exists('FXLI_Logger')) {
				FXLI_Logger::log_http_call($endpoint, 'POST', 'ERR', $duration, $response->get_error_message(), ['type' => 'insight']);
			}
			error_log('[Finlyzer Insight Network Error] ' . $response->get_error_message());
			if (class_exists('FXLI_Env') && FXLI_Env::is_production() && FXLI_Env::force_api_calculation()) {
				return new WP_Error('worker_http_error', __('Risk analysis service is temporarily unavailable.', 'finlyzer'));
			}
			$fallback = $this->generate_heuristic_warning($summary);
			$ttl = (class_exists('FXLI_Env') && FXLI_Env::is_development()) ? 10 : HOUR_IN_SECONDS;
			set_transient($cache_key, $fallback, $ttl);
			return $fallback;
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if (class_exists('FXLI_Logger')) {
			FXLI_Logger::log_http_call($endpoint, 'POST', $code, $duration, $code === 200 ? null : wp_remote_retrieve_body($response), ['type' => 'insight']);
		}

		if ($code !== 200) {
			error_log(sprintf('[Finlyzer Insight Error] HTTP %d', $code));
			if (class_exists('FXLI_Env') && FXLI_Env::is_production() && FXLI_Env::force_api_calculation()) {
				return new WP_Error('worker_http_error', __('Risk analysis service is temporarily unavailable.', 'finlyzer'));
			}
			$fallback = $this->generate_heuristic_warning($summary);
			$ttl = (class_exists('FXLI_Env') && FXLI_Env::is_development()) ? 10 : HOUR_IN_SECONDS;
			set_transient($cache_key, $fallback, $ttl);
			return $fallback;
		}

		// parse response body
		$data = json_decode(wp_remote_retrieve_body($response), true);
		$insight = is_array($data) && isset($data['insight']) ? (string) $data['insight'] : '';

		// defense in depth: strip all HTML tags and cap length
		$insight = wp_strip_all_tags($insight);
		$insight = mb_substr(trim($insight), 0, 600);

		// ensure non-empty insight or fallback
		if ($insight === '') {
			$insight = $this->generate_heuristic_warning($summary);
		}

		// cache successful insight in transients
		set_transient($cache_key, $insight, 12 * HOUR_IN_SECONDS);

		// persist newly generated AI insight with current order state fingerprint
		update_option($option_key, [
			'insight'           => $insight,
			'order_fingerprint' => $current_fingerprint,
			'order_count'       => (int) ($summary['order_count'] ?? 0),
			'total_loss'        => (float) ($summary['total_loss'] ?? 0.0),
			'generated_at'      => time(),
		], false);

		return $insight;
	}

	// retrieve metadata for the last persisted AI analysis of the given timeframe
	public function get_last_analysis_meta(int $days = 30): ?array {
		$days = max(7, min(90, $days));
		$persisted = get_option("finlyzer_ai_insight_{$days}");
		if (!is_array($persisted) || empty($persisted['insight'])) {
			return null;
		}

		$current_fingerprint = class_exists('FXLI_Order_Analyzer')
			? FXLI_Order_Analyzer::instance()->get_order_state_fingerprint($days)
			: '';

		return [
			'insight'           => (string) $persisted['insight'],
			'order_fingerprint' => (string) ($persisted['order_fingerprint'] ?? ''),
			'order_count'       => (int) ($persisted['order_count'] ?? 0),
			'total_loss'        => (float) ($persisted['total_loss'] ?? 0.0),
			'generated_at'      => (int) ($persisted['generated_at'] ?? 0),
			'is_up_to_date'     => ($current_fingerprint !== '' && ($persisted['order_fingerprint'] ?? '') === $current_fingerprint),
		];
	}

	// generate an immediate, data-backed financial risk warning when AI Worker is offline/unconfigured
	private function generate_heuristic_warning(array $summary): string {
		$total_loss = (float) ($summary['total_loss'] ?? 0.0);
		$currency = (string) ($summary['store_currency'] ?? 'USD');
		$days = (int) ($summary['period_days'] ?? 30);
		$orders = (int) ($summary['order_count'] ?? 0);
		$run_rate = (float) ($summary['annualized_run_rate'] ?? (($total_loss / max(1, $days)) * 365));
		$avg_per_order = (float) ($summary['avg_loss_per_order'] ?? ($orders > 0 ? $total_loss / $orders : 0));
		$top_curr = (string) ($summary['top_currency'] ?? '');

		// optimal state: no cross-border leakage
		if ($total_loss <= 0.0 || $orders === 0) {
			return sprintf(
				/* translators: %d: period in days */
				__('All transactions in the last %d days settled in your base currency. No processor conversion fees or exchange rate shifts detected.', 'finlyzer'),
				$days
			);
		}

		// critical or elevated risk warning
		$formatted_loss = function_exists('wc_price') ? wp_strip_all_tags(wc_price($total_loss, ['currency' => $currency])) : sprintf('%.2f %s', $total_loss, $currency);
		$formatted_run_rate = function_exists('wc_price') ? wp_strip_all_tags(wc_price($run_rate, ['currency' => $currency])) : sprintf('%.2f %s', $run_rate, $currency);
		$formatted_avg = function_exists('wc_price') ? wp_strip_all_tags(wc_price($avg_per_order, ['currency' => $currency])) : sprintf('%.2f %s', $avg_per_order, $currency);

		$top_curr_note = $top_curr !== '' ? sprintf(' %s settlements drove the highest fee impact.', $top_curr) : '';

		return sprintf(
			/* translators: 1: total loss, 2: order count, 3: days, 4: avg loss per order, 5: top currency note, 6: annual run-rate */
			__('Cross-border transactions incurred an estimated %1$s in processor conversion fees across %2$d orders (~%4$s avg).%5$s Projected 12-month margin impact is approximately %6$s.', 'finlyzer'),
			$formatted_loss,
			$orders,
			$days,
			$formatted_avg,
			$top_curr_note,
			$formatted_run_rate
		);
	}

	// resolve Cloudflare Worker order analysis endpoint URL via FXLI_Env with security validation
	public static function analyze_endpoint(): string {
		$endpoint = class_exists('FXLI_Env') ? FXLI_Env::analyze_endpoint() : '';

		if ($endpoint === '') {
			if (defined('FINLYZER_WORKER_ANALYZE_ENDPOINT') && is_string(FINLYZER_WORKER_ANALYZE_ENDPOINT) && FINLYZER_WORKER_ANALYZE_ENDPOINT !== '') {
				$endpoint = (string) FINLYZER_WORKER_ANALYZE_ENDPOINT;
			} else {
				$base = defined('FINLYZER_WORKER_ENDPOINT') ? (string) FINLYZER_WORKER_ENDPOINT : (defined('FXLI_WORKER_ENDPOINT') ? (string) FXLI_WORKER_ENDPOINT : '');
				if ($base === '' && function_exists('get_option')) {
					$base = (string) get_option('finlyzer_worker_endpoint', '');
				}
				if ($base === '') {
					$is_local = (function_exists('wp_get_environment_type') && in_array(wp_get_environment_type(), ['development', 'local'], true))
						|| (function_exists('home_url') && (str_contains(home_url(), 'localhost') || str_contains(home_url(), '127.0.0.1')));
					if ($is_local) {
						$base = 'http://127.0.0.1:8787';
					}
				}
				if ($base !== '') {
					$clean_base = preg_replace('#(/api/v1)?/insight/?$#', '', rtrim($base, '/'));
					$endpoint = $clean_base . '/api/v1/analyze';
				}
			}
		}

		// validate URL against SSRF and protocol policies
		if ($endpoint !== '' && class_exists('FXLI_Env')) {
			$validation = FXLI_Env::validate_endpoint_url($endpoint);
			if (is_wp_error($validation)) {
				error_log('[Finlyzer Security] Blocked insecure analyze endpoint: ' . $validation->get_error_message());
				return '';
			}
		}

		return apply_filters('finlyzer_worker_analyze_endpoint', $endpoint);
	}

	// delegate store order calculation directly to the Cloudflare Worker backend
	public function analyze_orders(array $payload): array|WP_Error {
		$endpoint = self::analyze_endpoint();
		if ($endpoint === '') {
			return new WP_Error('worker_unconfigured', __('Cloudflare Worker analysis endpoint is unconfigured or blocked by security policy.', 'finlyzer'));
		}

		// attach site authentication metadata to payload
		$payload['site_id'] = self::site_id();
		$payload['site_url'] = function_exists('home_url') ? home_url() : '';
		$payload['plugin_version'] = defined('FINLYZER_VERSION') ? FINLYZER_VERSION : '1.29.0';

		$body = wp_json_encode($payload);
		if ($body === false) {
			return new WP_Error('json_encode_error', __('Failed to encode order analysis payload.', 'finlyzer'));
		}

		// generate timestamped HMAC signature
		$timestamp = time();
		$signature = FXLI_Security::sign_worker_payload($body, $timestamp);
		if ($signature === '') {
			return new WP_Error('hmac_unconfigured', __('Worker HMAC signing secret is unconfigured or has insufficient entropy.', 'finlyzer'));
		}

		$timeout = class_exists('FXLI_Env') ? FXLI_Env::api_timeout() : 12;
		$strict_ssl = class_exists('FXLI_Env') ? FXLI_Env::strict_ssl() : true;

		$t0 = microtime(true);
		// dispatch server-to-server POST request to the Cloudflare Worker analysis engine
		$response = wp_remote_post($endpoint, [
			'timeout'   => $timeout,
			'sslverify' => $strict_ssl,
			'headers'   => [
				'Content-Type'      => 'application/json',
				'X-FXLI-Site'       => self::site_id(),
				'X-FXLI-Site-Url'   => $payload['site_url'],
				'X-FXLI-Version'    => $payload['plugin_version'],
				'X-FXLI-Time'       => (string) $timestamp,
				'X-FXLI-Sig'        => $signature,
			],
			'body'      => $body,
		]);
		$duration = round((microtime(true) - $t0) * 1000, 2);

		if (is_wp_error($response)) {
			if (class_exists('FXLI_Logger')) {
				FXLI_Logger::log_http_call($endpoint, 'POST', 'ERR', $duration, $response->get_error_message(), [
					'type'        => 'analyze',
					'order_count' => count($payload['orders'] ?? []),
					'currency'    => $payload['store_currency'] ?? 'USD',
				]);
			}
			error_log('[Finlyzer Analysis Network Error] ' . $response->get_error_message());
			return new WP_Error('worker_http_error', __('Unable to reach calculation service. Please verify server connectivity.', 'finlyzer'));
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$raw_body = wp_remote_retrieve_body($response);

		if (class_exists('FXLI_Logger')) {
			FXLI_Logger::log_http_call($endpoint, 'POST', $code, $duration, $code === 200 ? null : substr($raw_body, 0, 256), [
				'type'        => 'analyze',
				'order_count' => count($payload['orders'] ?? []),
				'currency'    => $payload['store_currency'] ?? 'USD',
			]);
		}

		if ($code !== 200) {
			// securely log full response details internally for administrator diagnostics
			error_log(sprintf('[Finlyzer Analysis Error] HTTP %d: %s', $code, substr($raw_body, 0, 512)));
			return new WP_Error('worker_http_error', __('Calculation service is temporarily unavailable. Please retry shortly.', 'finlyzer'));
		}

		$data = json_decode($raw_body, true);
		if (!is_array($data) || !isset($data['total_loss'])) {
			return new WP_Error('invalid_worker_response', __('Malformed financial analysis response from Worker.', 'finlyzer'));
		}

		return $data;
	}

	// static helper to probe handshake safely via singleton instance
	public static function probe_handshake(?string $custom_secret = null): array {
		return self::instance()->verify_handshake($custom_secret);
	}

	// execute an authenticated HMAC handshake probe against the Cloudflare Worker verify endpoint
	public function verify_handshake(?string $custom_secret = null): array {
		$secret = $custom_secret !== null && $custom_secret !== '' ? trim($custom_secret) : FXLI_Security::worker_shared_secret();

		if ($secret === '') {
			return [
				'success' => false,
				'error'   => __('HMAC secret is not configured.', 'finlyzer'),
				'message' => __('Please enter or generate a valid HMAC secret first.', 'finlyzer'),
			];
		}

		$base = $this->worker_endpoint();
		if ($base === '') {
			return [
				'success' => false,
				'error'   => __('Worker endpoint is not configured.', 'finlyzer'),
				'message' => __('Worker endpoint URL is missing.', 'finlyzer'),
			];
		}

		// derive canonical /api/v1/verify endpoint
		$clean_base = preg_replace('#(/api/v1)?/(insight|analyze)/?$#', '', rtrim($base, '/'));
		$verify_url = $clean_base . '/api/v1/verify';

		$site_url = function_exists('home_url') ? home_url() : 'http://localhost';
		$plugin_version = defined('FINLYZER_VERSION') ? FINLYZER_VERSION : '1.29.0';
		$timestamp = time();
		$body = wp_json_encode(['action' => 'verify', 'timestamp' => $timestamp]);
		if ($body === false) {
			$body = '{}';
		}

		// sign payload with timestamp prefix
		$signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

		$timeout = class_exists('FXLI_Env') ? FXLI_Env::api_timeout() : 6;
		$strict_ssl = class_exists('FXLI_Env') ? FXLI_Env::strict_ssl() : true;

		$t0 = microtime(true);
		$response = wp_remote_post($verify_url, [
			'timeout'   => $timeout,
			'sslverify' => $strict_ssl,
			'headers'   => [
				'Content-Type'    => 'application/json',
				'X-FXLI-Site'     => self::site_id(),
				'X-FXLI-Site-Url' => $site_url,
				'X-FXLI-Version'  => $plugin_version,
				'X-FXLI-Time'     => (string) $timestamp,
				'X-FXLI-Sig'      => $signature,
			],
			'body'      => $body,
		]);
		$duration = round((microtime(true) - $t0) * 1000, 2);

		if (is_wp_error($response)) {
			return [
				'success'     => false,
				'endpoint'    => $verify_url,
				'latency_ms'  => $duration,
				'error'       => $response->get_error_message(),
				'message'     => sprintf(__('Network connection failed: %s', 'finlyzer'), $response->get_error_message()),
			];
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$raw_body = wp_remote_retrieve_body($response);
		$data = json_decode($raw_body, true);

		if ($code === 200 && is_array($data) && !empty($data['verified'])) {
			return [
				'success'            => true,
				'status_code'        => $code,
				'endpoint'           => $verify_url,
				'latency_ms'         => $duration,
				'message'            => $data['message'] ?? __('HMAC handshake authenticated successfully.', 'finlyzer'),
				'site_id'            => $data['site_id'] ?? self::site_id(),
				'clock_skew_seconds' => $data['clock_skew_seconds'] ?? 0,
				'environment'        => $data['environment'] ?? 'production',
			];
		}

		// handle authentication mismatch
		if ($code === 401) {
			return [
				'success'     => false,
				'status_code' => 401,
				'endpoint'    => $verify_url,
				'latency_ms'  => $duration,
				'error'       => 'invalid_signature',
				'message'     => __('Authentication failed (HTTP 401): HMAC secret does not match Worker.', 'finlyzer'),
			];
		}

		// handle blocked site
		if ($code === 403) {
			return [
				'success'     => false,
				'status_code' => 403,
				'endpoint'    => $verify_url,
				'latency_ms'  => $duration,
				'error'       => 'site_blocked',
				'message'     => __('Site suspended (HTTP 403): This site installation is blocked in the backoffice.', 'finlyzer'),
			];
		}

		return [
			'success'     => false,
			'status_code' => $code,
			'endpoint'    => $verify_url,
			'latency_ms'  => $duration,
			'error'       => "http_{$code}",
			'message'     => sprintf(__('Worker returned unexpected status HTTP %d.', 'finlyzer'), $code),
		];
	}

	// purge all cached insights to ensure real-time synchronization
	public static function flush_cache(): void {
		// delete any transient starting with finlyzer_insight_
		global $wpdb;
		if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'query') && isset($wpdb->options)) {
			$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_finlyzer_insight_%' OR option_name LIKE '_transient_timeout_finlyzer_insight_%'");
		}
	}

	// generate a stable, non-PII site hash identifier
	public static function site_id(): string {
		$home = function_exists('home_url') ? home_url() : 'http://localhost';
		$salted = (defined('AUTH_KEY') ? AUTH_KEY : 'finlyzer_salt') . '|' . $home;
		return hash('sha256', $salted);
	}
}
