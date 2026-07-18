<?php
/**
 * Pro → Email alerts tab (§2.3). Daily/weekly/spike summary toggles + recipient.
 * Rendered by CrawlerToll_Pro_Admin::render_alerts_tab().
 *
 * @package CrawlerToll
 * @var bool   $alerts_daily
 * @var bool   $alerts_weekly
 * @var bool   $alerts_spike
 * @var string $alert_email
 * @var string $fallback_email Site admin email used when $alert_email is blank.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ct-pro-alerts">
	<h2><?php esc_html_e( 'Email alerts', 'crawlertoll' ); ?></h2>
	<p class="description" style="max-width:640px;">
		<?php esc_html_e( 'Get emailed about AI-crawler activity. Summaries are built from your bot-request logs, so they only have data while logging is active (Pro).', 'crawlertoll' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( add_query_arg( 'ct_tab', 'alerts', admin_url( 'options-general.php?page=crawlertoll' ) ) ); ?>">
		<?php wp_nonce_field( 'crawlertoll_save_alerts', 'crawlertoll_alerts_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Summaries', 'crawlertoll' ); ?></th>
				<td>
					<label style="display:block;margin-bottom:8px;">
						<input type="checkbox" name="ct_alerts_daily" value="1" <?php checked( $alerts_daily ); ?> />
						<?php esc_html_e( "Daily summary — yesterday's crawls + potential revenue", 'crawlertoll' ); ?>
					</label>
					<label style="display:block;margin-bottom:8px;">
						<input type="checkbox" name="ct_alerts_weekly" value="1" <?php checked( $alerts_weekly ); ?> />
						<?php esc_html_e( 'Weekly report — the last 7 days, sent every Monday', 'crawlertoll' ); ?>
					</label>
					<label style="display:block;">
						<input type="checkbox" name="ct_alerts_spike" value="1" <?php checked( $alerts_spike ); ?> />
						<?php esc_html_e( 'Spike alert — when a crawler exceeds 3× its 7-day average', 'crawlertoll' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="ct_alert_email"><?php esc_html_e( 'Send to', 'crawlertoll' ); ?></label></th>
				<td>
					<input type="email" id="ct_alert_email" name="ct_alert_email" class="regular-text"
						value="<?php echo esc_attr( $alert_email ); ?>"
						placeholder="<?php echo esc_attr( $fallback_email ); ?>" />
					<p class="description">
						<?php
						printf(
							/* translators: %s: site admin email address. */
							esc_html__( 'Leave blank to use the site admin address (%s).', 'crawlertoll' ),
							esc_html( $fallback_email )
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<p class="description" style="max-width:640px;">
			<?php esc_html_e( 'Delivery uses WordPress email (wp_mail). On shared hosting that can land in spam — for reliable delivery, install an SMTP plugin (Postmark, Amazon SES, etc.).', 'crawlertoll' ); ?>
		</p>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save alerts', 'crawlertoll' ); ?></button></p>
	</form>
</div>
