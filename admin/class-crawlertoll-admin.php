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

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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
		$out = $defaults;

		if ( ! is_array( $input ) ) {
			return $out;
		}

		$out['enabled']             = ! empty( $input['enabled'] );
		$out['price_micros']        = isset( $input['price_micros'] ) ? max( 0, (int) $input['price_micros'] ) : $defaults['price_micros'];
		$out['currency']            = isset( $input['currency'] ) && in_array( strtoupper( $input['currency'] ), array( 'USD', 'USDC', 'EUR', 'GBP' ), true )
			? strtoupper( $input['currency'] )
			: $defaults['currency'];
		$out['rail']                = isset( $input['rail'] ) && in_array( $input['rail'], array( 'x402', 'tollbit', 'skyfire', 'cloudflare-ppc', 'stripe-acp', 'context-license', 'custom' ), true )
			? $input['rail']
			: $defaults['rail'];
		$out['payment_url']         = isset( $input['payment_url'] ) ? esc_url_raw( trim( $input['payment_url'] ) ) : '';
		$out['terms_url']           = isset( $input['terms_url'] ) ? esc_url_raw( trim( $input['terms_url'] ) ) : '';
		$out['context_license_url'] = isset( $input['context_license_url'] ) ? esc_url_raw( trim( $input['context_license_url'] ) ) : '';
		$out['policy']              = isset( $input['policy'] ) ? trim( (string) wp_unslash( $input['policy'] ) ) : $defaults['policy'];

		return $out;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crawlertoll' ) );
		}
		$settings = crawlertoll_get_settings();
		$bots     = CrawlerToll_Bot_Catalogue::all();
		$rails    = array(
			'x402'            => 'x402 — Coinbase + LF stablecoin rail',
			'tollbit'         => 'TollBit hosted paywall',
			'skyfire'         => 'Skyfire KYAPay token',
			'cloudflare-ppc'  => 'Cloudflare Pay Per Crawl',
			'stripe-acp'      => 'Stripe Agentic Commerce Protocol',
			'context-license' => 'Per /.well-known/context-license.json',
			'custom'          => 'Custom',
		);

		// Count bot categories for the status cards.
		$category_counts = array();
		foreach ( $bots as $bot ) {
			$cat = $bot['category'];
			$category_counts[ $cat ] = ( $category_counts[ $cat ] ?? 0 ) + 1;
		}

		// Parse the policy to count active groups.
		$policy_data = CrawlerToll_RSL_Parser::parse( $settings['policy'] );
		$active_bots = 0;
		$active_groups = count( $policy_data['groups'] );
		foreach ( $policy_data['groups'] as $group ) {
			$active_bots += count( $group['user_agents'] );
		}

		include CRAWLERTOLL_PLUGIN_DIR . 'admin/views/settings.php';
	}
}
