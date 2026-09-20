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

				// register telemetry diagnostic logs endpoint
				register_rest_route($ns, '/logs', [
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [$this, 'handle_get_logs'],
					'permission_callback' => [$this, 'permission_check'],
				]);

				// register live connection test endpoint
				register_rest_route($ns, '/test-connection', [
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [$this, 'handle_test_connection'],
					'permission_callback' => [$this, 'permission_check'],
				]);

				// register clear logs endpoint
				register_rest_route($ns, '/clear-logs', [
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [$this, 'handle_clear_logs'],
					'permission_callback' => [$this, 'permission_check'],
				]);
			}
		});
	}

	// gate all endpoints with capability and nonce verification
	public function permission_check(WP_REST_Request $request): bool {
		// assert user has manage permissions
		if (!FXLI_Security::current_user_can_manage()) {
			if (class_exists('FXLI_Logger')) {
				FXLI_Logger::log('warn', 'AUTH', 'REST API request rejected: insufficient user permissions', [
					'endpoint' => $request->get_route(),
					'user_id'  => function_exists('get_current_user_id') ? get_current_user_id() : 0,
				]);
			}
			return false;
		}

		// strictly verify explicit REST nonce header
		$valid_nonce = FXLI_Security::verify_rest_nonce($request);
		if (!$valid_nonce && class_exists('FXLI_Logger')) {
			FXLI_Logger::log('warn', 'AUTH', 'REST API request rejected: invalid or missing X-WP-Nonce', [
				'endpoint' => $request->get_route(),
			]);
		}

		return $valid_nonce;
	}

	// serve clean HTML fragment directly to htmx, bypassing WordPress JSON serialization
	private function serve_html(string $html, int $status = 200): WP_REST_Response {
		add_filter(
			'rest_pre_serve_request',
			static function (bool $served, mixed $result, mixed $request, mixed $server) use ($html, $status): bool {
				// send text/html headers explicitly through WordPress server
				if ($server instanceof WP_REST_Server) {
					$server->set_status($status);
					$server->send_header('Content-Type', 'text/html; charset=utf-8');
					$server->send_header('Cache-Control', 'no-cache, private');
				} else {
					header('Content-Type: text/html; charset=utf-8');
					header('Cache-Control: no-cache, private');
					http_response_code($status);
				}

				// echo raw un-encoded HTML directly to output stream
				echo $html;

				// return true to signal WordPress core that response is fully served
				return true;
			},
			10,
			4
		);

		return new WP_REST_Response($html, $status, [
			'Content-Type'  => 'text/html; charset=utf-8',
			'Cache-Control' => 'no-cache, private',
		]);
	}

	// handle summary request and render escaped summary cards fragment
	public function handle_summary(WP_REST_Request $request): WP_REST_Response {
		$days = (int) $request->get_param('days');
		$summary = FXLI_Order_Analyzer::instance()->get_summary($days);

		// return 503 Service Unavailable if backend calculation API is unreachable
		if (is_wp_error($summary)) {
			if (class_exists('FXLI_Logger')) {
				FXLI_Logger::log('error', 'REST_API', sprintf('Summary analysis failed: %s (%s)', $summary->get_error_message(), $summary->get_error_code()), [
					'endpoint' => $request->get_route(),
					'code'     => $summary->get_error_code(),
				]);
			}

			return new WP_REST_Response([
				'code'    => $summary->get_error_code(),
				'message' => $summary->get_error_message(),
			], 503);
		}

		// capture template output buffer
		ob_start();
		include FINLYZER_PLUGIN_DIR . 'templates/partials/summary-cards.php';
		$html = (string) ob_get_clean();

		return $this->serve_html($html, 200);
	}

	// handle insight request and render escaped AI risk sentinel fragment
	public function handle_insight(WP_REST_Request $request): WP_REST_Response {
		$days = (int) $request->get_param('days');
		$summary = FXLI_Order_Analyzer::instance()->get_summary($days);

		// return 503 Service Unavailable if backend calculation API is unreachable
		if (is_wp_error($summary)) {
			if (class_exists('FXLI_Logger')) {
				FXLI_Logger::log('error', 'REST_API', sprintf('Insight order scan failed: %s (%s)', $summary->get_error_message(), $summary->get_error_code()), [
					'endpoint' => $request->get_route(),
					'code'     => $summary->get_error_code(),
				]);
			}

			return new WP_REST_Response([
				'code'    => $summary->get_error_code(),
				'message' => $summary->get_error_message(),
			], 503);
		}

		$insight = FXLI_Gemini_Client::instance()->summarize($summary);
		$error = is_wp_error($insight) ? $insight->get_error_message() : null;

		// capture template output buffer
		ob_start();
		include FINLYZER_PLUGIN_DIR . 'templates/partials/insight-note.php';
		$html = (string) ob_get_clean();

		return $this->serve_html($html, 200);
	}

	// retrieve recent telemetry diagnostic logs
	public function handle_get_logs(WP_REST_Request $request): WP_REST_Response {
		$logs = class_exists('FXLI_Logger') ? FXLI_Logger::get_recent_logs(50) : [];
		return new WP_REST_Response([
			'success' => true,
			'count'   => count($logs),
			'logs'    => $logs,
		], 200);
	}

	// execute live connection diagnostic handshake
	public function handle_test_connection(WP_REST_Request $request): WP_REST_Response {
		$diagnostics = class_exists('FXLI_Logger') ? FXLI_Logger::test_connection() : [
			'success' => false,
			'error'   => 'FXLI_Logger class is not loaded',
		];

		$status_code = !empty($diagnostics['success']) ? 200 : 503;
		return new WP_REST_Response($diagnostics, $status_code);
	}

	// clear stored telemetry diagnostic logs
	public function handle_clear_logs(WP_REST_Request $request): WP_REST_Response {
		$cleared = class_exists('FXLI_Logger') ? FXLI_Logger::clear_logs() : false;
		return new WP_REST_Response([
			'success' => $cleared,
			'message' => 'Telemetry logs cleared successfully.',
		], 200);
	}
}
