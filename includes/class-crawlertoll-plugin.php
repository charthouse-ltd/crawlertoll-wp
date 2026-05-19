<?php
/**
 * Main plugin class. Wires the decision tree into WordPress's
 * request lifecycle and exposes the discovery endpoints.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Plugin {

	public function register() {
		// Decide on every front-end request, as early as possible after
		// WP has populated the request context. `parse_request` runs
		// before any content is loaded; perfect for short-circuiting.
		add_action( 'parse_request', array( $this, 'on_parse_request' ), 1 );

		// Advertise RSL directives via the standard robots.txt filter.
		add_filter( 'robots_txt', array( $this, 'augment_robots_txt' ), 10, 2 );

		// Serve /.well-known/context-license.json via the REST API
		// (the cleanest path in WP — avoids rewrite-rule fragility).
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Optional: intercept /.well-known/context-license.json before
		// it hits the 404 handler, mapping to the REST endpoint.
		add_action( 'init', array( $this, 'add_well_known_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_well_known' ), 0 );
	}

	/**
	 * Run the decision tree against the current request and short-circuit
	 * with 402 / 403 if appropriate.
	 *
	 * @param WP $wp Current WP env.
	 */
	public function on_parse_request( $wp ) {
		$settings = crawlertoll_get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}
		// Skip admin and REST + cron + xmlrpc requests.
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			return;
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			$path = '/';
		}

		// Never charge for the discovery files themselves.
		if ( $path === '/robots.txt' || strpos( $path, '/.well-known/' ) === 0 ) {
			return;
		}

		$decision = CrawlerToll_Decision::decide(
			array(
				'method'     => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET',
				'path'       => $path,
				'user_agent' => $user_agent,
			),
			$settings
		);

		// Tell downstream code about the decision.
		header_register_callback(
			function () use ( $decision ) {
				header( 'X-CrawlerToll-Action: ' . $decision['action'] );
				if ( ! empty( $decision['bot']['operator'] ) ) {
					header( 'X-CrawlerToll-Operator: ' . $decision['bot']['operator'] );
				}
				if ( ! empty( $decision['bot']['name'] ) ) {
					header( 'X-CrawlerToll-Bot-Name: ' . $decision['bot']['name'] );
				}
			}
		);

		if ( $decision['action'] === '402' ) {
			CrawlerToll_HTTP402::send( $settings );
		}
		if ( $decision['action'] === 'block' ) {
			CrawlerToll_HTTP402::send_block( $decision['reasons'] );
		}
	}

	/**
	 * Append the RSL-extended directives to /robots.txt.
	 *
	 * @param string $output Existing robots.txt body.
	 * @param int    $public Is the blog public?
	 * @return string
	 */
	public function augment_robots_txt( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}
		$settings = crawlertoll_get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return $output;
		}
		$policy = isset( $settings['policy'] ) ? trim( $settings['policy'] ) : '';
		if ( $policy === '' ) {
			return $output;
		}
		return rtrim( $output, "\n" ) . "\n\n# CrawlerToll — RSL 1.0 directives.\n" . $policy . "\n";
	}

	/**
	 * Register the REST route that serves the context-license.json.
	 */
	public function register_rest_routes() {
		register_rest_route(
			'crawlertoll/v1',
			'/context-license',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'rest_context_license' ),
			)
		);
	}

	/**
	 * Build the context-license.json from settings + site info.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_context_license() {
		$settings = crawlertoll_get_settings();
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$slug = strtolower( str_replace( array( '.', ':' ), '-', is_string( $host ) ? $host : 'example' ) );

		$schemes = array( 'anonymous', 'api_key' );
		if ( $settings['rail'] === 'x402' ) {
			$schemes[] = 'x402';
		}

		$body = array(
			'$schema'    => 'https://schemas.crawlertoll.com/context-license/v1.json',
			'version'    => '1.0.0',
			'publisher'  => array(
				'name'    => get_bloginfo( 'name' ),
				'slug'    => $slug,
				'domain'  => is_string( $host ) ? $host : '',
				'contact' => get_bloginfo( 'admin_email' ),
			),
			'endpoints' => array(
				array(
					'name'        => 'default',
					'url'         => home_url( '/' ),
					'transport'   => 'streamable-http',
					'description' => sprintf( 'Default endpoint for %s. Edit Settings → CrawlerToll to declare specific endpoints.', get_bloginfo( 'name' ) ),
				),
			),
			'pricing'   => array(
				'model'             => 'per_query',
				'currency'          => $settings['currency'],
				'unit_price_micros' => (int) $settings['price_micros'],
			),
			'auth'      => array(
				'schemes' => $schemes,
			),
			'terms_of_use' => $settings['terms_url'] !== '' ? $settings['terms_url'] : home_url( '/ai-terms' ),
			'quality_signals' => array(
				'uptime_sla_pct'           => 99.0,
				'freshness_target_seconds' => 86400,
				'last_updated'             => gmdate( 'c' ),
			),
		);

		$resp = new WP_REST_Response( $body, 200 );
		$resp->header( 'Cache-Control', 'public, max-age=300' );
		$resp->header( 'Access-Control-Allow-Origin', '*' );
		return $resp;
	}

	public function add_well_known_rewrite() {
		add_rewrite_rule( '^\.well-known/context-license\.json$', 'index.php?crawlertoll_well_known=1', 'top' );
	}

	public function add_query_vars( $vars ) {
		$vars[] = 'crawlertoll_well_known';
		return $vars;
	}

	public function handle_well_known() {
		if ( ! get_query_var( 'crawlertoll_well_known' ) ) {
			return;
		}
		$response = rest_do_request( new WP_REST_Request( 'GET', '/crawlertoll/v1/context-license' ) );
		$data = $response->get_data();
		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=300' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore
		exit;
	}
}
