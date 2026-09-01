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
// map and send the set with every offer fetch — the registry picks the one
// valid for this site+path.
//
// M1 (2026-09-01): multi-storage identity. localStorage alone meant a storage
// wipe (or a private window) silently reset the free-allowance identity — the
// meter's accepted leak. The token map now lives in THREE stores and heals
// itself: every read merges localStorage + the first-party cookie + IndexedDB
// (per-path, most-local wins) and re-populates whichever store lost its copy.
// A reader clearing one store keeps their allowance; clearing all three gets
// a fresh vid — that residual leak is capped server-side by the per-IP
// ceiling (meter.js ipCeilingExceeded, publisher-tunable). All stores degrade
// silently: private mode just means less continuity, never a broken wall.
const METER_KEY = "ct:meter";
const METER_COOKIE = "ct_meter";
const METER_COOKIE_MAXAGE = 400 * 24 * 3600; // seconds — browser cap is ~400 days
const METER_COOKIE_LIMIT = 3800; // stay under the 4 KB per-cookie ceiling
const METER_IDB_DB = "ct-meter";
const METER_IDB_STORE = "kv";
const METER_IDB_KEY = "tokens";

function b64urlEncode(s: string): string {
  return btoa(unescape(encodeURIComponent(s))).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}
function b64urlDecode(s: string): string {
  const pad = s.length % 4 === 0 ? "" : "=".repeat(4 - (s.length % 4));
  return decodeURIComponent(escape(atob(s.replace(/-/g, "+").replace(/_/g, "/") + pad)));
}
function parseTokenMap(raw: string | null | undefined): Record<string, unknown> {
  try {
    const parsed = raw ? JSON.parse(raw) : {};
    return parsed && typeof parsed === "object" && !Array.isArray(parsed) ? parsed : {};
  } catch {
    return {};
  }
}

function lsRead(): Record<string, unknown> {
  try {
    return parseTokenMap(window.localStorage?.getItem(METER_KEY));
  } catch {
    return {};
  }
}
function lsWrite(map: Record<string, unknown>): void {
  try {
    window.localStorage?.setItem(METER_KEY, JSON.stringify(map));
  } catch {
    /* storage blocked */
  }
}

function cookieRead(): Record<string, unknown> {
  try {
    const m = document.cookie.match(new RegExp("(?:^|; )" + METER_COOKIE + "=([^;]*)"));
    return m ? parseTokenMap(b64urlDecode(m[1])) : {};
  } catch {
    return {};
  }
}
function cookieWrite(map: Record<string, unknown>): void {
  try {
    const keys = Object.keys(map);
    if (keys.length === 0) return;
    const enc = b64urlEncode(JSON.stringify(map));
    if (enc.length > METER_COOKIE_LIMIT) return; // too big — IDB is the surviving backup
    const secure = window.location.protocol === "https:" ? "; Secure" : "";
    document.cookie = `${METER_COOKIE}=${enc}; path=/; max-age=${METER_COOKIE_MAXAGE}; SameSite=Lax${secure}`;
  } catch {
    /* cookies blocked */
  }
}

