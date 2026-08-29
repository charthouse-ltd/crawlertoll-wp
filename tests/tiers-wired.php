<?php
/**
 * Access-tiers wiring guard (spec: docs/specs/access-tiers-v1.md §3, A2, 2026-08-29).
 *
 * Tiers let Pro publishers offer up to 4 price×duration options per path rule;
 * the registry signs the tier set into the offer and re-derives price+duration
 * server-side at redemption. The plugin has to (a) resolve tiers at seal time
 * (free-safe — the sealing engine ships in the free build) and (b) ship them to
 * the registry on register/reprice, (c) let the wall render one tile per tier
 * and echo tier_id at redemption. This guards the plugin side of that contract.
 *
 * Run: php tests/tiers-wired.php   (exit 0 = pass, 1 = fail)
 */

define( 'ABSPATH', __DIR__ . '/' );

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
$uiApi      = (string) file_get_contents( $dir . '/ui/src/unlock/api.ts' );
$uiPay      = (string) file_get_contents( $dir . '/ui/src/unlock/payments.ts' );

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

// A — the free-safe resolver exists and is loaded by the plugin bootstrap.
ck( file_exists( $tiers ), 'class-crawlertoll-tiers.php present' );
ck( strpos( $main, 'class-crawlertoll-tiers.php' ) !== false, 'tiers resolver required in crawlertoll.php' );
ck( strpos( $tierSrc, 'class CrawlerToll_Tiers' ) !== false, 'CrawlerToll_Tiers class defined' );
ck( strpos( $tierSrc, 'function sanitize_rows' ) !== false, 'sanitize_rows() exists (≤4 rows, price>0, duration null|1..8760)' );
ck( strpos( $tierSrc, 'function resolve_for_post' ) !== false, 'resolve_for_post() exists' );
ck( strpos( $tierSrc, 'function resolve_for_path' ) !== false, 'resolve_for_path() (pure matcher) exists' );
ck( strpos( $tierSrc, 'is_pro_active' ) !== false, 'tiers are Pro-gated (free build resolves null)' );

// B — both gates resolve tiers through the free-safe class, never a Pro-only class.
ck( strpos( $sealedgate, 'CrawlerToll_Tiers::' ) !== false, 'sealed gate resolves tiers via CrawlerToll_Tiers' );
ck( strpos( $premgate, 'CrawlerToll_Tiers::' ) !== false, 'premium gate resolves tiers via CrawlerToll_Tiers' );
ck( strpos( $sealedgate, 'CrawlerToll_Pricing::resolve_tiers' ) === false, 'sealed gate does NOT call a Pro-only tiers resolver' );
ck( strpos( $premgate, 'CrawlerToll_Pricing::resolve_tiers' ) === false, 'premium gate does NOT call a Pro-only tiers resolver' );

// C — registry client ships tiers on register + reprice (tri-state like meter).
ck( preg_match( '/register_sealed\([^)]*\$tiers/', $registry ) === 1, 'register_sealed() accepts a $tiers argument' );
ck( strpos( $registry, "'tiers'" ) !== false || strpos( $registry, '"tiers"' ) !== false, 'registry client sends the tiers key in a request body' );
ck( preg_match( '/update_sealed_price\([^)]*\$tiers/', $registry ) === 1, 'update_sealed_price() accepts a $tiers argument (set/disable)' );

// D — save handlers carry tier rows from the pricing forms.
ck( strpos( $proadmin, 'ct_tier_price' ) !== false, 'classic Pro pricing save reads ct_tier_price[][]' );
ck( strpos( $proadmin, 'ct_tier_dur' ) !== false, 'classic Pro pricing save reads ct_tier_dur[][]' );
ck( strpos( $proadmin, 'ct_tier_custom' ) !== false, 'classic Pro pricing save reads ct_tier_custom[][] (custom days)' );
ck( strpos( $plugin, "['tiers']" ) !== false || strpos( $plugin, "'tiers'" ) !== false, 'REST pricing save sanitizes tiers from rule rows' );

// E — the classic pricing view has the tier inputs.
ck( strpos( $viewsrc, 'ct_tier_price' ) !== false, 'pro-pricing.php view has tier price inputs' );
ck( strpos( $viewsrc, 'ct_tier_dur' ) !== false, 'pro-pricing.php view has tier duration selects' );

// F — React admin (Pro): tier types + editing UI.
ck( strpos( $uiTypes, 'interface AccessTier' ) !== false, 'pro/types.ts defines AccessTier' );
ck( strpos( $uiTypes, 'duration_hours' ) !== false, 'AccessTier carries duration_hours' );
ck( strpos( $uiPricing, 'tiers' ) !== false, 'Pricing.tsx edits tiers on path rules' );

// G — unlock wall: one tile per tier, tier price signed, tier_id echoed.
ck( strpos( $uiOffer, 'durationLabel' ) !== false, 'offer.ts labels tiers by duration (never "forever")' );
ck( strpos( $uiOffer, 'offer.tiers' ) !== false, 'offer.ts reads tiers from the signed offer' );
ck( strpos( $uiApi, 'tier_id' ) !== false, 'api.ts echoes tier_id at redemption' );
ck( strpos( $uiPay, 'tier.price_micros' ) !== false, 'payments.ts signs the TIER price, not the base price' );

// H — free-build safety: the free-safe resolver must NOT be stripped from the
// free artifact (it is required unconditionally by crawlertoll.php).
ck( strpos( $buildsh, 'class-crawlertoll-tiers.php' ) === false, 'build.sh does NOT strip the free-safe tiers resolver' );

exit( $fail === 0 ? 0 : 1 );
