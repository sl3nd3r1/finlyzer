<?php
declare(strict_types=1);

// prevent direct template execution
if (!defined('ABSPATH')) {
	exit;
}

$site_id = class_exists('FXLI_Gemini_Client') ? FXLI_Gemini_Client::site_id() : '';
$short_site_id = strlen($site_id) >= 12 ? substr($site_id, 0, 4) . '••••••••' . substr($site_id, -4) : $site_id;
$active_secret = class_exists('FXLI_Env') ? FXLI_Env::hmac_secret() : '';
$is_connected = $active_secret !== '' && !str_contains($active_secret, 'dev-ephemeral');
$current_env = class_exists('FXLI_Env') ? FXLI_Env::current_env() : 'production';
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
			<div id="finlyzer-cloud-status-badge" class="finlyzer-status-pill <?php echo $is_connected ? 'finlyzer-status-pill--active' : 'finlyzer-status-pill--warn'; ?>">
				<span class="finlyzer-status-dot"></span>
				<span><?php echo $is_connected ? esc_html__('Cloud AI Active & Protected', 'finlyzer') : esc_html__('Cloud Sentinel Initializing...', 'finlyzer'); ?></span>
			</div>
			<div class="finlyzer-spec-chip"><?php esc_html_e('AES-256-GCM Encrypted', 'finlyzer'); ?></div>
			<div class="finlyzer-spec-chip"><?php esc_html_e('Zero-PII Enforced', 'finlyzer'); ?></div>
		</div>

		<!-- Modal Body -->
		<div class="finlyzer-modal-body">
			<!-- Architecture Status Grid -->
			<div class="finlyzer-sentinel-grid">
				<div class="finlyzer-sentinel-card">
					<span class="finlyzer-sentinel-card__label"><?php esc_html_e('Site Identifier', 'finlyzer'); ?></span>
					<span class="finlyzer-sentinel-card__val finlyzer-sentinel-card__val--mono"><?php echo esc_html($short_site_id); ?></span>
					<span class="finlyzer-sentinel-card__desc"><?php esc_html_e('Anonymous cryptographic site identity', 'finlyzer'); ?></span>
				</div>
				<div class="finlyzer-sentinel-card">
					<span class="finlyzer-sentinel-card__label"><?php esc_html_e('Connection Protocol', 'finlyzer'); ?></span>
					<span class="finlyzer-sentinel-card__val"><?php esc_html_e('Zero-Touch Token', 'finlyzer'); ?></span>
					<span class="finlyzer-sentinel-card__desc"><?php esc_html_e('Automated per-site cryptographic pairing', 'finlyzer'); ?></span>
				</div>
				<div class="finlyzer-sentinel-card">
					<span class="finlyzer-sentinel-card__label"><?php esc_html_e('Storage Security', 'finlyzer'); ?></span>
					<span class="finlyzer-sentinel-card__val"><?php esc_html_e('AES-256-GCM AEAD', 'finlyzer'); ?></span>
					<span class="finlyzer-sentinel-card__desc"><?php esc_html_e('At-rest encryption keyed by server salts', 'finlyzer'); ?></span>
				</div>
				<div class="finlyzer-sentinel-card">
					<span class="finlyzer-sentinel-card__label"><?php esc_html_e('Privacy Compliance', 'finlyzer'); ?></span>
					<span class="finlyzer-sentinel-card__val"><?php esc_html_e('WordPress.org Sec 9', 'finlyzer'); ?></span>
					<span class="finlyzer-sentinel-card__desc"><?php esc_html_e('Zero customer PII transmitted or stored', 'finlyzer'); ?></span>
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

			<!-- Information Notice -->
			<div class="finlyzer-notice-box">
				<div class="finlyzer-notice-icon" aria-hidden="true">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
						<circle cx="12" cy="12" r="10"></circle>
						<line x1="12" y1="16" x2="12" y2="12"></line>
						<line x1="12" y1="8" x2="12.01" y2="8"></line>
					</svg>
				</div>
				<p class="finlyzer-notice-text">
					<?php esc_html_e('Your store is securely connected to the Finlyzer Cloud Sentinel using automated zero-touch cryptographic pairing. No API keys or technical setup required.', 'finlyzer'); ?>
				</p>
			</div>
		</div>
	</div>
</div>
