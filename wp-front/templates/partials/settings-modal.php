<?php
declare(strict_types=1);

// prevent direct template execution
if (!defined('ABSPATH')) {
	exit;
}

$hmac_source = class_exists('FXLI_Env') ? FXLI_Env::hmac_secret_source() : 'none';
$is_locked = class_exists('FXLI_Env') && FXLI_Env::is_hmac_secret_locked();
$active_secret = class_exists('FXLI_Env') ? FXLI_Env::hmac_secret() : '';
$is_configured = $active_secret !== '' && !str_contains($active_secret, 'dev-ephemeral');
$masked_secret = class_exists('FXLI_Crypto') ? FXLI_Crypto::mask_secret($active_secret) : '';
$worker_endpoint = class_exists('FXLI_Env') ? FXLI_Env::worker_endpoint() : '';
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
					<h3 class="finlyzer-modal-title" id="finlyzerSettingsTitle"><?php esc_html_e('Security & Worker Configuration', 'finlyzer'); ?></h3>
					<p class="finlyzer-modal-subtitle"><?php esc_html_e('Manage your Cloudflare Worker HMAC authentication secret and connection verification.', 'finlyzer'); ?></p>
				</div>
			</div>
			<button type="button" class="finlyzer-modal-close" id="finlyzerSettingsCloseBtn" aria-label="<?php esc_attr_e('Close settings', 'finlyzer'); ?>">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
					<line x1="18" y1="6" x2="6" y2="18"></line>
					<line x1="6" y1="6" x2="18" y2="18"></line>
				</svg>
			</button>
		</div>

		<!-- Status Bar -->
		<div class="finlyzer-modal-status-bar">
			<div class="finlyzer-status-pill <?php echo $is_configured ? 'finlyzer-status-pill--active' : 'finlyzer-status-pill--warn'; ?>">
				<span class="finlyzer-status-dot"></span>
				<span><?php echo $is_configured ? esc_html__('HMAC Secret Configured', 'finlyzer') : esc_html__('HMAC Secret Missing', 'finlyzer'); ?></span>
			</div>
			<div class="finlyzer-spec-chip">AES-256-GCM Encrypted</div>
			<div class="finlyzer-spec-chip"><?php echo esc_html(strtoupper($current_env)); ?> PROFILE</div>
		</div>

		<!-- Modal Body -->
		<div class="finlyzer-modal-body">
			<!-- Worker Endpoint Information -->
			<div class="finlyzer-form-group">
				<label class="finlyzer-form-label" for="finlyzerWorkerEndpointDisplay">
					<?php esc_html_e('Cloudflare Worker Endpoint', 'finlyzer'); ?>
				</label>
				<div class="finlyzer-input-row">
					<input
						type="text"
						id="finlyzerWorkerEndpointDisplay"
						class="finlyzer-form-input finlyzer-form-input--readonly"
						value="<?php echo esc_attr($worker_endpoint); ?>"
						readonly
					/>
				</div>
				<span class="finlyzer-form-hint">
					<?php esc_html_e('Endpoints are strictly validated against SSRF and require HTTPS in production.', 'finlyzer'); ?>
				</span>
			</div>

			<!-- Secret Configuration Section -->
			<div class="finlyzer-form-group">
				<div class="finlyzer-form-label-row">
					<label class="finlyzer-form-label" for="finlyzerHmacSecretInput">
						<?php esc_html_e('Shared Worker HMAC Secret', 'finlyzer'); ?>
					</label>
					<span class="finlyzer-source-tag">
						<?php
						if ($hmac_source === 'constant') {
							esc_html_e('Source: wp-config.php (Locked)', 'finlyzer');
						} elseif ($hmac_source === 'env') {
							esc_html_e('Source: Server Environment', 'finlyzer');
						} elseif ($hmac_source === 'database') {
							esc_html_e('Source: Database (Encrypted at Rest)', 'finlyzer');
						} else {
							esc_html_e('Source: Not Configured', 'finlyzer');
						}
						?>
					</span>
				</div>

				<?php if ($is_locked): ?>
					<div class="finlyzer-alert-banner finlyzer-alert-banner--info">
						<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
							<path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
						</svg>
						<div>
							<strong><?php esc_html_e('Configured via wp-config.php Constant', 'finlyzer'); ?></strong>
							<p><?php esc_html_e('The HMAC secret is managed at the server level via FINLYZER_WORKER_HMAC_SECRET. Database editing is locked to prevent accidental drift.', 'finlyzer'); ?></p>
						</div>
					</div>
					<div class="finlyzer-input-row">
						<input
							type="text"
							id="finlyzerHmacSecretInput"
							class="finlyzer-form-input finlyzer-form-input--mono"
							value="<?php echo esc_attr($masked_secret ?: '••••••••••••••••••••••••••••••••'); ?>"
							disabled
						/>
					</div>
				<?php else: ?>
					<div class="finlyzer-input-row">
						<input
							type="text"
							id="finlyzerHmacSecretInput"
							class="finlyzer-form-input finlyzer-form-input--mono"
							placeholder="<?php esc_attr_e('Enter 64-character hex secret or click Generate...', 'finlyzer'); ?>"
							value="<?php echo esc_attr($masked_secret); ?>"
							data-masked="<?php echo esc_attr($masked_secret); ?>"
							autocomplete="off"
							spellcheck="false"
						/>
						<button type="button" id="finlyzerGenerateSecretBtn" class="finlyzer-btn finlyzer-btn--secondary" title="<?php esc_attr_e('Generate cryptographically secure 64-char key', 'finlyzer'); ?>">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<polyline points="23 4 23 10 17 10"></polyline>
								<path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
							</svg>
							<span><?php esc_html_e('Generate', 'finlyzer'); ?></span>
						</button>
						<button type="button" id="finlyzerCopySecretBtn" class="finlyzer-btn finlyzer-btn--secondary" title="<?php esc_attr_e('Copy secret to clipboard', 'finlyzer'); ?>">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
								<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
							</svg>
							<span><?php esc_html_e('Copy', 'finlyzer'); ?></span>
						</button>
					</div>
					<div class="finlyzer-form-actions">
						<button type="button" id="finlyzerSaveSecretBtn" class="finlyzer-btn finlyzer-btn--primary">
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
								<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
								<polyline points="17 21 17 13 7 13 7 21"></polyline>
								<polyline points="7 3 7 8 15 8"></polyline>
							</svg>
							<span><?php esc_html_e('Save & Encrypt', 'finlyzer'); ?></span>
						</button>
						<?php if ($hmac_source === 'database'): ?>
							<button type="button" id="finlyzerDeleteSecretBtn" class="finlyzer-btn finlyzer-btn--danger" title="<?php esc_attr_e('Remove secret from database', 'finlyzer'); ?>">
								<span><?php esc_html_e('Clear', 'finlyzer'); ?></span>
							</button>
						<?php endif; ?>
					</div>
					<span class="finlyzer-form-hint">
						<?php esc_html_e('Stored in database encrypted with authenticated AES-256-GCM. The decryption key is derived from server salts in wp-config.php.', 'finlyzer'); ?>
					</span>
				<?php endif; ?>
			</div>

			<!-- Connection Verification Section -->
			<div class="finlyzer-verify-box">
				<div class="finlyzer-verify-header">
					<div>
						<h4 class="finlyzer-verify-title"><?php esc_html_e('HMAC Handshake Verification', 'finlyzer'); ?></h4>
						<p class="finlyzer-verify-sub"><?php esc_html_e('Tests live timestamped cryptographic signature against Cloudflare Worker.', 'finlyzer'); ?></p>
					</div>
					<button type="button" id="finlyzerVerifyHmacBtn" class="finlyzer-btn finlyzer-btn--accent">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<circle cx="12" cy="12" r="10"></circle>
							<line x1="12" y1="16" x2="12" y2="12"></line>
							<line x1="12" y1="8" x2="12.01" y2="8"></line>
						</svg>
						<span><?php esc_html_e('Test Connection', 'finlyzer'); ?></span>
					</button>
				</div>
				<div id="finlyzerVerifyResult" class="finlyzer-verify-result" style="display:none;" aria-live="polite"></div>
			</div>

			<!-- Cloudflare Instructions Guide -->
			<div class="finlyzer-guide-box">
				<h5 class="finlyzer-guide-title"><?php esc_html_e('Cloudflare Worker Deployment Instructions', 'finlyzer'); ?></h5>
				<ol class="finlyzer-guide-list">
					<li>
						<?php esc_html_e('Copy your HMAC secret using the button above.', 'finlyzer'); ?>
					</li>
					<li>
						<?php esc_html_e('In your terminal, inject the secret into your Cloudflare Worker:', 'finlyzer'); ?>
						<code>wrangler secret put WORKER_HMAC_SECRET --env production</code>
					</li>
					<li>
						<?php esc_html_e('For local development with XAMPP or Wrangler dev, add it to your worker’s .dev.vars file:', 'finlyzer'); ?>
						<code>WORKER_HMAC_SECRET=&lt;your-secret&gt;</code>
					</li>
				</ol>
			</div>
		</div>
	</div>
</div>
