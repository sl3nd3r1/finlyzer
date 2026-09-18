<?php
declare(strict_types=1);

// prevent direct access
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Handles database table installation and cron event lifecycle for Finlyzer.
 */
final class FXLI_Installer {

	// installs or upgrades custom database tables and registers background cron
	public static function activate(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'fxli_fx_events';
		$charset_collate = $wpdb->get_charset_collate();

		// define schema with covering index for fast date-range grouping
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			order_date DATETIME NOT NULL,
			order_currency CHAR(3) NOT NULL,
			store_currency CHAR(3) NOT NULL,
			order_total_minor BIGINT NOT NULL,
			estimated_loss_minor BIGINT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY order_id (order_id),
			KEY order_date (order_date),
			KEY order_date_currency (order_date, order_currency)
		) {$charset_collate};";

		// execute migration through WordPress upgrade core
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);

		// track database schema version
		update_option('fxli_db_version', defined('FINLYZER_DB_VERSION') ? FINLYZER_DB_VERSION : '2');

		// schedule daily order analysis cron if not already registered
		if (!wp_next_scheduled('fxli_daily_scan')) {
			wp_schedule_event(time(), 'daily', 'fxli_daily_scan');
		}
	}

	// unschedules cron jobs upon plugin deactivation
	public static function deactivate(): void {
		// retrieve existing cron timestamp
		$timestamp = wp_next_scheduled('fxli_daily_scan');
		if ($timestamp) {
			// unschedule scheduled recurring scan
			wp_unschedule_event($timestamp, 'fxli_daily_scan');
		}
		// data table is preserved on deactivation per WordPress plugin guidelines
	}
}
