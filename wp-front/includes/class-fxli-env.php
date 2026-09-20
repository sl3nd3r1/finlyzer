<?php
declare(strict_types=1);

// prevent direct script execution outside WordPress context
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Environment configuration and security boundary manager for Finlyzer.
 * Coordinates development vs production profiles, baked build-time constants,
 * runtime overrides, SSRF URL defenses, and developer diagnostic tooling.
 */
final class FXLI_Env {

	public const ENV_DEVELOPMENT = 'development';
	public const ENV_PRODUCTION = 'production';

	private static ?array $parsed_env_cache = null;

	// determine current environment mode (defaults to production if ambiguous)
	public static function current_env(): string {
		// 1. check wp-config constant override if explicitly declared
		if (defined('FINLYZER_ENV') && is_string(FINLYZER_ENV) && FINLYZER_ENV !== '') {
			return strtolower(trim(FINLYZER_ENV)) === self::ENV_DEVELOPMENT ? self::ENV_DEVELOPMENT : self::ENV_PRODUCTION;
		}

		// 2. check build-time baked constant
		if (defined('FINLYZER_BAKED_ENV') && is_string(FINLYZER_BAKED_ENV) && FINLYZER_BAKED_ENV !== '') {
			return strtolower(trim(FINLYZER_BAKED_ENV)) === self::ENV_DEVELOPMENT ? self::ENV_DEVELOPMENT : self::ENV_PRODUCTION;
		}

		// 3. check OS / web server environment variable
		$server_env = getenv('FINLYZER_ENV');
		if (is_string($server_env) && $server_env !== '') {
			return strtolower(trim($server_env)) === self::ENV_DEVELOPMENT ? self::ENV_DEVELOPMENT : self::ENV_PRODUCTION;
		}

		// 4. check WordPress standard environment type
		if (function_exists('wp_get_environment_type')) {
			$wp_env = wp_get_environment_type();
			if (in_array($wp_env, ['development', 'local'], true)) {
				return self::ENV_DEVELOPMENT;
			}
		}

		// 5. check local .env.development file presence as fallback for local dev
		$dev_env_file = FINLYZER_PLUGIN_DIR . '.env.development';
		if (file_exists($dev_env_file) && !file_exists(FINLYZER_PLUGIN_DIR . '.env.production')) {
			return self::ENV_DEVELOPMENT;
		}

		return self::ENV_PRODUCTION;
	}

	// check if running in production mode
	public static function is_production(): bool {
		return self::current_env() === self::ENV_PRODUCTION;
	}

	// check if running in development mode
	public static function is_development(): bool {
		return self::current_env() === self::ENV_DEVELOPMENT;
	}

	// determine if developer diagnostic section should be rendered
	public static function dev_tools_enabled(): bool {
		// production builds strictly prohibit developer tools
		if (self::is_production()) {
			return false;
		}

		// check explicit constant override
		if (defined('FINLYZER_ENABLE_DEV_TOOLS')) {
			return (bool) FINLYZER_ENABLE_DEV_TOOLS;
		}

		// read from parsed development env file
		$val = self::read_env_value('FINLYZER_ENABLE_DEV_TOOLS');
		if ($val !== null) {
			return filter_var($val, FILTER_VALIDATE_BOOLEAN);
		}

		return true;
	}

	// check if debug logging is enabled
	public static function debug_logging(): bool {
		if (self::is_production()) {
			return false;
		}

		if (defined('FINLYZER_DEBUG_LOGGING')) {
			return (bool) FINLYZER_DEBUG_LOGGING;
		}

		$val = self::read_env_value('FINLYZER_DEBUG_LOGGING');
		if ($val !== null) {
			return filter_var($val, FILTER_VALIDATE_BOOLEAN);
		}

		return false;
	}

	// check if calculations MUST be routed through the serverless API (no local mock bypass)
	public static function force_api_calculation(): bool {
		if (defined('FINLYZER_FORCE_API_CALCULATION')) {
			return (bool) FINLYZER_FORCE_API_CALCULATION;
		}

		$val = self::read_env_value('FINLYZER_FORCE_API_CALCULATION');
		if ($val !== null) {
			return filter_var($val, FILTER_VALIDATE_BOOLEAN);
		}

		return true;
	}

