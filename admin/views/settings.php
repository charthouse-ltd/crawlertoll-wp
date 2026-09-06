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
 * @var array        $recent_unlocks  Latest unlock receipts from the registry (D3).
 * @var string|null  $recent_unlocks_error Registry read-back error, if any.
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

<form method="post" action="options.php">
	<?php settings_fields( 'crawlertoll' ); ?>

	<!-- Status cards. React (free-app) mounts into #crawlertoll-free-app and
	     replaces the server-rendered cards below with the live version. If the
	     bundle is absent (un-built tree, or the wp.org build before assets
	     ship), these cards remain as the graceful fallback. -->
	<div id="crawlertoll-free-app">
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
				<div class="ct-stat-label"><?php esc_html_e( 'AI Crawlers Recognised', 'crawlertoll' ); ?></div>
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
				<?php echo $is_enabled ? esc_html__( 'CrawlerToll is active — declared AI crawlers are recognised and charged; premium posts are sealed.', 'crawlertoll' ) : esc_html__( 'CrawlerToll is paused — all traffic passes through normally.', 'crawlertoll' ); ?>
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
					<label for="crawlertoll-x402-pay-to"><?php esc_html_e( 'USDC payout address', 'crawlertoll' ); ?></label>
				</th>
				<td>
					<input
						id="crawlertoll-x402-pay-to"
						type="text"
						class="regular-text"
						placeholder="0x…"
						name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[x402_pay_to]"
						value="<?php echo esc_attr( isset( $settings['x402_pay_to'] ) ? (string) $settings['x402_pay_to'] : '' ); ?>"
					/>
					<p class="description">
						<?php esc_html_e( 'Your wallet address on Base. USDC from readers and AI agents is paid directly to this address — CrawlerToll never touches the money. Leave empty to disable the USDC rail.', 'crawlertoll' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="crawlertoll-stripe-pk"><?php esc_html_e( 'Stripe publishable key', 'crawlertoll' ); ?></label>
				</th>
				<td>
					<input
						id="crawlertoll-stripe-pk"
						type="text"
						class="regular-text code"
						placeholder="pk_live_…"
						autocomplete="off"
						name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[stripe_publishable_key]"
						value="<?php echo esc_attr( isset( $settings['stripe_publishable_key'] ) ? (string) $settings['stripe_publishable_key'] : '' ); ?>"
					/>
					<p class="description">
						<?php esc_html_e( 'Cards, Apple Pay and Google Pay are charged on YOUR Stripe account and paid out to you by Stripe. CrawlerToll never touches the money and takes no cut. Stripe Dashboard → Developers → API keys.', 'crawlertoll' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="crawlertoll-stripe-sk"><?php esc_html_e( 'Stripe secret key', 'crawlertoll' ); ?></label>
				</th>
				<td>
					<?php $ct_sk_masked = CrawlerToll_Stripe::masked_secret( isset( $settings['stripe_secret_key'] ) ? (string) $settings['stripe_secret_key'] : '' ); ?>
					<input
						id="crawlertoll-stripe-sk"
						type="password"
						class="regular-text code"
						placeholder="<?php echo esc_attr( '' !== $ct_sk_masked ? $ct_sk_masked : 'rk_live_…' ); ?>"
						autocomplete="new-password"
						name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[stripe_secret_key]"
						value=""
					/>
					<?php if ( '' !== $ct_sk_masked ) : ?>
						<label style="margin-left:8px;">
							<input type="checkbox" name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[stripe_secret_key_clear]" value="1" />
							<?php esc_html_e( 'Remove the stored key', 'crawlertoll' ); ?>
						</label>
					<?php endif; ?>
					<p class="description">
						<?php esc_html_e( 'Stored on this site only, never shown again. Use a restricted key (rk_…) with write access to PaymentIntents. Leave blank to keep the current key.', 'crawlertoll' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="crawlertoll-apple-pay"><?php esc_html_e( 'Apple Pay verification', 'crawlertoll' ); ?></label>
				</th>
				<td>
					<textarea
						id="crawlertoll-apple-pay"
						class="large-text code"
						rows="2"
						name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[apple_pay_domain_association]"
						placeholder="<?php esc_attr_e( 'Paste the contents of the verification file Stripe gives you', 'crawlertoll' ); ?>"
					><?php echo esc_textarea( isset( $settings['apple_pay_domain_association'] ) ? (string) $settings['apple_pay_domain_association'] : '' ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Only needed for one-tap Apple Pay: in your Stripe Dashboard → Settings → Payment method domains, add this site’s domain, download the verification file, and paste its contents here. We serve it at /.well-known/apple-developer-merchantid-domain-association for you. Google Pay needs no verification.', 'crawlertoll' ); ?>
					</p>
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
			<?php esc_html_e( 'Your AI-crawler rules', 'crawlertoll' ); ?>
		</h2>
		<p class="ct-card-desc">
			<?php esc_html_e( 'This is the rulebook your site hands to AI crawlers: who may read your content and at what price. It is written in a machine-readable standard (RSL 1.0, an extension of robots.txt) and is published automatically at /robots.txt.', 'crawlertoll' ); ?>
			<strong><?php esc_html_e( 'You normally never need to touch this — the defaults already charge every known AI crawler your standard price.', 'crawlertoll' ); ?></strong>
		</p>
		<details style="margin-top:4px;">
			<summary style="cursor:pointer;font-weight:600;font-size:13px;color:var(--ct-primary);">
				<?php esc_html_e( 'Advanced: view or edit the raw policy', 'crawlertoll' ); ?>
			</summary>
			<p class="ct-card-desc" style="margin-top:10px;">
				<?php
				printf(
					/* translators: %s: link to RSL spec */
					esc_html__( 'Raw RSL 1.0 directives, applied per request. Edit only if you know the syntax — a malformed rule can open content you meant to charge for. Full vocabulary: %s.', 'crawlertoll' ),
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
		</details>
	</div>

	<!-- Wall text (access-tiers spec §5.4, A3): publisher-editable wall copy. -->
	<div class="ct-card">
		<h2>
			<span class="dashicons dashicons-edit"></span>
			<?php esc_html_e( 'Wall text', 'crawlertoll' ); ?>
		</h2>
		<p class="ct-card-desc">
			<?php esc_html_e( 'The exact words readers see on the paywall. Leave a field empty to keep the default. Plain text only, up to 300 characters.', 'crawlertoll' ); ?>
			<?php esc_html_e( 'You can use placeholders: {price}, {currency}, {count}, {window_days}, {remaining}, {site_name} — they are filled in for each reader. A placeholder we cannot fill removes the whole custom line and falls back to the default, so readers never see a raw "{…}".', 'crawlertoll' ); ?>
		</p>
		<?php
		$ct_wall_defaults = CrawlerToll_Wall_Copy::defaults();
		$ct_wall_current  = isset( $settings['wall_text'] ) && is_array( $settings['wall_text'] ) ? $settings['wall_text'] : array();
		$ct_wall_fields   = array(
			'heading'     => __( 'Lock heading', 'crawlertoll' ),
			'value_line'  => __( 'Value line (supports {price})', 'crawlertoll' ),
			'meter_out'   => __( 'Free-reads-used-up note (supports {count}, {window_days})', 'crawlertoll' ),
			'unavailable' => __( 'Static lock-region sentence', 'crawlertoll' ),
		);
		foreach ( $ct_wall_fields as $ct_wall_key => $ct_wall_label ) :
			$ct_wall_val = isset( $ct_wall_current[ $ct_wall_key ] ) ? $ct_wall_current[ $ct_wall_key ] : '';
			?>
			<p style="margin:12px 0 4px;"><label for="ct-wall-<?php echo esc_attr( $ct_wall_key ); ?>" style="font-weight:600;font-size:13px;"><?php echo esc_html( $ct_wall_label ); ?></label></p>
			<input
				type="text"
				id="ct-wall-<?php echo esc_attr( $ct_wall_key ); ?>"
				name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[wall_text][<?php echo esc_attr( $ct_wall_key ); ?>]"
				value="<?php echo esc_attr( $ct_wall_val ); ?>"
				placeholder="<?php echo esc_attr( $ct_wall_defaults[ $ct_wall_key ] ); ?>"
				maxlength="300"
				class="large-text"
			/>
		<?php endforeach; ?>
		<p class="ct-card-desc" style="margin-top:10px;">
			<?php esc_html_e( 'Per article (Pro): the editor sidebar has a "Wall text" field that overrides the value line for that article only.', 'crawlertoll' ); ?>
		</p>
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

	<!-- Recent unlocks (D3, free tier) -->
	<div class="ct-card">
		<h2>
			<span class="dashicons dashicons-tickets-alt"></span>
			<?php esc_html_e( 'Recent unlocks', 'crawlertoll' ); ?>
		</h2>
		<p class="ct-card-desc">
			<?php esc_html_e( 'Every paid unlock of your sealed content leaves a receipt in the CrawlerToll Registry. These are your latest ones — proof that buyers (crawlers or humans) actually got in.', 'crawlertoll' ); ?>
		</p>

		<?php if ( ! $recent_unlocks_enrolled ) : ?>
			<p style="color:var(--ct-text-muted);font-size:13px;margin:0;">
				<?php esc_html_e( 'Your site is not enrolled with the registry yet. Enrollment happens automatically the first time you seal an article — receipts will appear here after the first paid unlock.', 'crawlertoll' ); ?>
			</p>
		<?php elseif ( $recent_unlocks_error ) : ?>
			<p style="color:var(--ct-text-muted);font-size:13px;margin:0;">
				<?php
				printf(
					/* translators: %s: error detail from the registry */
					esc_html__( 'Receipts are unavailable right now (%s). Nothing is lost — unlocks keep working; this list is just a read-back from the registry.', 'crawlertoll' ),
					esc_html( $recent_unlocks_error )
				);
				?>
			</p>
		<?php elseif ( empty( $recent_unlocks ) ) : ?>
			<p style="color:var(--ct-text-muted);font-size:13px;margin:0;">
				<?php esc_html_e( 'No unlocks yet — receipts appear here after the first paid unlock of a sealed article.', 'crawlertoll' ); ?>
			</p>
		<?php else : ?>
			<table class="widefat striped" style="margin-top:4px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'When', 'crawlertoll' ); ?></th>
						<th><?php esc_html_e( 'Content', 'crawlertoll' ); ?></th>
						<th><?php esc_html_e( 'Paid via', 'crawlertoll' ); ?></th>
						<th><?php esc_html_e( 'Receipt', 'crawlertoll' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $recent_unlocks as $unlock ) : ?>
						<?php
						$unlock_post_id = 0;
						if ( ! empty( $unlock['content_id'] ) && preg_match( '#/post/(\d+)$#', (string) $unlock['content_id'], $m ) ) {
							$unlock_post_id = (int) $m[1];
						}
						$unlock_ref = isset( $unlock['ref'] ) ? (string) $unlock['ref'] : '';
						if ( strlen( $unlock_ref ) > 18 ) {
							$unlock_ref = substr( $unlock_ref, 0, 10 ) . '…' . substr( $unlock_ref, -6 );
						}
						?>
						<tr>
							<td>
								<?php
								if ( ! empty( $unlock['created_at'] ) ) {
									printf(
										/* translators: %s: human-readable time difference */
										esc_html__( '%s ago', 'crawlertoll' ),
										esc_html( human_time_diff( (int) $unlock['created_at'], time() ) )
									);
								} else {
									echo '—';
								}
								?>
							</td>
							<td>
								<?php if ( $unlock_post_id && get_post( $unlock_post_id ) ) : ?>
									<a href="<?php echo esc_url( get_permalink( $unlock_post_id ) ); ?>" target="_blank" rel="noopener">
										<?php echo esc_html( get_the_title( $unlock_post_id ) ); ?>
									</a>
								<?php else : ?>
									<code style="font-size:11px;"><?php echo esc_html( (string) $unlock['content_id'] ); ?></code>
								<?php endif; ?>
							</td>
							<td>
								<span class="ct-endpoint-status active" style="text-transform:uppercase;">
									<?php echo esc_html( (string) $unlock['rail'] ); ?>
								</span>
							</td>
							<td>
								<?php if ( '' !== $unlock_ref ) : ?>
									<code style="font-size:11px;" title="<?php echo esc_attr( (string) $unlock['ref'] ); ?>"><?php echo esc_html( $unlock_ref ); ?></code>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<p style="font-size:12px;color:var(--ct-text-muted);margin:12px 0 0 0;">
			<?php esc_html_e( 'Showing the 25 most recent unlocks. The full revenue dashboard — history, filtering, CSV export — is part of CrawlerToll Pro.', 'crawlertoll' ); ?>
		</p>
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

	<!-- Advanced -->
	<div class="ct-card">
		<h2>
			<span class="dashicons dashicons-admin-tools"></span>
			<?php esc_html_e( 'Advanced', 'crawlertoll' ); ?>
		</h2>
		<p class="ct-card-desc"><?php esc_html_e( 'By default, deleting the plugin keeps your settings and request logs so you can reinstall without losing data.', 'crawlertoll' ); ?></p>
		<div class="ct-toggle-row">
			<label class="ct-toggle">
				<input
					type="checkbox"
					name="<?php echo esc_attr( CRAWLERTOLL_OPTION_KEY ); ?>[remove_data_on_uninstall]"
					value="1"
					<?php checked( ! empty( $settings['remove_data_on_uninstall'] ) ); ?>
				/>
				<span class="ct-toggle-slider"></span>
			</label>
			<span class="ct-toggle-label">
				<?php esc_html_e( 'Remove all CrawlerToll data when the plugin is deleted (drops the log table, settings, and scheduled tasks). Cannot be undone.', 'crawlertoll' ); ?>
			</span>
		</div>
	</div>

	<!-- Submit -->
	<div class="ct-submit-wrap">
		<?php submit_button( __( 'Save Changes', 'crawlertoll' ), 'primary', 'submit', false ); ?>
	</div>
</form>

