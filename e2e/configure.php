<?php
/**
 * Write the CrawlerToll settings fixture for the e2e rig. Boots WP and stores a
 * known crawlertoll_settings: enforcement on, flat price 5000, plus per-path
 * overrides the assertions check. Extend this as new features get fixtures.
 *
 * Usage: php configure.php <wp_dir>
 */

$wp_dir= rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( $wp_dir === '' ) {
	fwrite( STDERR, "usage: php configure.php <wp_dir>\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST'] = '127.0.0.1';
require_once $wp_dir . '/wp-load.php';

if ( ! function_exists( 'crawlertoll_get_settings' ) ) {
	fwrite( STDERR, "crawlertoll_get_settings() missing — plugin not active\n" );
	exit( 1 );
}

$s                 = crawlertoll_get_settings();
$s['enabled']      = true;
$s['price_micros'] = 5000;
$s['currency']     = 'USD';
$s['path_pricing'] = array(
	array( 'path' => '/premium/*', 'price_micros' => 50000, 'currency' => 'USD' ),
	array( 'path' => '/blog/', 'price_micros' => 2000, 'currency' => 'USD' ),
);
update_option( 'crawlertoll_settings', $s );

$pro = class_exists( 'CrawlerToll_Pro_Admin' ) && CrawlerToll_Pro_Admin::is_pro_active() ? 'on' : 'off';
echo "configured (pro=$pro)\n";
