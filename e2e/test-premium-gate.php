<?php
/**
 * WS3 slice 3.3 leak probe — real WordPress, real content surfaces. Creates a
 * premium post whose body (after <!--more-->) contains a unique sentinel, then
 * asserts the sentinel NEVER reaches any non-editor surface (the_content,
 * excerpt, feed, REST, structured-data page), the sealed marker + paywall JSON-LD
 * ARE present on the singular page, an editor sees the full body (bypass), and a
 * non-premium post is untouched. The registry is pointed at a dead port so
 * seal-on-serve fail-closes instantly (no 15s hang) — proving the gate degrades
 * to preview-only, never to the body, when the escrow is unreachable.
 *
 * Usage: php test-premium-gate.php <wp_dir>
 */

define( 'CRAWLERTOLL_REGISTRY_URL', 'http://127.0.0.1:9' ); // dead port → register_sealed fails fast → fail-closed

$wp_dir = rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( $wp_dir === '' ) {
	fwrite( STDERR, "usage: php test-premium-gate.php <wp_dir>\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST'] = '127.0.0.1';
require_once $wp_dir . '/wp-load.php';
require_once $wp_dir . '/wp-admin/includes/admin.php';

$pass = 0;
$fail = 0;
function ck( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) { printf( "  \033[32mPASS\033[0m %s\n", $label ); $pass++; }
	else { printf( "  \033[31mFAIL\033[0m %s\n", $label ); $fail++; }
}

$SENT = 'SEALEDBODYSENTINEL';
$PREV = 'FREEPREVIEWTEXT';

ck( class_exists( 'CrawlerToll_Premium_Gate' ), 'premium gate present (free build)' );

$pid = wp_insert_post( array(
	'post_title'   => 'Premium WS3 probe',
	'post_status'  => 'publish',
	'post_content' => "<p>{$PREV} is the free teaser.</p>\n<!--more-->\n<p>{$SENT} is the paid body.</p>",
	'post_author'  => 1,
) );
update_post_meta( $pid, '_crawlertoll_premium', 1 );

$npid = wp_insert_post( array(
	'post_title'   => 'Non-premium control',
	'post_status'  => 'publish',
	'post_content' => "<p>{$PREV} free.</p>\n<!--more-->\n<p>{$SENT} also free.</p>",
	'post_author'  => 1,
) );

// ── SINGULAR page (anon): main query so the locked section + JSON-LD render ──
wp_set_current_user( 0 );
query_posts( 'p=' . $pid ); // sets the MAIN query → is_singular() true
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // make is_main_query() true (query_posts alone doesn't)
$single_html = '';
while ( have_posts() ) {
	the_post();
	$single_html = apply_filters( 'the_content', get_the_content() );
}
ob_start();
( new CrawlerToll_Premium_Gate() )->emit_structured_data(); // is_singular() true under query_posts
$ld = ob_get_clean();

// The front-end unlock app (3.4) enqueues on the sealed singular page.
( new CrawlerToll_Premium_Gate() )->enqueue_unlock_app();
ob_start();
wp_print_scripts( 'crawlertoll-unlock-app' );
$unlock_js = ob_get_clean();
wp_reset_query();

ck( strpos( $single_html, $SENT ) === false, 'singular the_content: sealed body ABSENT' );
ck( strpos( $single_html, $PREV ) !== false, 'singular the_content: preview present' );
ck( strpos( $single_html, 'ct-sealed-body' ) !== false, 'singular the_content: sealed marker rendered' );
ck( strpos( $single_html, $SENT ) === false && strpos( $single_html, 'application/ct-sealed' ) === false, 'fail-closed: no blob embedded when registry unreachable (still no body)' );
ck( strpos( $ld, '"isAccessibleForFree":false' ) !== false, 'paywall JSON-LD: isAccessibleForFree=false' );
ck( strpos( $ld, 'ct-sealed-body' ) !== false, 'paywall JSON-LD: hasPart cssSelector matches the marker' );
ck( strpos( $unlock_js, 'type="module"' ) !== false && strpos( $unlock_js, 'unlock-app' ) !== false, 'unlock app enqueued (type=module) on the sealed page' );
ck( strpos( $unlock_js, 'window.crawlertollUnlock' ) !== false && strpos( $unlock_js, 'registryBase' ) !== false, 'unlock REST seam (window.crawlertollUnlock + registryBase) inlined' );

// ── per-surface anon probes ──
function loop_render( $id, $filter, $arg = null ) {
	global $post;
	$post = get_post( $id );
	setup_postdata( $post );
	$out = ( 'the_content_feed' === $filter )
		? apply_filters( 'the_content_feed', get_post_field( 'post_content', $id ), 'rss2' )
		: ( 'excerpt' === $filter ? get_the_excerpt( $id ) : apply_filters( $filter, get_post_field( 'post_content', $id ) ) );
	wp_reset_postdata();
	return $out;
}

ck( strpos( loop_render( $pid, 'excerpt' ), $SENT ) === false, 'excerpt: sealed body ABSENT' );
ck( strpos( loop_render( $pid, 'the_content_feed' ), $SENT ) === false, 'feed content:encoded: sealed body ABSENT' );

rest_get_server();
$resp = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $pid ) );
$d    = $resp->get_data();
$cr   = isset( $d['content']['rendered'] ) ? $d['content']['rendered'] : '';
$er   = isset( $d['excerpt']['rendered'] ) ? $d['excerpt']['rendered'] : '';
ck( strpos( $cr, $SENT ) === false, 'REST content.rendered: sealed body ABSENT' );
ck( strpos( $er, $SENT ) === false, 'REST excerpt.rendered: sealed body ABSENT' );
ck( ! empty( $d['content']['protected'] ), 'REST content.protected = true' );

