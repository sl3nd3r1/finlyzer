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
$is_mock             = !empty($summary['is_mock']);

// market timing loss & combined drag metrics
$total_market_timing_loss     = (float) ($summary['total_market_timing_loss'] ?? 0.0);
$total_combined_currency_drag = (float) ($summary['total_combined_currency_drag'] ?? round($total_loss + $total_market_timing_loss, 2));
$active_markets               = (array) ($summary['active_markets'] ?? []);
$gateways                     = (array) ($summary['gateways'] ?? []);
$products_by_gateway          = (array) ($summary['products_by_gateway'] ?? []);

$formatted_total         = wc_price($total_loss, ['currency' => $store_currency]);
$formatted_run_rate      = wc_price($annualized_run_rate, ['currency' => $store_currency]);
$formatted_avg_order     = wc_price($avg_loss_per_order, ['currency' => $store_currency]);
$formatted_timing_loss   = wc_price($total_market_timing_loss, ['currency' => $store_currency]);
$formatted_combined_drag = wc_price($total_combined_currency_drag, ['currency' => $store_currency]);

// map severity level to label and CSS modifier
$severity_labels = [
	'critical' => __('CRITICAL MARGIN DRAIN', 'finlyzer'),
	'warning'  => __('HIGH SPREAD EXPOSURE', 'finlyzer'),
	'moderate' => __('DETECTED LEAKAGE', 'finlyzer'),
	'optimal'  => __('OPTIMAL MARGIN RETENTION', 'finlyzer'),
];
$severity_label = $severity_labels[$severity_level] ?? $severity_labels['moderate'];
?>

<!-- Environment & Data Mode Indicator Bar -->
<div class="finlyzer-mode-bar <?php echo $is_mock ? 'finlyzer-mode-bar--mock' : 'finlyzer-mode-bar--live'; ?>">
	<div class="finlyzer-mode-bar__status">
		<span class="finlyzer-mode-dot"></span>
		<span class="finlyzer-mode-text">
			<?php if ($is_mock) : ?>
				<strong><?php esc_html_e('SIMULATION MODE', 'finlyzer'); ?></strong> &bull; <?php esc_html_e('Displaying simulated cross-currency orders', 'finlyzer'); ?>
			<?php else : ?>
				<strong><?php esc_html_e('LIVE STORE AUDIT', 'finlyzer'); ?></strong> &bull; <?php esc_html_e('Continuous transaction monitoring active', 'finlyzer'); ?>
			<?php endif; ?>
		</span>
	</div>
	<div class="finlyzer-mode-bar__action">
		<?php if ($is_mock) : ?>
			<a href="?finlyzer_mode=live" class="finlyzer-mode-switch-btn" title="<?php esc_attr_e('Switch to live store orders', 'finlyzer'); ?>">
				&larr; <?php esc_html_e('Switch to Live Data', 'finlyzer'); ?>
			</a>
		<?php else : ?>
			<a href="?finlyzer_mode=mock" class="finlyzer-mode-switch-btn" title="<?php esc_attr_e('Switch to simulated mock telemetry', 'finlyzer'); ?>">
				<?php esc_html_e('Preview Mock Data', 'finlyzer'); ?> &rarr;
			</a>
		<?php endif; ?>
	</div>
