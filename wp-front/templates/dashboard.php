<?php
declare(strict_types=1);

// prevent direct template execution
if (!defined('ABSPATH')) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
$default_days     = 30;
$rest_summary_url = add_query_arg('days', $default_days, rest_url('finlyzer/v1/summary'));
$rest_insight_url = add_query_arg('days', $default_days, rest_url('finlyzer/v1/insight'));
$rest_nonce       = wp_create_nonce('wp_rest');
$rest_base_url    = rest_url('finlyzer/v1');
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
?>
<script>
window.Finlyzer = window.Finlyzer || {
	restUrl: '<?php echo esc_url_raw($rest_base_url); ?>',
	nonce: '<?php echo esc_js($rest_nonce); ?>'
};
window.FXLI = window.Finlyzer;
</script>
<div class="finlyzer-app" id="finlyzer-app">

	<!-- Top Navigation and Header -->
	<header class="finlyzer-header">
		<div class="finlyzer-header__brand">
			<div class="finlyzer-header__identity">
				<img src="<?php echo esc_url(FINLYZER_PLUGIN_URL . 'assets/images/finlyzer-icon.svg'); ?>" alt="<?php esc_attr_e('Finlyzer Logo', 'finlyzer'); ?>" class="finlyzer-header__logo" width="38" height="38" />
				<h1 class="finlyzer-header__title"><?php esc_html_e('Finlyzer', 'finlyzer'); ?></h1>
			</div>
			<p class="finlyzer-header__sub">
				<?php esc_html_e('Track hidden payment gateway conversion fees and currency loss across your international sales.', 'finlyzer'); ?>
			</p>
		</div>

		<!-- Timeframe & Navigation Controls -->
		<div class="finlyzer-header__controls">
			<div class="finlyzer-range-group" role="group" aria-label="<?php esc_attr_e('Reporting timeframe', 'finlyzer'); ?>">
				<button type="button" class="finlyzer-range-btn is-active" data-days="30" aria-pressed="true" title="<?php esc_attr_e('View 30-day analytics', 'finlyzer'); ?>">30D</button>
				<button type="button" class="finlyzer-range-btn" data-days="60" aria-pressed="false" title="<?php esc_attr_e('View 60-day analytics', 'finlyzer'); ?>">60D</button>
				<button type="button" class="finlyzer-range-btn" data-days="90" aria-pressed="false" title="<?php esc_attr_e('View 90-day analytics', 'finlyzer'); ?>">90D</button>
			</div>
			<button type="button" class="finlyzer-settings-nav-btn" id="finlyzerSettingsNavBtn" title="<?php esc_attr_e('Finlyzer Cloud Sentinel Status', 'finlyzer'); ?>">
				<span class="finlyzer-nav-dot" aria-hidden="true"></span>
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
					<path d="M9 12l2 2 4-4"/>
				</svg>
				<span><?php esc_html_e('Cloud Sentinel', 'finlyzer'); ?></span>
			</button>
			<button type="button" class="finlyzer-about-nav-btn" id="finlyzerAboutNavBtn" title="<?php esc_attr_e('About Finlyzer & Author', 'finlyzer'); ?>">
				<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<circle cx="12" cy="12" r="10"></circle>
					<line x1="12" y1="16" x2="12" y2="12"></line>
					<line x1="12" y1="8" x2="12.01" y2="8"></line>
				</svg>
				<span><?php esc_html_e('About', 'finlyzer'); ?></span>
			</button>
		</div>
	</header>

	<!-- API Connection Status Banner -->
	<div id="finlyzer-connection-status" class="finlyzer-connection-status finlyzer-connection-status--connecting" role="status" aria-live="polite">
		<div class="finlyzer-connection-status__inner">
			<div class="finlyzer-connection-status__left">
				<span class="finlyzer-connection-spinner" id="finlyzer-connection-spinner" aria-hidden="true"></span>
				<span class="finlyzer-connection-icon" id="finlyzer-connection-icon" style="display:none;" aria-hidden="true">⚠️</span>
				<span class="finlyzer-connection-status__text" id="finlyzer-connection-status-text">
					<?php esc_html_e('Connecting to Finlyzer API...', 'finlyzer'); ?>
				</span>
			</div>
			<div class="finlyzer-connection-status__right">
				<button type="button" id="finlyzer-retry-btn" class="finlyzer-retry-btn" style="display:none;">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/>
					</svg>
					<span><?php esc_html_e('Retry Connection', 'finlyzer'); ?></span>
				</button>
			</div>
		</div>
	</div>

	<!-- Main Responsive Dashboard Layout (Horizontal on Desktop, Vertical on Mobile) -->
	<div class="finlyzer-main-layout">
		<!-- Primary Analytics Column (Hero, Metrics & Ledger) -->
		<div class="finlyzer-main-layout__primary">
			<section
				id="finlyzer-summary"
				class="finlyzer-summary"
				hx-get="<?php echo esc_url($rest_summary_url); ?>"
				hx-trigger="load"
				hx-headers='{"X-WP-Nonce": "<?php echo esc_attr($rest_nonce); ?>"}'
				hx-swap="innerHTML"
				aria-live="polite"
			>
				<div class="finlyzer-skeleton finlyzer-skeleton--hero" aria-hidden="true"></div>
				<div class="finlyzer-skeleton finlyzer-skeleton--ledger" aria-hidden="true"></div>
			</section>
		</div>

		<!-- Sidebar Advisory Column (Sticky AI Risk Sentinel on Desktop) -->
		<div class="finlyzer-main-layout__sidebar">
			<section
				id="finlyzer-insight"
				class="finlyzer-insight"
				hx-get="<?php echo esc_url($rest_insight_url); ?>"
				hx-trigger="load"
				hx-headers='{"X-WP-Nonce": "<?php echo esc_attr($rest_nonce); ?>"}'
				hx-swap="innerHTML"
				aria-live="polite"
			>
				<div class="finlyzer-skeleton finlyzer-skeleton--insight" aria-hidden="true"></div>
			</section>
		</div>
	</div>

	<?php
	// developer diagnostic section (rendered exclusively in development builds)
	if (class_exists('FXLI_Env') && FXLI_Env::dev_tools_enabled()) {
		include FINLYZER_PLUGIN_DIR . 'templates/partials/developer-section.php';
	}
	?>

	<!-- About Finlyzer & Engineering Author Section -->
	<section class="finlyzer-about-section" id="finlyzer-about-section" aria-label="<?php esc_attr_e('About Finlyzer', 'finlyzer'); ?>">
		<div class="finlyzer-about-card">
			<div class="finlyzer-about-grid">
				<div class="finlyzer-about-col finlyzer-about-col--info">
					<div class="finlyzer-about-pill">
						<span class="finlyzer-about-dot"></span>
						<span><?php esc_html_e('About', 'finlyzer'); ?></span>
					</div>
					<h3 class="finlyzer-about-title"><?php esc_html_e('AI Powered Cross-Border Loss Visibility For WooCommerce', 'finlyzer'); ?></h3>
					<p class="finlyzer-about-lead">
						<?php esc_html_e('AI Powered Cross-Border Loss Visibility For WooCommerce', 'finlyzer'); ?>
					</p>
					<p class="finlyzer-about-body">
						<?php esc_html_e('Fast AI-driven analytics for WooCommerce foreign exchange loss.', 'finlyzer'); ?>
					</p>
					<div class="finlyzer-about-badges">
						<span class="finlyzer-spec-chip">v<?php echo esc_html(FINLYZER_VERSION); ?></span>
					</div>
				</div>

				<div class="finlyzer-about-col finlyzer-about-col--author">
					<div class="finlyzer-author-box">
						<div class="finlyzer-author-profile">
							<div class="finlyzer-author-avatar" aria-hidden="true">
								<span>RG</span>
							</div>
							<div class="finlyzer-author-meta">
								<h4 class="finlyzer-author-name">RayGens</h4>
								<span class="finlyzer-author-role"><?php esc_html_e('Software Engineer & Backend Developer', 'finlyzer'); ?></span>
							</div>
						</div>
						<p class="finlyzer-author-tagline">
							<?php esc_html_e('Founder of Finlyzer and Developer.', 'finlyzer'); ?>
						</p>
						<div class="finlyzer-author-links">
							<a
								href="https://github.com/sl3nd3r1"
								target="_blank"
								rel="noopener noreferrer"
								class="finlyzer-social-btn finlyzer-social-btn--github"
								title="<?php esc_attr_e('View GitHub Profile (sl3nd3r1)', 'finlyzer'); ?>"
							>
								<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
									<path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.53 1.032 1.53 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/>
								</svg>
								<span>GitHub</span>
							</a>
							<a
								href="https://www.linkedin.com/in/ebrahimrazmahang"
								target="_blank"
								rel="noopener noreferrer"
								class="finlyzer-social-btn finlyzer-social-btn--linkedin"
								title="<?php esc_attr_e('Connect on LinkedIn (ebrahimrazmahang)', 'finlyzer'); ?>"
							>
								<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
									<path d="M19 0h-14c-2.761 0-5 2.239-5 5v14c0 2.761 2.239 5 5 5h14c2.762 0 5-2.239 5-5v-14c0-2.761-2.238-5-5-5zm-11 19h-3v-11h3v11zm-1.5-12.268c-.966 0-1.75-.79-1.75-1.764s.784-1.764 1.75-1.764 1.75.79 1.75 1.764-.783 1.764-1.75 1.764zm13.5 12.268h-3v-5.604c0-3.368-4-3.113-4 0v5.604h-3v-11h3v1.765c1.396-2.586 7-2.777 7 2.476v6.759z"/>
								</svg>
								<span>LinkedIn</span>
							</a>
						</div>
					</div>
				</div>
			</div>
		</div>
	</section>

	<!-- Security & Architecture Footer -->
	<footer class="finlyzer-footer">
		<div class="finlyzer-footer__left">
			<p>&copy; 2026 Finlyzer. All rights reserved.</p>
		</div>
		<div class="finlyzer-footer__right">
			<span class="finlyzer-version-tag">v<?php echo esc_html(FINLYZER_VERSION); ?></span>
		</div>
	</footer>

	<?php
	// security & worker settings modal
	include FINLYZER_PLUGIN_DIR . 'templates/partials/settings-modal.php';
	?>

</div>
