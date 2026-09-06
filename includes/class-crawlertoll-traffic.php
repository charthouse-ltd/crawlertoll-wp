<?php
/**
 * Traffic visibility (W5, 2026-09-06): who is actually at the door.
 *
 * Until now the plugin only saw declared AI crawlers. Publishers asked the
 * obvious question — what about everyone else? This class classifies EVERY
 * front-end request into four kinds and keeps small daily counters:
 *
 *   browser        a person (or something indistinguishable from one)
 *   ai_crawler     a declared AI crawler from the catalogue (charged/blocked)
 *   search_engine  Googlebot & co. — never charged (safe mode)
 *   automation     tooling that does not declare itself as an AI crawler:
 *                  curl, python-requests, headless browsers, generic
 *                  "bot/spider" strings, empty user agents. These are exactly
 *                  the visitors a user-agent paywall cannot bill — and exactly
 *                  the ones sealing handles: they get the encrypted body.
 *
 * Plus the funnel on sealed content: sealed-page views → walls shown →
 * unlocks by rail (reported by the unlock app via a public beacon).
 *
 * Storage: one autoload=false option holding a rolling window of daily rows,
 * no DB table, free-safe. Counters are best-effort (a lost race under-counts
 * by one; nothing here is billing). Classification is by user agent only —
 * honest about its limits: a headless browser with a real UA is a "browser".
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Traffic {

	const OPTION      = 'crawlertoll_traffic';
	const OPTION_UA   = 'crawlertoll_traffic_ua';
	const WINDOW_DAYS = 60;
	const UA_MAX      = 40;

	const CLASSES = array( 'browser', 'ai_crawler', 'search_engine', 'automation' );
	const EVENTS  = array( 'sealed_view', 'wall_shown', 'wall_unavailable', 'unlock_stripe', 'unlock_x402', 'unlock_meter', 'unlock_email', 'unlock_renewal', 'unlock_cache' );

	/** Substrings (lower-case) that mark undeclared automation. Checked AFTER the AI catalogue + search safelist. */
	const AUTOMATION_MARKERS = array(
		'python-requests', 'python-urllib', 'aiohttp', 'httpx/', 'scrapy', 'curl/', 'wget/', 'libwww', 'lwp-trivial',
		'go-http-client', 'java/', 'okhttp', 'apache-httpclient', 'axios/', 'node-fetch', 'undici', 'got/', 'guzzle',
		'headlesschrome', 'phantomjs', 'selenium', 'puppeteer', 'playwright', 'chrome-lighthouse',
		'bot', 'spider', 'crawler', 'crawl', 'scraper', 'fetch', 'monitor', 'validator', 'archive', 'http_request',
	);

	/**
	 * Classify a request. Pure.
	 *
	 * @param string     $user_agent
	 * @param array|null $decision   The decision tree result for this request (bot => catalogue entry|null, reasons).
	 * @return string One of CLASSES.
	 */
	public static function classify( $user_agent, $decision = null ) {
		$ua = strtolower( trim( (string) $user_agent ) );
		if ( is_array( $decision ) && ! empty( $decision['bot'] ) ) {
			return 'ai_crawler';
		}
		if ( is_array( $decision ) && ! empty( $decision['reasons'] ) && in_array( 'safe-mode', (array) $decision['reasons'], true ) ) {
			return 'search_engine';
		}
		if ( class_exists( 'CrawlerToll_SafeMode' ) && CrawlerToll_SafeMode::is_safe( $ua ) ) {
			return 'search_engine';
		}
		if ( '' === $ua || strlen( $ua ) < 12 ) {
			return 'automation';
		}
		foreach ( self::AUTOMATION_MARKERS as $m ) {
			if ( false !== strpos( $ua, $m ) ) {
				return 'automation';
			}
		}
		// A real browser UA names an engine; anything without one is tooling.
		if ( false === strpos( $ua, 'mozilla' ) && false === strpos( $ua, 'applewebkit' ) && false === strpos( $ua, 'gecko' ) ) {
			return 'automation';
		}
		return 'browser';
	}

	/**
	 * Count one request (and, when the request targets a sealed post, a sealed view).
	 *
	 * @param string $class       One of CLASSES.
	 * @param bool   $sealed_view
	 * @param string $user_agent  Kept (truncated) for the automation examples list only.
	 * @return void
	 */
	public static function count_request( $class, $sealed_view = false, $user_agent = '' ) {
		if ( ! in_array( $class, self::CLASSES, true ) ) {
			return;
		}
		$keys = array( $class );
		if ( $sealed_view ) {
			$keys[] = 'sealed_view';
		}
		self::bump( $keys );
		if ( 'automation' === $class ) {
			self::remember_ua( $user_agent );
		}
	}

	/**
	 * Count a wall event reported by the unlock app.
	 *
	 * @param string $event One of EVENTS.
	 * @return bool False when the event name is unknown.
	 */
	public static function count_event( $event ) {
		if ( ! in_array( $event, self::EVENTS, true ) ) {
			return false;
		}
		self::bump( array( $event ) );
		return true;
	}

	/**
	 * Increment today's counters. One read + one write of an autoload=false option.
	 *
	 * @param array<int,string> $keys
	 * @return void
	 */
	private static function bump( $keys ) {
		$day  = gmdate( 'Y-m-d' );
		$data = get_option( self::OPTION, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		if ( ! isset( $data[ $day ] ) || ! is_array( $data[ $day ] ) ) {
			$data[ $day ] = array();
		}
		foreach ( $keys as $k ) {
			$data[ $day ][ $k ] = ( isset( $data[ $day ][ $k ] ) ? (int) $data[ $day ][ $k ] : 0 ) + 1;
		}
		if ( count( $data ) > self::WINDOW_DAYS ) {
			ksort( $data );
			$data = array_slice( $data, -self::WINDOW_DAYS, null, true );
		}
		update_option( self::OPTION, $data, false );
	}

	/**
	 * Keep the most frequent undeclared user agents (truncated, no IPs).
	 *
	 * @param string $user_agent
	 * @return void
	 */
	private static function remember_ua( $user_agent ) {
		$ua = mb_substr( trim( (string) $user_agent ), 0, 120 );
		if ( '' === $ua ) {
			$ua = '(empty user agent)';
		}
		$map = get_option( self::OPTION_UA, array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		$map[ $ua ] = ( isset( $map[ $ua ] ) ? (int) $map[ $ua ] : 0 ) + 1;
		if ( count( $map ) > self::UA_MAX ) {
			arsort( $map );
			$map = array_slice( $map, 0, self::UA_MAX, true );
		}
		update_option( self::OPTION_UA, $map, false );
	}

	/**
	 * Aggregate the last $days. Pure over the stored rows.
	 *
	 * @param int        $days
	 * @param array|null $data  Optional injected rows (tests).
	 * @param array|null $uas   Optional injected UA map (tests).
	 * @return array
	 */
	public static function summary( $days = 30, $data = null, $uas = null ) {
		$days = max( 1, min( self::WINDOW_DAYS, (int) $days ) );
		$data = is_array( $data ) ? $data : get_option( self::OPTION, array() );
		$uas  = is_array( $uas ) ? $uas : get_option( self::OPTION_UA, array() );
		$since = gmdate( 'Y-m-d', time() - ( $days - 1 ) * 86400 );
		$tot   = array_fill_keys( array_merge( self::CLASSES, self::EVENTS ), 0 );
		$series = array();
		if ( is_array( $data ) ) {
			ksort( $data );
			foreach ( $data as $day => $row ) {
				if ( ! is_string( $day ) || $day < $since || ! is_array( $row ) ) {
					continue;
				}
				$point = array( 'day' => $day );
				foreach ( array_keys( $tot ) as $k ) {
					$v            = isset( $row[ $k ] ) ? (int) $row[ $k ] : 0;
					$tot[ $k ]   += $v;
					$point[ $k ]  = $v;
				}
				$series[] = $point;
			}
		}
		$unlocks_paid = $tot['unlock_stripe'] + $tot['unlock_x402'];
		$unlocks_all  = $unlocks_paid + $tot['unlock_meter'] + $tot['unlock_email'] + $tot['unlock_renewal'] + $tot['unlock_cache'];
		arsort( $uas );
		$top_ua = array();
		foreach ( array_slice( $uas, 0, 10, true ) as $ua => $n ) {
			$top_ua[] = array( 'ua' => (string) $ua, 'count' => (int) $n );
		}
		return array(
			'days'    => $days,
			'classes' => array(
				'browser'       => $tot['browser'],
				'ai_crawler'    => $tot['ai_crawler'],
				'search_engine' => $tot['search_engine'],
				'automation'    => $tot['automation'],
			),
			'funnel'  => array(
				'sealed_views'     => $tot['sealed_view'],
				'walls_shown'      => $tot['wall_shown'],
				'walls_unavailable'=> $tot['wall_unavailable'],
				'unlocks'          => $unlocks_all,
				'paid_unlocks'     => $unlocks_paid,
				'by_rail'          => array(
					'stripe'  => $tot['unlock_stripe'],
					'x402'    => $tot['unlock_x402'],
					'meter'   => $tot['unlock_meter'],
					'email'   => $tot['unlock_email'],
					'renewal' => $tot['unlock_renewal'],
					'cache'   => $tot['unlock_cache'],
				),
				'wall_rate'   => $tot['sealed_view'] > 0 ? round( $tot['wall_shown'] / $tot['sealed_view'], 3 ) : 0,
				'unlock_rate' => $tot['wall_shown'] > 0 ? round( $unlocks_all / $tot['wall_shown'], 3 ) : 0,
			),
			'top_automation' => $top_ua,
			'series'         => $series,
		);
	}
}
