<?php
declare(strict_types=1);

// prevent direct script execution
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Central security core for Finlyzer.
 * Handles permission gating, REST nonce validation, HMAC signing, and prompt sanitization.
 */
final class FXLI_Security {

	public const CAPABILITY = 'manage_woocommerce';
	public const ADMIN_CAPABILITY = 'manage_options';
	public const NONCE_ACTION = 'wp_rest';

	// verify user has required shop management privileges or site administrator access
	public static function current_user_can_manage(): bool {
		// assert user session is active
		if (!is_user_logged_in()) {
			return false;
		}

		// grant access to shop managers (WooCommerce) or site administrators (WordPress Core)
		return current_user_can(self::CAPABILITY) || current_user_can(self::ADMIN_CAPABILITY);
	}

	// strictly verify the X-WP-Nonce header on REST requests
	public static function verify_rest_nonce(WP_REST_Request $request): bool {
		// extract nonce header
		$nonce = $request->get_header('X-WP-Nonce');
		if (!is_string($nonce) || $nonce === '') {
			return false;
		}

		// evaluate against standard REST nonce action
		return (bool) wp_verify_nonce($nonce, self::NONCE_ACTION);
	}

	// generate HMAC-SHA256 signature for server-to-server worker communication
	public static function sign_worker_payload(string $body, int $timestamp): string {
		// retrieve shared secret
		$secret = self::worker_shared_secret();
		if ($secret === '') {
			return '';
		}

		// sign payload with timestamp prefix to prevent replay attacks
		return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
	}

	// retrieve the worker shared secret from environment or wp-config constants
	public static function worker_shared_secret(): string {
		// retrieve configured secret via FXLI_Env
		$secret = class_exists('FXLI_Env') ? FXLI_Env::hmac_secret() : '';

		if ($secret === '') {
			// legacy direct constant fallback
			if (defined('FINLYZER_WORKER_HMAC_SECRET') && is_string(FINLYZER_WORKER_HMAC_SECRET) && FINLYZER_WORKER_HMAC_SECRET !== '') {
				$secret = FINLYZER_WORKER_HMAC_SECRET;
			} elseif (defined('FXLI_WORKER_HMAC_SECRET') && is_string(FXLI_WORKER_HMAC_SECRET) && FXLI_WORKER_HMAC_SECRET !== '') {
				$secret = FXLI_WORKER_HMAC_SECRET;
			}
		}

		// in production mode, enforce strict secret entropy and prohibit development defaults
		$is_prod = class_exists('FXLI_Env') ? FXLI_Env::is_production() : true;
		if ($is_prod) {
			if ($secret === '' || str_contains($secret, 'dev-ephemeral')) {
				if (class_exists('FXLI_Logger')) {
					FXLI_Logger::log(FXLI_Logger::LEVEL_ERROR, 'SECURITY', 'Production HMAC secret is unconfigured or using weak dev fallback.');
				}
				return '';
			}
			if (strlen($secret) < 32) {
				if (class_exists('FXLI_Logger')) {
					FXLI_Logger::log(FXLI_Logger::LEVEL_ERROR, 'SECURITY', 'Production HMAC secret has insufficient entropy (< 32 chars).');
				}
				return '';
			}
			return $secret;
		}

		// development mode: if secret is empty, allow dev ephemeral fallback for seamless DX
		if ($secret === '') {
			$secret = 'dev-ephemeral-secret-32-byte-hex-token';
		}

		return $secret;
	}

	// validate candidate secret strength against enterprise security constraints
	public static function validate_hmac_secret_strength(string $secret): bool|string {
		if (class_exists('FXLI_Crypto')) {
			return FXLI_Crypto::validate_secret_entropy($secret);
		}
		$clean = trim($secret);
		if (strlen($clean) < 32) {
			return 'Secret must be at least 32 characters long.';
		}
		return true;
	}

	// strict allow-list sanitization for prompt values sent to LLM proxy
	public static function sanitize_prompt_scalar(mixed $value): string {
		// handle boolean values
		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		// handle numeric values safely
		if (is_int($value) || is_float($value)) {
			return (string) $value;
		}

		// cast to string
		$raw = is_string($value) ? $value : '';

		// completely strip script and style blocks including their contents
		$no_scripts = preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $raw) ?? '';

		// strip remaining HTML tags
		$stripped = wp_strip_all_tags($no_scripts);

		// allow-list characters strictly: alphanumeric, spaces, and minimal punctuation
		$sanitized = preg_replace('/[^A-Za-z0-9 .,:%\-]/', '', $stripped) ?? '';

		// collapse multiple consecutive spaces
		$normalized = preg_replace('/\s+/', ' ', $sanitized) ?? '';

		// limit string length to 64 characters to prevent prompt bloat or injection attempts
		return mb_substr(trim($normalized), 0, 64);
	}
}
