<?php
declare(strict_types=1);

// prevent direct script execution
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Server-rendered, escaped HTML fragment for the Finlyzer AI Risk Sentinel.
 * Styled with an intentional, chic financial warning tone alerting merchants to capital leakage.
 *
 * @var string|WP_Error $insight Sanitized insight or data-backed heuristic warning.
 * @var string|null      $error   Error message if Worker failed without fallback.
 */

$insight_text = is_string($insight) ? $insight : '';
?>
<div class="finlyzer-sentinel-box">

	<!-- Header with glowing warning badge -->
	<div class="finlyzer-sentinel-header">
		<div class="finlyzer-sentinel-title-wrap">
			<div class="finlyzer-sentinel-icon-wrap">
				<!-- Inline SVG alert sentinel icon (safe, no external request, zero XSS) -->
				<svg class="finlyzer-sentinel-svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
					<line x1="12" y1="9" x2="12" y2="13"></line>
					<line x1="12" y1="17" x2="12.01" y2="17"></line>
				</svg>
			</div>
			<div>
				<h4 class="finlyzer-sentinel-title"><?php esc_html_e('AI Financial Risk Sentinel', 'finlyzer'); ?></h4>
				<span class="finlyzer-sentinel-subtitle"><?php esc_html_e('Autonomous Currency & Margin Erosion Advisory', 'finlyzer'); ?></span>
			</div>
		</div>

		<div class="finlyzer-sentinel-tag">
			<span class="finlyzer-sentinel-pulse"></span>
			<span><?php esc_html_e('EXPOSURE WARNING', 'finlyzer'); ?></span>
		</div>
	</div>

	<!-- Body content -->
	<div class="finlyzer-sentinel-body">
		<?php if (!empty($error) && empty($insight_text)) : ?>
			<p class="finlyzer-sentinel-text finlyzer-sentinel-text--muted">
				<?php esc_html_e('AI Sentinel temporarily offline — order analytics above remain verified.', 'finlyzer'); ?>
			</p>
		<?php else : ?>
			<p class="finlyzer-sentinel-text">
				<?php echo esc_html($insight_text); ?>
			</p>
		<?php endif; ?>
	</div>

	<!-- Strategic Action Banner -->
	<div class="finlyzer-sentinel-action">
		<span class="finlyzer-action-label"><?php esc_html_e('Strategic Remediation:', 'finlyzer'); ?></span>
		<span class="finlyzer-action-text">
			<?php esc_html_e('Configure local currency settlement or multi-currency pricing to capture gateway spreads into store profit.', 'finlyzer'); ?>
		</span>
	</div>

</div>
