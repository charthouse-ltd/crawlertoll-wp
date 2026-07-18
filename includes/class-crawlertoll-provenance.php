<?php
/**
 * Content provenance — SHA-256 hashing of response bodies.
 *
 * Starts output buffering before WordPress renders the page, computes a
 * SHA-256 hash of the response body, and attaches it to the bot-request log.
 * Creates an irrefutable, timestamped record: "this specific content was on
 * this server, requested by this bot, at this exact time."
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Provenance {

	/**
	 * @var CrawlerToll_Logger
	 */
	private $logger;

	/**
	 * @var int|null Current log entry ID (set after decision is logged).
	 */
	private $log_id = null;

	/**
	 * @param CrawlerToll_Logger $logger
	 */
	public function __construct( $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Start output buffering. Must be called BEFORE any output is sent.
	 * Hook: `template_redirect` at priority -9999 (earliest possible).
	 *
	 * @return void
	 */
	public function start_buffer() {
		if ( ! $this->is_eligible_request() ) {
			return;
		}
		ob_start( array( $this, 'on_buffer_flush' ) );
	}

	/**
	 * Set the current log entry ID so the buffer flush callback knows
	 * which row to update with the hash.
	 *
	 * @param int $log_id
	 * @return void
	 */
	public function set_log_id( $log_id ) {
		$this->log_id = $log_id;
	}

	/**
	 * Callback fired when the output buffer flushes.
	 * Computes SHA-256 of the response body and stores it.
	 *
	 * @param string $buffer The full response body.
	 * @return string Unmodified buffer.
	 */
	public function on_buffer_flush( $buffer ) {
		if ( $this->log_id === null || ! is_string( $buffer ) || $buffer === '' ) {
			return $buffer;
		}

		$hash = hash( 'sha256', $buffer );

		$this->logger->attach_hash( $this->log_id, $hash );

		return $buffer;
	}

	/**
	 * Compute a SHA-256 hash of arbitrary content.
	 *
	 * @param string $content
	 * @return string 64-char hex string.
	 */
	public static function hash_content( $content ) {
		return hash( 'sha256', $content );
	}

	/**
	 * Verify that a content hash matches a piece of content.
	 *
	 * @param string $content The alleged content.
	 * @param string $hash    The SHA-256 hash to verify against.
	 * @return bool
	 */
	public static function verify( $content, $hash ) {
		return hash_equals( self::hash_content( $content ), $hash );
	}

	/**
	 * Is the current request eligible for output buffering?
	 * Skip admin, REST, AJAX, cron, XML-RPC, and the discovery endpoints.
	 *
	 * @return bool
	 */
	private function is_eligible_request() {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return false;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return false;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return false;
		}
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '/'; // phpcs:ignore
		if ( $path === '/robots.txt' || strpos( $path, '/.well-known/' ) === 0 ) {
			return false;
		}
		return true;
	}
}
