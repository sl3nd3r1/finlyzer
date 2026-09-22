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

	// perform automated zero-touch site pairing with Cloudflare Worker
	public static function auto_pair_site(?string $worker_base_url = null): bool {
		// return early if already configured with valid secret in database
		$existing = self::get_stored_secret();
		if ($existing !== null && strlen($existing) >= self::MIN_SECRET_LENGTH && !str_contains($existing, 'dev-ephemeral')) {
			return true;
		}

		// return early if managed via wp-config.php constant
		if (defined('FINLYZER_WORKER_HMAC_SECRET') && is_string(FINLYZER_WORKER_HMAC_SECRET) && FINLYZER_WORKER_HMAC_SECRET !== '') {
			return true;
		}

		// resolve worker base URL
		$baseUrl = $worker_base_url;
		if ($baseUrl === null && class_exists('FXLI_Env')) {
			$baseUrl = FXLI_Env::worker_base_url();
		}
		if (empty($baseUrl)) {
			$endpoint = class_exists('FXLI_Env') ? FXLI_Env::worker_endpoint() : '';
			$parsed = parse_url($endpoint);
			if (!empty($parsed['scheme']) && !empty($parsed['host'])) {
				$baseUrl = $parsed['scheme'] . '://' . $parsed['host'] . (!empty($parsed['port']) ? ':' . $parsed['port'] : '');
			}
		}

		if (empty($baseUrl)) {
			return false;
		}

		$pairEndpoint = rtrim($baseUrl, '/') . '/api/v1/pair';
		$siteId = class_exists('FXLI_Gemini_Client') ? FXLI_Gemini_Client::site_id() : hash('sha256', (defined('AUTH_KEY') ? AUTH_KEY : 'finlyzer') . '|' . (function_exists('home_url') ? home_url() : 'localhost'));
		$siteUrl = function_exists('home_url') ? home_url() : '';
		$pluginVersion = defined('FINLYZER_VERSION') ? FINLYZER_VERSION : '1.26.0';
		$now = time();
		$nonce = function_exists('wp_generate_password') ? wp_generate_password(32, false) : bin2hex(random_bytes(16));
		$signature = hash_hmac('sha256', "finlyzer:pair:{$siteId}:{$now}:{$nonce}", $siteId);

		$payload = [
			'site_id'        => $siteId,
			'site_url'       => $siteUrl,
			'plugin_version' => $pluginVersion,
			'timestamp'      => $now,
			'nonce'          => $nonce,
			'signature'      => $signature,
		];

		if (!function_exists('wp_remote_post')) {
			return false;
		}

		// determine whether SSL verification should be relaxed for local development (e.g. XAMPP Windows localhost)
		$isLocal = function_exists('home_url') && (str_contains(home_url(), 'localhost') || str_contains(home_url(), '127.0.0.1'));
		$isDev = (class_exists('FXLI_Env') && FXLI_Env::current_env() === 'development') || $isLocal;
		$strictSsl = class_exists('FXLI_Env') ? FXLI_Env::strict_ssl() : true;
		$verifySsl = $strictSsl && !$isLocal && !$isDev;

		$response = wp_remote_post($pairEndpoint, [
			'timeout'   => 10,
			'sslverify' => $verifySsl,
			'headers'   => [
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			],
			'body'      => wp_json_encode($payload),
		]);

		if (is_wp_error($response)) {
			error_log('[Finlyzer Crypto] Automated pairing request failed: ' . $response->get_error_message());
			// check for fallback secret seed from configuration
			return self::seed_fallback_secret();
		}

		$statusCode = wp_remote_retrieve_response_code($response);
		if ($statusCode !== 200) {
			error_log('[Finlyzer Crypto] Automated pairing rejected with HTTP ' . $statusCode);
			// check for fallback secret seed from configuration
			return self::seed_fallback_secret();
		}

		$bodyText = wp_remote_retrieve_body($response);
		$data = json_decode($bodyText, true);
		if (!is_array($data) || empty($data['token']) || !is_string($data['token']) || strlen($data['token']) !== 64) {
			error_log('[Finlyzer Crypto] Invalid token structure received from pairing endpoint.');
			return self::seed_fallback_secret();
		}

		// save received token using authenticated AES-256-GCM encryption at rest
		$saved = self::save_secret($data['token']);
		return $saved === true;
	}

	// seed secret from baked or environment configuration if remote pairing endpoint is unreachable
	private static function seed_fallback_secret(): bool {
		$fallbackSecret = null;
		if (defined('FINLYZER_BAKED_WORKER_HMAC_SECRET') && is_string(FINLYZER_BAKED_WORKER_HMAC_SECRET) && strlen(FINLYZER_BAKED_WORKER_HMAC_SECRET) >= self::MIN_SECRET_LENGTH) {
			$fallbackSecret = FINLYZER_BAKED_WORKER_HMAC_SECRET;
		} elseif (class_exists('FXLI_Env')) {
			$envSecret = FXLI_Env::read_env_value('FINLYZER_WORKER_HMAC_SECRET');
			if (is_string($envSecret) && strlen($envSecret) >= self::MIN_SECRET_LENGTH) {
				$fallbackSecret = $envSecret;
			}
		}

		if ($fallbackSecret !== null) {
			$saved = self::save_secret($fallbackSecret);
			return $saved === true;
		}

		return false;
	}

	// force re-synchronization of automated cloud pairing with rollback protection
	public static function force_re_pair(): array {
		// backup existing secret before attempting re-pairing to prevent total disconnection on transient failure
		$backupSecret = self::get_stored_secret();

		try {
			// purge existing secret to force fresh pairing
			self::delete_stored_secret();

			// execute fresh pairing
			$paired = self::auto_pair_site();
			if (!$paired) {
				// restore previous secret if fresh pairing was unsuccessful
				if ($backupSecret !== null && $backupSecret !== '') {
					self::save_secret($backupSecret);
				}
				return [
					'success' => false,
					'message' => __('Unable to establish connection with Finlyzer Cloud Sentinel. Previous secure state preserved.', 'finlyzer'),
				];
			}

			// verify connectivity via handshake using instance method
			if (class_exists('FXLI_Gemini_Client')) {
				$client = FXLI_Gemini_Client::instance();
				$handshake = $client->verify_handshake();
				if (!is_wp_error($handshake) && !empty($handshake['success'])) {
					return [
						'success'    => true,
						'latency_ms' => $handshake['latency_ms'] ?? 0,
						'message'    => __('Cloud Sentinel successfully re-synchronized and active.', 'finlyzer'),
					];
				}
			}

			return [
				'success' => true,
				'message' => __('Cloud Sentinel re-synchronized successfully.', 'finlyzer'),
			];
		} catch (\Throwable $e) {
			// log exception details securely to error_log without exposing to merchant
			error_log('[Finlyzer Crypto] Re-sync exception: ' . $e->getMessage());

			// restore backup secret
			if ($backupSecret !== null && $backupSecret !== '') {
				self::save_secret($backupSecret);
			}

			return [
				'success' => false,
				'message' => __('Connection re-synchronization could not be completed. Please try again shortly.', 'finlyzer'),
			];
		}
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
