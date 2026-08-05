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

// Chain metadata for wallet_addEthereumChain when the wallet doesn't know the
// network yet (error 4902). Keyed by numeric chainId from the offer's EIP-712 domain.
const CHAIN_PARAMS: Record<number, { chainId: string; chainName: string; rpcUrls: string[]; nativeCurrency: { name: string; symbol: string; decimals: number }; blockExplorerUrls: string[] }> = {
  8453: {
    chainId: "0x2105", chainName: "Base", rpcUrls: ["https://mainnet.base.org"],
    nativeCurrency: { name: "Ether", symbol: "ETH", decimals: 18 }, blockExplorerUrls: ["https://basescan.org"],
  },
  84532: {
    chainId: "0x14a34", chainName: "Base Sepolia", rpcUrls: ["https://sepolia.base.org"],
    nativeCurrency: { name: "Ether", symbol: "ETH", decimals: 18 }, blockExplorerUrls: ["https://sepolia.basescan.org"],
  },
};

/**
 * The EIP-3009 signature is only valid on the chain the offer's domain names —
 * signing on the wrong chain produces an unverifiable authorization (or worse,
 * looks valid while settling nowhere). Switch (or add) the wallet's chain first.
 */
async function ensureChain(domain: { chainId: number }): Promise<void> {
  if (!window.ethereum) {
    throw new UnlockError("No web3 wallet detected.", "x402_unavailable");
  }
  const targetHex = `0x${domain.chainId.toString(16)}`;
  const current = (await window.ethereum.request({ method: "eth_chainId" })) as string | undefined;
  if (current && current.toLowerCase() === targetHex.toLowerCase()) {
    return;
  }
  try {
    await window.ethereum.request({ method: "wallet_switchEthereumChain", params: [{ chainId: targetHex }] });
  } catch (e) {
    const code = (e as { code?: number })?.code;
    if (code === 4902) {
      const params = CHAIN_PARAMS[domain.chainId];
      if (!params) {
        throw new UnlockError("This payment network isn't supported by the unlock yet.", "x402_chain_unknown");
      }
      await window.ethereum.request({ method: "wallet_addEthereumChain", params: [params] });
      return;
    }
    if (code === 4001) {
      throw new UnlockError("Network switch was rejected in the wallet.", "x402_chain_rejected");
    }
    throw new UnlockError("Could not switch the wallet network.", "x402_chain");
  }
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
  const domain = x.eip712;
  if (!domain) {
    // The signed offer must include the token's EIP-712 domain for the wallet to
    // sign a verifiable authorization. Until buildOffer enriches it, x402-browser
    // can't complete — fail clearly rather than sign an unverifiable payload.
    throw new UnlockError("USDC unlock isn't fully configured yet.", "x402_no_domain");
  }

  let from: string[];
  try {
    from = (await window.ethereum.request({ method: "eth_requestAccounts" })) as string[];
  } catch {
    throw new UnlockError("Wallet connection was rejected.", "x402_connect_rejected");
  }
  const account = from[0];
  await ensureChain(domain);
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
  let signature: string;
  try {
    signature = (await window.ethereum.request({
      method: "eth_signTypedData_v4",
      params: [account, JSON.stringify(typedData)],
    })) as string;
  } catch (e) {
    // Cancelling the MetaMask signature prompt is the single most common user
    // path here — name it, never fall through to "Something went wrong."
    const code = (e as { code?: number })?.code;
    if (code === 4001) {
      throw new UnlockError("Signature was rejected in the wallet — nothing was charged.", "x402_sign_rejected");
    }
    throw new UnlockError("Could not sign the payment authorization.", "x402_sign");
  }

  // V2 (offer carries a CAIP-2 network): echo the accepted requirements
  // verbatim per spec and send as PAYMENT-SIGNATURE. Legacy V1: bare
  // X-PAYMENT payload. The registry accepts both, keyed on x402Version.
  const version: 1 | 2 = x.networkCaip2 ? 2 : 1;
  const xPayment = btoa(
    JSON.stringify(
      version === 2
        ? {
            x402Version: 2,
            scheme: "exact",
            network: x.networkCaip2,
            accepted: {
              scheme: "exact",
              network: x.networkCaip2,
              amount: String(x.priceMicros ?? 0),
              asset: x.asset,
              payTo: x.payTo,
              maxTimeoutSeconds: 300,
            },
            payload: { signature, authorization },
            extensions: {},
          }
        : {
            x402Version: 1,
            scheme: "exact",
            network: x.network,
            payload: { signature, authorization },
          },
    ),
  );
  const { cek } = await redeemX402(contentId, xPayment, version);
  return cek;
}
