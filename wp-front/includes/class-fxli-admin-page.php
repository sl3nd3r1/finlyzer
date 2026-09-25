<?php
declare(strict_types=1);

// prevent direct script execution
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Registers wp-admin menu and enqueues Finlyzer frontend assets.
 */
final class FXLI_Admin_Page {

	private const MENU_SLUG = 'finlyzer';

	private static ?self $instance = null;

	// singleton accessor
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	// register admin hooks
	private function __construct() {
		add_action('admin_menu', [$this, 'register_menu']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
	}

	// register main admin menu item under WooCommerce
	public function register_menu(): void {
		// load custom SVG icon as base64 data URI for crisp, self-contained rendering
		$icon_file = FINLYZER_PLUGIN_DIR . 'assets/images/finlyzer-icon.svg';
		$menu_icon = file_exists($icon_file)
			? 'data:image/svg+xml;base64,' . base64_encode((string) file_get_contents($icon_file))
			: 'dashicons-chart-line';

		add_menu_page(
			__('Finlyzer — FX Loss & Margin Insights', 'finlyzer'),
			__('Finlyzer', 'finlyzer'),
			FXLI_Security::CAPABILITY,
			self::MENU_SLUG,
			[$this, 'render_page'],
			$menu_icon,
			56
		);
	}

	// enqueue vendored htmx, dashboard logic, and modern chic stylesheet
	public function enqueue_assets(string $hook): void {
		// verify hook matches Finlyzer admin page
		if (strpos($hook, self::MENU_SLUG) === false && strpos($hook, 'fx-loss-insights') === false) {
			return;
		}

		// enqueue locally vendored htmx (no third-party CDN requests, 100% GDPR compliant)
		wp_enqueue_script(
			'htmx',
			FINLYZER_PLUGIN_URL . 'assets/js/vendor/htmx.min.js',
			[],
			'2.0.3',
			true
		);

		// enqueue dashboard script
		wp_enqueue_script(
			'finlyzer-dashboard',
			FINLYZER_PLUGIN_URL . 'assets/js/dashboard.js',
			['htmx'],
			FINLYZER_VERSION,
			true
		);

		// enqueue chic fintech stylesheet
		wp_enqueue_style(
			'finlyzer-dashboard',
			FINLYZER_PLUGIN_URL . 'assets/css/dashboard.css',
			[],
			FINLYZER_VERSION
		);

		// localize configuration safely for htmx request headers
		$is_cloud_opted_in = class_exists('FXLI_Env') && FXLI_Env::is_cloud_opted_in();
		$localized = [
			'restUrl'    => esc_url_raw(rest_url('finlyzer/v1')),
			'nonce'      => wp_create_nonce('wp_rest'),
			'cloudOptIn' => $is_cloud_opted_in,
		];

		wp_localize_script('finlyzer-dashboard', 'Finlyzer', $localized);
		wp_localize_script('finlyzer-dashboard', 'FXLI', $localized);
	}

	// render main dashboard template with capability safeguard
	public function render_page(): void {
		if (!FXLI_Security::current_user_can_manage()) {
			wp_die(esc_html__('You do not have permission to view the Finlyzer dashboard.', 'finlyzer'));
		}
		include FINLYZER_PLUGIN_DIR . 'templates/dashboard.php';
	}
}
