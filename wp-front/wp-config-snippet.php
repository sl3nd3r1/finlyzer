<?php
/**
 * Finlyzer Configuration Snippet
 *
 * Add these lines to wp-config.php, ABOVE the "That's all, stop editing!" line.
 * Never commit real secrets to version control — inject them via environment variables
 * or your host's secrets manager.
 */

// Cloudflare Worker endpoint URL
define('FINLYZER_WORKER_ENDPOINT', getenv('FINLYZER_WORKER_ENDPOINT') ?: 'https://finlyzer-worker.YOUR-SUBDOMAIN.workers.dev/insight');

// Shared HMAC secret — must match WORKER_HMAC_SECRET in your Cloudflare Worker
// Generate with: openssl rand -hex 32
define('FINLYZER_WORKER_HMAC_SECRET', getenv('FINLYZER_WORKER_HMAC_SECRET') ?: '');

// Legacy backward-compatibility definitions
if (!defined('FXLI_WORKER_ENDPOINT')) {
	define('FXLI_WORKER_ENDPOINT', FINLYZER_WORKER_ENDPOINT);
}
if (!defined('FXLI_WORKER_HMAC_SECRET')) {
	define('FXLI_WORKER_HMAC_SECRET', FINLYZER_WORKER_HMAC_SECRET);
}
