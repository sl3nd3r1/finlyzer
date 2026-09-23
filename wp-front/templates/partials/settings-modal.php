<?php
declare(strict_types=1);

// prevent direct template execution
if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$fxli_site_id = class_exists('FXLI_Gemini_Client') ? FXLI_Gemini_Client::site_id() : '';
$fxli_short_site_id = strlen($fxli_site_id) >= 12 ? substr($fxli_site_id, 0, 4) . '••••••••' . substr($fxli_site_id, -4) : $fxli_site_id;
$fxli_active_secret = class_exists('FXLI_Env') ? FXLI_Env::hmac_secret() : '';
$fxli_is_connected = $fxli_active_secret !== '' && !str_contains($fxli_active_secret, 'dev-ephemeral');
$fxli_current_env = class_exists('FXLI_Env') ? FXLI_Env::current_env() : 'production';
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
					<h3 class="finlyzer-modal-title" id="finlyzerSettingsTitle"><?php esc_html_e('Finlyzer Cloud Sentinel', 'finlyzer'); ?></h3>
					<p class="finlyzer-modal-subtitle"><?php esc_html_e('Autonomous FX margin intelligence & real-time risk protection.', 'finlyzer'); ?></p>
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
			<div id="finlyzer-cloud-status-badge" class="finlyzer-status-pill <?php echo $fxli_is_connected ? 'finlyzer-status-pill--active' : 'finlyzer-status-pill--warn'; ?>">
				<span class="finlyzer-status-dot"></span>
				<span><?php echo $fxli_is_connected ? esc_html__('Cloud AI Active & Protected', 'finlyzer') : esc_html__('Cloud Sentinel Initializing...', 'finlyzer'); ?></span>
			</div>
		</div>

		<!-- Modal Body -->
		<div class="finlyzer-modal-body">
			<!-- Store Identity Grid -->
			<div class="finlyzer-sentinel-grid finlyzer-sentinel-grid--single">
				<div class="finlyzer-sentinel-card">
					<span class="finlyzer-sentinel-card__label"><?php esc_html_e('Site Identifier', 'finlyzer'); ?></span>
					<span class="finlyzer-sentinel-card__val finlyzer-sentinel-card__val--mono"><?php echo esc_html($fxli_short_site_id); ?></span>
					<span class="finlyzer-sentinel-card__desc"><?php esc_html_e('Unique store identifier for autonomous risk calculations', 'finlyzer'); ?></span>
				</div>
			</div>

			<!-- Connection Health & Re-sync Box -->
			<div class="finlyzer-verify-box">
				<div class="finlyzer-verify-header">
					<div>
						<h4 class="finlyzer-verify-title"><?php esc_html_e('Cloud Sentinel Health', 'finlyzer'); ?></h4>
						<p class="finlyzer-verify-sub"><?php esc_html_e('Autonomous server-to-server connection with Finlyzer AI Sentinel.', 'finlyzer'); ?></p>
					</div>
					<button type="button" id="finlyzerVerifyHmacBtn" class="finlyzer-btn finlyzer-btn--accent finlyzer-cloud-resync-btn">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<polyline points="23 4 23 10 17 10"></polyline>
							<path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
						</svg>
						<span><?php esc_html_e('Re-sync Connection', 'finlyzer'); ?></span>
					</button>
				</div>
				<div id="finlyzerVerifyResult" class="finlyzer-verify-result" style="display:none;" aria-live="polite"></div>
			</div>
		</div>
	</div>
</div>
