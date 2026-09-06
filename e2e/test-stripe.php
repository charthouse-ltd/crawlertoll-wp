<?php
/**
 * e2e: publisher-owned Stripe card rail (spec 2026-09-06). Runs INSIDE the rig's
 * real WordPress for setup, then drives the PUBLIC REST endpoints over HTTP
 * exactly as the unlock app does. Stripe + the registry are stubbed by the
 * mu-plugin (e2e/mu-ct-stubs.php) so the intent → confirm → key path is
 * exercised end to end through real WP REST, real options, real post meta.
 *
 * Usage: php test-stripe.php <wp_dir> <base_url>   (exit 0 = all passed)
 */

$wp_dir = rtrim( (string) ( $argv[1] ?? '' ), '/' );
$base   = rtrim( (string) ( $argv[2] ?? '' ), '/' );
if ( $wp_dir === '' || $base === '' ) {
	fwrite( STDERR, "usage: php test-stripe.php <wp_dir> <base_url>\n" );
	exit( 2 );
}
$_SERVER['HTTP_HOST'] = '127.0.0.1';
require_once $wp_dir . '/wp-load.php';
require_once $wp_dir . '/wp-admin/includes/misc.php';

$fail = 0;
function ck( $c, $m ) {
	global $fail;
	echo ( $c ? "  \033[32mPASS\033[0m " : "  \033[31mFAIL\033[0m " ) . $m . "\n";
	if ( ! $c ) { $fail++; }
}
function post_json( $url, $data ) {
	$ch = curl_init( $url );
	curl_setopt_array( $ch, array(
		CURLOPT_POST           => true,
		CURLOPT_POSTFIELDS     => json_encode( $data ),
		CURLOPT_HTTPHEADER     => array( 'Content-Type: application/json' ),
		CURLOPT_RETURNTRANSFER => true,
	) );
	$body = curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	curl_close( $ch );
	return array( $code, json_decode( (string) $body, true ) );
}
function get_html( $url ) {
	$ch = curl_init( $url );
	curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => 'Mozilla/5.0 (e2e)' ) );
	$body = curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	curl_close( $ch );
	return array( $code, (string) $body );
}

if ( ! class_exists( 'CrawlerToll_Stripe' ) ) {
	fwrite( STDERR, "CrawlerToll_Stripe missing — plugin not active?\n" );
	exit( 1 );
}
ck( has_filter( 'pre_http_request' ), 'mu-plugin stubs loaded (pre_http_request hooked)' );

// Pretty permalinks so the post's path is /card-article/ (rule matching).
global $wp_rewrite;
update_option( 'permalink_structure', '/%postname%/' );
$wp_rewrite->set_permalink_structure( '/%postname%/' );
$wp_rewrite->flush_rules( true );

$intent_url  = rest_url( 'crawlertoll/v1/stripe/intent' );
$confirm_url = rest_url( 'crawlertoll/v1/stripe/confirm' );
// rest_url() carries the site host (127.0.0.1:PORT) — the same base php -S serves.

// ── 1. unconfigured site → 409 ───────────────────────────────────────
$s = crawlertoll_get_settings();
$s['stripe_publishable_key'] = '';
$s['stripe_secret_key']      = '';
update_option( 'crawlertoll_settings', $s );
delete_option( 'ct_e2e_intents' );
delete_option( 'ct_e2e_grants' );
delete_option( 'ct_e2e_stripe_status' );
list( $code, $out ) = post_json( $intent_url, array( 'content_id' => '127.0.0.1/post/1' ) );
ck( 409 === $code && isset( $out['code'] ) && 'card_not_configured' === $out['code'], "no keys → intent 409 card_not_configured (got $code)" );

// ── 2. configure keys + a tiered rule + a premium post ───────────────
$s = crawlertoll_get_settings();
$s['stripe_publishable_key'] = 'pk_test_e2e0123456789';
$s['stripe_secret_key']      = 'rk_test_e2e0123456789';
$s['currency']               = 'USD';
$s['path_pricing'][]         = array(
	'path'         => '/card-article/',
	'price_micros' => 50000,
	'currency'     => 'USD',
	'tiers'        => array(
		array( 'price_micros' => 3000000, 'duration_hours' => 24 ),   // t0 = $3.00 / 24h
		array( 'price_micros' => 9000000, 'duration_hours' => null ), // t1 = $9.00 / no expiry
	),
);
update_option( 'crawlertoll_settings', $s );
ck( CrawlerToll_Stripe::is_configured(), 'keys stored → is_configured()' );

$post_id = wp_insert_post( array(
	'post_title'   => 'Card article',
	'post_name'    => 'card-article',
	'post_status'  => 'publish',
	'post_type'    => 'post',
	'post_content' => "<p>Free preview paragraph one.</p>\n<p>Free preview paragraph two.</p>\n<!--more-->\n<p>Sealed body paragraph.</p>\n<p>More sealed text.</p>",
) );
ck( $post_id > 0, "premium post created (#$post_id)" );
update_post_meta( $post_id, CrawlerToll_Cut::META_KEY, 1 );
$content_id = CrawlerToll_Sealed_Gate::build_content_id( wp_parse_url( home_url(), PHP_URL_HOST ), $post_id );

