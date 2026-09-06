<?php
/**
 * Publisher-owned Stripe card rail — wiring + pure-logic guard (spec 2026-09-06).
 *
 * Card payments run on the PUBLISHER'S OWN Stripe account: keys live in this
 * site's settings (secret write-only), the origin creates + verifies the
 * PaymentIntent, and the registry releases the CEK on a publisher-attested
 * grant. CrawlerToll never holds a Stripe credential and never runs a Connect
 * platform. This guards (a) the pure binding/verification logic, (b) the
 * settings + REST + registry-client wiring, (c) the build (ships in both
 * artifacts, free-safe), and (d) the unlock app talking to the ORIGIN, not the
 * registry, for card payments.
 *
 * Run: php tests/stripe-wired.php   (exit 0 = pass, 1 = fail)
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data( $k = null ) { return null === $k ? $this->data : ( isset( $this->data[ $k ] ) ? $this->data[ $k ] : null ); }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }

$dir = dirname( __DIR__ );
require_once $dir . '/includes/class-crawlertoll-stripe.php';

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'ok'; }

// ── (a) pure logic ───────────────────────────────────────────────────
ck( CrawlerToll_Stripe::valid_publishable_key( 'pk_test_51Abc0123456789' ), 'pk_test_… is a valid publishable key' );
ck( ! CrawlerToll_Stripe::valid_publishable_key( 'sk_test_51Abc0123456789' ), 'a secret key is NOT accepted as publishable' );
ck( CrawlerToll_Stripe::valid_secret_key( 'rk_live_51Abc0123456789' ), 'rk_live_… (restricted) is a valid secret key' );
ck( CrawlerToll_Stripe::valid_secret_key( 'sk_test_51Abc0123456789' ), 'sk_test_… is a valid secret key' );
ck( ! CrawlerToll_Stripe::valid_secret_key( 'pk_live_51Abc0123456789' ), 'a publishable key is NOT accepted as secret' );
ck( 'live key ····6789' === CrawlerToll_Stripe::masked_secret( 'rk_live_51Abc0123456789' ), 'masked_secret shows mode + last 4 only' );
ck( '' === CrawlerToll_Stripe::masked_secret( '' ), 'masked_secret is empty when no key' );

ck( 'price_below_card_minimum' === code( CrawlerToll_Stripe::card_amount( 300000, 'USD' ) ), 'card_amount: $0.30 is below Stripe\'s floor → price_below_card_minimum' );
$a = CrawlerToll_Stripe::card_amount( 3000000, 'USDC' );
ck( is_array( $a ) && 300 === $a['cents'] && 'usd' === $a['currency'], 'card_amount: $3.00 USDC → 300 cents, usd (stablecoin label maps to fiat)' );
$a = CrawlerToll_Stripe::card_amount( 5000000, 'EUR' );
ck( is_array( $a ) && 500 === $a['cents'] && 'eur' === $a['currency'], 'card_amount: EUR passes through lowercase' );
ck( 'price_below_card_minimum' === code( CrawlerToll_Stripe::card_amount( 5000, 'USD' ) ), 'card_amount: the crawler price (5000 micros) is agent-only' );

$good = array( 'id' => 'pi_1', 'status' => 'succeeded', 'amount' => 300, 'currency' => 'usd', 'application_fee_amount' => null, 'metadata' => array( 'content_id' => 'site.example/post/7', 'tier_id' => 't0' ) );
$exp  = array( 'cents' => 300, 'currency' => 'usd', 'content_id' => 'site.example/post/7', 'tier_id' => 't0' );
ck( true === CrawlerToll_Stripe::verify_intent( $good, $exp ), 'verify_intent: succeeded + exact binding → true' );
ck( 'unsettled' === code( CrawlerToll_Stripe::verify_intent( array_merge( $good, array( 'status' => 'requires_payment_method' ) ), $exp ) ), 'verify_intent: non-succeeded → unsettled (402)' );
ck( 'binding_mismatch' === code( CrawlerToll_Stripe::verify_intent( array_merge( $good, array( 'amount' => 1 ) ), $exp ) ), 'verify_intent: a $0.01 charge must not unlock a $3 item' );
ck( 'binding_mismatch' === code( CrawlerToll_Stripe::verify_intent( array_merge( $good, array( 'currency' => 'eur' ) ), $exp ) ), 'verify_intent: currency mismatch rejected' );
$other = $good; $other['metadata']['content_id'] = 'site.example/post/8';
ck( 'binding_mismatch' === code( CrawlerToll_Stripe::verify_intent( $other, $exp ) ), 'verify_intent: intent substitution (other content) rejected' );
$otier = $good; $otier['metadata']['tier_id'] = 't1';
ck( 'binding_mismatch' === code( CrawlerToll_Stripe::verify_intent( $otier, $exp ) ), 'verify_intent: tier mismatch rejected' );
ck( 'unexpected_application_fee' === code( CrawlerToll_Stripe::verify_intent( array_merge( $good, array( 'application_fee_amount' => 5 ) ), $exp ) ), 'verify_intent: an application fee can never appear (we take no cut)' );
ck( 'missing_binding' === code( CrawlerToll_Stripe::verify_intent( $good, array( 'cents' => 300, 'currency' => 'usd' ) ) ), 'verify_intent: fails closed when the caller omits the content binding' );
$legacy = $good; $legacy['metadata'] = array( 'content_id' => 'site.example/post/7', 'tier_id' => '' );
ck( true === CrawlerToll_Stripe::verify_intent( $legacy, array_merge( $exp, array( 'tier_id' => '' ) ) ), 'verify_intent: legacy single price binds with an empty tier_id' );

// card_price_micros mirrors the registry's tier-id assignment (t<i>, b<i>, '' = single).
require_once $dir . '/includes/class-crawlertoll-plugin.php';
$tiers  = array( array( 'price_micros' => 3000000, 'duration_hours' => 24 ), array( 'price_micros' => 9000000, 'duration_hours' => null ) );
$bundle = array( 'path' => '/reviews/*', 'tiers' => array( array( 'price_micros' => 15000000, 'duration_hours' => 720 ) ) );
ck( 3000000 === CrawlerToll_Plugin::card_price_micros( $tiers, $bundle, 5000, 't0' ), 'card_price_micros: t0 → first article tier' );
ck( 9000000 === CrawlerToll_Plugin::card_price_micros( $tiers, $bundle, 5000, 't1' ), 'card_price_micros: t1 → second article tier' );
ck( 15000000 === CrawlerToll_Plugin::card_price_micros( $tiers, $bundle, 5000, 'b0' ), 'card_price_micros: b0 → bundle tier' );
ck( 5000 === CrawlerToll_Plugin::card_price_micros( $tiers, $bundle, 5000, '' ), 'card_price_micros: empty tier_id → single price' );
ck( null === CrawlerToll_Plugin::card_price_micros( $tiers, $bundle, 5000, 't5' ), 'card_price_micros: unknown tier → null (reject, never re-price)' );
ck( null === CrawlerToll_Plugin::card_price_micros( null, null, 5000, 't0' ), 'card_price_micros: tier named but no tiers configured → null' );

// ── (b) wiring ──────────────────────────────────────────────────────
$main     = (string) file_get_contents( $dir . '/crawlertoll.php' );
$plugin   = (string) file_get_contents( $dir . '/includes/class-crawlertoll-plugin.php' );
$registry = (string) file_get_contents( $dir . '/includes/class-crawlertoll-registry.php' );
$admin    = (string) file_get_contents( $dir . '/admin/class-crawlertoll-admin.php' );
$view     = (string) file_get_contents( $dir . '/admin/views/settings.php' );
$buildsh  = (string) file_get_contents( $dir . '/build.sh' );
$uiApi    = (string) file_get_contents( $dir . '/ui/src/unlock/api.ts' );
$uiOffer  = (string) file_get_contents( $dir . '/ui/src/unlock/offer.ts' );
$uiPay    = (string) file_get_contents( $dir . '/ui/src/unlock/payments.ts' );

ck( false !== strpos( $main, "includes/class-crawlertoll-stripe.php" ), 'crawlertoll.php loads the Stripe class' );
ck( false !== strpos( $main, "'stripe_publishable_key' => ''" ) && false !== strpos( $main, "'stripe_secret_key'    => ''" ), 'defaults carry both Stripe keys' );
ck( false !== strpos( $admin, 'CrawlerToll_Stripe::valid_publishable_key' ) && false !== strpos( $admin, 'CrawlerToll_Stripe::valid_secret_key' ), 'sanitize shape-checks both keys' );
ck( false !== strpos( $admin, 'stripe_secret_key_clear' ), 'sanitize supports clearing the stored secret' );
ck( false !== strpos( $view, '[stripe_publishable_key]' ) && false !== strpos( $view, '[stripe_secret_key]' ), 'settings view renders both key fields' );
ck( false !== strpos( $view, 'type="password"' ) && false !== strpos( $view, 'value=""' ) && false !== strpos( $view, 'masked_secret' ), 'secret field is write-only (password input, empty value, masked placeholder)' );
ck( false !== strpos( $plugin, "'/stripe/intent'" ) && false !== strpos( $plugin, "'/stripe/confirm'" ), 'REST routes /stripe/intent + /stripe/confirm registered' );
ck( false !== strpos( $plugin, 'function rest_stripe_intent' ) && false !== strpos( $plugin, 'function rest_stripe_confirm' ), 'REST handlers defined' );
ck( false !== strpos( $plugin, "CrawlerToll_Stripe::verify_intent(" ), 'confirm verifies the intent before asking for the key' );
ck( false !== strpos( $plugin, "->grant_release(" ) && false !== strpos( $plugin, "(string) \$pi['id']" ), 'confirm grants with the SERVER-confirmed intent id' );
ck( false !== strpos( $registry, 'function grant_release' ) && false !== strpos( $registry, "/grant'" ), 'registry client posts to /v1/sealed/:id/grant' );
ck( false === strpos( $registry, 'connected_account' ) && false === strpos( $plugin, 'connected_account' ), 'no Stripe Connect account anywhere in the plugin' );
ck( false !== strpos( $buildsh, 'includes/class-crawlertoll-stripe.php' ), 'build.sh asserts the Stripe class ships in BOTH artifacts and is free-safe' );
$stripeSrc = (string) file_get_contents( $dir . '/includes/class-crawlertoll-stripe.php' );
ck( ! preg_match( '/CrawlerToll_(DB|Pricing|Alerts|Logger|Provenance|CatalogueUpdater|Subscribers)\b/', $stripeSrc ), 'Stripe class is free-safe (no Pro-only class refs)' );

// ── (d) unlock app talks to the ORIGIN for cards ─────────────────────
ck( false === strpos( $uiApi, '/intent`' ) || false === strpos( $uiApi, 'registryBase' ) || false === strpos( $uiApi, 'function intentUrl' ), 'api.ts no longer builds a registry /intent URL' );
ck( false !== strpos( $uiApi, 'stripe/intent' ) && false !== strpos( $uiApi, 'stripe/confirm' ), 'api.ts calls the site REST stripe/intent + stripe/confirm' );
ck( false === strpos( $uiApi, 'rail: "stripe", pass_id' ), 'api.ts never redeems a card at the registry /key route' );
ck( false === strpos( $uiOffer, 'offer.passes' ), 'offer.ts no longer reads registry product passes' );
ck( false !== strpos( $uiOffer, 'rail: "stripe"' ) && false !== strpos( $uiOffer, 'tier,' ), 'offer.ts card tiles mirror the signed tiers' );
ck( false !== strpos( $uiPay, 'restBase' ), 'payments.ts startStripe takes the site REST base' );

echo "\n" . ( $fail ? "FAILED ($fail)" : 'ALL STRIPE-WIRED TESTS PASSED' ) . "\n";
exit( $fail ? 1 : 0 );
