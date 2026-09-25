<?php
/**
 * HTTP 402 response builder. PHP port of the JS `build402()` —
 * Cloudflare-shape headers + structured JSON payment offer.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_HTTP402 {

	/**
	 * Emit the 402 response and exit. Must be called BEFORE any output
	 * has been sent (i.e. from a `template_redirect` or earlier hook).
	 *
	 * @param array<string,mixed> $settings crawlertoll_get_settings() output.
	 * @return void
	 */
	public static function send( $settings ) {
		$price_micros = isset( $settings['price_micros'] ) ? (int) $settings['price_micros'] : 5000;
		$currency     = isset( $settings['currency'] ) ? (string) $settings['currency'] : 'USD';
		$rail         = isset( $settings['rail'] ) ? (string) $settings['rail'] : 'x402';
		$payment_url  = isset( $settings['payment_url'] ) ? (string) $settings['payment_url'] : '';
		$terms_url    = isset( $settings['terms_url'] ) ? (string) $settings['terms_url'] : '';
		$context_license_url = isset( $settings['context_license_url'] ) ? (string) $settings['context_license_url'] : '';

		$offer = array(
			'rail'         => $rail,
			'priceMicros'  => $price_micros,
			'currency'     => $currency,
		);
		if ( $payment_url !== '' ) {
			$offer['paymentUrl'] = $payment_url;
		}
		$offer['publisher'] = self::publisher_slug();
		$offer['endpoint']  = 'default';

		$body = wp_json_encode(
			array(
				'error'   => 'payment_required',
				'message' => 'Payment required.',
				'offer'   => $offer,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		$links = array();
		if ( $payment_url !== '' ) {
			$links[] = sprintf( '<%s>; rel="payment"; type="%s"', $payment_url, $rail );
		}
		$resolved_cl = $context_license_url !== '' ? $context_license_url : home_url( '/.well-known/context-license.json' );
		$links[] = sprintf( '<%s>; rel="describedby"; type="application/json"', $resolved_cl );
		if ( $terms_url !== '' ) {
			$links[] = sprintf( '<%s>; rel="terms-of-service"', $terms_url );
		}

		status_header( 402 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( sprintf( 'Crawler-Price: %d micros %s', $price_micros, $currency ) );
		header( sprintf( 'Crawler-Price-Rail: %s', $rail ) );
		header( 'Retry-After: 60' );
		if ( ! empty( $links ) ) {
			header( 'Link: ' . implode( ', ', $links ) );
		}
		// Disable WP's default rewrites of headers, then write body.
		nocache_headers();
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON.
		exit;
	}

	/**
	 * Send the 403 block response.
	 *
	 * @param array $reasons Decision reasons trace.
	 * @return void
	 */
	public static function send_block( $reasons ) {
		$body = wp_json_encode(
			array(
				'error'   => 'forbidden',
				'message' => 'Crawler access denied by site policy.',
				'reasons' => $reasons,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
		status_header( 403 );
		header( 'Content-Type: application/json; charset=utf-8' );
		nocache_headers();
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private static function publisher_slug() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) ) {
			return 'example';
		}
		return strtolower( str_replace( array( '.', ':' ), '-', $host ) );
	}
}
