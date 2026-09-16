<?php
/**
 * Upgrade-path e2e (2026-09-16): a WordPress auto-update swaps the plugin
 * files WITHOUT firing the activation hook. Simulate exactly that state on the
 * running rig — no schema marker, Pro log table gone, stale rewrite rules —
 * then make ONE ordinary HTTP request and assert the upgrader repaired
 * everything on that request, with settings untouched and no error recorded.
 *
 * Usage: php test-upgrade.php <wp_dir> <base_url>
 */
$wp_dir = rtrim( (string) ( $argv[1] ?? '' ), '/' );
$base   = rtrim( (string) ( $argv[2] ?? '' ), '/' );
if ( '' === $wp_dir || '' === $base ) { fwrite( STDERR, "usage: php test-upgrade.php <wp_dir> <base_url>\n" ); exit( 2 ); }
$_SERVER['HTTP_HOST'] = (string) parse_url( $base, PHP_URL_HOST ) . ':' . (int) parse_url( $base, PHP_URL_PORT );
require_once $wp_dir . '/wp-load.php';

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? "  \033[32mPASS\033[0m " : "  \033[31mFAIL\033[0m " ) . $m . "\n"; if ( ! $c ) { $fail++; } }
global $wpdb;
$table = $wpdb->prefix . 'crawlertoll_log';

// Remember what the publisher had configured.
$before = get_option( 'crawlertoll_settings' );
$before['price_micros'] = 7777;
update_option( 'crawlertoll_settings', $before );
CrawlerToll_Guard::clear();

// Simulate "files replaced by an update": marker gone, table gone, stale rules.
delete_option( CrawlerToll_Upgrader::OPTION );
$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
update_option( 'rewrite_rules', array( '^stale-only$' => 'index.php?p=1' ) );
$wpdb->flush();
ck( null === $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ), 'precondition: log table really is gone' );

// One ordinary front-end request — the route that registry enrollment needs.
$res  = wp_remote_get( $base . '/.well-known/context-license.json', array( 'timeout' => 15, 'headers' => array( 'User-Agent' => 'Mozilla/5.0 (upgrade-e2e)' ) ) );
$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
ck( 200 === $code, "first request after the update serves /.well-known/context-license.json → 200 (got $code)" );

wp_cache_flush();
ck( CRAWLERTOLL_VERSION === get_option( CrawlerToll_Upgrader::OPTION ), 'schema marker set to ' . CRAWLERTOLL_VERSION );
$wpdb->flush();
$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
ck( null !== $count && '' === $wpdb->last_error, 'Pro log table recreated by dbDelta' );
$after = get_option( 'crawlertoll_settings' );
ck( isset( $after['price_micros'] ) && 7777 === (int) $after['price_micros'], 'publisher settings preserved across the upgrade' );
$bad = array_filter( CrawlerToll_Guard::entries(), function ( $e ) { return 'upgrade' === $e['label']; } );
ck( empty( $bad ), 'no error recorded under "upgrade"' );
$c2 = (int) wp_remote_retrieve_response_code( wp_remote_get( $base . '/', array( 'timeout' => 15, 'headers' => array( 'User-Agent' => 'Mozilla/5.0' ) ) ) );
ck( 200 === $c2, "site still serves the front page → 200 (got $c2)" );

echo $fail ? "upgrade e2e: FAILED ($fail)\n" : "upgrade e2e: all passed\n";
exit( $fail ? 1 : 0 );
