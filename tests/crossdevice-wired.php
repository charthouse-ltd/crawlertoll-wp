<?php
/**
 * W6 (QR + code cross-device transfer) + W7 (onboarding checklist) wiring guard.
 * Run: php tests/crossdevice-wired.php   (exit 0 = pass, 1 = fail)
 */
$dir = dirname( __DIR__ );
$rd  = function ( $rel ) use ( $dir ) { return (string) file_get_contents( $dir . '/' . $rel ); };
$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

$api  = $rd( 'ui/src/unlock/api.ts' );
$app  = $rd( 'ui/src/unlock/App.tsx' );
$pkg  = $rd( 'ui/package.json' );
$reg  = $rd( '../crawlertoll-registry/src/index.js' );
$pass = $rd( '../crawlertoll-registry/src/passes.js' );
$rl   = $rd( '../crawlertoll-registry/src/ratelimit.js' );
$adm  = $rd( 'admin/class-crawlertoll-admin.php' );
$view = $rd( 'admin/views/settings.php' );
$dep  = $rd( 'e2e/deploy-qa2.sh' );

// W6
ck( false !== strpos( $api, 'export async function linkPass' ) && false !== strpos( $api, '/v1/sealed/pass/${passId}/link' ), 'unlock app can issue a transfer code for its pass' );
ck( false !== strpos( $api, 'export async function claimPassLink' ) && false !== strpos( $api, '/v1/sealed/pass/claim' ), 'unlock app can claim a code' );
ck( false !== strpos( $app, 'import qrcode from "qrcode-generator"' ) && false !== strpos( $pkg, '"qrcode-generator"' ), 'QR code rendered client-side (qrcode-generator, MIT)' );
ck( false !== strpos( $app, '?ct_link=${code}' ) && false !== strpos( $app, 'searchParams.get("ct_link")' ), 'QR encodes the article URL with ct_link; landing claims it silently' );
ck( false !== strpos( $app, 'Read it on another device' ) && false !== strpos( $app, 'Enter the code from your other device' ), 'both sides of the transfer have UI' );
ck( false !== strpos( $app, 'cekRead(contentId)?.p' ), 'transfer uses the settlement pass cached with the key' );
ck( false !== strpos( $app, '"unlock_renewal");\n      return true;' ) || false !== strpos( $app, ', "unlock_renewal");' ), 'a claimed transfer counts as a renewal in the funnel' );
ck( false !== strpos( $reg, '"/v1/sealed/pass/claim"' ) && false !== strpos( $reg, 'path.endsWith("/link")' ), 'registry routes link + claim' );
ck( false !== strpos( $pass, 'LINK_TTL_SECONDS = 600' ) && false !== strpos( $pass, 'expirationTtl: LINK_TTL_SECONDS' ) && false !== strpos( $pass, 'SEALED_KV.delete(key)' ), 'codes expire in 10 minutes and are single-use' );
ck( false !== strpos( $rl, 'path === "/v1/sealed/pass/claim"' ), 'claim endpoint is rate-limited (brute-force surface)' );

// W7
ck( false !== strpos( $adm, 'public static function onboarding_items' ), 'onboarding items builder exists' );
foreach ( array( 'Site runs on https', 'Unlock service reachable', 'A way to get paid', 'A premium post is sealed', 'Test the wall as a reader' ) as $label ) {
	ck( false !== strpos( $adm, "'$label'" ), "onboarding check: $label" );
}
ck( false !== strpos( $view, 'id="crawlertoll-onboarding"' ) && false !== strpos( $view, '$ct_done < $ct_total' ), 'checklist renders at the top and hides itself when complete' );
ck( false !== strpos( $view, "Fix this" ), 'every open item deep-links to its fix' );

// W9
ck( false !== strpos( $dep, 'QA2_SSH' ) && false !== strpos( $dep, 'crawlertoll.prev' ) && is_executable( $dir . '/e2e/deploy-qa2.sh' ), 'deploy-qa2.sh ships the premium build with a rollback copy' );

echo "\n" . ( $fail ? "FAILED ($fail)" : 'ALL CROSS-DEVICE/ONBOARDING TESTS PASSED' ) . "\n";
exit( $fail ? 1 : 0 );
