<?php
/**
 * Access tiers (Pro) — plugin-side resolver.
 * Spec: docs/specs/access-tiers-v1.md §2/§5.1 (2026-08-28).
 *
 * A path-pricing rule may carry up to 4 {price_micros, duration_hours|null}
 * tiers: "$0.005 = 24 hours", "$0.05 = no expiry". No tiers = today's legacy
 * single-price behavior. duration_hours null = "no expiry" (the honest label
 * — never "forever", spec §7).
 *
 * Free-safe by design — same discipline as CrawlerToll_Meter: this class
 * ships in BOTH builds (the sealing engine references it and must not depend
 * on Pro-only classes — see tests/cut-scope.php). Tiers are Pro-only:
 * is_pro_active() is always false in the free build, so resolve_for_post()
 * returns null there and sealed content registers without tier meta. The
 * path matcher duplicates the longest-prefix discipline of the (Pro-only)
 * pricing resolver, tiers-only, so this file carries no Pro dependency.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Tiers {

	const MAX_TIERS     = 4;
	const MAX_DURATIONH = 8760; // 1 year — registry bound (src/tiers.js).

	/**
	 * Sanitize raw tier rows (settings save + seal time). Keeps up to
	 * MAX_TIERS valid rows; returns null when nothing valid remains
	 * (fail-closed to legacy single-price, spec §4.2).
	 *
	 * @param mixed $raw Raw rows from settings input.
	 * @return array<int,array{price_micros:int,duration_hours:int|null}>|null
	 */
	public static function sanitize_rows( $raw ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$out = array();
		foreach ( $raw as $row ) {
			if ( count( $out ) >= self::MAX_TIERS ) {
				break;
			}
			if ( ! is_array( $row ) ) {
				continue;
			}
			$micros = isset( $row['price_micros'] ) ? (int) $row['price_micros'] : 0;
			if ( $micros <= 0 ) {
				continue;
			}
			$dur = null;
			if ( isset( $row['duration_hours'] ) && '' !== $row['duration_hours'] && null !== $row['duration_hours'] ) {
				$d = (int) $row['duration_hours'];
				if ( $d < 1 || $d > self::MAX_DURATIONH ) {
					continue;
				}
				$dur = $d;
			}
			$out[] = array(
				'price_micros'   => $micros,
				'duration_hours' => $dur,
			);
		}
		return empty( $out ) ? null : $out;
	}

	/**
	 * Resolve the tier set for a post being sealed: match the post's permalink
	 * path against the per-path pricing rules (longest prefix wins, trailing *
	 * wildcard), Pro-gated.
	 *
	 * @param int   $post_id
	 * @param array $settings Plugin settings.
	 * @return array<int,array{price_micros:int,duration_hours:int|null}>|null Null = legacy single-price.
	 */
	public static function resolve_for_post( $post_id, $settings ) {
		if ( ! class_exists( 'CrawlerToll_Pro_Admin' ) || ! CrawlerToll_Pro_Admin::is_pro_active() ) {
			return null;
		}
		$permalink = get_permalink( (int) $post_id );
		$path      = $permalink ? wp_parse_url( $permalink, PHP_URL_PATH ) : null;
		if ( ! is_string( $path ) || '' === $path ) {
			return null;
		}
		return self::resolve_for_path( $path, $settings );
	}

	/**
	 * Match a request path against the per-path rules and return the tier set
	 * of the winning rule. Pure — unit-testable without WP.
	 *
	 * @param string $path     Request path (e.g. /articles/my-post).
	 * @param array  $settings Plugin settings.
	 * @return array<int,array{price_micros:int,duration_hours:int|null}>|null
	 */
	public static function resolve_for_path( $path, $settings ) {
		$rule = self::matching_rule( $path, $settings );
		if ( null === $rule ) {
			return null;
		}
		// The winning rule governs — no fall-through to shorter rules.
		return isset( $rule['tiers'] ) ? self::sanitize_rows( $rule['tiers'] ) : null;
	}

	/**
	 * The winning rule for a path (longest prefix wins, trailing * wildcard).
	 * Pure — shared by tiers, bundles, and any future per-path meta.
	 *
	 * @param string $path
	 * @param array  $settings
	 * @return array|null
	 */
	private static function matching_rule( $path, $settings ) {
		$rules = isset( $settings['path_pricing'] ) && is_array( $settings['path_pricing'] ) ? $settings['path_pricing'] : array();
		if ( empty( $rules ) ) {
			return null;
		}
		usort( $rules, function ( $a, $b ) {
			$pa = isset( $a['path'] ) ? (string) $a['path'] : '';
			$pb = isset( $b['path'] ) ? (string) $b['path'] : '';
			return strlen( $pb ) - strlen( $pa );
		} );
		foreach ( $rules as $rule ) {
			$pattern = isset( $rule['path'] ) ? (string) $rule['path'] : '';
			if ( '' === $pattern ) {
				continue;
			}
			$matched = false;
			if ( substr( $pattern, -1 ) === '*' ) {
				$matched = strpos( $path, rtrim( $pattern, '*' ) ) === 0;
			} else {
				$matched = strpos( $path, $pattern ) === 0;
			}
			if ( $matched ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Bundle (Pro, A4, spec §5.5): a rule marked "sell as bundle" offers a pass
	 * covering its WHOLE path. Sanitize a stored rule into the registry shape
	 * {path, tiers} — fail-closed null (a broken bundle config = no bundle).
	 *
	 * @param mixed $rule Raw path_pricing rule.
	 * @return array{path:string,tiers:array}|null
	 */
	public static function sanitize_bundle( $rule ) {
		if ( ! is_array( $rule ) || empty( $rule['bundle'] ) ) {
			return null;
		}
		$path = isset( $rule['path'] ) ? trim( (string) $rule['path'] ) : '';
		if ( '' === $path || strlen( $path ) > 200 || '/' !== substr( $path, 0, 1 ) ) {
			return null;
		}
		$tiers = self::sanitize_rows( isset( $rule['bundle_tiers'] ) ? $rule['bundle_tiers'] : null );
		if ( ! $tiers ) {
			return null;
		}
		return array( 'path' => $path, 'tiers' => $tiers );
	}

	/**
	 * Resolve the bundle offer for a post being sealed (Pro-gated like tiers).
	 *
	 * @param int   $post_id
	 * @param array $settings
	 * @return array{path:string,tiers:array}|null
	 */
	public static function resolve_bundle_for_post( $post_id, $settings ) {
		if ( ! class_exists( 'CrawlerToll_Pro_Admin' ) || ! CrawlerToll_Pro_Admin::is_pro_active() ) {
			return null;
		}
		$path = self::url_path_for_post( $post_id );
		if ( null === $path ) {
			return null;
		}
		$rule = self::matching_rule( $path, $settings );
		return null === $rule ? null : self::sanitize_bundle( $rule );
	}

	/**
	 * The permalink path a post seals under — the registry matches scoped
	 * (bundle) passes against it (content_id is host/post/N, not a URL path).
	 *
	 * @param int $post_id
	 * @return string|null
	 */
	public static function url_path_for_post( $post_id ) {
		$permalink = get_permalink( (int) $post_id );
		$path      = $permalink ? wp_parse_url( $permalink, PHP_URL_PATH ) : null;
		return ( is_string( $path ) && '' !== $path ) ? $path : null;
	}
}
