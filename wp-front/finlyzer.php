<?php
/**
 * Plugin Name:       Finlyzer — FX Loss & Margin Insights for WooCommerce
 * Plugin URI:        https://github.com/sl3nd3r1/finlyzer
 * Description:       Track hidden payment gateway conversion fees and currency loss across your international WooCommerce sales.
 * Version:           1.9.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   11.1
 * Author:            Ebrahim Razmahang (RayGens)
 * Author URI:        https://www.linkedin.com/in/ebrahimrazmahang
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       finlyzer
 * Domain Path:       /languages
 *
 * @package           Finlyzer
 * @author            Ebrahim Razmahang (RayGens)
 * @license           GPL-2.0-or-later
 *
 * Security architecture (per mandatory-secure-web-skills):
 *  - Zero client-side credentials: API keys and HMAC secrets never reach the browser.
 *  - Server-to-server AI communication: WP -> Cloudflare Worker -> Gemini API.
 *  - Dual build architecture: isolated development & hardened production environment profiles.
 *  - Authenticated at-rest encryption (AES-256-GCM) with HKDF key derivation from WordPress salts.
 *  - Capability and nonce verification on all REST endpoints (manage_woocommerce + wp_rest).
 *  - Prepared SQL queries ($wpdb->prepare()) and strictly escaped template rendering.
 *  - Full High-Performance Order Storage (HPOS) compatibility declared.
 *  - Guideline 7 compliance: all remote external requests (Cloud AI, ECB exchange rates) are strictly opt-in only.
 */

declare(strict_types=1);

// prevent direct script execution outside WordPress context
if (!defined('ABSPATH')) {
	exit;
}

// core plugin constants
define('FINLYZER_VERSION', '1.9.1');
define('FINLYZER_DB_VERSION', '3');
define('FINLYZER_PLUGIN_FILE', __FILE__);
define('FINLYZER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FINLYZER_PLUGIN_URL', plugin_dir_url(__FILE__));

// backward compatibility aliases for existing codebase references
define('FXLI_VERSION', FINLYZER_VERSION);
define('FXLI_DB_VERSION', FINLYZER_DB_VERSION);
define('FXLI_PLUGIN_FILE', FINLYZER_PLUGIN_FILE);
define('FXLI_PLUGIN_DIR', FINLYZER_PLUGIN_DIR);
define('FXLI_PLUGIN_URL', FINLYZER_PLUGIN_URL);

// explicit file inclusion to keep attack surface minimal and auditable
require_once FINLYZER_PLUGIN_DIR . 'includes/class-fxli-crypto.php';
require_once FINLYZER_PLUGIN_DIR . 'includes/class-fxli-env.php';
require_once FINLYZER_PLUGIN_DIR . 'includes/class-fxli-security.php';
require_once FINLYZER_PLUGIN_DIR . 'includes/class-fxli-logger.php';
require_once FINLYZER_PLUGIN_DIR . 'includes/class-fxli-installer.php';
require_once FINLYZER_PLUGIN_DIR . 'includes/class-fxli-rate-service.php';
require_once FINLYZER_PLUGIN_DIR . 'includes/class-fxli-order-analyzer.php';
require_once FINLYZER_PLUGIN_DIR . 'includes/class-fxli-gemini-client.php';
require_once FINLYZER_PLUGIN_DIR . 'includes/class-fxli-rest-api.php';
require_once FINLYZER_PLUGIN_DIR . 'includes/class-fxli-admin-page.php';

/**
 * Verify system and environment compatibility requirements.
 *
 * @return array{met: bool, errors: list<string>}
 */
