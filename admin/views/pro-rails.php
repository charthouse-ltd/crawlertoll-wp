<?php
/**
 * CrawlerToll Pro — Multi-rail routing view (§2.5).
 *
 * @var string $default_rail  Site default rail key.
 * @var array  $overrides     bot_name => rail key.
 * @var array  $rail_options  rail key => label.
 * @var array  $bots          Bot catalogue entries (name, operator, category).
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ct_default_label = isset( $rail_options[ $default_rail ] ) ? $rail_options[ $default_rail ] : $default_rail;
?>

<div class="ct-card">
	<h2>
		<span class="dashicons dashicons-randomize"></span>
		<?php esc_html_e( 'Multi-rail routing', 'crawlertoll' ); ?>
	</h2>
	<p class="ct-card-desc">
		<?php
		printf(
			/* translators: %s: the site default rail name. */
			esc_html__( 'Route specific AI crawlers to specific settlement rails. Any crawler left on "Use site default" is billed via %s.', 'crawlertoll' ),
			'<strong>' . esc_html( $ct_default_label ) . '</strong>'
		);
		?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=crawlertoll&ct_tab=rails' ) ); ?>">
		<?php wp_nonce_field( 'crawlertoll_save_rails', 'crawlertoll_rails_nonce' ); ?>

		<table style="width:100%;border-collapse:collapse;font-size:13px;">
			<thead>
				<tr style="background:#f8fafc;border-bottom:2px solid var(--ct-border);text-align:left;">
					<th style="padding:10px 12px;font-weight:600;"><?php esc_html_e( 'Crawler', 'crawlertoll' ); ?></th>
					<th style="padding:10px 12px;font-weight:600;"><?php esc_html_e( 'Settlement rail', 'crawlertoll' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $bots as $ct_bot ) :
					$ct_name    = isset( $ct_bot['name'] ) ? $ct_bot['name'] : '';
					$ct_current = isset( $overrides[ $ct_name ] ) ? $overrides[ $ct_name ] : '';
					if ( '' === $ct_name ) {
						continue;
					}
				?>
					<tr style="border-bottom:1px solid #f1f5f9;">
						<td style="padding:8px 12px;">
							<span style="font-weight:600;"><?php echo esc_html( $ct_name ); ?></span>
							<span style="font-size:11px;color:var(--ct-text-muted);margin-left:6px;"><?php echo esc_html( isset( $ct_bot['operator'] ) ? $ct_bot['operator'] : '' ); ?></span>
						</td>
						<td style="padding:8px 12px;">
							<select name="ct_rail[<?php echo esc_attr( $ct_name ); ?>]" style="border:1px solid var(--ct-border);border-radius:6px;padding:6px 10px;font-size:13px;min-width:280px;">
								<option value=""><?php esc_html_e( 'Use site default', 'crawlertoll' ); ?></option>
								<?php foreach ( $rail_options as $ct_value => $ct_label ) : ?>
									<option value="<?php echo esc_attr( $ct_value ); ?>" <?php selected( $ct_current, $ct_value ); ?>>
										<?php echo esc_html( $ct_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="ct-submit-wrap" style="margin-top:16px;">
			<?php submit_button( __( 'Save rail routing', 'crawlertoll' ), 'primary', 'submit', false ); ?>
		</div>
	</form>
</div>
