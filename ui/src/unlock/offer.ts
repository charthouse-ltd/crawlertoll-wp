// Derive the rail menu from the SIGNED 402 offer (the authoritative source — the
// page's data-rail/price attrs are only loading-state hints). Pure + importable
// so it's unit-testable without a DOM. Mirrors the registry's buildOffer shape
// (registry/src/sealed.js): x402 carries payTo only when configured; each Stripe
// pass carries id/label/priceCents/currency/scope.

export interface OfferPass {
  id: string;
  label?: string;
  priceCents?: number;
  currency?: string;
  scope?: string;
}

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
  passes?: OfferPass[];
}

export type RailKind = "stripe" | "x402" | "xwallet";

export interface RailTile {
  rail: RailKind;
  key: string;
  label: string;
  enabled: boolean;
  reason?: string; // why disabled (shown to the reader)
  passId?: string; // stripe pass to redeem
  priceLabel?: string;
}

export interface UnlockEnv {
  hasStripeKey: boolean; // operator configured a Stripe publishable key
  hasWallet: boolean; // a browser web3 wallet (window.ethereum) is present
}

const SYMBOLS: Record<string, string> = { USD: "$", USDC: "$", EUR: "€", GBP: "£" };

function fmtCents(cents?: number, currency?: string): string {
  if (typeof cents !== "number") {
    return "";
  }
  const sym = SYMBOLS[(currency || "USD").toUpperCase()] ?? "";
  return `${sym}${(cents / 100).toFixed(2)}`;
}

function fmtMicros(micros?: number, currency?: string): string {
  if (typeof micros !== "number") {
    return "";
  }
  const sym = SYMBOLS[(currency || "USDC").toUpperCase()] ?? "";
  return `${sym}${(micros / 1_000_000).toFixed(2)}`;
}

/**
 * Returns the rail tiles to render. Empty array ⇒ "unlock unavailable" (no
 * configured rail). A disabled tile shows its reason rather than vanishing, so
 * the reader understands why a rail isn't available.
 */
export function offerToRails(offer: SignedOffer, env: UnlockEnv): RailTile[] {
  const tiles: RailTile[] = [];

  for (const pass of offer.passes ?? []) {
    tiles.push({
      rail: "stripe",
      key: `stripe:${pass.id}`,
      label: pass.label || "Pay by card",
      enabled: env.hasStripeKey,
      reason: env.hasStripeKey ? undefined : "Card payments not configured for this site.",
      passId: pass.id,
      priceLabel: fmtCents(pass.priceCents, pass.currency),
    });
  }

  if (offer.x402 && offer.x402.payTo) {
    // Label honestly: a testnet charge is not real money and must never look like it.
    const isTestnet = offer.x402.testnet === true || /sepolia|devnet/i.test(offer.x402.network || "");
    tiles.push({
      rail: "x402",
      key: "x402",
      label: isTestnet ? "Pay with USDC (testnet)" : "Pay with USDC (x402)",
      // Always clickable: with no wallet injected, clicking surfaces an
      // actionable "get a wallet" prompt (Chris, QA 2026-08-12) instead of a
      // dead grey tile the reader can't interrogate.
      enabled: true,
      reason: env.hasWallet ? undefined : "Needs a web3 wallet (e.g. MetaMask) — click for details.",
      priceLabel: fmtMicros(offer.x402.priceMicros, offer.x402.currency),
    });
  }

  // If there is no real rail at all, signal "unavailable" (no dead X-wallet tile).
  if (tiles.length === 0) {
    return [];
  }

  // X-wallet: always shown, always disabled until X ships a merchant API.
  tiles.push({
    rail: "xwallet",
    key: "xwallet",
    label: "Unlock with X",
    enabled: false,
    reason: "Coming soon.",
  });

  return tiles;
}
