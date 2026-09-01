<?php
/**
 * Email-gate subscribers (Pro, A5, spec §5.5) — the site's OWN reader list for
 * "read free with your email" unlocks.
 *
 * Data-protection posture: the WP site is the data controller. This table is
 * the ONLY place the raw address is stored at rest — the registry sees the
 * address in transit for the magic-link send and keeps only its sha256. Every
 * row carries the exact consent text the reader was shown plus the timestamp,
 * so the Readers tab CSV doubles as the consent audit trail. Rows whose
 * marketing consent is absent are never exported for marketing.
 *
 * Pro-only: this file is stripped from the free build (build.sh PREMIUM_ONLY)
 * and every reference elsewhere is class_exists/is_pro_active-guarded. The
 * table is created LAZILY (schema-version option) rather than only in the
 * activation hook, so existing installs upgrading to the A5 build get it
 * without a deactivate/reactivate cycle.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Subscribers {

	/**
	 * Schema version — bump when the DDL changes; maybe_create_table() re-runs
	 * dbDelta when the stored version lags.
	 */
	const DB_VERSION = 1;

	const VERSION_OPTION = 'crawlertoll_subs_dbv';

	/**
	 * Create or migrate the subscribers table when the schema version lags.
	 * Safe to call on every request — one get_option, no DDL in steady state.
	 *
	 * @return void
	 */
	public static function maybe_create_table() {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::DB_VERSION ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$table   = $wpdb->prefix . 'crawlertoll_subscribers';
		$collate = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			email VARCHAR(190) NOT NULL,
			email_sha256 CHAR(64) NOT NULL,
			consent_marketing TINYINT(1) NOT NULL DEFAULT 0,
			consent_text VARCHAR(300) NOT NULL DEFAULT '',
			consent_at DATETIME DEFAULT NULL,
			verified TINYINT(1) NOT NULL DEFAULT 0,
			verified_at DATETIME DEFAULT NULL,
			content_id VARCHAR(190) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			UNIQUE KEY uq_email_sha256 (email_sha256),
			INDEX idx_marketing (consent_marketing, verified),
			INDEX idx_created (created_at)
		) {$collate};";
		dbDelta( $sql );
		update_option( self::VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Lowercase-trim an address and return its sha256 (the join key with the
	 * registry's verification marker).
	 *
	 * @param string $email
	 * @return string
	 */
	public static function hash_email( $email ) {
		return hash( 'sha256', strtolower( trim( (string) $email ) ) );
	}

	/**
	 * Record (or refresh) a reader's email-gate request. One row per address:
	 * a repeat request updates the consent snapshot + the article that brought
	 * them in, never duplicates. consent_at moves only when the marketing
	 * checkbox state changes — the audit trail keeps the moment consent was
	 * given (or withdrawn).
	 *
	 * @param string $email             Validated reader address.
	 * @param bool   $consent_marketing Marketing checkbox state.
	 * @param string $consent_text      The exact label shown beside the checkbox.
	 * @param string $content_id        Article the reader requested.
	 * @return bool True on success.
	 */
	public static function upsert_request( $email, $consent_marketing, $consent_text, $content_id ) {
		self::maybe_create_table();
		global $wpdb;
		$table = $wpdb->prefix . 'crawlertoll_subscribers';
		$sha   = self::hash_email( $email );
		$now   = current_time( 'mysql' );
		$email = strtolower( trim( (string) $email ) );

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email_sha256 = %s", $sha ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing ) {
			$consent_changed = (int) $existing['consent_marketing'] !== ( $consent_marketing ? 1 : 0 )
				|| (string) $existing['consent_text'] !== (string) $consent_text;
			$wpdb->update(
				$table,
				array(
					'email'             => $email,
					'consent_marketing' => $consent_marketing ? 1 : 0,
					'consent_text'      => mb_substr( (string) $consent_text, 0, 300 ),
					'consent_at'        => $consent_changed ? $now : $existing['consent_at'],
					'content_id'        => (string) $content_id,
					'updated_at'        => $now,
				),
				array( 'email_sha256' => $sha )
			);
			return true;
		}
		return (bool) $wpdb->insert(
			$table,
			array(
				'email'             => $email,
				'email_sha256'      => $sha,
				'consent_marketing' => $consent_marketing ? 1 : 0,
				'consent_text'      => mb_substr( (string) $consent_text, 0, 300 ),
				'consent_at'        => $now,
				'verified'          => 0,
				'verified_at'       => null,
				'content_id'        => (string) $content_id,
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
	}

	/**
	 * Mark a subscriber verified after the registry confirms the magic-link
	 * token was redeemed. Fail-open by contract of the caller (the reader
	 * already holds the CEK — this is bookkeeping).
	 *
	 * @param string $email_sha256
	 * @param string $verified_at  ISO timestamp from the registry (or now).
	 * @return bool
	 */
	public static function mark_verified( $email_sha256, $verified_at = '' ) {
		self::maybe_create_table();
		global $wpdb;
		$table = $wpdb->prefix . 'crawlertoll_subscribers';
		$ts    = $verified_at ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $verified_at ) ) : current_time( 'mysql', true );
		return (bool) $wpdb->update(
			$table,
			array(
				'verified'    => 1,
				'verified_at' => $ts,
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'email_sha256' => (string) $email_sha256 )
		);
	}

	/**
	 * Recent subscribers for the Readers tab (newest first).
	 *
	 * @param int $limit
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( $limit = 500 ) {
		self::maybe_create_table();
		global $wpdb;
		$table = $wpdb->prefix . 'crawlertoll_subscribers';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The marketing export: ONLY rows with explicit marketing consent. Columns
	 * double as the consent audit trail (spec §5.5) — address, the exact text
	 * agreed to, when, and whether the address was verified by link click.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function export_marketing() {
		self::maybe_create_table();
		global $wpdb;
		$table = $wpdb->prefix . 'crawlertoll_subscribers';
		$rows  = $wpdb->get_results( "SELECT email, consent_text, consent_at, verified, verified_at, created_at FROM {$table} WHERE consent_marketing = 1 ORDER BY consent_at DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_array( $rows ) ? $rows : array();
	}
}
