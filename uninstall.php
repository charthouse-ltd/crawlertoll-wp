<?php
/**
 * Uninstall handler — runs when the user deletes CrawlerToll from the
 * Plugins screen. WordPress executes this file automatically on delete;
 * it never runs on deactivation.
 *
 * Data is removed ONLY if the site owner opted in via
 * Settings → CrawlerToll → Advanced ("Remove all data on uninstall").
 * Otherwise everything is preserved so a reinstall keeps history.
 *
 * Self-contained: does not load the plugin's classes, per the WordPress
 * best practice for uninstall scripts.
 *
 * @package CrawlerToll
 */

// Exit if not called by WordPress during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$crawlertoll_settings = get_option( 'crawlertoll_settings' );

// Respect the opt-in. Default (off) preserves all data.
if ( empty( $crawlertoll_settings['remove_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// 1. Drop the custom log table.
$crawlertoll_table = $wpdb->prefix . 'crawlertoll_log';
$wpdb->query( "DROP TABLE IF EXISTS {$crawlertoll_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is plugin-controlled, not user input; DROP TABLE cannot be parameterised.

// 2. Delete plugin options.
$crawlertoll_options = array(
	'crawlertoll_settings',
	'crawlertoll_new_bots_found',
	'crawlertoll_new_bots_list',
	'crawlertoll_registry_key',
);
foreach ( $crawlertoll_options as $crawlertoll_option ) {
	delete_option( $crawlertoll_option );
}

// 3. Delete transients.
delete_transient( 'crawlertoll_catalogue_last_check' );

// 4. Clear scheduled cron events.
$crawlertoll_hooks = array(
	'crawlertoll_registry_sync',
	'crawlertoll_registry_hash_feed',
	'crawlertoll_catalogue_update',
	'crawlertoll_daily_alert',
	'crawlertoll_weekly_alert',
	'crawlertoll_spike_check',
);
foreach ( $crawlertoll_hooks as $crawlertoll_hook ) {
	wp_clear_scheduled_hook( $crawlertoll_hook );
}