	// check if strict SSL certificate validation is enforced
	public static function strict_ssl(): bool {
		if (self::is_production()) {
			return true;
		}

		if (defined('FINLYZER_STRICT_SSL')) {
			return (bool) FINLYZER_STRICT_SSL;
		}

		$val = self::read_env_value('FINLYZER_STRICT_SSL');
		if ($val !== null) {
			return filter_var($val, FILTER_VALIDATE_BOOLEAN);
		}

		return false;
	}

	// resolve API request timeout in seconds
	public static function api_timeout(): int {
		if (defined('FINLYZER_API_TIMEOUT')) {
			return max(3, min(30, (int) FINLYZER_API_TIMEOUT));
		}

		$val = self::read_env_value('FINLYZER_API_TIMEOUT');
		if ($val !== null && is_numeric($val)) {
			return max(3, min(30, (int) $val));
		}

		return self::is_production() ? 8 : 15;
	}

	// resolve Cloudflare Worker insight endpoint URL with security enforcement
	public static function worker_endpoint(): string {
		// 1. check wp-config modern constant
		if (defined('FINLYZER_WORKER_ENDPOINT') && is_string(FINLYZER_WORKER_ENDPOINT) && FINLYZER_WORKER_ENDPOINT !== '') {
			return (string) FINLYZER_WORKER_ENDPOINT;
		}

		// 2. check legacy constant fallback
		if (defined('FXLI_WORKER_ENDPOINT') && is_string(FXLI_WORKER_ENDPOINT) && FXLI_WORKER_ENDPOINT !== '') {
			return (string) FXLI_WORKER_ENDPOINT;
		}

		// 3. check build-time baked constant
		if (defined('FINLYZER_BAKED_WORKER_ENDPOINT') && is_string(FINLYZER_BAKED_WORKER_ENDPOINT) && FINLYZER_BAKED_WORKER_ENDPOINT !== '') {
			return (string) FINLYZER_BAKED_WORKER_ENDPOINT;
		}

		// 4. check database option
		if (function_exists('get_option')) {
			$opt = (string) get_option('finlyzer_worker_endpoint', '');
			if ($opt !== '') {
				return $opt;
			}
		}

		// 5. check active .env file
		$env_val = self::read_env_value('FINLYZER_WORKER_ENDPOINT');
		if ($env_val !== null && $env_val !== '') {
			return $env_val;
		}

		// 6. local development fallback for local loopback
		if (self::is_development()) {
			return 'http://127.0.0.1:8787/insight';
		}

		return '';
	}

	// resolve Cloudflare Worker order analysis endpoint URL with security enforcement
	public static function analyze_endpoint(): string {
		// 1. check direct analyze constant override
		if (defined('FINLYZER_WORKER_ANALYZE_ENDPOINT') && is_string(FINLYZER_WORKER_ANALYZE_ENDPOINT) && FINLYZER_WORKER_ANALYZE_ENDPOINT !== '') {
			return (string) FINLYZER_WORKER_ANALYZE_ENDPOINT;
		}

		// 2. check build-time baked constant
		if (defined('FINLYZER_BAKED_WORKER_ANALYZE_ENDPOINT') && is_string(FINLYZER_BAKED_WORKER_ANALYZE_ENDPOINT) && FINLYZER_BAKED_WORKER_ANALYZE_ENDPOINT !== '') {
			return (string) FINLYZER_BAKED_WORKER_ANALYZE_ENDPOINT;
		}

		// 3. check active .env file
		$env_val = self::read_env_value('FINLYZER_WORKER_ANALYZE_ENDPOINT');
		if ($env_val !== null && $env_val !== '') {
			return $env_val;
		}

		// 4. derive from worker_endpoint base
		$base = self::worker_endpoint();
		if ($base === '') {
			return '';
		}

		$clean_base = preg_replace('#(/api/v1)?/insight/?$#', '', rtrim($base, '/'));
		return $clean_base . '/api/v1/analyze';
	}

