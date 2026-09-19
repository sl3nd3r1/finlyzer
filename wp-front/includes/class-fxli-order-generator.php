<?php
declare(strict_types=1);

// prevent direct script execution outside WordPress context
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Finlyzer WooCommerce Automated International Order Generator
 *
 * Architecture:
 * - Generates high-fidelity cross-border WooCommerce orders for local and staging environments (e.g. XAMPP).
 * - Full High-Performance Order Storage (HPOS) and legacy post storage compatibility (WooCommerce 8.x - 11.x).
 * - Populates custom gateway titles:
 *     - Stripe: "Credit Card (Stripe)"
 *     - PayPal: "PayPal Commerce Platform"
 *     - Klarna: "Klarna Pay Later / Slice It"
 * - Injects realistic fake transaction IDs (ch_stripe_*, PAYID-*, klarna_txn_*).
 * - Generates realistic catalog products and customer addresses matched to international currencies (EUR, GBP, CAD, AUD, JPY, SEK).
 * - Sets status strictly to 'completed' with realistic historical timestamps across the reporting period.
 * - Tags orders with '_finlyzer_sample_order' for clean, idempotent lifecycle management and cleanup.
 */
final class FXLI_Order_Generator {

	// currency to country and customer locale directory
	private const LOCALES = [
		'EUR' => [
			'country'   => 'DE',
			'city'      => 'Berlin',
			'postcode'  => '10115',
			'state'     => 'BE',
			'address'   => 'Friedrichstraße 43',
			'first_name'=> 'Lukas',
			'last_name' => 'Schmidt',
			'email'     => 'lukas.schmidt@example.de',
		],
		'GBP' => [
			'country'   => 'GB',
			'city'      => 'London',
			'postcode'  => 'EC1A 1BB',
			'state'     => 'LDN',
			'address'   => '24 Oxford Street',
			'first_name'=> 'Emma',
			'last_name' => 'Watson',
			'email'     => 'emma.watson@example.co.uk',
		],
		'CAD' => [
			'country'   => 'CA',
			'city'      => 'Toronto',
			'postcode'  => 'M5V 2T6',
			'state'     => 'ON',
			'address'   => '290 Bremner Blvd',
			'first_name'=> 'Chloe',
			'last_name' => 'Tremblay',
			'email'     => 'chloe.t@example.ca',
		],
		'AUD' => [
			'country'   => 'AU',
			'city'      => 'Sydney',
			'postcode'  => '2000',
			'state'     => 'NSW',
			'address'   => '1 Macquarie Place',
			'first_name'=> 'Jack',
			'last_name' => 'Murphy',
			'email'     => 'jack.murphy@example.com.au',
		],
		'JPY' => [
			'country'   => 'JP',
			'city'      => 'Tokyo',
			'postcode'  => '100-0001',
			'state'     => '13',
			'address'   => '1-1 Chiyoda',
			'first_name'=> 'Hiroshi',
			'last_name' => 'Tanaka',
			'email'     => 'hiroshi.tanaka@example.co.jp',
		],
		'SEK' => [
			'country'   => 'SE',
			'city'      => 'Stockholm',
			'postcode'  => '111 20',
			'state'     => 'AB',
			'address'   => 'Drottninggatan 50',
			'first_name'=> 'Astrid',
			'last_name' => 'Lindgren',
			'email'     => 'astrid.l@example.se',
		],
	];

	// supported gateway configurations with custom payment titles and synthetic tx id patterns
	private const GATEWAYS = [
		'stripe' => [
			'id'        => 'stripe',
			'title'     => 'Credit Card (Stripe)',
			'tx_prefix' => 'ch_stripe_',
		],
		'paypal' => [
			'id'        => 'paypal',
			'title'     => 'PayPal Commerce Platform',
			'tx_prefix' => 'PAYID-',
		],
		'klarna' => [
			'id'        => 'klarna_payments',
			'title'     => 'Klarna Pay Later / Slice It',
			'tx_prefix' => 'klarna_txn_',
		],
	];

