<?php
declare(strict_types=1);

// prevent direct script execution
if (!defined('ABSPATH')) {
	exit;
}

/**
 * REST API controller for Finlyzer frontend htmx requests.
 * All endpoints require shop manager capabilities and valid REST nonces.
 * Returns pre-escaped HTML fragments for direct htmx DOM swapping.
 */
final class FXLI_REST_API {

	private const NAMESPACE = 'fxli/v1';
	private const ALT_NAMESPACE = 'finlyzer/v1';

	private static ?self $instance = null;

	// singleton accessor
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	// register plugin REST endpoints on rest_api_init
	public function register_routes(): void {
		add_action('rest_api_init', function (): void {
			$namespaces = [self::NAMESPACE, self::ALT_NAMESPACE];

			foreach ($namespaces as $ns) {
				// register summary endpoint
				register_rest_route($ns, '/summary', [
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [$this, 'handle_summary'],
					'permission_callback' => [$this, 'permission_check'],
					'args'                => [
						'days' => [
							'required'          => false,
							'default'           => 30,
							'type'              => 'integer',
							'validate_callback' => static fn($v): bool => is_numeric($v) && (int) $v >= 7 && (int) $v <= 90,
							'sanitize_callback' => static fn($v): int => max(7, min(90, (int) $v)),
						],
					],
				]);

				// register insight endpoint
				register_rest_route($ns, '/insight', [
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [$this, 'handle_insight'],
					'permission_callback' => [$this, 'permission_check'],
					'args'                => [
						'days' => [
							'required'          => false,
							'default'           => 30,
							'type'              => 'integer',
							'validate_callback' => static fn($v): bool => is_numeric($v) && (int) $v >= 7 && (int) $v <= 90,
							'sanitize_callback' => static fn($v): int => max(7, min(90, (int) $v)),
						],
					],
				]);
			}
		});
	}

	// gate all endpoints with capability and nonce verification
	public function permission_check(WP_REST_Request $request): bool {
		// check logged in + manage_woocommerce capability
		if (!FXLI_Security::current_user_can_manage()) {
			return false;
		}

		// check explicit REST nonce header
		return FXLI_Security::verify_rest_nonce($request);
	}

	// serve clean HTML fragment directly to htmx, bypassing WordPress JSON serialization
	private function serve_html(string $html): WP_REST_Response {
		add_filter(
			'rest_pre_serve_request',
			static function (bool $served, WP_REST_Response $result, WP_REST_Request $request, WP_REST_Server $server) use ($html): bool {
				// send text/html headers explicitly through WordPress server
				$server->send_header('Content-Type', 'text/html; charset=utf-8');
				$server->send_header('Cache-Control', 'no-cache, private');

				// echo raw un-encoded HTML directly to output stream
				echo $html;

				// return true to signal WordPress core that response is fully served
				return true;
			},
			10,
			4
		);

		return new WP_REST_Response($html, 200, [
			'Content-Type'  => 'text/html; charset=utf-8',
			'Cache-Control' => 'no-cache, private',
		]);
	}

	// handle summary request and render escaped summary cards fragment
	public function handle_summary(WP_REST_Request $request): WP_REST_Response {
		$days = (int) $request->get_param('days');
		$summary = FXLI_Order_Analyzer::instance()->get_summary($days);

		// capture template output buffer
		ob_start();
		include FINLYZER_PLUGIN_DIR . 'templates/partials/summary-cards.php';
		$html = (string) ob_get_clean();

		return $this->serve_html($html);
	}

	// handle insight request and render escaped AI risk sentinel fragment
	public function handle_insight(WP_REST_Request $request): WP_REST_Response {
		$days = (int) $request->get_param('days');
		$summary = FXLI_Order_Analyzer::instance()->get_summary($days);
		$insight = FXLI_Gemini_Client::instance()->summarize($summary);

		$error = is_wp_error($insight) ? $insight->get_error_message() : null;

		// capture template output buffer
		ob_start();
		include FINLYZER_PLUGIN_DIR . 'templates/partials/insight-note.php';
		$html = (string) ob_get_clean();

		return $this->serve_html($html);
	}
}
