<?php
/**
 * Admin settings page — Settings → CrawlerToll.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Admin {

	/**
	 * @var CrawlerToll_DB|null
	 */
	private $db;

	/**
	 * @var CrawlerToll_Pro_Admin|null
	 */
	private $pro_admin;

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_upgrade_notice_dismiss' ) );
		add_action( 'admin_notices', array( $this, 'render_upgrade_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Initialise Pro admin if available.
		if ( class_exists( 'CrawlerToll_DB' ) ) {
			global $wpdb;
			$this->db = new CrawlerToll_DB( $wpdb );
		}
		if ( class_exists( 'CrawlerToll_Pro_Admin' ) && $this->db ) {
			$this->pro_admin = new CrawlerToll_Pro_Admin( $this->db );
			$this->pro_admin->register();
		}
	}

	public function enqueue_assets( $hook ) {
		if ( 'settings_page_crawlertoll' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'crawlertoll-admin',
			plugin_dir_url( CRAWLERTOLL_PLUGIN_FILE ) . 'assets/admin.css',
			array(),
			CRAWLERTOLL_VERSION
		);
		wp_enqueue_script(
			'crawlertoll-admin',
			plugin_dir_url( CRAWLERTOLL_PLUGIN_FILE ) . 'assets/admin.js',
			array(),
			CRAWLERTOLL_VERSION,
			true
		);

		// Mount the React free-app (Vite bundle) into the settings page. The
		// enqueuer is file_exists-guarded: if the bundle is stripped or unbuilt it
		// returns false and the page keeps its server-rendered status cards
		// (progressive enhancement — see admin/views/settings.php). When it does
		// mount, React replaces those cards with the live version, fed by the data
		// blob below.
		if ( CrawlerToll_Vite::enqueue( 'free', 'crawlertoll-free-app' ) ) {
			$settings = crawlertoll_get_settings();
			$bots     = CrawlerToll_Bot_Catalogue::all();
			$policy   = CrawlerToll_RSL_Parser::parse( $settings['policy'] );
			$data     = array(
				'enabled'      => ! empty( $settings['enabled'] ),
				'botCount'     => count( $bots ),
				'policyGroups' => count( $policy['groups'] ),
				'priceMicros'  => (int) $settings['price_micros'],
				'currency'     => (string) $settings['currency'],
			);
			wp_add_inline_script(
				'crawlertoll-free-app',
				'window.crawlertollFree = ' . wp_json_encode( $data ) . ';',
				'before'
			);
		}
	}

	public function register_menu() {
		add_options_page(
			__( 'CrawlerToll', 'crawlertoll' ),
			__( 'CrawlerToll', 'crawlertoll' ),
			'manage_options',
			'crawlertoll',
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'crawlertoll',
			CRAWLERTOLL_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => crawlertoll_default_settings(),
			)
		);
	}

	/**
	 * D1 (lineup freeze): any live 1.x install auto-updates into a ground-up
	 * rebuild. Show a notice on admin loads after an upgrade from <2.0 until
	 * dismissed — a changelog line reaches nobody, and this person's site just
	 * changed behavior unasked.
	 *
	 * @return void
	 */
	public function render_upgrade_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$seen = get_option( 'crawlertoll_installed_version' );
		if ( false === $seen ) {
			// No marker: fresh 2.x installs set it in the activation hook, so
			// arriving here without one means an upgrade from a release that
			// predates version tracking (i.e. <2.0).
			$seen = '1.0.1';
		}
		if ( ! version_compare( $seen, '2.0.0', '<' ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'crawlertoll_dismiss_upgrade', '1' ),
			'crawlertoll_dismiss_upgrade'
		);
		?>
		<div class="notice notice-warning">
			<p><strong><?php esc_html_e( 'CrawlerToll 2.0 — rebuilt from the ground up.', 'crawlertoll' ); ?></strong></p>
			<p>
				<?php esc_html_e( 'Your previous configuration does not carry over. Please open the CrawlerToll settings and set your pricing, payment rails and content rules again — it takes about two minutes.', 'crawlertoll' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'options-general.php?page=crawlertoll' ) ); ?>">
					<?php esc_html_e( 'Open CrawlerToll settings', 'crawlertoll' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( $dismiss_url ); ?>">
					<?php esc_html_e( 'Dismiss', 'crawlertoll' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle the upgrade-notice dismiss link (nonce-checked). Marks the current
	 * version as seen so the notice never returns for this install.
	 *
	 * @return void
	 */
	public function handle_upgrade_notice_dismiss() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['crawlertoll_dismiss_upgrade'] ) ) {
			return;
		}
		// phpcs:enable
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'crawlertoll_dismiss_upgrade' );
		update_option( 'crawlertoll_installed_version', CRAWLERTOLL_VERSION );
		wp_safe_redirect( remove_query_arg( array( 'crawlertoll_dismiss_upgrade', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$defaults = crawlertoll_default_settings();

		// Start from the currently-stored settings (merged over defaults) rather
		// than from defaults alone, so fields this form doesn't render — e.g.
		// rail_overrides, managed by the Pro Rails tab — survive a save here.
		$current = get_option( CRAWLERTOLL_OPTION_KEY );
		$out     = is_array( $current ) ? wp_parse_args( $current, $defaults ) : $defaults;

		if ( ! is_array( $input ) ) {
			return $out;
		}

		$out['enabled']             = ! empty( $input['enabled'] );
		$out['price_micros']        = isset( $input['price_micros'] ) ? max( 0, (int) $input['price_micros'] ) : $defaults['price_micros'];
		$out['currency']            = isset( $input['currency'] ) && in_array( strtoupper( $input['currency'] ), array( 'USD', 'USDC', 'EUR', 'GBP' ), true )
			? strtoupper( $input['currency'] )
			: $defaults['currency'];
		$out['rail']                = isset( $input['rail'] ) && in_array( $input['rail'], array_keys( crawlertoll_rail_options() ), true )
			? $input['rail']
			: $defaults['rail'];
		// R1.x-a: publisher's USDC payout address (EVM, Base). Strict shape check —
		// an invalid value must never reach the registry (it would aim payments at
		// a malformed destination). Invalid input keeps the stored value + warns.
		if ( isset( $input['x402_pay_to'] ) ) {
			$candidate = trim( (string) $input['x402_pay_to'] );
			if ( '' === $candidate || preg_match( '/^0x[0-9a-fA-F]{40}$/', $candidate ) ) {
				$out['x402_pay_to'] = $candidate;
			} else {
				add_settings_error( CRAWLERTOLL_OPTION_KEY, 'x402_pay_to_invalid', esc_html__( 'USDC payout address ignored: it must be a 0x… address (42 characters).', 'crawlertoll' ) );
			}
		}
		$out['payment_url']         = isset( $input['payment_url'] ) ? esc_url_raw( trim( $input['payment_url'] ) ) : '';
		// Apple Pay domain verification file contents (Stripe Dashboard → payment
		// method domains). Served verbatim at /.well-known/apple-developer-
		// merchantid-domain-association; harmless text token, strip tags only.
		$out['apple_pay_domain_association'] = isset( $input['apple_pay_domain_association'] ) ? trim( sanitize_textarea_field( wp_unslash( $input['apple_pay_domain_association'] ) ) ) : '';
		$out['terms_url']           = isset( $input['terms_url'] ) ? esc_url_raw( trim( $input['terms_url'] ) ) : '';
		$out['context_license_url'] = isset( $input['context_license_url'] ) ? esc_url_raw( trim( $input['context_license_url'] ) ) : '';
		$out['policy']              = isset( $input['policy'] ) ? sanitize_textarea_field( wp_unslash( $input['policy'] ) ) : $defaults['policy'];
		$out['remove_data_on_uninstall'] = ! empty( $input['remove_data_on_uninstall'] );

		return $out;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'crawlertoll' ) );
		}

		// Determine active tab.
		$pro_active = $this->pro_admin && CrawlerToll_Pro_Admin::is_pro_active();
		$tab = isset( $_GET['ct_tab'] ) ? sanitize_text_field( wp_unslash( $_GET['ct_tab'] ) ) : 'settings'; // phpcs:ignore

		$tabs = array(
			'settings'  => __( 'Settings', 'crawlertoll' ),
			'pricing'   => __( 'Pricing', 'crawlertoll' ),
			'alerts'    => __( 'Alerts', 'crawlertoll' ),
			'revenue'   => __( 'Revenue', 'crawlertoll' ),
			'logs'      => __( 'Logs', 'crawlertoll' ),
			'rails'     => __( 'Rails', 'crawlertoll' ),
			'webhooks'  => __( 'Webhooks', 'crawlertoll' ),
			'preview'   => __( 'Wall preview', 'crawlertoll' ),
		);

		// If Pro isn't active, redirect Pro tabs to settings with a notice.
		if ( ! $pro_active && in_array( $tab, array( 'pricing', 'alerts', 'revenue', 'logs', 'rails', 'webhooks' ), true ) ) {
			$tab = 'settings';
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-info is-dismissible"><p>';
				esc_html_e( 'Revenue dashboard and bot-request logs are CrawlerToll Pro features. Get a license at crawlertoll.com.', 'crawlertoll' );
				echo '</p></div>';
			} );
		}

		// Render tab navigation.
		echo '<div class="wrap">';
		echo '<div class="ct-header">';
		echo '<h1>' . esc_html__( 'CrawlerToll', 'crawlertoll' ) . ' <span class="ct-badge">v' . esc_html( CRAWLERTOLL_VERSION ) . '</span></h1>';
		echo '</div>';

		echo '<nav class="ct-tabs" style="margin-bottom:24px;border-bottom:2px solid #e2e8f0;display:flex;gap:0;">';
		foreach ( $tabs as $tab_key => $tab_label ) {
			$is_pro_tab = in_array( $tab_key, array( 'pricing', 'alerts', 'revenue', 'logs', 'rails', 'webhooks' ), true );
			$classes = 'ct-tab';
			if ( $tab_key === $tab ) {
				$classes .= ' ct-tab-active';
			}
			if ( $is_pro_tab && ! $pro_active ) {
				$classes .= ' ct-tab-locked';
			}
			$url = add_query_arg( 'ct_tab', $tab_key, admin_url( 'options-general.php?page=crawlertoll' ) );
			echo '<a href="' . esc_url( $url ) . '" class="' . esc_attr( $classes ) . '" style="display:inline-block;padding:10px 20px;text-decoration:none;font-weight:600;font-size:14px;color:';
			echo ( $tab_key === $tab ) ? '#6366f1' : '#64748b';
			echo ';border-bottom:';
			echo ( $tab_key === $tab ) ? '3px solid #6366f1' : '3px solid transparent';
			echo ';margin-bottom:-2px;transition:color .15s,border-color .15s;">';
			echo esc_html( $tab_label );
			if ( $is_pro_tab && ! $pro_active ) {
				echo ' <span style="font-size:10px;background:#f59e0b;color:#fff;padding:2px 6px;border-radius:99px;vertical-align:middle;">PRO</span>';
			}
			echo '</a>';
		}
		echo '</nav>';

		// Route to the appropriate view.
		switch ( $tab ) {
			case 'pricing':
				if ( $pro_active && $this->pro_admin ) {
					$this->pro_admin->render_pricing_tab();
				}
				break;
			case 'alerts':
				if ( $pro_active && $this->pro_admin ) {
					$this->pro_admin->render_alerts_tab();
				}
				break;
			case 'revenue':
				if ( $pro_active && $this->pro_admin ) {
					$this->pro_admin->render_revenue_tab();
				}
				break;
			case 'logs':
				if ( $pro_active && $this->pro_admin ) {
					$this->pro_admin->render_logs_tab();
				}
				break;
			case 'rails':
				if ( $pro_active && $this->pro_admin ) {
					$this->pro_admin->render_rails_tab();
				}
				break;
			case 'webhooks':
				if ( $pro_active && $this->pro_admin ) {
					$this->pro_admin->render_webhooks_tab();
				}
				break;
			case 'preview':
				$this->render_preview_tab();
				break;
			default:
				$this->render_settings_tab();
				break;
		}

		echo '</div>'; // .wrap
	}

	/**
	 * Wall preview tab (access-tiers spec §5.2): pick a premium post and see the
	 * reader-facing wall — the free preview exactly as the cut defines it, plus
	 * the lock region. Free feature (the cut itself is free). Side-effect-free:
	 * never seals or registers from the admin.
	 *
	 * @return void
	 */
	private function render_preview_tab() {
		$premium_posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 50,
				'meta_key'       => CrawlerToll_Cut::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded admin lookup, 50 rows max.
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		echo '<h2>' . esc_html__( 'Wall preview', 'crawlertoll' ) . '</h2>';

		if ( empty( $premium_posts ) ) {
			echo '<p>' . esc_html__( 'No premium posts yet. Mark a post as premium in the editor (CrawlerToll panel), then come back to see its paywall.', 'crawlertoll' ) . '</p>';
			return;
		}

		$selected = isset( $_GET['ct_preview_post'] ) ? absint( $_GET['ct_preview_post'] ) : (int) $premium_posts[0]->ID; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview selector.

		echo '<form method="get" style="margin:0 0 16px;">';
		echo '<input type="hidden" name="page" value="crawlertoll" />';
		echo '<input type="hidden" name="ct_tab" value="preview" />';
		echo '<label for="ct_preview_post" style="font-weight:600;margin-right:8px;">' . esc_html__( 'Premium post:', 'crawlertoll' ) . '</label>';
		echo '<select name="ct_preview_post" id="ct_preview_post" onchange="this.form.submit()">';
		foreach ( $premium_posts as $p ) {
			echo '<option value="' . esc_attr( (string) $p->ID ) . '"' . selected( $selected, $p->ID, false ) . '>' . esc_html( get_the_title( $p ) ) . '</option>';
		}
		echo '</select>';
		echo '</form>';

		$gate = new CrawlerToll_Premium_Gate();
		$html = $gate->wall_preview_html( $selected );
		if ( '' === $html ) {
			echo '<p>' . esc_html__( 'That post is not premium.', 'crawlertoll' ) . '</p>';
			return;
		}

		$cut = (int) get_post_meta( $selected, CrawlerToll_Cut::CUT_META, true );
		echo '<p style="color:#64748b;font-size:13px;">';
		if ( $cut > 0 ) {
			/* translators: %d: block/paragraph index after which the seal begins. */
			printf( esc_html__( 'Cut: manual — after block/paragraph #%d (set in the editor). This is what readers see:', 'crawlertoll' ), $cut );
		} else {
			esc_html_e( 'Cut: automatic (first block/paragraph, or a <!--more--> marker). This is what readers see:', 'crawlertoll' );
		}
		echo '</p>';

		// The publisher's own content rendered back to the publisher (manage_options
		// screen, their own post). Kses would strip the lock div's data attributes,
		// so this renders raw by design — same trust level as the post editor.
		echo '<div style="border:1px solid #e2e8f0;border-radius:10px;padding:20px;max-width:720px;background:#fff;">';
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see note above.
		echo '</div>';

		// Live preview (Chris QA 2026-08-29): mount the REAL unlock app into the
		// lock region when a seal exists — the wall the publisher sees is the wall
		// readers get, meter tile and rails included. Footer-enqueued; WP prints
		// admin footer scripts after the page body, so this late enqueue is safe.
		$has_seal = is_array( get_post_meta( $selected, CrawlerToll_Premium_Gate::SEAL_META, true ) );
		if ( $has_seal && CrawlerToll_Vite::enqueue( 'unlock', 'crawlertoll-unlock-app' ) ) {
			$settings = crawlertoll_get_settings();
			$base     = defined( 'CRAWLERTOLL_REGISTRY_URL' ) ? CRAWLERTOLL_REGISTRY_URL : CrawlerToll_Registry::REGISTRY_URL;
			wp_add_inline_script(
				'crawlertoll-unlock-app',
				'window.crawlertollUnlock = ' . wp_json_encode(
					array(
						'registryBase'         => esc_url_raw( $base ),
						'stripePublishableKey' => isset( $settings['stripe_publishable_key'] ) ? (string) $settings['stripe_publishable_key'] : '',
						'currency'             => isset( $settings['currency'] ) ? (string) $settings['currency'] : 'USD',
					)
				) . ';',
				'before'
			);
			echo '<p style="color:#64748b;font-size:12px;margin-top:8px;">' . esc_html__( 'This is the live wall — the unlock app is running. Free reads or payments you make here are real (they use your visitor allowance / wallet).', 'crawlertoll' ) . '</p>';
		} else {
			echo '<p style="color:#64748b;font-size:12px;margin-top:8px;">' . esc_html__( 'Static frame only: this post has not been sealed yet. View it once on the frontend (or in a private window) to seal it, then this preview becomes the live, clickable wall.', 'crawlertoll' ) . '</p>';
		}
	}

	/**
	 * Render the settings tab (the original settings page).
	 */
	private function render_settings_tab() {
		$settings = crawlertoll_get_settings();
		$bots     = CrawlerToll_Bot_Catalogue::all();
		$rails    = crawlertoll_rail_options();

		// Recent unlocks (lineup freeze D3, free tier): read the registry's
		// unlock-receipt store. Cached 60s so a slow/unreachable registry never
		// stalls the settings page; fail-soft — the view renders a graceful note
		// on WP_Error. Only fetched when the site is enrolled (no token, no data).
		$recent_unlocks          = array();
		$recent_unlocks_error    = null;
		$recent_unlocks_enrolled = class_exists( 'CrawlerToll_Registry' ) && CrawlerToll_Registry::is_registered();
		if ( $recent_unlocks_enrolled ) {
			$cached = get_transient( 'crawlertoll_recent_unlocks' );
			if ( false !== $cached ) {
				$recent_unlocks       = $cached['rows'];
				$recent_unlocks_error = $cached['error'];
			} else {
				$registry = new CrawlerToll_Registry();
				$result   = $registry->recent_unlocks( 25 );
				if ( is_wp_error( $result ) ) {
					$recent_unlocks_error = $result->get_error_message();
				} else {
					$recent_unlocks = $result;
				}
				set_transient(
					'crawlertoll_recent_unlocks',
					array( 'rows' => $recent_unlocks, 'error' => $recent_unlocks_error ),
					60
				);
			}
		}

		// Count bot categories for the status cards.
		$category_counts = array();
		foreach ( $bots as $bot ) {
			$cat = $bot['category'];
			$category_counts[ $cat ] = ( $category_counts[ $cat ] ?? 0 ) + 1;
		}

		// Parse the policy to count active groups.
		$policy_data   = CrawlerToll_RSL_Parser::parse( $settings['policy'] );
		$active_bots   = 0;
		$active_groups = count( $policy_data['groups'] );
		foreach ( $policy_data['groups'] as $group ) {
			$active_bots += count( $group['user_agents'] );
		}

		include CRAWLERTOLL_PLUGIN_DIR . 'admin/views/settings.php';
	}
}
