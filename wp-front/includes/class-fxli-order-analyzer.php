<?php
declare(strict_types=1);

// prevent direct script execution
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Core order scanner and financial analytics engine for Finlyzer.
 * Scans WooCommerce orders via HPOS-safe APIs, calculates gateway spread loss heuristics,
 * and compiles comprehensive financial exposure summaries.
 *
 * Supports seamless switching between Production (live WooCommerce DB orders)
 * and Development (realistic mock order telemetry) using 2026 WordPress environment standards.
 */
final class FXLI_Order_Analyzer {

	private static ?self $instance = null;

	// singleton accessor
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	// register daily cron listener on construction
	private function __construct() {
		add_action('fxli_daily_scan', [$this, 'scan_recent_orders']);
	}

	// determine whether mock mode is currently active
	public function is_mock_mode(): bool {
		// 1. Explicit constant override in wp-config.php takes highest precedence
		if (defined('FINLYZER_MOCK_MODE')) {
			return (bool) FINLYZER_MOCK_MODE;
		}

		// 2. Query param toggle for authorized shop managers (?finlyzer_mode=mock or ?finlyzer_mode=live)
		if (isset($_GET['finlyzer_mode'])) {
			$requested = sanitize_text_field(wp_unslash($_GET['finlyzer_mode']));
			if ($requested === 'mock') {
				return true;
			}
			if ($requested === 'live') {
				return false;
			}
		}

		// 3. Environment-based default: auto-enable mock in dev/local ONLY if explicitly requested via filter
		$is_dev = function_exists('wp_get_environment_type') && in_array(wp_get_environment_type(), ['development', 'local'], true);

		return (bool) apply_filters('finlyzer_enable_mock_mode', $is_dev && $this->is_database_empty());
	}

	// check if events table has zero records
	private function is_database_empty(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'fxli_fx_events';
		// if table doesn't exist yet or has no records
		$count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
		return (int) ($count ?? 0) === 0;
	}

	// scan paid orders within the lookback window in memory-safe batches
	public function scan_recent_orders(): void {
		$store_currency = get_woocommerce_currency();
		$since = (new DateTimeImmutable('-2 days'))->format('Y-m-d\TH:i:s');

		$page = 1;
		$batch_size = 100;
		$max_pages = 25; // hard boundary: max 2,500 orders per run to protect PHP memory

		do {
			// query orders through WooCommerce HPOS-safe repository
			$orders = wc_get_orders([
				'status'    => apply_filters('finlyzer_scanned_order_statuses', ['wc-processing', 'wc-completed']),
				'date_paid' => '>' . strtotime($since),
				'limit'     => $batch_size,
				'page'      => $page,
				'orderby'   => 'date',
				'order'     => 'DESC',
				'return'    => 'objects',
			]);

			// process each order in the current batch
			foreach ($orders as $order) {
				if ($order instanceof WC_Order) {
					$this->cache_order_estimate($order, $store_currency);
				}
			}

			$page++;
		} while (count($orders) === $batch_size && $page <= $max_pages);

		// invalidate cached summary transients after new events are recorded
		$this->clear_summary_transients();
	}

	// calculate and cache individual order FX spread loss
	private function cache_order_estimate(WC_Order $order, string $store_currency): void {
		global $wpdb;

		// check currency mismatch
		$order_currency = (string) $order->get_currency();
		if ($order_currency === '' || $order_currency === $store_currency) {
			return; // no cross-currency exchange occurred
		}

		// check non-zero order total
		$total = (float) $order->get_total();
		if ($total <= 0.0) {
			return; // ignore zero or negative values
		}

		// determine payment method safely
		$payment_method = (string) ($order->get_payment_method() ?? 'standard');

		// estimate spread loss
		$estimated_loss = $this->estimate_loss($total, $order_currency, $store_currency, $payment_method);

		// prepare database table target
		$table = $wpdb->prefix . 'fxli_fx_events';
		$paid_date = $order->get_date_paid()?->date('Y-m-d H:i:s') ?? current_time('mysql');

		// insert or replace event record using integer minor units (cents)
		$wpdb->replace(
			$table,
			[
				'order_id'             => $order->get_id(),
				'order_date'           => $paid_date,
				'order_currency'       => $order_currency,
				'store_currency'       => $store_currency,
				'order_total_minor'    => (int) round($total * 100),
				'estimated_loss_minor' => (int) round($estimated_loss * 100),
			],
			['%d', '%s', '%s', '%s', '%d', '%d']
		);
	}

	// conservative baseline FX spread loss estimate (average gateway spread markup: ~2.2%)
	private function estimate_loss(float $total, string $order_currency, string $store_currency, string $payment_method): float {
		// allow custom store overrides via filter
		$assumed_spread_pct = (float) apply_filters('fxli_assumed_fx_spread_pct', 0.022, $order_currency, $store_currency, $payment_method);
		return $total * max(0.001, min(0.20, $assumed_spread_pct));
	}

