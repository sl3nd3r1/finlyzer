<?php
/**
 * Finlyzer — Standalone Frontend Preview & Interactive Dev Server
 *
 * Runs without a full WordPress/MySQL setup so you can immediately test
 * the UI, htmx fragment swaps, range selectors, and AI Risk Sentinel in any browser.
 *
 * Supports switching between:
 *  - Mock Mode: Rich simulated cross-currency orders, spread loss, and AI warning.
 *  - Live/Production Mode: Clean zero-loss baseline when no foreign orders exist.
 *
 * Usage:
 *   php -S 127.0.0.1:8088 frontend/wp-front/preview-server.php
 * Then open:
 *   http://127.0.0.1:8088
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}
if (!defined('FINLYZER_VERSION')) {
	define('FINLYZER_VERSION', '1.7.0');
}
if (!defined('FINLYZER_PLUGIN_DIR')) {
	define('FINLYZER_PLUGIN_DIR', __DIR__ . '/');
}

// -------------------------------------------------------------
// WordPress & WooCommerce Environment Mock Primitives
// -------------------------------------------------------------
function esc_html(string $text): string {
	return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
function esc_attr(string $text): string {
	return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
function esc_url(string $url): string {
	return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}
function wp_kses_post(string $text): string {
	return $text; // safe for trusted wc_price fragments in preview
}
function wp_strip_all_tags(string $string, bool $remove_breaks = false): string {
	$string = preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $string) ?? '';
	$string = strip_tags($string);
	if ($remove_breaks) {
		$string = preg_replace('/[\r\n\t ]+/', ' ', $string) ?? '';
	}
	return trim($string);
}
function __(string $text, string $domain = 'default'): string {
	return $text;
}
function _n(string $single, string $plural, int $number, string $domain = 'default'): string {
	return $number === 1 ? $single : $plural;
}
function esc_html__(string $text, string $domain = 'default'): string {
	return esc_html($text);
}
function esc_attr__(string $text, string $domain = 'default'): string {
	return esc_attr($text);
}
function esc_html_e(string $text, string $domain = 'default'): void {
	echo esc_html($text);
}
function esc_attr_e(string $text, string $domain = 'default'): void {
	echo esc_attr($text);
}
function wp_create_nonce(string $action = ''): string {
	return 'finlyzer_preview_nonce_' . substr(md5($action), 0, 10);
}
function rest_url(string $path = ''): string {
	return '/wp-json/' . ltrim($path, '/');
}
function wc_price(float $price, array $args = []): string {
	$currency = $args['currency'] ?? 'USD';
	$symbol = match ($currency) {
		'EUR' => '€',
		'GBP' => '£',
		'JPY' => '¥',
		'CAD', 'AUD', 'USD' => '$',
		default => $currency . ' ',
	};
	return '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">' . esc_html($symbol) . '</span>' . number_format($price, 2) . '</bdi></span>';
}

// session or cookie to remember chosen mode in preview
$current_mode = $_COOKIE['finlyzer_preview_mode'] ?? 'mock';
if (isset($_GET['finlyzer_mode'])) {
	$current_mode = $_GET['finlyzer_mode'] === 'live' ? 'live' : 'mock';
	setcookie('finlyzer_preview_mode', $current_mode, time() + 86400, '/');
}

// -------------------------------------------------------------
// HTTP Router
// -------------------------------------------------------------
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

// 1. Serve static assets
if (preg_match('#^/assets/(css|js)/(.+)$#', $uri, $matches)) {
	$filePath = __DIR__ . '/assets/' . $matches[1] . '/' . $matches[2];
	if (file_exists($filePath) && is_file($filePath)) {
		$ext = pathinfo($filePath, PATHINFO_EXTENSION);
		$contentType = match ($ext) {
			'css' => 'text/css; charset=utf-8',
			'js' => 'application/javascript; charset=utf-8',
			default => 'application/octet-stream',
		};
		header('Content-Type: ' . $contentType);
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		readfile($filePath);
		exit;
	}
	http_response_code(404);
	echo 'File not found';
	exit;
}

// 2. Handle REST Endpoint: /wp-json/finlyzer/v1/summary
if ($uri === '/wp-json/finlyzer/v1/summary') {
	$days = isset($_GET['days']) ? (int) $_GET['days'] : 30;

	if ($current_mode === 'live') {
		// simulate production WooCommerce store with zero foreign currency orders yet
		$summary = [
			'period_days'                  => $days,
			'store_currency'               => 'USD',
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
	} else {
		// simulate development mode with rich multi-currency telemetry
		$multiplier = match ($days) {
			60 => 1.85,
			90 => 2.70,
			default => 1.00,
		};

		$baseLoss = 1420.50 * $multiplier;
		$baseOrders = (int) round(48 * $multiplier);

		$by_currency = [
			'EUR' => ['currency' => 'EUR', 'orders' => (int) round(28 * $multiplier), 'loss' => round(912.20 * $multiplier, 2), 'share_pct' => 64.2],
			'GBP' => ['currency' => 'GBP', 'orders' => (int) round(12 * $multiplier), 'loss' => round(328.10 * $multiplier, 2), 'share_pct' => 23.1],
			'CAD' => ['currency' => 'CAD', 'orders' => (int) round(5 * $multiplier),  'loss' => round(114.30 * $multiplier, 2), 'share_pct' => 8.1],
			'AUD' => ['currency' => 'AUD', 'orders' => (int) round(3 * $multiplier),  'loss' => round(65.90 * $multiplier, 2),  'share_pct' => 4.6],
		];

		$eur_timing_loss = round(840.84 * $multiplier, 2);
		$gbp_timing_loss = round(238.23 * $multiplier, 2);
		$cad_timing_loss = round(23.05 * $multiplier, 2);
		$aud_timing_loss = 0.0;
		$total_timing_loss = round($eur_timing_loss + $gbp_timing_loss + $cad_timing_loss + $aud_timing_loss, 2);

		$active_markets = [
			[
				'currency'              => 'EUR',
				'currency_name'         => 'Euro',
				'country'               => 'Eurozone',
				'country_code'          => 'EU',
				'flag_emoji'            => '🇪🇺',
				'foreign_volume'        => round(28000.0 * $multiplier, 2),
				'order_count'           => (int) round(28 * $multiplier),
				'order_exchange_rate'   => 0.900,
				'spot_exchange_rate'    => 0.925,
				'rate_change_pct'       => -2.7,
				'expected_store_amount' => round(31111.11 * $multiplier, 2),
				'current_store_value'   => round(30270.27 * $multiplier, 2),
				'market_timing_loss'    => $eur_timing_loss,
				'is_timing_loss'        => true,
				'gateway_spread_loss'   => round(912.20 * $multiplier, 2),
				'total_currency_drag'   => round(912.20 * $multiplier + $eur_timing_loss, 2),
			],
			[
				'currency'              => 'GBP',
				'currency_name'         => 'British Pound',
				'country'               => 'United Kingdom',
				'country_code'          => 'GB',
				'flag_emoji'            => '🇬🇧',
				'foreign_volume'        => round(9600.0 * $multiplier, 2),
				'order_count'           => (int) round(12 * $multiplier),
				'order_exchange_rate'   => 0.770,
				'spot_exchange_rate'    => 0.785,
				'rate_change_pct'       => -1.9,
				'expected_store_amount' => round(12467.53 * $multiplier, 2),
				'current_store_value'   => round(12229.30 * $multiplier, 2),
				'market_timing_loss'    => $gbp_timing_loss,
				'is_timing_loss'        => true,
				'gateway_spread_loss'   => round(328.10 * $multiplier, 2),
				'total_currency_drag'   => round(328.10 * $multiplier + $gbp_timing_loss, 2),
			],
			[
				'currency'              => 'CAD',
				'currency_name'         => 'Canadian Dollar',
				'country'               => 'Canada',
				'country_code'          => 'CA',
				'flag_emoji'            => '🇨🇦',
				'foreign_volume'        => round(4200.0 * $multiplier, 2),
				'order_count'           => (int) round(5 * $multiplier),
				'order_exchange_rate'   => 1.345,
				'spot_exchange_rate'    => 1.355,
				'rate_change_pct'       => -0.7,
				'expected_store_amount' => round(3122.68 * $multiplier, 2),
				'current_store_value'   => round(3099.63 * $multiplier, 2),
				'market_timing_loss'    => $cad_timing_loss,
				'is_timing_loss'        => true,
				'gateway_spread_loss'   => round(114.30 * $multiplier, 2),
				'total_currency_drag'   => round(114.30 * $multiplier + $cad_timing_loss, 2),
			],
			[
				'currency'              => 'AUD',
				'currency_name'         => 'Australian Dollar',
				'country'                => 'Australia',
				'country_code'          => 'AU',
				'flag_emoji'            => '🇦🇺',
				'foreign_volume'        => round(2800.0 * $multiplier, 2),
				'order_count'           => (int) round(3 * $multiplier),
				'order_exchange_rate'   => 1.520,
				'spot_exchange_rate'    => 1.515,
				'rate_change_pct'       => 0.3,
				'expected_store_amount' => round(1842.11 * $multiplier, 2),
				'current_store_value'   => round(1848.18 * $multiplier, 2),
				'market_timing_loss'    => 0.0,
				'is_timing_loss'        => false,
				'gateway_spread_loss'   => round(65.90 * $multiplier, 2),
				'total_currency_drag'   => round(65.90 * $multiplier, 2),
			],
		];

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
				'orders'          => (int) round(24 * $multiplier),
				'volume'          => round(28450.00 * $multiplier, 2),
				'loss'            => round(825.20 * $multiplier, 2),
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
				'orders'          => (int) round(18 * $multiplier),
				'volume'          => round(19200.00 * $multiplier, 2),
				'loss'            => round(442.10 * $multiplier, 2),
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
				'orders'          => (int) round(6 * $multiplier),
				'volume'          => round(6850.00 * $multiplier, 2),
				'loss'            => round(153.20 * $multiplier, 2),
				'loss_share_pct'  => 10.8,
			],
		];

		$products_by_gateway = [
			[
				'product_id'      => 101,
				'product_name'    => 'Wireless Noise-Cancelling Headphones Pro',
				'payment_method'  => 'paypal',
				'gateway_name'    => 'PayPal',
				'badge_color'     => '#0284C7',
				'units_sold'      => (int) round(14 * $multiplier),
				'foreign_revenue' => round(4890.00 * $multiplier, 2),
				'order_currency'  => 'EUR',
				'attributed_loss' => round(312.40 * $multiplier, 2),
				'loss_share_pct'  => 22.0,
			],
			[
				'product_id'      => 102,
				'product_name'    => 'Ultra-Wide 4K Gaming Monitor 34"',
				'payment_method'  => 'paypal',
				'gateway_name'    => 'PayPal',
				'badge_color'     => '#0284C7',
				'units_sold'      => (int) round(6 * $multiplier),
				'foreign_revenue' => round(5420.00 * $multiplier, 2),
				'order_currency'  => 'GBP',
				'attributed_loss' => round(315.30 * $multiplier, 2),
				'loss_share_pct'  => 22.2,
			],
			[
				'product_id'      => 103,
				'product_name'    => 'Mechanical Ergonomic Keyboard RGB',
				'payment_method'  => 'stripe',
				'gateway_name'    => 'Stripe',
				'badge_color'     => '#6366F1',
				'units_sold'      => (int) round(18 * $multiplier),
				'foreign_revenue' => round(3580.00 * $multiplier, 2),
				'order_currency'  => 'EUR',
				'attributed_loss' => round(245.10 * $multiplier, 2),
				'loss_share_pct'  => 17.3,
			],
			[
				'product_id'      => 104,
				'product_name'    => 'Smart Fitness Tracker Band V4',
				'payment_method'  => 'paypal',
				'gateway_name'    => 'PayPal',
				'badge_color'     => '#0284C7',
				'units_sold'      => (int) round(22 * $multiplier),
				'foreign_revenue' => round(2860.00 * $multiplier, 2),
				'order_currency'  => 'EUR',
				'attributed_loss' => round(197.50 * $multiplier, 2),
				'loss_share_pct'  => 13.9,
			],
			[
				'product_id'      => 105,
				'product_name'    => 'Waterproof Trail Running Shoes',
				'payment_method'  => 'stripe',
				'gateway_name'    => 'Stripe',
				'badge_color'     => '#6366F1',
				'units_sold'      => (int) round(12 * $multiplier),
				'foreign_revenue' => round(1920.00 * $multiplier, 2),
				'order_currency'  => 'CAD',
				'attributed_loss' => round(114.30 * $multiplier, 2),
				'loss_share_pct'  => 8.0,
			],
			[
				'product_id'      => 106,
				'product_name'    => 'Fast USB-C GaN 100W Charger',
				'payment_method'  => 'woocommerce_payments',
				'gateway_name'    => 'WooPayments',
				'badge_color'     => '#7C3AED',
				'units_sold'      => (int) round(20 * $multiplier),
				'foreign_revenue' => round(1840.00 * $multiplier, 2),
				'order_currency'  => 'GBP',
				'attributed_loss' => round(92.50 * $multiplier, 2),
				'loss_share_pct'  => 6.5,
			],
			[
				'product_id'      => 107,
				'product_name'    => 'Anodized Aluminum Laptop Stand',
				'payment_method'  => 'stripe',
				'gateway_name'    => 'Stripe',
				'badge_color'     => '#6366F1',
				'units_sold'      => (int) round(15 * $multiplier),
				'foreign_revenue' => round(1480.00 * $multiplier, 2),
				'order_currency'  => 'EUR',
				'attributed_loss' => round(82.70 * $multiplier, 2),
				'loss_share_pct'  => 5.8,
			],
			[
				'product_id'      => 108,
				'product_name'    => 'Leather Minimalist Card Wallet',
				'payment_method'  => 'woocommerce_payments',
				'gateway_name'    => 'WooPayments',
				'badge_color'     => '#7C3AED',
				'units_sold'      => (int) round(16 * $multiplier),
				'foreign_revenue' => round(1120.00 * $multiplier, 2),
				'order_currency'  => 'AUD',
				'attributed_loss' => round(60.70 * $multiplier, 2),
				'loss_share_pct'  => 4.3,
			],
		];

		$summary = [
			'period_days'                  => $days,
			'store_currency'               => 'USD',
			'total_loss'                   => round($baseLoss, 2),
			'order_count'                  => $baseOrders,
			'avg_loss_per_order'           => round($baseLoss / $baseOrders, 2),
			'annualized_run_rate'          => round(($baseLoss / $days) * 365, 2),
			'severity_level'               => 'critical',
			'top_currency'                 => 'EUR',
			'by_currency'                  => $by_currency,
			'gateways'                     => $gateways,
			'products_by_gateway'          => $products_by_gateway,
			'total_market_timing_loss'     => $total_timing_loss,
			'total_combined_currency_drag' => round($baseLoss + $total_timing_loss, 2),
			'active_markets'               => $active_markets,
			'is_mock'                      => true,
		];
	}

	header('Content-Type: text/html; charset=utf-8');
	include __DIR__ . '/templates/partials/summary-cards.php';
	exit;
}

// 3. Handle REST Endpoint: /wp-json/finlyzer/v1/insight
if ($uri === '/wp-json/finlyzer/v1/insight') {
	$days = isset($_GET['days']) ? (int) $_GET['days'] : 30;

	if ($current_mode === 'live') {
		$insight = "EXPOSURE AUDIT OPTIMAL: Zero cross-currency orders recorded in the past {$days} days. Your store currently incurs no payment gateway FX markup erosion.";
	} else {
		$multiplier = match ($days) {
			60 => 1.85,
			90 => 2.70,
			default => 1.00,
		};
		$totalLossFormatted = '$' . number_format(1420.50 * $multiplier, 2);
		$runRateFormatted = '$' . number_format((1420.50 * $multiplier / $days) * 365, 2);

		$insight = "CAPITAL EROSION WARNING: Your store quietly surrendered {$totalLossFormatted} in payment gateway spreads over the past {$days} days, driven primarily by EUR transactions (64.2% of total drain). At this current trajectory, unhedged spread markup represents an annualized profit drain of approximately {$runRateFormatted}. Immediate strategic remediation: configure native EUR settlement or enable multi-currency pricing to retain this margin in store profit.";
	}

	$error = null;
	header('Content-Type: text/html; charset=utf-8');
	include __DIR__ . '/templates/partials/insight-note.php';
	exit;
}

// 4. Main Admin Preview Shell
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Finlyzer — Preview Test Environment</title>
	<!-- Finlyzer Modern Chic Stylesheet -->
	<link rel="stylesheet" href="/assets/css/dashboard.css?v=<?php echo time(); ?>">
	<style>
		body {
			margin: 0;
			padding: 40px 24px;
			background: #06090F;
			color: #F3F5F8;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
			min-height: 100vh;
			display: flex;
			flex-direction: column;
			align-items: center;
		}
		.wp-admin-preview-bar {
			width: 100%;
			max-width: 1440px;
			display: flex;
			align-items: center;
			justify-content: space-between;
			background: #0E1420;
			border: 1px solid rgba(255, 255, 255, 0.08);
			padding: 12px 20px;
			border-radius: 12px;
			margin-bottom: 24px;
			font-size: 0.84rem;
			color: #94A3B8;
			flex-wrap: wrap;
			gap: 12px;
		}
		.preview-pill {
			background: rgba(16, 185, 129, 0.15);
			color: #34D399;
			border: 1px solid rgba(16, 185, 129, 0.3);
			padding: 4px 12px;
			border-radius: 9999px;
			font-weight: 700;
			font-size: 0.72rem;
			letter-spacing: 0.04em;
		}
		.preview-mode-toggle {
			display: flex;
			align-items: center;
			gap: 8px;
		}
		.preview-toggle-btn {
			background: rgba(255, 255, 255, 0.06);
			color: #94A3B8;
			border: 1px solid rgba(255, 255, 255, 0.12);
			padding: 4px 12px;
			border-radius: 6px;
			text-decoration: none;
			font-size: 0.75rem;
			font-weight: 600;
			transition: all 0.2s ease;
		}
		.preview-toggle-btn:hover {
			background: rgba(255, 255, 255, 0.12);
			color: #FFFFFF;
		}
		.preview-toggle-btn.is-active {
			background: #FFFFFF;
			color: #06090F;
			border-color: #FFFFFF;
		}
	</style>
</head>
<body>

	<div class="wp-admin-preview-bar">
		<div>
			<strong>Finlyzer Local Preview Harness</strong> &bull; WooCommerce 8.0+ HPOS Mock
		</div>
		<div class="preview-mode-toggle">
			<span>Data Source:</span>
			<a href="?finlyzer_mode=mock" class="preview-toggle-btn <?php echo $current_mode === 'mock' ? 'is-active' : ''; ?>">
				Mock Mode (Telemetry)
			</a>
			<a href="?finlyzer_mode=live" class="preview-toggle-btn <?php echo $current_mode === 'live' ? 'is-active' : ''; ?>">
				Live Mode (Zero Baseline)
			</a>
		</div>
		<div>
			<span class="preview-pill">ONLINE &bull; 127.0.0.1:8088</span>
		</div>
	</div>

	<!-- Render the exact Finlyzer dashboard template -->
	<?php include __DIR__ . '/templates/dashboard.php'; ?>

	<!-- Enqueue vendored htmx script -->
	<script src="/assets/js/vendor/htmx.min.js"></script>

	<!-- Localize Finlyzer REST parameters -->
	<script>
		window.Finlyzer = {
			restUrl: '/wp-json/finlyzer/v1',
			nonce: '<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>'
		};
		window.FXLI = window.Finlyzer;
	</script>

	<!-- Enqueue dashboard script -->
	<script src="/assets/js/dashboard.js"></script>

</body>
</html>