function idbOpen(): Promise<IDBDatabase | null> {
  return new Promise((resolve) => {
    try {
      if (!window.indexedDB) return resolve(null);
      const req = window.indexedDB.open(METER_IDB_DB, 1);
      req.onupgradeneeded = () => {
        try {
          req.result.createObjectStore(METER_IDB_STORE);
        } catch {
          /* already exists */
        }
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => resolve(null);
      req.onblocked = () => resolve(null);
    } catch {
      resolve(null);
    }
  });
}
async function idbRead(): Promise<Record<string, unknown>> {
  const db = await idbOpen();
  if (!db) return {};
  return new Promise((resolve) => {
    try {
      const tx = db.transaction(METER_IDB_STORE, "readonly");
      const req = tx.objectStore(METER_IDB_STORE).get(METER_IDB_KEY);
      req.onsuccess = () => resolve(typeof req.result === "string" ? parseTokenMap(req.result) : {});
      req.onerror = () => resolve({});
    } catch {
      resolve({});
    }
  });
}
async function idbWrite(map: Record<string, unknown>): Promise<void> {
  const db = await idbOpen();
  if (!db) return;
  return new Promise((resolve) => {
    try {
      const tx = db.transaction(METER_IDB_STORE, "readwrite");
      tx.objectStore(METER_IDB_STORE).put(JSON.stringify(map), METER_IDB_KEY);
      tx.oncomplete = () => resolve();
      tx.onerror = () => resolve();
    } catch {
      resolve();
    }
  });
}

function sameMap(a: Record<string, unknown>, b: Record<string, unknown>): boolean {
  const ka = Object.keys(a);
  const kb = Object.keys(b);
  return ka.length === kb.length && ka.every((k) => JSON.stringify(a[k]) === JSON.stringify(b[k]));
}

/**
 * M1: merged read across all three stores, with self-healing — any store
 * missing entries gets re-populated from the survivors. Per-path precedence:
 * localStorage > IndexedDB > cookie (the fast store is written most often).
 */
export async function meterTokensReadMerged(): Promise<Record<string, unknown>> {
  const ls = lsRead();
  const [idb, ck] = await Promise.all([idbRead(), Promise.resolve(cookieRead())]);
  const merged: Record<string, unknown> = { ...ck, ...idb, ...ls };
  try {
    if (!sameMap(merged, ls)) lsWrite(merged);
    if (!sameMap(merged, ck)) cookieWrite(merged);
    if (!sameMap(merged, idb)) void idbWrite(merged);
  } catch {
    /* healing is best-effort — the merged read itself is what matters */
  }
  return merged;
}

export function meterTokenStore(path: string, token: unknown): void {
  try {
    // Merge with the synchronous stores so a wiped localStorage doesn't cost
    // the OTHER paths' tokens when the cookie still holds them.
    const all = { ...cookieRead(), ...lsRead() };
    all[path] = token;
    lsWrite(all);
    cookieWrite(all);
    void idbWrite(all);
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
  // M1: merged multi-storage read — a wiped localStorage restores the meter
  // identity from the cookie/IndexedDB BEFORE the offer goes out, so the
  // registry re-mints with the SAME vid instead of issuing a fresh allowance.
  const tokens = Object.values(await meterTokensReadMerged());
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

// ─── Email gate (A5, spec §5.5) ─────────────────────────────────────
// The reader-facing steps hit the WP site's OWN REST endpoint (WP is the data
// controller — it stores the address + consent) which forwards server-side to
// the registry; only the redeem step talks to the registry directly (public).
// restBase comes from the mount's data-rest-url — subdirectory installs break
// a hardcoded /wp-json, so it is emitted server-side via rest_url().

export interface EmailRequestResult {
  status: string;
  expires_in: number;
}

/** Ask the site to email a one-time access link to the reader. */
export async function requestEmailLink(
  restBase: string,
  payload: { email: string; content_id: string; consent_functional: boolean; consent_marketing: boolean; consent_text: string },
): Promise<EmailRequestResult> {
  const res = await safeFetch(`${restBase.replace(/\/$/, "")}/email/request`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
  if (res.status === 200) {
    return (await res.json()) as EmailRequestResult;
  }
  const body = (await res.json().catch(() => ({}))) as { code?: string; message?: string };
  const code = typeof body.code === "string" ? body.code : `email_${res.status}`;
  // The PHP side already maps registry failures to plain sentences — prefer
  // its message, fall back to honest generic copy.
  const message =
    typeof body.message === "string" && body.message
      ? body.message
      : code === "invalid_email"
        ? "That doesn't look like an email address."
        : code === "rate_limited"
          ? "Too many requests — please try again later."
          : "We couldn't send the email — please try again in a moment.";
  throw new UnlockError(message, code);
}

/** Exchange the one-time magic-link token for the CEK (public registry route). */
export async function redeemEmailGrant(registryBase: string, token: string): Promise<KeyResponse> {
  const res = await safeFetch(`${registryBase.replace(/\/$/, "")}/v1/email/redeem`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ token }),
  });
  if (res.status === 200) {
    return (await res.json()) as KeyResponse;
  }
  if (res.status === 410) {
    throw new UnlockError("This link has expired or was already used — request a new one below.", "grant_expired_or_used");
  }
  if (res.status === 400) {
    throw new UnlockError("This access link is malformed.", "malformed_token");
  }
  if (res.status === 429) {
    throw new UnlockError("Too many attempts — please try again later.", "rate_limited");
  }
  throw new UnlockError("Could not verify your access link.", `email_redeem_${res.status}`);
}

/** Tell WP the grant redeemed so it can mark the subscriber verified (fail-open). */
export function markEmailVerified(restBase: string, token: string): void {
  void safeFetch(`${restBase.replace(/\/$/, "")}/email-verified`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ token }),
  }).catch(() => {
    /* bookkeeping only — the reader already holds the CEK */
  });
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
