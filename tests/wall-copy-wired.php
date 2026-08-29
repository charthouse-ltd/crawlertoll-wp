<?php
/**
 * Wall-copy wiring guard (spec: docs/specs/access-tiers-v1.md §5.4, A3, 2026-08-29).
 *
 * Every reader-facing wall string is a publisher-editable template. This guards
 * the plugin side: the free-safe resolver ships in both builds, the gate emits
 * the templates as mount data attributes, the classic settings form saves them,
 * the per-article override meta is registered for the block editor, and the
 * unlock app substitutes placeholders client-side with a never-raw fallback.
 *
 * Run: php tests/wall-copy-wired.php   (exit 0 = pass, 1 = fail)
 */

define( 'ABSPATH', __DIR__ . '/' );

// Minimal WP stubs so the resolver's pure parts run in the harness.
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	// Mirror WP: wp_strip_all_tags removes <script>/<style> blocks ENTIRELY
	// (content included), then strips remaining tags.
	function sanitize_textarea_field( $v ) {
		$v = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $v );
		return trim( strip_tags( $v ) );
	}
}

$dir       = dirname( __DIR__ );
$main      = (string) file_get_contents( $dir . '/crawlertoll.php' );
$copy      = $dir . '/includes/class-crawlertoll-wall-copy.php';
$copysrc   = (string) file_get_contents( $copy );
$premgate  = (string) file_get_contents( $dir . '/includes/class-crawlertoll-premium-gate.php' );
$admin     = (string) file_get_contents( $dir . '/admin/class-crawlertoll-admin.php' );
$plugin    = (string) file_get_contents( $dir . '/includes/class-crawlertoll-plugin.php' );
$cut       = (string) file_get_contents( $dir . '/includes/class-crawlertoll-cut.php' );
$viewsrc   = (string) file_get_contents( $dir . '/admin/views/settings.php' );
$panel     = (string) file_get_contents( $dir . '/assets/js/editor-panel.js' );
$app       = (string) file_get_contents( $dir . '/ui/src/unlock/App.tsx' );
$buildsh   = (string) file_get_contents( $dir . '/build.sh' );

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

// A — free-safe resolver exists, is bootstrapped, and is NOT stripped from free.
ck( file_exists( $copy ), 'class-crawlertoll-wall-copy.php present' );
ck( strpos( $main, 'class-crawlertoll-wall-copy.php' ) !== false, 'wall copy required in crawlertoll.php' );
ck( strpos( $buildsh, 'class-crawlertoll-wall-copy.php' ) === false, 'build.sh does NOT strip the free-safe wall-copy resolver' );
ck( strpos( $copysrc, 'class CrawlerToll_Wall_Copy' ) !== false, 'CrawlerToll_Wall_Copy class defined' );
ck( strpos( $copysrc, 'function defaults' ) !== false, 'defaults() defines the four templates' );
ck( strpos( $copysrc, "'heading'" ) !== false && strpos( $copysrc, "'value_line'" ) !== false
	&& strpos( $copysrc, "'meter_out'" ) !== false && strpos( $copysrc, "'unavailable'" ) !== false, 'all four spec templates present (heading, value_line, meter_out, unavailable)' );
ck( strpos( $copysrc, 'MAX_LEN' ) !== false && strpos( $copysrc, '= 300' ) !== false, '300-char cap constant present' );
ck( strpos( $copysrc, 'is_pro_active' ) !== false, 'per-article override is Pro-gated at resolve time' );
ck( strpos( $main, "CrawlerToll_Wall_Copy', 'register_meta'" ) !== false, 'per-article meta registered on init' );
ck( strpos( $copysrc, 'show_in_rest' ) !== false, 'override meta is show_in_rest (block editor can save it)' );

