<?php
// Local CLI harness for CrawlerToll_Cut::split() — run: php tests/cut-harness.php
// Pure splitter (no WP runtime needed). The MASTER INVARIANT — preview.marker.body
// === raw, byte-for-byte — is asserted for EVERY fixture; specific cases pin the
// cut-point rule, the get_extended losslessness fix, and the safe-forward guards.
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-crawlertoll-cut.php';

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

// Split + assert the master invariant; returns the result for further checks.
function inv( $raw, $label ) {
	$r = CrawlerToll_Cut::split( $raw );
	check( $r['preview'] . $r['marker'] . $r['body'] === $raw, "INVARIANT (preview.marker.body===raw): $label" );
	return $r;
}

// preview must not end inside an open '<...' or '[...' token (no mid-tag leak).
function preview_token_clean( $preview ) {
	$lt = strrpos( $preview, '<' );
	$gt = strrpos( $preview, '>' );
	if ( false !== $lt && ( false === $gt || $gt < $lt ) ) {
		return false;
	}
	$lb = strrpos( $preview, '[' );
	$rb = strrpos( $preview, ']' );
	if ( false !== $lb && ( false === $rb || $rb < $lb ) ) {
		return false;
	}
	return true;
}

// ── 1. get_extended losslessness regression (the bug that drove the design) ──
$raw = "  Intro paragraph.  <!--more Keep reading-->  Paid body bytes.  ";
$r   = inv( $raw, 'get_extended-lossy fixture (whitespace + custom more text)' );
check( '<!--more Keep reading-->' === $r['marker'], 'custom more-text captured verbatim into marker' );
check( strpos( $r['preview'], 'more' ) === false || strpos( $r['preview'], '<!--more' ) === false, 'marker text absent from preview' );
check( strpos( $r['body'], '<!--more' ) === false, 'marker absent from body' );
check( '  Intro paragraph.  ' === $r['preview'], 'leading/trailing whitespace preserved in preview (no get_extended trim)' );
check( '  Paid body bytes.  ' === $r['body'], 'whitespace preserved in body (no get_extended trim)' );

// ── 2. plain <!--more--> ──
$r = inv( 'Intro<!--more-->Body', 'plain more marker' );
check( 'more' === $r['mode'] && 'Intro' === $r['preview'] && 'Body' === $r['body'] && $r['has_body'], 'more: preview/body split + has_body' );

// ── 3. marker at byte 0 ──
$r = inv( '<!--more-->All paid', 'marker at byte 0' );
check( '' === $r['preview'] && 'All paid' === $r['body'], 'marker at 0: empty preview, all body' );

// ── 4. marker at end ──
$r = inv( 'Only intro<!--more-->', 'marker at end' );
check( '' === $r['body'] && false === $r['has_body'], 'marker at end: empty body, has_body=false' );

// ── 5. multiple markers: only the FIRST cuts ──
$r = inv( 'A<!--more-->B<!--more-->C', 'multiple markers' );
check( 'A' === $r['preview'] && 'B<!--more-->C' === $r['body'], 'only first marker cuts; later marker stays in body' );

// ── 6. no marker, multi-paragraph classic HTML → paragraph mode ──
$r = inv( "<p>First.</p>\n<p>Second.</p>\n<p>Third.</p>", 'classic multi-paragraph' );
check( 'paragraph' === $r['mode'] && $r['has_body'], 'classic: paragraph mode, body non-empty' );
check( '<p>First.</p>' === $r['preview'], 'classic: preview is the first </p> unit' );

// ── 7. degenerate one-liner (no boundary) → whole, nothing to seal ──
$r = inv( 'Just one short line, no boundary at all.', 'one-liner' );
check( 'whole' === $r['mode'] && '' === $r['body'] && false === $r['has_body'], 'one-liner: whole, has_body=false (caller must refuse to seal)' );

// ── 8. empty + whitespace-only ──
$r = inv( '', 'empty string' );
check( '' === $r['preview'] && false === $r['has_body'], 'empty: no crash, has_body=false' );
$r = inv( "   \n   ", 'whitespace-only' );
check( false === $r['has_body'], 'whitespace-only: has_body=false' );

// ── 9. Gutenberg: first top-level block (a nested group) stays WHOLE ──
$raw = '<!-- wp:group --><!-- wp:paragraph --><p>A</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>B</p><!-- /wp:paragraph --><!-- /wp:group --><!-- wp:paragraph --><p>C</p><!-- /wp:paragraph -->';
$r   = inv( $raw, 'gutenberg nested group' );
check( 'block' === $r['mode'], 'gutenberg: block mode' );
check( strpos( $r['preview'], '>A<' ) !== false && strpos( $r['preview'], '>B<' ) !== false, 'preview holds the WHOLE group (A and B), nested blocks intact' );
check( strpos( $r['preview'], '>C<' ) === false && strpos( $r['body'], '>C<' ) !== false, 'trailing block (C) is in the body, not the preview' );

