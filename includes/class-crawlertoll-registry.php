<?php
/**
 * Registry integration — registers the site with the CrawlerToll Registry
 * (registry.crawlertoll.com) for AI crawler discoverability.
 *
 * Opt-in. The context-license.json is always public. The registry just
 * makes it easier for AI crawlers to find. Without the registry, crawlers
 * have to scrape the raw web. With it, they query one index.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Registry {

	/**
	 * Registry API base URL.
	 */
	const REGISTRY_URL = 'https://registry.crawlertoll.com';

	/**
	 * Option key for registry settings.
	 */
	const OPTION_KEY = 'crawlertoll_registry';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Hook into settings save to sync with registry.
		add_action( 'update_option_' . CRAWLERTOLL_OPTION_KEY, array( $this, 'on_settings_updated' ), 10, 2 );

		// Daily re-validation cron.
		add_action( 'crawlertoll_registry_sync', array( $this, 'sync_with_registry' ) );
		if ( ! wp_next_scheduled( 'crawlertoll_registry_sync' ) ) {
			wp_schedule_event( time(), 'daily', 'crawlertoll_registry_sync' );
		}

		// Content hash feed — push provenance hashes to the registry.
		add_action( 'crawlertoll_registry_hash_feed', array( $this, 'push_content_hashes' ) );
		if ( ! wp_next_scheduled( 'crawlertoll_registry_hash_feed' ) ) {
			wp_schedule_event( time(), 'hourly', 'crawlertoll_registry_hash_feed' );
		}
	}

	/**
	 * Registry base URL. Honors the CRAWLERTOLL_REGISTRY_URL constant override
	 * (local dev / staging) everywhere — F1 (live QA 2026-07-28): several methods
	 * used the hardcoded production constant, so a staging override silently
	 * enrolled/pushed to the wrong registry.
	 *
	 * @return string
	 */
	private static function base_url() {
		return defined( 'CRAWLERTOLL_REGISTRY_URL' ) ? CRAWLERTOLL_REGISTRY_URL : self::REGISTRY_URL;
	}

	/**
	 * Whether the site is registered with the registry.
	 *
	 * @return bool
	 */
	public static function is_registered() {
		$settings = get_option( self::OPTION_KEY, array() );
		return ! empty( $settings['registered'] );
	}

	/**
	 * Register with the registry. Called when the user opts in.
	 *
	 * @return array{status: string, validated: bool, entry?: array}|WP_Error
	 */
	public function register_with_registry() {
		$plugin_settings = crawlertoll_get_settings();
		$categories = $this->detect_categories();

		$body = array(
			'domain'              => wp_parse_url( home_url(), PHP_URL_HOST ),
			'context_license_url' => home_url( '/.well-known/context-license.json' ),
			'site_name'           => get_bloginfo( 'name' ),
			'categories'          => $categories,
		);

		$response = wp_remote_post(
			self::base_url() . '/v1/register',
			array(
				'body'    => wp_json_encode( $body ),
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->get_registry_key(),
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $data['status'] ) && $data['status'] === 'registered' ) {
			update_option( self::OPTION_KEY, array(
				'registered'    => true,
				'registered_at' => current_time( 'mysql' ),
				'last_synced'   => current_time( 'mysql' ),
				'validated'     => ! empty( $data['validated'] ),
			) );
		}

		return $data;
	}

	/**
	 * Register a sealed CEK with the registry escrow.
	 * Base URL is overridable via the CRAWLERTOLL_REGISTRY_URL constant (local dev).
	 *
	 * @param string $content_id
	 * @param string $cek_b64      Base64 CEK (the escrow secret).
	 * @param int    $price_micros
	 * @param string $currency
	 * @param string $scope
	 * @return array|WP_Error Decoded JSON, or WP_Error on transport failure.
	 */
	public function register_sealed( $content_id, $cek_b64, $price_micros, $currency = 'USDC', $scope = 'full' ) {
		$response = $this->post_sealed_register( $content_id, $cek_b64, $price_micros, $currency, $scope );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// F1 (live QA 2026-07-28): an unenrolled site got a 401 here and the seal
		// silently degraded to a plain 402 — nothing for the bot to buy, funnel
		// dead at install. Self-heal instead: enroll once (TOFU binds our bearer
		// key to this domain, gated on the well-known ownership proof), then
		// retry the escrow write once. A genuine auth failure (someone else's
		// token owns the domain) still fails — exactly once, no loop.
		if ( is_array( $data ) && isset( $data['error'] )
			&& in_array( $data['error'], array( 'publisher_not_enrolled', 'invalid_token', 'missing_bearer_token' ), true ) ) {
			$enroll = $this->register_with_registry();
			if ( ! is_wp_error( $enroll ) && is_array( $enroll ) && isset( $enroll['status'] ) && 'registered' === $enroll['status'] ) {
				$response = $this->post_sealed_register( $content_id, $cek_b64, $price_micros, $currency, $scope );
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				$data = json_decode( wp_remote_retrieve_body( $response ), true );
			}
		}
		return $data;
	}

	/**
	 * Raw POST /v1/sealed/register (single attempt).
	 *
	 * @return array|WP_Error Raw HTTP response, or WP_Error on transport failure.
	 */
	private function post_sealed_register( $content_id, $cek_b64, $price_micros, $currency, $scope ) {
		return wp_remote_post(
			self::base_url() . '/v1/sealed/register',
			array(
				'body'    => wp_json_encode(
					array(
						'content_id'   => $content_id,
						'cek'          => $cek_b64,
						'price_micros' => (int) $price_micros,
						'currency'     => $currency,
						'scope'        => $scope,
						'publisher'    => wp_parse_url( home_url(), PHP_URL_HOST ),
					)
				),
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->get_registry_key(),
				),
				'timeout' => 15,
			)
		);
	}

	/**
	 * Delist from the registry.
	 *
	 * @return array|WP_Error
	 */
	public function delist_from_registry() {
		$domain = wp_parse_url( home_url(), PHP_URL_HOST );

		$response = wp_remote_request(
			self::base_url() . '/v1/publisher/' . urlencode( $domain ),
			array(
				'method'  => 'DELETE',
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->get_registry_key(),
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		delete_option( self::OPTION_KEY );
		return array( 'status' => 'delisted' );
	}

	/**
	 * Sync with the registry when settings change.
	 *
	 * @param mixed $old_value
	 * @param mixed $new_value
	 * @return void
	 */
	public function on_settings_updated( $old_value, $new_value ) {
		if ( ! self::is_registered() ) {
			return;
		}
		// Re-register to update metadata.
		$this->register_with_registry();
	}

	/**
	 * Daily sync — re-register to keep metadata fresh.
	 *
	 * @return void
	 */
	public function sync_with_registry() {
		if ( ! self::is_registered() ) {
			return;
		}
		$result = $this->register_with_registry();
		if ( ! is_wp_error( $result ) ) {
			$settings = get_option( self::OPTION_KEY, array() );
			$settings['last_synced'] = current_time( 'mysql' );
			update_option( self::OPTION_KEY, $settings );
		}
	}

	/**
	 * Push content hashes from the log table to the registry.
	 * Runs hourly. Only pushes hashes newer than the last push.
	 *
	 * @return void
	 */
	public function push_content_hashes() {
		if ( ! self::is_registered() ) {
			return;
		}

		$settings = get_option( self::OPTION_KEY, array() );
		$last_push = isset( $settings['last_hash_push'] ) ? $settings['last_hash_push'] : gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) );

		global $wpdb;
		$table = $wpdb->prefix . 'crawlertoll_log';

		$hashes = $wpdb->get_results( $wpdb->prepare(
			"SELECT content_hash, request_path, request_time, price_micros, currency
			FROM {$table}
			WHERE content_hash IS NOT NULL AND request_time > %s
			ORDER BY request_time DESC
			LIMIT 100",
			$last_push
		), ARRAY_A );

		if ( empty( $hashes ) ) {
			$settings['last_hash_push'] = current_time( 'mysql' );
			update_option( self::OPTION_KEY, $settings );
			return;
		}

		$domain = wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( $hashes as $hash ) {
			$body = array(
				'hash'             => $hash['content_hash'],
				'publisher_domain' => $domain,
				'path'             => $hash['request_path'],
				'timestamp'        => $hash['request_time'],
				'price_micros'     => (int) $hash['price_micros'],
				'currency'         => $hash['currency'],
			);

			wp_remote_post(
				self::base_url() . '/v1/hashes',
				array(
					'body'    => wp_json_encode( $body ),
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $this->get_registry_key(),
					),
					'timeout' => 10,
					'blocking' => false,
				)
			);
		}

		$settings['last_hash_push'] = current_time( 'mysql' );
		update_option( self::OPTION_KEY, $settings );
	}

	/**
	 * Render the registry settings section.
	 *
	 * @return void
	 */
	public function render_registry_section() {
		$is_registered = self::is_registered();
		$reg_settings  = get_option( self::OPTION_KEY, array() );
		?>
		<div class="ct-card">
			<h2>
				<span class="dashicons dashicons-networking"></span>
				<?php esc_html_e( 'CrawlerToll Registry', 'crawlertoll' ); ?>
			</h2>
			<p class="ct-card-desc">
				<?php esc_html_e( 'The registry is how AI crawlers discover your content. Without it, crawlers have to scrape the raw web blindly. With it, they query one index and find your content — with your price and license terms attached.', 'crawlertoll' ); ?>
			</p>

			<?php if ( $is_registered ) : ?>
				<div style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:8px;padding:16px;margin-bottom:16px;">
					<p style="margin:0;color:#065f46;font-weight:600;">
						<span class="dashicons dashicons-yes-alt" style="color:#10b981;"></span>
						<?php esc_html_e( 'Your site is listed in the CrawlerToll Registry.', 'crawlertoll' ); ?>
					</p>
					<p style="margin:4px 0 0 0;font-size:12px;color:#065f46;">
						<?php
						printf(
							/* translators: %s: registration date */
							esc_html__( 'Registered since %s. Last synced: %s.', 'crawlertoll' ),
							isset( $reg_settings['registered_at'] ) ? esc_html( $reg_settings['registered_at'] ) : '—',
							isset( $reg_settings['last_synced'] ) ? esc_html( $reg_settings['last_synced'] ) : '—'
						);
						?>
					</p>
					<p style="margin:8px 0 0 0;font-size:12px;color:#065f46;">
						<?php esc_html_e( 'AI crawlers can discover your content at:', 'crawlertoll' ); ?>
						<code style="background:rgba(0,0,0,.05);padding:2px 6px;border-radius:4px;">registry.crawlertoll.com/v1/publisher/<?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></code>
					</p>
				</div>

				<form method="post" action="">
					<?php wp_nonce_field( 'crawlertoll_registry_delist', 'ct_registry_nonce' ); ?>
					<input type="hidden" name="ct_registry_action" value="delist" />
					<button type="submit" class="button" style="color:#dc2626;border-color:#dc2626;">
						<?php esc_html_e( 'Remove from Registry', 'crawlertoll' ); ?>
					</button>
				</form>
			<?php else : ?>
				<div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:16px;margin-bottom:16px;">
					<p style="margin:0;color:#92400e;">
						<?php esc_html_e( 'Your site is not yet listed in the registry. AI crawlers cannot discover your content through the index.', 'crawlertoll' ); ?>
					</p>
					<p style="margin:4px 0 0 0;font-size:12px;color:#92400e;">
						<?php esc_html_e( 'Your context-license.json is still served from your domain — the registry just makes it easier for crawlers to find.', 'crawlertoll' ); ?>
					</p>
				</div>

				<form method="post" action="">
					<?php wp_nonce_field( 'crawlertoll_registry_register', 'ct_registry_nonce' ); ?>
					<input type="hidden" name="ct_registry_action" value="register" />
					<button type="submit" class="button button-primary" style="background:var(--ct-primary);color:#fff;border:none;padding:8px 24px;border-radius:6px;font-weight:600;cursor:pointer;">
						<?php esc_html_e( 'List My Site in the Registry', 'crawlertoll' ); ?>
					</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle registry form submissions.
	 *
	 * @return void
	 */
	public function handle_form_submission() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['ct_registry_action'] ) || ! isset( $_POST['ct_registry_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ct_registry_nonce'] ) ), 'crawlertoll_registry_' . sanitize_text_field( wp_unslash( $_POST['ct_registry_action'] ) ) ) ) {
			return;
		}
		// phpcs:enable

		$action = sanitize_text_field( wp_unslash( $_POST['ct_registry_action'] ) );

		if ( $action === 'register' ) {
			$result = $this->register_with_registry();
			if ( is_wp_error( $result ) ) {
				add_action( 'admin_notices', function () use ( $result ) {
					echo '<div class="notice notice-error"><p>';
					printf(
						/* translators: %s: error message */
						esc_html__( 'Registry registration failed: %s', 'crawlertoll' ),
						esc_html( $result->get_error_message() )
					);
					echo '</p></div>';
				} );
			} else {
				add_action( 'admin_notices', function () {
					echo '<div class="notice notice-success"><p>';
					esc_html_e( 'Your site is now listed in the CrawlerToll Registry. AI crawlers can discover your content.', 'crawlertoll' );
					echo '</p></div>';
				} );
			}
		} elseif ( $action === 'delist' ) {
			$result = $this->delist_from_registry();
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-info"><p>';
				esc_html_e( 'Your site has been removed from the CrawlerToll Registry.', 'crawlertoll' );
				echo '</p></div>';
			} );
		}
	}

	/**
	 * Clear cron on deactivation.
	 *
	 * @return void
	 */
	public static function clear_cron() {
		wp_clear_scheduled_hook( 'crawlertoll_registry_sync' );
		wp_clear_scheduled_hook( 'crawlertoll_registry_hash_feed' );
	}

	// ─── Helpers ─────────────────────────────────────────────────

	/**
	 * Detect content categories from the site's existing taxonomies.
	 *
	 * @return array<int,string>
	 */
	private function detect_categories() {
		$cats = array();

		// WordPress categories.
		$wp_cats = get_categories( array( 'number' => 5, 'orderby' => 'count', 'order' => 'DESC' ) );
		foreach ( $wp_cats as $cat ) {
			$cats[] = strtolower( $cat->name );
		}

		// Fallback from site description/tagline.
		if ( empty( $cats ) ) {
			$desc = get_bloginfo( 'description' );
			if ( $desc ) {
				$cats[] = strtolower( $desc );
			}
		}

		return array_slice( array_unique( $cats ), 0, 5 );
	}

	/**
	 * Get a registry API key. Generates one if it doesn't exist.
	 *
	 * @return string
	 */
	private function get_registry_key() {
		$key = get_option( 'crawlertoll_registry_key', '' );
		if ( empty( $key ) ) {
			$key = wp_generate_password( 32, false );
			update_option( 'crawlertoll_registry_key', $key );
		}
		return $key;
	}
}
