<?php
/**
 * M1 wiring guard (2026-09-01): multi-storage meter identity + tunable IP ceiling.
 *
 * Two halves of the meter anti-abuse story:
 *  (a) The wall persists the meter token map in localStorage + a first-party
 *      cookie + IndexedDB, merges all three on read (per-path, most-local
 *      wins), and re-populates whichever store lost its copy — a single-store
 *      wipe no longer resets the free-allowance identity.
 *  (b) The registry's per-IP grant ceiling becomes publisher-tunable per path
 *      rule (meter_ip_ceiling, 1..20; absent = registry default) and must be
 *      plumbed rule → resolver → register/PATCH bodies, with both pricing UIs.
 *
 * Run: php tests/m1-wired.php   (exit 0 = pass, 1 = fail)
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id ) { return 'https://demo.local/articles/post-' . (int) $id . '/'; }
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
}
$GLOBALS['ct_test_pro'] = true;
if ( ! class_exists( 'CrawlerToll_Pro_Admin' ) ) {
	class CrawlerToll_Pro_Admin {
		public static function is_pro_active() { return ! empty( $GLOBALS['ct_test_pro'] ); }
	}
}

$dir      = dirname( __DIR__ );
$meter    = $dir . '/includes/class-crawlertoll-meter.php';
$meterSrc = (string) file_get_contents( $meter );
$registry = (string) file_get_contents( $dir . '/includes/class-crawlertoll-registry.php' );
$proadmin = (string) file_get_contents( $dir . '/admin/class-crawlertoll-pro-admin.php' );
$plugin   = (string) file_get_contents( $dir . '/includes/class-crawlertoll-plugin.php' );
$viewsrc  = (string) file_get_contents( $dir . '/admin/views/pro-pricing.php' );
$uiTypes  = (string) file_get_contents( $dir . '/ui/src/pro/types.ts' );
$uiPric   = (string) file_get_contents( $dir . '/ui/src/pro/views/Pricing.tsx' );
$uiApi    = (string) file_get_contents( $dir . '/ui/src/unlock/api.ts' );

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

// A — resolver carries the ceiling multiple (free-safe, Pro-gated like the meter).
ck( strpos( $meterSrc, 'ip_ceiling_mult' ) !== false, 'meter resolver emits ip_ceiling_mult' );
ck( strpos( $meterSrc, 'meter_ip_ceiling' ) !== false, 'meter resolver reads the meter_ip_ceiling rule field' );

// B — registry client ships it on register AND reprice bodies.
ck( substr_count( $registry, "['ip_ceiling_mult']" ) >= 2, 'registry client sends ip_ceiling_mult in register + PATCH meter bodies' );

// C — both pricing saves parse the field with the 1..20 bound.
ck( strpos( $proadmin, 'ct_meter_ip_ceiling' ) !== false, 'classic Pro pricing save reads ct_meter_ip_ceiling[]' );
ck( strpos( $plugin, 'meter_ip_ceiling' ) !== false, 'REST pricing save stores meter_ip_ceiling' );
ck( preg_match( '/\$mceil >= 1 && \$mceil <= 20/', $proadmin ) === 1, 'classic save bounds the multiple to 1..20' );
ck( preg_match( '/\$mceil >= 1 && \$mceil <= 20/', $plugin ) === 1, 'REST save bounds the multiple to 1..20' );

// D — both pricing UIs expose the field with plain-language help.
ck( strpos( $viewsrc, 'ct_meter_ip_ceiling[]' ) !== false, 'pro-pricing.php view has the IP ceiling input' );
ck( strpos( $viewsrc, 'IP ceiling' ) !== false, 'pro-pricing.php explains the ceiling in plain language' );
ck( strpos( $uiTypes, 'meter_ip_ceiling' ) !== false, 'pro/types.ts PathRule carries meter_ip_ceiling' );
ck( strpos( $uiPric, 'ipCeiling' ) !== false, 'Pricing.tsx edits the ceiling on path rules' );
ck( strpos( $uiPric, 'Max per IP' ) !== false, 'Pricing.tsx labels the field in plain language' );

// E — the wall: three stores, merged read, self-healing.
ck( strpos( $uiApi, 'METER_COOKIE' ) !== false, 'api.ts defines the first-party cookie store' );
ck( strpos( $uiApi, 'indexedDB.open' ) !== false, 'api.ts defines the IndexedDB store' );
ck( strpos( $uiApi, 'meterTokensReadMerged' ) !== false, 'api.ts merges all three stores on read' );
ck( strpos( $uiApi, 'await meterTokensReadMerged()' ) !== false, 'fetchOffer awaits the merged read (identity restored before the offer)' );
ck( preg_match( '/lsWrite\( merged \)|lsWrite\(merged\)/', $uiApi ) === 1, 'merged read re-populates localStorage (self-healing)' );
ck( preg_match( '/cookieWrite\( merged \)|cookieWrite\(merged\)/', $uiApi ) === 1, 'merged read re-populates the cookie' );
ck( preg_match( '/idbWrite\( merged \)|idbWrite\(merged\)/', $uiApi ) === 1, 'merged read re-populates IndexedDB' );
ck( strpos( $uiApi, 'SameSite=Lax' ) !== false, 'cookie is SameSite=Lax (first-party only)' );
ck( strpos( $uiApi, 'METER_COOKIE_LIMIT' ) !== false, 'cookie write respects the 4 KB ceiling' );
ck( strpos( $uiApi, '...cookieRead(), ...lsRead()' ) !== false, 'meterTokenStore merges before writing (no token loss on wiped localStorage)' );

// F — functional: resolver output with stubs.
require $meter;
$settings = array(
	'path_pricing' => array(
		array( 'path' => '/articles/*', 'price_micros' => 5000, 'currency' => 'USDC', 'meter_count' => 3, 'meter_window' => 30, 'meter_ip_ceiling' => 2 ),
	),
);
$m = CrawlerToll_Meter::resolve_for_post( 7, $settings );
ck( is_array( $m ) && isset( $m['ip_ceiling_mult'] ) && 2 === $m['ip_ceiling_mult'], 'resolver passes the ceiling multiple through' );
$settings_bad = array(
	'path_pricing' => array(
		array( 'path' => '/articles/*', 'price_micros' => 5000, 'currency' => 'USDC', 'meter_count' => 3, 'meter_ip_ceiling' => 99 ),
	),
);
$m2 = CrawlerToll_Meter::resolve_for_post( 7, $settings_bad );
ck( is_array( $m2 ) && ! isset( $m2['ip_ceiling_mult'] ), 'out-of-bounds multiple is dropped (registry default applies)' );
$settings_off = array(
	'path_pricing' => array(
		array( 'path' => '/articles/*', 'price_micros' => 5000, 'currency' => 'USDC', 'meter_count' => 0 ),
	),
);
ck( CrawlerToll_Meter::resolve_for_post( 7, $settings_off ) === null, 'meter off = no meta at all (no ceiling either)' );
$GLOBALS['ct_test_pro'] = false;
ck( CrawlerToll_Meter::resolve_for_post( 7, $settings ) === null, 'free build resolves null (Pro-gated)' );
$GLOBALS['ct_test_pro'] = true;

exit( $fail === 0 ? 0 : 1 );
