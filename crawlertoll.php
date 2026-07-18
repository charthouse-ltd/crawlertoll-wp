<?php
/**
 * Plugin Name:       CrawlerToll
 * Plugin URI:        https://crawlertoll.com
 * Description:       AI-crawler enforcement for WordPress. Detects AI crawlers (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, +25 more), applies RSL 1.0 policy, and issues HTTP 402 with a structured payment offer. Vendor-neutral; works with TollBit, Skyfire, x402, Cloudflare Pay Per Crawl, and Stripe ACP.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Charthouse Ltd
 * Author URI:        https://charthouse.cc
 * License:           Apache-2.0 OR GPL-2.0-or-later
 * License URI:       https://www.apache.org/licenses/LICENSE-2.0
 * Text Domain:       crawlertoll
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct file access.
}

// @ct-build:freemius-start  (build.sh strips this block from the free wp.org artifact — the wp_org_gatekeeper SECRET must never ship)
/*
 * ─── Freemius licensing (Pro gate) ─────────────────────────────────────────
 * PREMIUM BUILD ONLY. `wp_org_gatekeeper` below is a SECRET that Freemius
 * auto-strips from its generated free build. We build the wp.org free zip by
 * hand, so this whole block (at minimum the gatekeeper line) MUST be removed
 * before any wp.org upload, and MUST NOT be committed to the public repo.
 * Guarded on the bundled SDK: a checkout without vendor/freemius/ degrades to
 * Pro-off rather than a fatal.
 */
if ( ! function_exists( 'crawlertoll_fs' ) && file_exists( __DIR__ . '/vendor/freemius/start.php' ) ) {
	function crawlertoll_fs() {
		global $crawlertoll_fs;

		if ( ! isset( $crawlertoll_fs ) ) {
			require_once __DIR__ . '/vendor/freemius/start.php';

			$crawlertoll_fs = fs_dynamic_init( array(
				'id'                  => '32506',
				'slug'                => 'crawlertoll',
				'type'                => 'plugin',
				'public_key'          => 'pk_30f053472ea39ed708f0b537b1a50',
				'is_premium'          => true,
				'premium_suffix'      => 'Pro',
				'has_premium_version' => true,
				'has_addons'          => false,
				'has_paid_plans'      => true,
				'is_org_compliant'    => true,
				// SECRET — strip before any wp.org upload (see header note above).
				'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
				'trial'               => array(
					'days'               => 14,
					'is_require_payment' => false,
				),
				'menu'                => array(
					'slug'       => 'crawlertoll',
					'first-path' => 'options-general.php?page=crawlertoll',
					'parent'     => array(
						'slug' => 'options-general.php',
					),
				),
			) );

			// Pair the premium build with its wp.org free counterpart so activating
			// one auto-deactivates the other (Freemius's set_basename contract).
			$crawlertoll_fs->set_basename( true, __FILE__ );
		}

		return $crawlertoll_fs;
	}

	crawlertoll_fs();
	do_action( 'crawlertoll_fs_loaded' );
}
// @ct-build:freemius-end

define( 'CRAWLERTOLL_VERSION', '0.2.0' );
define( 'CRAWLERTOLL_PLUGIN_FILE', __FILE__ );
define( 'CRAWLERTOLL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CRAWLERTOLL_OPTION_KEY', 'crawlertoll_settings' );

require_once CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-bot-catalogue.php';
require_once CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-rsl-parser.php';
require_once CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-safemode.php';
require_once CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-decision.php';
require_once CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-http402.php';
require_once CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-vite.php';
require_once CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-cut.php';
// Premium-path sealing engine (WS3) — ships free; free-safe (no Pro-only deps).
require_once CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-sealed.php';
require_once CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-registry.php';
require_once CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-sealed-gate.php';
require_once CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-premium-gate.php';
// Pro classes. These files are STRIPPED from the free wp.org build (see build.sh),
// so each require is file_exists-guarded — the free plugin degrades to Pro-off
// instead of fataling on a missing file. The premium build ships all six.
foreach ( array(
	'includes/class-crawlertoll-db.php',
	'includes/class-crawlertoll-logger.php',
	'includes/class-crawlertoll-provenance.php',
	'includes/class-crawlertoll-pricing.php',
	'includes/class-crawlertoll-alerts.php',
	'includes/class-crawlertoll-catalogue-updater.php',
) as $crawlertoll_pro_file ) {
	$crawlertoll_pro_path = CRAWLERTOLL_PLUGIN_DIR . $crawlertoll_pro_file;
	if ( file_exists( $crawlertoll_pro_path ) ) {
		require_once $crawlertoll_pro_path;
	}
}
unset( $crawlertoll_pro_file, $crawlertoll_pro_path );