</div>

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
				<?php if ($order_count === 0) : ?>
					<?php echo esc_html(sprintf(__('No cross-border orders settling into %s', 'finlyzer'), $store_currency)); ?>
				<?php else : ?>
					<?php echo esc_html(sprintf(
						/* translators: 1: order count, 2: store base currency */
						_n('Across %1$d cross-border order settling into %2$s', 'Across %1$d cross-border orders settling into %2$s', $order_count, 'finlyzer'),
						$order_count,
						$store_currency
					)); ?>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- Secondary KPI Metric Cards (2x2 Grid) -->
	<div class="finlyzer-kpi-stack">
		<div class="finlyzer-card finlyzer-kpi-card">
			<span class="finlyzer-kpi-label"><?php esc_html_e('ANNUALIZED RUN-RATE', 'finlyzer'); ?></span>
			<span class="finlyzer-kpi-val finlyzer-val--loss">
				<?php echo wp_kses_post($formatted_run_rate); ?>
			</span>
			<span class="finlyzer-kpi-sub"><?php esc_html_e('Projected 12-month margin impact', 'finlyzer'); ?></span>
		</div>

		<div class="finlyzer-card finlyzer-kpi-card">
			<span class="finlyzer-kpi-label"><?php esc_html_e('AVG LOSS PER ORDER', 'finlyzer'); ?></span>
			<span class="finlyzer-kpi-val">
				<?php echo wp_kses_post($formatted_avg_order); ?>
			</span>
			<span class="finlyzer-kpi-sub"><?php esc_html_e('Average conversion spread per order', 'finlyzer'); ?></span>
		</div>

		<div class="finlyzer-card finlyzer-kpi-card finlyzer-card--timing">
			<div class="finlyzer-kpi-header">
				<span class="finlyzer-kpi-label"><?php esc_html_e('MARKET TIMING LOSS', 'finlyzer'); ?></span>
				<span class="finlyzer-badge-ecb" title="<?php esc_attr_e('ECB reference rate via api.frankfurter.dev', 'finlyzer'); ?>"><?php esc_html_e('ECB Spot', 'finlyzer'); ?></span>
			</div>
			<span class="finlyzer-kpi-val <?php echo $total_market_timing_loss > 0 ? 'finlyzer-val--timing' : ''; ?>">
				<?php echo wp_kses_post($formatted_timing_loss); ?>
			</span>
			<span class="finlyzer-kpi-sub"><?php esc_html_e('Settlement delay & rate shift', 'finlyzer'); ?></span>
		</div>

		<div class="finlyzer-card finlyzer-kpi-card finlyzer-card--drag">
			<div class="finlyzer-kpi-header">
				<span class="finlyzer-kpi-label"><?php esc_html_e('COMBINED CURRENCY DRAG', 'finlyzer'); ?></span>
				<span class="finlyzer-badge-total"><?php esc_html_e('Total FX Drag', 'finlyzer'); ?></span>
			</div>
			<span class="finlyzer-kpi-val finlyzer-val--loss">
				<?php echo wp_kses_post($formatted_combined_drag); ?>
			</span>
			<span class="finlyzer-kpi-sub"><?php esc_html_e('Combined gateway markup & timing drag', 'finlyzer'); ?></span>
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
					<?php
					$strip_fn = function_exists('wp_strip_all_tags') ? 'wp_strip_all_tags' : 'strip_tags';
					$price_label = $strip_fn(wc_price((float) $data['loss'], ['currency' => $store_currency]));
					$tooltip_title = sprintf('%s: %s (%s%%)', $curr, $price_label, $pct);
					?>
					<div
						class="finlyzer-bar-segment curr-<?php echo esc_attr(strtolower($curr)); ?>"
						style="width: <?php echo esc_attr((string) $pct); ?>%;"
						title="<?php echo esc_attr($tooltip_title); ?>"
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
					<?php esc_html_e('No foreign currency orders recorded in this period. Currency margins are preserved.', 'finlyzer'); ?>
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

