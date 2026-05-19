=== CrawlerToll ===
Contributors: charthouse
Tags: ai, crawler, gptbot, claudebot, pay-per-crawl, rsl, robots-txt, http-402, web-bot-auth, monetization
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: Apache-2.0 OR GPL-2.0-or-later
License URI: https://www.apache.org/licenses/LICENSE-2.0

AI-crawler enforcement for WordPress. Detects AI crawlers, applies RSL 1.0 policy, issues HTTP 402 with a structured payment offer.

== Description ==

CrawlerToll is the open-source WordPress plugin for the AI-crawler economy. On every front-end request, it:

1. **Detects** AI crawlers via a curated catalogue of 30+ operators — GPTBot, ChatGPT-User, ClaudeBot, Claude-User, Google-Extended, Applebot-Extended, PerplexityBot, Meta-ExternalAgent, Bytespider, CCBot, Cohere, Mistral, You.com, Diffbot, Bright Data, and more.
2. **Applies** your [RSL 1.0](https://rslstandard.org/) robots.txt policy — `License:`, `Permits:`, `Prohibits:`, `Compensation:`, `Standard: RSL/1.0` directives — to the request path.
3. **Issues** HTTP 402 with Cloudflare-shape `Crawler-Price`, `Crawler-Price-Rail`, and `Link` headers plus a structured JSON payment offer when policy says so. Or 403 (block) when policy disallows without compensation. Or passes through with `X-CrawlerToll-Action`, `X-CrawlerToll-Operator`, `X-CrawlerToll-Bot-Name` headers for downstream logging.

Plus two discovery endpoints AI crawlers look for:

* `/robots.txt` — your RSL 1.0 policy, appended automatically via WordPress's standard `robots_txt` filter
* `/.well-known/context-license.json` — machine-readable buyer-side discovery metadata, built from your settings + site info

CrawlerToll is **vendor-neutral**. It ships adapters TO commercial backends (TollBit, Skyfire, x402, Cloudflare Pay Per Crawl, Stripe Agentic Commerce Protocol) but locks you to none. Use whichever settlement rail you've already chosen — or use the default `x402` stablecoin rail that needs no upfront integration.

= What this plugin does NOT do =

* **Settle payments.** It emits the 402 with a payment offer; actual settlement happens on whichever rail you chose.
* **Replace Cloudflare Pay Per Crawl.** If you have access to PPC's closed beta on a Cloudflare Enterprise plan, you can use both — PPC at the CDN tier, this plugin at the WordPress application tier for finer-grained policy.
* **Block scrapers that ignore robots.txt and don't identify themselves.** Headless-browser scraping (Bright Data, Oxylabs, etc.) requires edge-level enforcement that a WordPress plugin can't provide. CrawlerToll detects identified crawlers; Cloudflare Bot Management or similar handles the rest.

= Why CrawlerToll exists =

The AI-crawler-monetization space consolidated around **standards** in 2025–2026: RSL 1.0 (Reddit, Yahoo, People Inc., Medium, Quora, O'Reilly, Stack Overflow, Cloudflare), HTTP 402, Web Bot Auth, x402. CrawlerToll implements those standards in a vendor-neutral OSS library, shipping framework adapters for Node (Express, Fastify, Hono, Next.js) and now WordPress.

= Companion packages =

* `@crawlertoll/core` — the JavaScript core, audit-friendly
* `@crawlertoll/express`, `@crawlertoll/fastify`, `@crawlertoll/hono`, `@crawlertoll/next` — framework adapters
* `crawlertoll-cloudflare-template` — fork-and-deploy CF Workers template
* `crawlertoll-vercel-template` — fork-and-deploy Vercel Edge template

Find them all at [crawlertoll.com](https://crawlertoll.com) and on npm.

== Installation ==

1. Upload the `crawlertoll` folder to `/wp-content/plugins/`, or install via the Plugins → Add New menu.
2. Activate through the 'Plugins' menu in WordPress.
3. Go to **Settings → CrawlerToll**.
4. Review the default RSL 1.0 policy (it ships with the 8 most-common AI crawlers blocked + Compensation: per-crawl 5000 micros USD). Adjust price, currency, rail, and policy to fit your site.
5. Save.

The plugin starts enforcing immediately. Test with:

`curl -sI -H 'user-agent: GPTBot/1.2' https://your-site.example/`

You should see a `402 Payment Required` response with the `Crawler-Price` header.

== Frequently Asked Questions ==

= Will this block Google or Bing from crawling my site? =

No. The default policy targets specifically *AI* crawlers — GPTBot, ClaudeBot, PerplexityBot, Google-Extended (Google's training-data crawler, *not* Googlebot), Applebot-Extended (Apple's training crawler, *not* Applebot), etc. Search-engine crawlers continue to index your site normally unless you explicitly add them to the policy.

= Does it work with Cloudflare Pay Per Crawl? =

Yes — they're complementary. PPC runs at Cloudflare's edge (CDN tier); CrawlerToll runs at the WordPress application tier. You can use both, or just one. Set the `rail` to `cloudflare-ppc` in Settings → CrawlerToll if you want the 402 responses to advertise PPC as the settlement path.

= Does it work with TollBit / Skyfire? =

Yes. Set the `rail` to `tollbit` or `skyfire` and the `payment_url` to your TollBit/Skyfire-hosted paywall URL. The 402 response will include a `Link: <...>; rel="payment"; type="tollbit"` header pointing crawlers there.

= What if my site is on a multi-site network? =

The plugin works on each site independently. The settings page is per-site by default. Network-wide activation works but each site reads its own settings.

= How do I customise the policy? =

Edit the policy textarea in **Settings → CrawlerToll**. The policy uses standard robots.txt syntax extended with the RSL 1.0 directives `License:`, `Permits:`, `Prohibits:`, `Compensation:`, and `Standard:`. See the [RSL 1.0 spec](https://rslstandard.org/) for the directive vocabulary.

= Does it slow down my site? =

No — the decision tree runs on every request but is sub-millisecond (a UA substring check + an in-memory RSL parse). The policy is parsed once per request, not per-rule.

= How do I uninstall? =

Standard WordPress: Plugins → Deactivate → Delete. The plugin removes its own settings on uninstall. Your `/robots.txt` reverts to its pre-CrawlerToll form.

== Screenshots ==

1. The Settings → CrawlerToll admin page with the default policy.
2. Curl output showing a 402 response to a GPTBot User-Agent.

== Changelog ==

= 0.1.0 — 2026-05-19 =

* Initial public release.
* 30+ AI-crawler User-Agent catalogue.
* RSL 1.0 robots.txt parser + matcher.
* HTTP 402 issuance with Cloudflare-shape headers + structured JSON offer.
* `/robots.txt` augmentation via the standard `robots_txt` filter.
* `/.well-known/context-license.json` REST endpoint + clean rewrite.
* Admin settings page under Settings → CrawlerToll.
* Dual-licensed Apache-2.0 + GPL-2.0-or-later.

== Upgrade Notice ==

= 0.1.0 =

Initial release.
