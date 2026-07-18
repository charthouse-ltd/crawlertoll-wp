<?php
/**
 * Email-alerts wiring guard (§2.3, 2026-06-20).
 *
 * CrawlerToll_Alerts has full daily/weekly/spike logic + a real get_alert_email()
 * (the v0.2.0 readiness audit wrongly called it a fatal), but the alerts_* and
 * alert_email settings had no defaults and no UI, so the cron callbacks always
 * short-circuited. This guards the settings + Alerts tab that make them fire.
 *
 * Run: php tests/alerts-wired.php   (exit 0 = pass, 1 = fail)
 */

$dir      = dirname( __DIR__ );
$boot     = (string) file_get_contents( $dir . '/crawlertoll.php' );
$admin    = (string) file_get_contents( $dir . '/admin/class-crawlertoll-admin.php' );
$proadmin = (string) file_get_contents( $dir . '/admin/class-crawlertoll-pro-admin.php' );
$alerts   = (string) file_get_contents( $dir . '/includes/class-crawlertoll-alerts.php' );
$view     = $dir . '/admin/views/pro-alerts.php';

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

// A — the settings the cron callbacks read now exist as defaults.
ck( strpos( $boot, "'alerts_daily'" ) !== false, 'alerts_daily default present' );
ck( strpos( $boot, "'alerts_weekly'" ) !== false, 'alerts_weekly default present' );
ck( strpos( $boot, "'alerts_spike'" ) !== false, 'alerts_spike default present' );
ck( strpos( $boot, "'alert_email'" ) !== false, 'alert_email default present' );

// B — an Alerts tab exposes those settings.
ck( strpos( $admin, "'alerts'" ) !== false, 'Alerts tab registered in the admin nav' );
ck( strpos( $proadmin, 'function render_alerts_tab' ) !== false, 'render_alerts_tab() exists on the Pro admin' );
ck( strpos( $proadmin, 'crawlertoll_save_alerts' ) !== false, 'Alerts tab saves through its own nonce' );
ck( file_exists( $view ), 'pro-alerts.php view present' );

// C — get_alert_email() really is defined (audit claimed it was a fatal).
ck( strpos( $alerts, 'function get_alert_email' ) !== false, 'get_alert_email() is defined (no fatal)' );

exit( $fail === 0 ? 0 : 1 );
