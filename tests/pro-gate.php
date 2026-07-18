<?php
/**
 * Part B gate-guard (2026-06-20).
 *
 * The monetization stop-ship: Pro features must run ONLY behind a real,
 * Freemius-backed is_pro_active() gate — never bare class_exists(). This test
 * asserts the structure that closes the "Pro runs for free" leak.
 *
 * Run: php tests/pro-gate.php   (exit 0 = pass, 1 = fail)
 */

$dir      = dirname( __DIR__ );
$boot     = (string) file_get_contents( $dir . '/crawlertoll.php' );
$proadmin = (string) file_get_contents( $dir . '/admin/class-crawlertoll-pro-admin.php' );
$plugin   = (string) file_get_contents( $dir . '/includes/class-crawlertoll-plugin.php' );

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

// A — Freemius SDK is wired in the main plugin file.
ck( strpos( $boot, 'function crawlertoll_fs' ) !== false, 'Freemius helper crawlertoll_fs() present in crawlertoll.php' );
ck( strpos( $boot, 'fs_dynamic_init' ) !== false, 'fs_dynamic_init() init present' );

// Isolate the bootstrap function body.
$bstart = strpos( $boot, 'function crawlertoll_bootstrap' );
$bend   = strpos( $boot, "add_action( 'plugins_loaded'", $bstart === false ? 0 : $bstart );
$body   = ( $bstart !== false && $bend !== false ) ? substr( $boot, $bstart, $bend - $bstart ) : '';

// B — background Pro features are gated on is_pro_active(), not bare class_exists().
ck( strpos( $body, 'is_pro_active' ) !== false, 'bootstrap gates background Pro features on is_pro_active()' );
ck( strpos( $body, "class_exists( 'CrawlerToll_Alerts' )" ) === false, 'bootstrap no longer registers Alerts on bare class_exists()' );
ck( strpos( $body, "class_exists( 'CrawlerToll_CatalogueUpdater' )" ) === false, 'bootstrap no longer registers CatalogueUpdater on bare class_exists()' );

// C — is_pro_active() is Freemius-backed, not a dev-constant-only stub.
ck( strpos( $proadmin, 'can_use_premium_code' ) !== false, 'is_pro_active() consults Freemius can_use_premium_code()' );
ck( strpos( $proadmin, 'license validation against crawlertoll.com API' ) === false, 'stale custom-license-API TODO removed' );

// D — the free enforcement hot path no longer does a per-request table create.
ck( strpos( $plugin, 'maybe_create_table' ) === false, 'per-request maybe_create_table() removed from the runtime class' );

exit( $fail === 0 ? 0 : 1 );