// ── in-loop raw access (the_post neutralizes $GLOBALS['post']) ──
// A BLOCK-split premium post (no <!--more-->) so WP core's own more-teaser does
// NOT mask the result — the gate's neutralization is what must hide the body.
$bid = wp_insert_post( array(
	'post_title'   => 'Block premium probe',
	'post_status'  => 'publish',
	'post_content' => "<!-- wp:paragraph --><p>{$PREV} teaser block.</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>{$SENT} paid block.</p><!-- /wp:paragraph -->",
	'post_author'  => 1,
) );
update_post_meta( $bid, '_crawlertoll_premium', 1 );

$gtc_inloop = '';
$blk_inloop = '';
$q = new WP_Query( array( 'p' => $bid ) );
while ( $q->have_posts() ) {
	$q->the_post();
	$gtc_inloop = get_the_content();                                              // no id → $GLOBALS['post'], neutralized by the_post
	$blk_inloop = function_exists( 'do_blocks' ) ? do_blocks( get_the_content() ) : $gtc_inloop;
}
wp_reset_postdata();
ck( strpos( $gtc_inloop, $SENT ) === false, 'in-loop get_the_content(): sealed body ABSENT (global neutralized)' );
ck( strpos( $blk_inloop, $SENT ) === false, 'in-loop do_blocks(get_the_content()): sealed body ABSENT' );
// NOTE: an explicit get_post($id)->post_content render OUTSIDE the loop is the
// documented raw-access residual (universal filter-paywall ceiling) — not asserted.

// ── non-premium control: untouched (full body served) ──
ck( strpos( loop_render( $npid, 'the_content' ), $SENT ) !== false, 'non-premium the_content: full body served (no regression)' );

// ── editor bypass: full body (clean the request cache the anon phase neutralized) ──
clean_post_cache( $pid );
wp_set_current_user( 1 ); // admin can edit_post
ck( strpos( loop_render( $pid, 'the_content' ), $SENT ) !== false, 'editor the_content: full body served (bypass)' );

printf( "\npremium-gate: %d passed, %d failed\n", $pass, $fail );
exit( $fail === 0 ? 0 : 1 );
