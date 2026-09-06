// Derive the rail menu from the SIGNED 402 offer (the authoritative source — the
// page's data-rail/price attrs are only loading-state hints). Pure + importable
// so it's unit-testable without a DOM. Mirrors the registry's buildOffer shape
// (registry/src/sealed.js): x402 carries payTo only when configured. Card tiles
// are NOT in the offer — they mirror the same signed tiers (publisher-owned
// Stripe at the origin prices from the identical rule set, spec 2026-09-06).

export interface X402Offer {
  payTo?: string | null;
  asset?: string | null;
  network?: string | null;
  // CAIP-2 network id (e.g. "eip155:84532") inside the signed offer. Present ⇒
  // the registry speaks x402 V2 (PAYMENT-SIGNATURE header, `accepted` echo);
  // absent ⇒ legacy V1 (X-PAYMENT header).
  networkCaip2?: string | null;
  priceMicros?: number;
  currency?: string;
  testnet?: boolean;
  // Token's EIP-712 domain (inside the signed offer) — required for the wallet to
  // produce a verifiable EIP-3009 signature. Absent ⇒ rail shows as unconfigured.
  eip712?: { name: string; version: string; chainId: number; verifyingContract: string } | null;
}

export interface SignedOffer {
  content_id: string;
  publisher?: string;
  x402?: X402Offer | null;
  // Access tiers (A2, spec §3): price×duration offers, INSIDE the signed offer
  // (the signature covers the set — a tampered tier invalidates verification).
  // The client echoes tier_id at redemption; the registry re-derives price AND
  // duration server-side, so client-side values are display-only.
  tiers?: Array<{ tier_id: string; price_micros: number; duration_hours: number | null }>;
  // Bundle (A4, spec §5.5): whole-path pass, INSIDE the signed offer (the
  // signature covers it, same as tiers). scope_path is the registry-verified
  // path pattern this pass roams (e.g. "/" or "/reviews/*"); b-prefixed
  // tier_ids resolve against the entry's bundle server-side.
  bundle?: {
    scope_path: string;
    tiers: Array<{ tier_id: string; price_micros: number; duration_hours: number | null }>;
  };
  // Email gate (A5, spec §5.5): INSIDE the signed offer — humans may unlock
  // free after verifying an email address via a magic link (rail "email").
  email_gate?: boolean;
  // Metered free articles (Pro): UNSIGNED sibling of the signed offer — UI state
  // only. Present when this human reader is on a metered path. remaining 0 means
  // the allowance is used up (paid rails only).
  meter?: {
    token: unknown; // ct_meter_v1 — present verbatim to rail:"meter"
    remaining: number;
    count: number;
    window_days: number;
  };
}

export type RailKind = "stripe" | "x402" | "xwallet";

export interface RailTile {
  rail: RailKind;
  key: string;
  label: string;
  enabled: boolean;
  reason?: string; // why disabled (shown to the reader)
  priceLabel?: string;
  // A2: the access tier this tile buys (x402 or card). Absent = legacy single price.
  tier?: { tier_id: string; price_micros: number; duration_hours: number | null };
}

export interface UnlockEnv {
  hasStripeKey: boolean; // operator configured a Stripe publishable key
  hasWallet: boolean; // a browser web3 wallet (window.ethereum) is present
}

const SYMBOLS: Record<string, string> = { USD: "$", USDC: "$", EUR: "€", GBP: "£" };

// Stripe's card floor: a price below it is agent-only (the origin refuses to
// create an intent for it — mirrored here so the tile never appears).
export const CARD_MIN_MICROS = 500000;

function fmtMicros(micros?: number, currency?: string): string {
  if (typeof micros !== "number") {
    return "";
  }
  const sym = SYMBOLS[(currency || "USDC").toUpperCase()] ?? "";
  const v = micros / 1_000_000;
  // Sub-cent prices are normal here (a crawl can cost $0.005) — toFixed(2)
  // would display "$0.01" while charging $0.005. Show up to 4 decimals instead.
  const str = v >= 0.01 ? v.toFixed(2) : v.toFixed(4).replace(/0+$/, "").replace(/\.$/, "");
  return `${sym}${str}`;
}

/** A2: human duration label for tier tiles. null = "No expiry" (never "forever", spec §7). */
function durationLabel(hours: number | null): string {
  if (hours === null || hours === undefined) return "No expiry";
  if (hours === 24) return "24-hour access";
  if (hours === 168) return "7-day access";
  if (hours === 720) return "30-day access";
  if (hours % 24 === 0) return `${Math.round(hours / 24)}-day access`;
  return `${hours}-hour access`;
}

/**
 * Returns the rail tiles to render. Empty array ⇒ "unlock unavailable" (no
 * configured rail). A disabled tile shows its reason rather than vanishing, so
 * the reader understands why a rail isn't available.
 */
