<?php
/**
 * Version upgrader (2026-09-16).
 *
 * A WordPress auto-update does NOT fire the activation hook, so anything the
 * activation hook sets up must also happen here or an upgraded site silently
 * loses it: the Pro log table (dbDelta, idempotent), the rewrite rules that
 * serve /.well-known/context-license.json (registry enrollment depends on it)
 * and the settings row. Runs once per plugin version, on plugins_loaded.
 *
 * Guarded: a failure is recorded in the error log, never fatal, and the marker
 * stays unset so the next request retries. Free-safe (the DB class is Pro-only
 * and checked with class_exists).
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Upgrader {

	/** Last plugin version whose upgrade tasks completed. */
	const OPTION = 'crawlertoll_schema_version';

	/**
	 * @return bool True when the upgrade tasks ran (and completed) this request.
	 */
	public static function maybe_upgrade() {
		if ( ! defined( 'CRAWLERTOLL_VERSION' ) || get_option( self::OPTION ) === CRAWLERTOLL_VERSION ) {
			return false;
		}
		$ran = CrawlerToll_Guard::run( 'upgrade', array( __CLASS__, 'upgrade' ), false );
		return true === $ran;
	}

	/**
	 * The tasks. Every step is idempotent; the marker is written last so a
	 * throw in any step leaves the upgrade pending.
	 *
	 * @return bool
	 */
	public static function upgrade() {
		if ( defined( 'CRAWLERTOLL_OPTION_KEY' ) && function_exists( 'crawlertoll_default_settings' ) && false === get_option( CRAWLERTOLL_OPTION_KEY ) ) {
			add_option( CRAWLERTOLL_OPTION_KEY, crawlertoll_default_settings() );
		}
		if ( class_exists( 'CrawlerToll_DB' ) && isset( $GLOBALS['wpdb'] ) ) {
			( new CrawlerToll_DB( $GLOBALS['wpdb'] ) )->maybe_create_table();
		}
		// Regenerated on the next parse_request with this version's routes registered.
		delete_option( 'rewrite_rules' );
		update_option( self::OPTION, CRAWLERTOLL_VERSION );
		return true;
	}
}
