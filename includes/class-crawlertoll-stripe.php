<?php
/**
 * Publisher-owned Stripe card rail (spec 2026-09-06).
 *
 * Card payments run on the PUBLISHER'S OWN Stripe account: the publisher pastes
 * their publishable + (restricted) secret key into Settings, this origin creates
 * and verifies the PaymentIntent, then asks the registry to release the content
 * key with a publisher-attested grant. CrawlerToll never holds a Stripe
 * credential, never runs a Connect platform, and never touches the money —
 * Charthouse earns only the Pro subscription.
 *
 * Ships in BOTH builds (free = real card rail, lineup freeze D2). Free-safe:
 * no Pro-only class dependencies. `verify_intent()` and `card_amount()` are
 * pure so the harness can test them without WordPress.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Stripe {

	const API = 'https://api.stripe.com/v1';

	/** Stripe's card-charge floor (USD-equivalent). Sub-floor prices are agent-only. */
	const MIN_CENTS = 50;

	/**
	 * Both keys present and well-shaped?
	 *
	 * @param array|null $settings
	 * @return bool
	 */
	public static function is_configured( $settings = null ) {
		$s = is_array( $settings ) ? $settings : crawlertoll_get_settings();
		return ! empty( $s['stripe_publishable_key'] ) && ! empty( $s['stripe_secret_key'] )
			&& self::valid_publishable_key( (string) $s['stripe_publishable_key'] )
			&& self::valid_secret_key( (string) $s['stripe_secret_key'] );
	}

	/**
	 * @param string $key
	 * @return bool
	 */
	public static function valid_publishable_key( $key ) {
		return (bool) preg_match( '/^pk_(live|test)_[A-Za-z0-9]{10,}$/', (string) $key );
	}

	/**
	 * Secret (sk_) or restricted (rk_) key. Restricted is the recommended shape.
	 *
	 * @param string $key
	 * @return bool
	 */
	public static function valid_secret_key( $key ) {
		return (bool) preg_match( '/^(sk|rk)_(live|test)_[A-Za-z0-9]{10,}$/', (string) $key );
	}

	/**
	 * Display form of the stored secret: never the value, only mode + last 4.
	 *
	 * @param string $key
	 * @return string '' when none.
	 */
	public static function masked_secret( $key ) {
		$key = (string) $key;
		if ( ! self::valid_secret_key( $key ) ) {
			return '';
		}
		$mode = false !== strpos( $key, '_live_' ) ? 'live' : 'test';
		return $mode . ' key ····' . substr( $key, -4 );
	}

	/**
	 * Convert a registry price (micros in the plugin currency) into what Stripe
	 * charges: integer minor units + a lowercase ISO code. USDC is a stablecoin
	 * label, not a card currency — it maps to usd. Fails closed below the floor.
	 *
	 * @param int    $price_micros
	 * @param string $currency
	 * @return array{cents:int,currency:string}|WP_Error
	 */
	public static function card_amount( $price_micros, $currency ) {
		$cents = (int) round( (int) $price_micros / 10000 );
		$cur   = strtolower( (string) $currency );
		if ( 'usdc' === $cur || '' === $cur ) {
			$cur = 'usd';
		}
		if ( ! preg_match( '/^[a-z]{3}$/', $cur ) ) {
			return new WP_Error( 'bad_currency', 'Unsupported card currency.', array( 'status' => 400 ) );
		}
		if ( $cents < self::MIN_CENTS ) {
			return new WP_Error( 'price_below_card_minimum', 'This price is too small for a card payment.', array( 'status' => 400 ) );
		}
		return array( 'cents' => $cents, 'currency' => $cur );
	}

	/**
	 * Bind a retrieved PaymentIntent to EXACTLY the item it pays for. Pure.
	 * Without this a $0.50 charge — or any succeeded charge on the account —
	 * would unlock any item (intent substitution), and a mid-flight status
	 * would release a key the reader may still fail to pay for.
	 *
	 * @param array $pi       Decoded PaymentIntent object from Stripe.
	 * @param array $expected {cents:int,currency:string,content_id:string,tier_id:string}
	 * @return true|WP_Error
	 */
	public static function verify_intent( $pi, $expected ) {
		if ( ! is_array( $pi ) || empty( $pi['id'] ) ) {
			return new WP_Error( 'stripe_read_failed', 'Could not read the payment.', array( 'status' => 502 ) );
		}
		if ( ! empty( $pi['application_fee_amount'] ) ) {
			// Defensive: we never take a cut; a fee means this is not our intent.
			return new WP_Error( 'unexpected_application_fee', 'Payment could not be verified.', array( 'status' => 400 ) );
		}
		if ( ! isset( $pi['status'] ) || 'succeeded' !== $pi['status'] ) {
			return new WP_Error( 'unsettled', 'Payment has not completed yet.', array( 'status' => 402 ) );
		}
		foreach ( array( 'cents', 'currency', 'content_id' ) as $k ) {
			if ( ! isset( $expected[ $k ] ) || '' === $expected[ $k ] ) {
				return new WP_Error( 'missing_binding', 'Payment could not be verified.', array( 'status' => 400 ) );
			}
		}
		$meta = isset( $pi['metadata'] ) && is_array( $pi['metadata'] ) ? $pi['metadata'] : array();
		if ( ! isset( $meta['content_id'] ) || $meta['content_id'] !== (string) $expected['content_id'] ) {
			return new WP_Error( 'binding_mismatch', 'Payment could not be verified.', array( 'status' => 400 ) );
		}
		$tier = isset( $expected['tier_id'] ) ? (string) $expected['tier_id'] : '';
		if ( ( isset( $meta['tier_id'] ) ? (string) $meta['tier_id'] : '' ) !== $tier ) {
			return new WP_Error( 'binding_mismatch', 'Payment could not be verified.', array( 'status' => 400 ) );
		}
		if ( ! isset( $pi['currency'] ) || strtolower( (string) $pi['currency'] ) !== strtolower( (string) $expected['currency'] ) ) {
			return new WP_Error( 'binding_mismatch', 'Payment could not be verified.', array( 'status' => 400 ) );
		}
		if ( ! isset( $pi['amount'] ) || (int) $pi['amount'] !== (int) $expected['cents'] ) {
			return new WP_Error( 'binding_mismatch', 'Payment could not be verified.', array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Create a PaymentIntent on the publisher's account.
	 *
	 * @param int    $cents
	 * @param string $currency Lowercase ISO code.
	 * @param array  $metadata Flat string map (content_id, tier_id, site).
	 * @return array{id:string,client_secret:string}|WP_Error
	 */
	public static function create_intent( $cents, $currency, $metadata ) {
		$settings = crawlertoll_get_settings();
		if ( ! self::is_configured( $settings ) ) {
			return new WP_Error( 'card_not_configured', 'Card payments are not set up for this site.', array( 'status' => 409 ) );
		}
		$params = array(
			'amount'                            => (int) $cents,
			'currency'                          => (string) $currency,
			'automatic_payment_methods[enabled]' => 'true',
		);
		foreach ( (array) $metadata as $k => $v ) {
			$params[ 'metadata[' . $k . ']' ] = (string) $v;
		}
		$response = wp_remote_post(
			self::API . '/payment_intents',
			array(
				'body'    => $params,
				'headers' => array( 'Authorization' => 'Bearer ' . $settings['stripe_secret_key'] ),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'stripe_unreachable', 'Could not reach the card processor.', array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['id'] ) || empty( $data['client_secret'] ) ) {
			return new WP_Error( 'stripe_create_failed', 'Could not start the card payment.', array( 'status' => 502 ) );
		}
		return array( 'id' => (string) $data['id'], 'client_secret' => (string) $data['client_secret'] );
	}

	/**
	 * Retrieve a PaymentIntent from the publisher's account.
	 *
	 * @param string $intent_id
	 * @return array|WP_Error Decoded object.
	 */
	public static function retrieve_intent( $intent_id ) {
		$settings = crawlertoll_get_settings();
		if ( ! self::is_configured( $settings ) ) {
			return new WP_Error( 'card_not_configured', 'Card payments are not set up for this site.', array( 'status' => 409 ) );
		}
		if ( ! preg_match( '/^pi_[A-Za-z0-9]{6,}$/', (string) $intent_id ) ) {
			return new WP_Error( 'invalid_intent', 'Payment could not be verified.', array( 'status' => 400 ) );
		}
		$response = wp_remote_get(
			self::API . '/payment_intents/' . rawurlencode( (string) $intent_id ),
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $settings['stripe_secret_key'] ),
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'stripe_unreachable', 'Could not reach the card processor.', array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return new WP_Error( 'stripe_read_failed', 'Could not read the payment.', array( 'status' => 502 ) );
		}
		return $data;
	}
}
