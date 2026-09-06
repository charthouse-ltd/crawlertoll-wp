<?php
/**
 * Error guard (W4, 2026-09-06) — pure behaviour + wiring guard.
 *
 * (a) run()/wrap()/rest() swallow Throwables, return the right fallback and
 *     record a deduplicated, capped ring-buffer entry.
 * (b) Every front-end hook and public REST handler is wrapped; the gate's
 *     text filters fail EMPTY (never the body), enforcement fails VOID (open);
 *     the shutdown handler is registered at bootstrap; the admin notice, clear
 *     handler and the Advanced-card log/diagnostics exist.
 *
 * Run: php tests/guard-harness.php   (exit 0 = pass, 1 = fail)
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'CRAWLERTOLL_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

// In-memory wp_options + the few WP functions the guard touches.
$GLOBALS['ct_opts'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['ct_opts'] ) ? $GLOBALS['ct_opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['ct_opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['ct_opts'][ $k ] ); return true; }
function wp_normalize_path( $p ) { return str_replace( '\\', '/', $p ); }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { public $code; public $message; public $data; public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; } public function get_error_code() { return $this->code; } public function get_error_data( $k = null ) { return null === $k ? $this->data : ( $this->data[ $k ] ?? null ); } }
}

require_once CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-guard.php';

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

// ── (a) behaviour ───────────────────────────────────────────────────
ck( 'ok' === CrawlerToll_Guard::run( 'x', function () { return 'ok'; }, 'fb' ), 'run() returns the callable result when nothing throws' );
$boomer = function () { throw new RuntimeException( 'boom' ); };
ck( 'fb' === CrawlerToll_Guard::run( 'x', $boomer, 'fb' ), 'run() returns the fallback on exception' );
ck( 'fb2' === CrawlerToll_Guard::run( 'x', function () { return 1 % 0; }, 'fb2' ), 'run() also catches PHP Errors (DivisionByZeroError)' );
$entries = CrawlerToll_Guard::entries();
ck( 2 === count( $entries ), 'two distinct signatures recorded' );
ck( 'boom' === $entries[1]['message'] || 'boom' === $entries[0]['message'], 'message recorded' );
CrawlerToll_Guard::run( 'x', $boomer, null ); // same closure → same file:line → same signature
$entries = CrawlerToll_Guard::entries();
$boom = array_values( array_filter( $entries, function ( $e ) { return 'boom' === $e['message']; } ) );
ck( 2 === (int) $boom[0]['count'] && 2 === count( $entries ), 'repeat of the same error dedupes into count=2 (no new row)' );
ck( CrawlerToll_Guard::badge() === 3, 'badge counts every occurrence (3)' );
CrawlerToll_Guard::mark_seen();
ck( CrawlerToll_Guard::badge() === 0, 'mark_seen() zeroes the badge' );

for ( $i = 0; $i < 60; $i++ ) { CrawlerToll_Guard::store( array( 'label' => 'l', 'type' => 't', 'message' => "m$i", 'file' => 'f', 'line' => $i ) ); }
ck( count( CrawlerToll_Guard::entries() ) === CrawlerToll_Guard::MAX_ENTRIES, 'ring buffer capped at MAX_ENTRIES' );
CrawlerToll_Guard::clear();
ck( 0 === count( CrawlerToll_Guard::entries() ) && 0 === CrawlerToll_Guard::badge(), 'clear() empties log + badge' );

$w = CrawlerToll_Guard::wrap( function ( $v ) { throw new RuntimeException( 'x' ); }, 'w', CrawlerToll_Guard::FALLBACK_PASSTHROUGH );
ck( 'orig' === $w( 'orig' ), 'wrap(PASSTHROUGH) returns the first argument on failure' );
$w = CrawlerToll_Guard::wrap( function ( $v ) { throw new RuntimeException( 'x' ); }, 'w', CrawlerToll_Guard::FALLBACK_EMPTY );
ck( '' === $w( 'the sealed body' ), 'wrap(EMPTY) returns "" on failure — never the input body' );
$w = CrawlerToll_Guard::wrap( function ( $v ) { throw new RuntimeException( 'x' ); }, 'w', CrawlerToll_Guard::FALLBACK_VOID );
ck( null === $w( 'v' ), 'wrap(VOID) returns null on failure' );
$w = CrawlerToll_Guard::wrap( function ( $a, $b ) { return $a . $b; }, 'w' );
ck( 'ab' === $w( 'a', 'b' ), 'wrap() forwards all arguments when nothing throws' );
$r = CrawlerToll_Guard::rest( function ( $req ) { throw new RuntimeException( 'secret detail' ); }, 'rest.x' );
$out = $r( null );
ck( $out instanceof WP_Error && 'crawlertoll_internal' === $out->get_error_code() && 500 === $out->get_error_data( 'status' ), 'rest() maps a throw to a generic 500 WP_Error' );
ck( false === strpos( $out->message, 'secret detail' ), 'rest() error message does not leak internals' );

// ── (b) wiring ──────────────────────────────────────────────────────
$dir     = dirname( __DIR__ );
$main    = (string) file_get_contents( $dir . '/crawlertoll.php' );
$plugin  = (string) file_get_contents( $dir . '/includes/class-crawlertoll-plugin.php' );
$gate    = (string) file_get_contents( $dir . '/includes/class-crawlertoll-premium-gate.php' );
$admin   = (string) file_get_contents( $dir . '/admin/class-crawlertoll-admin.php' );
$view    = (string) file_get_contents( $dir . '/admin/views/settings.php' );
$buildsh = (string) file_get_contents( $dir . '/build.sh' );

ck( false !== strpos( $main, "includes/class-crawlertoll-guard.php" ) && false !== strpos( $main, 'CrawlerToll_Guard::register_shutdown_handler();' ), 'bootstrap loads the guard + registers the fatal shutdown handler' );
ck( false !== strpos( $plugin, "CrawlerToll_Guard::wrap( array( \$this, 'on_parse_request' ), 'enforce.parse_request', CrawlerToll_Guard::FALLBACK_VOID )" ), 'enforcement hook degrades OPEN' );
ck( false !== strpos( $plugin, "'discovery.robots_txt', CrawlerToll_Guard::FALLBACK_PASSTHROUGH" ), 'robots.txt filter passes through on failure' );
foreach ( array( 'rest_context_license', 'rest_stripe_intent', 'rest_stripe_confirm', 'rest_email_request', 'rest_email_verified', 'rest_realised', 'rest_revoke_pass' ) as $h ) {
	ck( false !== strpos( $plugin, "CrawlerToll_Guard::rest( array( \$this, '$h' )" ), "REST handler $h is guarded" );
}
foreach ( array( "'gate.the_content', CrawlerToll_Guard::FALLBACK_EMPTY", "'gate.excerpt', CrawlerToll_Guard::FALLBACK_EMPTY", "'gate.excerpt_rss', CrawlerToll_Guard::FALLBACK_EMPTY", "'gate.content_feed', CrawlerToll_Guard::FALLBACK_EMPTY", "'gate.seo_description', CrawlerToll_Guard::FALLBACK_EMPTY" ) as $needle ) {
	ck( false !== strpos( $gate, $needle ), "gate text filter fails EMPTY: $needle" );
}
ck( false !== strpos( $gate, "'gate.singular', CrawlerToll_Guard::FALLBACK_VOID" ) && false !== strpos( $gate, "'gate.structured_data', CrawlerToll_Guard::FALLBACK_VOID" ), 'gate actions fail VOID' );
ck( false !== strpos( $gate, "CrawlerToll_Guard::run( 'gate.rest'" ) && false !== strpos( $gate, 'private function rest_fallback' ), 'REST post filter strips content on failure (fail closed)' );
ck( false !== strpos( $admin, 'function render_error_notice' ) && false !== strpos( $admin, 'CrawlerToll_Guard::badge()' ), 'admin notice reads the badge' );
ck( false !== strpos( $admin, "admin_post_crawlertoll_clear_errors" ) && false !== strpos( $admin, "check_admin_referer( 'crawlertoll_clear_errors' )" ), 'clear-log handler is nonce-protected' );
ck( false !== strpos( $view, 'id="crawlertoll-errors"' ) && false !== strpos( $view, 'CrawlerToll_Guard::diagnostics()' ), 'settings shows the error log + diagnostics' );
ck( false === strpos( $buildsh, "'includes/class-crawlertoll-guard.php'" ) || false !== strpos( $buildsh, 'class-crawlertoll-guard.php' ), 'guard is not in the premium-only strip list' );
$guardSrc = (string) file_get_contents( $dir . '/includes/class-crawlertoll-guard.php' );
ck( ! preg_match( '/CrawlerToll_(DB|Pricing|Alerts|Logger|Provenance|CatalogueUpdater|Subscribers)\b/', $guardSrc ), 'guard is free-safe' );

echo "\n" . ( $fail ? "FAILED ($fail)" : 'ALL GUARD TESTS PASSED' ) . "\n";
exit( $fail ? 1 : 0 );
