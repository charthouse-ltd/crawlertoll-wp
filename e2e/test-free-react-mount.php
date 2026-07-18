<?php
/**
 * Free-build React mount proof. Boots the real WP where the *stripped free zip*
 * is the active plugin (Pro OFF) and asserts the React free-app is actually
 * wired into the settings page by real WordPress:
 *
 *   1. the settings page HTML carries the #crawlertoll-free-app mount node;
 *   2. enqueue_assets() (on the settings_page_crawlertoll hook) emits a
 *      type="module" <script> for the free-app bundle — the Vite ESM tag that
 *      silently fails if the type=module filter doesn't fire — plus the
 *      window.crawlertollFree data blob React reads;
 *   3. the bundle the manifest points at physically ships in the free zip.
 *
 * This proves WP emits the scaffolding and the asset is present. It does NOT run
 * the JS (no headless browser in this rig) — the pixel paint is the manual
 * click-through. The companion curl in run-free.sh proves the bundle is served.
 *
 * Usage: php test-free-react-mount.php <wp_dir>
 */

$wp_dir = rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( $wp_dir === '' ) {
	fwrite( STDERR, "usage: php test-free-react-mount.php <wp_dir>\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST'] = '127.0.0.1';
require_once $wp_dir . '/wp-load.php';
// render_page() calls settings_fields()/submit_button() — admin-only helpers a
// real wp-admin request has loaded but plain wp-load.php (CLI) does not. Pull in
// the admin includes so we exercise the genuine settings-page render path.
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

wp_set_current_user( 1 ); // admin created by install.php (has manage_options)

// The enqueuer class ships in the free build (not premium-only) — pin that first;
// everything else is moot if the bundle integration was stripped.
ck( class_exists( 'CrawlerToll_Vite' ), 'CrawlerToll_Vite present in free build' );

// 1. The settings page renders the mount node.
$admin = new CrawlerToll_Admin();
ob_start();
$admin->render_page();
$page_html = (string) ob_get_clean();
ck( strpos( $page_html, 'id="crawlertoll-free-app"' ) !== false, 'settings page HTML carries #crawlertoll-free-app mount node' );

// 2. enqueue_assets emits the module script + the data blob.
$admin->enqueue_assets( 'settings_page_crawlertoll' );
ob_start();
wp_print_scripts( 'crawlertoll-free-app' );
$script_html = (string) ob_get_clean();

ck( strpos( $script_html, 'type="module"' ) !== false, 'free-app <script> carries type="module" (Vite ESM tag)' );
ck( (bool) preg_match( '#src="[^"]*assets/app/free-app/[^"]*\.js#', $script_html ), 'module script points at the free-app bundle' );
ck( strpos( $script_html, 'window.crawlertollFree' ) !== false, 'window.crawlertollFree data blob inlined for React' );
ck( strpos( $script_html, '"botCount"' ) !== false, 'data blob carries the server-computed status (botCount)' );

// 3. The bundle the manifest references physically ships.
$manifest_path = CRAWLERTOLL_PLUGIN_DIR . 'assets/app/free-app/manifest.json';
$entry_ok      = false;
if ( file_exists( $manifest_path ) ) {
	$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
	$entry    = is_array( $manifest ) && isset( $manifest['src/free/main.tsx']['file'] ) ? $manifest['src/free/main.tsx']['file'] : '';
	$entry_ok = $entry !== '' && file_exists( CRAWLERTOLL_PLUGIN_DIR . 'assets/app/free-app/' . $entry );
}
ck( $entry_ok, 'free-app bundle from the manifest exists on disk (shipped in the free zip)' );

printf( "\nfree-react-mount: %d passed, %d failed\n", $pass, $fail );
exit( $fail === 0 ? 0 : 1 );
