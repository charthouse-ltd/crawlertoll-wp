<?php
/**
 * Per-path pricing wiring guard (§2.4, 2026-06-20).
 *
 * CrawlerToll_Pricing::resolve() was built + unit-tested (test-harness §6) but
 * never called on the request hot path, so every crawl was charged the flat
 * price. This test asserts the resolver is now wired into on_parse_request and
 * exposed via a Pricing admin tab — guarding against it being orphaned again.
 *
 * Run: php tests/pricing-wired.php   (exit 0 = pass, 1 = fail)
 */

$dir      = dirname( __DIR__ );
$plugin   = (string) file_get_contents( $dir . '/includes/class-crawlertoll-plugin.php' );
$boot     = (string) file_get_contents( $dir . '/crawlertoll.php' );
$admin    = (string) file_get_contents( $dir . '/admin/class-crawlertoll-admin.php' );
$proadmin = (string) file_get_contents( $dir . '/admin/class-crawlertoll-pro-admin.php' );
$view     = $dir . '/admin/views/pro-pricing.php';

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

// Isolate on_parse_request so we assert the resolver runs on the hot path,
// not merely that it is referenced somewhere else in the file.
$ostart = strpos( $plugin, 'function on_parse_request' );
$oend   = strpos( $plugin, 'function augment_robots_txt', $ostart === false ? 0 : $ostart );
$opr    = ( $ostart !== false && $oend !== false ) ? substr( $plugin, $ostart, $oend - $ostart ) : '';

// A — the resolver is invoked on the request path and assigned into $resolved,
//     which already feeds BOTH the 402 builder and the logger.
ck( strpos( $opr, '->resolve(' ) !== false, 'on_parse_request calls Pricing->resolve() on the hot path' );
ck( strpos( $opr, "\$resolved['price_micros']" ) !== false, 'resolved per-path price flows via $resolved' );
ck( strpos( $opr, 'is_pro_active' ) !== false, 'per-path pricing is gated on is_pro_active()' );

// B — path_pricing exists in defaults so the resolver + the free sanitize() see it.
ck( strpos( $boot, "'path_pricing'" ) !== false, 'path_pricing present in default settings' );

// C — a Pricing tab is wired (admin nav + Pro render method + own nonce + view).
ck( strpos( $admin, "'pricing'" ) !== false, 'Pricing tab registered in the admin nav' );
ck( strpos( $proadmin, 'function render_pricing_tab' ) !== false, 'render_pricing_tab() exists on the Pro admin' );
ck( strpos( $proadmin, 'crawlertoll_save_pricing' ) !== false, 'Pricing tab saves through its own nonce' );
ck( file_exists( $view ), 'pro-pricing.php view present' );

exit( $fail === 0 ? 0 : 1 );
