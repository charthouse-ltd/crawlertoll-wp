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

/** Step A: POST with no proof → the signed 402 offer (authoritative rail set). */
export async function fetchOffer(contentId: string): Promise<SignedOffer> {
  const res = await fetch(keyUrl(contentId), {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: "{}",
  });
  if (res.status === 402) {
    return (await res.json()) as SignedOffer;
  }
  if (res.status === 404) {
    throw new UnlockError("This content is unavailable.", "unknown_content");
  }
  throw new UnlockError("Could not load unlock options.", `offer_${res.status}`);
}

interface KeyResponse {
  cek: string;
  capability?: unknown;
}

async function postKey(contentId: string, body: Record<string, unknown>, headers: Record<string, string> = {}): Promise<KeyResponse> {
  const res = await fetch(keyUrl(contentId), {
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
  const res = await fetch(intentUrl(contentId), {
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

/** x402: redeem with a base64 X-PAYMENT header built from a wallet signature. */
export function redeemX402(contentId: string, xPayment: string): Promise<KeyResponse> {
  return postKey(contentId, {}, { "X-PAYMENT": xPayment });
}
