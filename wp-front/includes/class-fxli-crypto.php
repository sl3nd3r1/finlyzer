<?php
declare(strict_types=1);

// prevent direct script execution
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Enterprise Cryptographic Engine for Finlyzer.
 * Provides authenticated encryption at rest (AES-256-GCM) with HKDF key derivation
 * from WordPress server salts, safe secret masking, and entropy verification.
 */
final class FXLI_Crypto {

	public const CIPHER = 'aes-256-gcm';
	public const IV_LENGTH = 12; // 96-bit standard IV for GCM
	public const TAG_LENGTH = 16; // 128-bit authentication tag
	public const MIN_SECRET_LENGTH = 32;

	public const OPTION_ENCRYPTED_SECRET = 'finlyzer_worker_hmac_secret_encrypted';
	public const OPTION_LEGACY_PLAINTEXT_SECRET = 'finlyzer_worker_hmac_secret';

	// derive a deterministic 256-bit encryption key using HKDF-SHA256 from WordPress core salts
	public static function derive_encryption_key(): string {
		// collect server-level high-entropy salts from wp-config.php
		$salt_material = '';
		if (function_exists('wp_salt')) {
			$salt_material .= wp_salt('auth') . '|' . wp_salt('secure_auth');
		}

		// supplement with standard WordPress core salt constants if defined
		if (defined('AUTH_KEY') && is_string(AUTH_KEY) && AUTH_KEY !== '') {
			$salt_material .= '|' . AUTH_KEY;
		}
		if (defined('SECURE_AUTH_KEY') && is_string(SECURE_AUTH_KEY) && SECURE_AUTH_KEY !== '') {
			$salt_material .= '|' . SECURE_AUTH_KEY;
		}
		if (defined('LOGGED_IN_KEY') && is_string(LOGGED_IN_KEY) && LOGGED_IN_KEY !== '') {
			$salt_material .= '|' . LOGGED_IN_KEY;
		}

		// fallback for environments with missing salts (e.g. unconfigured dev or test setups)
		if ($salt_material === '') {
			$site_url = function_exists('home_url') ? home_url() : 'finlyzer-fallback-host';
			$salt_material = 'finlyzer-static-salt-material-entropy-buffer|' . $site_url;
		}

		// derive 32-byte (256-bit) key via HKDF-SHA256
		if (function_exists('hash_hkdf')) {
			return hash_hkdf('sha256', $salt_material, 32, 'finlyzer-at-rest-encryption-v1', 'finlyzer-storage-salt');
		}

		// fallback to PBKDF2 if hash_hkdf is unavailable
		return hash_pbkdf2('sha256', $salt_material, 'finlyzer-storage-salt', 10000, 32, true);
	}

	// encrypt sensitive secret at rest using authenticated AES-256-GCM
	public static function encrypt_secret(string $plaintext): string {
		$clean = trim($plaintext);
		if ($clean === '') {
			return '';
		}

		// derive key
		$key = self::derive_encryption_key();

		// generate cryptographically secure 96-bit IV
		$iv = random_bytes(self::IV_LENGTH);
		$tag = '';

		// execute authenticated encryption
		$ciphertext = openssl_encrypt(
			$clean,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'finlyzer-worker-hmac-v1',
			self::TAG_LENGTH
		);

		if ($ciphertext === false) {
			error_log('[Finlyzer Crypto] Failed to encrypt HMAC secret at rest.');
			return '';
		}

		// construct immutable envelope
		$envelope = [
			'v'      => 1,
			'cipher' => self::CIPHER,
			'iv'     => base64_encode($iv),
			'tag'    => base64_encode($tag),
			'data'   => base64_encode($ciphertext),
			'ts'     => time(),
		];

		$encoded = wp_json_encode($envelope);
		return is_string($encoded) ? $encoded : '';
	}

	// decrypt sensitive secret from JSON envelope with strict AEAD authentication tag verification
	public static function decrypt_secret(string $envelope_json): ?string {
		if ($envelope_json === '') {
			return null;
		}

		// decode envelope
		$envelope = json_decode($envelope_json, true);
		if (!is_array($envelope) || empty($envelope['iv']) || empty($envelope['tag']) || empty($envelope['data'])) {
			return null;
		}

		// verify cipher protocol
		if (($envelope['cipher'] ?? '') !== self::CIPHER) {
			error_log('[Finlyzer Crypto] Unsupported cipher in encrypted secret envelope: ' . ($envelope['cipher'] ?? 'unknown'));
			return null;
		}

		$iv = base64_decode((string) $envelope['iv'], true);
		$tag = base64_decode((string) $envelope['tag'], true);
		$ciphertext = base64_decode((string) $envelope['data'], true);

		if ($iv === false || $tag === false || $ciphertext === false) {
			return null;
		}

		if (strlen($iv) !== self::IV_LENGTH || strlen($tag) !== self::TAG_LENGTH) {
			return null;
		}

		$key = self::derive_encryption_key();

		// execute authenticated decryption (fails closed if tag does not match)
		$decrypted = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'finlyzer-worker-hmac-v1'
		);