// ── 10. self-closing first block is depth-neutral and ends immediately ──
$raw = '<!-- wp:spacer {"height":"40px"} /--><!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->';
$r   = inv( $raw, 'self-closing first block' );
check( 'block' === $r['mode'] && strpos( $r['preview'], 'wp:spacer' ) !== false && strpos( $r['preview'], 'wp:paragraph' ) === false, 'self-closing spacer ends the first block' );

// ── 11. <!--more--> INSIDE a block: honored byte-exactly (more wins) ──
$raw = '<!-- wp:paragraph --><p>Intro<!--more-->Paid</p><!-- /wp:paragraph -->';
$r   = inv( $raw, 'more inside a block' );
check( 'more' === $r['mode'] && strpos( $r['body'], 'Paid' ) !== false && strpos( $r['preview'], 'Paid' ) === false, 'more inside block: body holds Paid, preview does not' );

// ── 12. safe-forward: blank line inside a tag attribute → past the tag ──
$raw = "<div data=\"a\n\nb\">visible</div>\n\nPaid body.";
$r   = inv( $raw, 'blank line inside a tag attribute' );
check( preview_token_clean( $r['preview'] ), 'safe-forward: preview does not end mid-tag' );

// ── 13. safe-forward: blank line inside a shortcode → past the ']' ──
$raw = "intro [gallery ids=\"1\n\n2\"] visible\n\nPaid body.";
$r   = inv( $raw, 'blank line inside a shortcode' );
check( preview_token_clean( $r['preview'] ), 'safe-forward: preview does not end mid-shortcode' );

// ── 14. multibyte/UTF-8 round-trips and never bisects a codepoint ──
$raw = "Café ☕ intro paragraph.\n\nPaid ünïcödé ✓ body 🚀.";
$r   = inv( $raw, 'utf-8 content' );
check( mb_check_encoding( $r['preview'], 'UTF-8' ) && mb_check_encoding( $r['body'], 'UTF-8' ), 'utf-8: both halves are valid UTF-8 (no codepoint bisected)' );

// ── 15. CRLF blank line ──
$raw = "Intro line.\r\n\r\nPaid body.";
$r   = inv( $raw, 'CRLF blank line' );
check( 'paragraph' === $r['mode'] && strpos( $r['body'], 'Paid body.' ) !== false, 'CRLF: blank-line boundary detected' );

// ── 16. plain text, multiple paragraphs ──
$r = inv( "Para one.\n\nPara two.\n\nPara three.", 'plain text paragraphs' );
check( 'paragraph' === $r['mode'] && $r['has_body'], 'plain text: paragraph mode, body non-empty' );

// ── 17. one giant single block, no second block → nothing to seal ──
$raw = '<!-- wp:paragraph --><p>' . str_repeat( 'x', 4000 ) . '</p><!-- /wp:paragraph -->';
$r   = inv( $raw, 'single giant block' );
check( false === $r['has_body'], 'single block: block integrity wins, has_body=false' );

// ════════════════════════════════════════════════════════════════════════
// Red-team regression fixtures (adversarial review, 2026-06-29). The byte
// invariant CANNOT catch an over-reveal (a forward cut just relabels paid bytes
// as 'preview'), so these plant a SECRETPAID sentinel deep in the body and assert
// it never reaches the preview when sealing.
// ════════════════════════════════════════════════════════════════════════

// Plant a sentinel in the paid body; it must be absent from preview when has_body.
function no_leak( $raw, $sentinel, $label ) {
	$r = inv( $raw, $label );
	check( ! $r['has_body'] || strpos( $r['preview'], $sentinel ) === false, "NO-LEAK ($sentinel not in preview when sealing): $label" );
	return $r;
}

// HIGH (was a real leak): an unbalanced '[' in the free intro must NOT drag the paid body into preview.
$r = no_leak( "<p>Array syntax arr[ in code.</p>\n\nSECRETPAID body one.\n\nSECRETPAID body two]closing.", 'SECRETPAID', "unbalanced '[' in intro" );
check( $r['has_body'] && strpos( $r['preview'], 'arr[' ) !== false, "incidental '[' stays literal in a clean preview, body still sealed" );

