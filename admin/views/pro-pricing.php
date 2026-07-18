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
$blank   = array( 'path' => '', 'price_micros' => '', 'currency' => $site_currency );
$display = array_merge( $rules, array( $blank, $blank, $blank ) );
?>
<div class="ct-pro-pricing">
	<h2><?php esc_html_e( 'Per-path pricing', 'crawlertoll' ); ?></h2>
	<p class="description" style="max-width:640px;">
		<?php esc_html_e( 'Charge more for premium paths and less for low-value ones. Each rule matches by path prefix — the longest match wins, and a trailing * (e.g. /premium/*) matches everything beneath it. Paths with no rule fall back to your flat price.', 'crawlertoll' ); ?>
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
		<table class="widefat striped" style="max-width:720px;margin-top:12px;">
			<thead>
				<tr>
					<th style="width:50%;"><?php esc_html_e( 'Path prefix', 'crawlertoll' ); ?></th>
					<th><?php esc_html_e( 'Price (micros)', 'crawlertoll' ); ?></th>
					<th><?php esc_html_e( 'Currency', 'crawlertoll' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $display as $rule ) : ?>
					<?php
					$r_path  = isset( $rule['path'] ) ? (string) $rule['path'] : '';
					$r_price = ( isset( $rule['price_micros'] ) && '' !== $rule['price_micros'] ) ? (string) (int) $rule['price_micros'] : '';
					$r_curr  = isset( $rule['currency'] ) ? (string) $rule['currency'] : $site_currency;
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
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save pricing', 'crawlertoll' ); ?></button></p>
	</form>
</div>
