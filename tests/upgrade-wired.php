<?php
/**
 * Version upgrader + uninstall completeness (2026-09-16).
 *
 * (a) CrawlerToll_Upgrader::maybe_upgrade() runs the activation-equivalent
 *     tasks exactly once per plugin version (auto-updates skip the activation
 *     hook): settings row, Pro log table (dbDelta), rewrite flush, marker.
 *     A throwing step is recorded by the guard, never fatal, and leaves the
 *     marker unset so the next request retries.
 * (b) Wiring: required by the bootstrap, called on plugins_loaded, marked on
 *     activation, kept in the free build.
 * (c) uninstall.php lists EVERY option / transient / cron hook / post-meta key
 *     the plugin source writes (scanned here, so a new key cannot be forgotten).
 *
 * Run: php tests/upgrade-wired.php   (exit 0 = pass, 1 = fail)
 */

$dir = dirname( __DIR__ );
$tmp = sys_get_temp_dir() . '/ct-upgrade-test-' . getmypid();
@mkdir( $tmp . '/wp-admin/includes', 0777, true );
file_put_contents( $tmp . '/wp-admin/includes/upgrade.php', "<?php\n" );
define( 'ABSPATH', $tmp . '/' );
define( 'CRAWLERTOLL_PLUGIN_DIR', $dir . '/' );
define( 'CRAWLERTOLL_VERSION', '9.9.9-test' );
define( 'CRAWLERTOLL_OPTION_KEY', 'crawlertoll_settings' );

