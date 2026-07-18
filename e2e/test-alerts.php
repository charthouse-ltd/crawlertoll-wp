<?php
/**
 * e2e: email alerts (§2.3). Runs INSIDE the rig's real WordPress (real DB, plugin
 * active, Pro on). Seeds yesterday's log rows, enables the daily alert, triggers
 * the cron action, and asserts wp_mail fired with the expected summary.
 *
 * Usage: php test-alerts.php <wp_dir>   (exit 0 = all passed)
 */

$wp_dir = rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( $wp_dir === '' ) {
	fwrite( STDERR, "usage: php test-alerts.php <wp_dir>\n" );
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

if ( ! class_exists( 'CrawlerToll_DB' ) || ! class_exists( 'CrawlerToll_Alerts' ) ) {
	fwrite( STDERR, "plugin classes missing — not active?\n" );
	exit( 1 );
}

global $wpdb;
$db = new CrawlerToll_DB( $wpdb );
$db->maybe_create_table();

// Seed 3 charged crawls dated yesterday.
$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
for ( $i = 0; $i < 3; $i++ ) {
	$db->insert( array(
		'request_time'   => $yesterday . sprintf( ' 12:0%d:00', $i ),
		'bot_name'       => 'GPTBot',
		'bot_operator'   => 'OpenAI',
		'bot_category'   => 'training',
		'request_path'   => '/premium/x' . $i . '/',
		'request_method' => 'GET',
		'action'         => '402',
		'price_micros'   => 5000,
		'currency'       => 'USD',
		'rail'           => 'x402',
		'http_status'    => 402,
		'user_agent'     => 'GPTBot/1.2',
	) );
}

// Sanity: the DB layer aggregates correctly under SQLite.
$stats = $db->stats( $yesterday, $yesterday );
$crawls = isset( $stats['totals']['total_crawls'] ) ? (int) $stats['totals']['total_crawls'] : 0;
ck( $crawls === 3, "stats() counts the 3 seeded crawls (got $crawls)" );

// Enable the daily alert; blank recipient → falls back to admin email.
$s                 = crawlertoll_get_settings();
$s['alerts_daily'] = true;
$s['alert_email']  = '';
update_option( 'crawlertoll_settings', $s );

// Capture wp_mail without actually sending.
$captured = array();
add_filter( 'pre_wp_mail', function ( $short, $atts ) use ( &$captured ) {
	$captured[] = $atts;
	return true;
}, 10, 2 );

// The cron hook is attached by CrawlerToll_Alerts::register() during bootstrap
// (Pro active via CRAWLERTOLL_PRO_DEV).
ck( has_action( 'crawlertoll_daily_alert' ) !== false, 'daily-alert cron hook registered by Pro bootstrap' );

do_action( 'crawlertoll_daily_alert' );

ck( count( $captured ) === 1, 'exactly one alert email sent' );
if ( ! empty( $captured ) ) {
	$mail = $captured[0];
	ck( isset( $mail['subject'] ) && strpos( $mail['subject'], 'Daily' ) !== false, 'subject is the daily summary' );
	ck( isset( $mail['subject'] ) && strpos( $mail['subject'], '3' ) !== false, 'subject reports 3 crawls' );
	ck( isset( $mail['to'] ) && $mail['to'] === get_bloginfo( 'admin_email' ), 'sent to admin email (blank-recipient fallback)' );
	ck( isset( $mail['message'] ) && strpos( $mail['message'], 'Total AI crawls: 3' ) !== false, 'body reports the crawl total' );
}

// Negative: disabling the daily alert sends nothing.
$captured        = array();
$s               = crawlertoll_get_settings();
$s['alerts_daily'] = false;
update_option( 'crawlertoll_settings', $s );
do_action( 'crawlertoll_daily_alert' );
ck( count( $captured ) === 0, 'disabled daily alert sends nothing' );

echo ( $fail === 0 ? "alerts-e2e: all passed\n" : "alerts-e2e: $fail failed\n" );
exit( $fail === 0 ? 0 : 1 );
