<?php
/**
 * Finlyzer — Developer Diagnostic & API Inspector Section (v1.16.0)
 *
 * Exclusively included and rendered in Development Builds (FINLYZER_ENV=development).
 * Provides plugin developers with live endpoint inspection, outbound payload schemas,
 * latency diagnostics, and terminal curl reproduction commands to rapidly test and improve
 * the plugin before creating production-certified releases.
 *
 * Strictly excluded or inert in Production builds.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

// guard: assert developer tools are permitted in current environment
if (!class_exists('FXLI_Env') || !FXLI_Env::dev_tools_enabled()) {
	return;
}

$worker_endpoint = FXLI_Env::worker_endpoint();
$analyze_endpoint = FXLI_Env::analyze_endpoint();
$api_timeout = FXLI_Env::api_timeout();
$current_env = FXLI_Env::current_env();
$store_curr = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';

// compute live HMAC signature fixture for terminal verification
$dev_time = time();
$sample_body = (string) wp_json_encode([
	'store_currency' => $store_curr,
	'period_days'    => 30,
	'orders'         => [],
], JSON_UNESCAPED_SLASHES);
$dev_sig = class_exists('FXLI_Security') ? FXLI_Security::sign_worker_payload($sample_body, $dev_time) : '';
$dev_site_id = class_exists('FXLI_Gemini_Client') ? FXLI_Gemini_Client::site_id() : 'dev-site-verifier';
$dev_version = defined('FINLYZER_VERSION') ? FINLYZER_VERSION : '1.16.0';
?>

<div class="finlyzer-dev-section" id="finlyzer-dev-section" style="margin-top: 28px; border: 1px dashed rgba(245, 158, 11, 0.4); border-radius: 8px; background: rgba(245, 158, 11, 0.03); padding: 20px;">
	<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
		<div style="display: flex; align-items: center; gap: 10px;">
			<span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: #F59E0B; box-shadow: 0 0 8px #F59E0B;"></span>
			<h3 style="margin: 0; font-size: 14px; font-weight: 700; color: #F59E0B; text-transform: uppercase; letter-spacing: 0.05em;">
				<?php esc_html_e('Developer Environment & API Telemetry Inspector', 'finlyzer'); ?>
			</h3>
			<span style="font-family: ui-monospace, SFMono-Regular, monospace; font-size: 11px; background: rgba(245, 158, 11, 0.15); color: #FBBF24; padding: 2px 8px; border-radius: 4px;">
				<?php echo esc_html(strtoupper($current_env)); ?> BUILD
			</span>
		</div>
		<span style="font-size: 12px; color: #94A3B8;">
			<?php esc_html_e('Omitted automatically from production releases', 'finlyzer'); ?>
		</span>
	</div>

	<p style="font-size: 13px; color: #94A3B8; margin: 0 0 16px 0; line-height: 1.5;">
		<?php esc_html_e('This developer section is active because this build was compiled with the development environment profile (.env.development). Use this inspector to verify serverless isolate connectivity, inspect order batch schemas, and benchmark latency.', 'finlyzer'); ?>
	</p>

	<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 14px; margin-bottom: 16px;">
		<!-- Endpoint Configuration -->
		<div style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 6px; padding: 14px;">
			<div style="font-size: 11px; font-weight: 600; color: #64748B; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px;">
				<?php esc_html_e('Configured API Endpoints', 'finlyzer'); ?>
			</div>
			<div style="margin-bottom: 8px;">
				<div style="font-size: 11px; color: #94A3B8; margin-bottom: 2px;"><?php esc_html_e('AI Sentinel Endpoint:', 'finlyzer'); ?></div>
				<code style="display: block; font-size: 12px; color: #38BDF8; word-break: break-all; background: rgba(0,0,0,0.3); padding: 4px 6px; border-radius: 4px;">
					<?php echo esc_html($worker_endpoint ?: __('(Not Configured)', 'finlyzer')); ?>
				</code>
			</div>
			<div>
				<div style="font-size: 11px; color: #94A3B8; margin-bottom: 2px;"><?php esc_html_e('Order Analyzer Endpoint:', 'finlyzer'); ?></div>
				<code style="display: block; font-size: 12px; color: #38BDF8; word-break: break-all; background: rgba(0,0,0,0.3); padding: 4px 6px; border-radius: 4px;">
					<?php echo esc_html($analyze_endpoint ?: __('(Not Configured)', 'finlyzer')); ?>
				</code>
			</div>
		</div>

		<!-- Telemetry Settings -->
		<div style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 6px; padding: 14px;">
			<div style="font-size: 11px; font-weight: 600; color: #64748B; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px;">
				<?php esc_html_e('Transport & Security Protocols', 'finlyzer'); ?>
			</div>
			<ul style="margin: 0; padding: 0 0 0 16px; font-size: 12px; color: #CBD5E1; line-height: 1.8;">
				<li>
					<strong><?php esc_html_e('API Timeout:', 'finlyzer'); ?></strong>
					<span style="color: #38BDF8;"><?php echo (int) $api_timeout; ?>s</span>
				</li>
				<li>
					<strong><?php esc_html_e('Loopback Allowed:', 'finlyzer'); ?></strong>
					<span style="color: #34D399;"><?php esc_html_e('YES (127.0.0.1 / localhost dev enabled)', 'finlyzer'); ?></span>
				</li>
				<li>
					<strong><?php esc_html_e('SSL Verification:', 'finlyzer'); ?></strong>
					<span style="color: #FBBF24;"><?php esc_html_e('Relaxed in dev / Strictly enforced in prod', 'finlyzer'); ?></span>
				</li>
				<li>
					<strong><?php esc_html_e('HMAC Handshake:', 'finlyzer'); ?></strong>
					<span style="color: #A78BFA;"><?php esc_html_e('Timestamped SHA256 (300s window)', 'finlyzer'); ?></span>
				</li>
			</ul>
		</div>
	</div>

	<!-- Outbound Payload Inspector -->
	<details style="margin-top: 10px; background: rgba(15, 23, 42, 0.4); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 6px; padding: 10px 14px;">
		<summary style="cursor: pointer; font-size: 12px; font-weight: 600; color: #CBD5E1; user-select: none;">
			<?php esc_html_e('🔍 View Outgoing Payload Architecture & Terminal Curl Command', 'finlyzer'); ?>
		</summary>
		<div style="margin-top: 12px;">
			<div style="font-size: 11px; color: #94A3B8; margin-bottom: 4px;"><?php esc_html_e('Terminal verification curl command (pre-signed HMAC SHA-256):', 'finlyzer'); ?></div>
			<pre style="background: #020617; border: 1px solid rgba(255,255,255,0.06); color: #A5F3FC; padding: 10px; border-radius: 4px; font-size: 11px; overflow-x: auto; margin: 0 0 10px 0;">curl -X POST "<?php echo esc_attr($analyze_endpoint ?: 'http://127.0.0.1:8787/api/v1/analyze'); ?>" \
  -H "Content-Type: application/json" \
  -H "X-FXLI-Site: <?php echo esc_attr($dev_site_id); ?>" \
  -H "X-FXLI-Version: <?php echo esc_attr($dev_version); ?>" \
  -H "X-FXLI-Time: <?php echo esc_attr((string) $dev_time); ?>" \
  -H "X-FXLI-Sig: <?php echo esc_attr($dev_sig); ?>" \
  -d '<?php echo esc_attr($sample_body); ?>'</pre>
		</div>
	</details>
</div>