$GLOBALS['ct_opts'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['ct_opts'] ) ? $GLOBALS['ct_opts'][ $k ] : $d; }
function add_option( $k, $v ) { if ( array_key_exists( $k, $GLOBALS['ct_opts'] ) ) { return false; } $GLOBALS['ct_opts'][ $k ] = $v; return true; }
function update_option( $k, $v, $a = null ) { $GLOBALS['ct_opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['ct_opts'][ $k ] ); return true; }
function wp_normalize_path( $p ) { return str_replace( '\\', '/', $p ); }
function crawlertoll_default_settings() { return array( 'enabled' => true, 'rail' => 'x402' ); }
$GLOBALS['ct_dbdelta'] = 0; $GLOBALS['ct_dbdelta_throw'] = false;
function dbDelta( $sql ) { $GLOBALS['ct_dbdelta']++; if ( $GLOBALS['ct_dbdelta_throw'] ) { throw new RuntimeException( 'simulated dbDelta failure' ); } return array(); }
class CT_Fake_WPDB { public $prefix = 'wp_'; public function get_charset_collate() { return ''; } }
$GLOBALS['wpdb'] = new CT_Fake_WPDB();

require_once $dir . '/includes/class-crawlertoll-guard.php';
require_once $dir . '/includes/class-crawlertoll-db.php';
require_once $dir . '/includes/class-crawlertoll-upgrader.php';

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

// ── (a) behaviour ───────────────────────────────────────────────────
$GLOBALS['ct_opts']['rewrite_rules'] = array( '^stale$' => 'index.php?p=1' );
ck( true === CrawlerToll_Upgrader::maybe_upgrade(), 'first load on a new version runs the upgrade' );
ck( isset( $GLOBALS['ct_opts']['crawlertoll_settings']['enabled'] ), 'settings row created when missing' );
ck( 1 === $GLOBALS['ct_dbdelta'], 'Pro log table dbDelta ran once' );
ck( ! isset( $GLOBALS['ct_opts']['rewrite_rules'] ), 'rewrite rules flushed (deleted → regenerated on next parse_request)' );
ck( '9.9.9-test' === get_option( 'crawlertoll_schema_version' ), 'schema marker set to the plugin version' );
ck( false === CrawlerToll_Upgrader::maybe_upgrade() && 1 === $GLOBALS['ct_dbdelta'], 'second load is a no-op (marker matches)' );

$GLOBALS['ct_opts']['crawlertoll_settings'] = array( 'enabled' => false, 'rail' => 'stripe', 'price_micros' => 7777 );
update_option( 'crawlertoll_schema_version', '1.0.0' );
CrawlerToll_Upgrader::maybe_upgrade();
ck( 7777 === $GLOBALS['ct_opts']['crawlertoll_settings']['price_micros'] && 'stripe' === $GLOBALS['ct_opts']['crawlertoll_settings']['rail'], 'existing settings are preserved on upgrade (never overwritten with defaults)' );

// failure path: throw inside a step → recorded, not fatal, retried next time
update_option( 'crawlertoll_schema_version', '1.0.0' );
$GLOBALS['ct_dbdelta_throw'] = true;
$ran = CrawlerToll_Upgrader::maybe_upgrade();
ck( false === $ran, 'a throwing step returns false instead of fataling' );
ck( '1.0.0' === get_option( 'crawlertoll_schema_version' ), 'marker left unset after a failure (retry next request)' );
$entries = CrawlerToll_Guard::entries();
ck( 1 === count( $entries ) && 'upgrade' === $entries[0]['label'] && false !== strpos( $entries[0]['message'], 'simulated dbDelta' ), 'failure recorded in the error log under label "upgrade"' );
$GLOBALS['ct_dbdelta_throw'] = false;
ck( true === CrawlerToll_Upgrader::maybe_upgrade() && '9.9.9-test' === get_option( 'crawlertoll_schema_version' ), 'retry succeeds once the cause is gone' );

// ── (b) wiring ──────────────────────────────────────────────────────
$main  = (string) file_get_contents( $dir . '/crawlertoll.php' );
$build = (string) file_get_contents( $dir . '/build.sh' );
ck( false !== strpos( $main, "includes/class-crawlertoll-upgrader.php" ), 'bootstrap requires the upgrader' );
ck( preg_match( '/function crawlertoll_bootstrap\(\) \{.*?CrawlerToll_Upgrader::maybe_upgrade\(\);/s', $main ) === 1, 'maybe_upgrade() runs first thing in crawlertoll_bootstrap (plugins_loaded)' );
ck( false !== strpos( $main, "update_option( CrawlerToll_Upgrader::OPTION, CRAWLERTOLL_VERSION );" ), 'activation hook marks the schema current' );
ck( false === strpos( $build, "'includes/class-crawlertoll-upgrader.php'" ) && false !== strpos( $build, 'includes/class-crawlertoll-upgrader.php' ), 'upgrader ships in the free build and is on the free-safe list' );
$src = (string) file_get_contents( $dir . '/includes/class-crawlertoll-upgrader.php' );
ck( ! preg_match( '/CrawlerToll_(Pricing|Alerts|Logger|Provenance|CatalogueUpdater|Subscribers)\b/', $src ) && false !== strpos( $src, "class_exists( 'CrawlerToll_DB' )" ), 'upgrader is free-safe (DB only behind class_exists)' );

// ── (c) uninstall completeness ──────────────────────────────────────
$uninstall = (string) file_get_contents( $dir . '/uninstall.php' );
$files = array_merge( array( $dir . '/crawlertoll.php' ), glob( $dir . '/includes/*.php' ), glob( $dir . '/admin/*.php' ), glob( $dir . '/admin/views/*.php' ) );
$keys = array( 'option' => array(), 'transient' => array(), 'cron' => array(), 'meta' => array() );
foreach ( $files as $f ) {
	$c = (string) file_get_contents( $f );
	preg_match_all( "/(?:add|update|delete|get)_option\(\s*'(crawlertoll_[a-z0-9_]+)'/", $c, $m ); $keys['option'] = array_merge( $keys['option'], $m[1] );
	preg_match_all( "/const [A-Z_]+\s*=\s*'(crawlertoll_[a-z0-9_]+)'/", $c, $m );            $keys['option'] = array_merge( $keys['option'], $m[1] );
	preg_match_all( "/set_transient\(\s*'(crawlertoll_[a-z0-9_]+)'/", $c, $m );               $keys['transient'] = array_merge( $keys['transient'], $m[1] );
	preg_match_all( "/wp_schedule(?:_single)?_event\([^;]*?'(crawlertoll_[a-z0-9_]+)'/s", $c, $m ); $keys['cron'] = array_merge( $keys['cron'], $m[1] );
	preg_match_all( "/const [A-Z_]*META[A-Z_]*\s*=\s*'(_crawlertoll_[a-z0-9_]+)'/", $c, $m );  $keys['meta'] = array_merge( $keys['meta'], $m[1] );
}
$keys['option'][] = 'crawlertoll_settings';
$missing = array();
foreach ( $keys as $kind => $list ) {
	foreach ( array_unique( $list ) as $k ) {
		if ( '_' === substr( $k, -1 ) ) { continue; } // prefix of short-lived dynamic keys (e.g. per-client throttles)
		if ( false === strpos( $uninstall, "'" . $k . "'" ) ) { $missing[] = "$kind:$k"; }
	}
}
ck( empty( $missing ), 'uninstall.php removes every key the source writes' . ( $missing ? ' — missing: ' . implode( ', ', $missing ) : '' ) );
ck( false !== strpos( $uninstall, "'crawlertoll_log'" ) && false !== strpos( $uninstall, "'crawlertoll_subscribers'" ), 'uninstall drops both tables' );
ck( false !== strpos( $uninstall, "remove_data_on_uninstall" ), 'uninstall is opt-in (remove_data_on_uninstall)' );

// cleanup
@unlink( $tmp . '/wp-admin/includes/upgrade.php' ); @rmdir( $tmp . '/wp-admin/includes' ); @rmdir( $tmp . '/wp-admin' ); @rmdir( $tmp );
echo "\n" . ( $fail ? "FAILED ($fail)" : 'ALL UPGRADE TESTS PASSED' ) . "\n";
exit( $fail ? 1 : 0 );
