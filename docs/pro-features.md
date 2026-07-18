# CrawlerToll Pro — User Guide

> **Note for maintainers:** this `docs/` folder is developer/website source. It is
> **excluded from the wp.org free build** (alongside `tests/` and `e2e/`). It
> documents the **Pro** features, which ship only in the Freemius premium build.

CrawlerToll's free core detects 30+ AI crawlers, applies your RSL 1.0 policy, and
issues HTTP 402 — for one flat price, on every matching path. **Pro** turns that
flat enforcement into a revenue tool: price paths differently, route payments per
crawler, watch the money, and get alerted when something changes.

## Activating Pro

1. In WordPress, go to **Plugins → CrawlerToll → Activate License** (or start the
   14-day trial — no card required).
2. Paste your license key and click **Agree & Activate**.
3. The Pro tabs appear under **Settings → CrawlerToll**: **Pricing**, **Rails**,
   **Revenue**, and **Logs**.

Your enforcement policy lives where it always did, on the **Settings** tab. Pro
adds the tabs below; none of them change *whether* a crawler is charged (your RSL
policy decides that) — they change *how much*, *how* it settles, and *what you see*.

---

## Per-path pricing

**Settings → CrawlerToll → Pricing**

By default every charged crawl costs the same flat price (the *Compensation* in
your policy, e.g. `5000 micros USD`). Per-path pricing lets you charge **more for
premium content and less for low-value pages**.

### How rules work

Each rule is a **path prefix** and a **price**. When a crawler is about to be
charged, CrawlerToll finds the rule whose prefix matches the request path and uses
its price. If two rules match, **the longer (more specific) one wins** — the same
"longest match" logic browsers use for `robots.txt`.

| Rule path     | Matches                              | Notes                                  |
|---------------|--------------------------------------|----------------------------------------|
| `/premium/`   | `/premium/`, `/premium/report/`      | Exact prefix.                          |
| `/premium/*`  | everything under `/premium/`         | Trailing `*` = wildcard prefix.        |
| `/blog/`      | `/blog/`, `/blog/2026/post/`         | A shorter, lower-priced section.       |
| *(no rule)*   | everything else                      | Falls back to your flat price.         |

### Setting it up

1. Open the **Pricing** tab.
2. In a blank row, enter a **path prefix** (e.g. `/premium/*`), a **price in
   micros** (e.g. `50000` = $0.05), and a **currency**.
3. Add as many rows as you need. **Leave a row blank to ignore it**; **clear a
   path and save to delete** that rule.
4. Click **Save pricing**.

> **Micros?** Prices are in millionths of the currency unit, the same unit AI
> payment rails use. `1000000` micros = `1.00`. So `50000` micros = `$0.05`,
> `2000` micros = `$0.002`.

### What the crawler sees

When a crawler hits a priced, disallowed path it gets a `402 Payment Required`
whose price reflects the matched rule:

```
$ curl -sI -H 'user-agent: GPTBot/1.2' https://your-site.example/premium/report/
HTTP/1.1 402 Payment Required
Crawler-Price: 50000 micros USD
Crawler-Price-Rail: x402
```

The same price is recorded in your **Revenue** dashboard and **Logs**, so the
numbers you see always match what you actually offered.

### Important: pricing sets the *amount*, not the *gate*

A path is only charged if your **RSL policy disallows it** (Settings tab). Pricing
rules set the price for paths that are *already* being charged — they do **not**
make an allowed path start charging. To charge a new section, disallow it in your
policy first, then (optionally) give it a price here.

---

## Email alerts

**Settings → CrawlerToll → Alerts**

CrawlerToll can email you about AI-crawler activity, built from your bot-request
logs. Three independent summaries:

| Summary    | When                          | Contents                                       |
|------------|-------------------------------|------------------------------------------------|
| **Daily**  | once a day                    | yesterday's crawls, charged/blocked, revenue   |
| **Weekly** | every Monday                  | the last 7 days + your top crawlers            |
| **Spike**  | checked hourly, sent on spike | a crawler exceeding 3× its 7-day daily average |

