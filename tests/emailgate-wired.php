<?php
/**
 * Email-gate wiring guard (spec §5.5, A5, 2026-09-01).
 *
 * A path rule flagged "email gate" lets HUMAN readers unlock free after
 * verifying an email address (WP = data controller, stores raw email + exact
 * consent text; the registry emails a one-time magic link and keeps only the
 * sha256). The plugin has to (a) resolve the flag at seal time — free-safe,
 * Pro-gated, (b) ship it to the registry on register/reprice (tri-state),
 * (c) let publishers configure it in both pricing UIs + the consent mode in a
 * Readers tab, (d) serve the two public REST endpoints (request/verified) with
 * honest error codes, and (e) run the wall flow: menu tile → form →
 * email-sent → silent ?ct_email_grant= redeem. This guards the plugin side of
 * that contract (registry side: tests/emailgate.test.js).
 *
 * Run: php tests/emailgate-wired.php   (exit 0 = pass, 1 = fail)
 */

define( 'ABSPATH', __DIR__ . '/' );

// Minimal WP stubs so the resolver's pure parts run in the harness.
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ) { return 'https://demo.local/articles/post-' . (int) $id . '/'; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
// Pro gate stub: a global flag flips is_pro_active() per assertion.
$GLOBALS['ct_test_pro'] = true;
if ( ! class_exists( 'CrawlerToll_Pro_Admin' ) ) {
	class CrawlerToll_Pro_Admin {
		public static function is_pro_active() { return ! empty( $GLOBALS['ct_test_pro'] ); }
	}
}

$dir        = dirname( __DIR__ );
$main       = (string) file_get_contents( $dir . '/crawlertoll.php' );
$tiers      = $dir . '/includes/class-crawlertoll-tiers.php';
$tierSrc    = (string) file_get_contents( $tiers );
$sealedgate = (string) file_get_contents( $dir . '/includes/class-crawlertoll-sealed-gate.php' );
$premgate   = (string) file_get_contents( $dir . '/includes/class-crawlertoll-premium-gate.php' );
$registry   = (string) file_get_contents( $dir . '/includes/class-crawlertoll-registry.php' );
$proadmin   = (string) file_get_contents( $dir . '/admin/class-crawlertoll-pro-admin.php' );
$admin      = (string) file_get_contents( $dir . '/admin/class-crawlertoll-admin.php' );
$plugin     = (string) file_get_contents( $dir . '/includes/class-crawlertoll-plugin.php' );
$viewsrc    = (string) file_get_contents( $dir . '/admin/views/pro-pricing.php' );
$readersvw  = (string) file_get_contents( $dir . '/admin/views/pro-readers.php' );
$subs       = (string) file_get_contents( $dir . '/includes/class-crawlertoll-subscribers.php' );
$buildsh    = (string) file_get_contents( $dir . '/build.sh' );
$uiTypes    = (string) file_get_contents( $dir . '/ui/src/pro/types.ts' );
$uiPricing  = (string) file_get_contents( $dir . '/ui/src/pro/views/Pricing.tsx' );
$uiOffer    = (string) file_get_contents( $dir . '/ui/src/unlock/offer.ts' );
$uiApi      = (string) file_get_contents( $dir . '/ui/src/unlock/api.ts' );
$uiApp      = (string) file_get_contents( $dir . '/ui/src/unlock/App.tsx' );

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

// A — the free-safe resolver carries the email-gate API and is bootstrapped.
ck( strpos( $tierSrc, 'function resolve_email_gate_for_post' ) !== false, 'resolve_email_gate_for_post() exists' );
ck( strpos( $main, 'class-crawlertoll-tiers.php' ) !== false, 'tiers resolver required in crawlertoll.php' );
ck( strpos( $buildsh, 'class-crawlertoll-tiers.php' ) === false, 'build.sh does NOT strip the free-safe resolver' );

// B — both gates resolve the flag at seal time and PATCH the registry on drift.
ck( strpos( $sealedgate, 'CrawlerToll_Tiers::resolve_email_gate_for_post' ) !== false, 'sealed gate resolves the email gate flag' );
ck( strpos( $premgate, 'CrawlerToll_Tiers::resolve_email_gate_for_post' ) !== false, 'premium gate resolves the email gate flag' );
ck( strpos( $sealedgate, '$egate_differs' ) !== false && strpos( $premgate, '$egate_differs' ) !== false, 'both gates PATCH when the flag drifts' );
ck( strpos( $sealedgate, "'email_gate'" ) !== false && strpos( $premgate, "'email_gate'" ) !== false, 'both gates cache the flag in seal meta' );

// C — registry client: register carries the flag; reprice is tri-state; the
// two endpoint wrappers exist with the right verbs/routes.
ck( preg_match( '/register_sealed\([^)]*\$email_gate/', $registry ) === 1, 'register_sealed() accepts a $email_gate argument' );
ck( preg_match( '/update_sealed_price\([^)]*\$email_gate = false/', $registry ) === 1, 'update_sealed_price() email_gate arg is tri-state (false = leave)' );
ck( strpos( $registry, "\$body['email_gate'] = true" ) !== false, 'register body sends email_gate only when exactly true' );
ck( strpos( $registry, 'function email_request' ) !== false && strpos( $registry, "'/v1/email/request'" ) !== false, 'email_request() wrapper posts to /v1/email/request' );
ck( strpos( $registry, 'function email_verify_status' ) !== false && strpos( $registry, "'/v1/email/verify-status'" ) !== false, 'email_verify_status() wrapper posts to /v1/email/verify-status' );

// D — REST: the two PUBLIC routes + the honest error contract + pricing save.
ck( strpos( $plugin, "'/email/request'" ) !== false && strpos( $plugin, "'/email-verified'" ) !== false, 'public /email/request + /email-verified routes registered' );
ck( substr_count( $plugin, "'__return_true'" ) >= 3, 'email endpoints are public like /context-license (permission __return_true)' );
ck( strpos( $plugin, 'function rest_email_request' ) !== false && strpos( $plugin, 'function rest_email_verified' ) !== false, 'REST handlers exist' );
ck( strpos( $plugin, "'pro_required'" ) !== false, 'email request 403s when Pro is off' );
ck( strpos( $plugin, "'email_gate_disabled'" ) !== false, 'email request 403s when the article is not email-gated' );
ck( strpos( $plugin, "'consent_required'" ) !== false, 'missing consents 400 honestly (functional + pur-mode marketing)' );
ck( strpos( $plugin, "'rate_limited'" ) !== false, 'registry rate limit relays as 429' );
ck( strpos( $plugin, 'resolve_email_gate_for_post' ) !== false, 'REST handler re-checks the flag server-side (no arbitrary content_ids)' );
ck( strpos( $plugin, "'email_gate'] = true" ) !== false, 'REST pricing save stores the email_gate flag' );
ck( strpos( $plugin, 'class_exists( \'CrawlerToll_Subscribers\' )' ) !== false, 'REST handlers guard the Pro-only subscribers class (free build safe)' );

// E — subscribers store: Pro-only, stripped from free, guarded require.
ck( strpos( $subs, 'class CrawlerToll_Subscribers' ) !== false, 'subscribers class exists' );
ck( strpos( $subs, 'email_sha256' ) !== false && strpos( $subs, 'UNIQUE KEY uq_email_sha256' ) !== false, 'subscriber rows keyed on the address hash (registry join key)' );
ck( strpos( $subs, 'consent_text' ) !== false && strpos( $subs, 'consent_at' ) !== false, 'consent text + timestamp stored (audit trail)' );
ck( strpos( $subs, 'consent_marketing = 1' ) !== false, 'marketing export is consent-only' );
ck( strpos( $subs, 'maybe_create_table' ) !== false && strpos( $subs, 'crawlertoll_subs_dbv' ) !== false, 'lazy table creation via schema-version option (existing installs)' );
ck( strpos( $buildsh, 'class-crawlertoll-subscribers.php' ) !== false, 'build.sh strips the subscribers class from the FREE build' );
ck( strpos( $buildsh, 'CrawlerToll_Subscribers' ) !== false, 'build.sh asserts no CrawlerToll_Subscribers definition survives in free' );
ck( strpos( $main, 'class-crawlertoll-subscribers.php' ) !== false, 'crawlertoll.php requires it inside the file_exists-guarded Pro list' );

// F — Readers tab: render method, classic view, dispatcher wiring.
ck( strpos( $proadmin, 'function render_readers_tab' ) !== false, 'render_readers_tab() exists' );
ck( strpos( $proadmin, 'pro-readers.php' ) !== false, 'Readers tab includes the classic view (NOT the pro-app div)' );
ck( strpos( $proadmin, 'send_readers_csv' ) !== false && strpos( $proadmin, 'text/csv' ) !== false, 'CSV export streams text/csv' );
ck( strpos( $proadmin, 'crawlertoll_readers_export' ) !== false, 'CSV export is nonce-guarded' );
ck( strpos( $admin, "'readers'" ) !== false, 'admin dispatcher registers the readers tab' );
ck( strpos( $admin, 'render_readers_tab' ) !== false, 'admin dispatcher routes to render_readers_tab' );
ck( strpos( $readersvw, 'ct_email_gate_mode' ) !== false, 'Readers view edits the consent mode' );
ck( strpos( $readersvw, 'split' ) !== false && strpos( $readersvw, 'pur' ) !== false, 'both consent modes present' );
ck( strpos( $readersvw, 'Regulatory warning' ) !== false, 'pur mode shows the regulatory warning' );

// G — both pricing UIs carry the flag.
ck( strpos( $proadmin, 'ct_email_gate' ) !== false, 'classic Pro pricing save reads ct_email_gate[]' );
ck( strpos( $viewsrc, 'ct_email_gate[' ) !== false, 'pro-pricing.php view has the email-gate checkbox' );
ck( strpos( $uiTypes, 'email_gate' ) !== false, 'pro/types.ts PathRule carries email_gate' );
ck( strpos( $uiPricing, 'emailGate' ) !== false, 'Pricing.tsx edits the email gate on path rules' );

// H — the wall: offer flag, api helpers, mount attrs, full flow in App.tsx.
ck( strpos( $uiOffer, 'email_gate' ) !== false, 'offer.ts carries email_gate (signed offer)' );
ck( strpos( $uiApi, 'requestEmailLink' ) !== false && strpos( $uiApi, 'redeemEmailGrant' ) !== false && strpos( $uiApi, 'markEmailVerified' ) !== false, 'api.ts has the three email-gate calls' );
ck( strpos( $uiApi, '/email/request' ) !== false && strpos( $uiApi, '/v1/email/redeem' ) !== false && strpos( $uiApi, '/email-verified' ) !== false, 'api.ts hits the WP request endpoint, the registry redeem endpoint, and the WP verified endpoint' );
ck( strpos( $uiApi, 'grant_expired_or_used' ) !== false, '410 maps to honest expired/used copy' );
ck( strpos( $premgate, 'data-email-mode' ) !== false && strpos( $premgate, 'data-rest-url' ) !== false, 'the wall mount emits data-email-mode + data-rest-url (subdirectory installs)' );
ck( strpos( $uiApp, 'ct_email_grant' ) !== false, 'App.tsx detects the magic-link param on mount' );
ck( strpos( $uiApp, '"email-sent"' ) !== false && strpos( $uiApp, '"email"' ) !== false, 'App.tsx has the email + email-sent states' );
ck( strpos( $uiApp, 'Read free with your email' ) !== false, 'the menu tile exists' );
ck( strpos( $uiApp, 'replaceState' ) !== false, 'the token is stripped from the URL after redeem' );
ck( strpos( $uiApp, 'grantAttempted' ) !== false, 'the grant flow wins over CEK-cache/roaming on mount' );
ck( strpos( $uiApp, 'markEmailVerified' ) !== false, 'the wall reports the redeem back to WP (verified bookkeeping)' );

// I — functional: the resolver with stubs (Pro gate + longest-prefix matcher).
require $tiers;
$settings = array(
	'path_pricing' => array(
		array( 'path' => '/articles/*', 'price_micros' => 5000, 'currency' => 'USDC', 'email_gate' => true ),
		array( 'path' => '/', 'price_micros' => 5000, 'currency' => 'USDC' ),
	),
);
ck( CrawlerToll_Tiers::resolve_email_gate_for_post( 7, $settings ) === true, 'flagged rule resolves true (permalink /articles/post-7/)' );
$settings_off = array( 'path_pricing' => array( array( 'path' => '/articles/*', 'price_micros' => 5000, 'currency' => 'USDC' ) ) );
ck( CrawlerToll_Tiers::resolve_email_gate_for_post( 7, $settings_off ) === false, 'unflagged rule resolves false' );
$settings_nomatch = array( 'path_pricing' => array( array( 'path' => '/premium/*', 'price_micros' => 5000, 'currency' => 'USDC', 'email_gate' => true ) ) );
ck( CrawlerToll_Tiers::resolve_email_gate_for_post( 7, $settings_nomatch ) === false, 'non-matching rule resolves false' );
$GLOBALS['ct_test_pro'] = false;
ck( CrawlerToll_Tiers::resolve_email_gate_for_post( 7, $settings ) === false, 'free build resolves false (Pro-gated)' );
$GLOBALS['ct_test_pro'] = true;

exit( $fail === 0 ? 0 : 1 );
