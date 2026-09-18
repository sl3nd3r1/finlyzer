<?php
declare(strict_types=1);

// prevent direct script execution
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Server-rendered, escaped HTML fragment for the Finlyzer summary view.
 * Swapped directly into the DOM via htmx.
 *
 * @var array $summary Aggregated metrics from FXLI_Order_Analyzer::get_summary().
 */
$store_currency      = (string) ($summary['store_currency'] ?? 'USD');
$total_loss          = (float) ($summary['total_loss'] ?? 0.0);
$order_count         = (int) ($summary['order_count'] ?? 0);
$period_days         = (int) ($summary['period_days'] ?? 30);
$avg_loss_per_order  = (float) ($summary['avg_loss_per_order'] ?? 0.0);
$annualized_run_rate = (float) ($summary['annualized_run_rate'] ?? 0.0);
$severity_level      = (string) ($summary['severity_level'] ?? 'moderate');
$by_currency         = (array) ($summary['by_currency'] ?? []);

$formatted_total      = wc_price($total_loss, ['currency' => $store_currency]);
$formatted_run_rate   = wc_price($annualized_run_rate, ['currency' => $store_currency]);
$formatted_avg_order  = wc_price($avg_loss_per_order, ['currency' => $store_currency]);

// map severity level to label and CSS modifier
$severity_labels = [
	'critical' => __('CRITICAL MARGIN DRAIN', 'finlyzer'),
	'warning'  => __('HIGH SPREAD EXPOSURE', 'finlyzer'),
	'moderate' => __('DETECTED LEAKAGE', 'finlyzer'),
	'optimal'  => __('OPTIMAL MARGIN RETENTION', 'finlyzer'),
];
$severity_label = $severity_labels[$severity_level] ?? $severity_labels['moderate'];
?>

<!-- Overview Grid -->
<div class="finlyzer-grid">

	<!-- Hero Metric Card -->
	<div class="finlyzer-card finlyzer-card--hero finlyzer-severity--<?php echo esc_attr($severity_level); ?>">
		<div class="finlyzer-hero__header">
			<span class="finlyzer-card__eyebrow">
				<?php echo esc_html(sprintf(
					/* translators: %d: period days */
					__('ESTIMATED SPREAD LOSS (%d-DAY AUDIT)', 'finlyzer'),
					$period_days
				)); ?>
			</span>
			<span class="finlyzer-severity-pill finlyzer-severity-pill--<?php echo esc_attr($severity_level); ?>">
				<span class="finlyzer-pill-indicator"></span>
				<?php echo esc_html($severity_label); ?>
			</span>
		</div>

		<div class="finlyzer-hero__main">
			<div class="finlyzer-hero__figure">
				<?php echo wp_kses_post($formatted_total); ?>
			</div>
			<div class="finlyzer-hero__context">
				<?php echo esc_html(sprintf(
					/* translators: 1: order count, 2: store base currency */
					_n('Drained across %1$d cross-currency order settling into %2$s', 'Drained across %1$d cross-currency orders settling into %2$s', $order_count, 'finlyzer'),
					$order_count,
					$store_currency
				)); ?>
			</div>
		</div>
	</div>

	<!-- Secondary KPI Metric Cards -->
	<div class="finlyzer-kpi-stack">
		<div class="finlyzer-card finlyzer-kpi-card">
			<span class="finlyzer-kpi-label"><?php esc_html_e('ANNUALIZED RUN-RATE', 'finlyzer'); ?></span>
			<span class="finlyzer-kpi-val finlyzer-val--loss">
				<?php echo wp_kses_post($formatted_run_rate); ?>
			</span>
			<span class="finlyzer-kpi-sub"><?php esc_html_e('Projected 365-day capital leakage', 'finlyzer'); ?></span>
		</div>

		<div class="finlyzer-card finlyzer-kpi-card">
			<span class="finlyzer-kpi-label"><?php esc_html_e('AVG LOSS PER ORDER', 'finlyzer'); ?></span>
			<span class="finlyzer-kpi-val">
				<?php echo wp_kses_post($formatted_avg_order); ?>
			</span>
			<span class="finlyzer-kpi-sub"><?php esc_html_e('Gateway spread markup impact', 'finlyzer'); ?></span>
		</div>
	</div>

</div>