### Setting it up

1. Open the **Alerts** tab.
2. Tick the summaries you want.
3. (Optional) Set a **Send to** address. Leave it blank to use your site admin
   email.
4. Click **Save alerts**.

Summaries only have data while **logging is on** (a Pro feature, active whenever
Pro is). A daily or weekly summary with zero crawls isn't sent, so you won't get
empty emails.

### Deliverability

Alerts use WordPress's built-in `wp_mail`. On shared hosting that often lands in
spam. For reliable inboxing, install an SMTP plugin (Postmark, Amazon SES,
SendGrid, etc.) — CrawlerToll sends through whatever mail transport WordPress is
configured with.

---

## Log retention

**Settings → CrawlerToll → Logs** (the control sits just above the log table)

Every charged or blocked crawl is recorded in a log (Pro). To stop that table from
growing forever, CrawlerToll deletes entries older than your **retention window**
once a day.

- Set **Keep logs for** to the number of days to retain (default **90**).
- Set it to **0** to keep logs forever — nothing is auto-deleted.

The purge runs on a daily WordPress cron. Lowering the number takes effect on the
next run, and it never deletes anything newer than the window. Export your logs
(CSV/JSON, also on the Logs tab) first if you need a permanent archive.

---

## Per-crawler rail routing

**Settings → CrawlerToll → Rails**

A *rail* is how a crawler is told to pay — the free plugin advertises one rail
for everyone (the **Settlement rail** on the Settings tab). Pro lets you route
**different crawlers to different rails**.

Supported rails: **x402** (Coinbase/LF stablecoin), **TollBit**, **Skyfire**,
**Cloudflare Pay Per Crawl**, **Stripe ACP**, **per-`context-license`**, and a
**Custom** option.

1. Open the **Rails** tab.
2. For any crawler, pick a rail from its dropdown. Leave it on the default to keep
   using your site-wide rail.
3. Click **Save rail routing**.

The chosen rail shows up in the 402 response as the `Crawler-Price-Rail` header
and the `Link: …; rel="payment"` hint, so each crawler is pointed at the rail you
selected for it.

---

## Revenue dashboard

**Settings → CrawlerToll → Revenue**

A read-only summary of crawler activity for a date range (default: last 30 days).
Use the date pickers to change the window. It shows:

- **Total crawls**, and the split across **charged (402)**, **blocked (403)**, and
  **allowed**.
- **Potential revenue** — the sum of your priced 402s, i.e. what you'd earn if
  every charged crawl paid.
- **Change vs the previous period** of the same length.
- **Top crawlers** and **top paths** by volume.

"Potential" is the right word: CrawlerToll issues the 402 and records the price;
actual settlement happens on your chosen rail.

---

## Bot-request logs + export

**Settings → CrawlerToll → Logs**

Every crawler decision is recorded: time, bot, path, action, price, and (when
available) the content fingerprint. You can:

- **Filter** by crawler, action (allowed / charged / blocked), and date range.
- **Sort** by time, bot, or price, and page through results.
- **Export** the current filtered view to **CSV** or **JSON** — the export matches
  exactly what's on screen.

The **Keep logs for** control here governs [log retention](#log-retention).

---

## Content provenance

For crawlers you **allow through**, CrawlerToll computes a **SHA-256 fingerprint
of the exact content served** and stores it on that log row — a timestamped,
tamper-evident record of *"this content was served to this crawler at this time."*

- The fingerprint appears in the **Content Hash** column on the Logs tab.
- Verify or look one up via the REST API:
  `GET /wp-json/crawlertoll/v1/provenance?hash=<sha256>` (requires an admin
  login).

A `402` response serves no content (just the payment offer), so only **allowed,
served** requests carry a fingerprint — which is exactly what you'd want to prove
what an AI crawler actually ingested.
