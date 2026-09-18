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
	public const NONCE_ACTION = 'wp_rest';

	// verify user has required shop management privileges
	public static function current_user_can_manage(): bool {
		// must be logged in and possess shop manager or admin capabilities
		return is_user_logged_in() && current_user_can(self::CAPABILITY);
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
		// check modern Finlyzer constant
		if (defined('FINLYZER_WORKER_HMAC_SECRET') && is_string(FINLYZER_WORKER_HMAC_SECRET) && FINLYZER_WORKER_HMAC_SECRET !== '') {
			return FINLYZER_WORKER_HMAC_SECRET;
		}

		// check legacy constant fallback
		if (defined('FXLI_WORKER_HMAC_SECRET') && is_string(FXLI_WORKER_HMAC_SECRET) && FXLI_WORKER_HMAC_SECRET !== '') {
			return FXLI_WORKER_HMAC_SECRET;
		}

		// fail closed and log configuration issue
		error_log('[Finlyzer] FINLYZER_WORKER_HMAC_SECRET is not configured in wp-config.php.');
		return '';
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