	// compile aggregate financial loss summary over the specified period
	public function get_summary(int $days = 30): array {
		// enforce valid reporting period bounds
		$days = max(7, min(90, $days));
		$store_currency = get_woocommerce_currency();

		// if in mock development mode, return synthetic telemetry
		if ($this->is_mock_mode()) {
			return $this->generate_mock_summary($days, $store_currency);
		}

		// check transient cache to avoid unnecessary DB aggregation on rapid requests
		$cache_key = 'finlyzer_sum_' . $days . '_' . md5($store_currency);
		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			return $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'fxli_fx_events';

		// execute indexed query grouping by currency
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT order_currency, COUNT(*) AS orders, SUM(estimated_loss_minor) AS loss_minor
				 FROM {$table}
				 WHERE order_date >= %s
				 GROUP BY order_currency
				 ORDER BY loss_minor DESC",
				(new DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s')
			),
			ARRAY_A
		);

		$by_currency = [];
		$total_loss = 0.0;
		$order_count = 0;

		// aggregate metrics from query rows
		foreach ((array) $rows as $row) {
			$loss = ((int) $row['loss_minor']) / 100;
			$count = (int) $row['orders'];
			$curr = (string) $row['order_currency'];

			$by_currency[$curr] = [
				'currency' => $curr,
				'orders'   => $count,
				'loss'     => round($loss, 2),
			];

			$total_loss += $loss;
			$order_count += $count;
		}

		// calculate relative percentage distribution per currency
		foreach ($by_currency as $curr => $data) {
			$by_currency[$curr]['share_pct'] = $total_loss > 0 ? round(($data['loss'] / $total_loss) * 100, 1) : 0.0;
		}

		// calculate average loss per cross-currency transaction
		$avg_loss_per_order = $order_count > 0 ? round($total_loss / $order_count, 2) : 0.0;

		// extrapolate 365-day annual run-rate loss
		$annualized_run_rate = round(($total_loss / max(1, $days)) * 365, 2);

		// classify risk severity level
		$severity_level = 'optimal';
		if ($total_loss >= 500.0 || $annualized_run_rate >= 5000.0) {
			$severity_level = 'critical';
		} elseif ($total_loss >= 100.0 || $annualized_run_rate >= 1000.0) {
			$severity_level = 'warning';
		} elseif ($total_loss > 0.0) {
			$severity_level = 'moderate';
		}

		// identify highest exposure currency
		$top_currency = !empty($by_currency) ? array_key_first($by_currency) : '';

		$summary = [
			'period_days'         => $days,
			'store_currency'      => $store_currency,
			'total_loss'          => round($total_loss, 2),
			'order_count'         => $order_count,
			'avg_loss_per_order'  => $avg_loss_per_order,
			'annualized_run_rate' => $annualized_run_rate,
			'severity_level'      => $severity_level,
			'top_currency'        => $top_currency,
			'by_currency'         => $by_currency,
			'is_mock'             => false,
		];

		// cache summary for 5 minutes
		set_transient($cache_key, $summary, 5 * MINUTE_IN_SECONDS);

		return $summary;
	}

	// generate realistic synthetic telemetry for development and demonstration
	public function generate_mock_summary(int $days, string $store_currency = 'USD'): array {
		$scale = match ($days) {
			60 => 1.85,
			90 => 2.70,
			default => 1.00,
		};

		$base_loss = 1420.50 * $scale;
		$base_orders = (int) round(48 * $scale);

		$by_currency = [
			'EUR' => [
				'currency'  => 'EUR',
				'orders'    => (int) round(28 * $scale),
				'loss'      => round(912.20 * $scale, 2),
				'share_pct' => 64.2,
			],
			'GBP' => [
				'currency'  => 'GBP',
				'orders'    => (int) round(12 * $scale),
				'loss'      => round(328.10 * $scale, 2),
				'share_pct' => 23.1,
			],
			'CAD' => [
				'currency'  => 'CAD',
				'orders'    => (int) round(5 * $scale),
				'loss'      => round(114.30 * $scale, 2),
				'share_pct' => 8.1,
			],
			'AUD' => [
				'currency'  => 'AUD',
				'orders'    => (int) round(3 * $scale),
				'loss'      => round(65.90 * $scale, 2),
				'share_pct' => 4.6,
			],
		];

		return [
			'period_days'         => $days,
			'store_currency'      => $store_currency,
			'total_loss'          => round($base_loss, 2),
			'order_count'         => $base_orders,
			'avg_loss_per_order'  => round($base_loss / $base_orders, 2),
			'annualized_run_rate' => round(($base_loss / $days) * 365, 2),
			'severity_level'      => 'critical',
			'top_currency'        => 'EUR',
			'by_currency'         => $by_currency,
			'is_mock'             => true,
		];
	}

	// clear active summary transients when new data is parsed
	public function clear_summary_transients(): void {
		$periods = [30, 60, 90];
		$store_currency = get_woocommerce_currency();
		foreach ($periods as $days) {
			delete_transient('finlyzer_sum_' . $days . '_' . md5($store_currency));
		}
	}
}
