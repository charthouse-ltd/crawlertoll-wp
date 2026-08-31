<?php
/**
 * Bundle-passes wiring guard (spec: docs/specs/access-tiers-v1.md §5.5, A4, 2026-08-31).
 *
 * A path rule marked "sell as bundle" offers ONE pass covering its whole path:
 * the reader pays once and roams every covered article for the duration. The
 * plugin has to (a) resolve the bundle at seal time (free-safe — the sealing
 * engine ships in the free build), (b) ship bundle + url_path to the registry
 * on register/reprice (content_id is host/post/N — the registry matches scoped
 * passes against the permalink path), (c) let publishers configure it in both
 * pricing UIs, (d) render one wall tile per bundle tier, and (e) roam: a bundle
 * holder opening a NEW covered article unlocks silently via the pass list.
 * This guards the plugin side of that contract (registry side: tests/bundles.test.js).
 *
 * Run: php tests/bundles-wired.php   (exit 0 = pass, 1 = fail)
 */

define( 'ABSPATH', __DIR__ . '/' );

// Minimal WP stubs so the resolver's pure parts run in the harness.
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ) { return 'https://demo.local/reviews/post-' . (int) $id . '/'; }
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
$plugin     = (string) file_get_contents( $dir . '/includes/class-crawlertoll-plugin.php' );
$viewsrc    = (string) file_get_contents( $dir . '/admin/views/pro-pricing.php' );
$buildsh    = (string) file_get_contents( $dir . '/build.sh' );
$uiTypes    = (string) file_get_contents( $dir . '/ui/src/pro/types.ts' );
$uiPricing  = (string) file_get_contents( $dir . '/ui/src/pro/views/Pricing.tsx' );
$uiOffer    = (string) file_get_contents( $dir . '/ui/src/unlock/offer.ts' );
$uiApp      = (string) file_get_contents( $dir . '/ui/src/unlock/App.tsx' );

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

// A — the free-safe resolver carries the bundle API and is bootstrapped.
ck( strpos( $tierSrc, 'function sanitize_bundle' ) !== false, 'sanitize_bundle() exists (fail-closed null)' );
ck( strpos( $tierSrc, 'function resolve_bundle_for_post' ) !== false, 'resolve_bundle_for_post() exists' );
ck( strpos( $tierSrc, 'function url_path_for_post' ) !== false, 'url_path_for_post() exists (permalink path, not content_id)' );
ck( strpos( $tierSrc, 'function matching_rule' ) !== false, 'matching_rule() shared longest-prefix matcher extracted' );
ck( strpos( $main, 'class-crawlertoll-tiers.php' ) !== false, 'tiers/bundle resolver required in crawlertoll.php' );
ck( strpos( $buildsh, 'class-crawlertoll-tiers.php' ) === false, 'build.sh does NOT strip the free-safe resolver' );

// B — both gates resolve bundle + url_path through the free-safe class and PATCH on drift.
ck( strpos( $sealedgate, 'CrawlerToll_Tiers::resolve_bundle_for_post' ) !== false, 'sealed gate resolves the bundle offer' );
ck( strpos( $premgate, 'CrawlerToll_Tiers::resolve_bundle_for_post' ) !== false, 'premium gate resolves the bundle offer' );
ck( strpos( $sealedgate, 'CrawlerToll_Tiers::url_path_for_post' ) !== false, 'sealed gate resolves url_path' );
ck( strpos( $premgate, 'CrawlerToll_Tiers::url_path_for_post' ) !== false, 'premium gate resolves url_path' );
ck( strpos( $sealedgate, '$bundle_differ' ) !== false && strpos( $premgate, '$bundle_differ' ) !== false, 'both gates PATCH when the bundle config drifts' );
ck( strpos( $sealedgate, '$url_path_differs' ) !== false && strpos( $premgate, '$url_path_differs' ) !== false, 'both gates PATCH when the permalink path drifts' );

// C — registry client ships bundle + url_path on register and reprice (tri-state).
ck( preg_match( '/register_sealed\([^)]*\$bundle/', $registry ) === 1, 'register_sealed() accepts a $bundle argument' );
ck( preg_match( '/register_sealed\([^)]*\$url_path/', $registry ) === 1, 'register_sealed() accepts a $url_path argument' );
ck( strpos( $registry, "'bundle'" ) !== false, 'registry client sends the bundle key in a request body' );
ck( strpos( $registry, "'url_path'" ) !== false, 'registry client sends the url_path key in a request body' );
ck( preg_match( '/update_sealed_price\([^)]*\$bundle = false/', $registry ) === 1, 'update_sealed_price() bundle arg is tri-state (false = leave)' );
ck( preg_match( '/update_sealed_price\([^)]*\$url_path = false/', $registry ) === 1, 'update_sealed_price() url_path arg is tri-state' );

// D — save handlers carry bundle rows from both pricing forms.
ck( strpos( $proadmin, 'ct_bundle_price' ) !== false, 'classic Pro pricing save reads ct_bundle_price[][]' );
ck( strpos( $proadmin, 'ct_bundle_dur' ) !== false, 'classic Pro pricing save reads ct_bundle_dur[][]' );
ck( strpos( $proadmin, 'ct_bundle_custom' ) !== false, 'classic Pro pricing save reads ct_bundle_custom[][] (custom days)' );
ck( strpos( $proadmin, "isset( \$_POST['ct_bundle'] )" ) !== false || strpos( $proadmin, "\$_POST['ct_bundle']" ) !== false, 'classic save reads the ct_bundle checkbox' );
ck( strpos( $plugin, "'bundle_tiers'" ) !== false, 'REST pricing save sanitizes bundle_tiers from rule rows' );

