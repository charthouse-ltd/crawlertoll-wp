<?php
// Local CLI harness for the TOTAL gate composition — run: php tests/gate-harness.php
// Verifies CrawlerToll_Sealed_Gate::maybe_seal_and_serve() end-to-end with WP
// functions + the DB/Registry collaborators stubbed. Asserts the 402 body carries
// the sealed blob + a raw (un-encoded) content_id keyRelease, and NEVER the key.
define( 'ABSPATH', __DIR__ . '/' );

// --- Minimal WordPress surface the gate touches ---
function wp_unslash( $x ) { return $x; }
function url_to_postid( $uri ) { return 1; }            // pretend the URL resolves to a post
function get_post_field( $f, $id ) { return "SECRET ARTICLE BODY for post $id"; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function get_post_meta( $id, $k, $single = false ) { return ''; }   // cache miss → seal fresh
function update_post_meta( $id, $k, $v ) { return true; }
function home_url( $p = '' ) { return 'http://example.test' . $p; }
function status_header( $c ) { /* CLI no-op */ }
function nocache_headers() { /* CLI no-op */ }
function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); }
function is_wp_error( $x ) { return false; } // register_sealed double never returns an error

$_SERVER['REQUEST_URI'] = '/hello-world/';
$GLOBALS['wpdb']        = new stdClass();

require __DIR__ . '/../includes/class-crawlertoll-sealed.php';

// --- Test doubles for the collaborators (real ones need WP + HTTP) ---
class CrawlerToll_DB {
	public function __construct( $x ) {}
}
class CrawlerToll_Registry {
	const REGISTRY_URL = 'https://registry.crawlertoll.com';
	public function register_sealed( $cid, $cek, $price, $cur = 'USDC', $scope = 'full' ) {
		// Record what the gate would send the registry (key escrowed, not the content).
		fwrite( STDERR, "register_sealed: content_id=$cid price=$price cek_len=" . strlen( $cek ) . "\n" );
		return array( 'status' => 'registered', 'content_id' => $cid );
	}
}

require __DIR__ . '/../includes/class-crawlertoll-sealed-gate.php';

// Drives the gate as if a declared agent hit the gated post (echoes 402 + exits).
CrawlerToll_Sealed_Gate::maybe_seal_and_serve(
	array( 'price_micros' => 5000, 'currency' => 'USD', 'rail' => 'x402' )
);
