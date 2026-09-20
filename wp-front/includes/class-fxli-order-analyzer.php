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

	// update and cache order metrics in real-time when an order completes or updates
	public function on_order_status_changed(int $order_id = 0): void {
		// load order instance and cache spread loss calculation immediately
		if ($order_id > 0 && function_exists('wc_get_order')) {
			$order = wc_get_order($order_id);
			if ($order instanceof WC_Order) {
				$this->cache_order_estimate($order, get_woocommerce_currency());
			}
		}

		$this->clear_summary_transients();
	}

	// scan recent store orders (invoked via WP-Cron daily trigger)
	public function scan_recent_orders(): void {
		$this->sync_orders(30);
	}

	// scan and synchronize store orders within lookback window into local events tables
	public function sync_orders(int $days = 30): int {
		if (!function_exists('wc_get_orders')) {
			return 0;
		}

		$days = max(7, min(90, $days));
		$store_currency = get_woocommerce_currency();
		$since = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');
		$statuses = apply_filters('finlyzer_scanned_order_statuses', ['processing', 'completed', 'wc-processing', 'wc-completed']);

		$page = 1;
		$batch_size = 100;
		$max_pages = 25; // hard boundary: max 2,500 orders per scan to protect PHP memory
		$total_synced = 0;

		do {
			// query orders through WooCommerce HPOS-safe repository
			$orders = wc_get_orders([
				'status'       => $statuses,
				'date_created' => '>=' . $since,
				'limit'        => $batch_size,
				'page'         => $page,
				'orderby'      => 'date',
				'order'        => 'DESC',
				'return'       => 'objects',
			]);

			if (empty($orders) && $page === 1) {
				// fallback query without date filter in case dates are formatted differently
				$orders = wc_get_orders([
					'status'  => $statuses,
					'limit'   => $batch_size,
					'page'    => $page,
					'orderby' => 'date',
					'order'   => 'DESC',
					'return'  => 'objects',
				]);
			}

			// process each order in the current batch
			foreach ($orders as $order) {
				if ($order instanceof WC_Order) {
					$this->cache_order_estimate($order, $store_currency);
					$total_synced++;
				}
			}

			$page++;
		} while (count($orders) === $batch_size && $page <= $max_pages);

		// invalidate cached summary transients after new events are recorded
		$this->clear_summary_transients();

		return $total_synced;
	}

	// calculate and cache individual order FX spread loss & line item attribution
	public function cache_order_estimate(WC_Order $order, string $store_currency): void {
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
		$paid_date = $order->get_date_paid()?->date('Y-m-d H:i:s') ?? $order->get_date_created()?->date('Y-m-d H:i:s') ?? current_time('mysql');

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

	// delegate calculation strictly to Cloudflare Worker serverless backend (pure backend calculation)
	public function fetch_backend_analysis(int $days, string $store_currency): array|WP_Error {
		// assert WooCommerce order retrieval is possible
		if (!function_exists('wc_get_orders')) {
			if (defined('WC_ABSPATH') && file_exists(WC_ABSPATH . 'includes/wc-order-functions.php')) {
				require_once WC_ABSPATH . 'includes/wc-order-functions.php';
			}
		}

		if (!function_exists('wc_get_orders')) {
			// in development mode, provide clean zero-loss baseline so dashboard does not break
			if (class_exists('FXLI_Env') && FXLI_Env::is_development()) {
				return self::get_dev_zero_baseline($days, $store_currency);
			}
			return new WP_Error('woocommerce_missing', __('WooCommerce order query function not found.', 'finlyzer'));
		}

		$since = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s');
		$statuses = apply_filters('finlyzer_scanned_order_statuses', ['processing', 'completed', 'wc-processing', 'wc-completed']);

		// query store orders within reporting timeframe
		$wc_orders = wc_get_orders([
			'status'       => $statuses,
			'date_created' => '>=' . $since,
			'limit'        => 1000,
			'orderby'      => 'date',
			'order'        => 'DESC',
			'return'       => 'objects',
		]);

		// fallback to unconstrained date query if none found with date_created
		if (empty($wc_orders)) {
			$wc_orders = wc_get_orders([
				'status'  => $statuses,
				'limit'   => 1000,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'objects',
			]);
		}

		$orders_payload = [];
		foreach ($wc_orders as $ord) {
			if (!$ord instanceof WC_Order) {
				continue;
			}

			// filter to cross-border orders only (mismatched currency)
			$order_currency = (string) $ord->get_currency();
			if ($order_currency === '' || $order_currency === $store_currency) {
				continue;
			}

			$total = (float) $ord->get_total();
			if ($total <= 0.0) {
				continue;
			}

			// extract product line items safely
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
				'order_currency' => $order_currency,
				'order_total'    => $total,
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
		if (is_wp_error($response)) {
			return $response;
		}

		if (is_array($response) && isset($response['total_loss'])) {
			// backfill local database events for offline fault tolerance
			foreach ($wc_orders as $ord) {
				if ($ord instanceof WC_Order) {
					$this->cache_order_estimate($ord, $store_currency);
				}
			}
			return $response;
		}

		return new WP_Error('invalid_backend_response', __('Malformed financial analysis response from Finlyzer API.', 'finlyzer'));
	}

	// compile aggregate financial loss summary over the specified period strictly via backend calculation
	public function get_summary(int $days = 30): array|WP_Error {
		// enforce valid reporting period bounds
		$days = max(7, min(90, $days));
		$store_currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';

		// check transient cache to avoid unnecessary network roundtrips on rapid requests
		$cache_key = 'finlyzer_sum_' . $days . '_' . md5($store_currency);
		$cached = get_transient($cache_key);
		if (is_array($cached)) {
			return $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'fxli_fx_events';

		// if local event cache is empty, trigger an immediate on-demand scan across store orders
		$existing_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE order_date >= %s",
				(new DateTimeImmutable("-{$days} days"))->format('Y-m-d H:i:s')
			)
		);
		if ($existing_count === 0) {
			$this->sync_orders($days);
		}

		// execute serverless calculation on Cloudflare Worker backend
		$backend_result = $this->fetch_backend_analysis($days, $store_currency);
		if (is_wp_error($backend_result)) {
			return $backend_result;
		}

		if (is_array($backend_result) && isset($backend_result['total_loss'])) {
			set_transient($cache_key, $backend_result, 5 * MINUTE_IN_SECONDS);
			return $backend_result;
		}

		return new WP_Error('backend_unreachable', __('Unable to connect to Finlyzer calculation backend.', 'finlyzer'));
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

	// clear active summary transients when new data is parsed
	public function clear_summary_transients(): void {
		$periods = [30, 60, 90];
		$store_currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';
		foreach ($periods as $days) {
			delete_transient('finlyzer_sum_' . $days . '_' . md5($store_currency));
		}
	}

	// compile clean zero-loss development baseline structure when store has no foreign transactions or functions are deferred
	public static function get_dev_zero_baseline(int $days = 30, string $store_currency = 'USD'): array {
		return [
			'period_days'                  => $days,
			'store_currency'               => $store_currency,
			'total_loss'                   => 0.0,
			'order_count'                  => 0,
			'avg_loss_per_order'           => 0.0,
			'annualized_run_rate'          => 0.0,
			'severity_level'               => 'optimal',
			'top_currency'                 => '',
			'by_currency'                  => [],
			'gateways'                     => [],
			'products_by_gateway'          => [],
			'total_market_timing_loss'     => 0.0,
			'total_combined_currency_drag' => 0.0,
			'active_markets'               => [],
			'is_mock'                      => false,
		];
	}
}