<!-- Active Currency Markets & Market Timing Volatility Impact (Frankfurter ECB Integration) -->
<div class="finlyzer-ledger-card finlyzer-timing-card">
	<div class="finlyzer-ledger-header">
		<div>
			<h3 class="finlyzer-ledger-title">
				<span class="finlyzer-title-badge">ECB</span>
				<?php esc_html_e('Active Currency Markets & Timing Impact', 'finlyzer'); ?>
			</h3>
			<p class="finlyzer-ledger-subtitle">
				<?php echo esc_html(sprintf(
					/* translators: %d: active currency count */
					__('Filtered strictly to your store\'s %d active transaction currency markets. Reference rates sourced via Frankfurter (api.frankfurter.dev).', 'finlyzer'),
					count($active_markets)
				)); ?>
			</p>
		</div>
		<span class="finlyzer-badge-filter-notice">
			<?php echo esc_html(sprintf(
				/* translators: %d: active count */
				__('%d of 30 Supported Markets Active', 'finlyzer'),
				count($active_markets)
			)); ?>
		</span>
	</div>

	<div class="finlyzer-ledger finlyzer-timing-table" role="table" aria-label="<?php esc_attr_e('Active currency market timing breakdown', 'finlyzer'); ?>">
		<div class="finlyzer-ledger__row finlyzer-ledger__row--head" role="row">
			<span role="columnheader"><?php esc_html_e('Market / Country', 'finlyzer'); ?></span>
			<span role="columnheader"><?php esc_html_e('Order vs Spot Rate', 'finlyzer'); ?></span>
			<span role="columnheader"><?php esc_html_e('Spread Loss', 'finlyzer'); ?></span>
			<span role="columnheader"><?php esc_html_e('Timing Volatility', 'finlyzer'); ?></span>
			<span role="columnheader" class="finlyzer-text-right"><?php esc_html_e('Total Combined Drag', 'finlyzer'); ?></span>
		</div>

		<?php if (empty($active_markets)) : ?>
			<div class="finlyzer-ledger__row finlyzer-ledger__empty" role="row">
				<span role="cell" colspan="5">
					<span class="finlyzer-empty-icon">&#x2714;</span>
					<?php esc_html_e('No active foreign markets in this period. Currency rates matched spot benchmarks.', 'finlyzer'); ?>
				</span>
			</div>
		<?php else : ?>
			<?php foreach ($active_markets as $market) : ?>
				<?php
				$m_curr     = (string) ($market['currency'] ?? '');
				$m_country  = (string) ($market['country'] ?? $m_curr);
				$m_flag     = (string) ($market['flag_emoji'] ?? '🌐');
				$m_name     = (string) ($market['currency_name'] ?? $m_curr);
				$order_rate = (float) ($market['order_exchange_rate'] ?? 0.0);
				$spot_rate  = (float) ($market['spot_exchange_rate'] ?? 0.0);
				$rate_pct   = (float) ($market['rate_change_pct'] ?? 0.0);
				$is_loss    = !empty($market['is_timing_loss']);
				$t_loss     = (float) ($market['market_timing_loss'] ?? 0.0);
				$s_loss     = (float) ($market['gateway_spread_loss'] ?? 0.0);
				$tot_drag   = (float) ($market['total_currency_drag'] ?? ($s_loss + $t_loss));
				?>
				<div class="finlyzer-ledger__row" role="row">
					<!-- Country & Currency -->
					<span role="cell" class="finlyzer-market-cell">
						<span class="finlyzer-country-flag" aria-hidden="true"><?php echo esc_html($m_flag); ?></span>
						<span class="finlyzer-market-info">
							<strong class="finlyzer-country-name"><?php echo esc_html($m_country); ?></strong>
							<span class="finlyzer-curr-sub"><?php echo esc_html($m_curr); ?> &bull; <?php echo esc_html($m_name); ?></span>
						</span>
					</span>

					<!-- Rate Comparison & Shift -->
					<span role="cell" class="finlyzer-rate-cell">
						<div class="finlyzer-rate-values">
							<span class="finlyzer-rate-val" title="<?php esc_attr_e('Historical Order Rate', 'finlyzer'); ?>">
								<?php echo esc_html(number_format($order_rate, 4)); ?>
							</span>
							<span class="finlyzer-rate-arrow">&rarr;</span>
							<span class="finlyzer-rate-val finlyzer-rate-val--spot" title="<?php esc_attr_e('Current ECB Spot Rate', 'finlyzer'); ?>">
								<?php echo esc_html(number_format($spot_rate, 4)); ?>
							</span>
						</div>
						<span class="finlyzer-shift-pill <?php echo $rate_pct < 0 ? 'finlyzer-shift--deprec' : 'finlyzer-shift--apprec'; ?>">
							<?php if ($rate_pct < 0) : ?>
								&darr; <?php echo esc_html(abs($rate_pct)); ?>% <?php esc_html_e('Depreciated', 'finlyzer'); ?>
							<?php elseif ($rate_pct > 0) : ?>
								&uarr; +<?php echo esc_html($rate_pct); ?>% <?php esc_html_e('Appreciated', 'finlyzer'); ?>
							<?php else : ?>
								&bull; 0.0% <?php esc_html_e('Stable', 'finlyzer'); ?>
							<?php endif; ?>
						</span>
					</span>

					<!-- Gateway Spread Loss -->
					<span role="cell" class="finlyzer-spread-cell">
						<?php echo wp_kses_post(wc_price($s_loss, ['currency' => $store_currency])); ?>
					</span>

					<!-- Market Timing Volatility Loss -->
					<span role="cell" class="finlyzer-timing-loss-cell">
						<?php if ($t_loss > 0) : ?>
							<span class="finlyzer-timing-tag finlyzer-timing-tag--loss">
								+<?php echo wp_kses_post(wc_price($t_loss, ['currency' => $store_currency])); ?>
							</span>
						<?php else : ?>
							<span class="finlyzer-timing-tag finlyzer-timing-tag--neutral">
								<?php esc_html_e('0.00 (Favorable)', 'finlyzer'); ?>
							</span>
						<?php endif; ?>
					</span>

					<!-- Total Combined Drag -->
					<span role="cell" class="finlyzer-drag-cell finlyzer-text-right">
						<strong><?php echo wp_kses_post(wc_price($tot_drag, ['currency' => $store_currency])); ?></strong>
					</span>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</div>

