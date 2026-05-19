# CrawlerToll for WordPress

**AI-crawler enforcement for WordPress.** Detects 30+ AI crawlers, applies your RSL 1.0 policy, and issues HTTP 402 with a structured payment offer. Vendor-neutral; works with TollBit, Skyfire, x402, Cloudflare Pay Per Crawl, and Stripe ACP.

- **License**: Apache-2.0 OR GPL-2.0-or-later (dual-licensed — WordPress.org plugin distribution requires GPL compatibility; Apache 2.0 has the patent grant the Node packages also ship under)
- **WordPress**: 6.0+
- **PHP**: 7.4+

---

## Install

### From wordpress.org

1. WordPress admin → Plugins → Add New → search "CrawlerToll"
2. Install and activate
3. Settings → CrawlerToll → review the default policy and save

### From this repo (development)

```bash
cd wp-content/plugins
git clone https://github.com/nhrzxxw9dn-web/crawlertoll-wp crawlertoll
```

Then activate via the WordPress admin.

---

## Sixty seconds

After activation, the plugin starts enforcing immediately with sensible defaults:

- 8 AI crawler User-Agents blocked by default (GPTBot, ClaudeBot, PerplexityBot, CCBot, Google-Extended, Applebot-Extended, Meta-ExternalAgent, Bytespider)
- Compensation declared at 5,000 micros USD ($0.005) per crawl
- `/wp-content/uploads/` always allowed (so AI-overview images keep working)
- `*` catch-all is `Disallow:` (i.e. all other crawlers pass through)

Test it:

```bash
curl -sI -H 'user-agent: GPTBot/1.2' https://your-site.example/
# → HTTP/2 402
# → crawler-price: 5000 micros USD
# → crawler-price-rail: x402
# → link: <...>; rel="describedby"; ...
```

Browsers pass through normally.

---

## What it does

| On every request | Action |
|---|---|
| Match the User-Agent against the curated catalogue (30+ operators) | Cheap substring check, sub-millisecond |
| If matched: parse the RSL 1.0 policy from settings, find the right agent group | In-memory parse, no I/O |
| Apply Allow/Disallow precedence to the request path (longest-match wins, Allow ties beat Disallow per RFC 9309) | |
| If blocked + Compensation declared → emit HTTP 402 with structured offer | `Crawler-Price` / `Crawler-Price-Rail` / `Link rel="payment"` / `Link rel="describedby"` headers |
| If blocked + no compensation → emit 403 | |
| Otherwise → pass through with `X-CrawlerToll-Action` / `Operator` / `Bot-Name` headers for logging |

Plus two discovery endpoints AI crawlers look for:

- `/robots.txt` — your RSL 1.0 policy, automatically appended via WordPress's standard `robots_txt` filter
- `/.well-known/context-license.json` — machine-readable buyer-side discovery metadata

---

## Settings reference

**Settings → CrawlerToll** in the WordPress admin. Five fields:

| Field | Type | Default | What |
|---|---|---|---|
| `enabled` | checkbox | on | Turn enforcement on/off without uninstalling |
| `price_micros` | number | 5000 | Price in micros (5000 = $0.005 per crawl) |
| `currency` | dropdown | USD | USD / USDC / EUR / GBP |
| `rail` | dropdown | x402 | x402 / tollbit / skyfire / cloudflare-ppc / stripe-acp / context-license / custom |
| `payment_url` | URL | (empty) | The settlement-rail-specific paywall URL. Surfaced as `Link rel="payment"`. Optional for x402 (wallet-native). |
| `terms_url` | URL | (empty) | Your AI-terms page. Surfaced as `Link rel="terms-of-service"`. |
| `policy` | textarea | (default RSL) | Raw RSL 1.0 robots.txt directives. Applied per-request. |

