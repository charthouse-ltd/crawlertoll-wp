<?php
// Local CLI harness for ct_sealed_v1 — run: php tests/sealed-harness.php [gen]
// Not loaded by WordPress; define ABSPATH to satisfy the no-direct-access guard.
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-crawlertoll-sealed.php';

$fail = 0;
function check( $cond, $msg ) {
	global $fail;
	if ( $cond ) {
		echo "PASS: $msg\n";
	} else {
		echo "FAIL: $msg\n";
		$fail++;
	}
}

// Round-trip.
$pt = 'The quick brown fox — ünïcödé ✓';
list( $cek, $blob ) = CrawlerToll_Sealed::seal( $pt, 'post/42' );
check( 'ct_sealed_v1' === $blob['magic'], 'blob magic' );
check( 'A256GCM' === $blob['alg'], 'blob alg' );
check( 12 === strlen( base64_decode( $blob['nonce'] ) ), 'nonce is 12 bytes' );
check( 32 === strlen( base64_decode( $cek ) ), 'cek is 32 bytes' );
check( CrawlerToll_Sealed::unseal( $blob, $cek ) === $pt, 'round-trip plaintext' );

// Wrong CEK fails.
$threw = false;
try {
	CrawlerToll_Sealed::unseal( $blob, base64_encode( random_bytes( 32 ) ) );
} catch ( Exception $e ) {
	$threw = true;
}
check( $threw, 'wrong CEK throws' );

// Tampered AAD (content_id) fails.
$blob2               = $blob;
$blob2['content_id'] = 'post/99';
$threw               = false;
try {
	CrawlerToll_Sealed::unseal( $blob2, $cek );
} catch ( Exception $e ) {
	$threw = true;
}
check( $threw, 'tampered content_id (AAD) throws' );

// Pure-logic check for the gate helper (no WP runtime needed).
require __DIR__ . '/../includes/class-crawlertoll-sealed-gate.php';
check(
	CrawlerToll_Sealed_Gate::build_content_id( 'example.com', 42 ) === 'example.com/post/42',
	'content_id format'
);

// Generate the PHP->JS parity fixture when called with `gen`.
if ( in_array( 'gen', $argv, true ) ) {
	$plaintext  = '{"vehicle":"BMW 320i","isv_eur":6769.83}';
	$content_id = 'ct_php_seal_parity';
	list( $cek_b64, $b ) = CrawlerToll_Sealed::seal( $plaintext, $content_id );
	$out  = array(
		'plaintext_utf8' => $plaintext,
		'cek'            => $cek_b64,
		'blob'           => $b,
	);
	$path = __DIR__ . '/php_seal_parity.json';
	file_put_contents( $path, json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	echo "wrote $path\n";
}

exit( 0 === $fail ? 0 : 1 );
