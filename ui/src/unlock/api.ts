// Unlock app REST seam + per-rail proof acquisition. The registry key-release
// (registry/src/sealed.js POST /v1/sealed/:id/key) converges three inputs on one
// response: no proof → 402 {offer}; x402 X-PAYMENT → {cek}; Stripe {pass_id,
// intent_id} → {cek}. We NEVER touch content money — the buyer pays the publisher
// on the publisher's own account/wallet; we only read {cek}.

import type { SignedOffer } from "./offer";

export interface UnlockConfig {
  registryBase: string;
  stripePublishableKey: string; // '' when not configured
  currency: string;
}

declare global {
  interface Window {
    crawlertollUnlock?: UnlockConfig;
    ethereum?: { request: (args: { method: string; params?: unknown[] }) => Promise<unknown> };
  }
}

export const unlockConfig: UnlockConfig = window.crawlertollUnlock ?? {
  registryBase: "",
  stripePublishableKey: "",
  currency: "USD",
};

export const hasWallet = (): boolean => typeof window.ethereum !== "undefined";
export const hasStripeKey = (): boolean => unlockConfig.stripePublishableKey.length > 0;

// content_id ('host/post/ID') has literal slashes the registry slices verbatim —
// do NOT url-encode the whole id into one segment.
function keyUrl(contentId: string): string {
  return `${unlockConfig.registryBase.replace(/\/$/, "")}/v1/sealed/${contentId}/key`;
}
function intentUrl(contentId: string): string {
  return `${unlockConfig.registryBase.replace(/\/$/, "")}/v1/sealed/${contentId}/intent`;
}

export class UnlockError extends Error {
  constructor(message: string, readonly code: string) {
    super(message);
  }
}

// Metered free articles (Pro): the meter token is the reader's anonymous
// free-allowance identity. One token per metered path; we keep them all in one
// localStorage map and send the set with every offer fetch — the registry picks
// the one valid for this site+path. Same storage-blocked degradation as the CEK
// cache: private mode just means no free-allowance continuity.
const METER_KEY = "ct:meter";
function meterTokensRead(): Record<string, unknown> {
  try {
    const raw = window.localStorage?.getItem(METER_KEY);
    const parsed = raw ? JSON.parse(raw) : {};
    return parsed && typeof parsed === "object" ? parsed : {};
  } catch {
    return {};
  }
}
export function meterTokenStore(path: string, token: unknown): void {
  try {
    const all = meterTokensRead();
    all[path] = token;
    window.localStorage?.setItem(METER_KEY, JSON.stringify(all));
  } catch {
    /* storage blocked — no meter continuity, meter still works per-visit */
  }
}

// A failed fetch (registry down, offline, DNS) throws a bare TypeError — map it
// to a human message instead of the generic "Something went wrong."
async function safeFetch(url: string, init?: RequestInit): Promise<Response> {
  try {
    return await fetch(url, init);
  } catch {
    throw new UnlockError("Could not reach the payment server. Check your connection and try again.", "network");
  }
}

/** Step A: POST with no proof → the signed 402 offer (authoritative rail set). */
export async function fetchOffer(contentId: string): Promise<SignedOffer> {
  const tokens = Object.values(meterTokensRead());
  const res = await safeFetch(keyUrl(contentId), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: tokens.length > 0 ? JSON.stringify({ meter_tokens: tokens }) : "{}",
  });
  if (res.status === 402) {
    return (await res.json()) as SignedOffer;
  }
  if (res.status === 404) {
    throw new UnlockError("This content isn't set up for unlocking yet.", "unknown_content");
  }
  throw new UnlockError("Could not load unlock options.", `offer_${res.status}`);
}

// A1 (spec access-tiers-v1.md §4.1): the registry mints a settlement pass on
// every paid redemption and returns it alongside the CEK. The client caches it
// with the CEK — it is the reader's re-access proof after the cache expires
// (duration tiers, A2) or survives partial storage clears (M1 multi-storage).
export interface SettlementPass {
  pass_id: string;
  expires_at: string | null; // null = "no expiry" (the honest label, spec §7)
}

interface KeyResponse {
  cek: string;
  capability?: unknown;
  pass?: SettlementPass;
}

async function postKey(contentId: string, body: Record<string, unknown>, headers: Record<string, string> = {}): Promise<KeyResponse> {
  const res = await safeFetch(keyUrl(contentId), {
    method: "POST",
    headers: { "Content-Type": "application/json", ...headers },
    body: JSON.stringify(body),
  });
  if (res.status === 200) {
    return (await res.json()) as KeyResponse;
  }
  if (res.status === 409) {
    throw new UnlockError("This payment was already used. Reload if you've already unlocked.", "receipt_already_redeemed");
  }
  if (res.status === 402) {
    throw new UnlockError("Payment could not be verified yet.", "unsettled");
  }
  throw new UnlockError("Unlock failed.", `key_${res.status}`);
}

/** Stripe: create a PaymentIntent on the publisher's connected account. */
export async function createStripeIntent(contentId: string, passId: string): Promise<{ client_secret: string; intent_id: string }> {
  const res = await safeFetch(intentUrl(contentId), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ pass_id: passId }),
  });
  if (res.status === 409) {
    throw new UnlockError("Card payments aren't set up for this site yet.", "publisher_not_connected");
  }
  if (res.status !== 200) {
    throw new UnlockError("Could not start the card payment.", `intent_${res.status}`);
  }
  return (await res.json()) as { client_secret: string; intent_id: string };
}

/** Stripe: after the Payment Element confirms, redeem the key. */
export function redeemStripe(contentId: string, passId: string, intentId: string): Promise<KeyResponse> {
  return postKey(contentId, { rail: "stripe", pass_id: passId, intent_id: intentId });
}

/** x402: redeem with the payment header built from a wallet signature.
 *  V2 sends PAYMENT-SIGNATURE, legacy V1 sends X-PAYMENT — the registry
 *  accepts both, keyed on the payload's x402Version. When a tier is given,
 *  its tier_id is echoed in the body so the registry re-derives that tier's
 *  price AND duration server-side (spec §3). */
export function redeemX402(contentId: string, xPayment: string, version: 1 | 2 = 1, tierId?: string): Promise<KeyResponse> {
  return postKey(contentId, tierId ? { tier_id: tierId } : {}, { [version === 2 ? "PAYMENT-SIGNATURE" : "X-PAYMENT"]: xPayment });
}

/** Meter: redeem a free read against the reader's meter token (no payment). */
export function redeemMeter(contentId: string, meterToken: unknown): Promise<KeyResponse> {
  return postKey(contentId, { rail: "meter", meter_token: meterToken });
}

/**
 * A1: silent renewal — re-present an unexpired settlement pass for a fresh CEK,
 * no new payment. The registry resolves the pass server-side (rail recorded at
 * mint), so we send only the pass_id. An expired pass comes back 402 with
 * `pass_expired: true` — mapped to a distinct code so the UI can show the
 * renew flow instead of a generic failure.
 */
export async function renewPass(contentId: string, passId: string): Promise<KeyResponse> {
  const res = await safeFetch(keyUrl(contentId), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ pass_id: passId }),
  });
  if (res.status === 200) {
    return (await res.json()) as KeyResponse;
  }
  if (res.status === 402) {
    const body = (await res.json().catch(() => ({}))) as { pass_expired?: boolean };
    if (body.pass_expired) {
      throw new UnlockError("Your access to this article has ended.", "pass_expired");
    }
    throw new UnlockError("Your saved access could not be renewed.", "renew_rejected");
  }
  throw new UnlockError("Could not renew your access.", `renew_${res.status}`);
}
