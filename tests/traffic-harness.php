<?php
/**
 * Traffic visibility (W5, 2026-09-06) — pure classification + counters + wiring.
 *
 * Run: php tests/traffic-harness.php   (exit 0 = pass, 1 = fail)
 */

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['ct_opts'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['ct_opts'] ) ? $GLOBALS['ct_opts'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['ct_opts'][ $k ] = $v; return true; }
function is_noindex_stub() { return false; }

$dir = dirname( __DIR__ );
require_once $dir . '/includes/class-crawlertoll-safemode.php';
require_once $dir . '/includes/class-crawlertoll-traffic.php';

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

// ── classify ────────────────────────────────────────────────────────
$bot = array( 'bot' => array( 'name' => 'GPTBot' ), 'reasons' => array( 'ua-match:GPTBot' ) );
$none = array( 'bot' => null, 'reasons' => array( 'not-a-bot' ) );
$safe = array( 'bot' => null, 'reasons' => array( 'safe-mode' ) );
ck( 'ai_crawler' === CrawlerToll_Traffic::classify( 'Mozilla/5.0 (compatible; GPTBot/1.2)', $bot ), 'catalogue match → ai_crawler (decision wins over UA shape)' );
ck( 'search_engine' === CrawlerToll_Traffic::classify( 'Mozilla/5.0 (compatible; Googlebot/2.1)', $safe ), 'safe-mode reason → search_engine' );
ck( 'search_engine' === CrawlerToll_Traffic::classify( 'Mozilla/5.0 (compatible; bingbot/2.0)', $none ), 'safelist UA → search_engine even without the reason' );
ck( 'browser' === CrawlerToll_Traffic::classify( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', $none ), 'Safari → browser' );
ck( 'browser' === CrawlerToll_Traffic::classify( 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36', $none ), 'Android Chrome → browser' );
ck( 'automation' === CrawlerToll_Traffic::classify( 'python-requests/2.32.0', $none ), 'python-requests → automation' );
ck( 'automation' === CrawlerToll_Traffic::classify( 'curl/8.4.0', $none ), 'curl → automation' );
ck( 'automation' === CrawlerToll_Traffic::classify( 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/125.0 Safari/537.36', $none ), 'HeadlessChrome → automation' );
ck( 'automation' === CrawlerToll_Traffic::classify( 'Mozilla/5.0 (compatible; SomeUnknownBot/1.0; +https://example.org/bot)', $none ), 'generic "bot" outside the catalogue → automation' );
ck( 'automation' === CrawlerToll_Traffic::classify( '', $none ), 'empty UA → automation' );
ck( 'automation' === CrawlerToll_Traffic::classify( 'Go-http-client/2.0', $none ), 'Go http client → automation' );
ck( 'automation' === CrawlerToll_Traffic::classify( 'MyTool/1.0', $none ), 'no engine token at all → automation' );

// ── counters ────────────────────────────────────────────────────────
CrawlerToll_Traffic::count_request( 'browser', true, 'Mozilla/5.0 …' );
CrawlerToll_Traffic::count_request( 'browser', false, 'Mozilla/5.0 …' );
CrawlerToll_Traffic::count_request( 'automation', true, 'python-requests/2.32.0' );
CrawlerToll_Traffic::count_request( 'automation', false, 'python-requests/2.32.0' );
CrawlerToll_Traffic::count_request( 'ai_crawler', true, 'GPTBot/1.2' );
CrawlerToll_Traffic::count_request( 'nonsense', true, 'x' );
ck( true === CrawlerToll_Traffic::count_event( 'wall_shown' ), 'known wall event counted' );
ck( true === CrawlerToll_Traffic::count_event( 'unlock_stripe' ), 'unlock_stripe counted' );
ck( true === CrawlerToll_Traffic::count_event( 'unlock_meter' ), 'unlock_meter counted' );
ck( false === CrawlerToll_Traffic::count_event( 'evil' ), 'unknown event rejected' );
$s = CrawlerToll_Traffic::summary( 7 );
ck( 2 === $s['classes']['browser'] && 2 === $s['classes']['automation'] && 1 === $s['classes']['ai_crawler'] && 0 === $s['classes']['search_engine'], 'class totals (unknown class ignored)' );
ck( 3 === $s['funnel']['sealed_views'] && 1 === $s['funnel']['walls_shown'] && 2 === $s['funnel']['unlocks'] && 1 === $s['funnel']['paid_unlocks'], 'funnel totals: 3 sealed views, 1 wall, 2 unlocks (1 paid)' );
ck( 1 === $s['funnel']['by_rail']['stripe'] && 1 === $s['funnel']['by_rail']['meter'], 'unlocks split by rail' );
ck( abs( $s['funnel']['unlock_rate'] - 2 ) < 0.001 && abs( $s['funnel']['wall_rate'] - 0.333 ) < 0.001, 'rates computed (walls/views, unlocks/walls)' );
ck( 'python-requests/2.32.0' === $s['top_automation'][0]['ua'] && 2 === $s['top_automation'][0]['count'], 'top undeclared automation UA remembered with count' );
ck( 1 === count( $s['series'] ) && isset( $s['series'][0]['day'] ), 'one daily series point' );

// window trim: 70 fake days → 60 kept
$rows = array();
for ( $i = 0; $i < 70; $i++ ) { $rows[ gmdate( 'Y-m-d', time() - $i * 86400 ) ] = array( 'browser' => 1 ); }
update_option( CrawlerToll_Traffic::OPTION, $rows, false );
CrawlerToll_Traffic::count_request( 'browser' );
ck( count( get_option( CrawlerToll_Traffic::OPTION ) ) === CrawlerToll_Traffic::WINDOW_DAYS, 'rolling window trimmed to WINDOW_DAYS' );
$s30 = CrawlerToll_Traffic::summary( 30 );
ck( 31 === $s30['classes']['browser'], 'summary(30) sums exactly the last 30 days (30 seeded + 1 counted today)' );

// ── wiring ──────────────────────────────────────────────────────────
$main   = (string) file_get_contents( $dir . '/crawlertoll.php' );
$plugin = (string) file_get_contents( $dir . '/includes/class-crawlertoll-plugin.php' );
$view   = (string) file_get_contents( $dir . '/admin/views/settings.php' );
$uiApi  = (string) file_get_contents( $dir . '/ui/src/unlock/api.ts' );
$uiApp  = (string) file_get_contents( $dir . '/ui/src/unlock/App.tsx' );
$proApi = (string) file_get_contents( $dir . '/ui/src/pro/api.ts' );
$dash   = (string) file_get_contents( $dir . '/ui/src/pro/views/Dashboard.tsx' );
$build  = (string) file_get_contents( $dir . '/build.sh' );
ck( false !== strpos( $main, 'includes/class-crawlertoll-traffic.php' ), 'bootstrap loads the traffic class' );
ck( false !== strpos( $plugin, "CrawlerToll_Traffic::count_request( CrawlerToll_Traffic::classify( \$user_agent, \$decision ), \$ct_premium_id > 0, \$user_agent )" ), 'every front-end request is counted (premium posts included) inside the guard' );
ck( false !== strpos( $plugin, "'/wall-event'" ) && false !== strpos( $plugin, 'function rest_wall_event' ), 'public wall-event beacon route' );
ck( false !== strpos( $plugin, "'/traffic'" ) && false !== strpos( $plugin, 'function rest_traffic' ), 'Pro traffic summary route' );
ck( false !== strpos( $uiApi, 'export function wallEvent' ), 'unlock app has the beacon helper' );
foreach ( array( '"wall_shown"', '"wall_unavailable"', '"unlock_stripe"', '"unlock_x402"', '"unlock_meter"', '"unlock_email"', '"unlock_renewal"', '"unlock_cache"' ) as $ev ) {
	ck( false !== strpos( $uiApp, $ev ), "unlock app reports $ev" );
}
ck( false !== strpos( $proApi, 'fetchTraffic' ) && false !== strpos( $dash, 'TrafficSection' ) && false !== strpos( $dash, 'Undeclared automation' ), 'Pro dashboard renders the traffic block' );
ck( false !== strpos( $view, 'id="crawlertoll-traffic"' ) && false !== strpos( $view, 'CrawlerToll_Traffic::summary( 7 )' ), 'free settings shows the 7-day traffic card' );
ck( false === strpos( $build, "'includes/class-crawlertoll-traffic.php'" ), 'traffic class is not stripped from the free build' );
$src = (string) file_get_contents( $dir . '/includes/class-crawlertoll-traffic.php' );
ck( ! preg_match( '/CrawlerToll_(DB|Pricing|Alerts|Logger|Provenance|CatalogueUpdater|Subscribers)\b/', $src ), 'traffic class is free-safe' );

echo "\n" . ( $fail ? "FAILED ($fail)" : 'ALL TRAFFIC TESTS PASSED' ) . "\n";
exit( $fail ? 1 : 0 );
