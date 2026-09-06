<?php
/**
 * Error guard (W4, 2026-09-06): CrawlerToll must never white-screen a site.
 *
 * Every front-end hook, content filter and REST handler runs inside
 * CrawlerToll_Guard::run(). An exception or PHP error inside the plugin is
 * caught, recorded in a small ring-buffer log (wp_options, max 50 distinct
 * signatures, deduplicated), surfaced to admins as a dismissible notice and
 * on the Settings → Advanced card with copyable diagnostics — and the request
 * continues:
 *   - enforcement degrades OPEN (a broken decision path serves the page normally),
 *   - sealing stays CLOSED (a broken gate filter returns nothing, never the body),
 *   - REST handlers answer a generic 500 without leaking internals.
 * A shutdown handler also records fatals raised from inside the plugin's own
 * files, which is the only way to see a parse/compile error after an update.
 *
 * On "protecting the code": WordPress plugins are GPL and PHP ships as source;
 * there is nothing to hide and obfuscation is against wp.org rules. What this
 * class protects is the publisher's site — from us.
 *
 * Ships in BOTH builds, free-safe (no Pro dependencies).
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Guard {

	const OPTION_LOG   = 'crawlertoll_error_log';
	const OPTION_BADGE = 'crawlertoll_error_badge';
	const MAX_ENTRIES  = 50;

	/** Fallback modes for wrap(). */
	const FALLBACK_VOID        = 'void';        // actions: return null
	const FALLBACK_PASSTHROUGH = 'passthrough'; // filters on structures: return the first argument untouched
	const FALLBACK_EMPTY       = 'empty';       // text filters on premium content: return '' (never the body)

	/**
	 * Run $fn; on any Throwable record it and return $fallback.
	 *
	 * @param string   $label    Where it happened (e.g. "gate.the_content").
	 * @param callable $fn
	 * @param mixed    $fallback
	 * @return mixed
	 */
	public static function run( $label, $fn, $fallback = null ) {
		try {
			return call_user_func( $fn );
		} catch ( \Throwable $e ) {
			self::record( $label, $e );
			return $fallback;
		}
	}

	/**
	 * Wrap a hook callable so WordPress calls a guarded version.
	 *
	 * @param callable $callable
	 * @param string   $label
	 * @param string   $mode     One of the FALLBACK_* constants.
	 * @return \Closure
	 */
	public static function wrap( $callable, $label, $mode = self::FALLBACK_VOID ) {
		return function () use ( $callable, $label, $mode ) {
			$args = func_get_args();
			try {
				return call_user_func_array( $callable, $args );
			} catch ( \Throwable $e ) {
				self::record( $label, $e );
				if ( self::FALLBACK_PASSTHROUGH === $mode ) {
					return isset( $args[0] ) ? $args[0] : null;
				}
				if ( self::FALLBACK_EMPTY === $mode ) {
					return '';
				}
				return null;
			}
		};
	}

	/**
	 * Guard a REST handler: exceptions become a generic 500 (no internals leak).
	 *
	 * @param callable $callable
	 * @param string   $label
	 * @return \Closure
	 */
	public static function rest( $callable, $label ) {
		return function ( $request ) use ( $callable, $label ) {
			try {
				return call_user_func( $callable, $request );
			} catch ( \Throwable $e ) {
				self::record( $label, $e );
				return new WP_Error( 'crawlertoll_internal', 'CrawlerToll hit an internal error. The site owner can see details under Settings → CrawlerToll → Advanced.', array( 'status' => 500 ) );
			}
		};
	}

	/**
	 * Record a throwable (or a fatal from the shutdown handler) into the ring buffer.
	 *
	 * @param string           $label
	 * @param \Throwable|array $e     Throwable, or ['message','file','line'] from error_get_last().
	 * @return void
	 */
	public static function record( $label, $e ) {
		$message = $e instanceof \Throwable ? $e->getMessage() : ( isset( $e['message'] ) ? (string) $e['message'] : 'unknown' );
		$file    = $e instanceof \Throwable ? $e->getFile() : ( isset( $e['file'] ) ? (string) $e['file'] : '' );
		$line    = $e instanceof \Throwable ? $e->getLine() : ( isset( $e['line'] ) ? (int) $e['line'] : 0 );
		$type    = $e instanceof \Throwable ? get_class( $e ) : 'fatal';
		self::store( array(
			'label'   => (string) $label,
			'type'    => $type,
			'message' => mb_substr( (string) $message, 0, 500 ),
			'file'    => self::relative_path( $file ),
			'line'    => (int) $line,
		) );
		if ( function_exists( 'error_log' ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[CrawlerToll] %s: %s in %s:%d', $label, $message, $file, $line ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Pure ring-buffer write: dedupe on signature (count + last_seen), cap size.
	 *
	 * @param array $entry {label,type,message,file,line}
	 * @return void
	 */
	public static function store( $entry ) {
		$log = get_option( self::OPTION_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$sig = md5( $entry['label'] . '|' . $entry['type'] . '|' . $entry['message'] . '|' . $entry['file'] . '|' . $entry['line'] );
		$now = time();
		if ( isset( $log[ $sig ] ) ) {
			$log[ $sig ]['count']     = (int) $log[ $sig ]['count'] + 1;
			$log[ $sig ]['last_seen'] = $now;
		} else {
			$log[ $sig ] = array_merge( $entry, array( 'count' => 1, 'first_seen' => $now, 'last_seen' => $now ) );
			if ( count( $log ) > self::MAX_ENTRIES ) {
				uasort( $log, function ( $a, $b ) { return $a['last_seen'] <=> $b['last_seen']; } );
				$log = array_slice( $log, count( $log ) - self::MAX_ENTRIES, null, true );
			}
		}
		update_option( self::OPTION_LOG, $log, false );
		update_option( self::OPTION_BADGE, (int) get_option( self::OPTION_BADGE, 0 ) + 1, false );
	}

	/**
	 * Newest first.
	 *
	 * @return array<int,array>
	 */
	public static function entries() {
		$log = get_option( self::OPTION_LOG, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}
		uasort( $log, function ( $a, $b ) { return $b['last_seen'] <=> $a['last_seen']; } );
		return array_values( $log );
	}

	/** @return int Errors recorded since the admin last looked. */
	public static function badge() {
		return (int) get_option( self::OPTION_BADGE, 0 );
	}

	/** @return void */
	public static function mark_seen() {
		update_option( self::OPTION_BADGE, 0, false );
	}

	/** @return void */
	public static function clear() {
		delete_option( self::OPTION_LOG );
		delete_option( self::OPTION_BADGE );
	}

	/**
	 * Register the fatal-error shutdown handler. Only errors raised from inside
	 * the plugin's own directory are recorded — we are not a general error log.
	 *
	 * @return void
	 */
	public static function register_shutdown_handler() {
		register_shutdown_function( function () {
			$err = error_get_last();
			if ( ! is_array( $err ) || ! in_array( (int) $err['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
				return;
			}
			$file = isset( $err['file'] ) ? (string) $err['file'] : '';
			if ( '' === $file || ! defined( 'CRAWLERTOLL_PLUGIN_DIR' ) || 0 !== strpos( wp_normalize_path( $file ), wp_normalize_path( CRAWLERTOLL_PLUGIN_DIR ) ) ) {
				return;
			}
			self::record( 'fatal', $err );
		} );
	}

	/**
	 * Diagnostics bundle for support — no secrets, no reader data.
	 *
	 * @return array
	 */
	public static function diagnostics() {
		global $wp_version;
		$settings = function_exists( 'crawlertoll_get_settings' ) ? crawlertoll_get_settings() : array();
		$registry = class_exists( 'CrawlerToll_Registry' ) ? ( defined( 'CRAWLERTOLL_REGISTRY_URL' ) ? CRAWLERTOLL_REGISTRY_URL : CrawlerToll_Registry::REGISTRY_URL ) : '';
		$reach    = get_transient( 'crawlertoll_registry_health' );
		if ( false === $reach && $registry ) {
			$res   = wp_remote_get( $registry . '/health', array( 'timeout' => 5 ) );
			$reach = is_wp_error( $res ) ? 'unreachable: ' . $res->get_error_message() : 'http ' . wp_remote_retrieve_response_code( $res );
			set_transient( 'crawlertoll_registry_health', $reach, 300 );
		}
		return array(
			'plugin_version'   => defined( 'CRAWLERTOLL_VERSION' ) ? CRAWLERTOLL_VERSION : '',
			'pro_active'       => class_exists( 'CrawlerToll_Pro_Admin' ) ? CrawlerToll_Pro_Admin::is_pro_active() : false,
			'wp_version'       => isset( $wp_version ) ? $wp_version : '',
			'php_version'      => PHP_VERSION,
			'home_scheme'      => wp_parse_url( home_url(), PHP_URL_SCHEME ),
			'permalinks'       => (string) get_option( 'permalink_structure' ),
			'theme'            => function_exists( 'wp_get_theme' ) ? (string) wp_get_theme()->get( 'Name' ) : '',
			'active_plugins'   => count( (array) get_option( 'active_plugins', array() ) ),
			'enforcement'      => ! empty( $settings['enabled'] ),
			'rail'             => isset( $settings['rail'] ) ? (string) $settings['rail'] : '',
			'stripe_configured'=> class_exists( 'CrawlerToll_Stripe' ) ? CrawlerToll_Stripe::is_configured( $settings ) : false,
			'usdc_address_set' => ! empty( $settings['x402_pay_to'] ),
			'registry'         => $registry,
			'registry_health'  => $reach,
			'registry_enrolled'=> class_exists( 'CrawlerToll_Registry' ) ? CrawlerToll_Registry::is_registered() : false,
			'errors'           => array_slice( self::entries(), 0, 10 ),
		);
	}

	/**
	 * @param string $file
	 * @return string
	 */
	private static function relative_path( $file ) {
		$file = (string) $file;
		if ( defined( 'CRAWLERTOLL_PLUGIN_DIR' ) && 0 === strpos( $file, CRAWLERTOLL_PLUGIN_DIR ) ) {
			return 'crawlertoll/' . substr( $file, strlen( CRAWLERTOLL_PLUGIN_DIR ) );
		}
		return basename( $file );
	}
}
