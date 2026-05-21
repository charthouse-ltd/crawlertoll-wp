<?php
/**
 * Plugin Name:       CrawlerToll
 * Plugin URI:        https://crawlertoll.com
 * Description:       AI-crawler enforcement for WordPress. Detects AI crawlers (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, +25 more), applies RSL 1.0 policy, and issues HTTP 402 with a structured payment offer. Vendor-neutral; works with TollBit, Skyfire, x402, Cloudflare Pay Per Crawl, and Stripe ACP.
 * Version:           0.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Charthouse Ltd
 * Author URI:        https://charthouse.io
 * License:           Apache-2.0 OR GPL-2.0-or-later
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       crawlertoll
 * Domain Path:       /languages
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct file access.
}

define( 'CRAWLERTOLL_VERSION', '0.1.1' );
define( 'CRAWLERTOLL_PLUGIN_FILE', __FILE__ );
define( 'CRAWLERTOLL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CRAWLERTOLL_OPTION_KEY', 'crawlertoll_settings' );

require_once CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-bot-catalogue.php';
require_once CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-rsl-parser.php';
require_once CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-decision.php';
require_once CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-http402.php';
require_once CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-plugin.php';
require_once CRAWLERTOLL_PLUGIN_DIR . 'admin/class-crawlertoll-admin.php';

/**
 * Boot the plugin once WordPress has loaded.
 */
function crawlertoll_bootstrap() {
	$plugin = new CrawlerToll_Plugin();
	$plugin->register();

	if ( is_admin() ) {
		$admin = new CrawlerToll_Admin();
		$admin->register();
	}
}
add_action( 'plugins_loaded', 'crawlertoll_bootstrap' );

/**
 * Default settings — used on first install and as fallback when reading
 * a setting that hasn't been saved yet.
 *
 * @return array<string,mixed>
 */
function crawlertoll_default_settings() {
	return array(
		'enabled'              => true,
		'price_micros'         => 5000,
		'currency'             => 'USD',
		'rail'                 => 'x402',
		'payment_url'          => '',
		'terms_url'            => '',
		'context_license_url'  => '',
		'policy'               => crawlertoll_default_policy(),
	);
}

/**
 * The default RSL 1.0 policy that ships with the plugin. Adoptable as-is
 * for most publishers; customise via Settings → CrawlerToll.
 *
 * @return string
 */
function crawlertoll_default_policy() {
	return "User-agent: GPTBot\n"
		. "User-agent: ClaudeBot\n"
		. "User-agent: PerplexityBot\n"
		. "User-agent: CCBot\n"
		. "User-agent: Google-Extended\n"
		. "User-agent: Applebot-Extended\n"
		. "User-agent: Meta-ExternalAgent\n"
		. "User-agent: Bytespider\n"
		. "Disallow: /\n"
		. "Allow: /wp-content/uploads/\n"
		. "License: " . esc_url_raw( home_url( '/ai-license' ) ) . "\n"
		. "Permits: ai-search, rag\n"
		. "Prohibits: ai-training, redistribution-without-attribution\n"
		. "Compensation: per-crawl 5000 micros USD\n"
		. "Standard: RSL/1.0\n"
		. "\n"
		. "User-agent: *\n"
		. "Disallow:\n";
}

/**
 * Read the merged settings (defaults + saved overrides).
 *
 * @return array<string,mixed>
 */
function crawlertoll_get_settings() {
	$saved = get_option( CRAWLERTOLL_OPTION_KEY, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( crawlertoll_default_settings(), $saved );
}

register_activation_hook(
	__FILE__,
	function () {
		if ( get_option( CRAWLERTOLL_OPTION_KEY ) === false ) {
			add_option( CRAWLERTOLL_OPTION_KEY, crawlertoll_default_settings() );
		}
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		flush_rewrite_rules();
	}
);
