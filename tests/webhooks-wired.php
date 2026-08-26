<?php
/**
 * Unlock-webhooks wiring guard (spec: docs/specs/unlock-webhooks-v1.md, 2026-08-26).
 *
 * The Pro webhooks tab configures the registry-side unlock webhook (endpoint URL
 * + signing secret), fires test events, and lists recent deliveries. This guards
 * the registry client methods, the tab renderer, the view, and the nav wiring.
 *
 * Run: php tests/webhooks-wired.php   (exit 0 = pass, 1 = fail)
 */

$dir      = dirname( __DIR__ );
$admin    = (string) file_get_contents( $dir . '/admin/class-crawlertoll-admin.php' );
$proadmin = (string) file_get_contents( $dir . '/admin/class-crawlertoll-pro-admin.php' );
$registry = (string) file_get_contents( $dir . '/includes/class-crawlertoll-registry.php' );
$view     = $dir . '/admin/views/pro-webhooks.php';

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

// A — registry client methods for config / test / deliveries.
ck( strpos( $registry, 'function save_webhook_config' ) !== false, 'save_webhook_config() exists on the registry client' );
ck( strpos( $registry, "'/v1/webhooks/config'" ) !== false, 'client targets /v1/webhooks/config' );
ck( strpos( $registry, 'function test_webhook' ) !== false, 'test_webhook() exists on the registry client' );
ck( strpos( $registry, 'function webhook_deliveries' ) !== false, 'webhook_deliveries() exists on the registry client' );
ck( strpos( $registry, 'Bearer ' ) !== false, 'client authenticates with the registry write token' );

// B — Pro tab renderer + nonce'd actions + local secret store.
ck( strpos( $proadmin, 'function render_webhooks_tab' ) !== false, 'render_webhooks_tab() exists on the Pro admin' );
ck( strpos( $proadmin, 'crawlertoll_save_webhook' ) !== false, 'webhook save runs through its own nonce' );
ck( strpos( $proadmin, 'crawlertoll_test_webhook' ) !== false, 'webhook test runs through its own nonce' );
ck( strpos( $proadmin, "'crawlertoll_webhook'" ) !== false, 'endpoint + secret persist in the crawlertoll_webhook option' );

// C — nav wiring (Pro-gated like the other Pro tabs).
ck( strpos( $admin, "'webhooks'" ) !== false, 'Webhooks tab registered in the admin nav' );
ck( strpos( $admin, 'render_webhooks_tab' ) !== false, 'Webhooks tab routed in the tab switch' );

// D — the view exists and shows the signature-verification guidance.
ck( file_exists( $view ), 'pro-webhooks.php view present' );
$viewsrc = (string) file_get_contents( $view );
ck( strpos( $viewsrc, 'X-CrawlerToll-Signature' ) !== false, 'view documents the signature header for receiver verification' );

exit( $fail === 0 ? 0 : 1 );