// E — the classic pricing view has the bundle cell (checkbox + price rows).
ck( strpos( $viewsrc, 'ct_bundle[' ) !== false, 'pro-pricing.php view has the bundle checkbox' );
ck( strpos( $viewsrc, 'ct_bundle_price' ) !== false, 'pro-pricing.php view has bundle price inputs' );
ck( strpos( $viewsrc, 'ct_bundle_dur' ) !== false, 'pro-pricing.php view has bundle duration selects' );
ck( strpos( $viewsrc, 'Sell a bundle' ) !== false, 'pro-pricing.php labels the feature in plain language' );

// F — React admin (Pro): bundle types + editing UI.
ck( strpos( $uiTypes, 'bundle_tiers' ) !== false, 'pro/types.ts PathRule carries bundle_tiers' );
ck( strpos( $uiPricing, 'bundleTiers' ) !== false, 'Pricing.tsx edits bundle tiers on path rules' );
ck( strpos( $uiPricing, 'Sell a bundle' ) !== false, 'Pricing.tsx has the bundle checkbox' );

// G — unlock wall: bundle tiles from the signed offer + pass roaming.
ck( strpos( $uiOffer, 'offer.bundle' ) !== false, 'offer.ts reads the bundle from the signed offer' );
ck( strpos( $uiOffer, 'scope_path' ) !== false, 'offer.ts labels tiles with the bundle scope_path' );
ck( strpos( $uiOffer, 'Unlock ' ) !== false, 'offer.ts bundle tile label leads with "Unlock …"' );
ck( strpos( $uiApp, "'ct:passes'" ) !== false || strpos( $uiApp, '"ct:passes"' ) !== false, 'App.tsx keeps the cross-article pass list (ct:passes)' );
ck( strpos( $uiApp, 'roamWithPasses' ) !== false, 'App.tsx roams held passes on a cache miss' );
ck( strpos( $uiApp, 'passListPush' ) !== false, 'App.tsx records passes at release time' );
ck( strpos( $uiApp, 'passListDrop' ) !== false, 'App.tsx prunes expired passes from the list' );

// H — functional: sanitize_bundle fail-closed behavior (pure — no WP needed).
require $tiers;
ck( CrawlerToll_Tiers::sanitize_bundle( array( 'path' => '/reviews/*' ) ) === null, 'bundle off without the flag' );
ck( CrawlerToll_Tiers::sanitize_bundle( array( 'bundle' => true, 'path' => 'reviews/*', 'bundle_tiers' => array( array( 'price_micros' => 1000, 'duration_hours' => 24 ) ) ) ) === null, 'path must start with /' );
ck( CrawlerToll_Tiers::sanitize_bundle( array( 'bundle' => true, 'path' => '/reviews/*' ) ) === null, 'no valid tier rows = no bundle (fail-closed)' );
ck( CrawlerToll_Tiers::sanitize_bundle( array( 'bundle' => true, 'path' => '/reviews/*', 'bundle_tiers' => array( array( 'price_micros' => 0, 'duration_hours' => 24 ) ) ) ) === null, 'zero-price rows do not count' );
$sb = CrawlerToll_Tiers::sanitize_bundle( array(
	'bundle'       => true,
	'path'         => '/reviews/*',
	'bundle_tiers' => array(
		array( 'price_micros' => 30000, 'duration_hours' => 720 ),
		array( 'price_micros' => 5000, 'duration_hours' => null ),
	),
) );
ck( is_array( $sb ) && '/reviews/*' === $sb['path'] && 2 === count( $sb['tiers'] ), 'valid rule sanitizes to {path, tiers}' );
ck( 30000 === $sb['tiers'][0]['price_micros'] && 720 === $sb['tiers'][0]['duration_hours'], 'bundle tier price + duration survive' );
ck( null === $sb['tiers'][1]['duration_hours'], 'no-expiry bundle tier keeps null duration' );

// I — functional: resolve_bundle_for_post end-to-end with stubs (Pro gate + matcher).
$settings = array(
	'path_pricing' => array(
		array(
			'path'         => '/reviews/*',
			'price_micros' => 5000,
			'currency'     => 'USDC',
			'bundle'       => true,
			'bundle_tiers' => array( array( 'price_micros' => 30000, 'duration_hours' => 720 ) ),
		),
	),
);
$rb = CrawlerToll_Tiers::resolve_bundle_for_post( 7, $settings );
ck( is_array( $rb ) && '/reviews/*' === $rb['path'], 'resolves the bundle for a covered post (permalink /reviews/post-7/)' );
$GLOBALS['ct_test_pro'] = false;
ck( CrawlerToll_Tiers::resolve_bundle_for_post( 7, $settings ) === null, 'free build resolves null (Pro-gated)' );
$GLOBALS['ct_test_pro'] = true;
ck( CrawlerToll_Tiers::url_path_for_post( 7 ) === '/reviews/post-7/', 'url_path_for_post returns the permalink path' );
$settings_nomatch = array( 'path_pricing' => array( array( 'path' => '/premium/*', 'price_micros' => 5000, 'currency' => 'USDC', 'bundle' => true, 'bundle_tiers' => array( array( 'price_micros' => 30000, 'duration_hours' => 720 ) ) ) ) );
ck( CrawlerToll_Tiers::resolve_bundle_for_post( 7, $settings_nomatch ) === null, 'non-matching rule offers no bundle' );

exit( $fail === 0 ? 0 : 1 );