	// sample products catalog
	private const SAMPLE_PRODUCTS = [
		[
			'sku'   => 'FL-WATCH-01',
			'name'  => 'Nordic Minimalist Watch',
			'price' => '120.00',
		],
		[
			'sku'   => 'FL-SCARF-02',
			'name'  => 'Cashmere Wool Scarf',
			'price' => '85.00',
		],
		[
			'sku'   => 'FL-CHG-03',
			'name'  => 'Fast USB-C GaN 100W Charger',
			'price' => '45.00',
		],
		[
			'sku'   => 'FL-KEYB-04',
			'name'  => 'Mechanical Ergonomic Keyboard RGB',
			'price' => '165.00',
		],
		[
			'sku'   => 'FL-WLT-05',
			'name'  => 'Leather Minimalist Card Wallet',
			'price' => '55.00',
		],
		[
			'sku'   => 'FL-HDP-06',
			'name'  => 'Noise-Cancelling Wireless Headphones Pro',
			'price' => '249.00',
		],
	];

	// resolve or bootstrap sample catalog products
	private static function ensure_sample_products(): array {
		$product_ids = [];

		// check each catalog product definition
		foreach (self::SAMPLE_PRODUCTS as $def) {
			$existing_id = wc_get_product_id_by_sku($def['sku']);
			if ($existing_id > 0) {
				$product_ids[] = $existing_id;
				continue;
			}

			// create new WooCommerce simple product
			$product = new WC_Product_Simple();
			$product->set_name($def['name']);
			$product->set_sku($def['sku']);
			$product->set_regular_price($def['price']);
			$product->set_price($def['price']);
			$product->set_status('publish');
			$product->set_catalog_visibility('visible');
			$product->set_virtual(false);
			$product->set_manage_stock(false);

			$saved_id = $product->save();
			if ($saved_id > 0) {
				$product_ids[] = $saved_id;
			}
		}

		return $product_ids;
	}

