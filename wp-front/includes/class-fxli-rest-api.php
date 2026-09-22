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
						'refresh' => [
							'required'          => false,
							'default'           => false,
							'type'              => 'boolean',
							'sanitize_callback' => static fn($v): bool => filter_var($v, FILTER_VALIDATE_BOOLEAN),
						],
						'force' => [
							'required'          => false,
							'default'           => false,
							'type'              => 'boolean',
							'sanitize_callback' => static fn($v): bool => filter_var($v, FILTER_VALIDATE_BOOLEAN),
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

				// register HMAC settings read endpoint
				register_rest_route($ns, '/settings/hmac', [
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [$this, 'handle_get_hmac_settings'],
					'permission_callback' => [$this, 'permission_check'],
				]);

				// register HMAC settings save endpoint
				register_rest_route($ns, '/settings/hmac', [
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [$this, 'handle_save_hmac_settings'],
					'permission_callback' => [$this, 'permission_check'],
					'args'                => [
						'secret' => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => static fn($v): string => sanitize_text_field((string) $v),
						],
						'action' => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => static fn($v): string => sanitize_key((string) $v),
						],
					],
				]);

				// register HMAC settings delete endpoint
				register_rest_route($ns, '/settings/hmac', [
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [$this, 'handle_delete_hmac_settings'],
					'permission_callback' => [$this, 'permission_check'],
				]);

				// register HMAC live handshake verification endpoint
				register_rest_route($ns, '/settings/hmac/verify', [
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [$this, 'handle_verify_hmac_settings'],
					'permission_callback' => [$this, 'permission_check'],
					'args'                => [
						'secret' => [
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => static fn($v): string => sanitize_text_field((string) $v),
						],
					],
				]);

				// register zero-touch cloud sentinel status endpoint
				register_rest_route($ns, '/settings/cloud-status', [
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [$this, 'handle_get_cloud_status'],
					'permission_callback' => [$this, 'permission_check'],
				]);

				// register zero-touch cloud sentinel re-sync endpoint
				register_rest_route($ns, '/settings/cloud-resync', [
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [$this, 'handle_cloud_resync'],
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
		$response = new WP_REST_Response($html, $status, [
			'Content-Type'           => 'text/html; charset=utf-8',
			'Cache-Control'          => 'no-cache, no-store, must-revalidate, private',
			'X-Content-Type-Options' => 'nosniff',
		]);

		add_filter(
			'rest_pre_serve_request',
			static function (bool $served, mixed $result, mixed $request, mixed $server) use ($html, $status, $response): bool {
				// skip execution if request already served or result does not match this specific response instance
				if ($served || $result !== $response) {
					return $served;
				}

				// send HTTP status header via canonical WordPress function; WP_REST_Server::set_status() is protected
				if (function_exists('status_header')) {
					status_header($status);
				} elseif (!headers_sent()) {
					http_response_code($status);
				}

				// send security and content headers safely through server or standard headers
				if ($server instanceof WP_REST_Server) {
					$server->send_header('Content-Type', 'text/html; charset=utf-8');
					$server->send_header('Cache-Control', 'no-cache, no-store, must-revalidate, private');
					$server->send_header('X-Content-Type-Options', 'nosniff');
				} elseif (!headers_sent()) {
					header('Content-Type: text/html; charset=utf-8');
					header('Cache-Control: no-cache, no-store, must-revalidate, private');
					header('X-Content-Type-Options: nosniff');
				}

				// echo raw un-encoded HTML directly to output stream
				echo $html;

				// return true to signal WordPress core that response is fully served
				return true;
			},
			10,
			4
		);

		return $response;
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

		$force_refresh = (bool) $request->get_param('refresh') || (bool) $request->get_param('force');
		$insight = FXLI_Gemini_Client::instance()->summarize($summary, $force_refresh);
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

	// retrieve current HMAC secret status and masked preview safely
	public function handle_get_hmac_settings(WP_REST_Request $request): WP_REST_Response {
		$source = class_exists('FXLI_Env') ? FXLI_Env::hmac_secret_source() : 'none';
		$is_locked = class_exists('FXLI_Env') && FXLI_Env::is_hmac_secret_locked();
		$secret = class_exists('FXLI_Env') ? FXLI_Env::hmac_secret() : '';
		$is_configured = $secret !== '' && !str_contains($secret, 'dev-ephemeral');
		$masked = class_exists('FXLI_Crypto') ? FXLI_Crypto::mask_secret($secret) : (class_exists('FXLI_Env') ? FXLI_Env::get_masked_hmac_secret() : '');

		return new WP_REST_Response([
			'success'       => true,
			'configured'    => $is_configured,
			'source'        => $source,
			'is_locked'     => $is_locked,
			'masked_secret' => $masked,
			'secret_length' => strlen($secret),
			'endpoint'      => class_exists('FXLI_Env') ? FXLI_Env::worker_endpoint() : '',
			'environment'   => class_exists('FXLI_Env') ? FXLI_Env::current_env() : 'production',
		], 200);
	}

	// save or generate HMAC secret with authenticated at-rest encryption
	public function handle_save_hmac_settings(WP_REST_Request $request): WP_REST_Response {
		// assert secret is not locked by server environment or wp-config.php
		if (class_exists('FXLI_Env') && FXLI_Env::is_hmac_secret_locked()) {
			return new WP_REST_Response([
				'success' => false,
				'error'   => 'locked_by_configuration',
				'message' => __('HMAC secret is managed via server configuration (wp-config.php or environment) and cannot be overridden in the database.', 'finlyzer'),
			], 400);
		}

		$action = (string) $request->get_param('action');

		// 1. handle one-click secret generation
		if ($action === 'generate') {
			$new_secret = class_exists('FXLI_Crypto') ? FXLI_Crypto::generate_secret(32) : bin2hex(random_bytes(32));
			$save_result = class_exists('FXLI_Crypto') ? FXLI_Crypto::save_secret($new_secret) : false;

			if ($save_result !== true) {
				return new WP_REST_Response([
					'success' => false,
					'error'   => 'save_failed',
					'message' => is_string($save_result) ? $save_result : __('Failed to save generated secret.', 'finlyzer'),
				], 500);
			}

			return new WP_REST_Response([
				'success'       => true,
				'message'       => __('New 64-character high-entropy secret generated and encrypted at rest.', 'finlyzer'),
				'generated_key' => $new_secret, // rendered once on explicit user generation so user can configure Cloudflare Worker
				'masked_secret' => class_exists('FXLI_Crypto') ? FXLI_Crypto::mask_secret($new_secret) : '',
				'secret_length' => strlen($new_secret),
				'source'        => 'database',
				'is_locked'     => false,
			], 200);
		}

		// 2. handle user-provided secret
		$raw_secret = (string) $request->get_param('secret');
		$clean_secret = trim($raw_secret);

		$validation = class_exists('FXLI_Crypto') ? FXLI_Crypto::validate_secret_entropy($clean_secret) : true;
		if ($validation !== true) {
			return new WP_REST_Response([
				'success' => false,
				'error'   => 'invalid_entropy',
				'message' => is_string($validation) ? $validation : __('Secret has insufficient entropy (< 32 chars).', 'finlyzer'),
			], 400);
		}

		$save_result = class_exists('FXLI_Crypto') ? FXLI_Crypto::save_secret($clean_secret) : false;
		if ($save_result !== true) {
			return new WP_REST_Response([
				'success' => false,
				'error'   => 'encryption_error',
				'message' => is_string($save_result) ? $save_result : __('Encryption failed.', 'finlyzer'),
			], 500);
		}

		return new WP_REST_Response([
			'success'       => true,
			'message'       => __('HMAC secret encrypted and saved to database successfully.', 'finlyzer'),
			'masked_secret' => class_exists('FXLI_Crypto') ? FXLI_Crypto::mask_secret($clean_secret) : '',
			'secret_length' => strlen($clean_secret),
			'source'        => 'database',
			'is_locked'     => false,
		], 200);
	}

	// delete stored database secret
	public function handle_delete_hmac_settings(WP_REST_Request $request): WP_REST_Response {
		if (class_exists('FXLI_Env') && FXLI_Env::is_hmac_secret_locked()) {
			return new WP_REST_Response([
				'success' => false,
				'error'   => 'locked_by_configuration',
				'message' => __('HMAC secret is defined via server configuration and cannot be deleted.', 'finlyzer'),
			], 400);
		}

		if (class_exists('FXLI_Crypto')) {
			FXLI_Crypto::delete_stored_secret();
		}

		return new WP_REST_Response([
			'success' => true,
			'message' => __('Database secret deleted successfully.', 'finlyzer'),
		], 200);
	}

	// execute live connection verification handshake
	public function handle_verify_hmac_settings(WP_REST_Request $request): WP_REST_Response {
		$candidate_secret = $request->get_param('secret');
		$secret = is_string($candidate_secret) && $candidate_secret !== '' ? trim($candidate_secret) : null;

		$client = class_exists('FXLI_Gemini_Client') ? FXLI_Gemini_Client::instance() : null;
		if (!$client) {
			return new WP_REST_Response([
				'success' => false,
				'message' => 'FXLI_Gemini_Client is not available.',
			], 500);
		}

		$handshake = $client->verify_handshake($secret);
		$status_code = !empty($handshake['success']) ? 200 : (isset($handshake['status_code']) ? (int) $handshake['status_code'] : 503);

		return new WP_REST_Response($handshake, $status_code);
	}

	// retrieve high-level zero-touch Cloud Sentinel status
	public function handle_get_cloud_status(WP_REST_Request $request): WP_REST_Response {
		$site_id = class_exists('FXLI_Gemini_Client') ? FXLI_Gemini_Client::site_id() : '';
		$short_site_id = strlen($site_id) >= 12 ? substr($site_id, 0, 4) . '••••••••' . substr($site_id, -4) : $site_id;
		$source = class_exists('FXLI_Env') ? FXLI_Env::hmac_secret_source() : 'none';
		$is_locked = class_exists('FXLI_Env') && FXLI_Env::is_hmac_secret_locked();
		$env = class_exists('FXLI_Env') ? FXLI_Env::current_env() : 'production';

		// verify if secret is currently active
		$active_secret = class_exists('FXLI_Env') ? FXLI_Env::hmac_secret() : '';
		$is_paired = $active_secret !== '' && !str_contains($active_secret, 'dev-ephemeral');

		return new WP_REST_Response([
			'success'          => true,
			'connected'        => $is_paired,
			'status'           => $is_paired ? 'active' : 'unpaired',
			'site_id'          => $site_id,
			'short_site_id'    => $short_site_id,
			'mode'             => 'zero_touch_automated',
			'encryption'       => 'AES-256-GCM',
			'privacy_standard' => 'zero_pii',
			'source'           => $source,
			'is_locked'        => $is_locked,
			'environment'      => $env,
		], 200);
	}

	// execute automated zero-touch pairing re-synchronization
	public function handle_cloud_resync(WP_REST_Request $request): WP_REST_Response {
		if (class_exists('FXLI_Crypto')) {
			$result = FXLI_Crypto::force_re_pair();
			$status_code = !empty($result['success']) ? 200 : 503;
			return new WP_REST_Response($result, $status_code);
		}

		return new WP_REST_Response([
			'success' => false,
			'message' => __('FXLI_Crypto service is unavailable.', 'finlyzer'),
		], 500);
	}
}
