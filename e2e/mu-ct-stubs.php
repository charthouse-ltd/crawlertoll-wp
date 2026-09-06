<?php
/**
 * e2e HTTP stubs (mu-plugin, DEV RIG ONLY — never shipped; e2e/ is excluded
 * from both build artifacts).
 *
 * Short-circuits the two external services the card rail touches so the
 * intent → confirm → key path runs through REAL WordPress REST end to end:
 *   - api.stripe.com  (the publisher's own Stripe account): create returns a
 *     PaymentIntent; retrieve echoes what was created as `succeeded` (or the
 *     status set in option ct_e2e_stripe_status).
 *   - the registry's sealed endpoints: register/price succeed; /grant releases
 *     a fake CEK once per receipt_id and 409s on replay, recording every grant
 *     body in option ct_e2e_grants for assertions.
 * Everything else passes through untouched.
 */

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$method = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
	$ok     = function ( $data, $code = 200 ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $data ),
			'response' => array( 'code' => $code, 'message' => 200 === $code ? 'OK' : 'ERR' ),
			'cookies'  => array(),
			'filename' => null,
		);
	};

	// ── Stripe (publisher's own account) ──────────────────────────────
	if ( false !== strpos( $url, 'api.stripe.com/v1/payment_intents' ) ) {
		$intents = get_option( 'ct_e2e_intents', array() );
		if ( 'POST' === $method ) {
			$p    = isset( $args['body'] ) && is_array( $args['body'] ) ? $args['body'] : array();
			$n    = count( $intents ) + 1;
			$id   = 'pi_e2e' . str_pad( (string) $n, 6, '0', STR_PAD_LEFT );
			$meta = array();
			foreach ( $p as $k => $v ) {
				if ( preg_match( '/^metadata\[(.+)\]$/', (string) $k, $m ) ) {
					$meta[ $m[1] ] = (string) $v;
				}
			}
			$intents[ $id ] = array(
				'id'       => $id,
				'amount'   => isset( $p['amount'] ) ? (int) $p['amount'] : 0,
				'currency' => isset( $p['currency'] ) ? (string) $p['currency'] : '',
				'metadata' => $meta,
				'auth'     => isset( $args['headers']['Authorization'] ) ? (string) $args['headers']['Authorization'] : '',
			);
			update_option( 'ct_e2e_intents', $intents );
			return $ok( array( 'id' => $id, 'client_secret' => $id . '_secret_e2e', 'status' => 'requires_payment_method' ) );
		}
		if ( preg_match( '#/payment_intents/([^/?]+)#', $url, $m ) ) {
			$id = rawurldecode( $m[1] );
			if ( ! isset( $intents[ $id ] ) ) {
				return $ok( array( 'error' => array( 'message' => 'No such payment_intent' ) ), 404 );
			}
			$pi           = $intents[ $id ];
			$pi['status'] = (string) get_option( 'ct_e2e_stripe_status', 'succeeded' );
			$pi['application_fee_amount'] = null;
			return $ok( $pi );
		}
	}

	// ── Registry sealed endpoints ─────────────────────────────────────
	// Skipped when CT_E2E_STUB_REGISTRY is defined false: the rig then talks to a
	// REAL registry (e.g. a local `wrangler dev`) for hands-on browser QA.
	if ( defined( 'CT_E2E_STUB_REGISTRY' ) && ! CT_E2E_STUB_REGISTRY ) {
		return $pre;
	}
	if ( preg_match( '#/v1/sealed/register$#', $url ) ) {
		return $ok( array( 'status' => 'registered' ), 201 );
	}
	if ( preg_match( '#/v1/sealed/.+/price$#', $url ) ) {
		return $ok( array( 'status' => 'updated' ) );
	}
	if ( preg_match( '#/v1/sealed/(.+)/grant$#', $url, $m ) ) {
		$body   = json_decode( isset( $args['body'] ) ? (string) $args['body'] : '{}', true );
		$grants = get_option( 'ct_e2e_grants', array() );
		$auth   = isset( $args['headers']['Authorization'] ) ? (string) $args['headers']['Authorization'] : '';
		$rid    = isset( $body['receipt_id'] ) ? (string) $body['receipt_id'] : '';
		foreach ( $grants as $g ) {
			if ( $g['receipt_id'] === $rid ) {
				return $ok( array( 'error' => 'receipt_already_redeemed' ), 409 );
			}
		}
		$grants[] = array_merge( (array) $body, array( 'content_id_in_url' => rawurldecode( $m[1] ), 'auth' => $auth ) );
		update_option( 'ct_e2e_grants', $grants );
		return $ok( array(
			'cek'        => 'Q0VLLWUyZQ==',
			'capability' => array( 'magic' => 'ct_cap_v1', 'content_id' => rawurldecode( $m[1] ), 'scope' => 'premium' ),
			'pass'       => array( 'pass_id' => md5( $rid ), 'expires_at' => null ),
		) );
	}

	return $pre;
}, 10, 3 );
