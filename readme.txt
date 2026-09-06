=== CrawlerToll ===
Contributors: charthouse
Tags: ai-crawler, gptbot, claudebot, pay-per-crawl, http-402, x402, rsl, robots-txt, bot-blocker, ai-content-licensing, perplexitybot, context-license
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.0
License: Apache-2.0 OR GPL-2.0-or-later
License URI: https://www.apache.org/licenses/LICENSE-2.0

The paywall where the content itself is the lock — payable by card or by AI agent. Recognises 30 declared AI-crawler user-agents, applies RSL 1.0 policy, issues HTTP 402, and seals premium content so it only unlocks against a payment made directly to you.

== Description ==

CrawlerToll is the open-source WordPress plugin for the AI-crawler economy. On every front-end request, it:

1. **Recognises** 30 declared AI-crawler user-agents from a curated catalogue — GPTBot, ChatGPT-User, ClaudeBot, Claude-User, Google-Extended, Applebot-Extended, PerplexityBot, Meta-ExternalAgent, Bytespider, CCBot, Cohere, Mistral, You.com, Diffbot, Bright Data, and more.
2. **Applies** your [RSL 1.0](https://rslstandard.org/) robots.txt policy — `License:`, `Permits:`, `Prohibits:`, `Compensation:`, `Standard: RSL/1.0` directives — to the request path.
3. **Issues** HTTP 402 with Cloudflare-shape `Crawler-Price`, `Crawler-Price-Rail`, and `Link` headers plus a structured JSON payment offer when policy says so. Or 403 (block) when policy disallows without compensation. Or passes through with `X-CrawlerToll-Action`, `X-CrawlerToll-Operator`, `X-CrawlerToll-Bot-Name` headers for downstream logging.

Plus two discovery endpoints AI crawlers look for:

* `/robots.txt` — your RSL 1.0 policy, appended automatically via WordPress's standard `robots_txt` filter
* `/.well-known/context-license.json` — machine-readable buyer-side discovery metadata, built from your settings + site info

And for the content you mark **premium**, it seals the article body: the text is AES-256-GCM encrypted on the page, and the key is released only against a settled payment. Readers pay by card, Apple Pay or Google Pay on **your own Stripe account**; AI agents pay in USDC over the open x402 protocol straight to **your own wallet**. CrawlerToll never touches the money and takes no cut.

CrawlerToll is **vendor-neutral** and standards-based — HTTP 402, RSL 1.0, x402 — and runs on any host or CDN.

= What this plugin does NOT do =

* **Hold your money.** Card payments settle on your Stripe account and USDC lands in your wallet. There is no CrawlerToll balance, payout, or fee.
* **Replace Cloudflare Pay Per Crawl.** If you have access to PPC's closed beta on a Cloudflare Enterprise plan, you can use both — PPC at the CDN tier, this plugin at the WordPress application tier for finer-grained policy.
* **Identify scrapers that hide.** User-agent recognition only covers crawlers that declare themselves; a headless browser pretending to be a person is invisible to any plugin. That is why sealing exists: on premium content an unidentified scraper receives the encrypted body, not the article. Edge tools such as Cloudflare Bot Management handle the rest of your site.

= CrawlerToll Pro =

The free plugin recognises declared crawlers, applies your policy, issues 402s, and seals premium content that unlocks by card or USDC — at one flat price per site. **CrawlerToll Pro** turns that into a revenue workflow:

* **Per-path pricing** — charge more for premium sections, less for the rest.
* **Per-crawler rail routing** — send different AI crawlers to different settlement rails.
* **Revenue dashboard** — totals, trends, and your top crawlers and paths.
* **Bot-request logs** — every decision, filterable, with CSV/JSON export.
* **Email alerts** — daily and weekly summaries plus spike detection.
* **Content provenance** — SHA-256 fingerprints of exactly what each crawler received.
* **Automatic log retention** — cleanup on a window you choose.
* **Access tiers and bundles** — price × duration passes per section, or a whole-section pass.
* **Metered free articles and email-gated access** — N free reads per month, or unlock for a verified email address.
* **Unlock webhooks** — signed `unlock.succeeded` events to your own systems.

The free recognition, 402 enforcement and sealed paywall stay fully functional on their own. Learn more at [crawlertoll.com](https://crawlertoll.com).

= External services =

Crawler recognition, RSL policy and plain 402 responses run entirely on your server. The sealed paywall and the payment rails use these services. None of them ever receives your article text in the clear.

* **CrawlerToll unlock service (registry.crawlertoll.com)** — the key escrow. When a premium post is first viewed the plugin sends it the post's content key, content id (your host + post id), price and pricing rules; readers' browsers contact it to fetch the price offer and, after paying, the key. It records unlock receipts (content id, rail, transaction reference) that you can read back in Settings. It never receives the article text, card numbers, or wallet keys. Operated by Charthouse Ltd. Privacy: https://crawlertoll.com/privacy
* **Stripe** — only when you enter your own Stripe keys. The plugin creates and verifies PaymentIntents on **your** Stripe account, and readers' browsers load Stripe.js (js.stripe.com) to show the card form. Terms: https://stripe.com/legal · Privacy: https://stripe.com/privacy
* **x402 facilitator (facilitator.xpay.sh by default)** — contacted by the unlock service, not by your site, to settle a reader's or agent's signed USDC authorisation on-chain into your wallet. Publishers can choose a different facilitator.
* **Freemius** — license validation, checkout, and Pro updates. When you activate a Pro license the plugin contacts Freemius (the licensing provider and merchant of record). After you opt in (optional and skippable), Freemius may also collect anonymous environment data such as your WordPress and PHP versions to improve the product. Terms: https://freemius.com/terms/ · Privacy: https://freemius.com/privacy/
* **data.crawlertoll.com** — the optional auto-updating bot catalogue. When enabled, the plugin periodically fetches the latest AI-crawler list (`bots.json`) via a plain HTTP GET. No site or visitor data is sent. Privacy: https://crawlertoll.com/privacy

= Why CrawlerToll exists =

The AI-crawler-monetization space consolidated around **standards** in 2025–2026: RSL 1.0 (Reddit, Yahoo, People Inc., Medium, Quora, O'Reilly, Stack Overflow, Cloudflare), HTTP 402, Web Bot Auth, x402. CrawlerToll implements those standards in a vendor-neutral OSS library, shipping framework adapters for Node (Express, Fastify, Hono, Next.js) and now WordPress.

= Companion packages =

* `@crawlertoll/core` — the JavaScript core, audit-friendly
* `@crawlertoll/express`, `@crawlertoll/fastify`, `@crawlertoll/hono`, `@crawlertoll/next` — framework adapters
* `crawlertoll-cloudflare-template` — fork-and-deploy CF Workers template
* `crawlertoll-vercel-template` — fork-and-deploy Vercel Edge template

The WordPress plugin is the first shipping adapter; the JS packages are in open development at [crawlertoll.com](https://crawlertoll.com).

== Installation ==

1. Upload the `crawlertoll` folder to `/wp-content/plugins/`, or install via the Plugins → Add New menu.
2. Activate through the 'Plugins' menu in WordPress.
3. Go to **Settings → CrawlerToll**.
4. Review the default RSL 1.0 policy (it ships with the 8 most-common AI crawlers blocked + Compensation: per-crawl 5000 micros USD). Adjust price, currency, rail, and policy to fit your site.
5. Save.

To sell content to people, mark a post **Premium** in the editor, place the cut where the free preview ends, and enter your Stripe keys and/or USDC address under Payments.

The plugin starts enforcing immediately. Test with:

`curl -sI -H 'user-agent: GPTBot/1.2' https://your-site.example/`

You should see a `402 Payment Required` response with the `Crawler-Price` header.

== Frequently Asked Questions ==

= Is there a Pro version? =

Yes. CrawlerToll Pro adds per-path pricing, access tiers and bundles, metered free articles, email-gated access, unlock webhooks, per-crawler rail routing, a revenue dashboard, filterable bot-request logs with CSV/JSON export, email alerts, content provenance, and automatic log retention. The free plugin's recognition, 402 enforcement and sealed paywall remain fully functional without it. See [crawlertoll.com](https://crawlertoll.com).

= Do I have to invoice anyone? =

No — payment settles *before* access. When a crawler or reader pays, the money lands directly in your own Stripe account or wallet, and only then is the content unlocked. There is no credit, no monthly billing cycle, and nothing to chase.

Every paid unlock leaves a receipt you can read back in **Settings → CrawlerToll** (recent unlocks; Pro adds full history with CSV export). On the Stripe rail, Stripe's own receipts, invoices and tax reports apply automatically, since payments run through your account. On the x402 rail, the on-chain transaction hash is the receipt, linked in the unlock record to the exact article and time.

= Will this block Google or Bing from crawling my site? =

No. The default policy targets specifically *AI* crawlers — GPTBot, ClaudeBot, PerplexityBot, Google-Extended (Google's training-data crawler, *not* Googlebot), Applebot-Extended (Apple's training crawler, *not* Applebot), etc. Search-engine crawlers continue to index your site normally unless you explicitly add them to the policy.

= Does it work with Cloudflare Pay Per Crawl? =

Yes — they're complementary. Pay Per Crawl runs at Cloudflare's edge and only for Cloudflare customers who have it; CrawlerToll runs inside WordPress on any host, and adds the sealed paywall, the card rail and the human-reader unlock that the edge does not provide. You can use both, or just one.

= Does it work with TollBit / Skyfire? =

Not as settlement rails. If you already use a hosted paywall, choose the **custom payment URL** rail and the 402 response will point crawlers at it via a `Link: <…>; rel="payment"` header; sealing and the built-in rails are not required.

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

= 2.0.0 =

* Ground-up rebuild of the whole product. Configuration from 1.x does not carry over — after updating, open Settings → CrawlerToll and set pricing, payment rails and content rules again.
* Sealed-content engine: protected post bodies are AES-256-GCM encrypted; the key releases only against a settled payment.
* Unlock app: readers pay by card on your own Stripe account, or in USDC (x402) to your own wallet, and decrypt in place; unlocked access persists across reloads.
* Key escrow + settlement via the CrawlerToll registry (x402 V1 + V2, PAYMENT-* headers).
* Recent unlocks: every paid unlock leaves a receipt you can read back in Settings → CrawlerToll.
* Fail-closed cache safety for protected pages.

= 0.2.0 =

* Sealed-content engine: premium post bodies are AES-256-GCM encrypted; the key releases only against a settled payment.
* Unlock app: readers pay by card on your own Stripe account, or in USDC (x402) to your own wallet, and decrypt in place; unlocked access persists across reloads.
* Key escrow + settlement via the CrawlerToll registry (x402 V1 + V2, PAYMENT-* headers).
* Fail-closed cache safety for premium pages.

= 0.1.1 — 2026-05-21 =

* New modern admin dashboard UI with status cards, bot catalogue browser, and live curl tester.
* Performance: admin assets enqueued only on the CrawlerToll settings page.
* Improved toggle switch for enable/disable.
* Bot catalogue shows all 30 tracked crawlers with category colour coding and filter.

= 0.1.0 — 2026-05-19 =

* Initial public release.
* 30 AI-crawler User-Agent catalogue.
* RSL 1.0 robots.txt parser + matcher.
* HTTP 402 issuance with Cloudflare-shape headers + structured JSON offer.
* `/robots.txt` augmentation via the standard `robots_txt` filter.
* `/.well-known/context-license.json` REST endpoint + clean rewrite.
* Admin settings page under Settings → CrawlerToll.
* Dual-licensed Apache-2.0 + GPL-2.0-or-later.

== Upgrade Notice ==

= 2.0.0 =

Ground-up rebuild. 1.x settings do not carry over — reconfigure pricing, payment rails and content rules in Settings → CrawlerToll after updating.

= 0.1.0 =

Initial release.
