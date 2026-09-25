<?php
/**
 * EU/UK right-of-withdrawal waiver (2026-09-25) — pure helpers + wiring.
 *
 * Run: php tests/waiver-wired.php   (exit 0 = pass, 1 = fail)
 */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['ct_tz'] = ''; $GLOBALS['ct_locale'] = 'en_US';
function get_option( $k, $d = false ) { return 'timezone_string' === $k ? $GLOBALS['ct_tz'] : $d; }
function get_locale() { return $GLOBALS['ct_locale']; }
function __( $t, $d = null ) { return $t; }
function crawlertoll_get_settings() { return array( 'withdrawal_waiver' => 'auto' ); }
$dir = dirname( __DIR__ );
require_once $dir . '/includes/functions-waiver.php';

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

// ── heuristic ───────────────────────────────────────────────────────
ck( crawlertoll_site_looks_european( 'Europe/Berlin', 'en_US' ), 'Europe/* timezone → European' );
ck( crawlertoll_site_looks_european( 'UTC', 'de_DE' ), 'de_DE locale → European' );
ck( crawlertoll_site_looks_european( '', 'en_GB' ), 'en_GB → European (UK has the same rule)' );
ck( crawlertoll_site_looks_european( '', 'pt_PT' ) && ! crawlertoll_site_looks_european( '', 'pt_BR' ), 'pt_PT yes, pt_BR no' );
ck( ! crawlertoll_site_looks_european( 'America/New_York', 'en_US' ), 'US site → not European' );
ck( ! crawlertoll_site_looks_european( '', 'en' ), 'bare language without country → not European' );

// ── modes ───────────────────────────────────────────────────────────
ck( true === crawlertoll_waiver_required( array( 'withdrawal_waiver' => 'on' ) ), "'on' → always required" );
$GLOBALS['ct_tz'] = 'Europe/Paris';
ck( false === crawlertoll_waiver_required( array( 'withdrawal_waiver' => 'off' ) ), "'off' → never, even on a European site" );
ck( true === crawlertoll_waiver_required( array( 'withdrawal_waiver' => 'auto' ) ), "'auto' on a European site → required" );
$GLOBALS['ct_tz'] = 'America/Chicago'; $GLOBALS['ct_locale'] = 'en_US';
ck( false === crawlertoll_waiver_required( array( 'withdrawal_waiver' => 'auto' ) ), "'auto' on a US site → not required" );
ck( false === crawlertoll_waiver_required( array() ), 'missing key behaves as auto (US site → not required)' );
$t = crawlertoll_waiver_text();
ck( strlen( $t ) < 480 && false !== stripos( $t, 'right of withdrawal' ) && false !== stripos( $t, 'immediately' ), 'consent text names immediate access + loss of the withdrawal right, fits Stripe metadata' );

// ── wiring ──────────────────────────────────────────────────────────
$rd = function ( $f ) use ( $dir ) { return (string) file_get_contents( $dir . '/' . $f ); };
$main = $rd( 'crawlertoll.php' ); $admin = $rd( 'admin/class-crawlertoll-admin.php' ); $view = $rd( 'admin/views/settings.php' );
$gate = $rd( 'includes/class-crawlertoll-premium-gate.php' ); $plug = $rd( 'includes/class-crawlertoll-plugin.php' ); $reg = $rd( 'includes/class-crawlertoll-registry.php' );
$app  = $rd( 'ui/src/unlock/App.tsx' ); $api = $rd( 'ui/src/unlock/api.ts' ); $pay = $rd( 'ui/src/unlock/payments.ts' ); $rcpt = $rd( 'ui/src/unlock/receipt.ts' );
ck( false !== strpos( $main, "'withdrawal_waiver'    => 'auto'" ) && false !== strpos( $main, 'includes/functions-waiver.php' ), 'default is auto; helpers loaded at bootstrap' );
ck( false !== strpos( $admin, "array( 'auto', 'on', 'off' )" ), 'setting sanitised to auto/on/off' );
ck( false !== strpos( $view, '[withdrawal_waiver]' ) && false !== strpos( $view, 'not legal advice' ), 'settings field present with honest help text' );
ck( 2 === substr_count( $gate, 'data-waiver="1" data-waiver-text="' ), 'both wall renderers emit the waiver flag + text' );
ck( false !== strpos( $plug, "'withdrawal_waiver_required'" ) && false !== strpos( $plug, "\$metadata['withdrawal_waiver_at']" ), 'card intent refused without consent; consent time stamped on the PaymentIntent' );
ck( false !== strpos( $plug, "\$pi['metadata']['withdrawal_waiver_at']" ), 'confirm reads the waiver from the VERIFIED intent, not the request' );
ck( false !== strpos( $reg, "\$body['withdrawal_waiver_at'] = (int) \$waiver_at" ), 'grant sends the waiver time to the unlock service' );
ck( false !== strpos( $app, 'checked={!!waiverAt}' ) && false === strpos( $app, 'useState<number | null>(Math' ), 'checkbox starts unticked (pre-ticked is not valid consent)' );
ck( false !== strpos( $app, 'waiverRequired && !waiverAt' ) && false !== strpos( $app, 'setWaiverNudge(true)' ), 'paid tiles refuse to start until consent is given' );
ck( false !== strpos( $app, '{ withdrawalWaiver: waiverRequired && !!waiverAt }' ) && false !== strpos( $pay, 'createStripeIntent(restBase, contentId, tierId, !!opts?.withdrawalWaiver)' ), 'card path sends the consent to the site' );
ck( false !== strpos( $app, 'payX402(contentId, offer, tile.tier, waiverRequired ? waiverAt : null)' ) && false !== strpos( $api, 'body.withdrawal_waiver_at = waiverAt' ), 'USDC path sends the consent time to the unlock service' );
ck( false !== strpos( $app, 'Save your receipt' ) && false !== strpos( $rcpt, 'Immediate access and right of withdrawal' ), 'reader can save a receipt that states the consent (durable medium)' );
$view_rows = substr( $view, strpos( $view, 'foreach ( $recent_unlocks as $unlock )' ) );
ck( false !== strpos( $view_rows, 'number_format_i18n( $unlock_amount / 1000000, 2 )' ) && false !== strpos( $view_rows, 'href="<?php echo esc_url( $unlock_link ); ?>"' ) && false !== strpos( $view_rows, 'class="button-link ct-revoke-pass"' ), 'receipt rows render amount, payment link and the Pro revoke button' );
ck( false !== strpos( $view_rows, 'Withdrawal waived %s' ), 'receipt rows show when a withdrawal waiver was given' );

echo "\n" . ( $fail ? "FAILED ($fail)" : 'ALL WAIVER TESTS PASSED' ) . "\n";
exit( $fail ? 1 : 0 );