<!-- Payment Gateway FX Recognition Matrix -->
<div class="finlyzer-ledger-card finlyzer-gateway-section">
	<div class="finlyzer-ledger-header">
		<div>
			<h3 class="finlyzer-ledger-title">
				<span class="finlyzer-title-badge finlyzer-title-badge--gateway">GATEWAYS</span>
				<?php esc_html_e('Payment Gateway FX Recognition Matrix', 'finlyzer'); ?>
			</h3>
			<p class="finlyzer-ledger-subtitle">
				<?php echo esc_html(sprintf(
					/* translators: %d: gateway count */
					__('Active payment gateways detected in your WooCommerce store, cross-border FX capability, spread markup rates, and isolated currency drag.', 'finlyzer'),
					count($gateways)
				)); ?>
			</p>
		</div>
		<span class="finlyzer-badge-filter-notice">
			<?php echo esc_html(sprintf(
				/* translators: %d: gateway count */
				__('%d Gateways Profiled', 'finlyzer'),
				count($gateways)
			)); ?>
		</span>
	</div>

	<?php if (empty($gateways)) : ?>
		<div class="finlyzer-ledger__row finlyzer-ledger__empty" role="row">
			<span role="cell" colspan="4">
				<span class="finlyzer-empty-icon">&#x2714;</span>
				<?php esc_html_e('Zero payment gateway cross-border activity recorded in this period.', 'finlyzer'); ?>
			</span>
		</div>
	<?php else : ?>
		<div class="finlyzer-gateway-grid">
			<?php foreach ($gateways as $gw_id => $gw) : ?>
				<?php
				$gw_name    = (string) ($gw['name'] ?? $gw_id);
				$gw_title   = (string) ($gw['title'] ?? $gw_name);
				$supports   = !empty($gw['supports_fx']);
				$spread_pct = (float) ($gw['spread_rate_pct'] ?? 0.0);
				$fx_status  = (string) ($gw['fx_status'] ?? ($supports ? 'Active FX' : 'Domestic Only'));
				$color      = (string) ($gw['badge_color'] ?? '#6366F1');
				$orders     = (int) ($gw['orders'] ?? 0);
				$volume     = (float) ($gw['volume'] ?? 0.0);
				$loss       = (float) ($gw['loss'] ?? 0.0);
				$share_pct  = (float) ($gw['loss_share_pct'] ?? 0.0);
				$desc       = (string) ($gw['fee_description'] ?? '');
				?>
				<div class="finlyzer-gw-card" style="--gw-accent: <?php echo esc_attr($color); ?>;">
					<div class="finlyzer-gw-card__top">
						<div class="finlyzer-gw-card__identity">
							<span class="finlyzer-gw-card__dot"></span>
							<div>
								<h4 class="finlyzer-gw-card__name"><?php echo esc_html($gw_name); ?></h4>
								<span class="finlyzer-gw-card__type"><?php echo esc_html($gw_title); ?></span>
							</div>
						</div>
						<div class="finlyzer-gw-card__tags">
							<?php if ($supports) : ?>
								<span class="finlyzer-gw-tag finlyzer-gw-tag--fx" title="<?php esc_attr_e('Processes cross-currency transactions with spread markup', 'finlyzer'); ?>">
									<?php esc_html_e('Supports FX', 'finlyzer'); ?>
								</span>
								<span class="finlyzer-gw-tag finlyzer-gw-tag--spread">
									<?php echo esc_html(number_format($spread_pct, 1)); ?>% <?php esc_html_e('Spread', 'finlyzer'); ?>
								</span>
							<?php else : ?>
								<span class="finlyzer-gw-tag finlyzer-gw-tag--domestic">
									<?php esc_html_e('Domestic Only', 'finlyzer'); ?>
								</span>
							<?php endif; ?>
						</div>
					</div>

					<div class="finlyzer-gw-card__metrics">
						<div class="finlyzer-gw-metric">
							<span class="finlyzer-gw-metric__label"><?php esc_html_e('FX Orders', 'finlyzer'); ?></span>
							<strong class="finlyzer-gw-metric__val"><?php echo esc_html(number_format($orders)); ?></strong>
						</div>
						<div class="finlyzer-gw-metric">
							<span class="finlyzer-gw-metric__label"><?php esc_html_e('Foreign Volume', 'finlyzer'); ?></span>
							<strong class="finlyzer-gw-metric__val"><?php echo wp_kses_post(wc_price($volume, ['currency' => $store_currency])); ?></strong>
						</div>
						<div class="finlyzer-gw-metric">
							<span class="finlyzer-gw-metric__label"><?php esc_html_e('Gateway FX Loss', 'finlyzer'); ?></span>
							<strong class="finlyzer-gw-metric__val finlyzer-gw-metric__val--loss"><?php echo wp_kses_post(wc_price($loss, ['currency' => $store_currency])); ?></strong>
						</div>
					</div>

					<div class="finlyzer-gw-card__share">
						<div class="finlyzer-gw-share-bar">
							<div class="finlyzer-gw-share-bar__fill" style="width: <?php echo esc_attr((string) $share_pct); ?>%;"></div>
						</div>
						<span class="finlyzer-gw-share-label">
							<?php echo esc_html(sprintf(
								/* translators: %s: percentage */
								__('%s%% of total store FX loss', 'finlyzer'),
								number_format($share_pct, 1)
							)); ?>
						</span>
					</div>

					<?php if ($desc !== '') : ?>
						<div class="finlyzer-gw-card__desc">
							<span class="finlyzer-gw-desc-icon">&#9432;</span>
							<span><?php echo esc_html($desc); ?></span>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>

