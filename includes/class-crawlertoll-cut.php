<?php
/**
 * Free-tier "standard cut" splitter (WS3 slice 3.2). Splits a post's RAW
 * post_content into a cleartext PREVIEW (served + indexable) and a SEALED BODY
 * (encrypted by ct_sealed_v1, released only on payment) at a deterministic
 * cut-point.
 *
 * THE INVARIANT: split()['preview'] . split()['marker'] . split()['body'] is
 * byte-for-byte identical to the input, ALWAYS. The cut-point is the encryption
 * boundary; reconstruction (serve preview now, append the unsealed body on
 * unlock) is therefore exact and lossless.
 *
 * THE SAFE DIRECTION: when a boundary is ambiguous the cut only ever SHRINKS the
 * preview (seals more), never grows it — because growing the preview is the leak
 * direction (paid bytes becoming cleartext). retreat_to_clean() enforces this.
 *
 * Free-safe by design: pure PHP + the WP-core <!--more--> regex only. References
 * none of the Pro-only classes (the DB / Pricing / Alerts / Logger / Provenance /
 * Catalogue-updater classes are stripped from the free wp.org build). split()
 * needs no WP runtime, so it is unit-testable in a WP-less CLI harness
 * (tests/cut-harness.php).
 *
 * Why not WordPress's get_extended()? It is LOSSY — it whitespace-trims the two
 * halves and drops the marker, so main . extended !== raw. That would migrate
 * bytes between the free and paid halves. We reuse only core's marker *pattern*
 * to LOCATE the byte offset, then take our own exact slices.
 *
 * Known, documented 3.2 limitations (all leak-SAFE — they over-seal, never
 * over-reveal): a blank line inside <pre>/<code> can land the cut inside the
 * preformatted block (3.3 runs force_balance_tags on the *rendered* preview);
 * structureless/malformed premium content yields has_body=false so the caller
 * warns the author rather than silently sealing.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Cut {

	/** WordPress core's own <!--more--> marker pattern (with /s so custom more-text may span lines). */
	const MORE_RE = '/<!--more(.*?)?-->/s';

	/**
	 * Per-post premium marking (boolean post meta). Note: registered show_in_rest,
	 * so this boolean is WORLD-READABLE via the REST API by design (a paywall flag
	 * is observable anyway) — never store anything sensitive in a similar meta
	 * assuming the write auth_callback hides reads.
	 */
	const META_KEY = '_crawlertoll_premium';

	/**
	 * Split raw post content into preview + marker + sealable body.
	 *
	 * @param string $raw_content Raw post_content bytes (get_post_field('post_content')).
	 * @return array{preview:string,marker:string,body:string,cut:int,mode:string,has_body:bool}
	 *   INVARIANT: preview . marker . body === $raw_content, byte-for-byte.
	 */
	public static function split( $raw_content ) {
		// Defensive: a non-scalar (object/array/null) degrades to empty rather than
		// throwing on the cast. The sole caller passes a string.
		$raw = is_scalar( $raw_content ) ? (string) $raw_content : '';

		// (1) An explicit <!--more--> marker always wins (author's choice). Honor
		//     it byte-exactly; the marker (incl. any custom "more text") travels in
		//     its own slot. The body is everything strictly AFTER the marker, so it
		//     can never appear in the preview — no safety pass needed.
		$more = self::find_more( $raw );
		if ( null !== $more ) {
			$pos     = $more['pos'];
			$marker  = $more['marker'];
			$preview = substr( $raw, 0, $pos );
			$body    = (string) substr( $raw, $pos + strlen( $marker ) );
			return self::result( $preview, $marker, $body, 'more' );
		}

		// (2) No marker → the free standard template: block-aware first, then
		//     classic paragraph, then the safe degenerate (seal nothing).
		$off  = null;
		$mode = 'whole';

		if ( preg_match( '/<!--\s*wp:/', $raw ) ) {
			$block_end = self::first_block_end( $raw );
			if ( null !== $block_end ) {
				$off  = $block_end;
				$mode = 'block';
			}
		}
		if ( null === $off ) {
			$para_end = self::first_paragraph_end( $raw );
			if ( null !== $para_end ) {
				$off  = $para_end;
				$mode = 'paragraph';
			}
		}
		if ( null === $off ) {
			// No boundary at all: give the whole thing away as preview rather than
			// risk a mid-sentence cut; has_body=false so the caller MUST refuse to
			// seal/charge (and warn the author to add a <!--more-->).
			return self::result( $raw, '', '', 'whole' );
		}

		// Block boundaries sit exactly between top-level blocks (after a -->), so
		// they are already token-clean. Paragraph boundaries (esp. the blank-line
		// fallback) can land inside a tag/comment/shortcode — retreat to a clean
		// boundary, only ever shrinking the preview (the leak-safe direction).
		if ( 'paragraph' === $mode ) {
			$off = self::retreat_to_clean( $raw, $off );
		}

		$len = strlen( $raw );
		if ( $off >= $len ) {
			return self::result( $raw, '', '', 'whole' ); // nothing after the cut to seal
		}
		if ( $off <= 0 ) {
			// Retreat consumed the whole preview: no safe non-empty teaser exists.
			// Seal everything (empty preview) — leak-safe; the caller may warn.
			return self::result( '', '', $raw, $mode );
		}

		$preview = (string) substr( $raw, 0, $off );
		$body    = (string) substr( $raw, $off );
		return self::result( $preview, '', $body, $mode );
	}

	/**
	 * Whether a post is marked premium (sealed).
	 *
	 * @param int $post_id
	 * @return bool
	 */
	public static function is_premium( $post_id ) {
		return (bool) get_post_meta( (int) $post_id, self::META_KEY, true );
	}

	// ─── internals (pure) ────────────────────────────────────────────

	/**
	 * @param string $preview
	 * @param string $marker
	 * @param string $body
	 * @param string $mode
	 * @return array{preview:string,marker:string,body:string,cut:int,mode:string,has_body:bool}
	 */
	private static function result( $preview, $marker, $body, $mode ) {
		return array(
			'preview'  => $preview,
			'marker'   => $marker,
			'body'     => $body,
			'cut'      => strlen( $preview ) + strlen( $marker ),
			'mode'     => $mode,
			'has_body' => '' !== $body,
		);
	}

	/**
	 * Locate the first <!--more--> marker.
	 *
	 * @param string $s
	 * @return array{pos:int,marker:string}|null
	 */
	private static function find_more( $s ) {
		if ( preg_match( self::MORE_RE, $s, $mm, PREG_OFFSET_CAPTURE ) ) {
			return array( 'pos' => (int) $mm[0][1], 'marker' => (string) $mm[0][0] );
		}
		return null;
	}

	/**
	 * Byte offset just past the close of the FIRST complete top-level Gutenberg
	 * block. Incremental single-match scan (one delimiter in memory at a time —
	 * never preg_match_all, which amplifies memory ~27x by match count and OOMs on
	 * a delimiter-dense post). Delimiters are matched up to the literal --> (not
	 * [^>], so a '>' inside the block's JSON attributes can't truncate them). A
	 * self-closing <!-- wp:x /--> is depth-neutral; an orphan close at depth 0 is
	 * ignored (never drives depth negative). Returns null if no complete top-level
	 * block is found.
	 *
	 * @param string $s
	 * @return int|null
	 */
	private static function first_block_end( $s ) {
		$len    = strlen( $s );
		$cursor = 0;
		$depth  = 0;
		$guard  = 0;
		while ( $cursor < $len && $guard++ < 200000 ) {
			if ( ! preg_match( '/<!--\s*\/?wp:.*?-->/s', $s, $m, PREG_OFFSET_CAPTURE, $cursor ) ) {
				break;
			}
			$tag    = $m[0][0];
			$pos    = (int) $m[0][1];
			$end    = $pos + strlen( $tag );
			$cursor = $end > $cursor ? $end : $cursor + 1;

			$is_close = (bool) preg_match( '#^<!--\s*/wp:#', $tag );
			$is_self  = ! $is_close && (bool) preg_match( '#/\s*-->$#', $tag );

			if ( $is_close ) {
				if ( $depth > 0 ) {
					$depth--;
					if ( 0 === $depth ) {
						return $end; // closed back to the top level
					}
				}
				// else: orphan close (no open block) — ignore, keep scanning.
			} elseif ( $is_self ) {
				if ( 0 === $depth ) {
					return $end; // a self-closing top-level block stands alone
				}
				// inner self-closing block: depth-neutral
			} else {
				$depth++; // opening delimiter
			}
		}
		return null;
	}

	/**
	 * Byte offset just past the first paragraph unit whose preview is non-empty:
	 * an explicit </p>, else the first blank-line boundary. Skips boundaries that
	 * would leave an all-whitespace preview (a leading blank line must not collapse
	 * the teaser). Returns null if none exists.
	 *
	 * @param string $s
	 * @return int|null
	 */
	private static function first_paragraph_end( $s ) {
		$end = self::scan_boundary( $s, '#</p\s*>#i' );
		if ( null !== $end ) {
			return $end;
		}
		return self::scan_boundary( $s, '/\R\R/' );
	}

	/**
	 * First match of $re whose start is past the first non-whitespace byte (so the
	 * preview before it has real content). Incremental — no match array built.
	 *
	 * @param string $s
	 * @param string $re
	 * @return int|null
	 */
	private static function scan_boundary( $s, $re ) {
		$first_non_ws = preg_match( '/\S/', $s, $w, PREG_OFFSET_CAPTURE ) ? (int) $w[0][1] : strlen( $s );
		$len          = strlen( $s );
		$cursor       = 0;
		$guard        = 0;
		while ( $cursor <= $len && $guard++ < 200000 ) {
			if ( ! preg_match( $re, $s, $m, PREG_OFFSET_CAPTURE, $cursor ) ) {
				return null;
			}
			$pos = (int) $m[0][1];
			$end = $pos + strlen( $m[0][0] );
			if ( $pos > $first_non_ws ) {
				return $end; // preview before this boundary holds real content
			}
			$cursor = $end > $cursor ? $end : $cursor + 1;
		}
		return null;
	}

	/**
	 * Move an offset BACKWARD until the preview prefix substr(0,$off) does not end
	 * inside an open HTML/block comment, an open real HTML tag, or an open real
	 * shortcode. Only ever shrinks the preview (never advances into the body), so
	 * it can never pull a paid byte into the cleartext. Incidental '<'/'[' (a bare
	 * '<' before whitespace/digits, an 'arr[' in prose) are NOT treated as open
	 * tokens, so a clean boundary is left intact. Worst case returns 0 (seal all).
	 *
	 * @param string $s
	 * @param int    $off
	 * @return int
	 */
	private static function retreat_to_clean( $s, $off ) {
		$guard = 0;
		while ( $off > 0 && $guard++ < 10000 ) {
			$head = (string) substr( $s, 0, $off );

			// Unclosed HTML/block comment → retreat before the '<!--'.
			$cpos = strrpos( $head, '<!--' );
			if ( false !== $cpos ) {
				$cend = strpos( $s, '-->', $cpos );
				if ( false === $cend || $cend + 3 > $off ) {
					$off = $cpos;
					continue;
				}
			}

			// Unclosed real tag → retreat before its '<'.
			$tpos = self::last_open_tag( $head );
			if ( null !== $tpos ) {
				$off = $tpos;
				continue;
			}

			// Unclosed real shortcode → retreat before its '['.
			$spos = self::last_open_shortcode( $head );
			if ( null !== $spos ) {
				$off = $spos;
				continue;
			}

			break;
		}
		return max( 0, $off );
	}

	/**
	 * Offset of an unclosed real HTML tag ('<' followed by a tag-name / '/' / '!')
	 * after the last '>' in $head, or null. A bare '<' (before whitespace/digits)
	 * is text, not a tag.
	 *
	 * @param string $head
	 * @return int|null
	 */
	private static function last_open_tag( $head ) {
		$g     = strrpos( $head, '>' );
		$start = ( false === $g ) ? 0 : $g + 1;
		if ( preg_match( '/<(?=[a-zA-Z\/!])/', (string) substr( $head, $start ), $m, PREG_OFFSET_CAPTURE ) ) {
			return $start + (int) $m[0][1];
		}
		return null;
	}

	/**
	 * Offset of an unclosed real shortcode ('[' followed by a shortcode-name char)
	 * after the last ']' in $head, or null. A bare '[' (e.g. 'arr[') is text.
	 *
	 * @param string $head
	 * @return int|null
	 */
	private static function last_open_shortcode( $head ) {
		$r     = strrpos( $head, ']' );
		$start = ( false === $r ) ? 0 : $r + 1;
		if ( preg_match( '/\[(?=[a-zA-Z0-9_\/-])/', (string) substr( $head, $start ), $m, PREG_OFFSET_CAPTURE ) ) {
			return $start + (int) $m[0][1];
		}
		return null;
	}
}
