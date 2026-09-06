<?php
/**
 * W0/W1/W2/W3 wiring guard (2026-09-06): no stubs, realised revenue, https
 * warning, refunds.
 *
 * (a) Every remote the plugin calls is the LIVE unlock service (no dead
 *     hosts: data.crawlertoll.com / schemas.crawlertoll.com are gone), and the
 *     catalogue updater accepts the versioned {bots:[…]} wrapper it serves.
 * (b) The dead v1 payment webhook is gone (no CRAWLERTOLL_WEBHOOK_SECRET, no
 *     /webhook/payment route).
 * (c) Realised revenue: registry client + Pro REST proxy + dashboard widget.
 * (d) https warning renders in the settings view; receipts show amounts, link
 *     to the Stripe payment / block explorer, and carry refund guidance +
 *     a Pro revoke action wired to the registry's DELETE /v1/sealed/pass/:id.
 *
 * Run: php tests/realised-wired.php   (exit 0 = pass, 1 = fail)
 */

$dir = dirname( __DIR__ );
$rd  = function ( $rel ) use ( $dir ) { return (string) file_get_contents( $dir . '/' . $rel ); };

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

$plugin   = $rd( 'includes/class-crawlertoll-plugin.php' );
$registry = $rd( 'includes/class-crawlertoll-registry.php' );
$updater  = $rd( 'includes/class-crawlertoll-catalogue-updater.php' );
$view     = $rd( 'admin/views/settings.php' );
$api      = $rd( 'ui/src/pro/api.ts' );
$types    = $rd( 'ui/src/pro/types.ts' );
$dash     = $rd( 'ui/src/pro/views/Dashboard.tsx' );

// (a) live remotes only
$shipped = '';
foreach ( glob( $dir . '/includes/*.php' ) as $f ) { $shipped .= (string) file_get_contents( $f ); }
foreach ( glob( $dir . '/admin/*.php' ) as $f ) { $shipped .= (string) file_get_contents( $f ); }
ck( false === strpos( $shipped, 'data.crawlertoll.com' ), 'no reference to the dead data.crawlertoll.com host' );
ck( false === strpos( $shipped, 'schemas.crawlertoll.com' ), 'no reference to the dead schemas.crawlertoll.com host' );
ck( false !== strpos( $updater, "https://registry.crawlertoll.com/v1/bots.json" ), 'catalogue updater fetches bots.json from the live unlock service' );
ck( false !== strpos( $updater, "\$decoded['bots']" ), 'catalogue updater accepts the versioned {bots:[…]} wrapper' );
ck( false !== strpos( $plugin, "https://registry.crawlertoll.com/schemas/context-license/v1.json" ), 'context-license.json $schema points at the live schema route' );

// (b) dead v1 path gone
ck( false === strpos( $plugin, 'webhook/payment' ) && false === strpos( $plugin, 'rest_webhook_payment' ) && false === strpos( $plugin, 'CRAWLERTOLL_WEBHOOK_SECRET' ), 'v1 payment webhook route + handler + secret constant removed' );

// (c) realised revenue
ck( false !== strpos( $registry, 'function unlock_summary' ) && false !== strpos( $registry, "/v1/sealed/summary?publisher=" ), 'registry client reads /v1/sealed/summary' );
ck( false !== strpos( $plugin, "'/realised'" ) && false !== strpos( $plugin, 'function rest_realised' ), 'Pro REST /realised route + handler' );
ck( false !== strpos( $plugin, "'crawlertoll_realised_' . \$period" ), 'realised summary cached per period' );
ck( false !== strpos( $api, 'fetchRealised' ) && false !== strpos( $types, 'interface RealisedResponse' ), 'pro app fetches realised revenue' );
ck( false !== strpos( $dash, 'label="Realised revenue"' ) && false !== strpos( $dash, 'RealisedSection' ), 'dashboard shows a Realised revenue KPI + by-rail table' );
ck( false !== strpos( $dash, 'Potential revenue (priced 402s)' ), '"potential" is labelled honestly next to realised' );

// (d) https + receipts + refunds
ck( false !== strpos( $view, "'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME )" ) && false !== strpos( $view, 'Your site address is not https' ), 'settings warns when the site is not https' );
ck( false !== strpos( $view, 'dashboard.stripe.com/payments/' ) && false !== strpos( $view, 'basescan.org/tx/' ), 'receipts link to the Stripe payment / on-chain tx' );
ck( false !== strpos( $view, "\$unlock['amount_micros']" ), 'receipts show the collected amount' );
ck( false !== strpos( $view, 'Refunds and disputes:' ), 'refund + dispute guidance on the receipts card' );
ck( false !== strpos( $view, 'ct-revoke-pass' ) && false !== strpos( $view, "receipts/revoke" ), 'Pro revoke action calls the REST revoke route' );
ck( false !== strpos( $registry, 'function revoke_pass' ) && false !== strpos( $registry, "'/v1/sealed/pass/' . \$pass_id" ) && false !== strpos( $registry, "'method'  => 'DELETE'" ), 'registry client revokes via DELETE /v1/sealed/pass/:id' );
ck( false !== strpos( $plugin, "'/receipts/revoke'" ) && false !== strpos( $plugin, 'function rest_revoke_pass' ), 'Pro REST /receipts/revoke route + handler' );

echo "\n" . ( $fail ? "FAILED ($fail)" : 'ALL REALISED-WIRED TESTS PASSED' ) . "\n";
exit( $fail ? 1 : 0 );