	// retrieve baked or env HMAC secret
	public static function hmac_secret(): string {
		// 1. check wp-config constant override
		if (defined('FINLYZER_WORKER_HMAC_SECRET') && is_string(FINLYZER_WORKER_HMAC_SECRET) && FINLYZER_WORKER_HMAC_SECRET !== '') {
			return FINLYZER_WORKER_HMAC_SECRET;
		}

		// 2. check legacy constant override
		if (defined('FXLI_WORKER_HMAC_SECRET') && is_string(FXLI_WORKER_HMAC_SECRET) && FXLI_WORKER_HMAC_SECRET !== '') {
			return FXLI_WORKER_HMAC_SECRET;
		}

		// 3. check build-time baked constant
		if (defined('FINLYZER_BAKED_WORKER_HMAC_SECRET') && is_string(FINLYZER_BAKED_WORKER_HMAC_SECRET) && FINLYZER_BAKED_WORKER_HMAC_SECRET !== '') {
			return FINLYZER_BAKED_WORKER_HMAC_SECRET;
		}

		// 4. check database option
		if (function_exists('get_option')) {
			$opt = (string) get_option('finlyzer_worker_hmac_secret', '');
			if ($opt !== '') {
				return $opt;
			}
		}

		// 5. check active .env file
		$env_val = self::read_env_value('FINLYZER_WORKER_HMAC_SECRET');
		if ($env_val !== null && $env_val !== '') {
			return $env_val;
		}

		// 6. development fallback
		if (self::is_development()) {
			return 'dev-ephemeral-secret-32-byte-hex-token';
		}

		return '';
	}

	// strictly validate endpoint URL against SSRF and protocol policies
	public static function validate_endpoint_url(string $url): bool|WP_Error {
		if ($url === '') {
			return new WP_Error('empty_endpoint', 'Worker endpoint URL is required.');
		}

		$parts = parse_url($url);
		if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
			return new WP_Error('malformed_endpoint', 'Worker endpoint URL is malformed.');
		}

		$scheme = strtolower($parts['scheme']);
		$host = strtolower($parts['host']);

		// in production, strictly enforce HTTPS protocol
		if (self::is_production() && $scheme !== 'https') {
			return new WP_Error('insecure_protocol', 'Production API endpoints MUST use HTTPS encryption (TLS 1.2+).');
		}

		// SSRF defense: block loopback and private IP ranges in production
		if (self::is_production()) {
			// block explicit localhost name
			if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
				return new WP_Error('ssrf_blocked_host', 'Loopback hosts are prohibited in production mode.');
			}

			// resolve IP address
			$ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

			if (filter_var($ip, FILTER_VALIDATE_IP)) {
				// check private, reserved, and loopback ranges
				$flags = FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
				if (!filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
					return new WP_Error('ssrf_blocked_ip', "Target IP {$ip} is within a prohibited private or loopback range.");
				}

				// check AWS metadata service (169.254.169.254)
				if ($ip === '169.254.169.254') {
					return new WP_Error('ssrf_blocked_metadata', 'Cloud metadata service access is strictly blocked.');
				}
			}
		}

		return true;
	}

	// read key from cached active .env file
	private static function read_env_value(string $key): ?string {
		if (self::$parsed_env_cache === null) {
			self::$parsed_env_cache = self::load_active_env_file();
		}

		return self::$parsed_env_cache[$key] ?? null;
	}

	// load active environment file according to determined mode
	private static function load_active_env_file(): array {
		$target_file = self::is_production()
			? FINLYZER_PLUGIN_DIR . '.env.production'
			: FINLYZER_PLUGIN_DIR . '.env.development';

		if (!file_exists($target_file)) {
			// fallback check for generic .env
			$generic = FINLYZER_PLUGIN_DIR . '.env';
			if (file_exists($generic)) {
				$target_file = $generic;
			} else {
				return [];
			}
		}

		$lines = file($target_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if ($lines === false) {
			return [];
		}

		$env = [];
		foreach ($lines as $line) {
			$trimmed = trim($line);
			// skip comments
			if ($trimmed === '' || str_starts_with($trimmed, '#')) {
				continue;
			}

			$parts = explode('=', $trimmed, 2);
			if (count($parts) === 2) {
				$k = trim($parts[0]);
				$v = trim($parts[1]);
				// unquote if wrapped in quotes
				if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
					$v = substr($v, 1, -1);
				}
				$env[$k] = $v;
			}
		}

		return $env;
	}
}
