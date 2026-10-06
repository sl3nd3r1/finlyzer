<?php
declare(strict_types=1);

// prevent direct template execution
if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$fxli_cloud_opted_in      = class_exists('FXLI_Env') && FXLI_Env::is_cloud_opted_in();
$fxli_live_rates_opted_in = class_exists('FXLI_Rate_Service') && FXLI_Rate_Service::is_live_rates_opted_in();
$fxli_site_id             = class_exists('FXLI_Gemini_Client') ? FXLI_Gemini_Client::site_id() : '';
$fxli_short_site_id       = strlen($fxli_site_id) >= 12 ? substr($fxli_site_id, 0, 4) . '••••••••' . substr($fxli_site_id, -4) : $fxli_site_id;
$fxli_active_secret       = class_exists('FXLI_Env') ? FXLI_Env::hmac_secret() : '';
$fxli_is_connected        = $fxli_cloud_opted_in && $fxli_active_secret !== '' && !str_contains($fxli_active_secret, 'dev-ephemeral');
$fxli_current_env         = class_exists('FXLI_Env') ? FXLI_Env::current_env() : 'production';
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>
<div class="finlyzer-modal-backdrop" id="finlyzerSettingsModal" role="dialog" aria-modal="true" aria-labelledby="finlyzerSettingsTitle" style="display:none;">
	<div class="finlyzer-modal-card">
		<!-- Modal Header -->
		<div class="finlyzer-modal-header">
			<div class="finlyzer-modal-header__brand">
				<div class="finlyzer-modal-icon" aria-hidden="true">
					<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
						<path d="M9 12l2 2 4-4"/>
					</svg>
				</div>
				<div>
					<h3 class="finlyzer-modal-title" id="finlyzerSettingsTitle"><?php esc_html_e('Server Connection & Diagnostics', 'finlyzer'); ?></h3>
					<p class="finlyzer-modal-subtitle"><?php esc_html_e('Manage server connectivity and credentials.', 'finlyzer'); ?></p>
				</div>
			</div>
			<button type="button" class="finlyzer-modal-close" id="finlyzerSettingsCloseBtn" aria-label="<?php esc_attr_e('Close status dialog', 'finlyzer'); ?>">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
					<line x1="18" y1="6" x2="6" y2="18"></line>
					<line x1="6" y1="6" x2="18" y2="18"></line>
				</svg>
			</button>
		</div>

		<!-- Status Bar -->
		<div class="finlyzer-modal-status-bar">
			<div id="finlyzer-cloud-status-badge" class="finlyzer-status-pill <?php echo $fxli_is_connected ? 'finlyzer-status-pill--active' : 'finlyzer-status-pill--optimal'; ?>">
				<span class="finlyzer-status-dot"></span>
				<span id="finlyzer-status-pill-text"><?php echo $fxli_is_connected ? esc_html__('Server Connected & Active', 'finlyzer') : esc_html__('Server Standby / Local Mode', 'finlyzer'); ?></span>
			</div>
		</div>

		<!-- Modal Body -->
		<div class="finlyzer-modal-body">
			<!-- Store Identity Grid -->
			<div class="finlyzer-sentinel-grid finlyzer-sentinel-grid--single">
				<div class="finlyzer-sentinel-card">
					<span class="finlyzer-sentinel-card__label"><?php esc_html_e('Engine Status', 'finlyzer'); ?></span>
					<span class="finlyzer-sentinel-card__val" id="finlyzerEngineModeVal"><?php echo $fxli_is_connected ? esc_html__('Cloud Sentinel Enabled', 'finlyzer') : esc_html__('Local Calculation Mode', 'finlyzer'); ?></span>
					<span class="finlyzer-sentinel-card__desc"><?php esc_html_e('Core order metrics are calculated securely on your store database.', 'finlyzer'); ?></span>
				</div>
			</div>

			<!-- Server Connection & Synchronization Box -->
			<div class="finlyzer-verify-box finlyzer-server-sync-box">
				<div class="finlyzer-verify-header">
					<div>
						<h4 class="finlyzer-verify-title"><?php esc_html_e('Cloud AI Sentinel — Server Connection & Sync', 'finlyzer'); ?></h4>
						<p class="finlyzer-verify-sub">
							<?php esc_html_e('Verify server connection and synchronize authentication credentials. If the plugin is not connecting, first click "Check Connection" to test the server, and then click "Re-sync" to retrieve and update your secure HMAC token from the server.', 'finlyzer'); ?>
						</p>
					</div>
					<div class="finlyzer-optin-actions" id="finlyzerServerSyncActions">
						<button type="button" id="finlyzerCheckConnectionBtn" class="finlyzer-btn finlyzer-btn--secondary" title="<?php esc_attr_e('Test connectivity to the server', 'finlyzer'); ?>">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
							</svg>
							<span id="finlyzerCheckConnectionBtnText"><?php esc_html_e('Check Connection', 'finlyzer'); ?></span>
						</button>
						<button type="button" id="finlyzerVerifyHmacBtn" class="finlyzer-btn finlyzer-btn--accent finlyzer-cloud-resync-btn" title="<?php esc_attr_e('Re-synchronize HMAC token from the server', 'finlyzer'); ?>">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<polyline points="23 4 23 10 17 10"></polyline>
								<path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
							</svg>
							<span id="finlyzerResyncModalBtnText"><?php esc_html_e('Re-sync', 'finlyzer'); ?></span>
						</button>
					</div>
				</div>
				<div id="finlyzerVerifyResult" class="finlyzer-verify-result" style="display:none;" aria-live="polite"></div>
			</div>
		</div>
	</div>
</div>
