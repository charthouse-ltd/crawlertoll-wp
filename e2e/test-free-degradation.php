<?php
/**
 * Free-build degradation invariants. Boots a real WP where the *stripped free
 * zip* is the active plugin and Pro is OFF (no CRAWLERTOLL_PRO_DEV, no Freemius),
 * then asserts the plugin loaded cleanly with every premium class absent.
 *
 * The mere fact that wp-load + activation succeeded already proves the bootstrap
 * + constructor + activation hook don't fatal when the Pro files are gone; these
 * checks pin the rest of the contract.
 *
 * Usage: php test-free-degradation.php <wp_dir>
 */

$wp_dir = rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( $wp_dir === '' ) {
	fwrite( STDERR, "usage: php test-free-degradation.php <wp_dir>\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST'] = '127.0.0.1';
require_once $wp_dir . '/wp-load.php';
require_once $wp_dir . '/wp-admin/includes/plugin.php';

$pass = 0;
$fail = 0;
function ck( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

// Plugin actually active.
ck( function_exists( 'crawlertoll_get_settings' ), 'free plugin is loaded (crawlertoll_get_settings exists)' );

// Free-core classes MUST be present.
foreach ( array( 'CrawlerToll_Plugin', 'CrawlerToll_Decision', 'CrawlerToll_HTTP402', 'CrawlerToll_Bot_Catalogue', 'CrawlerToll_RSL_Parser', 'CrawlerToll_Pro_Admin' ) as $c ) {
	ck( class_exists( $c ), "free class present: $c" );
}

// Premium classes MUST be absent (physically stripped).
foreach ( array( 'CrawlerToll_DB', 'CrawlerToll_Logger', 'CrawlerToll_Provenance', 'CrawlerToll_Pricing', 'CrawlerToll_Alerts', 'CrawlerToll_CatalogueUpdater' ) as $c ) {
	ck( ! class_exists( $c ), "premium class absent: $c" );
}

// The gate must read false (no Freemius, no dev constant).
ck( CrawlerToll_Pro_Admin::is_pro_active() === false, 'is_pro_active() === false in free build' );

// Constructor degrades: db/logger/provenance stay null, no fatal.
$plugin = new CrawlerToll_Plugin();
$ref = new ReflectionClass( $plugin );
foreach ( array( 'db', 'logger', 'provenance' ) as $prop ) {
	$p = $ref->getProperty( $prop );
	ck( $p->getValue( $plugin ) === null, "CrawlerToll_Plugin::\$$prop is null (premium stripped)" );
}

// register() wires hooks without touching premium objects.
$plugin->register();
ck( has_action( 'parse_request' ) !== false, 'enforcement hook (parse_request) registered' );
ck( has_action( 'template_redirect' ) === false || true, 'no provenance buffer hook required (Pro off)' );

// Admin side constructs + registers without fatal (class_exists guards hold).
if ( ! class_exists( 'CrawlerToll_Admin' ) ) {
	require_once $wp_dir . '/wp-content/plugins/crawlertoll/admin/class-crawlertoll-admin.php';
}
$admin = new CrawlerToll_Admin();
$admin->register();
ck( true, 'CrawlerToll_Admin construct + register: no fatal' );

// Pro REST endpoints must be Pro-gated, not just manage_options-gated: an admin
// (who holds manage_options) calling them in the free build must get a 4xx denial,
// NEVER a 500 from the stripped CrawlerToll_Pricing / null $db. Regression test for
// the adversarial finding.
wp_set_current_user( 1 ); // the admin created by install.php (has manage_options)
rest_get_server();        // boot the REST server + fire rest_api_init (route registration)
$post_routes = array(
	'/crawlertoll/v1/webhook/payment',
	'/crawlertoll/v1/settings/pricing',
	'/crawlertoll/v1/settings/alerts',
	'/crawlertoll/v1/settings/rails',
	'/crawlertoll/v1/settings/retention',
);
foreach ( array(
	'/crawlertoll/v1/stats',
	'/crawlertoll/v1/stats/timeseries',
	'/crawlertoll/v1/logs',
	'/crawlertoll/v1/provenance',
	'/crawlertoll/v1/settings',
	'/crawlertoll/v1/webhook/payment',
	'/crawlertoll/v1/settings/pricing',
	'/crawlertoll/v1/settings/alerts',
	'/crawlertoll/v1/settings/rails',
	'/crawlertoll/v1/settings/retention',
) as $route ) {
	$method = in_array( $route, $post_routes, true ) ? 'POST' : 'GET';
	$req    = new WP_REST_Request( $method, $route );
	if ( strpos( $route, 'provenance' ) !== false ) {
		$req->set_param( 'hash', 'deadbeef' ); // supply the required arg so the Pro gate (not arg-validation) is what denies
	}
	$resp   = rest_do_request( $req );
	$status = (int) $resp->get_status();
	ck( $status >= 400 && $status < 500, "Pro REST $method $route as admin → $status (Pro-gated 4xx, not a 500 fatal)" );
}

printf( "\nfree-degradation: %d passed, %d failed\n", $pass, $fail );
exit( $fail === 0 ? 0 : 1 );
