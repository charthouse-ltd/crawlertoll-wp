/**
 * Header-walker client — the standard way to READ CrawlerToll proof surfaces.
 *
 * Identifies itself by sending crawler user-agents (the honest client mode —
 * never pretends to be a browser) and never auto-pays. Two entry points:
 *
 *   walkHeaders(ctx)     — full-crawl envelope for the publishable report:
 *                          per-crawler-segment state per surface + evidence trails
 *   segmentStates(ctx)   — pricing/segment-state machine over proof surfaces:
 *                          ai-bot · agent · operator · human
 *
 * Rules of interpretation (these are the REPORT claims):
 *   - /crawlertoll/{slug}/   → ACTIVE when that crawler's request gets the offer
 *     (or the key path returns managed) — bot-pays is ACTIVE, not merely declared
 *   - /directory/:active     → ACTIVE (the Doorkeeper's own active set is listed)
 *   - /directory/:available  → AVAILABLE (the publisher has not selected it)
 *   - /directory/:declared   → DECLARED (off-catalogue, manual declaration only)
 *
 * Non-negotiable: every ACTIVE/AVAILABLE item carries a non-empty evidence trail
 * naming the surface(s) it was proven on.
 */
import { DOORKEEPER } from "../lib/paths.js";
import { canonicalFetch } from "../lib/net.js";

const SEGMENT_DIR = { "ai-bot": "active", agent: "available", operator: "operator" };
const ALL_SURFACES = (slug) => [DOORKEEPER.crawler(slug), ...Object.values(DOORKEEPER)];

