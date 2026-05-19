# Changelog

All notable changes to the CrawlerToll WordPress plugin are documented here. Follows [Semantic Versioning](https://semver.org/).

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
