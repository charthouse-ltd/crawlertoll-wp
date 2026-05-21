<?php
/**
 * CrawlerToll settings page — modern dashboard UI.
 *
 * @var array        $settings        Current settings.
 * @var array        $rails           label → display name for the rail dropdown.
 * @var array        $bots            Bot catalogue entries (name, operator, ua_match, category).
 * @var array        $category_counts Counts per category.
 * @var array        $policy_data     Parsed RSL policy.
 * @var int          $active_bots     Number of User-agent entries in the active policy.
 * @var int          $active_groups   Number of agent groups in the active policy.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_enabled = ! empty( $settings['enabled'] );
$price_dollars = number_format( (int) $settings['price_micros'] / 1000000, 4 );
$site_url = home_url();
?>

<div class="ct-header">
	<h1>
		<?php esc_html_e( 'CrawlerToll', 'crawlertoll' ); ?>
		<span class="ct-badge">v<?php echo esc_html( CRAWLERTOLL_VERSION ); ?></span>
	</h1>
	<p>
		<?php esc_html_e( 'AI-crawler enforcement for WordPress. Detects 30+ AI crawlers, applies your RSL 1.0 policy, and issues HTTP 402 with a structured payment offer. Vendor-neutral — works with TollBit, Skyfire, x402, Cloudflare Pay Per Crawl, and Stripe ACP.', 'crawlertoll' ); ?>
	</p>
</div>

<form method="post" action="options.php">
	<?php settings_fields( 'crawlertoll' ); ?>

	<!-- Status cards -->
	<div class="ct-status-bar">
		<div class="ct-stat-card">
			<div class="ct-stat-icon <?php echo $is_enabled ? 'green' : 'amber'; ?>">
				<span class="dashicons <?php echo $is_enabled ? 'dashicons-shield' : 'dashicons-shield-alt'; ?>"></span>
			</div>
			<div class="ct-stat-value"><?php echo $is_enabled ? esc_html__( 'Active', 'crawlertoll' ) : esc_html__( 'Paused', 'crawlertoll' ); ?></div>
			<div class="ct-stat-label"><?php esc_html_e( 'Enforcement', 'crawlertoll' ); ?></div>
		</div>
		<div class="ct-stat-card">
			<div class="ct-stat-icon purple">
				<span class="dashicons dashicons-networking"></span>
			</div>
			<div class="ct-stat-value"><?php echo count( $bots ); ?></div>
			<div class="ct-stat-label"><?php esc_html_e( 'AI Crawlers Detected', 'crawlertoll' ); ?></div>
		</div>
		<div class="ct-stat-card">
			<div class="ct-stat-icon blue">
				<span class="dashicons dashicons-admin-generic"></span>
			</div>
			<div class="ct-stat-value"><?php echo esc_html( $active_groups ); ?></div>
			<div class="ct-stat-label"><?php esc_html_e( 'Policy Groups', 'crawlertoll' ); ?></div>
		</div>
		<div class="ct-stat-card">
			<div class="ct-stat-icon <?php echo (int) $settings['price_micros'] > 0 ? 'green' : 'amber'; ?>">
				<span class="dashicons dashicons-money"></span>
			</div>
			<div class="ct-stat-value">$<?php echo esc_html( $price_dollars ); ?></div>
			<div class="ct-stat-label"><?php esc_html_e( 'Per Crawl', 'crawlertoll' ); ?></div>
		</div>
	</div>

	<!-- Enforcement toggle -->
	<div class="ct-card">
		<h2>
			<span class="dashicons dashicons-shield"></span>
			<?php esc_html_e( 'Enforcement', 'crawlertoll' ); ?>
		</h2>
		<p class="ct-card-desc"><?php esc_html_e( 'Turn AI-crawler enforcement on or off without uninstalling the plugin.', 'crawlertoll' ); ?></p>
		<div class="ct-toggle-row">
			<label class="ct-toggle">
				<input
					type="checkbox"
					name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[enabled]"
					value="1"
					<?php checked( $is_enabled ); ?>
				/>
				<span class="ct-toggle-slider"></span>
			</label>
			<span class="ct-toggle-label">
				<?php echo $is_enabled ? esc_html__( 'CrawlerToll is active — AI crawlers are being detected and enforced.', 'crawlertoll' ) : esc_html__( 'CrawlerToll is paused — all traffic passes through normally.', 'crawlertoll' ); ?>
			</span>
		</div>
	</div>

	<!-- Payment offer -->
	<div class="ct-card">
		<h2>
			<span class="dashicons dashicons-money"></span>
			<?php esc_html_e( 'Payment offer', 'crawlertoll' ); ?>
		</h2>
		<p class="ct-card-desc"><?php esc_html_e( 'Returned in the HTTP 402 response when a crawler hits a disallowed path with a Compensation directive.', 'crawlertoll' ); ?></p>
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
						name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[price_micros]"
						value="<?php echo esc_attr( (int) $settings['price_micros'] ); ?>"
					/>
					<p class="description">
						<?php
						printf(
							/* translators: %s: dollar amount */
							esc_html__( 'Price in micros (1/1,000,000 of the currency unit). Current: %s per crawl.', 'crawlertoll' ),
							'<strong>$' . esc_html( $price_dollars ) . '</strong>'
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="crawlertoll-currency"><?php esc_html_e( 'Currency', 'crawlertoll' ); ?></label>
				</th>
				<td>
					<select id="crawlertoll-currency" name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[currency]">
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
					<select id="crawlertoll-rail" name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[rail]">
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
					<p class="description"><?php esc_html_e( 'Surfaced in the 402 Link header as rel="payment". Optional for x402 (wallet-native).', 'crawlertoll' ); ?></p>
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
						placeholder="<?php echo esc_url( home_url( '/ai-terms' ) ); ?>"
					/>
				</td>
			</tr>
		</table>
	</div>

	<!-- RSL Policy -->
	<div class="ct-card">
		<h2>
			<span class="dashicons dashicons-editor-code"></span>
			<?php esc_html_e( 'RSL 1.0 Policy', 'crawlertoll' ); ?>
		</h2>
		<p class="ct-card-desc">
			<?php
			printf(
				/* translators: %s: link to RSL spec */
				esc_html__( 'Your robots.txt policy extended with RSL 1.0 directives. Appended to /robots.txt and applied per-request. See the %s for the full directive vocabulary.', 'crawlertoll' ),
				'<a href="https://rslstandard.org/" target="_blank" rel="noopener">RSL 1.0 spec</a>'
			);
			?>
		</p>
		<textarea
			id="crawlertoll-policy"
			name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[policy]"
			rows="18"
			cols="80"
			class="large-text code"
			spellcheck="false"
		><?php echo esc_textarea( $settings['policy'] ); ?></textarea>
	</div>

	<!-- Curl tester -->
	<div class="ct-card">
		<h2>
			<span class="dashicons dashicons-terminal"></span>
			<?php esc_html_e( 'Live test', 'crawlertoll' ); ?>
		</h2>
		<p class="ct-card-desc"><?php esc_html_e( 'Simulate an AI crawler request against your current policy. See exactly what headers and status code your site returns.', 'crawlertoll' ); ?></p>

		<div class="ct-curl-tester">
			<div class="ct-curl-input-row">
				<select id="ct-curl-ua">
					<option value="">— Select a crawler —</option>
					<optgroup label="OpenAI">
						<option value="GPTBot/1.2">GPTBot (training)</option>
						<option value="ChatGPT-User/1.0">ChatGPT-User (inference)</option>
						<option value="OAI-SearchBot/1.0">OAI-SearchBot (search)</option>
					</optgroup>
					<optgroup label="Anthropic">
						<option value="ClaudeBot/1.0">ClaudeBot (training)</option>
						<option value="Claude-User/1.0">Claude-User (inference)</option>
						<option value="Claude-SearchBot/1.0">Claude-SearchBot (search)</option>
					</optgroup>
					<optgroup label="Google">
						<option value="Google-Extended">Google-Extended (training)</option>
						<option value="GoogleOther">GoogleOther</option>
					</optgroup>
					<optgroup label="Other">
						<option value="PerplexityBot/1.0">PerplexityBot</option>
						<option value="Applebot-Extended">Applebot-Extended</option>
						<option value="Meta-ExternalAgent/1.0">Meta-ExternalAgent</option>
						<option value="Bytespider">Bytespider (ByteDance)</option>
						<option value="CCBot/2.0">CCBot (Common Crawl)</option>
						<option value="cohere-ai/1.0">Cohere</option>
						<option value="MistralAI-User/1.0">MistralAI-User</option>
						<option value="Mozilla/5.0 (compatible)">Regular browser</option>
					</optgroup>
				</select>
				<input type="text" id="ct-curl-path" placeholder="/some/post/" value="/" />
				<input type="hidden" id="ct-curl-site" value="<?php echo esc_url( $site_url ); ?>" />
				<button type="button" id="ct-curl-test-btn" class="ct-curl-btn">Test</button>
			</div>
			<div id="ct-curl-output" class="ct-curl-output"></div>
		</div>
	</div>

	<!-- Bot catalogue -->
	<div class="ct-card">
		<button type="button" class="ct-collapse-toggle">
			<span class="dashicons dashicons-arrow-right-alt2"></span>
			<?php esc_html_e( 'AI Crawler Catalogue', 'crawlertoll' ); ?>
			<span style="font-weight:400;color:var(--ct-text-muted);margin-left:4px;">
				(<?php echo esc_html( count( $bots ) ); ?> crawlers)
			</span>
		</button>
		<div class="ct-collapse-content" style="max-height:0;">
			<div style="margin-top:16px;">
				<div class="ct-legend">
					<span><span class="ct-bot-dot training"></span> Training</span>
					<span><span class="ct-bot-dot inference"></span> Inference</span>
					<span><span class="ct-bot-dot search"></span> Search</span>
					<span><span class="ct-bot-dot agent"></span> Agent</span>
					<span><span class="ct-bot-dot scraper"></span> Scraper</span>
				</div>
				<input
					type="text"
					id="ct-bot-filter"
					placeholder="<?php esc_attr_e( 'Filter crawlers…', 'crawlertoll' ); ?>"
					style="width:100%;margin-bottom:12px;"
				/>
				<div class="ct-bot-grid">
					<?php foreach ( $bots as $bot ) : ?>
						<div class="ct-bot-chip">
							<span class="ct-bot-dot <?php echo esc_attr( $bot['category'] ); ?>"></span>
							<span class="ct-bot-name"><?php echo esc_html( $bot['name'] ); ?></span>
							<span class="ct-bot-op"><?php echo esc_html( $bot['operator'] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<script type="application/json" id="ct-bot-data"><?php echo wp_json_encode( $bots ); ?></script>
	</div>

	<!-- Discovery endpoints -->
	<div class="ct-card">
		<h2>
			<span class="dashicons dashicons-rest-api"></span>
			<?php esc_html_e( 'Discovery endpoints', 'crawlertoll' ); ?>
		</h2>
		<p class="ct-card-desc"><?php esc_html_e( 'These endpoints are automatically served by the plugin and are how AI crawlers discover your licensing terms.', 'crawlertoll' ); ?></p>
		<div class="ct-endpoint-row">
			<code><?php echo esc_url( $site_url . '/robots.txt' ); ?></code>
			<span class="ct-endpoint-status active"><?php esc_html_e( 'ACTIVE', 'crawlertoll' ); ?></span>
			<span style="font-size:12px;color:var(--ct-text-muted);"><?php esc_html_e( 'RSL policy auto-appended via WordPress filter', 'crawlertoll' ); ?></span>
		</div>
		<div class="ct-endpoint-row">
			<code><?php echo esc_url( $site_url . '/.well-known/context-license.json' ); ?></code>
			<span class="ct-endpoint-status active"><?php esc_html_e( 'ACTIVE', 'crawlertoll' ); ?></span>
			<span style="font-size:12px;color:var(--ct-text-muted);"><?php esc_html_e( 'Built from your settings + site info. CC0 schema.', 'crawlertoll' ); ?></span>
		</div>
	</div>

	<!-- Submit -->
	<div class="ct-submit-wrap">
		<?php submit_button( __( 'Save Changes', 'crawlertoll' ), 'primary', 'submit', false ); ?>
	</div>
</form>