const CATALOG_UA = {
  "GPTBot": "Mozilla/5.0 (compatible; GPTBot/1.3; +https://openai.com/gptbot)",
  "ClaudeBot": "ClaudeBot/1.2 (+https://www.anthropic.com/claude-bot)",
  "TollBit": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 TollBit/1.0 (+https://tollbit.com/bot)",
  "Meta-ExternalAgent": "meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)",
  "Meta-ExternalFetcher": "meta-externalfetcher/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)",
  "OAI-SearchBot": "Mozilla/5.0 (compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot)",
  "Amazonbot": "Mozilla/5.0 (compatible; Amazonbot/0.1; +https://developer.amazon.com/support/amazonbot)",
  "DuckAssistBot": "DuckAssistBot/1.2 (+https://duckduckgo.com/duckassistbot)",
  "Bytespider": "Mozilla/5.0 (compatible; Bytespider/1.0; +https://zhanzhang.toutiao.com/)",
  "Applebot": "Mozilla/5.0 (compatible; Applebot/0.1; +http://www.apple.com/go/applebot)",
  "Applebot-Extended": "Mozilla/5.0 (compatible; Applebot-Extended/0.1; +http://www.apple.com/go/applebot)",
  "ChatGPT-User": "Mozilla/5.0 (compatible; ChatGPT-User/1.0; +https://openai.com/bot)",
  "CCBot": "CCBot/2.0 (https://commoncrawl.org/faq/)",
  "cohere-ai": "cohere-ai/1.0 (+https://about.cohere.com/docs/crawler)",
  "cohere-training-data-crawler": "cohere-training-data-crawler/1.0",
  "PerplexityBot": "PerplexityBot/1.0 (+https://perplexity.ai/perplexitybot)",
  "Perplexity-User": "Perplexity-User/1.0 (+https://perplexity.ai/perplexitybot)",
  "Google-Extended": "Google-Extended/1.0",
  "GoogleOther": "GoogleOther/1.0",
  "GoogleOther-Image": "GoogleOther-Image/1.0",
  "GoogleOther-Video": "GoogleOther-Video/1.0",
  "FacebookBot": "facebookbot/1.0 (+http://www.facebook.com/externalhit_uatext.php)",
  "FacebookExternalHit": "facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)",
  "LinkedInBot": "LinkedInBot/1.0 (+https://www.linkedin.com/)",
  "Twitterbot": "Twitterbot/1.0",
  "Slackbot": "Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)",
  "Discordbot": "Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)",
  "Yahoo": "Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)",
  "AI2Bot": "Mozilla/5.0 (compatible; AI2Bot/2.0; +https://www.allenai.org/crawler)",
  "AI2Bot-Dolma": "AI2Bot-Dolma/1.0",
  "iaskspider": "iaskspider/2.0 (+https://iask.ai/iaskspider/)",
  "diffbot": "Mozilla/5.0 (compatible; Diffbot/0.1; +http://www.diffbot.com)",
};

function walkForUA(ctx, ua, surfaces, key) {
  const trail = [];
  let result = { state: "no-crawler-state", item: ua };
  for (const surface of surfaces) {
    const headers = { "user-agent": ua };
    if (key) headers["x-crawlertoll-key"] = key;
    let r;
    try { r = canonicalFetch(ctx, surface, { headers }); }
    catch (err) {
      trail.push(`${surface}: unreachable (${String(err).slice(0, 120)})`);
      continue;
    }
    result = Object.assign(result, { surface, http: r.status });
    if (r.status === 200) {
      const slug = r.body?.offer?.planSlug || r.body?.planSlug || "";
      const agency = r.body?.offer?.agency || r.body?.agency || null;
      trail.push(
        `${surface}: 200 offer [${slug || "crawlertoll-micro"}]` +
        (agency ? ` x402 rails=${(agency.payment?.rails || []).join("+") || "?"}` : "")
      );
      result = Object.assign(result, {
        state: "offer", agency: agency || undefined,
        planSlug: slug || "crawlertoll-micro",
      });
      return { result, trail };
    }
    if (r.status === 402 || r.status === 403) {
      trail.push(`${surface}: ${r.status} ${r.body?.error || ""}`.trim());
      result = Object.assign(result, { state: r.status === 402 ? "paywall" : "blocked" });
      return { result, trail };
    }
    trail.push(`${surface}: ${r.status}`);
  }
  return { result, trail };
}

/** Slug token match: every "-"-segment of the slug must appear in the UA
 *  (handles "gpt-bot"→"GPTBot", "tollbit"→"TollBit/1.0"). */
function slugSegmentsMatch(slug, ua) {
  return slug.toLowerCase().split("-").every((seg) => ua.toLowerCase().includes(seg));
}

export function walkHeaders(ctx, { key, catalog = [], operatorHints = [] } = {}) {
  const items = [];

  // The Doorkeeper's declared sets — every directory item is a claim to verify.
  let directory = null;
  try {
    const r = canonicalFetch(ctx, DOORKEEPER.directory, { headers: { "user-agent": CATALOG_UA["GPTBot"] } });
    if (r.status === 200 && r.body?.bots) {
      directory = r.body.bots;
      const rules = [
        ["active", "active"], ["available", "available"], ["operator", "operator"], ["declared", "declared"],
      ];
      for (const [group, state] of rules) {
        for (const slug of directory[group] || []) {
          // Directory state is the claim; a key on /directory also probes the managed path.
          const headers = { "user-agent": CATALOG_UA[slug] || `CrawlerToll-Probe/1.0 (${slug})` };
          if (key && group === "active") headers["x-crawlertoll-key"] = key;
          let probe = "";
          try {
            const p = canonicalFetch(ctx, DOORKEEPER.directory, { headers });
            probe = p.status === 200 && p.body?.probe
              ? `; key probe: ${p.body.probe === "managed" ? "MANAGED" : "unmanaged"}`
              : "";
          } catch { /* directory probe optional */ }
          items.push({ item: slug, group: `directory:${group}`, state, evidence: [`/directory:${group} listed${probe}`] });
        }
      }
      if (!directory.declared?.length) {
        items.push({ item: "(none)", group: "directory:declared", state: "declared", evidence: ["/directory:declared empty — no off-catalogue declarations"] });
      }
    }
  } catch (err) {
    items.push({ item: "(directory)", group: "directory", state: "no-crawler-state", evidence: [`unreachable: ${String(err).slice(0, 120)}`] });
  }

  // Per-crawler proof: send its UA at its own slug surface. If a key is set,
  // the key path is ALSO exercised — every detection lands on the evidence trail
  // regardless of which path answered.
  for (const entry of catalog) {
    const ua = entry.ua || CATALOG_UA[entry.slug] || `CrawlerToll-Probe/1.0 (${entry.slug})`;
    const surfaces = ALL_SURFACES(entry.slug);
    let item = { item: entry.slug, group: entry.group || "catalog", state: "no-crawler-state", evidence: [] };
    for (const surface of surfaces) {
      const headers = { "user-agent": ua };
      if (key) headers["x-crawlertoll-key"] = key;
      let r;
      try { r = canonicalFetch(ctx, surface, { headers }); }
      catch (err) { item.evidence.push(`${surface}: unreachable (${String(err).slice(0, 100)})`); continue; }
      if (key && r.status === 200 && r.body?.probe === "managed") {
        item.evidence.push(`${surface}: key accepted → MANAGED`);
        item = Object.assign(item, { surface, http: 200, state: "managed" });
        break;
      }
      if (r.status === 200) {
        const slug = r.body?.offer?.planSlug || r.body?.planSlug || "";
        item.evidence.push(`${surface}: 200 offer [${slug || "crawlertoll-micro"}]`);
        item = Object.assign(item, {
          surface, http: 200, state: "offer", planSlug: slug || "crawlertoll-micro",
          agency: r.body?.offer?.agency || r.body?.agency || undefined,
        });
        break;
      }
      if (r.status === 402 || r.status === 403) {
        item.evidence.push(`${surface}: ${r.status} ${r.body?.error || ""}`.trim());
        item = Object.assign(item, { surface, http: r.status, state: r.status === 402 ? "paywall" : "blocked" });
        break;
      }
      item.evidence.push(`${surface}: ${r.status}`);
    }
    items.push(item);
  }

  // Operator recognition: hints the operator site itself listed in allowed_crawlers.
  for (const hint of operatorHints) {
    const { result, trail } = walkForUA(ctx, hint.ua || CATALOG_UA[hint.slug] || hint.slug, [DOORKEEPER.directory, DOORKEEPER.crawler(hint.slug)], key);
    const state = result.state === "offer" || result.state === "managed" ? "operator"
      : result.state === "paywall" || result.state === "blocked" ? "not-recognized"
      : "unknown";
    items.push({ item: hint.slug, group: "operator-hint", state, evidence: trail });
  }

  const failures = items.filter((i) => ["offer", "active", "available"].includes(i.state) && i.evidence.length === 0);
  return {
    crawlerContext: "header-walker", noBrowser: true, noPaymentSent: true,
    directory: directory || undefined,
    items,
    summary: {
      total: items.length,
      byState: items.reduce((acc, i) => ((acc[i.state] = (acc[i.state] || 0) + 1), acc), {}),
      evidenceFailures: failures.length,
    },
  };
}

/** Segment-state machine: each pricing segment is exercised against the proof
 *  surfaces; state transitions record the observed surface answers. */
export function segmentStates(ctx, { key } = {}) {
  const segments = {
    "ai-bot": { ua: CATALOG_UA["GPTBot"] },
    agent: { ua: CATALOG_UA["ChatGPT-User"] },
    operator: { ua: "CrawlerToll-Operator/1.0" },
    human: { ua: "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36" },
  };
  const states = {};
  for (const [segment, cfg] of Object.entries(segments)) {
    const dirGroup = SEGMENT_DIR[segment];
    const transitions = [];
    const surfaces = [DOORKEEPER.directory, ...(dirGroup ? [DOORKEEPER[dirGroup]] : []), DOORKEEPER.crawler("GPTBot")];
    let state = "unseen";
    for (const surface of surfaces) {
      const headers = { "user-agent": cfg.ua };
      if (key) headers["x-crawlertoll-key"] = key;
      let r;
      try { r = canonicalFetch(ctx, surface, { headers }); }
      catch (err) { transitions.push(`${surface}: unreachable (${String(err).slice(0, 100)})`); continue; }
      if (key && r.status === 200 && r.body?.probe === "managed") {
        transitions.push(`${surface}: key accepted → MANAGED`);
        state = "managed";
        continue;
      }
      if (r.status === 200) {
        const hasOffer = !!(r.body?.offer || r.body?.planSlug);
        transitions.push(`${surface}: 200 ${hasOffer ? "offer" : "listed"}`);
        if (hasOffer) state = "offer";
      } else if (r.status === 402 || r.status === 403) {
        transitions.push(`${surface}: ${r.status} ${r.body?.error || ""}`.trim());
        state = r.status === 402 ? "paywall" : "blocked";
      } else {
        transitions.push(`${surface}: ${r.status}`);
      }
    }
    states[segment] = { state, transitions };
  }
  return states;
}

export { CATALOG_UA, slugSegmentsMatch };
