<?php
/**
 * Uninstall e2e (2026-09-16): with "Remove all data on uninstall" ticked, WP's
 * own uninstall_plugin() must leave nothing behind — options (including the
 * publisher's Stripe keys), both tables, per-post meta, transients, cron.
 * DESTRUCTIVE for the rig: run last.
 *
 * Usage: php test-uninstall.php <wp_dir>
 */
$wp_dir = rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( '' === $wp_dir ) { fwrite( STDERR, "usage: php test-uninstall.php <wp_dir>\n" ); exit( 2 ); }
$_SERVER['HTTP_HOST'] = '127.0.0.1';
require_once $wp_dir . '/wp-load.php';
require_once $wp_dir . '/wp-admin/includes/plugin.php';

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? "  \033[32mPASS\033[0m " : "  \033[31mFAIL\033[0m " ) . $m . "\n"; if ( ! $c ) { $fail++; } }
global $wpdb;
$wpdb->suppress_errors( true ); // table-existence probes below are expected to error after the drop

// Seed every kind of data the plugin writes.
$s = get_option( 'crawlertoll_settings', array() );
$s['remove_data_on_uninstall'] = true;
$s['stripe_secret_key']        = 'sk_test_uninstall_me';
update_option( 'crawlertoll_settings', $s );
update_option( 'crawlertoll_traffic', array( gmdate( 'Y-m-d' ) => array( 'browser' => 1 ) ), false );
update_option( 'crawlertoll_error_log', array( 'x' => array( 'label' => 't', 'type' => 't', 'message' => 'm', 'file' => 'f', 'line' => 1, 'count' => 1, 'first_seen' => 1, 'last_seen' => 1 ) ), false );
update_option( 'crawlertoll_registry', array( 'token' => 'tok' ) );
set_transient( 'crawlertoll_registry_health', 'http 200', 300 );
if ( ! wp_next_scheduled( 'crawlertoll_purge_logs' ) ) { wp_schedule_event( time() + 3600, 'daily', 'crawlertoll_purge_logs' ); }
$post_id = wp_insert_post( array( 'post_title' => 'uninstall-seed', 'post_content' => 'x', 'post_status' => 'publish' ) );
// Seed at the DB level: the plugin filters *_post_metadata for its own keys.
$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $post_id, 'meta_key' => '_crawlertoll_premium', 'meta_value' => '1' ) );
$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $post_id, 'meta_key' => '_crawlertoll_sealed_v1', 'meta_value' => serialize( array( 'ct' => 'cache' ) ) ) );
$seeded = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $post_id, '%crawlertoll%' ) );
ck( 2 === $seeded, 'precondition: 2 plugin meta rows seeded' );
if ( class_exists( 'CrawlerToll_Subscribers' ) ) { CrawlerToll_Subscribers::maybe_create_table(); }
$tables = array( $wpdb->prefix . 'crawlertoll_log', $wpdb->prefix . 'crawlertoll_subscribers' );
foreach ( $tables as $t ) { $wpdb->flush(); $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" ); ck( '' === $wpdb->last_error, "precondition: table exists $t" ); }

$res = uninstall_plugin( 'crawlertoll/crawlertoll.php' );
ck( true === $res, 'uninstall_plugin() ran uninstall.php' );
wp_cache_flush();

$options = array( 'crawlertoll_settings', 'crawlertoll_installed_version', 'crawlertoll_schema_version', 'crawlertoll_registry', 'crawlertoll_registry_key', 'crawlertoll_traffic', 'crawlertoll_traffic_ua', 'crawlertoll_error_log', 'crawlertoll_error_badge', 'crawlertoll_bot_catalogue', 'crawlertoll_webhook', 'crawlertoll_subs_dbv', 'crawlertoll_new_bots_found', 'crawlertoll_new_bots_list' );
$left = array();
foreach ( $options as $o ) { if ( false !== get_option( $o, false ) ) { $left[] = $o; } }
ck( empty( $left ), 'every option removed' . ( $left ? ' — left: ' . implode( ', ', $left ) : '' ) );
$row = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", 'crawlertoll%' ) );
ck( 0 === (int) $row, "no option row starting with crawlertoll remains (found $row)" );
ck( false === get_transient( 'crawlertoll_registry_health' ), 'transient removed' );
foreach ( $tables as $t ) { $wpdb->flush(); $v = $wpdb->get_var( "SELECT COUNT(*) FROM `{$t}`" ); ck( null === $v || '' !== $wpdb->last_error, "table dropped $t" ); }
$meta_left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", '%crawlertoll%' ) );
ck( 0 === $meta_left && null !== get_post( $post_id ), "per-post meta removed, post itself kept (left $meta_left)" );
ck( false === wp_next_scheduled( 'crawlertoll_purge_logs' ), 'cron hook unscheduled' );
$secret = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%sk_test_uninstall_me%' ) );
ck( 0 === (int) $secret, 'the publisher Stripe secret is gone from the database' );

echo $fail ? "uninstall e2e: FAILED ($fail)\n" : "uninstall e2e: all passed\n";
exit( $fail ? 1 : 0 );
