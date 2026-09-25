<?php
/**
 * Receipts table e2e (2026-09-25): render the REAL Settings → CrawlerToll page
 * as an administrator with seeded unlock receipts, and assert what a publisher
 * sees per row — amount, a link to where the money is (Stripe payment or
 * on-chain tx), the EU/UK withdrawal-waiver line, and the Pro revoke button.
 * (A source-string test passed for weeks while these cells were never printed.)
 *
 * Usage: php test-receipts-view.php <wp_dir>
 */
$wp_dir = rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( '' === $wp_dir ) { fwrite( STDERR, "usage: php test-receipts-view.php <wp_dir>\n" ); exit( 2 ); }
$_SERVER['HTTP_HOST'] = '127.0.0.1';
define( 'WP_ADMIN', true );
require_once $wp_dir . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? "  \033[32mPASS\033[0m " : "  \033[31mFAIL\033[0m " ) . $m . "\n"; if ( ! $c ) { $fail++; } }

wp_set_current_user( 1 );
$reg_before = get_option( 'crawlertoll_registry', array() );
update_option( 'crawlertoll_registry', array_merge( (array) $reg_before, array( 'registered' => true ) ) );
$now  = time();
$pass = str_repeat( 'a1', 16 );
$rows = array(
	array( 'id' => 'stripe:pi_3Qe2eRcpt0001', 'content_id' => '127.0.0.1/post/999999', 'rail' => 'stripe', 'ref' => 'pi_3Qe2eRcpt0001', 'buyer' => null, 'amount_micros' => 4000000, 'currency' => 'USD', 'pass_id' => $pass, 'waiver_at' => $now - 120, 'created_at' => $now - 100 ),
	array( 'id' => 'x402:0xabc', 'content_id' => '127.0.0.1/post/999998', 'rail' => 'x402', 'ref' => '0x' . str_repeat( 'ab', 32 ), 'buyer' => '0x' . str_repeat( '12', 20 ), 'amount_micros' => 1500000, 'currency' => 'USDC', 'pass_id' => null, 'waiver_at' => null, 'created_at' => $now - 50 ),
	array( 'id' => 'meter:1', 'content_id' => '127.0.0.1/post/999997', 'rail' => 'meter', 'ref' => 'abc123', 'buyer' => null, 'amount_micros' => 0, 'currency' => null, 'pass_id' => null, 'waiver_at' => null, 'created_at' => $now - 10 ),
);
set_transient( 'crawlertoll_recent_unlocks', array( 'rows' => $rows, 'error' => null ), 300 );

ob_start();
( new CrawlerToll_Admin() )->render_page();
$html = ob_get_clean();

ck( false !== strpos( $html, 'id="crawlertoll-withdrawal-waiver"' ), 'settings page renders the EU/UK withdrawal setting' );
ck( false !== strpos( $html, '4.00 USD' ) && false !== strpos( $html, '1.50 USDC' ), 'receipt rows show the amount paid (card + USDC)' );
ck( false !== strpos( $html, 'href="https://dashboard.stripe.com/payments/pi_3Qe2eRcpt0001"' ), 'card receipt links to the payment on the publisher\'s Stripe' );
ck( false !== strpos( $html, 'href="https://basescan.org/tx/0x' . str_repeat( 'ab', 32 ) . '"' ), 'USDC receipt links to the on-chain transaction' );
ck( 1 === substr_count( $html, 'Withdrawal waived' ), 'withdrawal-waiver line shown only on the row that has one' );
$pro = class_exists( 'CrawlerToll_Pro_Admin' ) && CrawlerToll_Pro_Admin::is_pro_active();
ck( $pro ? 1 === substr_count( $html, 'data-pass="' . $pass . '"' ) : false === strpos( $html, 'ct-revoke-pass" data-pass' ), $pro ? 'Pro: one Revoke access button (the paid row with a pass)' : 'free: no revoke button' );
ck( false !== strpos( $html, 'Free read, email unlock or pass renewal' ), 'unpaid rows show a dash with an explanation, not 0.00' );
preg_match( '#<table class="widefat striped"[^>]*>.*?</table>#s', substr( $html, strpos( $html, 'foreach' ) ?: 0 ) ?: $html, $t );
$tbl = '';
if ( preg_match_all( '#<table class="widefat striped".*?</table>#s', $html, $all ) ) {
	foreach ( $all[0] as $cand ) { if ( false !== strpos( $cand, 'pi_3Qe2eRcpt0001' ) ) { $tbl = $cand; } }
}
$th = substr_count( $tbl, '<th>' );
preg_match( '#<tbody>\s*<tr>(.*?)</tr>#s', $tbl, $firstrow );
$td = isset( $firstrow[1] ) ? substr_count( $firstrow[1], '<td' ) : -1;
ck( $th > 0 && $th === $td, "every header has a cell ($th headers, $td cells in a row)" );

delete_transient( 'crawlertoll_recent_unlocks' );
update_option( 'crawlertoll_registry', $reg_before );
echo $fail ? "receipts-view e2e: FAILED ($fail)\n" : "receipts-view e2e: all passed\n";
exit( $fail ? 1 : 0 );
