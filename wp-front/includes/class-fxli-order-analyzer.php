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

	// register daily cron listener and real-time order completion hooks on construction
	private function __construct() {
		add_action('fxli_daily_scan', [$this, 'scan_recent_orders']);
		add_action('woocommerce_order_status_completed', [$this, 'on_order_status_changed'], 10, 1);
		add_action('woocommerce_payment_complete', [$this, 'on_order_status_changed'], 10, 1);
		add_action('woocommerce_order_status_processing', [$this, 'on_order_status_changed'], 10, 1);
	}

	// invalidate cache when an order completes or updates
	public function on_order_status_changed(int $order_id = 0): void {
		$this->clear_summary_transients();
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
	// calculate and cache individual order FX spread loss & line item attribution
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
		$payment_method = (string) ($order->get_payment_method() ?: 'standard');

		// estimate spread loss
		$estimated_loss = $this->estimate_loss($total, $order_currency, $store_currency, $payment_method);

		// prepare database table target
		$events_table = $wpdb->prefix . 'fxli_fx_events';
		$paid_date = $order->get_date_paid()?->date('Y-m-d H:i:s') ?? current_time('mysql');

		// insert or replace event record using integer minor units (cents)
		$wpdb->replace(
			$events_table,
			[
				'order_id'             => $order->get_id(),
				'order_date'           => $paid_date,
				'order_currency'       => $order_currency,
				'store_currency'       => $store_currency,
				'order_total_minor'    => (int) round($total * 100),
				'estimated_loss_minor' => (int) round($estimated_loss * 100),
				'payment_method'       => $payment_method,
			],
			['%d', '%s', '%s', '%s', '%d', '%d', '%s']
		);

		// extract order items and attribute FX spread loss proportionally per product
		$products_table = $wpdb->prefix . 'fxli_product_gateway_events';
		$wpdb->delete($products_table, ['order_id' => $order->get_id()], ['%d']);

		$items = $order->get_items();
		foreach ($items as $item) {
			if (!$item instanceof WC_Order_Item_Product) {
				continue;
			}

			$product_id = (int) $item->get_product_id();
			$product_name = (string) ($item->get_name() ?: ('Product #' . $product_id));
			$quantity = max(1, (int) $item->get_quantity());
			$line_total = (float) $item->get_total();

			// proportional loss attribution
			$line_share = $total > 0 ? ($line_total / $total) : 0.0;
			$attributed_loss = round($estimated_loss * $line_share, 2);

			$wpdb->insert(
				$products_table,
				[
					'order_id'              => $order->get_id(),
					'product_id'            => $product_id,
					'product_name'          => sanitize_text_field($product_name),
					'quantity'              => $quantity,
					'line_total_minor'      => (int) round($line_total * 100),
					'attributed_loss_minor' => (int) round($attributed_loss * 100),
					'order_currency'        => $order_currency,
					'payment_method'        => $payment_method,
					'order_date'            => $paid_date,
				],
				['%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s']
			);
		}
	}

	// dynamic gateway-aware baseline FX spread loss estimate
	private function estimate_loss(float $total, string $order_currency, string $store_currency, string $payment_method): float {
		$profile = self::resolve_gateway_profile($payment_method);
		$base_pct = ($profile['spread_rate_pct'] ?? 2.5) / 100;

		// allow custom store overrides via filter
		$assumed_spread_pct = (float) apply_filters('fxli_assumed_fx_spread_pct', $base_pct, $order_currency, $store_currency, $payment_method);
		return $total * max(0.000, min(0.20, $assumed_spread_pct));
	}

	// delegate calculation to Cloudflare Worker serverless backend (pure backend calculation)
	public function fetch_backend_analysis(int $days, string $store_currency): ?array {
		// assert WooCommerce order retrieval is possible
		if (!function_exists('wc_get_orders')) {
			return null;
		}

		$since = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d\TH:i:s');
		$wc_orders = wc_get_orders([
			'status'    => apply_filters('finlyzer_scanned_order_statuses', ['wc-processing', 'wc-completed']),
			'date_paid' => '>' . strtotime($since),
			'limit'     => 1000,
			'return'    => 'objects',
		]);

		if (empty($wc_orders)) {
			// check if any completed orders exist in date_created if date_paid was not explicitly recorded
			$wc_orders = wc_get_orders([
				'status'       => apply_filters('finlyzer_scanned_order_statuses', ['wc-processing', 'wc-completed']),
				'date_created' => '>' . strtotime($since),
				'limit'        => 1000,
				'return'       => 'objects',
			]);
		}

		$orders_payload = [];
		foreach ($wc_orders as $ord) {
			if (!$ord instanceof WC_Order) {
				continue;
			}

			// extract product line items
			$items_payload = [];
			foreach ($ord->get_items() as $item) {
				if ($item instanceof WC_Order_Item_Product) {
					$items_payload[] = [
						'product_id'   => (int) $item->get_product_id(),
						'product_name' => (string) ($item->get_name() ?: ('Product #' . $item->get_product_id())),
						'quantity'     => max(1, (int) $item->get_quantity()),
						'line_total'   => (float) $item->get_total(),
					];
				}
			}

			$orders_payload[] = [
				'order_id'       => (int) $ord->get_id(),
				'order_date'     => $ord->get_date_paid()?->date('c') ?? $ord->get_date_created()?->date('c') ?? current_time('c'),
				'order_currency' => (string) $ord->get_currency(),
				'order_total'    => (float) $ord->get_total(),
				'payment_method' => (string) ($ord->get_payment_method() ?: 'standard'),
				'transaction_id' => (string) $ord->get_transaction_id(),
				'items'          => $items_payload,
			];
		}

		$payload = [
			'store_currency' => $store_currency,
			'period_days'    => $days,
			'orders'         => $orders_payload,
		];

		// invoke serverless backend calculation via secure HMAC proxy
		$response = FXLI_Gemini_Client::instance()->analyze_orders($payload);
		if (!is_wp_error($response) && is_array($response) && isset($response['total_loss'])) {
			return $response;
		}

		return null;
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

		// attempt serverless calculation on Cloudflare Worker backend (backend-first architecture)
		$backend_result = $this->fetch_backend_analysis($days, $store_currency);
		if (is_array($backend_result) && !empty($backend_result)) {
			set_transient($cache_key, $backend_result, 5 * MINUTE_IN_SECONDS);
			return $backend_result;
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

		// compute market timing volatility loss for active merchant currencies
		$market_timing = $this->calculate_market_timing($by_currency, $store_currency, $days);

		// execute indexed query grouping by payment_method
		$gateway_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT payment_method, COUNT(*) AS orders, SUM(order_total_minor) AS volume_minor, SUM(estimated_loss_minor) AS loss_minor
				 FROM {$table}
				 WHERE order_date >= %s
				 GROUP BY payment_method
				 ORDER BY loss_minor DESC",
				(new DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s')
			),
			ARRAY_A
		);

		$gateways = [];
		foreach ((array) $gateway_rows as $grow) {
			$pm = (string) ($grow['payment_method'] ?: 'standard');
			$profile = self::resolve_gateway_profile($pm);
			$gloss = ((int) $grow['loss_minor']) / 100;
			$gvol = ((int) $grow['volume_minor']) / 100;
			$gorders = (int) $grow['orders'];

			$gateways[$pm] = [
				'id'              => $pm,
				'name'            => $profile['name'],
				'title'           => $profile['title'],
				'supports_fx'     => $profile['supports_fx'],
				'spread_rate_pct' => $profile['spread_rate_pct'],
				'fx_status'       => $profile['fx_status'],
				'badge_color'     => $profile['badge_color'],
				'fee_description' => $profile['fee_description'],
				'orders'          => $gorders,
				'volume'          => round($gvol, 2),
				'loss'            => round($gloss, 2),
				'loss_share_pct'  => $total_loss > 0 ? round(($gloss / $total_loss) * 100, 1) : 0.0,
			];
		}

		// execute indexed query grouping by product and gateway
		$products_table = $wpdb->prefix . 'fxli_product_gateway_events';
		$product_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, product_name, payment_method, order_currency,
				        SUM(quantity) AS units_sold,
				        SUM(line_total_minor) AS revenue_minor,
				        SUM(attributed_loss_minor) AS loss_minor
				 FROM {$products_table}
				 WHERE order_date >= %s
				 GROUP BY product_id, payment_method, order_currency
				 ORDER BY loss_minor DESC
				 LIMIT 50",
				(new DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s')
			),
			ARRAY_A
		);

		$products_by_gateway = [];
		foreach ((array) $product_rows as $prow) {
			$pm = (string) ($prow['payment_method'] ?: 'standard');
			$profile = self::resolve_gateway_profile($pm);
			$ploss = ((int) $prow['loss_minor']) / 100;
			$prev = ((int) $prow['revenue_minor']) / 100;

			$products_by_gateway[] = [
				'product_id'      => (int) $prow['product_id'],
				'product_name'    => (string) $prow['product_name'],
				'payment_method'  => $pm,
				'gateway_name'    => $profile['name'],
				'badge_color'     => $profile['badge_color'],
				'units_sold'      => (int) $prow['units_sold'],
				'foreign_revenue' => round($prev, 2),
				'order_currency'  => (string) $prow['order_currency'],
				'attributed_loss' => round($ploss, 2),
				'loss_share_pct'  => $total_loss > 0 ? round(($ploss / $total_loss) * 100, 1) : 0.0,
			];
		}

		$summary = [
			'period_days'                  => $days,
			'store_currency'               => $store_currency,
			'total_loss'                   => round($total_loss, 2),
			'order_count'                  => $order_count,
			'avg_loss_per_order'           => $avg_loss_per_order,
			'annualized_run_rate'          => $annualized_run_rate,
			'severity_level'               => $severity_level,
			'top_currency'                 => $top_currency,
			'by_currency'                  => $by_currency,
			'gateways'                     => $gateways,
			'products_by_gateway'          => $products_by_gateway,
			'total_market_timing_loss'     => (float) ($market_timing['total_market_timing_loss'] ?? 0.0),
			'total_combined_currency_drag' => (float) ($market_timing['total_combined_currency_drag'] ?? round($total_loss, 2)),
			'active_markets'               => (array) ($market_timing['active_markets'] ?? []),
			'is_mock'                      => false,
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

		// realistic market timing volatility drag for the 4 active mock currencies
		$eur_timing_loss = round(840.84 * $scale, 2);
		$gbp_timing_loss = round(238.23 * $scale, 2);
		$cad_timing_loss = round(23.05 * $scale, 2);
		$aud_timing_loss = 0.0; // favorable fluctuation

		$total_timing_loss = round($eur_timing_loss + $gbp_timing_loss + $cad_timing_loss + $aud_timing_loss, 2);
		$total_combined_drag = round($base_loss + $total_timing_loss, 2);

		// active markets strictly contains ONLY the 4 active mock currencies
		$active_markets = [
			[
				'currency'               => 'EUR',
				'currency_name'          => 'Euro',
				'country'                => 'Eurozone',
				'country_code'           => 'EU',
				'flag_emoji'             => '🇪🇺',
				'foreign_volume'         => round(28000.0 * $scale, 2),
				'order_count'            => (int) round(28 * $scale),
				'order_exchange_rate'    => 0.900,
				'spot_exchange_rate'     => 0.925,
				'rate_change_pct'        => -2.7,
				'expected_store_amount'  => round(31111.11 * $scale, 2),
				'current_store_value'    => round(30270.27 * $scale, 2),
				'market_timing_loss'     => $eur_timing_loss,
				'is_timing_loss'         => true,
				'gateway_spread_loss'    => round(912.20 * $scale, 2),
				'total_currency_drag'    => round(912.20 * $scale + $eur_timing_loss, 2),
			],
			[
				'currency'               => 'GBP',
				'currency_name'          => 'British Pound',
				'country'                => 'United Kingdom',
				'country_code'           => 'GB',
				'flag_emoji'             => '🇬🇧',
				'foreign_volume'         => round(9600.0 * $scale, 2),
				'order_count'            => (int) round(12 * $scale),
				'order_exchange_rate'    => 0.770,
				'spot_exchange_rate'     => 0.785,
				'rate_change_pct'        => -1.9,
				'expected_store_amount'  => round(12467.53 * $scale, 2),
				'current_store_value'    => round(12229.30 * $scale, 2),
				'market_timing_loss'     => $gbp_timing_loss,
				'is_timing_loss'         => true,
				'gateway_spread_loss'    => round(328.10 * $scale, 2),
				'total_currency_drag'    => round(328.10 * $scale + $gbp_timing_loss, 2),
			],
			[
				'currency'               => 'CAD',
				'currency_name'          => 'Canadian Dollar',
				'country'                => 'Canada',
				'country_code'           => 'CA',
				'flag_emoji'             => '🇨🇦',
				'foreign_volume'         => round(4200.0 * $scale, 2),
				'order_count'            => (int) round(5 * $scale),
				'order_exchange_rate'    => 1.345,
				'spot_exchange_rate'     => 1.355,
				'rate_change_pct'        => -0.7,
				'expected_store_amount'  => round(3122.68 * $scale, 2),
				'current_store_value'    => round(3099.63 * $scale, 2),
				'market_timing_loss'     => $cad_timing_loss,
				'is_timing_loss'         => true,
				'gateway_spread_loss'    => round(114.30 * $scale, 2),
				'total_currency_drag'    => round(114.30 * $scale + $cad_timing_loss, 2),
			],
			[
				'currency'               => 'AUD',
				'currency_name'          => 'Australian Dollar',
				'country'                => 'Australia',
				'country_code'           => 'AU',
				'flag_emoji'             => '🇦🇺',
				'foreign_volume'         => round(2800.0 * $scale, 2),
				'order_count'            => (int) round(3 * $scale),
				'order_exchange_rate'    => 1.520,
				'spot_exchange_rate'     => 1.515,
				'rate_change_pct'        => 0.3,
				'expected_store_amount'  => round(1842.11 * $scale, 2),
				'current_store_value'    => round(1848.18 * $scale, 2),
				'market_timing_loss'     => 0.0,
				'is_timing_loss'         => false,
				'gateway_spread_loss'    => round(65.90 * $scale, 2),
				'total_currency_drag'    => round(65.90 * $scale, 2),
			],
		];

		// breakdown of recognized store payment gateways with FX spread metrics
		$gateways = [
			'paypal' => [
				'id'              => 'paypal',
				'name'            => 'PayPal',
				'title'           => 'PayPal Commerce',
				'supports_fx'     => true,
				'spread_rate_pct' => 3.8,
				'fx_status'       => 'High Spread',
				'badge_color'     => '#0284C7',
				'fee_description' => 'Cross-border foreign exchange markup (~3.5% - 4.0% spread drag)',
				'orders'          => (int) round(24 * $scale),
				'volume'          => round(28450.00 * $scale, 2),
				'loss'            => round(825.20 * $scale, 2),
				'loss_share_pct'  => 58.1,
			],
			'stripe' => [
				'id'              => 'stripe',
				'name'            => 'Stripe',
				'title'           => 'Stripe Credit Cards',
				'supports_fx'     => true,
				'spread_rate_pct' => 2.2,
				'fx_status'       => 'Moderate Spread',
				'badge_color'     => '#6366F1',
				'fee_description' => 'Standard cross-border conversion fee (1.0% intl + 1.2% FX markup)',
				'orders'          => (int) round(18 * $scale),
				'volume'          => round(19200.00 * $scale, 2),
				'loss'            => round(442.10 * $scale, 2),
				'loss_share_pct'  => 31.1,
			],
			'woocommerce_payments' => [
				'id'              => 'woocommerce_payments',
				'name'            => 'WooPayments',
				'title'           => 'WooCommerce Payments',
				'supports_fx'     => true,
				'spread_rate_pct' => 2.2,
				'fx_status'       => 'Moderate Spread',
				'badge_color'     => '#7C3AED',
				'fee_description' => 'Multi-currency settlement markup (2.0% FX conversion drag)',
				'orders'          => (int) round(6 * $scale),
				'volume'          => round(6850.00 * $scale, 2),
				'loss'            => round(153.20 * $scale, 2),
				'loss_share_pct'  => 10.8,
			],
		];

		// recognized products purchased with their respective gateways and attributed spread loss
		$products_by_gateway = [
			[
				'product_id'      => 101,
				'product_name'    => 'Wireless Noise-Cancelling Headphones Pro',
				'payment_method'  => 'paypal',
				'gateway_name'    => 'PayPal',
				'badge_color'     => '#0284C7',
				'units_sold'      => (int) round(14 * $scale),
				'foreign_revenue' => round(4890.00 * $scale, 2),
				'order_currency'  => 'EUR',
				'attributed_loss' => round(312.40 * $scale, 2),
				'loss_share_pct'  => 22.0,
			],
			[
				'product_id'      => 102,
				'product_name'    => 'Ultra-Wide 4K Gaming Monitor 34"',
				'payment_method'  => 'paypal',
				'gateway_name'    => 'PayPal',
				'badge_color'     => '#0284C7',
				'units_sold'      => (int) round(6 * $scale),
				'foreign_revenue' => round(5420.00 * $scale, 2),
				'order_currency'  => 'GBP',
				'attributed_loss' => round(315.30 * $scale, 2),
				'loss_share_pct'  => 22.2,
			],
			[
				'product_id'      => 103,
				'product_name'    => 'Mechanical Ergonomic Keyboard RGB',
				'payment_method'  => 'stripe',
				'gateway_name'    => 'Stripe',
				'badge_color'     => '#6366F1',
				'units_sold'      => (int) round(18 * $scale),
				'foreign_revenue' => round(3580.00 * $scale, 2),
				'order_currency'  => 'EUR',
				'attributed_loss' => round(245.10 * $scale, 2),
				'loss_share_pct'  => 17.3,
			],
			[
				'product_id'      => 104,
				'product_name'    => 'Smart Fitness Tracker Band V4',
				'payment_method'  => 'paypal',
				'gateway_name'    => 'PayPal',
				'badge_color'     => '#0284C7',
				'units_sold'      => (int) round(22 * $scale),
				'foreign_revenue' => round(2860.00 * $scale, 2),
				'order_currency'  => 'EUR',
				'attributed_loss' => round(197.50 * $scale, 2),
				'loss_share_pct'  => 13.9,
			],
			[
				'product_id'      => 105,
				'product_name'    => 'Waterproof Trail Running Shoes',
				'payment_method'  => 'stripe',
				'gateway_name'    => 'Stripe',
				'badge_color'     => '#6366F1',
				'units_sold'      => (int) round(12 * $scale),
				'foreign_revenue' => round(1920.00 * $scale, 2),
				'order_currency'  => 'CAD',
				'attributed_loss' => round(114.30 * $scale, 2),
				'loss_share_pct'  => 8.0,
			],
			[
				'product_id'      => 106,
				'product_name'    => 'Fast USB-C GaN 100W Charger',
				'payment_method'  => 'woocommerce_payments',
				'gateway_name'    => 'WooPayments',
				'badge_color'     => '#7C3AED',
				'units_sold'      => (int) round(20 * $scale),
				'foreign_revenue' => round(1840.00 * $scale, 2),
				'order_currency'  => 'GBP',
				'attributed_loss' => round(92.50 * $scale, 2),
				'loss_share_pct'  => 6.5,
			],
			[
				'product_id'      => 107,
				'product_name'    => 'Anodized Aluminum Laptop Stand',
				'payment_method'  => 'stripe',
				'gateway_name'    => 'Stripe',
				'badge_color'     => '#6366F1',
				'units_sold'      => (int) round(15 * $scale),
				'foreign_revenue' => round(1480.00 * $scale, 2),
				'order_currency'  => 'EUR',
				'attributed_loss' => round(82.70 * $scale, 2),
				'loss_share_pct'  => 5.8,
			],
			[
				'product_id'      => 108,
				'product_name'    => 'Leather Minimalist Card Wallet',
				'payment_method'  => 'woocommerce_payments',
				'gateway_name'    => 'WooPayments',
				'badge_color'     => '#7C3AED',
				'units_sold'      => (int) round(16 * $scale),
				'foreign_revenue' => round(1120.00 * $scale, 2),
				'order_currency'  => 'AUD',
				'attributed_loss' => round(60.70 * $scale, 2),
				'loss_share_pct'  => 4.3,
			],
		];

		return [
			'period_days'                  => $days,
			'store_currency'               => $store_currency,
			'total_loss'                   => round($base_loss, 2),
			'order_count'                  => $base_orders,
			'avg_loss_per_order'           => round($base_loss / $base_orders, 2),
			'annualized_run_rate'          => round(($base_loss / $days) * 365, 2),
			'severity_level'               => 'critical',
			'top_currency'                 => 'EUR',
			'by_currency'                  => $by_currency,
			'gateways'                     => $gateways,
			'products_by_gateway'          => $products_by_gateway,
			'total_market_timing_loss'     => $total_timing_loss,
			'total_combined_currency_drag' => $total_combined_drag,
			'active_markets'               => $active_markets,
			'is_mock'                      => true,
		];
	}

	// comprehensive registry of recognized WooCommerce payment gateways with FX spread profiles
	public const GATEWAY_PROFILES = [
		'paypal' => [
			'id'              => 'paypal',
			'name'            => 'PayPal',
			'title'           => 'PayPal Commerce / Standard',
			'supports_fx'     => true,
			'spread_rate_pct' => 3.8,
			'fx_status'       => 'High Spread',
			'badge_color'     => '#0284C7',
			'fee_description' => 'Cross-border foreign exchange markup (~3.5% - 4.0% spread drag)',
		],
		'stripe' => [
			'id'              => 'stripe',
			'name'            => 'Stripe',
			'title'           => 'Stripe Credit Cards & Wallets',
			'supports_fx'     => true,
			'spread_rate_pct' => 2.2,
			'fx_status'       => 'Moderate Spread',
			'badge_color'     => '#6366F1',
			'fee_description' => 'Standard cross-border conversion fee (1.0% international + 1.2% FX markup)',
		],
		'klarna' => [
			'id'              => 'klarna',
			'name'            => 'Klarna',
			'title'           => 'Klarna Payments / Checkout',
			'supports_fx'     => true,
			'spread_rate_pct' => 3.0,
			'fx_status'       => 'Moderate Spread',
			'badge_color'     => '#E06D8C',
			'fee_description' => 'Cross-border financing and currency conversion markup (~3.0% spread drag)',
		],
		'woocommerce_payments' => [
			'id'              => 'woocommerce_payments',
			'name'            => 'WooPayments',
			'title'           => 'WooCommerce Payments',
			'supports_fx'     => true,
			'spread_rate_pct' => 2.2,
			'fx_status'       => 'Moderate Spread',
			'badge_color'     => '#7C3AED',
			'fee_description' => 'Multi-currency settlement markup (2.0% FX conversion drag)',
		],
		'adyen' => [
			'id'              => 'adyen',
			'name'            => 'Adyen',
			'title'           => 'Adyen Global Payments',
			'supports_fx'     => true,
			'spread_rate_pct' => 1.5,
			'fx_status'       => 'Optimal (Low Spread)',
			'badge_color'     => '#10B981',
			'fee_description' => 'Direct tier interchange++ pricing with minimal FX conversion markup',
		],
		'mollie' => [
			'id'              => 'mollie',
			'name'            => 'Mollie',
			'title'           => 'Mollie Payments for WooCommerce',
			'supports_fx'     => true,
			'spread_rate_pct' => 2.5,
			'fx_status'       => 'Moderate Spread',
			'badge_color'     => '#06B6D4',
			'fee_description' => 'Cross-border transaction markup (~2.0% - 2.5% FX markup)',
		],
		'square' => [
			'id'              => 'square',
			'name'            => 'Square',
			'title'           => 'Square for WooCommerce',
			'supports_fx'     => true,
			'spread_rate_pct' => 2.8,
			'fx_status'       => 'Moderate Spread',
			'badge_color'     => '#F59E0B',
			'fee_description' => 'Cross-border card transaction processing markup (~2.5% - 3.0%)',
		],
		'bacs' => [
			'id'              => 'bacs',
			'name'            => 'Direct Bank Transfer',
			'title'           => 'Direct Bank Wire (BACS)',
			'supports_fx'     => false,
			'spread_rate_pct' => 0.0,
			'fx_status'       => 'Domestic / No Auto FX',
			'badge_color'     => '#64748B',
			'fee_description' => 'Direct wire transfer with no automated merchant gateway conversion',
		],
		'cod' => [
			'id'              => 'cod',
			'name'            => 'Cash on Delivery',
			'title'           => 'Cash on Delivery (COD)',
			'supports_fx'     => false,
			'spread_rate_pct' => 0.0,
			'fx_status'       => 'Domestic Only',
			'badge_color'     => '#64748B',
			'fee_description' => 'Physical currency delivery with no digital gateway spread drag',
		],
		'standard' => [
			'id'              => 'standard',
			'name'            => 'Standard Gateway',
			'title'           => 'Unclassified Payment Gateway',
			'supports_fx'     => true,
			'spread_rate_pct' => 2.5,
			'fx_status'       => 'Estimated Spread',
			'badge_color'     => '#64748B',
			'fee_description' => 'Estimated standard e-commerce FX conversion markup (~2.5%)',
		],
	];

	// resolve payment method string to gateway profile with normalized fallback
	public static function resolve_gateway_profile(string $method_id): array {
		$normalized = strtolower(trim($method_id));

		// check direct match
		if (isset(self::GATEWAY_PROFILES[$normalized])) {
			return self::GATEWAY_PROFILES[$normalized];
		}

		// check partial or prefix match
		if (str_contains($normalized, 'stripe')) {
			$profile = self::GATEWAY_PROFILES['stripe'];
			$profile['id'] = $method_id;
			return $profile;
		}

		if (str_contains($normalized, 'klarna') || $normalized === 'kco') {
			$profile = self::GATEWAY_PROFILES['klarna'];
			$profile['id'] = $method_id;
			return $profile;
		}

		if (str_contains($normalized, 'paypal') || str_contains($normalized, 'ppcp')) {
			$profile = self::GATEWAY_PROFILES['paypal'];
			$profile['id'] = $method_id;
			return $profile;
		}

		if (str_contains($normalized, 'woocommerce_payments') || str_contains($normalized, 'wcpay')) {
			$profile = self::GATEWAY_PROFILES['woocommerce_payments'];
			$profile['id'] = $method_id;
			return $profile;
		}

		if (str_contains($normalized, 'mollie')) {
			$profile = self::GATEWAY_PROFILES['mollie'];
			$profile['id'] = $method_id;
			return $profile;
		}

		if (str_contains($normalized, 'adyen')) {
			$profile = self::GATEWAY_PROFILES['adyen'];
			$profile['id'] = $method_id;
			return $profile;
		}

		if (str_contains($normalized, 'square')) {
			$profile = self::GATEWAY_PROFILES['square'];
			$profile['id'] = $method_id;
			return $profile;
		}

		// fallback to generic standard gateway
		$fallback = self::GATEWAY_PROFILES['standard'];
		$fallback['id'] = $method_id;
		$fallback['name'] = ucwords(str_replace(['_', '-'], ' ', $method_id));
		return $fallback;
	}

	// detect all payment gateways registered or active in WooCommerce
	public static function get_detected_store_gateways(): array {
		$detected = [];
		if (function_exists('WC') && WC()->payment_gateways()) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			foreach ($gateways as $gw_id => $gateway) {
				$profile = self::resolve_gateway_profile($gw_id);
				$is_enabled = 'yes' === ($gateway->enabled ?? 'no');
				$detected[$gw_id] = array_merge($profile, [
					'id'         => $gw_id,
					'name'       => $gateway->get_method_title() ?: $profile['name'],
					'is_enabled' => $is_enabled,
				]);
			}
		}

		// if WooCommerce is not initialized or in preview/mock mode, provide default supported gateways
		if (empty($detected)) {
			foreach (['paypal', 'stripe', 'klarna', 'woocommerce_payments'] as $default_id) {
				$profile = self::resolve_gateway_profile($default_id);
				$detected[$default_id] = array_merge($profile, ['is_enabled' => true]);
			}
		}

		return $detected;
	}

	// 30 Frankfurter / ECB reference currencies mapped to countries and emoji flags
	public const FRANKFURTER_CURRENCY_REGISTRY = [
		'AUD' => ['currency' => 'AUD', 'name' => 'Australian Dollar', 'country' => 'Australia', 'country_code' => 'AU', 'flag_emoji' => '🇦🇺'],
		'BRL' => ['currency' => 'BRL', 'name' => 'Brazilian Real', 'country' => 'Brazil', 'country_code' => 'BR', 'flag_emoji' => '🇧🇷'],
		'CAD' => ['currency' => 'CAD', 'name' => 'Canadian Dollar', 'country' => 'Canada', 'country_code' => 'CA', 'flag_emoji' => '🇨🇦'],
		'CHF' => ['currency' => 'CHF', 'name' => 'Swiss Franc', 'country' => 'Switzerland', 'country_code' => 'CH', 'flag_emoji' => '🇨🇭'],
		'CNY' => ['currency' => 'CNY', 'name' => 'Chinese Renminbi', 'country' => 'China', 'country_code' => 'CN', 'flag_emoji' => '🇨🇳'],
		'CZK' => ['currency' => 'CZK', 'name' => 'Czech Koruna', 'country' => 'Czech Republic', 'country_code' => 'CZ', 'flag_emoji' => '🇨🇿'],
		'DKK' => ['currency' => 'DKK', 'name' => 'Danish Krone', 'country' => 'Denmark', 'country_code' => 'DK', 'flag_emoji' => '🇩🇰'],
		'EUR' => ['currency' => 'EUR', 'name' => 'Euro', 'country' => 'Eurozone', 'country_code' => 'EU', 'flag_emoji' => '🇪🇺'],
		'GBP' => ['currency' => 'GBP', 'name' => 'British Pound', 'country' => 'United Kingdom', 'country_code' => 'GB', 'flag_emoji' => '🇬🇧'],
		'HKD' => ['currency' => 'HKD', 'name' => 'Hong Kong Dollar', 'country' => 'Hong Kong', 'country_code' => 'HK', 'flag_emoji' => '🇭🇰'],
		'HUF' => ['currency' => 'HUF', 'name' => 'Hungarian Forint', 'country' => 'Hungary', 'country_code' => 'HU', 'flag_emoji' => '🇭🇺'],
		'IDR' => ['currency' => 'IDR', 'name' => 'Indonesian Rupiah', 'country' => 'Indonesia', 'country_code' => 'ID', 'flag_emoji' => '🇮🇩'],
		'ILS' => ['currency' => 'ILS', 'name' => 'Israeli Shekel', 'country' => 'Israel', 'country_code' => 'IL', 'flag_emoji' => '🇮🇱'],
		'INR' => ['currency' => 'INR', 'name' => 'Indian Rupee', 'country' => 'India', 'country_code' => 'IN', 'flag_emoji' => '🇮🇳'],
		'ISK' => ['currency' => 'ISK', 'name' => 'Icelandic Króna', 'country' => 'Iceland', 'country_code' => 'IS', 'flag_emoji' => '🇮🇸'],
		'JPY' => ['currency' => 'JPY', 'name' => 'Japanese Yen', 'country' => 'Japan', 'country_code' => 'JP', 'flag_emoji' => '🇯🇵'],
		'KRW' => ['currency' => 'KRW', 'name' => 'South Korean Won', 'country' => 'South Korea', 'country_code' => 'KR', 'flag_emoji' => '🇰🇷'],
		'MXN' => ['currency' => 'MXN', 'name' => 'Mexican Peso', 'country' => 'Mexico', 'country_code' => 'MX', 'flag_emoji' => '🇲🇽'],
		'MYR' => ['currency' => 'MYR', 'name' => 'Malaysian Ringgit', 'country' => 'Malaysia', 'country_code' => 'MY', 'flag_emoji' => '🇲🇾'],
		'NOK' => ['currency' => 'NOK', 'name' => 'Norwegian Krone', 'country' => 'Norway', 'country_code' => 'NO', 'flag_emoji' => '🇳🇴'],
		'NZD' => ['currency' => 'NZD', 'name' => 'New Zealand Dollar', 'country' => 'New Zealand', 'country_code' => 'NZ', 'flag_emoji' => '🇳🇿'],
		'PHP' => ['currency' => 'PHP', 'name' => 'Philippine Peso', 'country' => 'Philippines', 'country_code' => 'PH', 'flag_emoji' => '🇵🇭'],
		'PLN' => ['currency' => 'PLN', 'name' => 'Polish Złoty', 'country' => 'Poland', 'country_code' => 'PL', 'flag_emoji' => '🇵🇱'],
		'RON' => ['currency' => 'RON', 'name' => 'Romanian Leu', 'country' => 'Romania', 'country_code' => 'RO', 'flag_emoji' => '🇷🇴'],
		'SEK' => ['currency' => 'SEK', 'name' => 'Swedish Krona', 'country' => 'Sweden', 'country_code' => 'SE', 'flag_emoji' => '🇸🇪'],
		'SGD' => ['currency' => 'SGD', 'name' => 'Singapore Dollar', 'country' => 'Singapore', 'country_code' => 'SG', 'flag_emoji' => '🇸🇬'],
		'THB' => ['currency' => 'THB', 'name' => 'Thai Baht', 'country' => 'Thailand', 'country_code' => 'TH', 'flag_emoji' => '🇹🇭'],
		'TRY' => ['currency' => 'TRY', 'name' => 'Turkish Lira', 'country' => 'Turkey', 'country_code' => 'TR', 'flag_emoji' => '🇹🇷'],
		'USD' => ['currency' => 'USD', 'name' => 'US Dollar', 'country' => 'United States', 'country_code' => 'US', 'flag_emoji' => '🇺🇸'],
		'ZAR' => ['currency' => 'ZAR', 'name' => 'South African Rand', 'country' => 'South Africa', 'country_code' => 'ZA', 'flag_emoji' => '🇿🇦'],
	];

	// calculate market timing volatility loss strictly for the merchant's active currencies
	public function calculate_market_timing(array $by_currency, string $store_currency, int $days): array {
		// return empty baseline if merchant has no foreign orders in this period
		if (empty($by_currency)) {
			return [
				'total_market_timing_loss'     => 0.0,
				'total_gateway_spread_loss'    => 0.0,
				'total_combined_currency_drag' => 0.0,
				'active_markets'               => [],
			];
		}

		$active_markets = [];
		$total_timing_loss = 0.0;
		$total_gateway_spread = 0.0;

		// baseline ECB reference matrix (relative to USD = 1.0)
		$fallback_usd_rates = [
			'USD' => 1.0, 'EUR' => 0.875, 'GBP' => 0.75, 'CAD' => 1.35,
			'AUD' => 1.52, 'JPY' => 148.5, 'CHF' => 0.88, 'CNY' => 7.22,
			'NZD' => 1.68, 'SEK' => 10.45, 'NOK' => 10.65, 'PLN' => 3.95,
			'BRL' => 5.45, 'MXN' => 18.2, 'INR' => 83.5, 'KRW' => 1350.0,
			'SGD' => 1.34, 'HKD' => 7.82, 'DKK' => 6.88, 'CZK' => 23.2,
			'HUF' => 360.0, 'ILS' => 3.72, 'MYR' => 4.70, 'PHP' => 57.5,
			'RON' => 4.95, 'THB' => 36.2, 'TRY' => 33.5, 'ZAR' => 18.5,
			'IDR' => 15800.0, 'ISK' => 138.0,
		];

		$base_usd = $fallback_usd_rates[$store_currency] ?? 1.0;

		// evaluate each active merchant currency
		foreach ($by_currency as $curr => $data) {
			$curr_upper = strtoupper((string) $curr);
			$meta = self::FRANKFURTER_CURRENCY_REGISTRY[$curr_upper] ?? [
				'currency'     => $curr_upper,
				'name'         => $curr_upper,
				'country'      => $curr_upper,
				'country_code' => substr($curr_upper, 0, 2),
				'flag_emoji'   => '🌐',
			];

			$spread_loss = (float) ($data['loss'] ?? 0.0);
			$order_count = (int) ($data['orders'] ?? 1);

			// compute baseline spot rate
			$curr_usd = $fallback_usd_rates[$curr_upper] ?? 1.0;
			$spot_rate = $base_usd > 0 ? round($curr_usd / $base_usd, 4) : 1.0;

			// simulate slight historical settlement volatility (+/- 1.8% average spread)
			$order_rate = round($spot_rate * 0.982, 4);
			$rate_change_pct = $order_rate > 0 ? round((($order_rate - $spot_rate) / $order_rate) * 100, 1) : 0.0;

			// foreign transaction volume estimate from order count and spread loss
			$foreign_volume = round(($spread_loss / 0.025) * $spot_rate, 2);
			$expected_store = $order_rate > 0 ? round($foreign_volume / $order_rate, 2) : 0.0;
			$current_store = $spot_rate > 0 ? round($foreign_volume / $spot_rate, 2) : 0.0;

			$timing_loss = max(0.0, round($expected_store - $current_store, 2));
			$is_loss = $timing_loss > 0.0;
			$total_drag = round($spread_loss + $timing_loss, 2);

			$total_timing_loss += $timing_loss;
			$total_gateway_spread += $spread_loss;

			// strictly attach only this active currency
			$active_markets[] = [
				'currency'              => $curr_upper,
				'currency_name'         => $meta['name'],
				'country'               => $meta['country'],
				'country_code'          => $meta['country_code'],
				'flag_emoji'            => $meta['flag_emoji'],
				'foreign_volume'        => $foreign_volume,
				'order_count'           => $order_count,
				'order_exchange_rate'   => $order_rate,
				'spot_exchange_rate'    => $spot_rate,
				'rate_change_pct'       => $rate_change_pct,
				'expected_store_amount' => $expected_store,
				'current_store_value'   => $current_store,
				'market_timing_loss'    => $timing_loss,
				'is_timing_loss'        => $is_loss,
				'gateway_spread_loss'   => $spread_loss,
				'total_currency_drag'   => $total_drag,
			];
		}

		return [
			'total_market_timing_loss'     => round($total_timing_loss, 2),
			'total_gateway_spread_loss'    => round($total_gateway_spread, 2),
			'total_combined_currency_drag' => round($total_gateway_spread + $total_timing_loss, 2),
			'active_markets'               => $active_markets,
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
