<?php
/**
 * Minimal RSL 1.0 robots.txt parser + matcher. PHP port of the JS
 * `@crawlertoll/core/rsl` module — same wire format, same semantics.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_RSL_Parser {

	/**
	 * Parse a robots.txt-flavoured RSL 1.0 document.
	 *
	 * @param string $text Raw robots.txt body.
	 * @return array{groups: array, sitemaps: array, warnings: array}
	 */
	public static function parse( $text ) {
		$lines    = preg_split( "/\r?\n/", (string) $text );
		$groups   = array();
		$sitemaps = array();
		$warnings = array();
		$current  = null;
		$in_ua_header = false;

		foreach ( $lines as $i => $raw ) {
			// Strip comments.
			$comment_pos = strpos( $raw, '#' );
			$stripped = trim( $comment_pos === false ? $raw : substr( $raw, 0, $comment_pos ) );
			if ( $stripped === '' ) {
				$in_ua_header = false;
				continue;
			}

			$colon = strpos( $stripped, ':' );
			if ( $colon === false ) {
				$warnings[] = 'line ' . ( $i + 1 ) . ': no colon';
				continue;
			}
			$name  = strtolower( trim( substr( $stripped, 0, $colon ) ) );
			$value = trim( substr( $stripped, $colon + 1 ) );

			switch ( $name ) {
				case 'user-agent':
					$ua = strtolower( $value );
					if ( $current === null || ! $in_ua_header ) {
						$current = self::new_group();
						$groups[] = &$current;
						$in_ua_header = true;
					}
					$current['user_agents'][] = $ua;
					break;

				case 'disallow':
					$in_ua_header = false;
					if ( $current === null ) {
						$current = self::new_group();
						$groups[] = &$current;
					}
					if ( $value !== '' ) {
						$current['disallow'][] = $value;
					}
					break;

				case 'allow':
					$in_ua_header = false;
					if ( $current === null ) {
						$current = self::new_group();
						$groups[] = &$current;
					}
					if ( $value !== '' ) {
						$current['allow'][] = $value;
					}
					break;

				case 'crawl-delay':
					$in_ua_header = false;
					if ( $current === null ) {
						$current = self::new_group();
						$groups[] = &$current;
					}
					if ( is_numeric( $value ) && (float) $value >= 0 ) {
						$current['crawl_delay'] = (float) $value;
					}
					break;

				case 'sitemap':
					$sitemaps[] = $value;
					break;

				case 'license':
					$in_ua_header = false;
					if ( $current === null ) {
						$current = self::new_group();
						$groups[] = &$current;
					}
					$current['license'] = $value;
					break;

				case 'permits':
					$in_ua_header = false;
					if ( $current === null ) {
						$current = self::new_group();
						$groups[] = &$current;
					}
					$current['permits'] = array_merge( $current['permits'], self::token_list( $value ) );
					break;

				case 'prohibits':
					$in_ua_header = false;
					if ( $current === null ) {
						$current = self::new_group();
						$groups[] = &$current;
					}
					$current['prohibits'] = array_merge( $current['prohibits'], self::token_list( $value ) );
					break;

				case 'compensation':
					$in_ua_header = false;
					if ( $current === null ) {
						$current = self::new_group();
						$groups[] = &$current;
					}
					$comp = self::parse_compensation( $value );
					if ( $comp !== null ) {
						$current['compensation'][] = $comp;
					}
					break;

				case 'standard':
					$in_ua_header = false;
					if ( $current === null ) {
						$current = self::new_group();
						$groups[] = &$current;
					}
					$current['standards'][] = $value;
					break;
			}
		}
		unset( $current );

		return array(
			'groups'   => $groups,
			'sitemaps' => $sitemaps,
			'warnings' => $warnings,
		);
	}

	/**
	 * Match an incoming UA against the parsed policy, returning the
	 * most-specific matching group (longest UA substring wins). Falls
	 * back to the `*` catch-all group if any.
	 *
	 * @param array  $policy    Result of `parse()`.
	 * @param string $user_agent Raw User-Agent header.
	 * @return array|null
	 */
	public static function match_agent( $policy, $user_agent ) {
		$lc = strtolower( (string) $user_agent );
		$best = null;
		$best_specificity = 0;
		$catch_all = null;

		foreach ( $policy['groups'] as $group ) {
			foreach ( $group['user_agents'] as $ua ) {
				if ( $ua === '*' ) {
					if ( $catch_all === null ) {
						$catch_all = $group;
					}
					continue;
				}
				if ( strpos( $lc, $ua ) !== false ) {
					$specificity = strlen( $ua );
					if ( $best === null || $specificity > $best_specificity ) {
						$best = $group;
						$best_specificity = $specificity;
					}
				}
			}
		}
		return $best !== null ? $best : $catch_all;
	}

	/**
	 * Apply Allow/Disallow precedence to a path. Longest-match wins;
	 * ties go to Allow per RFC 9309.
	 *
	 * @param array  $group         RSL agent group.
	 * @param string $path          Request path.
	 * @param bool   $allow_default Default when nothing matches.
	 * @return array{allowed: bool, matched: string, pattern: string|null}
	 */
	public static function match_path( $group, $path, $allow_default = true ) {
		$best_allow = null;
		$best_disallow = null;

		foreach ( $group['allow'] as $a ) {
			if ( strpos( $path, $a ) === 0 && ( $best_allow === null || strlen( $a ) > strlen( $best_allow ) ) ) {
				$best_allow = $a;
			}
		}
		foreach ( $group['disallow'] as $d ) {
			if ( strpos( $path, $d ) === 0 && ( $best_disallow === null || strlen( $d ) > strlen( $best_disallow ) ) ) {
				$best_disallow = $d;
			}
		}

		if ( $best_allow !== null && ( $best_disallow === null || strlen( $best_allow ) >= strlen( $best_disallow ) ) ) {
			return array( 'allowed' => true, 'matched' => 'allow', 'pattern' => $best_allow );
		}
		if ( $best_disallow !== null ) {
			return array( 'allowed' => false, 'matched' => 'disallow', 'pattern' => $best_disallow );
		}
		return array( 'allowed' => $allow_default, 'matched' => 'default', 'pattern' => null );
	}

	// ─── helpers ───────────────────────────────────────────────────

	private static function new_group() {
		return array(
			'user_agents' => array(),
			'disallow'    => array(),
			'allow'       => array(),
			'crawl_delay' => null,
			'license'     => null,
			'permits'     => array(),
			'prohibits'   => array(),
			'compensation' => array(),
			'standards'   => array(),
		);
	}

	private static function token_list( $value ) {
		$tokens = preg_split( '/[\s,]+/', strtolower( $value ) );
		$tokens = array_filter( $tokens, function ( $t ) {
			return $t !== '';
		} );
		return array_values( $tokens );
	}

	private static function parse_compensation( $value ) {
		$tokens = preg_split( '/\s+/', $value );
		$tokens = array_values( array_filter( $tokens, function ( $t ) {
			return $t !== '';
		} ) );
		if ( count( $tokens ) === 0 ) {
			return null;
		}
		$valid_models = array( 'free', 'per-crawl', 'per-token', 'per-document', 'subscription', 'negotiate' );
		$model = strtolower( $tokens[0] );
		if ( ! in_array( $model, $valid_models, true ) ) {
			return null;
		}
		$comp = array( 'model' => $model );
		$url = null;
		foreach ( $tokens as $t ) {
			if ( preg_match( '#^https?://#i', $t ) ) {
				$url = $t;
				break;
			}
		}
		if ( $model === 'free' || $model === 'negotiate' || $model === 'subscription' ) {
			if ( $url !== null ) {
				$comp['url'] = $url;
			}
			return $comp;
		}
		// Paid: <model> <micros> "micros" <currency> [<url>]
		if ( isset( $tokens[1] ) && is_numeric( $tokens[1] ) ) {
			$comp['price_micros'] = (int) $tokens[1];
		}
		if ( isset( $tokens[3] ) ) {
			$cur = strtoupper( $tokens[3] );
			if ( in_array( $cur, array( 'USD', 'USDC', 'EUR', 'GBP' ), true ) ) {
				$comp['currency'] = $cur;
			}
		}
		if ( $url !== null ) {
			$comp['url'] = $url;
		}
		return $comp;
	}
}
