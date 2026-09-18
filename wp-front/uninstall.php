<?php
declare(strict_types=1);

// block direct invocation outside WordPress uninstallation lifecycle
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

// remove Finlyzer events table
$table = $wpdb->prefix . 'fxli_fx_events';
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table identifier only
$wpdb->query("DROP TABLE IF EXISTS {$table}");

// delete version options
delete_option('finlyzer_db_version');
delete_option('fxli_db_version');

// unschedule daily scanner cron
wp_clear_scheduled_hook('fxli_daily_scan');

// purge all Finlyzer cached transients
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\\_transient\\_finlyzer\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_finlyzer\\_%'
	    OR option_name LIKE '\\_transient\\_fxli\\_%'
	    OR option_name LIKE '\\_transient\\_timeout\\_fxli\\_%'"
);
