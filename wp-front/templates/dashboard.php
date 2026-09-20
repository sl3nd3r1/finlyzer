<?php
declare(strict_types=1);

// prevent direct template execution
if (!defined('ABSPATH')) {
	exit;
}

$default_days = 30;
$rest_summary_url = add_query_arg('days', $default_days, rest_url('finlyzer/v1/summary'));
$rest_insight_url = add_query_arg('days', $default_days, rest_url('finlyzer/v1/insight'));
$rest_nonce = wp_create_nonce('wp_rest');
$rest_base_url = rest_url('finlyzer/v1');
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
			<h1 class="finlyzer-header__title"><?php esc_html_e('Finlyzer', 'finlyzer'); ?></h1>
			<p class="finlyzer-header__sub">
				<?php esc_html_e('Track hidden payment gateway conversion fees and currency loss across your international sales.', 'finlyzer'); ?>
			</p>
		</div>

		<!-- Timeframe Selector Controls -->
		<div class="finlyzer-header__controls">
			<div class="finlyzer-range-group" role="group" aria-label="<?php esc_attr_e('Reporting timeframe', 'finlyzer'); ?>">
				<button type="button" class="finlyzer-range-btn is-active" data-days="30" aria-pressed="true" title="<?php esc_attr_e('View 30-day analytics', 'finlyzer'); ?>">30D</button>
				<button type="button" class="finlyzer-range-btn" data-days="60" aria-pressed="false" title="<?php esc_attr_e('View 60-day analytics', 'finlyzer'); ?>">60D</button>
				<button type="button" class="finlyzer-range-btn" data-days="90" aria-pressed="false" title="<?php esc_attr_e('View 90-day analytics', 'finlyzer'); ?>">90D</button>
			</div>
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

	<!-- Security & Architecture Footer -->
	<footer class="finlyzer-footer">
		<div class="finlyzer-footer__left">
			<p>&copy; 2026 Finlyzer. All rights reserved.</p>
		</div>
		<div class="finlyzer-footer__right">
			<span class="finlyzer-version-tag">v<?php echo esc_html(FINLYZER_VERSION); ?></span>
		</div>
	</footer>

</div>
