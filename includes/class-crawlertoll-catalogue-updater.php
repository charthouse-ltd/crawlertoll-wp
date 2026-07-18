<?php
/**
 * Auto-updating bot catalogue. Weekly cron job fetches the latest catalogue
 * from data.crawlertoll.com and merges new bots into the local list.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_CatalogueUpdater {

	/**
	 * Remote URL for the canonical bot catalogue.
	 */
	const CATALOGUE_URL = 'https://data.crawlertoll.com/bots.json';

	/**
	 * Option key for the merged catalogue.
	 */
	const OPTION_KEY = 'crawlertoll_bot_catalogue';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'crawlertoll_catalogue_update', array( __CLASS__, 'fetch_and_merge' ) );

		if ( ! wp_next_scheduled( 'crawlertoll_catalogue_update' ) ) {
			wp_schedule_event( time(), 'weekly', 'crawlertoll_catalogue_update' );
		}

		// Also check on admin page load (for immediate feedback after activation).
		add_action( 'admin_init', array( __CLASS__, 'maybe_check_for_updates' ) );
	}

	/**
	 * Fetch the remote catalogue and merge new bots.
	 *
	 * @return void
	 */
	public static function fetch_and_merge() {
		$response = wp_remote_get( self::CATALOGUE_URL, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			// Silently fail — we use the bundled catalogue as fallback.
			return;
		}

		$body = wp_remote_retrieve_body( $response );
		$remote_bots = json_decode( $body, true );

		if ( ! is_array( $remote_bots ) || empty( $remote_bots ) ) {
			return;
		}

		$local_bots = CrawlerToll_Bot_Catalogue::all();
		$new_bots   = self::find_new_bots( $local_bots, $remote_bots );

		if ( empty( $new_bots ) ) {
			return;
		}

		// Store the merged catalogue for use by the bot catalogue class.
		update_option( self::OPTION_KEY, $remote_bots );

		// Flag that new bots were found so the admin can show a notice.
		update_option( 'crawlertoll_new_bots_found', count( $new_bots ) );
		update_option( 'crawlertoll_new_bots_list', $new_bots );
	}

	/**
	 * Find bots in the remote list that aren't in the local list.
	 *
	 * @param array $local
	 * @param array $remote
	 * @return array
	 */
	private static function find_new_bots( $local, $remote ) {
		$local_names = array();
		foreach ( $local as $bot ) {
			$local_names[] = strtolower( $bot['name'] );
		}

		$new = array();
		foreach ( $remote as $bot ) {
			if ( ! in_array( strtolower( $bot['name'] ), $local_names, true ) ) {
				$new[] = $bot;
			}
		}
		return $new;
	}

	/**
	 * Check for updates once per day when an admin visits the CrawlerToll page.
	 * Avoids a full HTTP request on every admin page load.
	 *
	 * @return void
	 */
	public static function maybe_check_for_updates() {
		// Only on our settings page.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page !== 'crawlertoll' ) {
			return;
		}
		// phpcs:enable

		$last_check = get_transient( 'crawlertoll_catalogue_last_check' );
		if ( $last_check !== false ) {
			return; // Checked within the last day.
		}

		self::fetch_and_merge();
		set_transient( 'crawlertoll_catalogue_last_check', time(), DAY_IN_SECONDS );
	}

	/**
	 * Show admin notice if new bots were found.
	 *
	 * @return void
	 */
	public static function maybe_show_new_bots_notice() {
		$count = get_option( 'crawlertoll_new_bots_found', 0 );
		if ( $count <= 0 ) {
			return;
		}

		$new_bots = get_option( 'crawlertoll_new_bots_list', array() );
		$names = array();
		foreach ( array_slice( $new_bots, 0, 5 ) as $bot ) {
			$names[] = $bot['name'];
		}
		$name_list = implode( ', ', $names );
		if ( $count > 5 ) {
			$name_list .= ' ' . sprintf(
				/* translators: %d: number of additional bots */
				__( 'and %d more', 'crawlertoll' ),
				$count - 5
			);
		}

		echo '<div class="notice notice-info is-dismissible"><p>';
		printf(
			/* translators: 1: list of bot names, 2: count */
			esc_html__( 'CrawlerToll: %2$d new AI crawler(s) detected: %1$s. Added to your catalogue automatically. ', 'crawlertoll' ),
			esc_html( $name_list ),
			(int) $count
		);
		echo '</p></div>';

		// Clear after showing once.
		delete_option( 'crawlertoll_new_bots_found' );
		delete_option( 'crawlertoll_new_bots_list' );
	}

	/**
	 * Clear scheduled hook on deactivation.
	 *
	 * @return void
	 */
	public static function clear_cron() {
		wp_clear_scheduled_hook( 'crawlertoll_catalogue_update' );
	}
}
