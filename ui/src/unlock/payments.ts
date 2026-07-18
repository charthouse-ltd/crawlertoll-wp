// Per-rail payment-proof acquisition. These are the LIVE-CONFIG paths: Stripe
// needs the operator's publishable key + a connected account; x402 needs a reader
// wallet + the registry facilitator + the offer to carry the token's EIP-712
// domain. No-MoR: the buyer pays the publisher directly; we only read {cek}.

import { createStripeIntent, redeemStripe, redeemX402, unlockConfig, UnlockError } from "./api";
import type { SignedOffer } from "./offer";

interface StripeLike {
  elements: (opts: { clientSecret: string }) => StripeElements;
  confirmPayment: (opts: { elements: StripeElements; redirect: "if_required" }) => Promise<{ error?: { message?: string }; paymentIntent?: { status: string } }>;
}
interface StripeElements {
  create: (type: string) => { mount: (sel: string | HTMLElement) => void };
  getElement?: (type: string) => unknown;
}

declare global {
  interface Window {
    Stripe?: (key: string) => StripeLike;
  }
}

// Dynamically load js.stripe.com (external service — needs a wp.org external-
// services disclosure entry; flagged in the launch checklist). Only ever called
// when a publishable key is configured.
function loadStripeJs(): Promise<void> {
  if (window.Stripe) {
    return Promise.resolve();
  }
  return new Promise((resolve, reject) => {
    const s = document.createElement("script");
    s.src = "https://js.stripe.com/v3/";
    s.onload = () => resolve();
    s.onerror = () => reject(new UnlockError("Could not load the card form.", "stripe_js"));
    document.head.appendChild(s);
  });
}

/**
 * Stripe: create the intent, mount the Payment Element into `mountNode`, and
 * return a confirm() the UI calls when the reader submits. On success it redeems
 * the key and returns the CEK.
 */
export async function startStripe(contentId: string, passId: string, mountNode: HTMLElement): Promise<() => Promise<string>> {
  if (!window.Stripe) {
    await loadStripeJs();
  }
  const stripe = window.Stripe?.(unlockConfig.stripePublishableKey);
  if (!stripe) {
    throw new UnlockError("Card payments are unavailable.", "stripe_init");
  }
  const { client_secret, intent_id } = await createStripeIntent(contentId, passId);
  const elements = stripe.elements({ clientSecret: client_secret });
  elements.create("payment").mount(mountNode);

  return async () => {
    const result = await stripe.confirmPayment({ elements, redirect: "if_required" });
    if (result.error) {
      throw new UnlockError(result.error.message || "Card was declined.", "stripe_confirm");
    }
    const { cek } = await redeemStripe(contentId, passId, intent_id);
    return cek;
  };
}

/**
 * x402: sign an EIP-3009 transferWithAuthorization with the reader's wallet and
 * redeem with an X-PAYMENT header. Requires the offer to carry the token domain
 * (chainId/verifyingContract/name/version) — surfaced as a clear error if absent.
 */
export async function payX402(contentId: string, offer: SignedOffer): Promise<string> {
  const x = offer.x402;
  if (!x || !x.payTo || !window.ethereum) {
    throw new UnlockError("USDC unlock is unavailable.", "x402_unavailable");
  }
  const domain = (x as Record<string, unknown>).eip712 as
    | { name: string; version: string; chainId: number; verifyingContract: string }
    | undefined;
  if (!domain) {
    // The signed offer must include the token's EIP-712 domain for the wallet to
    // sign a verifiable authorization. Until buildOffer enriches it, x402-browser
    // can't complete — fail clearly rather than sign an unverifiable payload.
    throw new UnlockError("USDC unlock isn't fully configured yet.", "x402_no_domain");
  }

  const from = (await window.ethereum.request({ method: "eth_requestAccounts" })) as string[];
  const account = from[0];
  const now = Math.floor(Date.now() / 1000);
  const authorization = {
    from: account,
    to: x.payTo,
    value: String(x.priceMicros ?? 0),
    validAfter: "0",
    validBefore: String(now + 600),
    nonce: `0x${crypto.getRandomValues(new Uint8Array(32)).reduce((a, b) => a + b.toString(16).padStart(2, "0"), "")}`,
  };
  const typedData = {
    types: {
      EIP712Domain: [
        { name: "name", type: "string" },
        { name: "version", type: "string" },
        { name: "chainId", type: "uint256" },
        { name: "verifyingContract", type: "address" },
      ],
      TransferWithAuthorization: [
        { name: "from", type: "address" },
        { name: "to", type: "address" },
        { name: "value", type: "uint256" },
        { name: "validAfter", type: "uint256" },
        { name: "validBefore", type: "uint256" },
        { name: "nonce", type: "bytes32" },
      ],
    },
    domain,
    primaryType: "TransferWithAuthorization",
    message: authorization,
  };
  const signature = (await window.ethereum.request({
    method: "eth_signTypedData_v4",
    params: [account, JSON.stringify(typedData)],
  })) as string;

  const xPayment = btoa(
    JSON.stringify({
      x402Version: 1,
      scheme: "exact",
      network: x.network,
      payload: { signature, authorization },
    }),
  );
  const { cek } = await redeemX402(contentId, xPayment);
  return cek;
}
