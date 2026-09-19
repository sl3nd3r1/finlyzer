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

	// resolve Cloudflare Worker endpoint URL
	private function worker_endpoint(): string {
		$endpoint = defined('FINLYZER_WORKER_ENDPOINT') ? FINLYZER_WORKER_ENDPOINT : (defined('FXLI_WORKER_ENDPOINT') ? FXLI_WORKER_ENDPOINT : '');
		return apply_filters('finlyzer_worker_endpoint', apply_filters('fxli_worker_endpoint', $endpoint));
	}

	// request or generate an AI risk warning insight based on order aggregates
	public function summarize(array $summary): string|WP_Error {
		// generate deterministic cache key based on aggregated summary values
		$cache_key = 'finlyzer_insight_' . md5(wp_json_encode($summary));
		$cached = get_transient($cache_key);
		if (is_string($cached) && $cached !== '') {
			return $cached;
		}

		$endpoint = $this->worker_endpoint();

		// if worker is not yet configured, produce an intelligent heuristic risk warning
		if ($endpoint === '') {
			$fallback = $this->generate_heuristic_warning($summary);
			set_transient($cache_key, $fallback, 6 * HOUR_IN_SECONDS);
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
		$plugin_version = defined('FINLYZER_VERSION') ? FINLYZER_VERSION : '1.11.0';

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

		// dispatch server-to-server POST request to the Cloudflare Worker
		$response = wp_remote_post($endpoint, [
			'timeout' => 8,
			'headers' => [
				'Content-Type'      => 'application/json',
				'X-FXLI-Site'       => self::site_id(),
				'X-FXLI-Site-Url'   => $site_url,
				'X-FXLI-Version'    => $plugin_version,
				'X-FXLI-Time'       => (string) $timestamp,
				'X-FXLI-Sig'        => $signature,
			],
			'body'    => $body,
		]);

		// on network or HTTP error, fallback to data-backed heuristic warning
		if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
			$fallback = $this->generate_heuristic_warning($summary);
			set_transient($cache_key, $fallback, HOUR_IN_SECONDS);
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

		// cache successful insight for 12 hours
		set_transient($cache_key, $insight, 12 * HOUR_IN_SECONDS);

		return $insight;
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

	// resolve Cloudflare Worker order analysis endpoint URL
	public static function analyze_endpoint(): string {
		if (defined('FINLYZER_WORKER_ANALYZE_ENDPOINT')) {
			return (string) FINLYZER_WORKER_ANALYZE_ENDPOINT;
		}

		$base = defined('FINLYZER_WORKER_ENDPOINT') ? FINLYZER_WORKER_ENDPOINT : (defined('FXLI_WORKER_ENDPOINT') ? FXLI_WORKER_ENDPOINT : '');
		if ($base === '') {
			return '';
		}

		// strip /api/v1/insight or /insight or trailing slashes to obtain base worker URL
		$clean_base = preg_replace('#(/api/v1)?/insight/?$#', '', rtrim($base, '/'));
		$endpoint = $clean_base . '/api/v1/analyze';

		return apply_filters('finlyzer_worker_analyze_endpoint', $endpoint);
	}

	// delegate store order calculation directly to the Cloudflare Worker backend
	public function analyze_orders(array $payload): array|WP_Error {
		$endpoint = self::analyze_endpoint();
		if ($endpoint === '') {
			return new WP_Error('worker_unconfigured', 'Cloudflare Worker endpoint is unconfigured.');
		}

		// attach site authentication metadata to payload
		$payload['site_id'] = self::site_id();
		$payload['site_url'] = function_exists('home_url') ? home_url() : '';
		$payload['plugin_version'] = defined('FINLYZER_VERSION') ? FINLYZER_VERSION : '1.11.0';

		$body = wp_json_encode($payload);
		if ($body === false) {
			return new WP_Error('json_encode_error', 'Failed to encode order analysis payload.');
		}

		// generate timestamped HMAC signature
		$timestamp = time();
		$signature = FXLI_Security::sign_worker_payload($body, $timestamp);
		if ($signature === '') {
			return new WP_Error('hmac_unconfigured', 'Worker HMAC signing secret is unconfigured.');
		}

		// dispatch server-to-server POST request to the Cloudflare Worker isolate
		$response = wp_remote_post($endpoint, [
			'timeout' => 12,
			'headers' => [
				'Content-Type'    => 'application/json',
				'X-FXLI-Site'     => self::site_id(),
				'X-FXLI-Site-Url' => $payload['site_url'],
				'X-FXLI-Version'  => $payload['plugin_version'],
				'X-FXLI-Time'     => (string) $timestamp,
				'X-FXLI-Sig'      => $signature,
			],
			'body'    => $body,
		]);

		if (is_wp_error($response)) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code($response);
		$raw_body = wp_remote_retrieve_body($response);

		if ($code !== 200) {
			return new WP_Error('worker_http_error', "Worker returned HTTP status {$code}: {$raw_body}");
		}

		$data = json_decode($raw_body, true);
		if (!is_array($data) || !isset($data['total_loss'])) {
			return new WP_Error('invalid_worker_response', 'Malformed financial analysis response from Worker.');
		}

		return $data;
	}

	// generate a stable, non-PII site hash identifier
	public static function site_id(): string {
		$salted = (defined('AUTH_KEY') ? AUTH_KEY : 'finlyzer_salt') . '|' . home_url();
		return hash('sha256', $salted);
	}
}