		if ($decrypted === false) {
			error_log('[Finlyzer Crypto] AEAD authentication tag verification failed. Secret may be tampered or salts changed.');
			return null;
		}

		return $decrypted;
	}

	// safely mask secret for human UI inspection without leaking sensitive entropy
	public static function mask_secret(string $secret): string {
		$clean = trim($secret);
		$len = strlen($clean);

		if ($len === 0) {
			return '';
		}

		if ($len < 8) {
			return str_repeat('•', 16);
		}

		// display first 4 and last 4 characters, masking the rest
		$start = substr($clean, 0, 4);
		$end = substr($clean, -4);
		$bullet_count = max(8, min(32, $len - 8));

		return $start . str_repeat('•', $bullet_count) . $end;
	}

	// generate a cryptographically strong 64-character hexadecimal secret
	public static function generate_secret(int $bytes = 32): string {
		return bin2hex(random_bytes(max(16, $bytes)));
	}

	// validate secret strength and entropy
	public static function validate_secret_entropy(string $secret): bool|string {
		$clean = trim($secret);
		$len = strlen($clean);

		if ($len === 0) {
			return __('HMAC secret cannot be empty.', 'finlyzer');
		}

		if ($len < self::MIN_SECRET_LENGTH) {
			return sprintf(
				__('HMAC secret is too short (%d characters). It must be at least %d characters.', 'finlyzer'),
				$len,
				self::MIN_SECRET_LENGTH
			);
		}

		// prevent weak development fallback tokens
		if (str_contains(strtolower($clean), 'dev-ephemeral') || str_contains(strtolower($clean), 'change-me') || str_contains(strtolower($clean), 'secret-here')) {
			return __('HMAC secret contains a known insecure default token. Please use a high-entropy key.', 'finlyzer');
		}

		// verify characters are printable safe ASCII (hex or base64 or alphanumeric)
		if (!preg_match('/^[a-zA-Z0-9_\-\.\:\+=\/]+$/', $clean)) {
			return __('HMAC secret contains invalid characters. Use hex or alphanumeric characters.', 'finlyzer');
		}

		return true;
	}

	// persist secret in database with authenticated encryption
	public static function save_secret(string $secret): bool|string {
		$validation = self::validate_secret_entropy($secret);
		if ($validation !== true) {
			return $validation;
		}

		$encrypted = self::encrypt_secret($secret);
		if ($encrypted === '') {
			return __('Encryption failed. Please verify OpenSSL extension is enabled.', 'finlyzer');
		}

		// save encrypted envelope to wp_options
		if (function_exists('update_option')) {
			update_option(self::OPTION_ENCRYPTED_SECRET, $encrypted, false);
			// purge legacy plaintext option if present
			delete_option(self::OPTION_LEGACY_PLAINTEXT_SECRET);
			return true;
		}

		return false;
	}

	// retrieve decrypted secret from database
	public static function get_stored_secret(): ?string {
		if (!function_exists('get_option')) {
			return null;
		}

		$encrypted = get_option(self::OPTION_ENCRYPTED_SECRET, null);
		if (is_string($encrypted) && $encrypted !== '') {
			return self::decrypt_secret($encrypted);
		}

		// check for legacy unencrypted option and auto-migrate
		$legacy = get_option(self::OPTION_LEGACY_PLAINTEXT_SECRET, null);
		if (is_string($legacy) && $legacy !== '') {
			self::save_secret($legacy);
			return $legacy;
		}

		return null;
	}

	// purge stored database secret
	public static function delete_stored_secret(): bool {
		if (!function_exists('delete_option')) {
			return false;
		}
		delete_option(self::OPTION_ENCRYPTED_SECRET);
		delete_option(self::OPTION_LEGACY_PLAINTEXT_SECRET);
		return true;
	}

	// perform automatic self-healing migration from legacy plaintext option
	public static function auto_migrate(): void {
		if (!function_exists('get_option')) {
			return;
		}

		$legacy = get_option(self::OPTION_LEGACY_PLAINTEXT_SECRET, null);
		if (is_string($legacy) && $legacy !== '') {
			self::save_secret($legacy);
		}
	}
}
