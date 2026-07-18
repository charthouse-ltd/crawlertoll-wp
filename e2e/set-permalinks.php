<?php
/**
 * Switch the e2e WP to pretty permalinks and flush rewrite rules, so /robots.txt
 * and the plugin's /.well-known/context-license.json rewrite resolve (with plain
 * permalinks WP canonical-redirects them with a 301). Mirrors a real site.
 *
 * Usage: php set-permalinks.php <wp_dir>
 */
$wp_dir = rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( $wp_dir === '' ) {
	fwrite( STDERR, "usage: php set-permalinks.php <wp_dir>\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST'] = '127.0.0.1';
require_once $wp_dir . '/wp-load.php';
require_once $wp_dir . '/wp-admin/includes/misc.php';

global $wp_rewrite;
update_option( 'permalink_structure', '/%postname%/' );
$wp_rewrite->set_permalink_structure( '/%postname%/' );
$wp_rewrite->flush_rules( true );
echo "permalinks set + flushed\n";
