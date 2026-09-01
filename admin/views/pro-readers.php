<?php
/**
 * Pro → Readers tab (A5, spec §5.5): email-gate consent mode + the subscriber
 * list collected by "read free with your email". Rendered by
 * CrawlerToll_Pro_Admin::render_readers_tab() — classic PHP (no pro-app div).
 *
 * @package CrawlerToll
 * @var string $mode        "split" (marketing optional) | "pur" (consent-or-pay).
 * @var array  $subscribers Subscriber rows (newest first).
 * @var string $export_url  Nonce'd CSV export URL (marketing-consented only).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ct-pro-readers">
	<h2><?php esc_html_e( 'Readers — email access', 'crawlertoll' ); ?></h2>
	<p class="description" style="max-width:680px;">
		<?php esc_html_e( 'When a path rule flags "Email gate" (Pricing tab), human readers can unlock those articles free by verifying an email address — you get a reachable reader instead of a micropayment. AI crawlers always pay; this gate is for humans only. Enable it per section under Pricing → the rule’s "Email gate" checkbox.', 'crawlertoll' ); ?>
	</p>

	<div class="ct-card" style="max-width:680px;margin-top:16px;">
		<h3 style="margin-top:0;"><?php esc_html_e( 'Consent mode', 'crawlertoll' ); ?></h3>
		<form method="post" action="<?php echo esc_url( add_query_arg( 'ct_tab', 'readers', admin_url( 'options-general.php?page=crawlertoll' ) ) ); ?>">
			<?php wp_nonce_field( 'crawlertoll_save_readers', 'crawlertoll_readers_nonce' ); ?>
			<label style="display:block;margin-bottom:8px;">
				<input type="radio" name="ct_email_gate_mode" value="split" <?php checked( $mode, 'split' ); ?> />
				<strong><?php esc_html_e( 'Email only (recommended)', 'crawlertoll' ); ?></strong><br />
				<span class="description" style="margin-left:24px;">
					<?php esc_html_e( 'The reader must agree to receive their access link. A separate, unticked newsletter checkbox is optional — the cleanest consent posture.', 'crawlertoll' ); ?>
				</span>
			</label>
			<label style="display:block;margin-bottom:8px;">
				<input type="radio" name="ct_email_gate_mode" value="pur" <?php checked( $mode, 'pur' ); ?> />
				<strong><?php esc_html_e( 'Consent-or-pay', 'crawlertoll' ); ?></strong><br />
				<span class="description" style="margin-left:24px;">
					<?php esc_html_e( 'The free email unlock requires the newsletter consent; readers who decline can still pay.', 'crawlertoll' ); ?>
				</span>
			</label>
			<?php if ( 'pur' === $mode ) : ?>
				<div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:12px;margin:8px 0 12px 24px;">
					<p style="margin:0;color:#92400e;font-size:12px;">
						<?php esc_html_e( 'Regulatory warning: "consent-or-pay" for marketing emails is under active scrutiny in the EU/EEA (EDPB) and may not count as freely given consent in your jurisdiction. Get legal advice before relying on it. The CSV export below preserves exactly what each reader agreed to.', 'crawlertoll' ); ?>
					</p>
				</div>
			<?php endif; ?>
			<p style="margin-left:24px;"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save consent mode', 'crawlertoll' ); ?></button></p>
		</form>
	</div>

	<div class="ct-card" style="max-width:900px;margin-top:16px;">
		<h3 style="margin-top:0;">
			<?php esc_html_e( 'Subscribers', 'crawlertoll' ); ?>
			<a href="<?php echo esc_url( $export_url ); ?>" class="button" style="float:right;">
				<?php esc_html_e( 'Export marketing CSV', 'crawlertoll' ); ?>
			</a>
		</h3>
		<p class="description">
			<?php esc_html_e( 'The CSV contains ONLY readers who ticked the newsletter box — with the exact text they agreed to and the timestamp. That is your consent audit trail; treat it as such. "Verified" means the reader actually clicked their access link.', 'crawlertoll' ); ?>
		</p>
		<?php if ( empty( $subscribers ) ) : ?>
			<p style="color:#64748b;"><?php esc_html_e( 'No readers yet. Once an email-gated article gets its first unlock request, the address appears here.', 'crawlertoll' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="margin-top:8px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Email', 'crawlertoll' ); ?></th>
						<th><?php esc_html_e( 'Verified', 'crawlertoll' ); ?></th>
						<th><?php esc_html_e( 'Newsletter consent', 'crawlertoll' ); ?></th>
						<th><?php esc_html_e( 'Consent given at', 'crawlertoll' ); ?></th>
						<th><?php esc_html_e( 'First article', 'crawlertoll' ); ?></th>
						<th><?php esc_html_e( 'Requested', 'crawlertoll' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $subscribers as $sub ) : ?>
						<tr>
							<td><?php echo esc_html( $sub['email'] ); ?></td>
							<td>
								<?php if ( ! empty( $sub['verified'] ) ) : ?>
									<span style="color:#059669;font-weight:600;" title="<?php echo esc_attr( (string) $sub['verified_at'] ); ?>"><?php esc_html_e( 'Yes', 'crawlertoll' ); ?></span>
								<?php else : ?>
									<span style="color:#94a3b8;"><?php esc_html_e( 'Not yet', 'crawlertoll' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! empty( $sub['consent_marketing'] ) ) : ?>
									<span style="color:#059669;font-weight:600;" title="<?php echo esc_attr( (string) $sub['consent_text'] ); ?>"><?php esc_html_e( 'Yes', 'crawlertoll' ); ?></span>
								<?php else : ?>
									<span style="color:#94a3b8;"><?php esc_html_e( 'No', 'crawlertoll' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) $sub['consent_at'] ); ?></td>
							<td><code style="font-size:11px;"><?php echo esc_html( (string) $sub['content_id'] ); ?></code></td>
							<td><?php echo esc_html( (string) $sub['created_at'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>
