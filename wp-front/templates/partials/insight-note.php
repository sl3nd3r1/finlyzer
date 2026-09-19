<?php
declare(strict_types=1);

// prevent direct script execution
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Server-rendered, escaped HTML fragment for the Finlyzer Margin Sentinel.
 * Styled with Microsoft Fluent 2 subtle rounded components and humanized executive copy.
 *
 * @var string|WP_Error $insight Sanitized insight or data-backed advisory.
 * @var string|null      $error   Error message if Worker failed without fallback.
 * @var bool|null        $is_optimal Optimal state flag.
 */

$insight_text = is_string($insight) ? $insight : '';
$is_optimal_state = isset($is_optimal)
	? (bool) $is_optimal
	: (stripos($insight_text, 'base currency') !== false
		|| stripos($insight_text, 'no processor') !== false
		|| stripos($insight_text, 'optimal') !== false
		|| stripos($insight_text, 'no payment') !== false
		|| stripos($insight_text, 'zero') !== false);
?>
<div class="finlyzer-sentinel-box <?php echo $is_optimal_state ? 'finlyzer-sentinel-box--optimal' : ''; ?>">

	<!-- Header with Fluent 2 rounded status badge -->
	<div class="finlyzer-sentinel-header">
		<div class="finlyzer-sentinel-title-wrap">
			<div class="finlyzer-sentinel-icon-wrap">
				<?php if ($is_optimal_state) : ?>
					<!-- Secure shield check icon for optimal margin state -->
					<svg class="finlyzer-sentinel-svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
						<polyline points="9 12 11 14 15 10"></polyline>
					</svg>
				<?php else : ?>
					<!-- Alert triangle icon for active spread leakage -->
					<svg class="finlyzer-sentinel-svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
						<line x1="12" y1="9" x2="12" y2="13"></line>
						<line x1="12" y1="17" x2="12.01" y2="17"></line>
					</svg>
				<?php endif; ?>
			</div>
			<div>
				<h4 class="finlyzer-sentinel-title"><?php esc_html_e('Margin Sentinel', 'finlyzer'); ?></h4>
				<span class="finlyzer-sentinel-subtitle"><?php esc_html_e('Automated foreign exchange fee audit', 'finlyzer'); ?></span>
			</div>
		</div>

		<div class="finlyzer-sentinel-tag <?php echo $is_optimal_state ? 'finlyzer-sentinel-tag--optimal' : ''; ?>">
			<span class="finlyzer-sentinel-pulse"></span>
			<span><?php echo esc_html($is_optimal_state ? __('MARGIN SECURE', 'finlyzer') : __('FEES DETECTED', 'finlyzer')); ?></span>
		</div>
	</div>

	<!-- Body content -->
	<div class="finlyzer-sentinel-body">
		<?php if (!empty($error) && empty($insight_text)) : ?>
			<p class="finlyzer-sentinel-text finlyzer-sentinel-text--muted">
				<?php esc_html_e('Audit summary temporarily unavailable — order analytics above remain verified.', 'finlyzer'); ?>
			</p>
		<?php else : ?>
			<p class="finlyzer-sentinel-text">
				<?php echo esc_html($insight_text); ?>
			</p>
		<?php endif; ?>
	</div>

</div>
