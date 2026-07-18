<?php
/**
 * Email alerts — periodic summaries of AI crawler activity.
 * WP Cron hooks for daily, weekly, and monthly reports.
 *
 * Known limitation: wp_mail on shared WordPress hosting has ~40% spam rate.
 * For reliable delivery, configure an SMTP plugin or upgrade to a dedicated
 * email API (Postmark/SES) in a future version.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Alerts {

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
	 * Register cron hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'crawlertoll_daily_alert', array( $this, 'send_daily_alert' ) );
		add_action( 'crawlertoll_weekly_alert', array( $this, 'send_weekly_alert' ) );
		add_action( 'crawlertoll_spike_check', array( $this, 'send_spike_alert' ) );

		// Schedule if not already scheduled.
		if ( ! wp_next_scheduled( 'crawlertoll_daily_alert' ) ) {
			wp_schedule_event( time(), 'daily', 'crawlertoll_daily_alert' );
		}
		if ( ! wp_next_scheduled( 'crawlertoll_weekly_alert' ) ) {
			wp_schedule_event( strtotime( 'next Monday 09:00:00' ), 'weekly', 'crawlertoll_weekly_alert' );
		}
		if ( ! wp_next_scheduled( 'crawlertoll_spike_check' ) ) {
			wp_schedule_event( time(), 'hourly', 'crawlertoll_spike_check' );
		}
	}

	/**
	 * Clear all scheduled hooks on deactivation.
	 *
	 * @return void
	 */
	public static function clear_cron() {
		wp_clear_scheduled_hook( 'crawlertoll_daily_alert' );
		wp_clear_scheduled_hook( 'crawlertoll_weekly_alert' );
		wp_clear_scheduled_hook( 'crawlertoll_spike_check' );
	}

	/**
	 * Send daily summary.
	 */
	public function send_daily_alert() {
		$settings = crawlertoll_get_settings();
		if ( empty( $settings['alerts_daily'] ) ) {
			return;
		}
		$to = $this->get_alert_email( $settings );
		if ( ! $to ) {
			return;
		}

		$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		$stats     = $this->db->stats( $yesterday, $yesterday );
		$totals    = isset( $stats['totals'] ) ? $stats['totals'] : array();
		$crawls    = isset( $totals['total_crawls'] ) ? (int) $totals['total_crawls'] : 0;
		$revenue   = isset( $totals['total_revenue_micros'] ) ? number_format( (int) $totals['total_revenue_micros'] / 1000000, 2 ) : '0.00';

		if ( $crawls === 0 ) {
			return; // Nothing to report.
		}

		$subject = sprintf(
			/* translators: 1: site name, 2: crawl count */
			__( '[%1$s] CrawlerToll Daily: %2$s AI crawls, $%3$s potential revenue', 'crawlertoll' ),
			get_bloginfo( 'name' ),
			number_format_i18n( $crawls ),
			$revenue
		);

		$body = $this->build_email_body( $stats, $yesterday, $yesterday, 'daily' );
		wp_mail( $to, $subject, $body );
	}

	/**
	 * Send weekly summary.
	 */
	public function send_weekly_alert() {
		$settings = crawlertoll_get_settings();
		if ( empty( $settings['alerts_weekly'] ) ) {
			return;
		}
		$to = $this->get_alert_email( $settings );
		if ( ! $to ) {
			return;
		}

		$to_date   = gmdate( 'Y-m-d' );
		$from_date = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
		$stats     = $this->db->stats( $from_date, $to_date );
		$totals    = isset( $stats['totals'] ) ? $stats['totals'] : array();
		$crawls    = isset( $totals['total_crawls'] ) ? (int) $totals['total_crawls'] : 0;
		$revenue   = isset( $totals['total_revenue_micros'] ) ? number_format( (int) $totals['total_revenue_micros'] / 1000000, 2 ) : '0.00';

		if ( $crawls === 0 ) {
			return;
		}

		$subject = sprintf(
			/* translators: 1: site name, 2: crawl count */
			__( '[%1$s] CrawlerToll Weekly Report: %2$s crawls, $%3$s potential revenue', 'crawlertoll' ),
			get_bloginfo( 'name' ),
			number_format_i18n( $crawls ),
			$revenue
		);

		$body = $this->build_email_body( $stats, $from_date, $to_date, 'weekly' );
		wp_mail( $to, $subject, $body );
	}

	/**
	 * Spike detection — fires hourly. Alerts if any bot exceeds 3× its 7-day average.
	 */
	public function send_spike_alert() {
		$settings = crawlertoll_get_settings();
		if ( empty( $settings['alerts_spike'] ) ) {
			return;
		}
		$to = $this->get_alert_email( $settings );
		if ( ! $to ) {
			return;
		}

		$today     = gmdate( 'Y-m-d' );
		$week_ago  = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
		$today_stats = $this->db->stats( $today, $today );
		$week_stats  = $this->db->stats( $week_ago, $today );

		$today_bots = isset( $today_stats['top_bots'] ) ? $today_stats['top_bots'] : array();
		$week_bots  = isset( $week_stats['top_bots'] ) ? $week_stats['top_bots'] : array();

		// Build a weekly average map.
		$weekly_avg = array();
		foreach ( $week_bots as $bot ) {
			$weekly_avg[ $bot['bot_name'] ] = (int) $bot['crawls'] / 7;
		}

		$spikes = array();
		foreach ( $today_bots as $bot ) {
			$name   = $bot['bot_name'];
			$today_count = (int) $bot['crawls'];
			$avg    = isset( $weekly_avg[ $name ] ) ? $weekly_avg[ $name ] : 0;
			if ( $avg > 0 && $today_count > ( $avg * 3 ) && $today_count >= 10 ) {
				$spikes[] = array(
					'name'      => $name,
					'operator'  => isset( $bot['bot_operator'] ) ? $bot['bot_operator'] : '',
					'today'     => $today_count,
					'average'   => round( $avg, 1 ),
				);
			}
		}

		if ( empty( $spikes ) ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] CrawlerToll Spike Alert — unusual AI crawler activity', 'crawlertoll' ),
			get_bloginfo( 'name' )
		);

		$body = sprintf(
			/* translators: %s: site name */
			__( 'Unusual AI crawler activity detected on %s:', 'crawlertoll' ),
			get_bloginfo( 'name' )
		) . "\n\n";

		foreach ( $spikes as $spike ) {
			$body .= sprintf(
				"%s (%s): %d crawls today vs %.1f daily average\n",
				$spike['name'],
				$spike['operator'],
				$spike['today'],
				$spike['average']
			);
		}

		$body .= "\n" . __( 'Full dashboard:', 'crawlertoll' ) . ' ';
		$body .= admin_url( 'options-general.php?page=crawlertoll&ct_tab=revenue' );
		$body .= "\n\n— CrawlerToll™ · Charthouse Ltd\n";

		wp_mail( $to, $subject, $body );
	}

	/**
	 * Build the email body for daily/weekly summaries.
	 *
	 * @param array  $stats
	 * @param string $from
	 * @param string $to
	 * @param string $period 'daily' or 'weekly'
	 * @return string
	 */
	private function build_email_body( $stats, $from, $to, $period ) {
		$totals  = isset( $stats['totals'] ) ? $stats['totals'] : array();
		$top_bots = isset( $stats['top_bots'] ) ? $stats['top_bots'] : array();

		$crawls  = isset( $totals['total_crawls'] ) ? (int) $totals['total_crawls'] : 0;
		$charged = isset( $totals['charged'] ) ? (int) $totals['charged'] : 0;
		$blocked = isset( $totals['blocked'] ) ? (int) $totals['blocked'] : 0;
		$revenue = isset( $totals['total_revenue_micros'] ) ? number_format( (int) $totals['total_revenue_micros'] / 1000000, 2 ) : '0.00';

		$body  = sprintf(
			/* translators: 1: site name, 2: period label */
			__( '%1$s — %2$s AI crawler report', 'crawlertoll' ),
			get_bloginfo( 'name' ),
			$period === 'daily' ? __( 'Daily', 'crawlertoll' ) : __( 'Weekly', 'crawlertoll' )
		) . "\n";
		$body .= str_repeat( '—', 40 ) . "\n\n";

		$body .= sprintf( __( 'Period: %s — %s', 'crawlertoll' ), $from, $to ) . "\n";
		$body .= sprintf( __( 'Total AI crawls: %s', 'crawlertoll' ), number_format_i18n( $crawls ) ) . "\n";
		$body .= sprintf( __( 'Charged (402): %s', 'crawlertoll' ), number_format_i18n( $charged ) ) . "\n";
		$body .= sprintf( __( 'Blocked (403): %s', 'crawlertoll' ), number_format_i18n( $blocked ) ) . "\n";
		$body .= sprintf( __( 'Potential revenue: $%s', 'crawlertoll' ), $revenue ) . "\n\n";

		if ( ! empty( $top_bots ) ) {
			$body .= __( 'Top AI crawlers:', 'crawlertoll' ) . "\n";
			foreach ( array_slice( $top_bots, 0, 5 ) as $bot ) {
				$bot_rev = isset( $bot['revenue_micros'] ) ? number_format( (int) $bot['revenue_micros'] / 1000000, 2 ) : '0.00';
				$body .= sprintf( "  %s: %s crawls, $%s\n", $bot['bot_name'], number_format_i18n( (int) $bot['crawls'] ), $bot_rev );
			}
		}

		$body .= "\n" . __( 'Full dashboard:', 'crawlertoll' ) . ' ';
		$body .= admin_url( 'options-general.php?page=crawlertoll&ct_tab=revenue' );
		$body .= "\n\n— CrawlerToll™ · Charthouse Ltd\n";
		$body .= __( 'To adjust alerts: Settings → CrawlerToll → Alerts', 'crawlertoll' );

		return $body;
	}

	/**
	 * Get the alert email address from settings or fall back to admin email.
	 *
	 * @param array $settings
	 * @return string
	 */
	private function get_alert_email( $settings ) {
		if ( ! empty( $settings['alert_email'] ) && is_email( $settings['alert_email'] ) ) {
			return $settings['alert_email'];
		}
		return get_bloginfo( 'admin_email' );
	}
}
