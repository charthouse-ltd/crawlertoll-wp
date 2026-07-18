<?php
/**
 * Pro-build React mount proof. Runs in the Pro-unlocked e2e (CRAWLERTOLL_PRO_DEV
 * on) and asserts the React Pro app is wired into the revenue tab by real WP:
 *
 *   1. the revenue tab HTML carries the #crawlertoll-pro-app mount node;
 *   2. enqueue_pro_assets() emits a type="module" <script> for the pro-app
 *      bundle plus the window.crawlertollPro REST seam (restUrl + nonce) — the
 *      app can't reach the cookie-authed Pro routes without that nonce;
 *   3. the bundle the manifest points at physically exists;
 *   4. GET /crawlertoll/v1/stats actually answers 200 with a `current` payload
 *      for an authed admin — the data the dashboard renders.
 *
 * Proves WP emits the scaffolding, exposes the seam, and the route is live. It
 * does NOT execute the JS (no headless browser) — pixel paint is the manual pass.
 *
 * Usage: php test-pro-react-mount.php <wp_dir>
 */

$wp_dir = rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( $wp_dir === '' ) {
	fwrite( STDERR, "usage: php test-pro-react-mount.php <wp_dir>\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST'] = '127.0.0.1';
require_once $wp_dir . '/wp-load.php';
if ( ! function_exists( 'submit_button' ) ) {
	require_once $wp_dir . '/wp-admin/includes/admin.php';
}

$pass = 0;
$fail = 0;
function ck( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

wp_set_current_user( 1 ); // admin created by install.php (manage_options)

ck( CrawlerToll_Pro_Admin::is_pro_active(), 'Pro is active (CRAWLERTOLL_PRO_DEV) — preconditions hold' );

// 1. The revenue tab renders the pro mount node tagged data-view=dashboard.
$_GET['ct_tab'] = 'revenue';
$admin = new CrawlerToll_Admin();
$admin->register(); // wires $pro_admin so the Pro tabs route to the Pro views
ob_start();
$admin->render_page();
$page_html = (string) ob_get_clean();
ck( strpos( $page_html, 'id="crawlertoll-pro-app"' ) !== false, 'revenue tab HTML carries #crawlertoll-pro-app mount node' );
ck( strpos( $page_html, 'data-view="dashboard"' ) !== false, 'revenue mount tagged data-view="dashboard"' );

// 1b. The logs tab mounts the same app tagged data-view=logs, with the
// retention form still server-rendered above it (a write, no REST endpoint).
$_GET['ct_tab'] = 'logs';
$admin2 = new CrawlerToll_Admin();
$admin2->register();
ob_start();
$admin2->render_page();
$logs_html = (string) ob_get_clean();
ck( strpos( $logs_html, 'data-view="logs"' ) !== false, 'logs tab mounts pro-app tagged data-view="logs"' );
ck( strpos( $logs_html, 'crawlertoll_retention_nonce' ) !== false, 'retention save form still server-rendered (fallback) on the logs tab' );

// 1c. The three form tabs each mount the pro-app tagged with their own view.
foreach ( array( 'pricing', 'alerts', 'rails' ) as $tab ) {
	$_GET['ct_tab'] = $tab;
	$a = new CrawlerToll_Admin();
	$a->register();
	ob_start();
	$a->render_page();
	$h = (string) ob_get_clean();
	ck( strpos( $h, 'data-view="' . $tab . '"' ) !== false, "$tab tab mounts pro-app tagged data-view=\"$tab\"" );
}

// 2. enqueue_pro_assets emits the module script + the REST seam.
global $wpdb;
$pro = new CrawlerToll_Pro_Admin( new CrawlerToll_DB( $wpdb ) );
$pro->enqueue_pro_assets( 'settings_page_crawlertoll' );
ob_start();
wp_print_scripts( 'crawlertoll-pro-app' );
$script_html = (string) ob_get_clean();

ck( strpos( $script_html, 'type="module"' ) !== false, 'pro-app <script> carries type="module" (Vite ESM tag)' );
ck( (bool) preg_match( '#src="[^"]*assets/app/pro-app/[^"]*\.js#', $script_html ), 'module script points at the pro-app bundle' );
ck( strpos( $script_html, 'window.crawlertollPro' ) !== false, 'window.crawlertollPro REST seam inlined' );
ck( strpos( $script_html, '"restUrl"' ) !== false, 'seam carries the REST base url' );
ck( strpos( $script_html, '"nonce"' ) !== false, 'seam carries an X-WP-Nonce' );

// 3. The bundle the manifest references physically ships.
$manifest_path = CRAWLERTOLL_PLUGIN_DIR . 'assets/app/pro-app/manifest.json';
$entry_ok      = false;
if ( file_exists( $manifest_path ) ) {
	$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
	$entry    = is_array( $manifest ) && isset( $manifest['src/pro/main.tsx']['file'] ) ? $manifest['src/pro/main.tsx']['file'] : '';
	$entry_ok = $entry !== '' && file_exists( CRAWLERTOLL_PLUGIN_DIR . 'assets/app/pro-app/' . $entry );
}
ck( $entry_ok, 'pro-app bundle from the manifest exists on disk' );

// 4. The data route the dashboard fetches actually answers for an authed admin.
rest_get_server();
$resp = rest_do_request( new WP_REST_Request( 'GET', '/crawlertoll/v1/stats' ) );
$code = (int) $resp->get_status();
$body = $resp->get_data();
ck( 200 === $code, "GET /crawlertoll/v1/stats as admin → $code (200 expected)" );
ck( is_array( $body ) && array_key_exists( 'current', $body ), '/stats payload carries `current` (totals/top_bots/top_paths)' );

// 5. The logs route the browser fetches also answers for an authed admin.
$lresp = rest_do_request( new WP_REST_Request( 'GET', '/crawlertoll/v1/logs' ) );
$lcode = (int) $lresp->get_status();
$lbody = $lresp->get_data();
ck( 200 === $lcode, "GET /crawlertoll/v1/logs as admin → $lcode (200 expected)" );
ck( is_array( $lbody ) && array_key_exists( 'entries', $lbody ) && array_key_exists( 'total', $lbody ), '/logs payload carries `entries` + `total`' );

// 6. The timeseries route the dashboard trend charts fetch also answers.
$tresp = rest_do_request( new WP_REST_Request( 'GET', '/crawlertoll/v1/stats/timeseries' ) );
$tcode = (int) $tresp->get_status();
$tbody = $tresp->get_data();
ck( 200 === $tcode, "GET /crawlertoll/v1/stats/timeseries as admin → $tcode (200 expected)" );
ck( is_array( $tbody ) && array_key_exists( 'days', $tbody ) && isset( $tbody['period']['from'] ), '/stats/timeseries payload carries `period` + `days`' );

// 7. The Pro-settings write API (backs the React forms) round-trips for an
//    authed admin: GET loads, POST sanitises + persists, GET reflects it.
$gs  = rest_do_request( new WP_REST_Request( 'GET', '/crawlertoll/v1/settings' ) );
$gsb = $gs->get_data();
ck( 200 === (int) $gs->get_status(), 'GET /crawlertoll/v1/settings as admin → 200' );
ck( is_array( $gsb ) && isset( $gsb['alerts'], $gsb['meta']['rail_options'], $gsb['retention_days'] ), '/settings carries alerts + rail_options meta + retention' );

$pr = new WP_REST_Request( 'POST', '/crawlertoll/v1/settings/pricing' );
$pr->set_body_params( array( 'rules' => array( array( 'path' => '/api/*', 'price_micros' => 12345, 'currency' => 'eur' ) ) ) );
$prb = rest_do_request( $pr )->get_data();
ck( isset( $prb['path_pricing'][0] )
	&& '/api/*' === $prb['path_pricing'][0]['path']
	&& 12345 === (int) $prb['path_pricing'][0]['price_micros']
	&& 'EUR' === $prb['path_pricing'][0]['currency'],
	'POST /settings/pricing sanitises (currency upcased) + echoes the rule' );

$after = rest_do_request( new WP_REST_Request( 'GET', '/crawlertoll/v1/settings' ) )->get_data();
ck( ! empty( $after['path_pricing'] ) && 12345 === (int) $after['path_pricing'][0]['price_micros'], 'pricing write PERSISTED (GET /settings reflects it)' );

$rr = new WP_REST_Request( 'POST', '/crawlertoll/v1/settings/retention' );
$rr->set_body_params( array( 'days' => 45 ) );
$rrb = rest_do_request( $rr )->get_data();
ck( is_array( $rrb ) && 45 === (int) $rrb['retention_days'], 'POST /settings/retention persists days=45' );

$ar = new WP_REST_Request( 'POST', '/crawlertoll/v1/settings/alerts' );
$ar->set_body_params( array( 'daily' => true, 'weekly' => false, 'spike' => true, 'email' => 'ops@example.com' ) );
$arb = rest_do_request( $ar )->get_data();
ck( isset( $arb['alerts'] ) && true === $arb['alerts']['daily'] && false === $arb['alerts']['weekly'] && 'ops@example.com' === $arb['alerts']['email'], 'POST /settings/alerts persists toggles + recipient' );

// Rails: a valid override is kept; an invalid rail value is dropped (whitelist).
$rl = new WP_REST_Request( 'POST', '/crawlertoll/v1/settings/rails' );
$rl->set_body_params( array( 'overrides' => array( 'GPTBot' => 'stripe-acp', 'BadBot' => 'not-a-rail' ) ) );
$rlb = rest_do_request( $rl )->get_data();
ck( isset( $rlb['rail_overrides']['GPTBot'] ) && 'stripe-acp' === $rlb['rail_overrides']['GPTBot'] && ! isset( $rlb['rail_overrides']['BadBot'] ), 'POST /settings/rails keeps valid override, drops non-whitelisted rail' );

printf( "\npro-react-mount: %d passed, %d failed\n", $pass, $fail );
exit( $fail === 0 ? 0 : 1 );