require_once CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-plugin.php';
require_once CRAWLERTOLL_PLUGIN_DIR . 'admin/class-crawlertoll-admin.php';
require_once CRAWLERTOLL_PLUGIN_DIR . 'admin/class-crawlertoll-pro-admin.php';

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

	// Premium-path gate (WS3) — seals marked posts for everyone but editors.
	// Registered unconditionally: sealing is a FREE feature.
	( new CrawlerToll_Premium_Gate() )->register();

	// Pro features — registered ONLY for an active license (Freemius-backed).
	// is_pro_active() is false on free installs, so the alert + catalogue crons
	// (and the external calls they schedule) never run without Pro.
	if ( CrawlerToll_Pro_Admin::is_pro_active() ) {
		global $wpdb;
		$db = new CrawlerToll_DB( $wpdb );
		( new CrawlerToll_Alerts( $db ) )->register();
		CrawlerToll_CatalogueUpdater::register();
		add_action( 'admin_notices', array( 'CrawlerToll_CatalogueUpdater', 'maybe_show_new_bots_notice' ) );

		// Daily log-retention purge (§2.2): delete entries older than the window.
		add_action( 'crawlertoll_purge_logs', 'crawlertoll_run_log_purge' );
		if ( ! wp_next_scheduled( 'crawlertoll_purge_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'crawlertoll_purge_logs' );
		}
	}
}
add_action( 'plugins_loaded', 'crawlertoll_bootstrap' );

/**
 * Register the per-post "premium (sealed) content" marking (WS3 slice 3.2).
 * Boolean post meta, REST-exposed so the React admin can toggle it; editable by
 * anyone who can edit posts. INERT until the serving path lands (slice 3.3) — a
 * marked post is not split or sealed by anything yet.
 *
 * @return void
 */
function crawlertoll_register_premium_meta() {
	register_post_meta(
		'post',
		CrawlerToll_Cut::META_KEY,
		array(
			'type'          => 'boolean',
			'single'        => true,
			'default'       => false,
			'show_in_rest'  => true,
			'auth_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		)
	);
}
add_action( 'init', 'crawlertoll_register_premium_meta' );

/**
 * Cron callback: delete bot-request logs older than the retention window.
 * A `retention_days` of 0 means "keep forever" — skip the purge.
 *
 * @return void
 */
function crawlertoll_run_log_purge() {
	// DB is a Pro class. The cron is only ever scheduled while Pro is active, but
	// guard defensively so a stale event firing under the free build (DB stripped)
	// degrades to a no-op instead of a fatal.
	if ( ! class_exists( 'CrawlerToll_DB' ) ) {
		return;
	}
	$settings = crawlertoll_get_settings();
	$days     = isset( $settings['retention_days'] ) ? (int) $settings['retention_days'] : 90;
	if ( $days < 1 ) {
		return;
	}
	global $wpdb;
	$db = new CrawlerToll_DB( $wpdb );
	$db->purge_old( $days );
}

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
		'remove_data_on_uninstall' => false,
		'rail_overrides'       => array(),
		'path_pricing'         => array(),
		'alerts_daily'         => false,
		'alerts_weekly'        => false,
		'alerts_spike'         => false,
		'alert_email'          => '',
		'retention_days'       => 90,
	);
}

/**
 * The settlement rails CrawlerToll can present in a 402 offer.
 * Single source of truth for the rail value → label map, shared by the
 * settings dropdown, the sanitize whitelist, and the Pro Rails tab.
 *
 * @return array<string,string>
 */
function crawlertoll_rail_options() {
	return array(
		'x402'            => 'x402 — Coinbase + LF stablecoin rail',
		'tollbit'         => 'TollBit hosted paywall',
		'skyfire'         => 'Skyfire KYAPay token',
		'cloudflare-ppc'  => 'Cloudflare Pay Per Crawl',
		'stripe-acp'      => 'Stripe Agentic Commerce Protocol',
		'context-license' => 'Per /.well-known/context-license.json',
		'custom'          => 'Custom',
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
		// DB is a Pro class (stripped from the free build) — only create the log
		// table when it ships. The free build never writes logs, so skipping it
		// is correct; activation must not fatal on the missing file.
		$crawlertoll_db_file = CRAWLERTOLL_PLUGIN_DIR . 'includes/class-crawlertoll-db.php';
		if ( file_exists( $crawlertoll_db_file ) ) {
			require_once $crawlertoll_db_file;
			$db = new CrawlerToll_DB( $GLOBALS['wpdb'] );
			$db->maybe_create_table();
		}
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		if ( class_exists( 'CrawlerToll_Alerts' ) ) {
			CrawlerToll_Alerts::clear_cron();
		}
		if ( class_exists( 'CrawlerToll_CatalogueUpdater' ) ) {
			CrawlerToll_CatalogueUpdater::clear_cron();
		}
		wp_clear_scheduled_hook( 'crawlertoll_purge_logs' );
		flush_rewrite_rules();
	}
);
