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

		$events_table = $wpdb->prefix . 'fxli_fx_events';
		$products_table = $wpdb->prefix . 'fxli_product_gateway_events';
		$charset_collate = $wpdb->get_charset_collate();

		// define events schema with payment_method and covering indexes formatted strictly for dbDelta()
		$sql_events = "CREATE TABLE {$events_table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			order_id bigint(20) unsigned NOT NULL,
			order_date datetime NOT NULL,
			order_currency char(3) NOT NULL,
			store_currency char(3) NOT NULL,
			order_total_minor bigint(20) NOT NULL,
			estimated_loss_minor bigint(20) NOT NULL,
			payment_method varchar(64) NOT NULL DEFAULT 'standard',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY order_date (order_date),
			KEY order_date_currency (order_date, order_currency),
			KEY order_payment (payment_method)
		) {$charset_collate};";

		// define product-level gateway attribution table formatted strictly for dbDelta()
		$sql_products = "CREATE TABLE {$products_table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			order_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			product_name varchar(255) NOT NULL,
			quantity int(10) unsigned NOT NULL DEFAULT 1,
			line_total_minor bigint(20) NOT NULL,
			attributed_loss_minor bigint(20) NOT NULL,
			order_currency char(3) NOT NULL,
			payment_method varchar(64) NOT NULL DEFAULT 'standard',
			order_date datetime NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY product_id (product_id),
			KEY payment_method (payment_method),
			KEY order_date (order_date),
			KEY date_gateway_product (order_date, payment_method, product_id)
		) {$charset_collate};";

		// execute migration through WordPress upgrade core
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql_events);
		dbDelta($sql_products);

		// track database schema version
		update_option('fxli_db_version', defined('FINLYZER_DB_VERSION') ? FINLYZER_DB_VERSION : '3');

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
