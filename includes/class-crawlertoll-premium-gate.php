<?php
/**
 * Premium-path gate (WS3 slice 3.3). Inverts enforcement: for a post marked
 * premium (_crawlertoll_premium), the sealed BODY half (CrawlerToll_Cut::split)
 * never reaches ANY content-bearing WordPress surface — only the cleartext
 * PREVIEW does (plus an unlock entry point for humans / a 402 offer for agents).
 *
 * SINGLE CHOKEPOINT: every surface routes through preview_html($id), which for a
 * gated post returns only the preview. "Gated" = premium AND the current user
 * cannot edit the post (authors/editors see the full content so they can preview).
 * The body is emitted on exactly ONE path — the editor branch — and every failure
 * degrades toward preview-only, never toward the body (fail-closed).
 *
 * Free-safe: deps are only CrawlerToll_Cut / _Sealed / _Registry / _Sealed_Gate /
 * _SafeMode / _Bot_Catalogue — never a Pro-only class (build.sh enforces this).
 *
 * Scope (3.3): post type 'post' only (the only type with the premium meta).
 * DEFERRED (documented, NOT content leaks — the rendered snippet is already gated):
 *  - the verified-search IP-range/CIDR tier (SEO response-shape nicety; the body
 *    is preview-only to every non-editor regardless, so this is not leak-safety) — 3.6-adjacent;
 *  - the posts_search SQL narrowing (a body-term existence ORACLE; the result
 *    snippet itself is already preview-only via the excerpt gate) — 3.3b.
 *
 * KNOWN LIMITATION (raw-access — the universal filter-paywall ceiling): every
 * STANDARD WordPress output surface is gated (the_content, excerpts, all feeds,
 * REST in every context, oEmbed, search, sitemap, block-theme render, in-loop
 * get_the_content()/do_blocks()). But code that EXPLICITLY fetches a specific
 * premium post by id and renders its RAW post_content outside the loop —
 * get_post($id)->post_content, get_the_content(null,false,$id), or
 * do_blocks(get_post($id)->post_content) — bypasses every filter. This cannot be
 * closed safely: get_post() returns a fresh instance per call, and the only
 * persistent override (wp_cache_set 'posts') would write the preview into a
 * persistent object cache and corrupt the post for editors + the seal logic on
 * later requests. Shared by ALL filter-based paywalls. Mitigation: render premium
 * posts via the_content() (the WordPress-idiomatic path), which is gated.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Premium_Gate {

	/** Marker class on the locked region — matches the JSON-LD hasPart cssSelector. */
	const MARKER_CLASS = 'ct-sealed-body';

	/** Seal cache meta (blob + body hash). Never registered show_in_rest. */
	const SEAL_META = '_crawlertoll_sealed_v1';

	/** @var array<int,array> Memoized split() per post id. */
	private $parts = array();

	/** @var array<int,(string|false)> Memoized sealed blob per post id. */
	private $blob = array();

	/**
	 * Wire every content-bearing surface through the gate. Registered
	 * unconditionally (sealing ships free), from the plugin bootstrap.
	 *
	 * @return void
	 */
	public function register() {
		// ── data layer: blank the raw post_content of gated posts in any query /
		//    loop, so EVERY reader (the_content, get_the_content(), do_blocks(),
		//    a wrong loop pointer) sees preview-only — not just the the_content
		//    filter. The original split is memoized first so seal-on-serve keeps
		//    the real body. Residual (documented): a bare get_post($id) rendered
		//    entirely outside any query/loop is unhookable — the universal
		//    filter-paywall limitation. ──
		add_filter( 'the_posts', array( $this, 'neutralize_query_posts' ), 10, 1 );
		add_action( 'the_post', array( $this, 'neutralize_loop_post' ), 1, 1 );

		// ── content surfaces (each gated per-post) ──
		add_filter( 'the_content', array( $this, 'filter_content' ), 7 );
		add_filter( 'get_the_excerpt', array( $this, 'filter_excerpt' ), 11, 2 );
		add_filter( 'the_excerpt_rss', array( $this, 'filter_excerpt_rss' ), 9 );
		add_filter( 'the_content_feed', array( $this, 'filter_content_feed' ), 9, 2 );
		add_filter( 'rest_prepare_post', array( $this, 'filter_rest' ), 10, 3 );
		add_filter( 'oembed_response_data', array( $this, 'filter_oembed' ), 10, 2 );

		// ── SEO-plugin meta descriptions (auto-generated from content) ──
		foreach ( array( 'wpseo_metadesc', 'rank_math/frontend/description', 'aioseo_description', 'seopress_titles_desc' ) as $hook ) {
			add_filter( $hook, array( $this, 'filter_seo_description' ), 20 );
		}

		// ── singular-page response: structured data, cache headers, agent 402 ──
		add_action( 'wp_head', array( $this, 'emit_structured_data' ), 5 );
		add_action( 'template_redirect', array( $this, 'on_singular_premium' ), 0 );

		// ── front-end unlock app (WS3 3.4): loads on a sealed page for non-editors ──
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_unlock_app' ) );

		// ── invalidate the seal cache when a premium post is edited ──
		add_action( 'save_post', array( $this, 'bust_seal_cache' ), 10, 1 );
	}

	// ─── data-layer neutralization ────────────────────────────────────

	/**
	 * Blank the raw post_content of every gated post in a query result.
	 *
	 * @param WP_Post[] $posts
	 * @return WP_Post[]
	 */
	public function neutralize_query_posts( $posts ) {
		if ( is_array( $posts ) ) {
			foreach ( $posts as $post ) {
				if ( $post instanceof WP_Post ) {
					$this->neutralize( $post );
				}
			}
		}
		return $posts;
	}

	/**
	 * Blank the raw post_content of the current loop post (covers setup_postdata
	 * paths that don't run through the_posts).
	 *
	 * @param WP_Post $post
	 * @return void
	 */
	public function neutralize_loop_post( $post ) {
		if ( $post instanceof WP_Post ) {
			$this->neutralize( $post );
		}
	}

	/**
	 * Replace a gated post object's raw post_content with the preview, capturing
	 * the original split FIRST (so seal-on-serve still has the body). Idempotent.
	 *
	 * @param WP_Post $post
	 * @return void
	 */
	private function neutralize( WP_Post $post ) {
		$id = (int) $post->ID;
		if ( ! $this->is_gated( $id ) ) {
			return;
		}
		// Memoize the split from the ORIGINAL bytes before blanking anything.
		if ( ! isset( $this->parts[ $id ] ) ) {
			$this->parts[ $id ] = CrawlerToll_Cut::split( (string) $post->post_content );
		}
		// Mutate ONLY this request-local loop/query instance (NOT the get_post()
		// cache via wp_cache_set — that would persist the preview into a Redis/
		// Memcached object cache and corrupt the post for editors + the seal logic
		// on later requests). This closes in-loop get_the_content()/do_blocks(),
		// which read $GLOBALS['post']. See the class docblock for the documented
		// residual (an explicit get_post($id) raw render outside the loop).
		$post->post_content = $this->preview_html( $id );
	}

	// ─── per-post gate core ───────────────────────────────────────────

	/**
	 * Is this post sealed for the current viewer? Premium AND the current user
	 * cannot edit it (editors/authors see full content). Per-post, so it is
	 * correct inside loops/feeds/REST that process many posts.
	 *
	 * @param int $post_id
	 * @return bool
	 */
	private function is_gated( $post_id ) {
		$post_id = (int) $post_id;
		return $post_id > 0
			&& CrawlerToll_Cut::is_premium( $post_id )
			&& ! current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Memoized split() of a post's raw content.
	 *
	 * @param int $post_id
	 * @return array
	 */
	private function parts( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! isset( $this->parts[ $post_id ] ) ) {
			$this->parts[ $post_id ] = CrawlerToll_Cut::split( (string) get_post_field( 'post_content', $post_id ) );
		}
		return $this->parts[ $post_id ];
	}

	/**
	 * The cleartext preview HTML for a post (balanced so it can't leave an open
	 * tag that swallows page chrome). NEVER contains the sealed body.
	 *
	 * @param int $post_id
	 * @return string
	 */
	private function preview_html( $post_id ) {
		$preview = $this->parts( $post_id )['preview'];
		return function_exists( 'force_balance_tags' ) ? force_balance_tags( $preview ) : $preview;
	}

	/**
	 * A plain-text preview snippet for excerpts/descriptions (never body bytes).
	 *
	 * @param int $post_id
	 * @param int $words
	 * @return string
	 */
	private function preview_snippet( $post_id, $words = 55 ) {
		return wp_trim_words( wp_strip_all_tags( $this->parts( $post_id )['preview'] ), $words, '…' );
	}

	// ─── content surface filters ──────────────────────────────────────

	/**
	 * the_content — the #1 surface. Premium+non-editor: preview only; on the
	 * singular main post, append the lock UI + sealed marker + embedded blob.
	 *
	 * @param string $content
	 * @return string
	 */
	public function filter_content( $content ) {
		$id = (int) get_the_ID();
		if ( ! $this->is_gated( $id ) ) {
			return $content;
		}
		$preview = $this->preview_html( $id );
		if ( is_singular() && is_main_query() && in_the_loop() && $id === (int) get_queried_object_id() ) {
			$preview .= $this->locked_section( $id );
		}
		return $preview;
	}

	/**
	 * get_the_excerpt — archives, search snippets, related-post widgets, oEmbed.
	 * A manual author excerpt is a deliberate teaser (kept); an auto excerpt is
	 * generated from the body, so replace it with a preview snippet.
	 *
	 * @param string       $text
	 * @param WP_Post|null $post
	 * @return string
	 */
	public function filter_excerpt( $text, $post = null ) {
		$id = $post ? (int) ( is_object( $post ) ? $post->ID : $post ) : (int) get_the_ID();
		if ( ! $this->is_gated( $id ) ) {
			return $text;
		}
		$manual = (string) get_post_field( 'post_excerpt', $id );
		if ( '' !== trim( $manual ) ) {
			return $text; // author-written teaser
		}
		return $this->preview_snippet( $id );
	}

	/**
	 * the_excerpt_rss — RSS <description>. Force preview regardless of the site's
	 * full-text/summary feed setting for premium posts.
	 *
	 * @param string $excerpt
	 * @return string
	 */
	public function filter_excerpt_rss( $excerpt ) {
		$id = (int) get_the_ID();
		return $this->is_gated( $id ) ? $this->preview_snippet( $id ) : $excerpt;
	}

	/**
	 * the_content_feed — RSS2/Atom <content:encoded> ships the FULL body and
	 * bypasses the web the_content chain, so it needs its own gate.
	 *
	 * @param string $content
	 * @param string $feed_type
	 * @return string
	 */
	public function filter_content_feed( $content, $feed_type = '' ) {
		$id = (int) get_the_ID();
		if ( ! $this->is_gated( $id ) ) {
			return $content;
		}
		return $this->preview_html( $id ) . "\n<p><a href=\"" . esc_url( get_permalink( $id ) ) . '">' . esc_html__( 'Read the rest on the site →', 'crawlertoll' ) . '</a></p>';
	}

	/**
	 * REST wp/v2/posts — content.rendered + excerpt.rendered ship the full body
	 * to headless/Gutenberg clients; raw fields (edit context) blanked for
	 * non-editors defensively.
	 *
	 * @param WP_REST_Response $response
	 * @param WP_Post          $post
	 * @param WP_REST_Request  $request
	 * @return WP_REST_Response
	 */
	public function filter_rest( $response, $post, $request ) {
		if ( ! ( $post instanceof WP_Post ) || ! $this->is_gated( $post->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			$data['content']['rendered']  = $this->preview_html( $post->ID );
			$data['content']['protected'] = true;
			if ( isset( $data['content']['raw'] ) ) {
				$data['content']['raw'] = '';
			}
		}
		if ( isset( $data['excerpt'] ) && is_array( $data['excerpt'] ) ) {
			$data['excerpt']['rendered'] = wpautop( $this->preview_snippet( $post->ID ) );
			if ( isset( $data['excerpt']['raw'] ) ) {
				$data['excerpt']['raw'] = '';
			}
		}
		$response->set_data( $data );
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		return $response;
	}

	/**
	 * oEmbed JSON another site embeds.
	 *
	 * @param array   $data
	 * @param WP_Post $post
	 * @return array
	 */
	public function filter_oembed( $data, $post ) {
		if ( ( $post instanceof WP_Post ) && $this->is_gated( $post->ID ) ) {
			$data['description'] = $this->preview_snippet( $post->ID, 40 );
		}
		return $data;
	}

	/**
	 * SEO-plugin meta description / og:description on the singular premium page.
	 *
	 * @param string $description
	 * @return string
	 */
	public function filter_seo_description( $description ) {
		if ( is_singular() ) {
			$id = (int) get_queried_object_id();
			if ( $this->is_gated( $id ) ) {
				return $this->preview_snippet( $id, 30 );
			}
		}
		return $description;
	}

	// ─── singular-page response ───────────────────────────────────────

	/**
	 * On a singular premium page for a non-editor: fail-closed cache headers and,
	 * for a catalogued AI agent, a 402 + the machine offer headers (the preview
	 * HTML still renders — same bytes a human sees, so no cloaking).
	 *
	 * @return void
	 */
	public function on_singular_premium() {
		if ( ! is_singular() ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( $id <= 0 || ! CrawlerToll_Cut::is_premium( $id ) ) {
			return;
		}

		// Premium pages must never be stored by a shared/page/CDN cache — incl. an
		// editor's full view (it must not be handed to the next anonymous visitor).
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}

		if ( current_user_can( 'edit_post', $id ) ) {
			return; // editor: full content, but still uncacheable (set above)
		}

		// Agent: emit the machine offer (402 + price + key-release Link). The body
		// is absent regardless; the preview HTML renders normally.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! CrawlerToll_SafeMode::is_safe( $ua ) && null !== CrawlerToll_Bot_Catalogue::match( $ua ) ) {
			$blob = $this->ensure_sealed( $id );
			if ( false !== $blob ) {
				$settings = crawlertoll_get_settings();
				$host     = wp_parse_url( home_url(), PHP_URL_HOST );
				$cid      = CrawlerToll_Sealed_Gate::build_content_id( $host, $id );
				$base     = defined( 'CRAWLERTOLL_REGISTRY_URL' ) ? CRAWLERTOLL_REGISTRY_URL : CrawlerToll_Registry::REGISTRY_URL;
				status_header( 402 );
				header( sprintf( 'Crawler-Price: %d micros %s', (int) $settings['price_micros'], $settings['currency'] ) );
				header( 'Crawler-Price-Rail: ' . $settings['rail'] );
				header( sprintf( 'Link: <%s>; rel="payment"; type="ct-sealed-key"', $base . '/v1/sealed/' . $cid . '/key' ) );
			}
		}
	}

	/**
	 * JSON-LD paywall structured data on the singular premium page. Same markup a
	 * human and a crawler see (no cloaking); tells search engines the body is
	 * paywalled so the preview is indexed without the body.
	 *
	 * @return void
	 */
	public function emit_structured_data() {
		if ( ! is_singular() ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( $id <= 0 || ! $this->is_gated( $id ) ) {
			return;
		}
		$ld = array(
			'@context'            => 'https://schema.org',
			'@type'               => 'Article',
			'isAccessibleForFree' => false,
			'hasPart'             => array(
				'@type'               => 'WebPageElement',
				'isAccessibleForFree' => false,
				'cssSelector'         => '.' . self::MARKER_CLASS,
			),
			'headline'            => wp_strip_all_tags( get_the_title( $id ) ),
			'url'                 => get_permalink( $id ),
			'datePublished'       => get_post_time( 'c', true, $id ),
			'publisher'           => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
			),
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $ld ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Enqueue the front-end unlock app on a sealed page for a non-editor with a
	 * sealable body. The app mounts into the locked region (.ct-sealed-body),
	 * reads the inline blob, drives the rail menu → payment → key-release →
	 * WebCrypto decrypt. Free-safe (ships in the free build). The inline seam
	 * carries the registry base + the Stripe publishable key (if configured).
	 *
	 * @return void
	 */
	public function enqueue_unlock_app() {
		if ( ! is_singular() ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( $id <= 0 || ! $this->is_gated( $id ) ) {
			return;
		}
		if ( empty( $this->parts( $id )['has_body'] ) ) {
			return; // nothing sealed → no unlock UI
		}
		if ( CrawlerToll_Vite::enqueue( 'unlock', 'crawlertoll-unlock-app' ) ) {
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
		}
	}

	// ─── seal-on-serve (fail-closed) ──────────────────────────────────

	/**
	 * The locked-region markup appended to the singular preview: the marker div
	 * (matched by the JSON-LD cssSelector), a human unlock placeholder (3.4
	 * hydrates it), and the sealed blob embedded inert (harmless without the CEK).
	 *
	 * @param int $post_id
	 * @return string
	 */
	private function locked_section( $post_id ) {
		$settings = crawlertoll_get_settings();
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$cid      = CrawlerToll_Sealed_Gate::build_content_id( $host, $post_id );

		$html  = '<div class="' . esc_attr( self::MARKER_CLASS ) . ' crawlertoll-locked"';
		$html .= ' data-content-id="' . esc_attr( $cid ) . '"';
		$html .= ' data-price-micros="' . esc_attr( (string) (int) $settings['price_micros'] ) . '"';
		$html .= ' data-currency="' . esc_attr( $settings['currency'] ) . '"';
		$html .= ' data-rail="' . esc_attr( $settings['rail'] ) . '">';
		$html .= '<p>' . esc_html__( 'The rest of this content is available with a one-time unlock.', 'crawlertoll' ) . '</p>';
		$html .= '</div>';

		$blob = $this->ensure_sealed( $post_id );
		if ( false !== $blob ) {
			$html .= '<script type="application/ct-sealed+json">' . wp_json_encode( $blob ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return $html;
	}

	/**
	 * Seal the BODY half and escrow its CEK with the registry, caching the blob in
	 * post meta keyed on sha256(body). FAIL-CLOSED: any failure returns false and
	 * NOTHING (no blob) is served — the preview is still shown, the body never is.
	 * The CEK is discarded; only the ciphertext blob is persisted.
	 *
	 * @param int $post_id
	 * @return string|false The sealed blob (JSON-encodable array) or false.
	 */
	private function ensure_sealed( $post_id ) {
		$post_id = (int) $post_id;
		if ( isset( $this->blob[ $post_id ] ) ) {
			return $this->blob[ $post_id ];
		}

		$parts = $this->parts( $post_id );
		if ( empty( $parts['has_body'] ) ) {
			return $this->blob[ $post_id ] = false; // nothing separable to seal
		}

		$body = $parts['body'];
		$hash = hash( 'sha256', $body );

		$cached = get_post_meta( $post_id, self::SEAL_META, true );
		if ( is_array( $cached ) && isset( $cached['hash'], $cached['blob'] ) && $cached['hash'] === $hash ) {
			return $this->blob[ $post_id ] = $cached['blob'];
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$cid  = CrawlerToll_Sealed_Gate::build_content_id( $host, $post_id );

		try {
			list( $cek_b64, $blob ) = CrawlerToll_Sealed::seal( $body, $cid );
		} catch ( Exception $e ) {
			return $this->blob[ $post_id ] = false; // crypto unavailable → fail closed
		}

		$settings = crawlertoll_get_settings();
		$registry = new CrawlerToll_Registry();
		$res      = $registry->register_sealed( $cid, $cek_b64, (int) $settings['price_micros'], $settings['currency'], 'premium' );
		if ( is_wp_error( $res ) || empty( $res['status'] ) || 'registered' !== $res['status'] ) {
			// Never serve a blob whose CEK the escrow didn't store — fail closed.
			return $this->blob[ $post_id ] = false;
		}

		update_post_meta(
			$post_id,
			self::SEAL_META,
			array(
				'hash'          => $hash,
				'content_id'    => $cid,
				'blob'          => $blob,
				'registered_at' => current_time( 'mysql' ),
			)
		);
		return $this->blob[ $post_id ] = $blob;
	}

	/**
	 * Drop the seal cache when a post is edited (belt-and-suspenders alongside the
	 * sha256(body) hash-miss check).
	 *
	 * @param int $post_id
	 * @return void
	 */
	public function bust_seal_cache( $post_id ) {
		delete_post_meta( (int) $post_id, self::SEAL_META );
	}
}
