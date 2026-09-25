<?php
/**
 * Curated catalogue of AI crawler User-Agents. PHP port of the
 * `@crawlertoll/core` bot catalogue.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each entry: name, operator, ua_match (substring on lowercased UA),
 * category (training | inference | search | agent | scraper), policy_url.
 */
class CrawlerToll_Bot_Catalogue {

	/**
	 * @return array<int,array<string,string>>
	 */
	public static function all() {
		return array(
			// OpenAI.
			array( 'name' => 'GPTBot',             'operator' => 'OpenAI',       'ua_match' => 'gptbot',             'category' => 'training' ),
			array( 'name' => 'ChatGPT-User',       'operator' => 'OpenAI',       'ua_match' => 'chatgpt-user',       'category' => 'inference' ),
			array( 'name' => 'OAI-SearchBot',      'operator' => 'OpenAI',       'ua_match' => 'oai-searchbot',      'category' => 'search' ),
			array( 'name' => 'ChatGPT Operator',   'operator' => 'OpenAI',       'ua_match' => 'chatgpt operator',   'category' => 'agent' ),

			// Anthropic.
			array( 'name' => 'ClaudeBot',          'operator' => 'Anthropic',    'ua_match' => 'claudebot',          'category' => 'training' ),
			array( 'name' => 'Claude-User',        'operator' => 'Anthropic',    'ua_match' => 'claude-user',        'category' => 'inference' ),
			array( 'name' => 'Claude-SearchBot',   'operator' => 'Anthropic',    'ua_match' => 'claude-searchbot',   'category' => 'search' ),

			// Google.
			array( 'name' => 'Google-Extended',    'operator' => 'Google',       'ua_match' => 'google-extended',    'category' => 'training' ),
			array( 'name' => 'GoogleOther',        'operator' => 'Google',       'ua_match' => 'googleother',        'category' => 'search' ),
			array( 'name' => 'Googlebot',          'operator' => 'Google',       'ua_match' => 'googlebot',          'category' => 'search' ),

			// Perplexity.
			array( 'name' => 'PerplexityBot',      'operator' => 'Perplexity',   'ua_match' => 'perplexitybot',      'category' => 'search' ),
			array( 'name' => 'Perplexity-User',    'operator' => 'Perplexity',   'ua_match' => 'perplexity-user',    'category' => 'inference' ),

			// Apple.
			array( 'name' => 'Applebot-Extended',  'operator' => 'Apple',        'ua_match' => 'applebot-extended',  'category' => 'training' ),
			array( 'name' => 'Applebot',           'operator' => 'Apple',        'ua_match' => 'applebot',           'category' => 'search' ),

			// Meta.
			array( 'name' => 'Meta-ExternalAgent', 'operator' => 'Meta',         'ua_match' => 'meta-externalagent', 'category' => 'training' ),
			array( 'name' => 'facebookexternalhit','operator' => 'Meta',         'ua_match' => 'facebookexternalhit','category' => 'scraper' ),

			// ByteDance.
			array( 'name' => 'Bytespider',         'operator' => 'ByteDance',    'ua_match' => 'bytespider',         'category' => 'training' ),

			// Common Crawl.
			array( 'name' => 'CCBot',              'operator' => 'Common Crawl', 'ua_match' => 'ccbot',              'category' => 'training' ),

			// Other operators.
			array( 'name' => 'cohere-ai',          'operator' => 'Cohere',       'ua_match' => 'cohere-ai',          'category' => 'training' ),
			array( 'name' => 'MistralAI-User',     'operator' => 'Mistral',      'ua_match' => 'mistralai-user',     'category' => 'inference' ),
			array( 'name' => 'YouBot',             'operator' => 'You.com',      'ua_match' => 'youbot',             'category' => 'search' ),
			array( 'name' => 'Diffbot',            'operator' => 'Diffbot',      'ua_match' => 'diffbot',            'category' => 'scraper' ),
			array( 'name' => 'BrightBot',          'operator' => 'Bright Data',  'ua_match' => 'brightbot',          'category' => 'scraper' ),
			array( 'name' => 'anthropic-ai',       'operator' => 'Unknown (legacy)', 'ua_match' => 'anthropic-ai',   'category' => 'training' ),
			array( 'name' => 'Omgili',             'operator' => 'Webz.io',      'ua_match' => 'omgili',             'category' => 'scraper' ),
			array( 'name' => 'ImagesiftBot',       'operator' => 'ImageSift',    'ua_match' => 'imagesiftbot',       'category' => 'training' ),
			array( 'name' => 'Timpibot',           'operator' => 'Timpi',        'ua_match' => 'timpibot',           'category' => 'training' ),
			array( 'name' => 'PetalBot',           'operator' => 'Huawei',       'ua_match' => 'petalbot',           'category' => 'search' ),
			array( 'name' => 'YandexBot',          'operator' => 'Yandex',       'ua_match' => 'yandexbot',          'category' => 'search' ),
			array( 'name' => 'DuckAssistBot',      'operator' => 'DuckDuckGo',   'ua_match' => 'duckassistbot',      'category' => 'inference' ),
		);
	}

	/**
	 * Find the first catalogue entry that matches a given User-Agent.
	 *
	 * @param string $user_agent Raw User-Agent header value.
	 * @return array<string,string>|null
	 */
	public static function match( $user_agent ) {
		if ( ! is_string( $user_agent ) || $user_agent === '' ) {
			return null;
		}
		$lc = strtolower( $user_agent );
		foreach ( self::all() as $entry ) {
			if ( strpos( $lc, $entry['ua_match'] ) !== false ) {
				return $entry;
			}
		}
		return null;
	}
}