// Anonymous view triggers seal + (stubbed) escrow registration.
list( $code, $html ) = get_html( get_permalink( $post_id ) );
ck( 200 === $code, "anonymous GET of the premium post → 200 (got $code)" );
ck( false !== strpos( $html, 'ct-sealed-body' ) || is_array( get_post_meta( $post_id, CrawlerToll_Premium_Gate::SEAL_META, true ) ), 'post sealed (seal meta present / marker rendered)' );
ck( false === strpos( $html, 'Sealed body paragraph' ), 'sealed body never served in cleartext' );

// ── 3. intent: tier pricing is server-derived ────────────────────────
list( $code, $out ) = post_json( $intent_url, array( 'content_id' => $content_id, 'tier_id' => 't0' ) );
ck( 200 === $code && ! empty( $out['client_secret'] ) && ! empty( $out['intent_id'] ), "intent t0 → 200 with client_secret + intent_id (got $code)" );
ck( isset( $out['amount'], $out['currency'] ) && 300 === (int) $out['amount'] && 'usd' === $out['currency'], 'intent t0 charges 300 cents usd ($3.00 tier, USD)' );
$intent_id = isset( $out['intent_id'] ) ? (string) $out['intent_id'] : '';
$intents   = get_option( 'ct_e2e_intents', array() );
ck( isset( $intents[ $intent_id ] ) && 'Bearer rk_test_e2e0123456789' === $intents[ $intent_id ]['auth'], 'intent created with the PUBLISHER\'s own key (Bearer rk_test_…)' );
ck( isset( $intents[ $intent_id ]['metadata']['content_id'] ) && $content_id === $intents[ $intent_id ]['metadata']['content_id'] && 't0' === $intents[ $intent_id ]['metadata']['tier_id'], 'intent metadata binds content_id + tier_id' );

list( $code, $out ) = post_json( $intent_url, array( 'content_id' => $content_id, 'tier_id' => 't9' ) );
ck( 400 === $code && isset( $out['code'] ) && 'unknown_tier' === $out['code'], "unknown tier → 400 unknown_tier (got $code)" );
list( $code, $out ) = post_json( $intent_url, array( 'content_id' => $content_id ) );
ck( 400 === $code && isset( $out['code'] ) && 'price_below_card_minimum' === $out['code'], "single crawler price (5000 micros) → 400 price_below_card_minimum (got $code)" );
list( $code, $out ) = post_json( $intent_url, array( 'content_id' => '127.0.0.1/post/999999', 'tier_id' => 't0' ) );
ck( 404 === $code, "unknown content → 404 (got $code)" );

// ── 4. confirm: verify on the publisher's account, grant at the registry ──
list( $code, $out ) = post_json( $confirm_url, array( 'content_id' => $content_id, 'intent_id' => $intent_id, 'tier_id' => 't0' ) );
ck( 200 === $code && isset( $out['cek'] ) && 'Q0VLLWUyZQ==' === $out['cek'], "confirm → 200 with cek (got $code)" );
ck( isset( $out['pass']['pass_id'] ) && isset( $out['capability']['magic'] ), 'confirm relays pass + capability from the registry' );
$grants = get_option( 'ct_e2e_grants', array() );
$g      = end( $grants );
ck( is_array( $g ) && 'stripe' === $g['rail'] && $intent_id === $g['receipt_id'] && 't0' === $g['tier_id'] && 'USD' === $g['currency'] && '127.0.0.1' === $g['publisher'], 'grant body: rail=stripe, receipt=intent id, tier t0, USD, publisher=site host' );
ck( is_array( $g ) && 0 === strpos( (string) $g['auth'], 'Bearer ' ) && $content_id === $g['content_id_in_url'], 'grant is bearer-authed and addressed to this content_id' );

// ── 5. replay + tamper + unsettled ───────────────────────────────────
list( $code, $out ) = post_json( $confirm_url, array( 'content_id' => $content_id, 'intent_id' => $intent_id, 'tier_id' => 't0' ) );
ck( 409 === $code && isset( $out['code'] ) && 'receipt_already_redeemed' === $out['code'], "replayed confirm → 409 receipt_already_redeemed (got $code)" );
list( $code, $out ) = post_json( $confirm_url, array( 'content_id' => $content_id, 'intent_id' => $intent_id, 'tier_id' => 't1' ) );
ck( 400 === $code && isset( $out['code'] ) && 'binding_mismatch' === $out['code'], "confirm with another tier for the same intent → 400 binding_mismatch (got $code)" );
list( $code, $out ) = post_json( $confirm_url, array( 'content_id' => $content_id, 'intent_id' => 'pi_doesnotexist', 'tier_id' => 't0' ) );
ck( 502 === $code || 400 === $code, "confirm with an unknown intent → rejected (got $code)" );

update_option( 'ct_e2e_stripe_status', 'requires_payment_method' );
list( $code, $out ) = post_json( $intent_url, array( 'content_id' => $content_id, 'tier_id' => 't1' ) );
$intent2 = isset( $out['intent_id'] ) ? (string) $out['intent_id'] : '';
list( $code, $out ) = post_json( $confirm_url, array( 'content_id' => $content_id, 'intent_id' => $intent2, 'tier_id' => 't1' ) );
ck( 402 === $code && isset( $out['code'] ) && 'unsettled' === $out['code'], "confirm before the card settled → 402 unsettled, no key (got $code)" );
delete_option( 'ct_e2e_stripe_status' );
$grants_after = get_option( 'ct_e2e_grants', array() );
ck( count( $grants_after ) === count( $grants ), 'no grant was requested for an unsettled intent' );

echo "\nstripe e2e: " . ( $fail ? "$fail failed" : 'all passed' ) . "\n";
exit( $fail ? 1 : 0 );
