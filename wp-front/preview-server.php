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
	define('FINLYZER_VERSION', '1.2.0');
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
			'period_days'         => $days,
			'store_currency'      => 'USD',
			'total_loss'          => 0.0,
			'order_count'         => 0,
			'avg_loss_per_order'  => 0.0,
			'annualized_run_rate' => 0.0,
			'severity_level'      => 'optimal',
			'top_currency'        => '',
			'by_currency'         => [],
			'is_mock'             => false,
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

		$summary = [
			'period_days'         => $days,
			'store_currency'      => 'USD',
			'total_loss'          => round($baseLoss, 2),
			'order_count'         => $baseOrders,
			'avg_loss_per_order'  => round($baseLoss / $baseOrders, 2),
			'annualized_run_rate' => round(($baseLoss / $days) * 365, 2),
			'severity_level'      => 'critical',
			'top_currency'        => 'EUR',
			'by_currency'         => [
				'EUR' => ['currency' => 'EUR', 'orders' => (int) round(28 * $multiplier), 'loss' => round(912.20 * $multiplier, 2), 'share_pct' => 64.2],
				'GBP' => ['currency' => 'GBP', 'orders' => (int) round(12 * $multiplier), 'loss' => round(328.10 * $multiplier, 2), 'share_pct' => 23.1],
				'CAD' => ['currency' => 'CAD', 'orders' => (int) round(5 * $multiplier),  'loss' => round(114.30 * $multiplier, 2), 'share_pct' => 8.1],
				'AUD' => ['currency' => 'AUD', 'orders' => (int) round(3 * $multiplier),  'loss' => round(65.90 * $multiplier, 2),  'share_pct' => 4.6],
			],
			'is_mock'             => true,
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
