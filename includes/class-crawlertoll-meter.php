<?php
/**
 * Metered free articles (Pro) — plugin-side resolver.
 * Spec: docs/specs/metered-free-articles-v1.md (2026-08-26).
 *
 * Free-safe by design: this class ships in BOTH builds (the sealing engine
 * references it, and the sealing engine must not depend on Pro-only classes —
 * see tests/cut-scope.php). The meter itself is Pro-only: is_pro_active() is
 * always false in the free build, so resolve_for_post() returns null there and
 * sealed content registers without meter meta. The path matcher duplicates the
 * longest-prefix discipline of the (Pro-only) pricing resolver, meter-only, so
 * this file carries no Pro dependency.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Meter {

	/**
	 * Resolve the meter meta for a post being sealed: match the post's permalink
	 * path against the per-path pricing rules (longest prefix wins, trailing *
	 * wildcard), Pro-gated.
	 *
	 * @param int   $post_id
	 * @param array $settings Plugin settings.
	 * @return array{count:int,window_days:int,path:string}|null Null = no meter.
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
	 * Match a request path against the per-path rules and return the meter meta
	 * of the winning rule. Pure — unit-testable without WP.
	 *
	 * @param string $path     Request path (e.g. /articles/my-post).
	 * @param array  $settings Plugin settings.
	 * @return array{count:int,window_days:int,path:string}|null
	 */
	public static function resolve_for_path( $path, $settings ) {
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
			if ( ! $matched ) {
				continue;
			}
			$count = isset( $rule['meter_count'] ) ? (int) $rule['meter_count'] : 0;
			if ( $count <= 0 ) {
				return null; // The winning rule has no meter — do not fall through to shorter rules.
			}
			return array(
				'count'       => min( 50, $count ),
				'window_days' => isset( $rule['meter_window'] ) && (int) $rule['meter_window'] > 0 ? min( 365, (int) $rule['meter_window'] ) : 30,
				'path'        => $pattern,
			);
		}
		return null;
	}
}
