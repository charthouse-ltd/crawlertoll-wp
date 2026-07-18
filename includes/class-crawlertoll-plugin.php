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

	/**
	 * @var CrawlerToll_DB
	 */
	private $db;

	/**
	 * @var CrawlerToll_Logger
	 */
	private $logger;

	/**
	 * @var CrawlerToll_Provenance
	 */
	private $provenance;

	public function __construct() {
		global $wpdb;
		// DB/Logger/Provenance are Pro classes, physically stripped from the free
		// wp.org build. Guard every instantiation so the free plugin loads cleanly:
		// the properties stay null and every runtime use of them is is_pro_active()-
		// gated (always false in the free build, where Freemius is absent).
		if ( class_exists( 'CrawlerToll_DB' ) ) {
			$this->db = new CrawlerToll_DB( $wpdb );
		}
		if ( $this->db && class_exists( 'CrawlerToll_Logger' ) ) {
			$this->logger = new CrawlerToll_Logger( $this->db );
		}
		if ( $this->logger && class_exists( 'CrawlerToll_Provenance' ) ) {
			$this->provenance = new CrawlerToll_Provenance( $this->logger );
		}
	}

	public function register() {
		// Content provenance (Pro): buffer the response so a charged request can
		// be hashed. Only for active licenses — the free hot path skips buffering.
		if ( CrawlerToll_Pro_Admin::is_pro_active() ) {
			add_action( 'template_redirect', array( $this->provenance, 'start_buffer' ), -9999 );
		}

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

		// Premium posts are owned by CrawlerToll_Premium_Gate (WS3): it serves the
		// cleartext preview to everyone-but-editors and emits the 402 offer at
		// template_redirect, so an agent and a human get the SAME preview HTML (no
		// cloaking). Do NOT short-circuit them with the legacy detect-and-toll here.
		if ( class_exists( 'CrawlerToll_Cut' ) ) {
			$ct_premium_id = url_to_postid( $request_uri );
			if ( $ct_premium_id > 0 && CrawlerToll_Cut::is_premium( $ct_premium_id ) ) {
				return;
			}
		}

		$decision = CrawlerToll_Decision::decide(
			array(
				'method'     => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET',
				'path'       => $path,
				'user_agent' => $user_agent,
			),
			$settings
		);

		// Resolve the per-request settlement rail (multi-rail routing, §2.5).
		// Pass the resolved settings to BOTH the logger and the 402 builder so
		// the stored rail and the offered rail always agree.
		$resolved = $settings;
		if ( ! empty( $decision['bot']['name'] ) ) {
			// Multi-rail routing is a Pro feature; CrawlerToll_Pricing is stripped from
			// the free build, so fall back to the flat site rail there.
			$resolved['rail'] = class_exists( 'CrawlerToll_Pricing' )
				? CrawlerToll_Pricing::resolve_rail( $decision['bot']['name'], $settings )
				: ( isset( $settings['rail'] ) ? $settings['rail'] : 'x402' );
		}

		// Per-path pricing (§2.4, Pro): the longest-prefix rule for this path
		// overrides the flat price, so the 402 offer header and the logged
		// revenue both reflect what this specific path is worth. Free installs
		// keep the flat price — the resolver is never consulted on the hot path.
		if ( ! empty( $decision['bot'] ) && CrawlerToll_Pro_Admin::is_pro_active() ) {
			$priced                   = ( new CrawlerToll_Pricing( $this->db ) )->resolve( $path, $settings );
			$resolved['price_micros'] = $priced['price_micros'];
			$resolved['currency']     = $priced['currency'];
		}

		// Log the decision + attach provenance — Pro only. Free installs skip the
		// per-request DB write entirely, keeping the enforcement path lean.
		if ( CrawlerToll_Pro_Admin::is_pro_active() && ! empty( $decision['bot'] ) ) {
			$log_id = $this->logger->log_decision( $decision, $resolved );
			if ( $log_id !== false ) {
				$this->provenance->set_log_id( $log_id );
			}
		}

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
			CrawlerToll_HTTP402::send( $resolved );
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
		// Free: context-license endpoint (public).
		register_rest_route(
			'crawlertoll/v1',
			'/context-license',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'rest_context_license' ),
			)
		);

		// Free: status endpoint.
		register_rest_route(
			'crawlertoll/v1',
			'/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () { return current_user_can( 'read' ); },
				'callback'            => array( $this, 'rest_status' ),
			)
		);

		// Free: bot catalogue.
		register_rest_route(
			'crawlertoll/v1',
			'/bots',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () { return current_user_can( 'read' ); },
				'callback'            => array( $this, 'rest_bots' ),
			)
		);

		// Pro: stats.
		register_rest_route(
			'crawlertoll/v1',
			'/stats',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () { return current_user_can( 'manage_options' ) && CrawlerToll_Pro_Admin::is_pro_active(); },
				'callback'            => array( $this, 'rest_stats' ),
			)
		);

		// Pro: daily activity buckets (dashboard trend charts).
		register_rest_route(
			'crawlertoll/v1',
			'/stats/timeseries',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () { return current_user_can( 'manage_options' ) && CrawlerToll_Pro_Admin::is_pro_active(); },
				'callback'            => array( $this, 'rest_stats_timeseries' ),
			)
		);

		// Pro: logs.
		register_rest_route(
			'crawlertoll/v1',
			'/logs',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () { return current_user_can( 'manage_options' ) && CrawlerToll_Pro_Admin::is_pro_active(); },
				'callback'            => array( $this, 'rest_logs' ),
			)
		);

		// Pro: settings read + writes (back the React Pro forms). Same gate as the
		// other Pro routes; writes are CSRF-protected by WP's REST cookie auth
		// (the React client sends X-WP-Nonce). Each write mirrors the sanitisation
		// of its old nonce'd POST handler in CrawlerToll_Pro_Admin.
		$pro_settings_perm = function () { return current_user_can( 'manage_options' ) && CrawlerToll_Pro_Admin::is_pro_active(); };
		register_rest_route( 'crawlertoll/v1', '/settings', array(
			'methods'             => 'GET',
			'permission_callback' => $pro_settings_perm,
			'callback'            => array( $this, 'rest_get_settings' ),
		) );
		register_rest_route( 'crawlertoll/v1', '/settings/pricing', array(
			'methods'             => 'POST',
			'permission_callback' => $pro_settings_perm,
			'callback'            => array( $this, 'rest_save_pricing' ),
		) );
		register_rest_route( 'crawlertoll/v1', '/settings/alerts', array(
			'methods'             => 'POST',
			'permission_callback' => $pro_settings_perm,
			'callback'            => array( $this, 'rest_save_alerts' ),
		) );
		register_rest_route( 'crawlertoll/v1', '/settings/rails', array(
			'methods'             => 'POST',
			'permission_callback' => $pro_settings_perm,
			'callback'            => array( $this, 'rest_save_rails' ),
		) );
		register_rest_route( 'crawlertoll/v1', '/settings/retention', array(
			'methods'             => 'POST',
			'permission_callback' => $pro_settings_perm,
			'callback'            => array( $this, 'rest_save_retention' ),
		) );

		// Pro: provenance lookup.
		register_rest_route(
			'crawlertoll/v1',
			'/provenance',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () { return current_user_can( 'manage_options' ) && CrawlerToll_Pro_Admin::is_pro_active(); },
				'callback'            => array( $this, 'rest_provenance' ),
				'args'                => array(
					'hash' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Pro: payment webhook (for settlement rails).
		register_rest_route(
			'crawlertoll/v1',
			'/webhook/payment',
			array(
				'methods'             => 'POST',
				'permission_callback' => function ( $request ) {
					if ( ! CrawlerToll_Pro_Admin::is_pro_active() ) {
						return false;
					}
					$secret = $request->get_header( 'X-CrawlerToll-Webhook-Secret' );
					$stored = defined( 'CRAWLERTOLL_WEBHOOK_SECRET' ) ? CRAWLERTOLL_WEBHOOK_SECRET : '';
					return $secret && hash_equals( $stored, $secret );
				},
				'callback'            => array( $this, 'rest_webhook_payment' ),
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
				'name'   => get_bloginfo( 'name' ),
				'slug'   => $slug,
				'domain' => is_string( $host ) ? $host : '',
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

	// ─── REST API Callbacks ─────────────────────────────────────────

	/**
	 * GET /crawlertoll/v1/status
	 */
	public function rest_status() {
		$settings = crawlertoll_get_settings();
		return array(
			'active'   => ! empty( $settings['enabled'] ),
			'version'  => CRAWLERTOLL_VERSION,
			'rail'     => $settings['rail'],
			'currency' => $settings['currency'],
		);
	}

	/**
	 * GET /crawlertoll/v1/bots
	 */
	public function rest_bots() {
		return CrawlerToll_Bot_Catalogue::all();
	}

	/**
	 * GET /crawlertoll/v1/stats?period=7d|30d
	 */
	public function rest_stats( $request ) {
		$period = $request->get_param( 'period' ) ?: '30d';
		$days   = $period === '7d' ? 7 : 30;
		$to     = gmdate( 'Y-m-d' );
		$from   = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		$pricing  = new CrawlerToll_Pricing( $this->db );
		$comparison = $pricing->revenue_comparison( $from, $to );

		return array(
			'period'          => array( 'from' => $from, 'to' => $to ),
			'current'         => $comparison['current'],
			'change_pct'      => $comparison['change_pct'],
		);
	}

	/**
	 * GET /crawlertoll/v1/settings — current Pro-form values + the static option
	 * maps the forms need (rails, currencies).
	 */
	public function rest_get_settings() {
		$s = crawlertoll_get_settings();
		return array(
			'price_micros'   => (int) $s['price_micros'],
			'currency'       => (string) $s['currency'],
			'rail'           => (string) $s['rail'],
			'path_pricing'   => ( isset( $s['path_pricing'] ) && is_array( $s['path_pricing'] ) ) ? array_values( $s['path_pricing'] ) : array(),
			'rail_overrides' => ( isset( $s['rail_overrides'] ) && is_array( $s['rail_overrides'] ) && $s['rail_overrides'] ) ? $s['rail_overrides'] : (object) array(),
			'alerts'         => array(
				'daily'  => ! empty( $s['alerts_daily'] ),
				'weekly' => ! empty( $s['alerts_weekly'] ),
				'spike'  => ! empty( $s['alerts_spike'] ),
				'email'  => isset( $s['alert_email'] ) ? (string) $s['alert_email'] : '',
			),
			'retention_days' => isset( $s['retention_days'] ) ? (int) $s['retention_days'] : 90,
			'meta'           => array(
				'rail_options'   => crawlertoll_rail_options(),
				'currencies'     => array( 'USD', 'USDC', 'EUR', 'GBP' ),
				'fallback_email' => get_bloginfo( 'admin_email' ),
			),
		);
	}

	/**
	 * POST /crawlertoll/v1/settings/pricing — per-path price rules.
	 * Mirrors CrawlerToll_Pro_Admin::render_pricing_tab()'s sanitisation.
	 */
	public function rest_save_pricing( $request ) {
		$allowed_curr  = array( 'USD', 'USDC', 'EUR', 'GBP' );
		$settings      = crawlertoll_get_settings();
		$site_currency = isset( $settings['currency'] ) ? $settings['currency'] : 'USD';

		$input = $request->get_param( 'rules' );
		$input = is_array( $input ) ? $input : array();

		$rules = array();
		foreach ( $input as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$p = isset( $row['path'] ) ? sanitize_text_field( (string) $row['path'] ) : '';
			if ( '' === $p ) {
				continue; // Skip blank rows.
			}
			$m = isset( $row['price_micros'] ) ? max( 0, (int) $row['price_micros'] ) : 0;
			$c = ( isset( $row['currency'] ) && in_array( strtoupper( (string) $row['currency'] ), $allowed_curr, true ) ) ? strtoupper( (string) $row['currency'] ) : $site_currency;
			$rules[] = array( 'path' => $p, 'price_micros' => $m, 'currency' => $c );
		}

		$settings['path_pricing'] = $rules;
		update_option( CRAWLERTOLL_OPTION_KEY, $settings );
		return array( 'path_pricing' => $rules );
	}

	/**
	 * POST /crawlertoll/v1/settings/alerts — summary-email toggles + recipient.
	 * Mirrors CrawlerToll_Pro_Admin::render_alerts_tab()'s sanitisation.
	 */
	public function rest_save_alerts( $request ) {
		$settings = crawlertoll_get_settings();

		$settings['alerts_daily']  = (bool) $request->get_param( 'daily' );
		$settings['alerts_weekly'] = (bool) $request->get_param( 'weekly' );
		$settings['alerts_spike']  = (bool) $request->get_param( 'spike' );
		$email                     = sanitize_email( (string) $request->get_param( 'email' ) );
		$settings['alert_email']   = ( $email && is_email( $email ) ) ? $email : '';

		update_option( CRAWLERTOLL_OPTION_KEY, $settings );
		return array(
			'alerts' => array(
				'daily'  => $settings['alerts_daily'],
				'weekly' => $settings['alerts_weekly'],
				'spike'  => $settings['alerts_spike'],
				'email'  => $settings['alert_email'],
			),
		);
	}

	/**
	 * POST /crawlertoll/v1/settings/rails — per-bot settlement-rail overrides.
	 * Mirrors CrawlerToll_Pro_Admin::render_rails_tab()'s sanitisation.
	 */
	public function rest_save_rails( $request ) {
		$allowed = array_keys( crawlertoll_rail_options() );
		$input   = $request->get_param( 'overrides' );
		$input   = is_array( $input ) ? $input : array();

		$overrides = array();
		foreach ( $input as $bot => $rail ) {
			$bot  = sanitize_text_field( (string) $bot );
			$rail = sanitize_text_field( (string) $rail );
			if ( '' !== $rail && '' !== $bot && in_array( $rail, $allowed, true ) ) {
				$overrides[ $bot ] = $rail;
			}
		}

		$settings                   = crawlertoll_get_settings();
		$settings['rail_overrides'] = $overrides;
		update_option( CRAWLERTOLL_OPTION_KEY, $settings );
		return array( 'rail_overrides' => empty( $overrides ) ? (object) array() : $overrides );
	}

	/**
	 * POST /crawlertoll/v1/settings/retention — log-retention window (days).
	 * Mirrors the retention save in CrawlerToll_Pro_Admin::render_logs_tab().
	 */
	public function rest_save_retention( $request ) {
		$days     = max( 0, (int) $request->get_param( 'days' ) );
		$settings = crawlertoll_get_settings();
		$settings['retention_days'] = $days;
		update_option( CRAWLERTOLL_OPTION_KEY, $settings );
		return array( 'retention_days' => $days );
	}

	/**
	 * GET /crawlertoll/v1/stats/timeseries?period=7d|30d
	 */
	public function rest_stats_timeseries( $request ) {
		$period = $request->get_param( 'period' ) ?: '30d';
		$days   = $period === '7d' ? 7 : 30;
		$to     = gmdate( 'Y-m-d' );
		$from   = gmdate( 'Y-m-d', strtotime( "-{$days} days" ) );

		return array(
			'period' => array( 'from' => $from, 'to' => $to ),
			'days'   => $this->db->timeseries( $from, $to ),
		);
	}

	/**
	 * GET /crawlertoll/v1/logs?bot=X&action=Y&from=Z&to=W&page=1&per_page=50
	 */
	public function rest_logs( $request ) {
		$result = $this->db->query( array(
			'bot'      => $request->get_param( 'bot' ) ?: '',
			'action'   => $request->get_param( 'action' ) ?: '',
			'from'     => $request->get_param( 'from' ) ?: '',
			'to'       => $request->get_param( 'to' ) ?: '',
			'page'     => (int) ( $request->get_param( 'page' ) ?: 1 ),
			'per_page' => min( 100, (int) ( $request->get_param( 'per_page' ) ?: 50 ) ),
			'orderby'  => $request->get_param( 'orderby' ) ?: 'request_time',
			'order'    => $request->get_param( 'order' ) ?: 'DESC',
		) );

		return $result;
	}

	/**
	 * GET /crawlertoll/v1/provenance?hash=a1b2c3...
	 */
	public function rest_provenance( $request ) {
		$hash = $request->get_param( 'hash' );
		$entry = $this->db->lookup_hash( $hash );

		if ( ! $entry ) {
			return new WP_REST_Response( array( 'found' => false ), 404 );
		}

		return array(
			'found' => true,
			'entry' => $entry,
		);
	}

	/**
	 * POST /crawlertoll/v1/webhook/payment
	 */
	public function rest_webhook_payment( $request ) {
		$log_id     = $request->get_param( 'crawlertoll_log_id' );
		$rail       = $request->get_param( 'rail' );
		$payment_id = $request->get_param( 'payment_id' );

		if ( ! $log_id || ! $rail || ! $payment_id ) {
			return new WP_REST_Response( array( 'error' => 'Missing required fields' ), 400 );
		}

		$ok = $this->db->mark_paid( (int) $log_id, $rail, $payment_id );

		if ( ! $ok ) {
			return new WP_REST_Response( array( 'error' => 'Failed to mark as paid' ), 500 );
		}

		return array( 'status' => 'ok' );
	}
}