<?php if (!empty($by_currency)) : ?>
	<!-- Visual Multi-Currency Loss Distribution Bar -->
	<div class="finlyzer-distribution">
		<div class="finlyzer-distribution__header">
			<span class="finlyzer-distribution__title"><?php esc_html_e('Currency Exposure Distribution', 'finlyzer'); ?></span>
			<span class="finlyzer-distribution__legend">
				<?php foreach (array_slice($by_currency, 0, 4) as $curr => $data) : ?>
					<span class="finlyzer-legend-item">
						<span class="finlyzer-legend-swatch curr-<?php echo esc_attr(strtolower($curr)); ?>"></span>
						<?php echo esc_html($curr); ?> (<?php echo esc_html(number_format((float) ($data['share_pct'] ?? 0), 1)); ?>%)
					</span>
				<?php endforeach; ?>
			</span>
		</div>
		<div class="finlyzer-distribution-bar" role="progressbar" aria-label="<?php esc_attr_e('FX loss percentage breakdown', 'finlyzer'); ?>">
			<?php foreach ($by_currency as $curr => $data) : ?>
				<?php $pct = (float) ($data['share_pct'] ?? 0); ?>
				<?php if ($pct > 0.5) : ?>
					<div
						class="finlyzer-bar-segment curr-<?php echo esc_attr(strtolower($curr)); ?>"
						style="width: <?php echo esc_attr((string) $pct); ?>%;"
						title="<?php echo esc_attr(sprintf('%s: %s (%s%%)', $curr, wp_strip_all_tags(wc_price((float) $data['loss'], ['currency' => $store_currency])), $pct)); ?>"
					></div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	</div>
<?php endif; ?>

<!-- Detailed Currency Statement Ledger -->
<div class="finlyzer-ledger-card">
	<div class="finlyzer-ledger-header">
		<h3 class="finlyzer-ledger-title"><?php esc_html_e('Cross-Border Currency Ledger', 'finlyzer'); ?></h3>
		<span class="finlyzer-ledger-caption">
			<?php echo esc_html(sprintf(
				/* translators: %d: currency count */
				_n('%d foreign currency active', '%d foreign currencies active', count($by_currency), 'finlyzer'),
				count($by_currency)
			)); ?>
		</span>
	</div>

	<div class="finlyzer-ledger" role="table" aria-label="<?php esc_attr_e('FX loss by currency statement', 'finlyzer'); ?>">
		<div class="finlyzer-ledger__row finlyzer-ledger__row--head" role="row">
			<span role="columnheader"><?php esc_html_e('Currency', 'finlyzer'); ?></span>
			<span role="columnheader"><?php esc_html_e('Transactions', 'finlyzer'); ?></span>
			<span role="columnheader"><?php esc_html_e('Share of Drain', 'finlyzer'); ?></span>
			<span role="columnheader" class="finlyzer-text-right"><?php esc_html_e('Est. Spread Loss', 'finlyzer'); ?></span>
		</div>

		<?php if (empty($by_currency)) : ?>
			<div class="finlyzer-ledger__row finlyzer-ledger__empty" role="row">
				<span role="cell" colspan="4">
					<span class="finlyzer-empty-icon">&#x2714;</span>
					<?php esc_html_e('Zero cross-border FX transactions detected in this window. No currency spread markup incurred.', 'finlyzer'); ?>
				</span>
			</div>
		<?php else : ?>
			<?php foreach ($by_currency as $currency => $row) : ?>
				<div class="finlyzer-ledger__row" role="row">
					<span role="cell" class="finlyzer-ledger__currency">
						<span class="finlyzer-curr-badge"><?php echo esc_html($currency); ?></span>
					</span>
					<span role="cell" class="finlyzer-ledger__orders">
						<?php echo esc_html(number_format((int) $row['orders'])); ?>
					</span>
					<span role="cell" class="finlyzer-ledger__share">
						<div class="finlyzer-mini-bar">
							<div class="finlyzer-mini-bar-fill" style="width: <?php echo esc_attr((string) ($row['share_pct'] ?? 0)); ?>%;"></div>
						</div>
						<span class="finlyzer-share-text"><?php echo esc_html(number_format((float) ($row['share_pct'] ?? 0), 1)); ?>%</span>
					</span>
					<span role="cell" class="finlyzer-ledger__loss finlyzer-text-right">
						<?php echo wp_kses_post(wc_price((float) $row['loss'], ['currency' => $store_currency])); ?>
					</span>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</div>