	// generate bulk completed international orders
	public static function generate(int $count = 30, array $options = []): array {
		// assert WooCommerce order factory exists
		if (!function_exists('wc_create_order')) {
			return [
				'success' => false,
				'message' => 'WooCommerce is not active or wc_create_order is unavailable.',
				'created' => 0,
				'orders'  => [],
			];
		}

		// clamp generation bounds
		$count = max(1, min(250, $count));
		$days_window = max(7, min(90, (int) ($options['days'] ?? 30)));
		$allowed_gateways = !empty($options['gateways']) && is_array($options['gateways'])
			? $options['gateways']
			: ['stripe', 'paypal', 'klarna'];
		$allowed_currencies = !empty($options['currencies']) && is_array($options['currencies'])
			? $options['currencies']
			: ['EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'SEK'];

		// ensure test catalog products exist
		$product_ids = self::ensure_sample_products();
		if (empty($product_ids)) {
			return [
				'success' => false,
				'message' => 'Failed to initialize sample catalog products.',
				'created' => 0,
				'orders'  => [],
			];
		}

		$created_orders = [];
		$now = time();

		// generate individual orders
		for ($i = 0; $i < $count; $i++) {
			// pick random target currency and corresponding locale
			$curr = $allowed_currencies[array_rand($allowed_currencies)];
			$loc = self::LOCALES[$curr] ?? self::LOCALES['EUR'];

			// pick random payment gateway
			$gw_key = $allowed_gateways[array_rand($allowed_gateways)];
			$gw = self::GATEWAYS[$gw_key] ?? self::GATEWAYS['stripe'];

			// generate realistic synthetic transaction id
			$fake_tx = $gw['tx_prefix'] . strtoupper(bin2hex(random_bytes(6)));

			// randomize historical order date within the lookback window
			$offset_seconds = random_int(3600, $days_window * 86400);
			$order_timestamp = $now - $offset_seconds;
			$order_datetime = (new DateTimeImmutable("@{$order_timestamp}"))->format('Y-m-d H:i:s');

			// instantiate WooCommerce HPOS-safe order
			$order = wc_create_order();
			if (!$order instanceof WC_Order) {
				continue;
			}

			// assign currency and custom gateway attributes
			$order->set_currency($curr);
			$order->set_payment_method($gw['id']);
			$order->set_payment_method_title($gw['title']);
			$order->set_transaction_id($fake_tx);

			// assign customer billing details
			$order->set_billing_first_name($loc['first_name']);
			$order->set_billing_last_name($loc['last_name']);
			$order->set_billing_address_1($loc['address']);
			$order->set_billing_city($loc['city']);
			$order->set_billing_state($loc['state']);
			$order->set_billing_postcode($loc['postcode']);
			$order->set_billing_country($loc['country']);
			$order->set_billing_email($loc['email']);

			// assign customer shipping details
			$order->set_shipping_first_name($loc['first_name']);
			$order->set_shipping_last_name($loc['last_name']);
			$order->set_shipping_address_1($loc['address']);
			$order->set_shipping_city($loc['city']);
			$order->set_shipping_state($loc['state']);
			$order->set_shipping_postcode($loc['postcode']);
			$order->set_shipping_country($loc['country']);

			// add 1 to 3 random line items
			$num_items = random_int(1, 3);
			$shuffled_products = $product_ids;
			shuffle($shuffled_products);
			$selected_products = array_slice($shuffled_products, 0, $num_items);

			foreach ($selected_products as $pid) {
				$prod = wc_get_product($pid);
				if ($prod instanceof WC_Product) {
					$qty = random_int(1, 2);
					$order->add_product($prod, $qty);
				}
			}

			// assign timestamps
			$order->set_date_created($order_datetime);
			$order->set_date_paid($order_datetime);
			$order->set_date_completed($order_datetime);

			// calculate subtotal, taxes, and grand totals
			$order->calculate_totals();

			// set status to completed
			$order->set_status('completed', 'Order auto-generated by Finlyzer test suite');

			// tag order for tracking and clean deletion
			$order->update_meta_data('_finlyzer_sample_order', '1');

			// save order to database
			$order_id = $order->save();
			if ($order_id > 0) {
				// immediately register order into Finlyzer event tables for instant dashboard visibility
				FXLI_Order_Analyzer::instance()->cache_order_estimate($order, get_woocommerce_currency());

				$created_orders[] = [
					'id'             => $order_id,
					'currency'       => $curr,
					'total'          => $order->get_total(),
					'gateway'        => $gw['id'],
					'gateway_title'  => $gw['title'],
					'transaction_id' => $fake_tx,
					'status'         => $order->get_status(),
					'date'           => $order_datetime,
				];
			}
		}

		// clear cached summary transients so reports immediately refresh
		delete_transient('finlyzer_sum_30_' . md5(get_woocommerce_currency()));
		delete_transient('finlyzer_sum_60_' . md5(get_woocommerce_currency()));
		delete_transient('finlyzer_sum_90_' . md5(get_woocommerce_currency()));

		return [
			'success' => true,
			'created' => count($created_orders),
			'orders'  => $created_orders,
		];
	}

	// clean all previously generated sample orders
	public static function clean(): int {
		if (!function_exists('wc_get_orders')) {
			return 0;
		}

		// query orders matching sample tag
		$sample_orders = wc_get_orders([
			'limit'      => -1,
			'meta_key'   => '_finlyzer_sample_order',
			'meta_value' => '1',
			'return'     => 'objects',
		]);

		$deleted_count = 0;
		foreach ($sample_orders as $order) {
			if ($order instanceof WC_Order) {
				$order->delete(true); // force permanent delete
				$deleted_count++;
			}
		}

		return $deleted_count;
	}
}
