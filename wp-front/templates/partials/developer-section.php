<?php
/**
 * Finlyzer — Developer Diagnostic & API Inspector Section (v1.16.0)
 *
 * Exclusively included and rendered in Development Builds (FINLYZER_ENV=development).
 * Provides plugin developers with live endpoint inspection, outbound payload schemas,
 * live connection handshake diagnostics, and telemetry logs to immediately diagnose
 * serverless isolate connectivity on localhost (XAMPP/LocalWP).
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

$worker_endpoint  = FXLI_Env::worker_endpoint();
$analyze_endpoint = FXLI_Env::analyze_endpoint();
$api_timeout      = FXLI_Env::api_timeout();
$current_env      = FXLI_Env::current_env();
$store_curr       = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD';

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

// retrieve recent outbound HTTP telemetry logs
$recent_logs = class_exists('FXLI_Logger') ? FXLI_Logger::get_recent_logs(15) : [];
$nonce = wp_create_nonce('wp_rest');
$test_endpoint = rest_url('finlyzer/v1/test-connection');
$clear_endpoint = rest_url('finlyzer/v1/clear-logs');
?>

<div class="finlyzer-dev-section" id="finlyzer-dev-section" style="margin-top: 28px; border: 1px dashed rgba(245, 158, 11, 0.4); border-radius: 8px; background: rgba(245, 158, 11, 0.03); padding: 20px;">
	<!-- Header -->
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
		<?php esc_html_e('This inspector allows you to test serverless isolate connectivity in real time, view live outbound HTTP telemetry, and identify connectivity or signature issues instantly.', 'finlyzer'); ?>
	</p>

	<!-- Telemetry and Protocol Configuration Grid -->
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

		<!-- Transport & Security Protocols -->
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

	<!-- Live Diagnostic Connection Handshake Card -->
	<div style="background: rgba(15, 23, 42, 0.8); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: 6px; padding: 16px; margin-bottom: 16px;">
		<div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 12px;">
			<div>
				<h4 style="margin: 0; font-size: 13px; font-weight: 600; color: #F1F5F9;">
					<?php esc_html_e('Live Backend Connectivity Diagnostic', 'finlyzer'); ?>
				</h4>
				<span style="font-size: 11px; color: #94A3B8;">
					<?php esc_html_e('Pings /health and tests HMAC calculation handshake against local worker', 'finlyzer'); ?>
				</span>
			</div>
			<div style="display: flex; gap: 8px;">
				<button type="button" id="finlyzer-test-conn-btn" style="background: #2563EB; color: #FFFFFF; border: none; padding: 6px 14px; border-radius: 4px; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
					<span>⚡</span> <?php esc_html_e('Run Live Handshake Diagnostic', 'finlyzer'); ?>
				</button>
				<button type="button" id="finlyzer-clear-logs-btn" style="background: rgba(255,255,255,0.08); color: #CBD5E1; border: 1px solid rgba(255,255,255,0.15); padding: 6px 12px; border-radius: 4px; font-size: 12px; cursor: pointer;">
					<?php esc_html_e('Clear Logs', 'finlyzer'); ?>
				</button>
			</div>
		</div>

		<!-- Dynamic Result Box -->
		<div id="finlyzer-test-conn-result" style="display: none; background: #020617; border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 4px; padding: 12px; font-family: ui-monospace, SFMono-Regular, monospace; font-size: 11px; color: #E2E8F0;">
		</div>
	</div>

	<!-- Outbound Telemetry Log Table -->
	<div style="background: rgba(15, 23, 42, 0.6); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 6px; padding: 14px; margin-bottom: 16px;">
		<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
			<div style="font-size: 11px; font-weight: 600; color: #64748B; text-transform: uppercase; letter-spacing: 0.05em;">
				<?php esc_html_e('Recent Outbound HTTP Telemetry Log', 'finlyzer'); ?>
				<span style="color: #94A3B8; font-weight: 400;">(<?php echo count($recent_logs); ?> <?php esc_html_e('entries', 'finlyzer'); ?>)</span>
			</div>
			<span style="font-size: 11px; color: #64748B;"><?php esc_html_e('Auto-captured on all outbound API calls', 'finlyzer'); ?></span>
		</div>

		<?php if (empty($recent_logs)) : ?>
			<div style="text-align: center; padding: 18px; color: #64748B; font-size: 12px;">
				<?php esc_html_e('No outbound HTTP calls logged yet. Click "Run Live Handshake Diagnostic" or refresh the page to record telemetry.', 'finlyzer'); ?>
			</div>
		<?php else : ?>
			<div style="overflow-x: auto;">
				<table id="finlyzer-telemetry-table" style="width: 100%; border-collapse: collapse; font-size: 11px; text-align: left;">
					<thead>
						<tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.1); color: #94A3B8;">
							<th style="padding: 6px 8px;"><?php esc_html_e('Time', 'finlyzer'); ?></th>
							<th style="padding: 6px 8px;"><?php esc_html_e('Method', 'finlyzer'); ?></th>
							<th style="padding: 6px 8px;"><?php esc_html_e('Endpoint', 'finlyzer'); ?></th>
							<th style="padding: 6px 8px;"><?php esc_html_e('Status', 'finlyzer'); ?></th>
							<th style="padding: 6px 8px;"><?php esc_html_e('Latency', 'finlyzer'); ?></th>
							<th style="padding: 6px 8px;"><?php esc_html_e('Details / Diagnostics', 'finlyzer'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($recent_logs as $entry) :
							$status = $entry['context']['status'] ?? 'N/A';
							$is_ok = is_numeric($status) && (int) $status === 200;
							$badge_bg = $is_ok ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)';
							$badge_color = $is_ok ? '#34D399' : '#F87171';
							$duration = $entry['context']['duration_ms'] ?? 0;
							$err = $entry['context']['error'] ?? '';
							$url = $entry['context']['endpoint'] ?? $entry['message'];
						?>
							<tr style="border-bottom: 1px solid rgba(255, 255, 255, 0.04); font-family: ui-monospace, SFMono-Regular, monospace;">
								<td style="padding: 6px 8px; color: #64748B; white-space: nowrap;">
									<?php echo esc_html(substr($entry['timestamp'] ?? '', 11)); ?>
								</td>
								<td style="padding: 6px 8px; color: #CBD5E1; font-weight: 600;">
									<?php echo esc_html($entry['context']['method'] ?? 'POST'); ?>
								</td>
								<td style="padding: 6px 8px; color: #38BDF8; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
									<?php echo esc_html($url); ?>
								</td>
								<td style="padding: 6px 8px;">
									<span style="background: <?php echo esc_attr($badge_bg); ?>; color: <?php echo esc_attr($badge_color); ?>; padding: 2px 6px; border-radius: 3px; font-weight: 600;">
										<?php echo esc_html((string) $status); ?>
									</span>
								</td>
								<td style="padding: 6px 8px; color: #FBBF24; white-space: nowrap;">
									<?php echo esc_html(number_format((float) $duration, 1)); ?> ms
								</td>
								<td style="padding: 6px 8px; color: <?php echo $err ? '#F87171' : '#94A3B8'; ?>; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
									<?php echo esc_html($err ?: ($is_ok ? 'Handshake Verified' : $entry['message'])); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
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

<script>
(function() {
	var testBtn = document.getElementById('finlyzer-test-conn-btn');
	var clearBtn = document.getElementById('finlyzer-clear-logs-btn');
	var resultBox = document.getElementById('finlyzer-test-conn-result');
	var testUrl = '<?php echo esc_url_raw($test_endpoint); ?>';
	var clearUrl = '<?php echo esc_url_raw($clear_endpoint); ?>';
	var nonce = '<?php echo esc_js($nonce); ?>';

	if (testBtn && resultBox) {
		testBtn.addEventListener('click', function() {
			testBtn.disabled = true;
			testBtn.innerHTML = '<span>⏳</span> Testing Connection...';
			resultBox.style.display = 'block';
			resultBox.innerHTML = '<span style="color:#38BDF8;">Dispatching diagnostic probes to Cloudflare Worker...</span>';

			fetch(testUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce
				}
			})
			.then(function(res) {
				return res.json().then(function(data) {
					return { status: res.status, data: data };
				});
			})
			.then(function(res) {
				testBtn.disabled = false;
				testBtn.innerHTML = '<span>⚡</span> Run Live Handshake Diagnostic';
				var d = res.data;
				var html = '';
				if (res.status === 200 && d.success) {
					html += '<div style="color:#34D399; font-weight:600; margin-bottom:8px;">✅ ALL DIAGNOSTIC CHECKS PASSED (API CONNECTED)</div>';
				} else {
					html += '<div style="color:#F87171; font-weight:600; margin-bottom:8px;">❌ DIAGNOSTIC HANDSHAKE FAILED</div>';
				}

				if (d.health_check) {
					var h = d.health_check;
					var hOk = h.status === 200;
					html += '<div><strong>/health:</strong> <span style="color:' + (hOk ? '#34D399' : '#F87171') + '">' + (h.status || 'ERR') + '</span> (' + h.duration_ms + ' ms)';
					if (h.error) html += ' - <span style="color:#F87171;">' + h.error + '</span>';
					html += '</div>';
				}

				if (d.analyze_handshake) {
					var a = d.analyze_handshake;
					var aOk = a.status === 200;
					html += '<div><strong>/api/v1/analyze:</strong> <span style="color:' + (aOk ? '#34D399' : '#F87171') + '">' + (a.status || 'ERR') + '</span> (' + a.duration_ms + ' ms)';
					if (a.error) html += ' - <span style="color:#F87171;">' + a.error + '</span>';
					html += '</div>';
				}

				if (d.diagnostic_notes && d.diagnostic_notes.length > 0) {
					html += '<div style="margin-top:8px; color:#FBBF24;"><strong>Notes:</strong><br>' + d.diagnostic_notes.join('<br>') + '</div>';
				}

				resultBox.innerHTML = html;
			})
			.catch(function(err) {
				testBtn.disabled = false;
				testBtn.innerHTML = '<span>⚡</span> Run Live Handshake Diagnostic';
				resultBox.innerHTML = '<span style="color:#F87171;">Request failed: ' + err.message + '</span>';
			});
		});
	}

	if (clearBtn) {
		clearBtn.addEventListener('click', function() {
			clearBtn.disabled = true;
			fetch(clearUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce
				}
			})
			.then(function() {
				window.location.reload();
			})
			.catch(function() {
				clearBtn.disabled = false;
			});
		});
	}
})();
</script>
