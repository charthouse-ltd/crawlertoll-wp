<?php
/**
 * Decision orchestrator. PHP equivalent of `@crawlertoll/core`'s
 * `decide()` — runs bot detection + RSL policy matching and returns a
 * structured decision (allow / 402 / block).
 *
 * Web Bot Auth verification is intentionally omitted from the WP plugin
 * v0.1: WP runs on shared hosting where outbound HTTP to fetch a JWKS
 * is expensive and may be disabled. The Node ecosystem handles WBA;
 * the WP plugin focuses on the cheap UA+policy gate.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Decision {

	/**
	 * Run the decision tree for an incoming request.
	 *
	 * @param array<string,mixed> $request    [method, path, user_agent].
	 * @param array<string,mixed> $settings   crawlertoll_get_settings() output.
	 * @return array{
	 *   action: 'allow'|'402'|'block',
	 *   bot: array|null,
	 *   group: array|null,
	 *   reasons: string[]
	 * }
	 */
	public static function decide( $request, $settings ) {
		$reasons = array();
		$user_agent = isset( $request['user_agent'] ) ? (string) $request['user_agent'] : '';
		$path = isset( $request['path'] ) ? (string) $request['path'] : '/';

		$bot_entry = CrawlerToll_Bot_Catalogue::match( $user_agent );
		if ( $bot_entry === null ) {
			$reasons[] = 'not-a-bot';
			return array(
				'action'  => 'allow',
				'bot'     => null,
				'group'   => null,
				'reasons' => $reasons,
			);
		}
		$reasons[] = 'ua-match:' . $bot_entry['name'];

		// Parse the configured policy and find the agent group.
		$policy = CrawlerToll_RSL_Parser::parse( isset( $settings['policy'] ) ? $settings['policy'] : '' );
		$group  = CrawlerToll_RSL_Parser::match_agent( $policy, $user_agent );
		if ( $group !== null && ! empty( $group['user_agents'] ) ) {
			$reasons[] = 'rsl-group:' . implode( ',', $group['user_agents'] );
		}

		if ( $group === null ) {
			// No policy match → default-allow.
			$reasons[] = 'default-allow';
			return array(
				'action'  => 'allow',
				'bot'     => $bot_entry,
				'group'   => null,
				'reasons' => $reasons,
			);
		}

		$path_decision = CrawlerToll_RSL_Parser::match_path( $group, $path );
		$reasons[] = 'rsl-path:' . $path_decision['matched'] . ':' . ( $path_decision['allowed'] ? 'allow' : 'deny' );

		if ( $path_decision['allowed'] ) {
			return array(
				'action'  => 'allow',
				'bot'     => $bot_entry,
				'group'   => $group,
				'reasons' => $reasons,
			);
		}

		// Disallowed → 402 if compensation declared and offer configured, else block.
		if ( ! empty( $group['compensation'] ) && ! empty( $settings['enabled'] ) ) {
			$reasons[] = 'rsl-charge';
			return array(
				'action'  => '402',
				'bot'     => $bot_entry,
				'group'   => $group,
				'reasons' => $reasons,
			);
		}

		$reasons[] = 'rsl-block';
		return array(
			'action'  => 'block',
			'bot'     => $bot_entry,
			'group'   => $group,
			'reasons' => $reasons,
		);
	}
}
