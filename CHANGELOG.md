# Changelog

All notable changes to the CrawlerToll WordPress plugin are documented here. Follows [Semantic Versioning](https://semver.org/).

## [Unreleased] — TOTAL gate (agents-only, sealed content)

> **LOCAL / not for upload.** Adds external HTTP (registry escrow registration), which contradicts the wp.org v1 (0.1.x) "no external calls" review note. Do **not** upload the working tree until v1 is approved.

### Added

- **TOTAL gate** (`CrawlerToll_Sealed`, `CrawlerToll_Sealed_Gate`) — opt-in. When on, a declared AI crawler hitting a gated singular post receives **sealed `ct_sealed_v1` ciphertext** + a 402 with a registry key-release `Link`, instead of a bare 402. AES-256-GCM, AAD = content_id, byte-compatible with the Python (`specapi/ct/sealed.py`) and JS (`@crawlertoll/sdk`) implementations — proven by a PHP→JS parity test. Humans are unaffected (agents-only).
- **Registry escrow registration** (`CrawlerToll_Registry::register_sealed`) — seals locally, registers only the key (CEK) with the registry (`CRAWLERTOLL_REGISTRY_URL`-overridable); the registry holds the key, never the content. Sealed blobs cached in post meta, invalidated on content change.
- **Settings toggle** for the TOTAL gate.

### Verified

- PHP→JS `ct_sealed_v1` parity (cross-language unseal).
- End-to-end (deterministic): PHP-sealed → registry register → 402 → simulated pay → key release → unseal → original plaintext.
- Payment is **SIMULATED**. Manual wp-now QA (agent → sealed 402, human → normal page) still pending.

## [0.2.0] — 2026-05-21

### Added

- **Custom log table** (`{$wpdb->prefix}crawlertoll_log`) — dedicated storage for bot-request history. Indexed on `(bot_name, request_time)`, `(action, request_time)`, and `(content_hash)`. Created via `dbDelta()` on plugin activation. NOT stored in `wp_options` — avoids autoload bloat.
- **Bot-request logger** (`CrawlerToll_Logger`) — writes every decision (allow/402/block) to the custom table with bot identity, path, pricing, and HTTP status.
- **Content provenance** (`CrawlerToll_Provenance`) — SHA-256 hashing of response bodies via output buffering. Creates an irrefutable, timestamped record: "this specific content was on this server, requested by this bot, at this exact time." Hash stored alongside the log entry. `hash_content()` and `verify()` static helpers for programmatic use.
- **Safe Mode** (`CrawlerToll_SafeMode`) — guarantees Googlebot, Bingbot, YandexBot, DuckDuckBot, Applebot, and social-preview crawlers (Facebook, Twitter/X, LinkedIn, Slack, Discord, WhatsApp, Telegram) are NEVER blocked or charged, regardless of RSL policy. Non-configurable to prevent accidental SEO damage. Explicitly excludes Applebot-Extended (AI training crawler) from the safelist.
- **SEO Plugin Harmony** — respects `noindex` from Yoast SEO, Rank Math, All in One SEO, and SEOPress. Pages marked noindex never trigger a 402. Composes cleanly alongside existing SEO plugin configurations.
- **Per-path pricing** (`CrawlerToll_Pricing`) — path-specific price overrides with wildcard support (`/premium/*`). Longest-prefix wins. Falls back to the default price from settings. Revenue summary and period-over-period comparison helpers.
- **Database layer** (`CrawlerToll_DB`) — custom table CRUD, filtered/paginated queries, aggregate stats, content hash lookup, payment marking, retention-based purge, and clean table drop for uninstall.

### Changed

- `CrawlerToll_Plugin` now initialises `CrawlerToll_DB`, `CrawlerToll_Logger`, and `CrawlerToll_Provenance` in its constructor. Creates the log table on every load (idempotent).
- `on_parse_request` logs every bot decision and attaches provenance hashes via output buffering.
- `template_redirect` at priority -9999 starts output buffering for content hashing.
- Activation hook creates the log table. Version bumped to 0.2.0.

### Added

- **Modern admin dashboard UI.** Status cards showing enforcement state, crawler count, active policy groups, and per-crawl price. Toggle switch for enable/disable. Bot catalogue browser with category colour coding and text filter. Live curl tester to simulate crawler requests against the current policy. Admin CSS/JS enqueued only on the CrawlerToll settings page.

### Changed

- Admin class now enqueues `assets/admin.css` and `assets/admin.js` on the settings page.
- `render_page()` passes bot catalogue data and parsed policy stats to the view for the dashboard cards.

## [0.1.0] — 2026-05-19

Initial public release. Ships alongside the `@crawlertoll/*` npm family.

### Added

- **30+ AI crawler User-Agent catalogue** in PHP — OpenAI, Anthropic, Google, Apple, Perplexity, Meta, ByteDance, Common Crawl, Cohere, Mistral, You.com, Diffbot, Bright Data, and more. Same catalogue as `@crawlertoll/core`.
- **RSL 1.0 robots.txt parser + matcher** in PHP — `License:`, `Permits:`, `Prohibits:`, `Compensation:`, `Standard:` directives. Longest-match path precedence with Allow ties beating Disallow per RFC 9309.
- **HTTP 402 issuance** with Cloudflare-shape headers (`Crawler-Price`, `Crawler-Price-Rail`, `Retry-After`, `Link rel="payment"` / `rel="describedby"` / `rel="terms-of-service"`) and structured JSON payment offer body.
- **`/robots.txt` augmentation** via WordPress's standard `robots_txt` filter — your RSL directives are appended automatically.
- **`/.well-known/context-license.json`** served via REST API endpoint + clean rewrite. Built from your settings + site info.
- **Admin settings page** at Settings → CrawlerToll: enable toggle, price (micros), currency (USD/USDC/EUR/GBP), settlement rail (x402/tollbit/skyfire/cloudflare-ppc/stripe-acp/context-license/custom), payment URL, terms URL, RSL policy textarea.
- **Lifecycle hooks**: activation writes defaults, deactivation flushes rewrites, uninstall removes settings.
- **Per-request decision headers** — `X-CrawlerToll-Action`, `X-CrawlerToll-Operator`, `X-CrawlerToll-Bot-Name` set on every response for downstream logging.
- **Smart skip list** — REST, admin, ajax, cron, xmlrpc, and the discovery endpoints themselves bypass the decision tree.

### License

Dual-licensed Apache-2.0 OR GPL-2.0-or-later. WordPress.org plugin distribution requires GPL compatibility; Apache 2.0 ships with the patent grant the Node packages also ship under.

### Conformance

- Mirrors `@crawlertoll/core` decisions byte-for-byte for the same input.
- Web Bot Auth verification is intentionally omitted from this v0.1 (PHP shared-hosting environments often disable outbound HTTP, making JWKS fetch unreliable). The cheap UA + RSL gate is the value here; cryptographic-identity verification lives in the Node ecosystem.

### Roadmap

- **v0.2**: Sample bot-detection logs in the admin UI. Web Bot Auth verification (opt-in, requires `wp_remote_get` for JWKS fetch). Per-rail adapter settings.
- **v0.3**: Multisite network-level settings. Bulk policy import from a URL. CSV log export.
