<?php
/**
 * Safe Mode — guarantees search-engine crawlers are never blocked or charged.
 * SEO Plugin Harmony — respects noindex from major SEO plugins.
 *
 * These are separate concerns but share a common purpose: CrawlerToll must
 * never interfere with legitimate search indexing or SEO plugin directives.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_SafeMode {

	/**
	 * Hardcoded safelist of search-engine crawlers that are NEVER blocked
	 * or charged, regardless of RSL policy.
	 *
	 * These bots index your site for search results. Blocking them kills
	 * your SEO. The safelist is non-configurable to prevent accidents.
	 *
	 * @return array<int,string> Lowercased UA substrings.
	 */
	public static function search_engine_safelist() {
		return array(
			'googlebot',        // Google Search
			'bingbot',          // Bing Search
			'msnbot',           // Legacy Bing
			'slurp',            // Yahoo Search
			'duckduckbot',      // DuckDuckGo Search
			'yandexbot',        // Yandex Search
			'baiduspider',      // Baidu Search
			'applebot',         // Apple Search (NOT Applebot-Extended)
			'facebookexternalhit', // Facebook link previews (social, not AI)
			'twitterbot',       // Twitter/X link previews
			'linkedinbot',      // LinkedIn link previews
			'telegrambot',      // Telegram link previews
			'slackbot',         // Slack link previews
			'discordbot',       // Discord link previews
			'whatsapp',         // WhatsApp link previews
		);
	}

	/**
	 * Check if a User-Agent is a search-engine or social-preview crawler
	 * and should be unconditionally allowed.
	 *
	 * @param string $user_agent Raw User-Agent header.
	 * @return bool True if this UA should be allowed regardless of policy.
	 */
	public static function is_safe( $user_agent ) {
		$lc = strtolower( (string) $user_agent );

		foreach ( self::search_engine_safelist() as $safe_ua ) {
			if ( strpos( $lc, $safe_ua ) !== false ) {
				// Applebot-Extended is an AI-training crawler and should NOT
				// be safelisted. Applebot (without -Extended) is the search
				// crawler and IS safelisted.
				if ( $safe_ua === 'applebot' && strpos( $lc, 'applebot-extended' ) !== false ) {
					continue;
				}
				return true;
			}
		}
		return false;
	}

	// ─── SEO Plugin Harmony ───────────────────────────────────────────

	/**
	 * Check if the current page/post is marked noindex by a supported SEO plugin.
	 * If so, CrawlerToll should never issue a 402 — noindex means the publisher
	 * doesn't want this page in any index, AI or otherwise.
	 *
	 * @return bool True if noindex is set by a supported SEO plugin.
	 */
	public static function is_noindex() {
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return false;
		}

		// Yoast SEO.
		if ( self::check_yoast_noindex( $post_id ) ) {
			return true;
		}

		// Rank Math.
		if ( self::check_rankmath_noindex( $post_id ) ) {
			return true;
		}

		// All in One SEO (AIOSEO).
		if ( self::check_aioseo_noindex( $post_id ) ) {
			return true;
		}

		// SEOPress.
		if ( self::check_seopress_noindex( $post_id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Yoast SEO: _yoast_wpseo_meta-robots-noindex.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	private static function check_yoast_noindex( $post_id ) {
		$noindex = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
		return $noindex === '1' || $noindex === 1;
	}

	/**
	 * Rank Math: rank_math_robots (serialized array).
	 *
	 * @param int $post_id
	 * @return bool
	 */
	private static function check_rankmath_noindex( $post_id ) {
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		if ( ! is_array( $robots ) ) {
			return false;
		}
		return ! empty( $robots['noindex'] ) && $robots['noindex'] === 'on';
	}

	/**
	 * All in One SEO: _aioseo_robots_noindex.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	private static function check_aioseo_noindex( $post_id ) {
		$noindex = get_post_meta( $post_id, '_aioseo_robots_noindex', true );
		return $noindex === '1' || $noindex === 1;
	}

	/**
	 * SEOPress: _seopress_robots_index.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	private static function check_seopress_noindex( $post_id ) {
		$index = get_post_meta( $post_id, '_seopress_robots_index', true );
		return $index === 'yes'; // "yes" means noindex in SEOPress's inverted logic.
	}
}
