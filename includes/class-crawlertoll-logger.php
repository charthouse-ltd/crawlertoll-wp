<?php
/**
 * Request logger. Writes every decision to the custom log table.
 * Used by the plugin's `on_parse_request` hook and the REST API.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Logger {

	/**
	 * @var CrawlerToll_DB
	 */
	private $db;

	/**
	 * @param CrawlerToll_DB $db
	 */
	public function __construct( $db ) {
		$this->db = $db;
	}

	/**
	 * Log a decision. Called from `CrawlerToll_Plugin::on_parse_request()`
	 * after the decision is made.
	 *
	 * @param array<string,mixed> $decision Decision from CrawlerToll_Decision::decide().
	 * @param array<string,mixed> $settings Current settings.
	 * @return int|false Log entry ID, or false on failure.
	 */
	public function log_decision( $decision, $settings ) {
		$bot       = ! empty( $decision['bot'] ) ? $decision['bot'] : array();
		$action    = isset( $decision['action'] ) ? $decision['action'] : 'allow';

		$http_status = 200;
		if ( $action === '402' ) {
			$http_status = 402;
		} elseif ( $action === 'block' ) {
			$http_status = 403;
		}

		$price_micros = 0;
		if ( $action === '402' ) {
			$price_micros = isset( $settings['price_micros'] ) ? (int) $settings['price_micros'] : 5000;
		}

		$entry = array(
			'request_time'   => current_time( 'mysql' ),
			'bot_name'       => isset( $bot['name'] ) ? $bot['name'] : '',
			'bot_operator'   => isset( $bot['operator'] ) ? $bot['operator'] : '',
			'bot_category'   => isset( $bot['category'] ) ? $bot['category'] : '',
			'request_path'   => isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '/', // phpcs:ignore
			'request_method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET', // phpcs:ignore
			'action'         => $action,
			'price_micros'   => $price_micros,
			'currency'       => isset( $settings['currency'] ) ? $settings['currency'] : 'USD',
			'rail'           => isset( $settings['rail'] ) ? $settings['rail'] : 'x402',
			'http_status'    => $http_status,
			'user_agent'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '', // phpcs:ignore
		);

		return $this->db->insert( $entry );
	}

	/**
	 * Attach a content hash to an existing log entry.
	 *
	 * Called after output buffering captures the response body.
	 *
	 * @param int    $log_id
	 * @param string $hash SHA-256 hex string.
	 * @return bool
	 */
	public function attach_hash( $log_id, $hash ) {
		global $wpdb;

		$result = $wpdb->update(
			$this->db->get_table_name(),
			array( 'content_hash' => $hash ),
			array( 'id' => (int) $log_id ),
			array( '%s' ),
			array( '%d' )
		);

		return $result !== false;
	}
}
