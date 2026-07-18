<?php
/**
 * Admin settings page — Settings → CrawlerToll.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Admin {

	/**
	 * @var CrawlerToll_DB|null
	 */
	private $db;

	/**
	 * @var CrawlerToll_Pro_Admin|null
	 */
	private $pro_admin;

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Initialise Pro admin if available.
		if ( class_exists( 'CrawlerToll_DB' ) ) {
			global $wpdb;
			$this->db = new CrawlerToll_DB( $wpdb );
		}
		if ( class_exists( 'CrawlerToll_Pro_Admin' ) && $this->db ) {
			$this->pro_admin = new CrawlerToll_Pro_Admin( $this->db );
			$this->pro_admin->register();
		}
	}

	public function enqueue_assets( $hook ) {
		if ( 'settings_page_crawlertoll' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'crawlertoll-admin',
			plugin_dir_url( CRAWLERTOLL_PLUGIN_FILE ) . 'assets/admin.css',
			array(),
			CRAWLERTOLL_VERSION
		);
		wp_enqueue_script(
			'crawlertoll-admin',
			plugin_dir_url( CRAWLERTOLL_PLUGIN_FILE ) . 'assets/admin.js',
			array(),
			CRAWLERTOLL_VERSION,
			true
		);

		// Mount the React free-app (Vite bundle) into the settings page. The
		// enqueuer is file_exists-guarded: if the bundle is stripped or unbuilt it
		// returns false and the page keeps its server-rendered status cards
		// (progressive enhancement — see admin/views/settings.php). When it does
		// mount, React replaces those cards with the live version, fed by the data
		// blob below.
		if ( CrawlerToll_Vite::enqueue( 'free', 'crawlertoll-free-app' ) ) {
			$settings = crawlertoll_get_settings();
			$bots     = CrawlerToll_Bot_Catalogue::all();
			$policy   = CrawlerToll_RSL_Parser::parse( $settings['policy'] );
			$data     = array(
				'enabled'      => ! empty( $settings['enabled'] ),
				'botCount'     => count( $bots ),
				'policyGroups' => count( $policy['groups'] ),
				'priceMicros'  => (int) $settings['price_micros'],
				'currency'     => (string) $settings['currency'],
			);
			wp_add_inline_script(
				'crawlertoll-free-app',
				'window.crawlertollFree = ' . wp_json_encode( $data ) . ';',
				'before'
			);
		}
	}

	public function register_menu() {
		add_options_page(
			__( 'CrawlerToll', 'crawlertoll' ),
			__( 'CrawlerToll', 'crawlertoll' ),
			'manage_options',
			'crawlertoll',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'crawlertoll',
			CRAWLERTOLL_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => crawlertoll_default_settings(),
			)
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$defaults = crawlertoll_default_settings();

		// Start from the currently-stored settings (merged over defaults) rather
		// than from defaults alone, so fields this form doesn't render — e.g.
		// rail_overrides, managed by the Pro Rails tab — survive a save here.
		$current = get_option( CRAWLERTOLL_OPTION_KEY );
		$out     = is_array( $current ) ? wp_parse_args( $current, $defaults ) : $defaults;

		if ( ! is_array( $input ) ) {
			return $out;
		}

		$out['enabled']             = ! empty( $input['enabled'] );
		$out['price_micros']        = isset( $input['price_micros'] ) ? max( 0, (int) $input['price_micros'] ) : $defaults['price_micros'];
		$out['currency']            = isset( $input['currency'] ) && in_array( strtoupper( $input['currency'] ), array( 'USD', 'USDC', 'EUR', 'GBP' ), true )
			? strtoupper( $input['currency'] )
			: $defaults['currency'];
		$out['rail']                = isset( $input['rail'] ) && in_array( $input['rail'], array_keys( crawlertoll_rail_options() ), true )
			? $input['rail']
			: $defaults['rail'];
		$out['payment_url']         = isset( $input['payment_url'] ) ? esc_url_raw( trim( $input['payment_url'] ) ) : '';
		$out['terms_url']           = isset( $input['terms_url'] ) ? esc_url_raw( trim( $input['terms_url'] ) ) : '';
		$out['context_license_url'] = isset( $input['context_license_url'] ) ? esc_url_raw( trim( $input['context_license_url'] ) ) : '';
		$out['policy']              = isset( $input['policy'] ) ? sanitize_textarea_field( wp_unslash( $input['policy'] ) ) : $defaults['policy'];
		$out['remove_data_on_uninstall'] = ! empty( $input['remove_data_on_uninstall'] );

		return $out;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crawlertoll' ) );
		}

		// Determine active tab.
		$pro_active = $this->pro_admin && CrawlerToll_Pro_Admin::is_pro_active();
		$tab = isset( $_GET['ct_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['ct_tab'] ) ) : 'settings'; // phpcs:ignore

		$tabs = array(
			'settings'  => __( 'Settings', 'crawlertoll' ),
			'pricing'   => __( 'Pricing', 'crawlertoll' ),
			'alerts'    => __( 'Alerts', 'crawlertoll' ),
			'revenue'   => __( 'Revenue', 'crawlertoll' ),
			'logs'      => __( 'Logs', 'crawlertoll' ),
			'rails'     => __( 'Rails', 'crawlertoll' ),
		);

		// If Pro isn't active, redirect Pro tabs to settings with a notice.
		if ( ! $pro_active && in_array( $tab, array( 'pricing', 'alerts', 'revenue', 'logs', 'rails' ), true ) ) {
			$tab = 'settings';
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-info is-dismissible"><p>';
				esc_html_e( 'Revenue dashboard and bot-request logs are CrawlerToll Pro features. Get a license at crawlertoll.com.', 'crawlertoll' );
				echo '</p></div>';
			} );
		}

		// Render tab navigation.
		echo '<div class="wrap">';
		echo '<div class="ct-header">';
		echo '<h1>' . esc_html__( 'CrawlerToll', 'crawlertoll' ) . ' <span class="ct-badge">v' . esc_html( CRAWLERTOLL_VERSION ) . '</span></h1>';
		echo '</div>';

		echo '<nav class="ct-tabs" style="margin-bottom:24px;border-bottom:2px solid #e2e8f0;display:flex;gap:0;">';
		foreach ( $tabs as $tab_key => $tab_label ) {
			$is_pro_tab = in_array( $tab_key, array( 'pricing', 'alerts', 'revenue', 'logs', 'rails' ), true );
			$classes = 'ct-tab';
			if ( $tab_key === $tab ) {
				$classes .= ' ct-tab-active';
			}
			if ( $is_pro_tab && ! $pro_active ) {
				$classes .= ' ct-tab-locked';
			}
			$url = add_query_arg( 'ct_tab', $tab_key, admin_url( 'options-general.php?page=crawlertoll' ) );
			echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $classes ) . '" style="display:inline-block;padding:10px 20px;text-decoration:none;font-weight:600;font-size:14px;color:';
			echo ( $tab_key === $tab ) ? '#6366f1' : '#64748b';
			echo ';border-bottom:';
			echo ( $tab_key === $tab ) ? '3px solid #6366f1' : '3px solid transparent';
			echo ';margin-bottom:-2px;transition:color .15s,border-color .15s;">';
			echo esc_html( $tab_label );
			if ( $is_pro_tab && ! $pro_active ) {
				echo ' <span style="font-size:10px;background:#f59e0b;color:#fff;padding:2px 6px;border-radius:99px;vertical-align:middle;">PRO</span>';
			}
			echo '</a>';
		}
		echo '</nav>';

		// Route to the appropriate view.
		switch ( $tab ) {
			case 'pricing':
				if ( $pro_active && $this->pro_admin ) {
					$this->pro_admin->render_pricing_tab();
				}
				break;
			case 'alerts':
				if ( $pro_active && $this->pro_admin ) {
					$this->pro_admin->render_alerts_tab();
				}
				break;
			case 'revenue':
				if ( $pro_active && $this->pro_admin ) {
					$this->pro_admin->render_revenue_tab();
				}
				break;
			case 'logs':
				if ( $pro_active && $this->pro_admin ) {
					$this->pro_admin->render_logs_tab();
				}
				break;
			case 'rails':
				if ( $pro_active && $this->pro_admin ) {
					$this->pro_admin->render_rails_tab();
				}
				break;
			default:
				$this->render_settings_tab();
				break;
		}

		echo '</div>'; // .wrap
	}

	/**
	 * Render the settings tab (the original settings page).
	 */
	private function render_settings_tab() {
		$settings = crawlertoll_get_settings();
		$bots     = CrawlerToll_Bot_Catalogue::all();
		$rails    = crawlertoll_rail_options();

		// Count bot categories for the status cards.
		$category_counts = array();
		foreach ( $bots as $bot ) {
			$cat = $bot['category'];
			$category_counts[ $cat ] = ( $category_counts[ $cat ] ?? 0 ) + 1;
		}

		// Parse the policy to count active groups.
		$policy_data   = CrawlerToll_RSL_Parser::parse( $settings['policy'] );
		$active_bots   = 0;
		$active_groups = count( $policy_data['groups'] );
		foreach ( $policy_data['groups'] as $group ) {
			$active_bots += count( $group['user_agents'] );
		}

		include CRAWLERTOLL_PLUGIN_DIR . 'admin/views/settings.php';
	}
}