<!-- Purchased Products by Gateway Ledger -->
<div class="finlyzer-ledger-card finlyzer-products-section">
	<div class="finlyzer-ledger-header">
		<div>
			<h3 class="finlyzer-ledger-title">
				<span class="finlyzer-title-badge finlyzer-title-badge--products">PRODUCTS</span>
				<?php esc_html_e('Purchased Products by Payment Gateway', 'finlyzer'); ?>
			</h3>
			<p class="finlyzer-ledger-subtitle">
				<?php esc_html_e('Catalog items purchased via cross-border payment gateways, attributed foreign revenue, and proportional FX spread drag.', 'finlyzer'); ?>
			</p>
		</div>
		<div class="finlyzer-gw-filter-bar" id="finlyzerGwFilterBar">
			<button type="button" class="finlyzer-gw-tab finlyzer-gw-tab--active" data-gw-filter="all">
				<?php esc_html_e('All Gateways', 'finlyzer'); ?> (<?php echo esc_html(count($products_by_gateway)); ?>)
			</button>
			<?php
			$distinct_gateways = [];
			foreach ($products_by_gateway as $p) {
				$pm = (string) ($p['payment_method'] ?? 'standard');
				if (!isset($distinct_gateways[$pm])) {
					$distinct_gateways[$pm] = (string) ($p['gateway_name'] ?? $pm);
				}
			}
			foreach ($distinct_gateways as $pm_id => $pm_name) :
			?>
				<button type="button" class="finlyzer-gw-tab" data-gw-filter="<?php echo esc_attr($pm_id); ?>">
					<?php echo esc_html($pm_name); ?>
				</button>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="finlyzer-ledger finlyzer-products-table" role="table" aria-label="<?php esc_attr_e('Products purchased by gateway statement', 'finlyzer'); ?>">
		<div class="finlyzer-ledger__row finlyzer-ledger__row--head" role="row">
			<span role="columnheader"><?php esc_html_e('Product / SKU', 'finlyzer'); ?></span>
			<span role="columnheader"><?php esc_html_e('Payment Gateway', 'finlyzer'); ?></span>
			<span role="columnheader"><?php esc_html_e('Units Sold', 'finlyzer'); ?></span>
			<span role="columnheader"><?php esc_html_e('Foreign Revenue', 'finlyzer'); ?></span>
			<span role="columnheader"><?php esc_html_e('Share of Drag', 'finlyzer'); ?></span>
			<span role="columnheader" class="finlyzer-text-right"><?php esc_html_e('Attributed FX Loss', 'finlyzer'); ?></span>
		</div>

		<?php if (empty($products_by_gateway)) : ?>
			<div class="finlyzer-ledger__row finlyzer-ledger__empty" role="row">
				<span role="cell" colspan="6">
					<span class="finlyzer-empty-icon">&#x2714;</span>
					<?php esc_html_e('No product transactions recorded with cross-border payment gateways in this period.', 'finlyzer'); ?>
				</span>
			</div>
		<?php else : ?>
			<?php foreach ($products_by_gateway as $p) : ?>
				<?php
				$p_id       = (int) ($p['product_id'] ?? 0);
				$p_name     = (string) ($p['product_name'] ?? ('Product #' . $p_id));
				$p_gw       = (string) ($p['payment_method'] ?? 'standard');
				$p_gw_name  = (string) ($p['gateway_name'] ?? $p_gw);
				$p_color    = (string) ($p['badge_color'] ?? '#6366F1');
				$p_units    = (int) ($p['units_sold'] ?? 0);
				$p_rev      = (float) ($p['foreign_revenue'] ?? 0.0);
				$p_curr     = (string) ($p['order_currency'] ?? $store_currency);
				$p_loss     = (float) ($p['attributed_loss'] ?? 0.0);
				$p_share    = (float) ($p['loss_share_pct'] ?? 0.0);
				?>
				<div class="finlyzer-ledger__row finlyzer-product-row" role="row" data-gateway="<?php echo esc_attr($p_gw); ?>">
					<!-- Product Name & ID -->
					<span role="cell" class="finlyzer-product-cell">
						<strong class="finlyzer-product-name"><?php echo esc_html($p_name); ?></strong>
						<span class="finlyzer-product-id"><?php echo esc_html(sprintf(__('ID: #%d', 'finlyzer'), $p_id)); ?></span>
					</span>

					<!-- Gateway Pill with Dynamic Color -->
					<span role="cell" class="finlyzer-product-gw-cell">
						<span class="finlyzer-gw-pill" style="border-left-color: <?php echo esc_attr($p_color); ?>;">
							<span class="finlyzer-gw-dot" style="background-color: <?php echo esc_attr($p_color); ?>;"></span>
							<?php echo esc_html($p_gw_name); ?>
						</span>
					</span>

					<!-- Units Sold -->
					<span role="cell" class="finlyzer-units-cell">
						<?php echo esc_html(number_format($p_units)); ?>
					</span>

					<!-- Foreign Revenue -->
					<span role="cell" class="finlyzer-rev-cell">
						<?php echo wp_kses_post(wc_price($p_rev, ['currency' => $p_curr])); ?>
					</span>

					<!-- Share of Drag -->
					<span role="cell" class="finlyzer-share-cell">
						<div class="finlyzer-mini-bar">
							<div class="finlyzer-mini-bar-fill" style="width: <?php echo esc_attr((string) $p_share); ?>%; background: <?php echo esc_attr($p_color); ?>;"></div>
						</div>
						<span class="finlyzer-share-text"><?php echo esc_html(number_format($p_share, 1)); ?>%</span>
					</span>

					<!-- Attributed Loss -->
					<span role="cell" class="finlyzer-product-loss-cell finlyzer-text-right">
						<strong><?php echo wp_kses_post(wc_price($p_loss, ['currency' => $store_currency])); ?></strong>
					</span>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</div>

