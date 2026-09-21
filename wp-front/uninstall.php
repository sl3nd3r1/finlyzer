<?php
declare(strict_types=1);

// block direct invocation outside WordPress uninstallation lifecycle
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

// remove Finlyzer events tables
$events_table = $wpdb->prefix . 'fxli_fx_events';
$products_table = $wpdb->prefix . 'fxli_product_gateway_events';
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table identifier only
$wpdb->query("DROP TABLE IF EXISTS {$events_table}");
$wpdb->query("DROP TABLE IF EXISTS {$products_table}");

// delete all Finlyzer and FXLI options and state settings
delete_option('finlyzer_db_version');
delete_option('fxli_db_version');
delete_option('finlyzer_order_state_version');
delete_option('finlyzer_telemetry_logs');

// unschedule daily scanner cron
wp_clear_scheduled_hook('fxli_daily_scan');

// purge all Finlyzer database options and cached transients
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE 'finlyzer\\_%'
	    OR option_name LIKE 'fxli\\_%'
	    OR option_name LIKE '\\_transient\\_finlyzer\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_finlyzer\\_%'
	    OR option_name LIKE '\\_transient\\_fxli\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_fxli\\_%'"
);

