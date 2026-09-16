<?php
/**
 * Post-swap checks for run-upgrade.sh (real 0.1.1 → current tree, no activation).
 * Usage: php check-upgrade.php <wp_dir> <expected_price_micros>
 */
$wp_dir = rtrim( (string) ( $argv[1] ?? '' ), '/' );
$price  = (int) ( $argv[2] ?? 0 );
$_SERVER['HTTP_HOST'] = '127.0.0.1';
require_once $wp_dir . '/wp-load.php';
$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? "  \033[32mPASS\033[0m " : "  \033[31mFAIL\033[0m " ) . $m . "\n"; if ( ! $c ) { $fail++; } }
global $wpdb;
wp_cache_flush();
ck( defined( 'CRAWLERTOLL_VERSION' ) && class_exists( 'CrawlerToll_Upgrader' ), 'current plugin loaded (' . CRAWLERTOLL_VERSION . ')' );
ck( CRAWLERTOLL_VERSION === get_option( CrawlerToll_Upgrader::OPTION ), 'schema marker set by the first request after the swap' );
$s = get_option( 'crawlertoll_settings' );
ck( is_array( $s ) && $price === (int) $s['price_micros'], "0.1.1 setting preserved (price_micros=$price)" );
ck( 'x402' === crawlertoll_get_settings()['rail'], '0.1.1 rail still x402 after merge with 2.0 defaults' );
ck( isset( crawlertoll_get_settings()['stripe_publishable_key'] ), 'new 2.0 keys filled from defaults' );
$wpdb->flush(); $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}crawlertoll_log`" );
ck( '' === $wpdb->last_error, 'Pro log table created without the activation hook' );
ck( false === get_option( 'crawlertoll_installed_version' ), 'no <2.0 marker → the "rebuilt, reconfigure" admin notice will show once' );
$err = CrawlerToll_Guard::entries();
ck( empty( $err ), 'error log empty after the upgrade' . ( $err ? ' — ' . wp_json_encode( array_slice( $err, 0, 3 ) ) : '' ) );
echo $fail ? "upgrade checks: FAILED ($fail)\n" : "upgrade checks: all passed\n";
exit( $fail ? 1 : 0 );
