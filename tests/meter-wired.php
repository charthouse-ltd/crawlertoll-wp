<?php
/**
 * Metered-free-articles wiring guard (spec: docs/specs/metered-free-articles-v1.md, 2026-08-26).
 *
 * The meter lets Pro publishers grant N free reads per path per rolling window;
 * the registry enforces it, the plugin only has to (a) resolve the meter meta at
 * seal time (free-safe — the sealing engine ships in the free build) and
 * (b) ship the meta to the registry on register/reprice. This guards the plugin
 * side of that contract.
 *
 * Run: php tests/meter-wired.php   (exit 0 = pass, 1 = fail)
 */

define( 'ABSPATH', __DIR__ . '/' );

$dir        = dirname( __DIR__ );
$main       = (string) file_get_contents( $dir . '/crawlertoll.php' );
$meter      = $dir . '/includes/class-crawlertoll-meter.php';
$metersrc   = (string) file_get_contents( $meter );
$sealedgate = (string) file_get_contents( $dir . '/includes/class-crawlertoll-sealed-gate.php' );
$premgate   = (string) file_get_contents( $dir . '/includes/class-crawlertoll-premium-gate.php' );
$registry   = (string) file_get_contents( $dir . '/includes/class-crawlertoll-registry.php' );
$proadmin   = (string) file_get_contents( $dir . '/admin/class-crawlertoll-pro-admin.php' );
$plugin     = (string) file_get_contents( $dir . '/includes/class-crawlertoll-plugin.php' );
$pricing    = (string) file_get_contents( $dir . '/includes/class-crawlertoll-pricing.php' );
$view       = $dir . '/admin/views/pro-pricing.php';
$viewsrc    = (string) file_get_contents( $view );

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

// A — the free-safe resolver exists and is loaded by the plugin bootstrap.
ck( file_exists( $meter ), 'class-crawlertoll-meter.php present' );
ck( strpos( $main, 'class-crawlertoll-meter.php' ) !== false, 'meter resolver required in crawlertoll.php' );
ck( strpos( $metersrc, 'class CrawlerToll_Meter' ) !== false, 'CrawlerToll_Meter class defined' );
ck( strpos( $metersrc, 'function resolve_for_post' ) !== false, 'resolve_for_post() exists' );
ck( strpos( $metersrc, 'function resolve_for_path' ) !== false, 'resolve_for_path() (pure matcher) exists' );
ck( strpos( $metersrc, 'is_pro_active' ) !== false, 'meter is Pro-gated (free build resolves null)' );

// B — both gates resolve meter meta through the free-safe class, never the Pro-only pricing class.
ck( strpos( $sealedgate, 'CrawlerToll_Meter::resolve_for_post' ) !== false, 'sealed gate resolves meter via CrawlerToll_Meter' );
ck( strpos( $premgate, 'CrawlerToll_Meter::resolve_for_post' ) !== false, 'premium gate resolves meter via CrawlerToll_Meter' );
ck( strpos( $sealedgate, 'CrawlerToll_Pricing::resolve_meter_for_post' ) === false, 'sealed gate does NOT call the Pro-only pricing resolver' );
ck( strpos( $premgate, 'CrawlerToll_Pricing::resolve_meter_for_post' ) === false, 'premium gate does NOT call the Pro-only pricing resolver' );

// C — registry client ships the meter meta on register + reprice.
ck( strpos( $registry, 'function register_sealed' ) !== false, 'register_sealed() exists' );
ck( preg_match( '/register_sealed\([^)]*\$meter/', $registry ) === 1, 'register_sealed() accepts a $meter argument' );
ck( strpos( $registry, "'meter'" ) !== false || strpos( $registry, '"meter"' ) !== false, 'registry client sends the meter key in a request body' );
ck( preg_match( '/update_sealed_price\([^)]*\$meter/', $registry ) === 1, 'update_sealed_price() accepts a $meter argument (set/disable)' );

// D — pricing save handlers carry meter_count / meter_window from the path-rule form.
ck( strpos( $proadmin, 'ct_meter_count' ) !== false, 'Pro pricing save reads ct_meter_count[]' );
ck( strpos( $proadmin, 'ct_meter_window' ) !== false, 'Pro pricing save reads ct_meter_window[]' );
ck( strpos( $plugin, "['meter_count']" ) !== false || strpos( $plugin, "'meter_count'" ) !== false, 'REST pricing save sanitizes meter_count/meter_window from rule rows' );

// E — the classic pricing view has the inputs + plain-language explainer.
ck( strpos( $viewsrc, 'ct_meter_count' ) !== false, 'pro-pricing.php view has meter count inputs' );
ck( strpos( $viewsrc, 'ct_meter_window' ) !== false, 'pro-pricing.php view has meter window inputs' );

// F — pricing resolver exposes meter_of_rule for the React REST payload.
ck( strpos( $pricing, 'function meter_of_rule' ) !== false, 'meter_of_rule() exists on the pricing resolver' );
ck( strpos( $pricing, "'meter'" ) !== false, 'pricing resolve() returns the meter key' );

exit( $fail === 0 ? 0 : 1 );