export function offerToRails(offer: SignedOffer, env: UnlockEnv): RailTile[] {
  const tiles: RailTile[] = [];

  // Card tiles (publisher-owned Stripe): one per article tier, one per bundle
  // tier, or the single price when it clears the card floor. The origin
  // re-derives the amount from the same tier_id server-side.
  if (env.hasStripeKey) {
    const cardCurrency = offer.x402?.currency || "USD";
    const cardReason = undefined;
    const tiers = Array.isArray(offer.tiers) ? offer.tiers : [];
    if (tiers.length > 0) {
      for (const tier of tiers) {
        tiles.push({
          rail: "stripe",
          key: `stripe:${tier.tier_id}`,
          label: `${durationLabel(tier.duration_hours)} — pay by card`,
          enabled: tier.price_micros >= CARD_MIN_MICROS,
          reason: tier.price_micros >= CARD_MIN_MICROS ? cardReason : "Too small for a card payment.",
          priceLabel: fmtMicros(tier.price_micros, cardCurrency),
          tier,
        });
      }
    } else if (typeof offer.x402?.priceMicros === "number" && offer.x402.priceMicros >= CARD_MIN_MICROS) {
      tiles.push({
        rail: "stripe",
        key: "stripe",
        label: "Pay by card",
        enabled: true,
        priceLabel: fmtMicros(offer.x402.priceMicros, cardCurrency),
      });
    }
    const bundle = offer.bundle;
    if (bundle && typeof bundle.scope_path === "string" && Array.isArray(bundle.tiers)) {
      const scopeLabel = bundle.scope_path === "/" ? "the whole site" : `all of ${bundle.scope_path}`;
      for (const tier of bundle.tiers) {
        tiles.push({
          rail: "stripe",
          key: `stripe:${tier.tier_id}`,
          label: `Unlock ${scopeLabel} — ${durationLabel(tier.duration_hours)} — pay by card`,
          enabled: tier.price_micros >= CARD_MIN_MICROS,
          reason: tier.price_micros >= CARD_MIN_MICROS ? cardReason : "Too small for a card payment.",
          priceLabel: fmtMicros(tier.price_micros, cardCurrency),
          tier,
        });
      }
    }
  }

  if (offer.x402 && offer.x402.payTo) {
    // Label honestly: a testnet charge is not real money and must never look like it.
    const isTestnet = offer.x402.testnet === true || /sepolia|devnet/i.test(offer.x402.network || "");
    const usdcLabel = isTestnet ? "USDC (testnet)" : "USDC";
    const walletReason = env.hasWallet ? undefined : "Needs a web3 wallet (e.g. MetaMask) — click for details.";
    const tiers = Array.isArray(offer.tiers) ? offer.tiers : [];
    if (tiers.length > 0) {
      // A2: one tile per (price × duration) tier — the tier set REPLACES the
      // legacy single-price tile when configured (spec §3 menu).
      for (const tier of tiers) {
        tiles.push({
          rail: "x402",
          key: `x402:${tier.tier_id}`,
          label: `${durationLabel(tier.duration_hours)} — pay with ${usdcLabel}`,
          // Always clickable: with no wallet injected, clicking surfaces an
          // actionable "get a wallet" prompt (Chris, QA 2026-08-12).
          enabled: true,
          reason: walletReason,
          priceLabel: fmtMicros(tier.price_micros, offer.x402.currency),
          tier,
        });
      }
    } else {
      tiles.push({
        rail: "x402",
        key: "x402",
        label: isTestnet ? "Pay with USDC (testnet)" : "Pay with USDC (x402)",
        enabled: true,
        reason: walletReason,
        priceLabel: fmtMicros(offer.x402.priceMicros, offer.x402.currency),
      });
    }

    // A4: one tile per bundle tier — buys a pass that roams EVERY article under
    // bundle.scope_path for the chosen duration. The b-prefixed tier_id flows
    // into payX402 like any article tier; the registry resolves it against the
    // entry's signed bundle server-side (price tampering → verification fails).
    const bundle = offer.bundle;
    if (bundle && typeof bundle.scope_path === "string" && Array.isArray(bundle.tiers)) {
      const scopeLabel = bundle.scope_path === "/" ? "the whole site" : `all of ${bundle.scope_path}`;
      for (const tier of bundle.tiers) {
        tiles.push({
          rail: "x402",
          key: `x402:${tier.tier_id}`,
          label: `Unlock ${scopeLabel} — ${durationLabel(tier.duration_hours)}`,
          enabled: true,
          reason: walletReason,
          priceLabel: fmtMicros(tier.price_micros, offer.x402.currency),
          tier,
        });
      }
    }
  }

  // Empty array ⇒ "unlock unavailable" (no configured rail). A disabled tile
  // shows its reason rather than vanishing, so the reader knows why.
  // (2026-08-26, Chris's call: the disabled "Unlock with X — Coming soon"
  // teaser tile was removed — no unproven claims on a reader paywall.)
  return tiles;
}
