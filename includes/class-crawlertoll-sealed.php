<?php
/**
 * ct_sealed_v1 — AES-256-GCM content sealing, byte-compatible with
 * specapi/ct/sealed.py and crawlertoll-sdk-js/src/sealed.ts.
 *   CEK 32B (base64), nonce 12B, AAD = content_id, ciphertext = ct||tag (16B tag).
 *
 * @package CrawlerToll
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Sealed {

	const MAGIC = 'ct_sealed_v1';

	/**
	 * Seal plaintext with a fresh CEK.
	 *
	 * @param string $plaintext  Raw bytes.
	 * @param string $content_id Stable id; bound as AEAD additional data.
	 * @return array{0:string,1:array} [ base64 CEK, blob array ]
	 * @throws RuntimeException on cipher failure.
	 */
	public static function seal( $plaintext, $content_id ) {
		$cek   = random_bytes( 32 );
		$nonce = random_bytes( 12 );
		$tag   = '';
		$ct    = openssl_encrypt(
			$plaintext,
			'aes-256-gcm',
			$cek,
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			$content_id, // AAD binds the envelope to its id.
			16           // tag length in bytes.
		);
		if ( false === $ct ) {
			throw new RuntimeException( 'ct_sealed_v1 seal failed' );
		}
		$blob = array(
			'magic'      => self::MAGIC,
			'content_id' => $content_id,
			'alg'        => 'A256GCM',
			'nonce'      => base64_encode( $nonce ),
			'ciphertext' => base64_encode( $ct . $tag ), // ct||tag, matching WebCrypto/cryptography.
		);
		return array( base64_encode( $cek ), $blob );
	}

	/**
	 * Unseal a ct_sealed_v1 blob.
	 *
	 * @param array  $blob    Blob array (magic, content_id, nonce, ciphertext).
	 * @param string $cek_b64 Base64 CEK.
	 * @return string Plaintext bytes.
	 * @throws RuntimeException on bad blob or auth failure.
	 */
	public static function unseal( $blob, $cek_b64 ) {
		if ( empty( $blob['magic'] ) || self::MAGIC !== $blob['magic'] ) {
			throw new RuntimeException( 'not a ct_sealed_v1 blob' );
		}
		$cek        = base64_decode( $cek_b64 );
		$nonce      = base64_decode( $blob['nonce'] );
		$ciphertext = base64_decode( $blob['ciphertext'] );
		$tag        = substr( $ciphertext, -16 );
		$ct         = substr( $ciphertext, 0, -16 );
		$plain      = openssl_decrypt(
			$ct,
			'aes-256-gcm',
			$cek,
			OPENSSL_RAW_DATA,
			$nonce,
			$tag,
			$blob['content_id'] // AAD must match.
		);
		if ( false === $plain ) {
			throw new RuntimeException( 'ct_sealed_v1 unseal failed (auth)' );
		}
		return $plain;
	}
}
