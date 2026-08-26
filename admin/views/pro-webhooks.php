<?php
/**
 * Webhooks tab view (Pro). Rendered by CrawlerToll_Pro_Admin::render_webhooks_tab().
 *
 * @var array $wh          Local webhook config {url, secret} (empty when unconfigured).
 * @var bool  $configured  Whether a webhook is active.
 * @var array|WP_Error $deliveries Recent delivery rows from the registry.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ct-card" style="max-width:820px;">
	<h2><?php esc_html_e( 'Unlock Webhooks', 'crawlertoll' ); ?></h2>
	<p>
		<?php esc_html_e( 'Get a signed HTTP POST at your endpoint every time a reader or agent unlocks content — paid or free (metered). Point it at Zapier, n8n, Make, or your own system to trigger onboarding emails, Slack pings, or warehouse syncs.', 'crawlertoll' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( add_query_arg( 'ct_tab', 'webhooks', admin_url( 'options-general.php?page=crawlertoll' ) ) ); ?>">
		<?php wp_nonce_field( 'crawlertoll_save_webhook', 'crawlertoll_webhook_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ct_webhook_url"><?php esc_html_e( 'Endpoint URL', 'crawlertoll' ); ?></label></th>
				<td>
					<input type="url" id="ct_webhook_url" name="ct_webhook_url" class="regular-text" style="width:100%;max-width:480px;"
						placeholder="https://hooks.zapier.com/hooks/catch/…"
						value="<?php echo esc_attr( isset( $wh['url'] ) ? $wh['url'] : '' ); ?>" />
					<p class="description">
						<?php esc_html_e( 'Public https:// address that accepts POST requests. Leave empty and save to disable.', 'crawlertoll' ); ?>
					</p>
				</td>
			</tr>
			<?php if ( $configured ) : ?>
			<tr>
				<th scope="row"><label for="ct_webhook_secret"><?php esc_html_e( 'Signing secret', 'crawlertoll' ); ?></label></th>
				<td>
					<input type="text" id="ct_webhook_secret" class="regular-text" style="width:100%;max-width:480px;font-family:monospace;" readonly
						value="<?php echo esc_attr( $wh['secret'] ); ?>"
						onclick="this.select();" />
					<p class="description">
						<?php esc_html_e( 'Every delivery carries an X-CrawlerToll-Signature header (t=…,v1=…) — HMAC-SHA256 of "t.body" with this secret. Verify it and reject timestamps older than 5 minutes. Treat this secret like a password.', 'crawlertoll' ); ?>
					</p>
					<label>
						<input type="checkbox" name="ct_webhook_regenerate" value="1" />
						<?php esc_html_e( 'Regenerate the secret on save (your endpoint must be updated with the new one)', 'crawlertoll' ); ?>
					</label>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<?php submit_button( $configured ? __( 'Save webhook', 'crawlertoll' ) : __( 'Enable webhook', 'crawlertoll' ), 'primary', 'submit', false ); ?>
	</form>

	<?php if ( $configured ) : ?>
	<form method="post" action="<?php echo esc_url( add_query_arg( 'ct_tab', 'webhooks', admin_url( 'options-general.php?page=crawlertoll' ) ) ); ?>" style="display:inline-block;margin-left:8px;">
		<?php wp_nonce_field( 'crawlertoll_test_webhook', 'crawlertoll_webhook_test_nonce' ); ?>
		<?php submit_button( __( 'Send test event', 'crawlertoll' ), 'secondary', 'submit', false ); ?>
	</form>

	<h3 style="margin-top:28px;"><?php esc_html_e( 'Recent deliveries', 'crawlertoll' ); ?></h3>
	<?php if ( is_wp_error( $deliveries ) ) : ?>
		<p class="description">
			<?php
			/* translators: %s: registry error code */
			printf( esc_html__( 'Could not load deliveries: %s', 'crawlertoll' ), esc_html( $deliveries->get_error_message() ) );
			?>
		</p>
	<?php elseif ( empty( $deliveries ) ) : ?>
		<p class="description"><?php esc_html_e( 'No deliveries yet — they appear after the first unlock (or a test event).', 'crawlertoll' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:820px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Time', 'crawlertoll' ); ?></th>
					<th><?php esc_html_e( 'Event', 'crawlertoll' ); ?></th>
					<th><?php esc_html_e( 'Status', 'crawlertoll' ); ?></th>
					<th><?php esc_html_e( 'Attempts', 'crawlertoll' ); ?></th>
					<th><?php esc_html_e( 'HTTP', 'crawlertoll' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $deliveries as $d ) : ?>
				<tr>
					<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', (int) $d['created_at'] ) ); ?> UTC</td>
					<td style="font-family:monospace;font-size:11px;"><?php echo esc_html( isset( $d['event_id'] ) ? $d['event_id'] : '' ); ?></td>
					<td>
						<?php
						$status = isset( $d['status'] ) ? (string) $d['status'] : '?';
						$color  = 'delivered' === $status ? '#15803d' : ( 'exhausted' === $status || 'disabled' === $status ? '#b91c1c' : '#b45309' );
						echo '<span style="color:' . esc_attr( $color ) . ';font-weight:600;">' . esc_html( $status ) . '</span>';
						?>
					</td>
					<td><?php echo esc_html( (string) (int) $d['attempts'] ); ?></td>
					<td><?php echo esc_html( null !== $d['last_code'] ? (string) (int) $d['last_code'] : '—' ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'Failed deliveries are retried automatically for about 24 hours (8 attempts). "Exhausted" means your endpoint never answered — check the URL and send a test event.', 'crawlertoll' ); ?>
		</p>
	<?php endif; ?>
	<?php endif; ?>
</div>
