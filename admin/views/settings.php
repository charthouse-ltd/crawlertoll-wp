<?php
/**
 * CrawlerToll settings page view.
 *
 * @var array $settings Current settings.
 * @var array $rails    label → display name for the rail dropdown.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'CrawlerToll', 'crawlertoll' ); ?></h1>
	<p class="description">
		<?php
		printf(
			/* translators: 1: opening anchor, 2: closing anchor */
			esc_html__( 'AI-crawler enforcement for WordPress. Detects %1$s30+ AI crawlers%2$s, applies your RSL 1.0 policy, and issues HTTP 402 with a structured payment offer.', 'crawlertoll' ),
			'<a href="https://crawlertoll.com" target="_blank" rel="noopener">',
			'</a>'
		);
		?>
	</p>

	<form method="post" action="options.php">
		<?php settings_fields( 'crawlertoll' ); ?>

		<h2><?php esc_html_e( 'Enforcement', 'crawlertoll' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable CrawlerToll', 'crawlertoll' ); ?></th>
				<td>
					<label>
						<input
							type="checkbox"
							name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[enabled]"
							value="1"
							<?php checked( ! empty( $settings['enabled'] ) ); ?>
						/>
						<?php esc_html_e( 'Detect AI crawlers and enforce the policy below.', 'crawlertoll' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Payment offer', 'crawlertoll' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Used when the policy declares a Compensation directive for a crawler that hits a Disallowed path. Returned in the HTTP 402 response.', 'crawlertoll' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="crawlertoll-price-micros"><?php esc_html_e( 'Price (micros)', 'crawlertoll' ); ?></label>
				</th>
				<td>
					<input
						id="crawlertoll-price-micros"
						type="number"
						min="0"
						step="1"
						class="regular-text"
						name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[price_micros]"
						value="<?php echo esc_attr( (int) $settings['price_micros'] ); ?>"
					/>
					<p class="description"><?php esc_html_e( 'Price in micros (1/1,000,000 of the currency unit). Example: 5000 = $0.005 per crawl.', 'crawlertoll' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="crawlertoll-currency"><?php esc_html_e( 'Currency', 'crawlertoll' ); ?></label>
				</th>
				<td>
					<select
						id="crawlertoll-currency"
						name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[currency]"
					>
						<?php foreach ( array( 'USD', 'USDC', 'EUR', 'GBP' ) as $cur ) : ?>
							<option value="<?php echo esc_attr( $cur ); ?>" <?php selected( $settings['currency'], $cur ); ?>>
								<?php echo esc_html( $cur ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="crawlertoll-rail"><?php esc_html_e( 'Settlement rail', 'crawlertoll' ); ?></label>
				</th>
				<td>
					<select
						id="crawlertoll-rail"
						name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[rail]"
					>
						<?php foreach ( $rails as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['rail'], $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="crawlertoll-payment-url"><?php esc_html_e( 'Payment URL', 'crawlertoll' ); ?></label>
				</th>
				<td>
					<input
						id="crawlertoll-payment-url"
						type="url"
						class="regular-text"
						name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[payment_url]"
						value="<?php echo esc_attr( $settings['payment_url'] ); ?>"
						placeholder="https://pay.example.com/abc"
					/>
					<p class="description"><?php esc_html_e( 'Surfaced in the 402 Link header as rel="payment". Optional for x402 (which is wallet-native).', 'crawlertoll' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="crawlertoll-terms-url"><?php esc_html_e( 'Terms-of-use URL', 'crawlertoll' ); ?></label>
				</th>
				<td>
					<input
						id="crawlertoll-terms-url"
						type="url"
						class="regular-text"
						name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[terms_url]"
						value="<?php echo esc_attr( $settings['terms_url'] ); ?>"
						placeholder="<?php echo esc_attr( home_url( '/ai-terms' ) ); ?>"
					/>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'RSL 1.0 policy', 'crawlertoll' ); ?></h2>
		<p class="description">
			<?php
			printf(
				/* translators: %s: URL to RSL spec */
				esc_html__( 'The policy appended to /robots.txt and applied per-request. See the %s for the directive vocabulary.', 'crawlertoll' ),
				'<a href="https://rslstandard.org/" target="_blank" rel="noopener">RSL 1.0 spec</a>'
			);
			?>
		</p>
		<textarea
			id="crawlertoll-policy"
			name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[policy]"
			rows="20"
			cols="80"
			class="large-text code"
		><?php echo esc_textarea( $settings['policy'] ); ?></textarea>

		<h2><?php esc_html_e( 'Discovery endpoints', 'crawlertoll' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'robots.txt', 'crawlertoll' ); ?></th>
				<td>
					<code><?php echo esc_url( home_url( '/robots.txt' ) ); ?></code>
					<?php /* translators: appears as helper text */ ?>
					<p class="description"><?php esc_html_e( 'The RSL policy above is auto-appended to your existing robots.txt via WordPress\'s standard filter.', 'crawlertoll' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'context-license.json', 'crawlertoll' ); ?></th>
				<td>
					<code><?php echo esc_url( home_url( '/.well-known/context-license.json' ) ); ?></code>
					<p class="description"><?php esc_html_e( 'Served by the plugin via a rewrite rule. Built from your settings + site info. CC0 schema, Apache 2.0 reference parsers.', 'crawlertoll' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
