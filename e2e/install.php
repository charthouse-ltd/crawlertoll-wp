<?php
/**
 * Headless WordPress install for the CrawlerToll e2e rig.
 *
 * wp-cli's `core install` fatals on PHP 8.5, so we drive wp_install() directly.
 * The SQLite drop-in (wp-content/db.php) is auto-loaded by wp-load, so no MySQL
 * is needed.
 *
 * Usage: php install.php <wp_dir> <port>
 */

$wp_dir  = rtrim( (string) ( $argv[1] ?? '' ), '/' );
$port = (int) ( $argv[2] ?? 0 );

if ( $wp_dir === '' || $port === 0 ) {
	fwrite( STDERR, "usage: php install.php <wp_dir> <port>\n" );
	exit( 2 );
}

define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST']      = "127.0.0.1:$port";
$_SERVER['SERVER_NAME']    = '127.0.0.1';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once $wp_dir . '/wp-load.php';
require_once $wp_dir . '/wp-admin/includes/upgrade.php';
require_once $wp_dir . '/wp-admin/includes/plugin.php';

if ( ! is_blog_installed() ) {
	$res = wp_install( 'CrawlerToll E2E', 'admin', 'admin@example.com', true, '', 'password' );
	if ( is_wp_error( $res ) ) {
		fwrite( STDERR, 'wp_install failed: ' . $res->get_error_message() . "\n" );
		exit( 1 );
	}
}

// Pin the URLs to where php -S actually serves, so admin/redirects don't break.
update_option( 'siteurl', "http://127.0.0.1:$port" );
update_option( 'home', "http://127.0.0.1:$port" );

$res = activate_plugin( 'crawlertoll/crawlertoll.php' );
if ( is_wp_error( $res ) ) {
	fwrite( STDERR, 'activate_plugin failed: ' . $res->get_error_message() . "\n" );
	exit( 1 );
}

echo "installed + crawlertoll activated\n";
