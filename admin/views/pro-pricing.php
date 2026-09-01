<?php
/**
 * Pro → Per-path pricing tab (§2.4). Longest-prefix path rules that override
 * the flat per-crawl price. Rendered by CrawlerToll_Pro_Admin::render_pricing_tab().
 *
 * @package CrawlerToll
 * @var array  $rules         Saved rules: list of { path, price_micros, currency }.
 * @var int    $default_price Flat fallback price (micros).
 * @var string $site_currency Default currency code.
 * @var array  $currencies    Allowed currency codes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Render saved rules followed by 3 blank rows for new entries — no JS needed,
// blank paths are skipped on save (clear a path + save to delete its rule).
$blank   = array( 'path' => '', 'price_micros' => '', 'currency' => $site_currency, 'meter_count' => '', 'meter_window' => '', 'tiers' => array(), 'bundle' => false, 'bundle_tiers' => array(), 'email_gate' => false );
$display = array_merge( $rules, array( $blank, $blank, $blank ) );

// Access tiers (A2): duration select choices. Stored as duration_hours
// (null = no expiry); 'custom' pairs with the days input beside it.
$ct_dur_choices = array(
	'none'   => __( 'No expiry', 'crawlertoll' ),
	'24'     => __( '24 hours', 'crawlertoll' ),
	'168'    => __( '7 days', 'crawlertoll' ),
	'720'    => __( '30 days', 'crawlertoll' ),
	'custom' => __( 'Custom days →', 'crawlertoll' ),
);
?>
<div class="ct-pro-pricing">
	<h2><?php esc_html_e( 'Per-path pricing', 'crawlertoll' ); ?></h2>
	<p class="description" style="max-width:640px;">
		<?php esc_html_e( 'Charge more for premium paths and less for low-value ones. Each rule matches by path prefix — the longest match wins, and a trailing * (e.g. /premium/*) matches everything beneath it. Paths with no rule fall back to your flat price.', 'crawlertoll' ); ?>
	</p>
	<p class="description" style="max-width:640px;">
		<?php esc_html_e( 'Free articles: let each reader open N articles on this path for free before the paywall asks for payment (per rolling window). 0 or blank = paywall from the first article. AI crawlers always pay — the allowance is for human readers only.', 'crawlertoll' ); ?>
	</p>
	<p class="description" style="max-width:640px;">
		<?php esc_html_e( 'Access tiers: offer temporary access at a lower price. Readers who pick 24 hours can return within 24 h without paying again; after that they are asked to renew. Add up to 4 price rows per rule — the reader sees one button per row. Blank price = row unused; no rows at all = the flat single price above, with no expiry.', 'crawlertoll' ); ?>
	</p>
	<p class="description" style="max-width:640px;">
		<?php esc_html_e( 'Bundle: sell one pass that covers EVERYTHING under this path (e.g. all of /reviews/* or, with /, the whole site). The reader pays once and roams every covered article for the chosen duration — your single-article prices stay on the wall beside it. Tick "Sell a bundle" and add its price rows (priced like tiers, usually higher than a single article). Articles a reader already bought separately are not refunded or credited.', 'crawlertoll' ); ?>
	</p>
	<p class="description" style="max-width:640px;">
		<?php esc_html_e( 'Email gate: let HUMAN readers unlock articles on this path free by verifying their email address — you get a reachable subscriber instead of a micropayment (see the Readers tab for the consent mode and the subscriber list). AI crawlers always pay; the email gate never applies to them.', 'crawlertoll' ); ?>
	</p>
	<p class="description">
		<?php
		printf(
			/* translators: 1: price in micros, 2: currency code. */
			esc_html__( 'Flat fallback price: %1$s micros %2$s.', 'crawlertoll' ),
			esc_html( number_format_i18n( $default_price ) ),
			esc_html( $site_currency )
		);
		?>
	</p>

	<form method="post" action="<?php echo esc_url( add_query_arg( 'ct_tab', 'pricing', admin_url( 'options-general.php?page=crawlertoll' ) ) ); ?>">
		<?php wp_nonce_field( 'crawlertoll_save_pricing', 'crawlertoll_pricing_nonce' ); ?>
		<table class="widefat striped" style="max-width:900px;margin-top:12px;">
			<thead>
				<tr>
					<th style="width:22%;"><?php esc_html_e( 'Path prefix', 'crawlertoll' ); ?></th>
					<th><?php esc_html_e( 'Price (micros)', 'crawlertoll' ); ?></th>
					<th><?php esc_html_e( 'Currency', 'crawlertoll' ); ?></th>
					<th><?php esc_html_e( 'Free articles', 'crawlertoll' ); ?></th>
					<th><?php esc_html_e( 'Window (days)', 'crawlertoll' ); ?></th>
					<th style="width:30%;"><?php esc_html_e( 'Access tiers (price micros → access duration)', 'crawlertoll' ); ?></th>
					<th style="width:26%;"><?php esc_html_e( 'Bundle (whole-path pass)', 'crawlertoll' ); ?></th>
					<th><?php esc_html_e( 'Email gate', 'crawlertoll' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $display as $i => $rule ) : ?>
					<?php
					$r_path  = isset( $rule['path'] ) ? (string) $rule['path'] : '';
					$r_price = ( isset( $rule['price_micros'] ) && '' !== $rule['price_micros'] ) ? (string) (int) $rule['price_micros'] : '';
					$r_curr  = isset( $rule['currency'] ) ? (string) $rule['currency'] : $site_currency;
					$r_mcount = ( isset( $rule['meter_count'] ) && '' !== $rule['meter_count'] ) ? (string) (int) $rule['meter_count'] : '';
					$r_mwin   = ( isset( $rule['meter_window'] ) && '' !== $rule['meter_window'] ) ? (string) (int) $rule['meter_window'] : '';
					?>
					<tr>
						<td><input type="text" name="ct_price_path[]" value="<?php echo esc_attr( $r_path ); ?>" placeholder="/premium/*" style="width:100%;" /></td>
						<td><input type="number" min="0" step="1" name="ct_price_micros[]" value="<?php echo esc_attr( $r_price ); ?>" placeholder="<?php echo esc_attr( (string) $default_price ); ?>" /></td>
						<td>
							<select name="ct_price_currency[]">
								<?php foreach ( $currencies as $code ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $r_curr, $code ); ?>><?php echo esc_html( $code ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><input type="number" min="0" max="50" step="1" name="ct_meter_count[]" value="<?php echo esc_attr( $r_mcount ); ?>" placeholder="0" /></td>
						<td><input type="number" min="1" max="365" step="1" name="ct_meter_window[]" value="<?php echo esc_attr( $r_mwin ); ?>" placeholder="30" /></td>
						<td>
							<?php
							$r_tiers = ( isset( $rule['tiers'] ) && is_array( $rule['tiers'] ) ) ? $rule['tiers'] : array();
							for ( $j = 0; $j < 4; $j++ ) :
								$t        = isset( $r_tiers[ $j ] ) && is_array( $r_tiers[ $j ] ) ? $r_tiers[ $j ] : null;
								$t_price  = $t && isset( $t['price_micros'] ) ? (string) (int) $t['price_micros'] : '';
								$t_durh   = $t && array_key_exists( 'duration_hours', $t ) ? $t['duration_hours'] : null;
								if ( null === $t_durh ) {
									$t_sel = 'none';
									$t_cus = '';
								} elseif ( in_array( (int) $t_durh, array( 24, 168, 720 ), true ) ) {
									$t_sel = (string) (int) $t_durh;
									$t_cus = '';
								} else {
									$t_sel = 'custom';
									$t_cus = (string) max( 1, (int) round( (int) $t_durh / 24 ) );
								}
								?>
								<div style="display:flex;gap:4px;align-items:center;margin-bottom:3px;">
									<input type="number" min="0" step="1" name="ct_tier_price[<?php echo esc_attr( (string) $i ); ?>][]" value="<?php echo esc_attr( $t_price ); ?>" placeholder="micros" style="width:80px;" />
									<select name="ct_tier_dur[<?php echo esc_attr( (string) $i ); ?>][]">
										<?php foreach ( $ct_dur_choices as $val => $label ) : ?>
											<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $t_sel, $val ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<input type="number" min="1" max="365" step="1" name="ct_tier_custom[<?php echo esc_attr( (string) $i ); ?>][]" value="<?php echo esc_attr( $t_cus ); ?>" placeholder="days" style="width:60px;" title="<?php esc_attr_e( 'Custom duration in days (only used with “Custom days”)', 'crawlertoll' ); ?>" />
								</div>
							<?php endfor; ?>
						</td>
						<td>
							<label style="display:block;margin-bottom:4px;">
								<input type="checkbox" name="ct_bundle[<?php echo esc_attr( (string) $i ); ?>]" value="1" <?php checked( ! empty( $rule['bundle'] ) ); ?> />
								<?php esc_html_e( 'Sell a bundle', 'crawlertoll' ); ?>
							</label>
							<?php
							$r_btiers = ( isset( $rule['bundle_tiers'] ) && is_array( $rule['bundle_tiers'] ) ) ? $rule['bundle_tiers'] : array();
							for ( $j = 0; $j < 4; $j++ ) :
								$b       = isset( $r_btiers[ $j ] ) && is_array( $r_btiers[ $j ] ) ? $r_btiers[ $j ] : null;
								$b_price = $b && isset( $b['price_micros'] ) ? (string) (int) $b['price_micros'] : '';
								$b_durh  = $b && array_key_exists( 'duration_hours', $b ) ? $b['duration_hours'] : null;
								if ( null === $b_durh ) {
									$b_sel = 'none';
									$b_cus = '';
								} elseif ( in_array( (int) $b_durh, array( 24, 168, 720 ), true ) ) {
									$b_sel = (string) (int) $b_durh;
									$b_cus = '';
								} else {
									$b_sel = 'custom';
									$b_cus = (string) max( 1, (int) round( (int) $b_durh / 24 ) );
								}
								?>
								<div style="display:flex;gap:4px;align-items:center;margin-bottom:3px;">
									<input type="number" min="0" step="1" name="ct_bundle_price[<?php echo esc_attr( (string) $i ); ?>][]" value="<?php echo esc_attr( $b_price ); ?>" placeholder="micros" style="width:80px;" />
									<select name="ct_bundle_dur[<?php echo esc_attr( (string) $i ); ?>][]">
										<?php foreach ( $ct_dur_choices as $val => $label ) : ?>
											<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $b_sel, $val ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
									<input type="number" min="1" max="365" step="1" name="ct_bundle_custom[<?php echo esc_attr( (string) $i ); ?>][]" value="<?php echo esc_attr( $b_cus ); ?>" placeholder="days" style="width:60px;" title="<?php esc_attr_e( 'Custom duration in days (only used with “Custom days”)', 'crawlertoll' ); ?>" />
								</div>
							<?php endfor; ?>
						</td>
						<td>
							<label style="display:block;">
								<input type="checkbox" name="ct_email_gate[<?php echo esc_attr( (string) $i ); ?>]" value="1" <?php checked( ! empty( $rule['email_gate'] ) ); ?> />
								<?php esc_html_e( 'Read free with email', 'crawlertoll' ); ?>
							</label>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save pricing', 'crawlertoll' ); ?></button></p>
	</form>
</div>
