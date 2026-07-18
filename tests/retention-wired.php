<?php
/**
 * Log-retention wiring guard (§2.2, 2026-06-20).
 *
 * CrawlerToll_DB::purge_old() existed but had no caller — logs grew unbounded.
 * This asserts the daily purge cron + callback + UI control that drive it.
 *
 * Run: php tests/retention-wired.php   (exit 0 = pass, 1 = fail)
 */

$dir      = dirname( __DIR__ );
$boot     = (string) file_get_contents( $dir . '/crawlertoll.php' );
$proadmin = (string) file_get_contents( $dir . '/admin/class-crawlertoll-pro-admin.php' );
$view     = (string) file_get_contents( $dir . '/admin/views/pro-logs.php' );

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

ck( strpos( $boot, "'retention_days'" ) !== false, 'retention_days default present' );
ck( strpos( $boot, "wp_schedule_event( time(), 'daily', 'crawlertoll_purge_logs' )" ) !== false, 'daily purge cron scheduled' );
ck( strpos( $boot, 'function crawlertoll_run_log_purge' ) !== false, 'purge callback defined' );
ck( strpos( $boot, 'purge_old' ) !== false, 'callback calls purge_old()' );
ck( strpos( $boot, "wp_clear_scheduled_hook( 'crawlertoll_purge_logs' )" ) !== false, 'deactivation clears the purge cron' );
ck( strpos( $proadmin, 'crawlertoll_save_retention' ) !== false, 'Logs tab handles the retention save' );
ck( strpos( $view, 'ct_retention_days' ) !== false, 'retention control rendered in the Logs view' );

exit( $fail === 0 ? 0 : 1 );
