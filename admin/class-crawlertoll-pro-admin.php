<?php
/**
 * Pro admin — revenue dashboard, bot-request logs, and Pro settings tabs.
 * Extends the free admin with Pro-only views gated behind license validation.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Pro_Admin {

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
	 * Register Pro hooks. Called from the main admin class when Pro is active.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_pro_assets' ) );
		add_action( 'wp_ajax_crawlertoll_export_logs', array( $this, 'handle_export' ) );
	}

	/**
	 * Enqueue Pro admin CSS/JS on CrawlerToll settings pages.
	 *
	 * @param string $hook
	 * @return void
	 */
	public function enqueue_pro_assets( $hook ) {
		if ( 'settings_page_crawlertoll' !== $hook ) {
			return;
		}

		// React Pro app (Vite bundle). Mounts into #crawlertoll-pro-app on the
		// revenue tab and drives itself off the Pro REST routes. file_exists-guarded
		// inside enqueue() — if the bundle is absent the revenue tab keeps its
		// server-rendered PHP dashboard (progressive enhancement). The inline blob
		// is the REST seam: the /stats + /logs routes are cookie-authed and require
		// an X-WP-Nonce, so React can't reach them without this nonce.
		if ( CrawlerToll_Vite::enqueue( 'pro', 'crawlertoll-pro-app' ) ) {
			$settings = crawlertoll_get_settings();
			wp_add_inline_script(
				'crawlertoll-pro-app',
				'window.crawlertollPro = ' . wp_json_encode(
					array(
						'restUrl'     => esc_url_raw( rest_url( 'crawlertoll/v1/' ) ),
						'nonce'       => wp_create_nonce( 'wp_rest' ),
						'currency'    => isset( $settings['currency'] ) ? (string) $settings['currency'] : 'USD',
						// Log export is a signed admin-ajax download (not REST) — the
						// React browser builds the link from these.
						'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
						'exportNonce' => wp_create_nonce( 'crawlertoll_export_logs' ),
					)
				) . ';',
				'before'
			);
		}

		// Pro JS (charts, logs interactivity) — loaded after admin.js. Only enqueued
		// when the asset is actually present, so we never emit a 404 for a script that
		// isn't shipped (charts render server-side as SVG; logs filter via GET links).
		if ( ! file_exists( plugin_dir_path( CRAWLERTOLL_PLUGIN_FILE ) . 'assets/pro-admin.js' ) ) {
			return;
		}
		wp_enqueue_script(
			'crawlertoll-pro-admin',
			plugin_dir_url( CRAWLERTOLL_PLUGIN_FILE ) . 'assets/pro-admin.js',
			array(),
			CRAWLERTOLL_VERSION,
			true
		);
		wp_localize_script(
			'crawlertoll-pro-admin',
			'CrawlerTollPro',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'crawlertoll_pro' ),
			)
		);
	}

	/**
	 * Whether Pro features are unlocked.
	 *
	 * In development, define CRAWLERTOLL_PRO_DEV in wp-config.php to bypass
	 * license checks. In production, the license is validated by Freemius
	 * (which handles its own caching and grace period).
	 *
	 * @return bool
	 */
	public static function is_pro_active() {
		// Local-dev override: test Pro features without a live license.
		if ( defined( 'CRAWLERTOLL_PRO_DEV' ) && CRAWLERTOLL_PRO_DEV ) {
			return true;
		}
		// can_use_premium_code() is true for paying + trialing licenses.
		return function_exists( 'crawlertoll_fs' ) && crawlertoll_fs()->can_use_premium_code();
	}

	/**
	 * Render the revenue dashboard tab.
	 *
	 * @return void
	 */
	public function render_revenue_tab() {
		$settings = crawlertoll_get_settings();
		$currency = isset( $settings['currency'] ) ? $settings['currency'] : 'USD';

		// Default to last 30 days.
		$to   = gmdate( 'Y-m-d' );
		$from = gmdate( 'Y-m-d', strtotime( '-30 days' ) );

		// Allow date range override via query params.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['ct_from'] ) ) {
			$from = sanitize_text_field( wp_unslash( $_GET['ct_from'] ) );
		}
		if ( ! empty( $_GET['ct_to'] ) ) {
			$to = sanitize_text_field( wp_unslash( $_GET['ct_to'] ) );
		}
		// phpcs:enable

		$pricing  = new CrawlerToll_Pricing( $this->db );
		$comparison = $pricing->revenue_comparison( $from, $to );
		$current    = isset( $comparison['current'] ) ? $comparison['current'] : array( 'totals' => array(), 'top_bots' => array(), 'top_paths' => array() );
		$change_pct = isset( $comparison['change_pct'] ) ? $comparison['change_pct'] : 0;

		$totals       = isset( $current['totals'] ) ? $current['totals'] : array();
		$total_crawls = isset( $totals['total_crawls'] ) ? (int) $totals['total_crawls'] : 0;
		$charged       = isset( $totals['charged'] ) ? (int) $totals['charged'] : 0;
		$blocked       = isset( $totals['blocked'] ) ? (int) $totals['blocked'] : 0;
		$allowed       = isset( $totals['allowed'] ) ? (int) $totals['allowed'] : 0;
		$revenue_micros = isset( $totals['total_revenue_micros'] ) ? (int) $totals['total_revenue_micros'] : 0;
		$revenue_dollars = number_format( $revenue_micros / 1000000, 2 );

		$top_bots  = isset( $current['top_bots'] ) ? $current['top_bots'] : array();
		$top_paths = isset( $current['top_paths'] ) ? $current['top_paths'] : array();

		// Build simple SVG bar chart data for top bots.
		$max_bot_crawls = 1;
		foreach ( $top_bots as $bot ) {
			if ( (int) $bot['crawls'] > $max_bot_crawls ) {
				$max_bot_crawls = (int) $bot['crawls'];
			}
		}

		// React (pro-app) mounts into #crawlertoll-pro-app and replaces the
		// server-rendered dashboard below with the live, REST-driven version. If
		// the bundle is absent or JS fails, this PHP dashboard is the fallback.
		echo '<div id="crawlertoll-pro-app" data-view="dashboard">';
		include CRAWLERTOLL_PLUGIN_DIR . 'admin/views/pro-dashboard.php';
		echo '</div>';
	}

	/**
	 * Render the bot-request logs tab.
	 *
	 * @return void
	 */
	public function render_logs_tab() {
		// Handle the retention save (own nonce; read-modify-write of settings).
		if ( isset( $_POST['crawlertoll_retention_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['crawlertoll_retention_nonce'] ) ), 'crawlertoll_save_retention' )
			&& current_user_can( 'manage_options' )
		) {
			$settings                   = crawlertoll_get_settings();
			$settings['retention_days'] = isset( $_POST['ct_retention_days'] ) ? max( 0, (int) $_POST['ct_retention_days'] ) : 90;
			update_option( CRAWLERTOLL_OPTION_KEY, $settings );
			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'Log retention saved.', 'crawlertoll' );
			echo '</p></div>';
		}

		// Parse filters from query params.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$bot      = isset( $_GET['ct_bot'] ) ? sanitize_text_field( wp_unslash( $_GET['ct_bot'] ) ) : '';
		$action   = isset( $_GET['ct_action'] ) ? sanitize_text_field( wp_unslash( $_GET['ct_action'] ) ) : '';
		$from     = isset( $_GET['ct_from'] ) ? sanitize_text_field( wp_unslash( $_GET['ct_from'] ) ) : gmdate( 'Y-m-d', strtotime( '-7 days' ) );
		$to       = isset( $_GET['ct_to'] ) ? sanitize_text_field( wp_unslash( $_GET['ct_to'] ) ) : gmdate( 'Y-m-d' );
		$page     = isset( $_GET['ct_page'] ) ? max( 1, (int) $_GET['ct_page'] ) : 1;
		$per_page = 50;
		$orderby  = isset( $_GET['ct_orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['ct_orderby'] ) ) : 'request_time';
		$order    = isset( $_GET['ct_order'] ) ? sanitize_text_field( wp_unslash( $_GET['ct_order'] ) ) : 'DESC';
		// phpcs:enable

		$result = $this->db->query( array(
			'bot'      => $bot,
			'action'   => $action,
			'from'     => $from,
			'to'       => $to,
			'page'     => $page,
			'per_page' => $per_page,
			'orderby'  => $orderby,
			'order'    => $order,
		) );

		$entries  = $result['entries'];
		$total    = $result['total'];
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );

		// Bot list for filter dropdown.
		$all_bots = CrawlerToll_Bot_Catalogue::all();

		$retention_settings = crawlertoll_get_settings();
		$retention_days     = isset( $retention_settings['retention_days'] ) ? (int) $retention_settings['retention_days'] : 90;
		$base_url           = admin_url( 'options-general.php?page=crawlertoll&ct_tab=logs' );

		// React (pro-app, data-view=logs) replaces the whole logs surface —
		// retention control + filterable browser — with the live version (both have
		// REST endpoints now). The included PHP partials are the no-JS fallback.
		echo '<div id="crawlertoll-pro-app" data-view="logs">';
		include CRAWLERTOLL_PLUGIN_DIR . 'admin/views/pro-logs-retention.php';
		include CRAWLERTOLL_PLUGIN_DIR . 'admin/views/pro-logs.php';
		echo '</div>';
	}

	/**
	 * Render the multi-rail routing tab (§2.5). Per-bot settlement-rail
	 * overrides; any bot without an override uses the site default rail.
	 * Saves through its own nonce'd form (read-modify-write of
	 * crawlertoll_settings) so it never collides with the free Settings form.
	 *
	 * @return void
	 */
	public function render_rails_tab() {
		// Handle save.
		if ( isset( $_POST['crawlertoll_rails_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['crawlertoll_rails_nonce'] ) ), 'crawlertoll_save_rails' )
			&& current_user_can( 'manage_options' )
		) {
			$allowed = array_keys( crawlertoll_rail_options() );
			$posted  = ( isset( $_POST['ct_rail'] ) && is_array( $_POST['ct_rail'] ) ) ? wp_unslash( $_POST['ct_rail'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each element sanitised below.

			$overrides = array();
			foreach ( $posted as $bot_name => $rail ) {
				$bot_name = sanitize_text_field( $bot_name );
				$rail     = sanitize_text_field( $rail );
				if ( '' !== $rail && '' !== $bot_name && in_array( $rail, $allowed, true ) ) {
					$overrides[ $bot_name ] = $rail;
				}
			}

			$settings                   = crawlertoll_get_settings();
			$settings['rail_overrides'] = $overrides;
			update_option( CRAWLERTOLL_OPTION_KEY, $settings );

			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'Rail routing saved.', 'crawlertoll' );
			echo '</p></div>';
		}

		$settings     = crawlertoll_get_settings();
		$default_rail = isset( $settings['rail'] ) ? $settings['rail'] : 'x402';
		$overrides    = ( isset( $settings['rail_overrides'] ) && is_array( $settings['rail_overrides'] ) ) ? $settings['rail_overrides'] : array();
		$rail_options = crawlertoll_rail_options();
		$bots         = CrawlerToll_Bot_Catalogue::all();

		echo '<div id="crawlertoll-pro-app" data-view="rails">';
		include CRAWLERTOLL_PLUGIN_DIR . 'admin/views/pro-rails.php';
		echo '</div>';
	}

	/**
	 * Render the per-path pricing tab (§2.4). Longest-prefix path rules that
	 * override the flat per-crawl price for matching request paths. Saves
	 * through its own nonce'd form (read-modify-write of crawlertoll_settings)
	 * so it never collides with the free Settings form — same pattern as Rails.
	 *
	 * @return void
	 */
	public function render_pricing_tab() {
		$allowed_curr = array( 'USD', 'USDC', 'EUR', 'GBP' );

		// Handle save.
		if ( isset( $_POST['crawlertoll_pricing_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['crawlertoll_pricing_nonce'] ) ), 'crawlertoll_save_pricing' )
			&& current_user_can( 'manage_options' )
		) {
			$settings      = crawlertoll_get_settings();
			$site_currency = isset( $settings['currency'] ) ? $settings['currency'] : 'USD';

			$paths  = ( isset( $_POST['ct_price_path'] ) && is_array( $_POST['ct_price_path'] ) ) ? wp_unslash( $_POST['ct_price_path'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
			$micros = ( isset( $_POST['ct_price_micros'] ) && is_array( $_POST['ct_price_micros'] ) ) ? wp_unslash( $_POST['ct_price_micros'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast below.
			$currs  = ( isset( $_POST['ct_price_currency'] ) && is_array( $_POST['ct_price_currency'] ) ) ? wp_unslash( $_POST['ct_price_currency'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- whitelisted below.

			$rules = array();
			foreach ( $paths as $i => $p ) {
				$p = sanitize_text_field( $p );
				if ( '' === $p ) {
					continue; // Skip blank rows.
				}
				$m       = isset( $micros[ $i ] ) ? max( 0, (int) $micros[ $i ] ) : 0;
				$c       = ( isset( $currs[ $i ] ) && in_array( strtoupper( $currs[ $i ] ), $allowed_curr, true ) ) ? strtoupper( $currs[ $i ] ) : $site_currency;
				$rules[] = array(
					'path'         => $p,
					'price_micros' => $m,
					'currency'     => $c,
				);
			}

			$settings['path_pricing'] = $rules;
			update_option( CRAWLERTOLL_OPTION_KEY, $settings );

			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'Per-path pricing saved.', 'crawlertoll' );
			echo '</p></div>';
		}

		$settings      = crawlertoll_get_settings();
		$default_price = isset( $settings['price_micros'] ) ? (int) $settings['price_micros'] : 5000;
		$site_currency = isset( $settings['currency'] ) ? $settings['currency'] : 'USD';
		$rules         = ( isset( $settings['path_pricing'] ) && is_array( $settings['path_pricing'] ) ) ? $settings['path_pricing'] : array();
		$currencies    = $allowed_curr;

		echo '<div id="crawlertoll-pro-app" data-view="pricing">';
		include CRAWLERTOLL_PLUGIN_DIR . 'admin/views/pro-pricing.php';
		echo '</div>';
	}

	/**
	 * Render the email-alerts tab (§2.3). Toggles for daily/weekly/spike summary
	 * emails plus the recipient address. Saves through its own nonce'd form
	 * (read-modify-write of crawlertoll_settings), same pattern as Rails/Pricing.
	 * The cron logic lives in CrawlerToll_Alerts; this tab persists the flags it
	 * reads (without these saved settings the crons fire but send nothing).
	 *
	 * @return void
	 */
	public function render_alerts_tab() {
		// Handle save.
		if ( isset( $_POST['crawlertoll_alerts_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['crawlertoll_alerts_nonce'] ) ), 'crawlertoll_save_alerts' )
			&& current_user_can( 'manage_options' )
		) {
			$settings                  = crawlertoll_get_settings();
			$settings['alerts_daily']  = ! empty( $_POST['ct_alerts_daily'] );
			$settings['alerts_weekly'] = ! empty( $_POST['ct_alerts_weekly'] );
			$settings['alerts_spike']  = ! empty( $_POST['ct_alerts_spike'] );
			$email                     = isset( $_POST['ct_alert_email'] ) ? sanitize_email( wp_unslash( $_POST['ct_alert_email'] ) ) : '';
			$settings['alert_email']   = ( $email && is_email( $email ) ) ? $email : '';
			update_option( CRAWLERTOLL_OPTION_KEY, $settings );

			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'Alert settings saved.', 'crawlertoll' );
			echo '</p></div>';
		}

		$settings       = crawlertoll_get_settings();
		$alerts_daily   = ! empty( $settings['alerts_daily'] );
		$alerts_weekly  = ! empty( $settings['alerts_weekly'] );
		$alerts_spike   = ! empty( $settings['alerts_spike'] );
		$alert_email    = isset( $settings['alert_email'] ) ? (string) $settings['alert_email'] : '';
		$fallback_email = get_bloginfo( 'admin_email' );

		echo '<div id="crawlertoll-pro-app" data-view="alerts">';
		include CRAWLERTOLL_PLUGIN_DIR . 'admin/views/pro-alerts.php';
		echo '</div>';
	}

	/**
	 * Handle the "Export CSV / JSON" download. Hooked to
	 * wp_ajax_crawlertoll_export_logs. Streams a file and exits.
	 *
	 * Honours the same filters as the logs tab (bot, action, date range,
	 * sort) so the export matches what the admin sees on screen.
	 *
	 * @return void
	 */
	public function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crawlertoll' ), '', array( 'response' => 403 ) );
		}
		if ( ! self::is_pro_active() ) {
			wp_die( esc_html__( 'Log export is a CrawlerToll Pro feature. Get a license at crawlertoll.com.', 'crawlertoll' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'crawlertoll_export_logs' );

		$format = ( isset( $_GET['ct_export'] ) && 'json' === $_GET['ct_export'] ) ? 'json' : 'csv';

		$filters = array(
			'bot'     => isset( $_GET['ct_bot'] ) ? sanitize_text_field( wp_unslash( $_GET['ct_bot'] ) ) : '',
			'action'  => isset( $_GET['ct_action'] ) ? sanitize_text_field( wp_unslash( $_GET['ct_action'] ) ) : '',
			'from'    => isset( $_GET['ct_from'] ) ? sanitize_text_field( wp_unslash( $_GET['ct_from'] ) ) : '',
			'to'      => isset( $_GET['ct_to'] ) ? sanitize_text_field( wp_unslash( $_GET['ct_to'] ) ) : '',
			'orderby' => isset( $_GET['ct_orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['ct_orderby'] ) ) : 'request_time',
			'order'   => isset( $_GET['ct_order'] ) ? sanitize_text_field( wp_unslash( $_GET['ct_order'] ) ) : 'DESC',
		);

		$entries = $this->db->export( $filters );
		$stamp   = gmdate( 'Y-m-d' );

		nocache_headers();

		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="crawlertoll-logs-' . $stamp . '.json"' );
			$meta = array(
				'site'        => get_bloginfo( 'name' ),
				'exported_at' => gmdate( 'c' ),
				'filters'     => array_filter( $filters ),
				'count'       => count( $entries ),
			);
			echo self::format_logs_json( $entries, $meta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- file download, not HTML.
		} else {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="crawlertoll-logs-' . $stamp . '.csv"' );
			echo self::format_logs_csv( $entries ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- file download, not HTML.
		}

		exit;
	}

	/**
	 * Serialise log entries to CSV. Pure — no WP/DB/IO side effects, so it is
	 * unit-testable. Column order matches V2 spec §2.11.
	 *
	 * @param array<int,array<string,mixed>> $entries
	 * @return string
	 */
	public static function format_logs_csv( $entries ) {
		$columns = array( 'time', 'bot_name', 'operator', 'category', 'path', 'action', 'price_micros', 'currency', 'rail', 'content_hash', 'http_status' );

		$fh = fopen( 'php://temp', 'r+' );
		// Pass an explicit empty escape char: the default backslash escape is
		// deprecated in PHP 8.4+ and corrupts values ending in a backslash.
		fputcsv( $fh, $columns, ',', '"', '' );

		foreach ( $entries as $e ) {
			fputcsv(
				$fh,
				array(
					isset( $e['request_time'] ) ? $e['request_time'] : '',
					isset( $e['bot_name'] ) ? $e['bot_name'] : '',
					isset( $e['bot_operator'] ) ? $e['bot_operator'] : '',
					isset( $e['bot_category'] ) ? $e['bot_category'] : '',
					isset( $e['request_path'] ) ? $e['request_path'] : '',
					isset( $e['action'] ) ? $e['action'] : '',
					isset( $e['price_micros'] ) ? (int) $e['price_micros'] : 0,
					isset( $e['currency'] ) ? $e['currency'] : '',
					isset( $e['rail'] ) ? $e['rail'] : '',
					isset( $e['content_hash'] ) ? $e['content_hash'] : '',
					isset( $e['http_status'] ) ? $e['http_status'] : '',
				),
				',',
				'"',
				''
			);
		}

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );

		return $csv;
	}

	/**
	 * Serialise log entries to JSON with an export metadata header. Pure.
	 *
	 * @param array<int,array<string,mixed>> $entries
	 * @param array<string,mixed>            $meta
	 * @return string
	 */
	public static function format_logs_json( $entries, $meta = array() ) {
		return wp_json_encode(
			array(
				'meta'    => $meta,
				'entries' => array_values( $entries ),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);
	}
}
