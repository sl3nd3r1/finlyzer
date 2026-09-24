<?php
/**
 * Plugin Name:       Finlyzer — FX Loss & Margin Insights for WooCommerce
 * Plugin URI:        https://github.com/sl3nd3r1/finlyzer
 * Description:       Track hidden payment gateway conversion fees and currency loss across your international WooCommerce sales.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * Author:            Finlyzer Core Team & RayGens
 * Author URI:        https://www.linkedin.com/in/ebrahimrazmahang
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       finlyzer
 * Domain Path:       /languages
 *
 * @package           Finlyzer
 * @author            Finlyzer Core Team & RayGens
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
 */

declare(strict_types=1);

// prevent direct script execution outside WordPress context
if (!defined('ABSPATH')) {
	exit;
}

// core plugin constants
define('FINLYZER_VERSION', '1.0.0');
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
require_once FINLYZER_PLUGIN_DIR . 'includes/class-fxli-order-analyzer.php';
require_once FINLYZER_PLUGIN_DIR . 'includes/class-fxli-gemini-client.php';
require_once FINLYZER_PLUGIN_DIR . 'includes/class-fxli-rest-api.php';
require_once FINLYZER_PLUGIN_DIR . 'includes/class-fxli-admin-page.php';

// verify WooCommerce availability and minimum supported version (8.0+)
function finlyzer_requirements_met(): bool {
	// check WooCommerce main class
	if (!class_exists('WooCommerce')) {
		return false;
	}
	// check WooCommerce version
	if (defined('WC_VERSION') && version_compare(WC_VERSION, '8.0', '<')) {
		return false;
	}
	return true;
}

// render admin notice if requirements fail
function finlyzer_admin_missing_requirements_notice(): void {
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e('Finlyzer requires WooCommerce 8.0 or newer to be installed and active.', 'finlyzer'); ?></p>
	</div>
	<?php
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

	// load internationalization translation files
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Safe translation loader for non-WordPress.org / local development fallbacks.
	load_plugin_textdomain('finlyzer', false, dirname(plugin_basename(__FILE__)) . '/languages');

	// initialize singletons
	FXLI_Order_Analyzer::instance();
	FXLI_Gemini_Client::instance();
	FXLI_REST_API::instance()->register_routes();
	FXLI_Admin_Page::instance();
}
add_action('plugins_loaded', 'finlyzer_bootstrap');

// register lifecycle hooks
register_activation_hook(__FILE__, ['FXLI_Installer', 'activate']);
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