The policy textarea uses standard robots.txt syntax extended with the RSL 1.0 directives. See the [RSL 1.0 spec](https://rslstandard.org/) for the directive vocabulary.

---

## Customising the policy

Default policy that ships with the plugin:

```
User-agent: GPTBot
User-agent: ClaudeBot
User-agent: PerplexityBot
User-agent: CCBot
User-agent: Google-Extended
User-agent: Applebot-Extended
User-agent: Meta-ExternalAgent
User-agent: Bytespider
Disallow: /
Allow: /wp-content/uploads/
License: https://your-site.example/ai-license
Permits: ai-search, rag
Prohibits: ai-training, redistribution-without-attribution
Compensation: per-crawl 5000 micros USD
Standard: RSL/1.0

User-agent: *
Disallow:
```

To allow GPTBot to read your `/blog/*` but charge for `/articles/*`:

```
User-agent: GPTBot
Allow: /blog
Disallow: /articles
Compensation: per-crawl 10000 micros USD
Standard: RSL/1.0
```

To block Common Crawl entirely:

```
User-agent: CCBot
Disallow: /
```

(Note: no `Compensation:` directive → 403 block, not 402 charge.)

---

## How it integrates with WordPress

| Surface | Plugin behaviour |
|---|---|
| `parse_request` action | The decision tree runs as early as possible — before any content rendering, before the database is even queried. Short-circuit cost is sub-millisecond. |
| `robots_txt` filter | RSL directives are appended to WordPress's existing `/robots.txt`. |
| REST API | `/wp-json/crawlertoll/v1/context-license` returns the structured discovery JSON. |
| Rewrite rule | `/.well-known/context-license.json` proxies to the REST endpoint cleanly. |
| Admin menu | Settings → CrawlerToll |
| `wp_options` | Single key `crawlertoll_settings` stores all configuration |
| Activation | Defaults written; rewrite rules flushed |
| Deactivation | Rewrite rules flushed |
| Uninstall | Settings removed |

---

## Compatibility

- **WP Super Cache / W3 Total Cache / WP Rocket**: works. The plugin returns 402 *before* the cache layer runs, so cached pages aren't served to bots that should be 402'd.
- **Cloudflare APO**: works. Cloudflare caches at the edge; the plugin's 402 responses are served from WP origin only when bots reach origin (which is what you want for accuracy).
- **Multisite**: works per-site. Each site reads its own `crawlertoll_settings`.
- **REST API**: skipped — the plugin doesn't enforce on `/wp-json/*` because that surface is typically authenticated API traffic, not crawler-facing.
- **WP CLI / cron / xmlrpc**: skipped.

---

## Companion packages

Same `decide()` engine, different deployment surface:

- [`@crawlertoll/core`](https://www.npmjs.com/package/@crawlertoll/core) — JavaScript core (vendor-neutral)
- [`@crawlertoll/express`](https://www.npmjs.com/package/@crawlertoll/express) — Node, Express 4+5
- [`@crawlertoll/fastify`](https://www.npmjs.com/package/@crawlertoll/fastify) — Node, Fastify 4+5
- [`@crawlertoll/hono`](https://www.npmjs.com/package/@crawlertoll/hono) — Cloudflare Workers, Bun, Deno, Vercel Edge, Node
- [`@crawlertoll/next`](https://www.npmjs.com/package/@crawlertoll/next) — Next.js 14+15
- [`@crawlertoll/publisher`](https://www.npmjs.com/package/@crawlertoll/publisher) — Publisher CLI + SDK
- [`crawlertoll-cloudflare-template`](https://github.com/nhrzxxw9dn-web/crawlertoll-cloudflare-template) — Fork-and-deploy CF Workers template
- [`crawlertoll-vercel-template`](https://github.com/nhrzxxw9dn-web/crawlertoll-vercel-template) — Fork-and-deploy Vercel Edge template

---

## Licensing

This plugin is dual-licensed under **Apache-2.0 OR GPL-2.0-or-later**.

- **WordPress.org distribution** requires GPL-compatibility — GPL-2.0-or-later satisfies this.
- **Apache 2.0** ships with the patent grant the Node packages also ship under, useful for regulated industries.
- Choose either license at your option.

See [LICENSE](./LICENSE) (Apache 2.0) and [LICENSE-GPL](./LICENSE-GPL) (GPL 2.0).

---

## Resources

- **Marketplace**: [crawlertoll.com](https://crawlertoll.com)
- **RSL 1.0 spec**: [rslstandard.org](https://rslstandard.org/)
- **HTTP 402** (Cloudflare): [pay-per-crawl blog post](https://blog.cloudflare.com/introducing-pay-per-crawl/)
- **x402 Foundation**: [x402.org](https://www.x402.org/)