function finlyzer_check_requirements(): array {
	$errors = [];

	// verify minimum PHP version (8.1+)
	if (version_compare(PHP_VERSION, '8.1', '<')) {
		$errors[] = sprintf(
			/* translators: 1: current PHP version, 2: required PHP version */
			__('Finlyzer requires PHP %2$s or higher (currently running %1$s).', 'finlyzer'),
			PHP_VERSION,
			'8.1'
		);
	}

	// verify minimum WordPress version (6.4+)
	global $wp_version;
	if (isset($wp_version) && version_compare($wp_version, '6.4', '<')) {
		$errors[] = sprintf(
			/* translators: 1: current WordPress version, 2: required WordPress version */
			__('Finlyzer requires WordPress %2$s or higher (currently running %1$s).', 'finlyzer'),
			$wp_version,
			'6.4'
		);
	}

	// verify required PHP extensions
	$missing_extensions = [];
	foreach (['openssl', 'json', 'hash'] as $ext) {
		if (!extension_loaded($ext)) {
			$missing_extensions[] = $ext;
		}
	}
	if (!empty($missing_extensions)) {
		$errors[] = sprintf(
			/* translators: %s: list of missing PHP extensions */
			__('Finlyzer requires the following PHP extension(s): %s.', 'finlyzer'),
			implode(', ', $missing_extensions)
		);
	}

	// verify WooCommerce availability and minimum supported version (8.0+)
	if (!class_exists('WooCommerce')) {
		$errors[] = __('Finlyzer requires WooCommerce to be installed and active.', 'finlyzer');
	} elseif (defined('WC_VERSION') && version_compare(WC_VERSION, '8.0', '<')) {
		$errors[] = sprintf(
			/* translators: 1: current WooCommerce version, 2: required WooCommerce version */
			__('Finlyzer requires WooCommerce %2$s or higher (currently running %1$s).', 'finlyzer'),
			WC_VERSION,
			'8.0'
		);
	}

	return [
		'met'    => empty($errors),
		'errors' => $errors,
	];
}

// verify WooCommerce availability and minimum supported version (8.0+)
function finlyzer_requirements_met(): bool {
	return finlyzer_check_requirements()['met'];
}

// render admin notice if requirements fail
function finlyzer_admin_missing_requirements_notice(): void {
	$check = finlyzer_check_requirements();
	if ($check['met']) {
		return;
	}
	?>
	<div class="notice notice-warning is-dismissible">
		<p><strong><?php esc_html_e('Finlyzer — System Requirement Notice:', 'finlyzer'); ?></strong></p>
		<ul style="list-style-type: disc; margin-left: 20px;">
			<?php foreach ($check['errors'] as $error) : ?>
				<li><?php echo esc_html($error); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
}

// handle clean plugin activation with pre-flight requirement enforcement
function finlyzer_on_activate(): void {
	$check = finlyzer_check_requirements();

	// fail closed if host server fails essential PHP or crypto extension requirements
	if (version_compare(PHP_VERSION, '8.1', '<') || !extension_loaded('openssl') || !extension_loaded('json') || !extension_loaded('hash')) {
		if (function_exists('deactivate_plugins')) {
			deactivate_plugins(plugin_basename(__FILE__));
		}
		wp_die(
			esc_html(implode(' ', $check['errors'])),
			esc_html__('Finlyzer Activation Error', 'finlyzer'),
			['back_link' => true]
		);
	}

	// execute database schema migrations and cron registration
	FXLI_Installer::activate();
}

// initialize plugin components once all plugins have loaded
function finlyzer_bootstrap(): void {
	// abort bootstrap with warning if requirements are not satisfied
	if (!finlyzer_requirements_met()) {
		add_action('admin_notices', 'finlyzer_admin_missing_requirements_notice');
		return;
	}

	// self-healing schema migration: ensure tables exist even if plugin was updated via direct folder copy
	if (is_admin() && function_exists('get_option') && get_option('fxli_db_version') !== FINLYZER_DB_VERSION) {
		FXLI_Installer::activate();
	}

	// self-healing secret migration: encrypt any legacy plaintext secrets
	if (class_exists('FXLI_Crypto')) {
		FXLI_Crypto::auto_migrate();
	}

	// initialize singletons
	FXLI_Rate_Service::instance();
	FXLI_Order_Analyzer::instance();
	FXLI_Gemini_Client::instance();
	FXLI_REST_API::instance()->register_routes();
	FXLI_Admin_Page::instance();
}
add_action('plugins_loaded', 'finlyzer_bootstrap');

// register lifecycle hooks
register_activation_hook(__FILE__, 'finlyzer_on_activate');
register_deactivation_hook(__FILE__, ['FXLI_Installer', 'deactivate']);

// declare High-Performance Order Storage (HPOS) custom order tables compatibility
add_action('before_woocommerce_init', function (): void {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			__FILE__,
			true
		);
	}
});
