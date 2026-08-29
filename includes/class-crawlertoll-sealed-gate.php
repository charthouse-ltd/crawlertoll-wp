<?php
/**
 * TOTAL gate orchestration: for a 402 decision on a singular post, seal the
 * post content (cached in post meta), register the key with the registry
 * escrow, and serve 402 + the sealed blob + a key-release link. Agents-only:
 * the caller only invokes this for a detected bot.
 *
 * @package CrawlerToll
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Sealed_Gate {

	const META_KEY = '_crawlertoll_sealed_v1';

	/**
	 * Stable content id for a post.
	 *
	 * @param string $host
	 * @param int    $post_id
	 * @return string
	 */
	public static function build_content_id( $host, $post_id ) {
		return $host . '/post/' . (int) $post_id;
	}

	/**
	 * If the current request resolves to a singular post, seal + register +
	 * serve a 402 with the blob, then exit. Returns false (caller falls back
	 * to the plain 402) when there is no singular post to seal.
	 *
	 * @param array $settings Resolved settings (rail/price already resolved).
	 * @return bool Handled? (true = response sent + exit; false = fall through)
	 */
	public static function maybe_seal_and_serve( $settings ) {
		$uri     = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$post_id = url_to_postid( $uri );
		if ( $post_id <= 0 ) {
			return false; // non-singular → caller sends the plain 402
		}

		$plaintext = (string) get_post_field( 'post_content', $post_id );
		$host      = wp_parse_url( home_url(), PHP_URL_HOST );
		$cid       = self::build_content_id( $host, $post_id );

		$sealed = self::get_or_create_sealed( $post_id, $plaintext, $cid, $settings );
		if ( false === $sealed ) {
			return false; // sealing/registration failed → fall back to plain 402
		}

		$base        = defined( 'CRAWLERTOLL_REGISTRY_URL' ) ? CRAWLERTOLL_REGISTRY_URL : CrawlerToll_Registry::REGISTRY_URL;
		// content_id is used raw (literal slashes): the registry slices the raw path
		// segment and looks it up verbatim, so it must NOT be url-encoded.
		$key_release = $base . '/v1/sealed/' . $cid . '/key';

		$price    = isset( $settings['price_micros'] ) ? (int) $settings['price_micros'] : 5000;
		$currency = isset( $settings['currency'] ) ? $settings['currency'] : 'USD';
		$rail     = isset( $settings['rail'] ) ? $settings['rail'] : 'x402';

		status_header( 402 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( sprintf( 'Crawler-Price: %d micros %s', $price, $currency ) );
		header( 'Crawler-Price-Rail: ' . $rail );
		header( sprintf( 'Link: <%s>; rel="payment"; type="ct-sealed-key"', $key_release ) );
		nocache_headers();
		echo wp_json_encode(
			array(
				'error'   => 'payment_required',
				'message' => 'Sealed content. Pay to release the decryption key.',
				'sealed'  => $sealed['blob'],
				'offer'   => array(
					'rail'        => $rail,
					'priceMicros' => $price,
					'currency'    => $currency,
					'content_id'  => $cid,
					'keyRelease'  => $key_release,
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Return a cached sealed blob for the post, or seal + register a fresh one.
	 * Cache invalidates when the post content hash changes.
	 *
	 * @param int    $post_id
	 * @param string $plaintext
	 * @param string $cid
	 * @param array  $settings
	 * @return array{blob:array}|false
	 */
	private static function get_or_create_sealed( $post_id, $plaintext, $cid, $settings ) {
		$hash   = hash( 'sha256', $plaintext );
		$price  = isset( $settings['price_micros'] ) ? (int) $settings['price_micros'] : 5000;
		$curr   = isset( $settings['currency'] ) ? (string) $settings['currency'] : 'USD';
		// Metered free articles (Pro): per-path free allowance resolved from the
		// post's permalink. Null for free tier / unmetered paths.
		$meter  = CrawlerToll_Meter::resolve_for_post( $post_id, $settings );
		// Access tiers (Pro, A2): per-path price×duration set from the permalink.
		$tiers  = CrawlerToll_Tiers::resolve_for_post( $post_id, $settings );
		$cached = get_post_meta( $post_id, self::META_KEY, true );
		if ( is_array( $cached ) && isset( $cached['hash'], $cached['blob'] ) && $cached['hash'] === $hash ) {
			// Reprice without re-sealing when the commercial terms changed (audit
			// 2026-08-25): the CEK stays valid, buyers' cached keys keep working.
			// Fail-soft: a sync hiccup keeps serving at the registered price.
			$cached_price = isset( $cached['price_micros'] ) ? (int) $cached['price_micros'] : -1;
			$cached_curr  = isset( $cached['currency'] ) ? (string) $cached['currency'] : '';
			$cached_meter = isset( $cached['meter'] ) ? $cached['meter'] : null;
			$meters_differ = wp_json_encode( $cached_meter ) !== wp_json_encode( $meter );
			$cached_tiers  = isset( $cached['tiers'] ) ? $cached['tiers'] : null;
			$tiers_differ  = wp_json_encode( $cached_tiers ) !== wp_json_encode( $tiers );
			if ( $cached_price !== $price || $cached_curr !== $curr || $meters_differ || $tiers_differ ) {
				$res = ( new CrawlerToll_Registry() )->update_sealed_price(
					$cid,
					$price,
					$curr,
					$meters_differ ? $meter : false,
					$tiers_differ ? $tiers : false
				);
				if ( ! is_wp_error( $res ) ) {
					$cached['price_micros'] = $price;
					$cached['currency']     = $curr;
					if ( $meters_differ ) {
						$cached['meter'] = $meter;
					}
					if ( $tiers_differ ) {
						$cached['tiers'] = $tiers;
					}
					update_post_meta( $post_id, self::META_KEY, $cached );
				}
			}
			return array( 'blob' => $cached['blob'] );
		}

		try {
			list( $cek_b64, $blob ) = CrawlerToll_Sealed::seal( $plaintext, $cid );
		} catch ( Exception $e ) {
			return false;
		}

		// Sealing ships in the FREE build, where the Pro DB class is stripped — the
		// registry client doesn't need it (its constructor param was always dead).
		$registry = new CrawlerToll_Registry();
		$result   = $registry->register_sealed(
			$cid,
			$cek_b64,
			$price,
			$curr,
			'full',
			$meter,
			$tiers
		);
		if ( is_wp_error( $result ) || empty( $result['status'] ) || 'registered' !== $result['status'] ) {
			return false;
		}

		update_post_meta(
			$post_id,
			self::META_KEY,
			array(
				'hash'          => $hash,
				'content_id'    => $cid,
				'blob'          => $blob,
				'price_micros'  => $price,
				'currency'      => $curr,
				'meter'         => $meter,
				'tiers'         => $tiers,
				'registered_at' => current_time( 'mysql' ),
			)
		);
		return array( 'blob' => $blob );
	}
}
