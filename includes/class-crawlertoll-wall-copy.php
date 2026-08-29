<?php
/**
 * Publisher-editable wall copy (access-tiers spec §5.4, A3, 2026-08-29).
 *
 * Every reader-facing wall string is a template the publisher can edit, with
 * placeholders ({price}, {currency}, {count}, {window_days}, {remaining},
 * {site_name}). Four templates: the lock heading, the value line, the
 * exhausted-meter note, and the static lock-region sentence.
 *
 * Free-safe by design — same discipline as CrawlerToll_Meter/CrawlerToll_Tiers:
 * ships in BOTH builds (the gate references it and must not depend on Pro-only
 * classes). The site-wide templates are a FREE feature; the per-article
 * override (post meta _crawlertoll_wall_text, value line only) is Pro-gated at
 * resolve time: is_pro_active() is always false in the free build, so the meta
 * is simply never read there.
 *
 * Sanitization discipline (spec §5.4): plain text only, ≤300 chars per string;
 * kses-strip on save, esc_html/esc_attr on render. Placeholders are substituted
 * CLIENT-side (the wall is a React app); unknown placeholders fall back to the
 * built-in default there — a raw "{…}" never reaches the reader.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Wall_Copy {

	const MAX_LEN   = 300;
	const META_KEY  = '_crawlertoll_wall_text';

	/**
	 * The four editable templates and their built-in defaults. The defaults ARE
	 * the copy the wall shipped with — an empty/invalid template degrades to
	 * these, never to a raw placeholder.
	 *
	 * @return array<string,string>
	 */
	public static function defaults() {
		return array(
			// Lock heading above the offer.
			'heading'     => __( 'Keep reading', 'crawlertoll' ),
			// Value line on the idle card ({price} substituted client-side).
			'value_line'  => __( 'Unlock the rest of this article for {price} — one-time, no subscription.', 'crawlertoll' ),
			// Note when the metered free reads are used up.
			'meter_out'   => __( "You've read your {count} free articles for this {window_days}-day window.", 'crawlertoll' ),
			// Static sentence inside the lock region (pre-hydration + unavailable).
			'unavailable' => __( 'The rest of this content is available with a one-time unlock.', 'crawlertoll' ),
		);
	}

	/**
	 * Sanitize a wall_text settings array: known keys only, kses-stripped plain
	 * text, ≤ MAX_LEN chars. Empty values drop out (resolve falls back to the
	 * default for that key).
	 *
	 * @param mixed $raw Raw 'wall_text' input.
	 * @return array<string,string>
	 */
	public static function sanitize( $raw ) {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( self::defaults() as $key => $default ) {
			if ( ! isset( $raw[ $key ] ) || ! is_string( $raw[ $key ] ) ) {
				continue;
			}
			// Plain text only — the wall renders on the public frontend.
			$v = trim( sanitize_textarea_field( wp_unslash( $raw[ $key ] ) ) );
			if ( function_exists( 'mb_substr' ) ) {
				$v = mb_substr( $v, 0, self::MAX_LEN );
			} else {
				$v = substr( $v, 0, self::MAX_LEN );
			}
			if ( '' !== $v && $v !== $default ) {
				$out[ $key ] = $v; // store only real overrides
			}
		}
		return $out;
	}

	/**
	 * Sanitize the per-article override (value line only).
	 *
	 * @param mixed $raw Raw meta value.
	 * @return string
	 */
	public static function sanitize_meta( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$v = trim( sanitize_textarea_field( wp_unslash( $raw ) ) );
		if ( function_exists( 'mb_substr' ) ) {
			$v = mb_substr( $v, 0, self::MAX_LEN );
		} else {
			$v = substr( $v, 0, self::MAX_LEN );
		}
		return $v;
	}

	/**
	 * Resolve the four templates for a post being gated: settings override over
	 * defaults, per-article meta (Pro) over the value line. Templates keep their
	 * placeholders — substitution happens in the unlock app, which is the only
	 * place that knows {price}/{remaining} for THIS reader.
	 *
	 * @param int   $post_id
	 * @param array $settings Plugin settings.
	 * @return array<string,string> All four keys, always non-empty.
	 */
	public static function resolve( $post_id, $settings ) {
		$out  = self::defaults();
		$over = isset( $settings['wall_text'] ) && is_array( $settings['wall_text'] ) ? $settings['wall_text'] : array();
		foreach ( $out as $key => $default ) {
			if ( isset( $over[ $key ] ) && is_string( $over[ $key ] ) && '' !== trim( $over[ $key ] ) ) {
				$out[ $key ] = $over[ $key ];
			}
		}
		// Per-article override (Pro): value line only — the conversion pitch is
		// the string a publisher tailors per article.
		if ( $post_id > 0 && class_exists( 'CrawlerToll_Pro_Admin' ) && CrawlerToll_Pro_Admin::is_pro_active() ) {
			$meta = get_post_meta( (int) $post_id, self::META_KEY, true );
			$meta = self::sanitize_meta( $meta );
			if ( '' !== $meta ) {
				$out['value_line'] = $meta;
			}
		}
		return $out;
	}

	/**
	 * Register the per-article override meta (show_in_rest so the block-editor
	 * sidebar can save it; auth_callback gates writes on edit_post).
	 *
	 * @return void
	 */
	public static function register_meta() {
		register_post_meta(
			'post',
			self::META_KEY,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_meta' ),
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}
}
