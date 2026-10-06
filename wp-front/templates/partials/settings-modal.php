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
					<h3 class="finlyzer-modal-title" id="finlyzerSettingsTitle"><?php esc_html_e('Finlyzer Engine & Cloud Sentinel', 'finlyzer'); ?></h3>
					<p class="finlyzer-modal-subtitle"><?php esc_html_e('Autonomous FX margin intelligence & privacy controls.', 'finlyzer'); ?></p>
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
				<span id="finlyzer-status-pill-text"><?php echo $fxli_is_connected ? esc_html__('Cloud AI Active & Protected', 'finlyzer') : esc_html__('Local Calculation Engine Active (Private)', 'finlyzer'); ?></span>
			</div>
			<div id="finlyzer-rates-status-badge" class="finlyzer-status-pill <?php echo $fxli_live_rates_opted_in ? 'finlyzer-status-pill--active' : 'finlyzer-status-pill--optimal'; ?>" style="margin-left: 8px;">
				<span class="finlyzer-status-dot"></span>
				<span id="finlyzer-rates-pill-text"><?php echo $fxli_live_rates_opted_in ? esc_html__('Live ECB Rates Active', 'finlyzer') : esc_html__('Offline ECB Rates Matrix (Default)', 'finlyzer'); ?></span>
			</div>
		</div>

		<!-- Modal Body -->
		<div class="finlyzer-modal-body">
			<!-- Store Identity Grid -->
			<div class="finlyzer-sentinel-grid finlyzer-sentinel-grid--single">
				<div class="finlyzer-sentinel-card">
					<span class="finlyzer-sentinel-card__label"><?php esc_html_e('Engine Mode', 'finlyzer'); ?></span>
					<span class="finlyzer-sentinel-card__val" id="finlyzerEngineModeVal"><?php echo $fxli_cloud_opted_in ? esc_html__('Cloud Sentinel Enabled', 'finlyzer') : esc_html__('100% Local On-Store Database Engine', 'finlyzer'); ?></span>
					<span class="finlyzer-sentinel-card__desc"><?php esc_html_e('All core calculations run on your own WordPress database without external dependencies.', 'finlyzer'); ?></span>
				</div>
			</div>

			<!-- European Central Bank / Frankfurter API Opt-In Box (Guideline 7 Compliant) -->
			<div class="finlyzer-verify-box finlyzer-verify-box--rates" style="margin-bottom: 20px;">
				<div class="finlyzer-verify-header">
					<div>
						<h4 class="finlyzer-verify-title"><?php esc_html_e('European Central Bank Live Rates', 'finlyzer'); ?></h4>
						<p class="finlyzer-verify-sub">
							<?php esc_html_e('Optional daily reference exchange rates via public Frankfurter API (https://api.frankfurter.dev). Off by default per WordPress Plugin Guideline 7. Sends only currency ISO codes (e.g. USD, EUR) to fetch daily ECB reference exchange rates. No personal data, store URL, or order data is ever sent.', 'finlyzer'); ?>
						</p>
					</div>
					<div class="finlyzer-optin-actions" id="finlyzerRatesOptinActions">
						<?php if ($fxli_live_rates_opted_in) : ?>
							<button type="button" id="finlyzerRatesOptOutBtn" class="finlyzer-btn finlyzer-btn--secondary">
								<span><?php esc_html_e('Switch to Offline Rates', 'finlyzer'); ?></span>
							</button>
						<?php else : ?>
							<button type="button" id="finlyzerRatesOptInBtn" class="finlyzer-btn finlyzer-btn--accent">
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
									<circle cx="12" cy="12" r="10"></circle>
									<line x1="2" y1="12" x2="22" y2="12"></line>
									<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
								</svg>
								<span><?php esc_html_e('Enable Live Rates (Opt In)', 'finlyzer'); ?></span>
							</button>
						<?php endif; ?>
					</div>
				</div>
				<div id="finlyzerRatesResult" class="finlyzer-verify-result" style="display:none;" aria-live="polite"></div>
			</div>

			<!-- Cloud Sentinel Opt-In / Connection Box -->
			<div class="finlyzer-verify-box">
				<div class="finlyzer-verify-header">
					<div>
						<h4 class="finlyzer-verify-title"><?php esc_html_e('Cloud AI Sentinel', 'finlyzer'); ?></h4>
						<p class="finlyzer-verify-sub">
							<?php esc_html_e('Optional AI risk analysis. Sends strictly anonymized numeric order aggregates (store currency, total loss, currency counts). Zero customer PII is ever sent.', 'finlyzer'); ?>
						</p>
					</div>
					<div class="finlyzer-optin-actions" id="finlyzerOptinActions">
						<?php if ($fxli_cloud_opted_in) : ?>
							<button type="button" id="finlyzerOptOutBtn" class="finlyzer-btn finlyzer-btn--secondary">
								<span><?php esc_html_e('Switch to Local Mode', 'finlyzer'); ?></span>
							</button>
							<button type="button" id="finlyzerVerifyHmacBtn" class="finlyzer-btn finlyzer-btn--accent finlyzer-cloud-resync-btn">
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
									<polyline points="23 4 23 10 17 10"></polyline>
									<path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
								</svg>
								<span><?php esc_html_e('Re-sync', 'finlyzer'); ?></span>
							</button>
						<?php else : ?>
							<button type="button" id="finlyzerOptInBtn" class="finlyzer-btn finlyzer-btn--accent">
								<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
									<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
									<path d="M9 12l2 2 4-4"/>
								</svg>
								<span><?php esc_html_e('Enable Cloud AI (Opt In)', 'finlyzer'); ?></span>
							</button>
						<?php endif; ?>
					</div>
				</div>
				<div id="finlyzerVerifyResult" class="finlyzer-verify-result" style="display:none;" aria-live="polite"></div>
			</div>
		</div>
	</div>
</div>