// HIGH (was a real leak): a bare '<' in the free intro must NOT be treated as a tag and pull the body forward.
$r = no_leak( "a < b is math.\n\nSECRETPAID > more body.", 'SECRETPAID', "bare '<' in intro" );
check( $r['has_body'], "bare '<' case still seals a body" );

// HIGH (was a real leak): 6KB+ over-reveal via a stray '[' before a paragraph boundary.
$r = no_leak( "<p>intro [</p>\n\n" . str_repeat( 'SECRETPAID. ', 500 ) . ']TAIL', 'SECRETPAID', "stray '[' before huge paid body" );
check( $r['has_body'], 'stray-bracket huge-body case still seals' );

// MEDIUM (was a real leak): block-mode stray '[' must not pull the next block into preview.
$r = no_leak( '<!-- wp:paragraph {"x":"["} --><p>VISIBLE [shortcode</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>SECRETPAID</p><!-- /wp:paragraph -->', 'SECRETPAID', "block-mode stray '['" );
check( 'block' === $r['mode'] && $r['has_body'], 'block stray-bracket: still a clean block cut with a sealed body' );

// MEDIUM (#9): '>' inside block JSON must not desync the depth walk / cut the group in half.
$r = no_leak( '<!-- wp:group {"x":">"} --><!-- wp:p --><p>FREE1</p><!-- /wp:p --><!-- wp:p --><p>FREE2</p><!-- /wp:p --><!-- /wp:group --><!-- wp:p --><p>SECRETPAID</p><!-- /wp:p -->', 'SECRETPAID', "'>' inside block JSON" );
check( strpos( $r['preview'], '>FREE2<' ) !== false, "block JSON '>' : whole first top-level group (FREE1+FREE2) stays in preview" );

// #6: a leading blank line must NOT collapse the preview to nothing.
$r = no_leak( "\n\nFirst paragraph visible.\n\nSECRETPAID two.\n\nSECRETPAID three.", 'SECRETPAID', 'leading blank line' );
check( strpos( $r['preview'], 'First paragraph visible.' ) !== false && $r['has_body'], 'leading blank line: preview keeps the first real paragraph' );

// #3: <!--more--> with an embedded newline is now recognized (/s flag) and sealed.
$r = inv( "Intro line.<!--more\nKeep reading-->SECRETPAID body.", 'multiline more marker' );
check( 'more' === $r['mode'] && strpos( $r['body'], 'SECRETPAID' ) !== false && strpos( $r['preview'], 'SECRETPAID' ) === false, 'multiline more: recognized, body sealed, no leak' );

// #8: an orphan close delimiter must not be treated as a real block boundary.
$r = inv( 'Free intro.<!-- /wp:group -->more text.<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', 'orphan close delimiter' );
check( $r['preview'] . $r['marker'] . $r['body'] === 'Free intro.<!-- /wp:group -->more text.<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', 'orphan close: invariant holds (degenerate, leak-safe)' );

// #5: a non-string argument degrades gracefully instead of throwing.
$threw = false;
try {
	$r = CrawlerToll_Cut::split( new stdClass() );
	check( '' === $r['preview'] && false === $r['has_body'], 'non-string (object) → empty result, no throw' );
} catch ( \Throwable $e ) {
	$threw = true;
}
check( ! $threw, 'split(object) does not throw' );

// DoS (#4): a delimiter-dense input must not OOM/hang (incremental scan, not preg_match_all).
$dense = str_repeat( '<!--wp:a-->', 50000 ); // 50k open delimiters, no close
$t0    = microtime( true );
$r     = CrawlerToll_Cut::split( $dense );
$dt    = microtime( true ) - $t0;
check( $r['preview'] . $r['marker'] . $r['body'] === $dense, 'dense-delimiter input: invariant holds' );
check( $dt < 2.0, sprintf( 'dense-delimiter input handled fast (%.3fs, incremental scan — no preg_match_all OOM)', $dt ) );

// #11: safe-forward/retreat always terminates on a clean boundary under load.
$loadish = '<p>intro</p>' . "\n\n" . str_repeat( '<!--x-->', 50000 ) . 'tail';
$t0      = microtime( true );
$r       = inv( $loadish, 'comment-flood after a paragraph boundary' );
$dt      = microtime( true ) - $t0;
check( preview_token_clean( $r['preview'] ) && $dt < 2.0, sprintf( 'comment-flood: clean preview boundary, fast (%.3fs)', $dt ) );

echo "\n";
echo 0 === $fail ? "ALL CUT-HARNESS TESTS PASSED\n" : "CUT-HARNESS FAILURES: $fail\n";
exit( 0 === $fail ? 0 : 1 );