// B — the gate emits the templates + site name as mount data attributes (both mount points).
ck( substr_count( $premgate, 'data-wall-heading' ) >= 2, 'gate emits data-wall-heading at both mount points' );
ck( substr_count( $premgate, 'data-wall-value' ) >= 2, 'gate emits data-wall-value at both mount points' );
ck( substr_count( $premgate, 'data-wall-meter-out' ) >= 2, 'gate emits data-wall-meter-out at both mount points' );
ck( substr_count( $premgate, 'data-wall-unavailable' ) >= 2, 'gate emits data-wall-unavailable at both mount points' );
ck( substr_count( $premgate, 'data-site-name' ) >= 2, 'gate emits data-site-name at both mount points' );
ck( strpos( $premgate, 'CrawlerToll_Wall_Copy::resolve' ) !== false, 'gate resolves copy via the free-safe class' );

// C — save paths: classic settings sanitize + form fields + REST round-trip.
ck( strpos( $admin, "CrawlerToll_Wall_Copy::sanitize" ) !== false, 'classic settings sanitize() handles wall_text' );
ck( strpos( $viewsrc, '[wall_text][' ) !== false, 'settings form posts wall_text fields' );
foreach ( array( 'heading', 'value_line', 'meter_out', 'unavailable' ) as $wf ) {
	ck( strpos( $viewsrc, "'" . $wf . "'" ) !== false, "settings form renders the $wf field" );
}
ck( strpos( $plugin, "'wall_text'" ) !== false, 'REST settings GET round-trips wall_text' );

// D — editor sidebar: per-article override field, Pro-gated visibility.
ck( strpos( $panel, '_crawlertoll_wall_text' ) !== false, 'editor panel edits _crawlertoll_wall_text' );
ck( strpos( $panel, 'proActive' ) !== false, 'editor panel hides the field on free installs (no dead stubs)' );
ck( strpos( $cut, 'proActive' ) !== false, 'cut class localizes the proActive flag' );

// E — unlock app: placeholder substitution with never-raw fallback.
ck( strpos( $app, 'function tpl(' ) !== false, 'App.tsx has the tpl() substitution helper' );
ck( strpos( $app, 'dataset.wallHeading' ) !== false, 'App.tsx reads data-wall-heading' );
ck( strpos( $app, 'dataset.wallValue' ) !== false, 'App.tsx reads data-wall-value' );
ck( strpos( $app, 'dataset.wallMeterOut' ) !== false, 'App.tsx reads data-wall-meter-out' );
ck( strpos( $app, 'missing' ) !== false, 'tpl() falls back when a placeholder has no value' );

// F — functional: the sanitizer caps, strips tags, drops empties + unknown keys.
require $copy;
$san = CrawlerToll_Wall_Copy::sanitize( array(
	'heading'     => '<script>alert(1)</script>Members only',
	'value_line'  => str_repeat( 'x', 400 ),
	'meter_out'   => '',
	'unavailable' => '  Padded  ',
	'hacker_key'  => 'nope',
) );
ck( $san['heading'] === 'Members only', 'sanitize strips HTML from templates' );
ck( strlen( $san['value_line'] ) === 300, 'sanitize caps at 300 chars' );
ck( ! isset( $san['meter_out'] ), 'sanitize drops empty values (default applies)' );
ck( $san['unavailable'] === 'Padded', 'sanitize trims whitespace' );
ck( ! isset( $san['hacker_key'] ), 'sanitize drops unknown keys' );
$res = CrawlerToll_Wall_Copy::resolve( 0, array( 'wall_text' => array( 'heading' => 'Subscribers' ) ) );
ck( $res['heading'] === 'Subscribers', 'resolve applies the settings override' );
ck( $res['value_line'] !== '' && $res['meter_out'] !== '' && $res['unavailable'] !== '', 'resolve always returns all four, non-empty' );
$res2 = CrawlerToll_Wall_Copy::resolve( 0, array() );
ck( $res2['heading'] === 'Keep reading', 'resolve falls back to shipped defaults' );

exit( $fail === 0 ? 0 : 1 );
