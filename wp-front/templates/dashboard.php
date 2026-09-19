<?php
declare(strict_types=1);

// prevent direct template execution
if (!defined('ABSPATH')) {
	exit;
}

$default_days = 30;
$rest_summary_url = rest_url('finlyzer/v1/summary') . '?days=' . $default_days;
$rest_insight_url = rest_url('finlyzer/v1/insight') . '?days=' . $default_days;
$rest_nonce = wp_create_nonce('wp_rest');
?>
<div class="finlyzer-app" id="finlyzer-app">

	<!-- Top Navigation and Header -->
	<header class="finlyzer-header">
		<div class="finlyzer-header__brand">
			<div class="finlyzer-badge">
				<span class="finlyzer-badge__dot"></span>
				<span class="finlyzer-badge__text"><?php esc_html_e('CURRENCY AUDIT ACTIVE', 'finlyzer'); ?></span>
			</div>
			<h1 class="finlyzer-header__title"><?php esc_html_e('Finlyzer', 'finlyzer'); ?></h1>
			<p class="finlyzer-header__sub">
				<?php esc_html_e('Track hidden payment gateway conversion fees and currency loss across your international sales.', 'finlyzer'); ?>
			</p>
		</div>

		<!-- Timeframe Selector -->
		<div class="finlyzer-header__controls">
			<div class="finlyzer-range-group" role="group" aria-label="<?php esc_attr_e('Reporting timeframe', 'finlyzer'); ?>">
				<button type="button" class="finlyzer-range-btn is-active" data-days="30">30D</button>
				<button type="button" class="finlyzer-range-btn" data-days="60">60D</button>
				<button type="button" class="finlyzer-range-btn" data-days="90">90D</button>
			</div>
		</div>
	</header>

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
