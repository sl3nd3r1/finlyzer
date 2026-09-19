<?php
declare(strict_types=1);

/**
 * Finlyzer CLI Tool — Bulk International Order Generator for WooCommerce
 *
 * Usage:
 *   php bin/generate-orders.php [options]
 *
 * Options:
 *   --count=N         Number of orders to generate (default: 30, max: 250)
 *   --days=N          Historical lookback window in days (default: 30)
 *   --currencies=LIST Comma-separated currency codes (default: EUR,GBP,CAD,AUD,JPY,SEK)
 *   --gateways=LIST   Comma-separated gateways (default: stripe,paypal,klarna)
 *   --clean           Remove all previously generated sample orders before generation
 *   --path=PATH       Explicit path to WordPress installation directory
 *
 * Architecture:
 * - Compatible with XAMPP (Linux: /opt/lampp/htdocs/wordpress, Windows: C:/xampp/htdocs/wordpress)
 * - Compatible with HPOS (High-Performance Order Storage) and WooCommerce 8.x - 11.x.
 * - Injects custom gateway titles, fake transaction IDs, and sets completed status.
 */

// enforce CLI execution only
if (PHP_SAPI !== 'cli') {
	echo "This script can only be executed via the command line.\n";
	exit(1);
}

// parse CLI arguments
$options = getopt('', [
	'count::',
	'days::',
	'currencies::',
	'gateways::',
	'clean',
	'path::',
	'help',
]);

if (isset($options['help'])) {
	echo <<<HELP
================================================================
Finlyzer Automated International Order Generator (WooCommerce)
================================================================
Usage:
  php bin/generate-orders.php [options]

Options:
  --count=N         Number of orders to generate (default: 30)
  --days=N          Lookback window in days (default: 30)
  --currencies=LIST Comma-separated currencies (e.g. EUR,GBP,CAD,AUD,JPY,SEK)
  --gateways=LIST   Comma-separated gateways (stripe,paypal,klarna)
  --clean           Remove previously generated test orders
  --path=PATH       Path to WordPress root (e.g. /opt/lampp/htdocs/wordpress)
  --help            Display this help message

HELP;
	exit(0);
}

// candidate search paths for wp-load.php
$candidate_paths = [];

// check explicit CLI path
if (!empty($options['path'])) {
	$p = rtrim($options['path'], '/\\');
	$candidate_paths[] = $p . '/wp-load.php';
}

// relative path when installed inside wp-content/plugins/finlyzer/bin
$candidate_paths[] = dirname(__DIR__, 4) . '/wp-load.php';
$candidate_paths[] = dirname(__DIR__, 3) . '/wp-load.php';

// common local dev and XAMPP installations (Linux & Windows)
$candidate_paths[] = '/opt/lampp/htdocs/wordpress/wp-load.php';
$candidate_paths[] = '/var/www/html/wordpress/wp-load.php';
$candidate_paths[] = '/var/www/wordpress/wp-load.php';
$candidate_paths[] = 'C:/xampp/htdocs/wordpress/wp-load.php';
$candidate_paths[] = 'C:\\xampp\\htdocs\\wordpress\\wp-load.php';
$candidate_paths[] = 'D:/xampp/htdocs/wordpress/wp-load.php';

$wp_load_path = null;
foreach ($candidate_paths as $candidate) {
	if (file_exists($candidate)) {
		$wp_load_path = $candidate;
		break;
	}
}

// verify WordPress bootstrap file exists
if (!$wp_load_path) {
	echo "\n❌ ERROR: Could not locate 'wp-load.php'.\n";
	echo "Please provide the path using --path=/path/to/wordpress\n";
	echo "Example: php bin/generate-orders.php --path=/opt/lampp/htdocs/wordpress\n\n";
	exit(1);
}

echo "--> Bootstrapping WordPress environment from: {$wp_load_path}\n";

// define CLI environment constants before loading WordPress
define('DOING_CRON', false);
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once $wp_load_path;

// assert WooCommerce is loaded
if (!function_exists('wc_create_order')) {
	echo "\n❌ ERROR: WooCommerce is not active in this WordPress installation.\n";
	echo "Please activate WooCommerce before running the order generator.\n\n";
	exit(1);
}

// ensure Finlyzer Order Generator is available
$generator_file = dirname(__DIR__) . '/includes/class-fxli-order-generator.php';
if (file_exists($generator_file)) {
	require_once $generator_file;
}

if (!class_exists('FXLI_Order_Generator')) {
	echo "\n❌ ERROR: FXLI_Order_Generator class could not be loaded.\n\n";
	exit(1);
}

// clean previous test orders if requested
if (isset($options['clean'])) {
	echo "--> Cleaning previously generated sample orders...\n";
	$cleaned = FXLI_Order_Generator::clean();
	echo "✓ Cleaned {$cleaned} previous sample orders.\n\n";
}

// parse options
$count = isset($options['count']) ? max(1, min(250, (int) $options['count'])) : 30;
$days = isset($options['days']) ? max(7, min(90, (int) $options['days'])) : 30;

$currencies = isset($options['currencies'])
	? array_map('trim', explode(',', (string) $options['currencies']))
	: ['EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'SEK'];

$gateways = isset($options['gateways'])
	? array_map('trim', explode(',', (string) $options['gateways']))
	: ['stripe', 'paypal', 'klarna'];

echo "================================================================\n";
echo "🚀 FINLYZER BULK INTERNATIONAL ORDER GENERATOR\n";
echo "================================================================\n";
echo " Target Orders:      {$count}\n";
echo " Period Window:      {$days} days\n";
echo " Active Currencies:  " . implode(', ', $currencies) . "\n";
echo " Active Gateways:    " . implode(', ', $gateways) . "\n";
echo " Store Currency:     " . get_woocommerce_currency() . "\n";
echo "================================================================\n\n";

$start_time = microtime(true);

// execute order generation
$result = FXLI_Order_Generator::generate($count, [
	'days'       => $days,
	'currencies' => $currencies,
	'gateways'   => $gateways,
]);

$elapsed = round((microtime(true) - $start_time) * 1000, 2);

if (!$result['success']) {
	echo "❌ GENERATION FAILED: " . ($result['message'] ?? 'Unknown error') . "\n";
	exit(1);
}

$created = $result['created'];
echo "✅ Successfully generated {$created} completed international orders in {$elapsed}ms!\n\n";

// display sample generated orders
echo "Sample Generated Orders:\n";
echo str_repeat('-', 95) . "\n";
printf("%-8s | %-6s | %-10s | %-24s | %-22s | %-10s\n", "Order ID", "Curr", "Total", "Gateway Title", "Transaction ID", "Status");
echo str_repeat('-', 95) . "\n";

$orders = $result['orders'] ?? [];
$display_slice = array_slice($orders, 0, 10);
foreach ($display_slice as $ord) {
	printf(
		"#%-7d | %-6s | %-10.2f | %-24s | %-22s | %-10s\n",
		$ord['id'],
		$ord['currency'],
		(float) $ord['total'],
		substr($ord['gateway_title'], 0, 24),
		substr($ord['transaction_id'], 0, 22),
		$ord['status']
	);
}

if (count($orders) > 10) {
	echo "... and " . (count($orders) - 10) . " more orders.\n";
}
echo str_repeat('-', 95) . "\n\n";
echo "All orders saved with 'wc-completed' status.\n";
echo "Finlyzer dashboard will now show realistic multi-currency processor fee loss metrics.\n\n";
