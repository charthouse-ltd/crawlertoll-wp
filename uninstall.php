<?php
/**
 * Uninstall — runs when the plugin is DELETED from wp-admin (never on
 * deactivate). Removes everything CrawlerToll wrote to this site, but only
 * when the publisher ticked "Remove all data on uninstall" (default off), so
 * a delete-by-mistake keeps pricing, sealed posts, readers and receipts.
 *
 * Keep this file complete: tests/upgrade-wired.php scans the plugin source for
 * every option, transient, cron hook and post-meta key it writes and fails
 * when one is not listed here.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$crawlertoll_settings = get_option( 'crawlertoll_settings' );

if ( empty( $crawlertoll_settings['remove_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

// Tables (Pro): crawl log + email-gate subscribers.
foreach ( array( 'crawlertoll_log', 'crawlertoll_subscribers' ) as $crawlertoll_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . $crawlertoll_table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- table names are plugin-controlled; DROP TABLE cannot be parameterised.
}

// Options — settings (includes the publisher's own Stripe keys), enrollment
// token, catalogue, webhook, traffic counters, error log, version markers.
$crawlertoll_options = array(
	'crawlertoll_settings',
	'crawlertoll_installed_version',
	'crawlertoll_schema_version',
	'crawlertoll_new_bots_found',
	'crawlertoll_new_bots_list',
	'crawlertoll_registry_key',
	'crawlertoll_registry',
	'crawlertoll_webhook',
	'crawlertoll_bot_catalogue',
	'crawlertoll_error_log',
	'crawlertoll_error_badge',
	'crawlertoll_traffic',
	'crawlertoll_traffic_ua',
	'crawlertoll_subs_dbv',
);
foreach ( $crawlertoll_options as $crawlertoll_option ) {
	delete_option( $crawlertoll_option );
}

foreach ( array( 'crawlertoll_catalogue_last_check', 'crawlertoll_registry_health', 'crawlertoll_recent_unlocks' ) as $crawlertoll_transient ) {
	delete_transient( $crawlertoll_transient );
}

// Per-post data: premium flag, cut position, wall copy, sealed-body cache.
// (post_content itself is never touched by sealing.)
foreach ( array( '_crawlertoll_premium', '_crawlertoll_cut', '_crawlertoll_wall_text', '_crawlertoll_sealed_v1' ) as $crawlertoll_meta ) {
	delete_post_meta_by_key( $crawlertoll_meta );
}

$crawlertoll_hooks = array(
	'crawlertoll_registry_sync',
	'crawlertoll_registry_hash_feed',
	'crawlertoll_catalogue_update',
	'crawlertoll_daily_alert',
	'crawlertoll_weekly_alert',
	'crawlertoll_spike_check',
	'crawlertoll_purge_logs',
);
foreach ( $crawlertoll_hooks as $crawlertoll_hook ) {
	wp_clear_scheduled_hook( $crawlertoll_hook );
}
