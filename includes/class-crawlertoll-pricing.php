<?php
/**
 * Per-path pricing resolver. Extends the RSL policy with path-specific
 * price overrides. Longest-prefix wins — same semantics as RFC 9309.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Pricing {

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
	 * Resolve the price for a given path. Checks per-path overrides first,
	 * falls back to the default price from settings.
	 *
	 * @param string              $path     Request path.
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return array{price_micros: int, currency: string, matched_rule: string|null}
	 */
	public function resolve( $path, $settings ) {
		$overrides = isset( $settings['path_pricing'] ) ? $settings['path_pricing'] : array();
		if ( ! is_array( $overrides ) || empty( $overrides ) ) {
			return array(
				'price_micros' => isset( $settings['price_micros'] ) ? (int) $settings['price_micros'] : 5000,
				'currency'     => isset( $settings['currency'] ) ? $settings['currency'] : 'USD',
				'matched_rule' => null,
				'meter'        => null,
			);
		}

		// Sort overrides by path length descending — longest match wins.
		usort( $overrides, function ( $a, $b ) {
			return strlen( $b['path'] ) - strlen( $a['path'] );
		} );

		foreach ( $overrides as $rule ) {
			$pattern = isset( $rule['path'] ) ? $rule['path'] : '';
			if ( $pattern === '' ) {
				continue;
			}
			$meter = self::meter_of_rule( $rule );
			// Support wildcard matching: /premium/* matches /premium/report/
			if ( substr( $pattern, -1 ) === '*' ) {
				$prefix = rtrim( $pattern, '*' );
				if ( strpos( $path, $prefix ) === 0 ) {
					return array(
						'price_micros' => isset( $rule['price_micros'] ) ? (int) $rule['price_micros'] : 5000,
						'currency'     => isset( $rule['currency'] ) ? $rule['currency'] : ( isset( $settings['currency'] ) ? $settings['currency'] : 'USD' ),
						'matched_rule' => $pattern,
						'meter'        => $meter,
					);
				}
			}
			// Exact-prefix match.
			if ( strpos( $path, $pattern ) === 0 ) {
				return array(
					'price_micros' => isset( $rule['price_micros'] ) ? (int) $rule['price_micros'] : 5000,
					'currency'     => isset( $rule['currency'] ) ? $rule['currency'] : ( isset( $settings['currency'] ) ? $settings['currency'] : 'USD' ),
					'matched_rule' => $pattern,
					'meter'        => $meter,
				);
			}
		}

		// Fallback to default.
		return array(
			'price_micros' => isset( $settings['price_micros'] ) ? (int) $settings['price_micros'] : 5000,
			'currency'     => isset( $settings['currency'] ) ? $settings['currency'] : 'USD',
			'matched_rule' => null,
			'meter'        => null,
		);
	}

	/**
	 * Meter config from a path rule (metered free articles, Pro — spec:
	 * docs/specs/metered-free-articles-v1.md). Null when the rule has no meter.
	 * The free-safe runtime resolver used at seal time is CrawlerToll_Meter
	 * (includes/class-crawlertoll-meter.php) — the sealing engine ships in the
	 * free build and must not depend on this Pro-only class.
	 *
	 * @param array $rule Path-pricing rule.
	 * @return array{count:int,window_days:int,path:string}|null
	 */
	public static function meter_of_rule( $rule ) {
		$count = isset( $rule['meter_count'] ) ? (int) $rule['meter_count'] : 0;
		if ( $count <= 0 ) {
			return null;
		}
		return array(
			'count'       => min( 50, $count ),
			'window_days' => isset( $rule['meter_window'] ) && (int) $rule['meter_window'] > 0 ? min( 365, (int) $rule['meter_window'] ) : 30,
			'path'        => isset( $rule['path'] ) ? (string) $rule['path'] : '/',
		);
	}

	/**
	 * Resolve the settlement rail for a given bot (multi-rail routing, §2.5).
	 *
	 * Pro publishers can map specific bots to specific rails via
	 * settings['rail_overrides'] (bot_name => rail). Any bot without an
	 * override — and every bot for a free publisher, who has no overrides —
	 * falls back to the site default rail. Pure: no DB/IO, so it is
	 * unit-testable and cheap enough for the request hot path.
	 *
	 * @param string              $bot_name Matched bot name (e.g. "GPTBot").
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return string Rail key (e.g. "x402").
	 */
	public static function resolve_rail( $bot_name, $settings ) {
		$default = isset( $settings['rail'] ) ? (string) $settings['rail'] : 'x402';

		if ( '' === (string) $bot_name ) {
			return $default;
		}

		$overrides = isset( $settings['rail_overrides'] ) ? $settings['rail_overrides'] : array();
		if ( ! is_array( $overrides ) ) {
			return $default;
		}

		return ( isset( $overrides[ $bot_name ] ) && '' !== $overrides[ $bot_name ] )
			? (string) $overrides[ $bot_name ]
			: $default;
	}

	/**
	 * Revenue summary for the dashboard.
	 *
	 * @param string $from Start date (Y-m-d).
	 * @param string $to   End date (Y-m-d).
	 * @return array<string,mixed>
	 */
	public function revenue_summary( $from, $to ) {
		return $this->db->stats( $from, $to );
	}

	/**
	 * Revenue comparison vs previous period.
	 *
	 * @param string $from Start date (Y-m-d).
	 * @param string $to   End date (Y-m-d).
	 * @return array{current: array, previous: array, change_pct: float}|null
	 */
	public function revenue_comparison( $from, $to ) {
		$from_dt  = new DateTime( $from );
		$to_dt    = new DateTime( $to );
		$interval = $from_dt->diff( $to_dt );
		$days     = (int) $interval->days;

		if ( $days <= 0 ) {
			return null;
		}

		$prev_from = ( clone $from_dt )->modify( "-{$days} days" )->format( 'Y-m-d' );
		$prev_to   = ( clone $to_dt )->modify( "-{$days} days" )->format( 'Y-m-d' );

		$current  = $this->db->stats( $from, $to );
		$previous = $this->db->stats( $prev_from, $prev_to );

		$current_rev  = isset( $current['totals']['total_revenue_micros'] ) ? (int) $current['totals']['total_revenue_micros'] : 0;
		$previous_rev = isset( $previous['totals']['total_revenue_micros'] ) ? (int) $previous['totals']['total_revenue_micros'] : 0;

		$change_pct = 0.0;
		if ( $previous_rev > 0 ) {
			$change_pct = round( ( ( $current_rev - $previous_rev ) / $previous_rev ) * 100, 1 );
		} elseif ( $current_rev > 0 ) {
			$change_pct = 100.0; // From zero to something = 100% increase.
		}

		return array(
			'current'    => $current,
			'previous'   => $previous,
			'change_pct' => $change_pct,
		);
	}
}
