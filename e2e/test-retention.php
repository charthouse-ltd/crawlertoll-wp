<?php
/**
 * e2e: log retention (§2.2). Runs inside the rig's real WordPress. Seeds an old
 * and a recent row, runs the purge cron, and asserts the old row is deleted, the
 * recent one survives, and that retention_days = 0 means "keep forever".
 *
 * Usage: php test-retention.php <wp_dir>   (exit 0 = all passed)
 */

$wp_dir = rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( $wp_dir === '' ) {
	fwrite( STDERR, "usage: php test-retention.php <wp_dir>\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST'] = '127.0.0.1';
require_once $wp_dir . '/wp-load.php';

$fail = 0;
function ck( $c, $m ) {
	global $fail;
	echo ( $c ? "  \033[32mPASS\033[0m " : "  \033[31mFAIL\033[0m " ) . $m . "\n";
	if ( ! $c ) { $fail++; }
}

if ( ! class_exists( 'CrawlerToll_DB' ) ) {
	fwrite( STDERR, "plugin not active\n" );
	exit( 1 );
}

global $wpdb;
$db    = new CrawlerToll_DB( $wpdb );
$db->maybe_create_table();
$table = $db->get_table_name();
$wpdb->query( "DELETE FROM {$table}" ); // clean slate

$seed = function ( $when ) use ( $db ) {
	$db->insert( array(
		'request_time' => $when,
		'bot_name'     => 'GPTBot',
		'action'       => '402',
		'price_micros' => 5000,
		'currency'     => 'USD',
		'rail'         => 'x402',
		'http_status'  => 402,
		'request_path' => '/x/',
		'user_agent'   => 'GPTBot/1.2',
	) );
};
$count = function () use ( $wpdb, $table ) {
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
};

$old    = gmdate( 'Y-m-d H:i:s', strtotime( '-100 days' ) );
$recent = gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) );
$seed( $old );
$seed( $recent );
ck( $count() === 2, 'seeded 2 rows (one 100 days old, one 1 day old)' );

// Retention 90 → the cron purges the old row, keeps the recent one.
$s                  = crawlertoll_get_settings();
$s['retention_days'] = 90;
update_option( 'crawlertoll_settings', $s );
ck( has_action( 'crawlertoll_purge_logs' ) !== false, 'purge cron hook registered by Pro bootstrap' );
do_action( 'crawlertoll_purge_logs' );
ck( $count() === 1, 'purge deleted the 100-day-old row' );
$left = (string) $wpdb->get_var( "SELECT request_time FROM {$table} LIMIT 1" );
ck( strpos( $left, substr( $recent, 0, 10 ) ) === 0, 'the recent row survived' );

// Retention 0 = keep forever → a fresh old row is NOT purged.
$seed( $old );
$s                  = crawlertoll_get_settings();
$s['retention_days'] = 0;
update_option( 'crawlertoll_settings', $s );
do_action( 'crawlertoll_purge_logs' );
ck( $count() === 2, 'retention 0 keeps everything (no purge)' );

echo ( $fail === 0 ? "retention-e2e: all passed\n" : "retention-e2e: $fail failed\n" );
exit( $fail === 0 ? 0 : 1 );
