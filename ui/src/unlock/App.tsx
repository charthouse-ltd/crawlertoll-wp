import { useEffect, useRef, useState, type CSSProperties } from "react";
import { fetchOffer, hasStripeKey, hasWallet, meterTokenStore, redeemMeter, renewPass, UnlockError } from "./api";
import type { SettlementPass } from "./api";
import { offerToRails, type RailTile, type SignedOffer } from "./offer";
import { payX402, startStripe } from "./payments";
import type { PaidRelease } from "./payments";
import { sanitizeBody } from "./sanitize";
import { unseal, InsecureContextError, type SealedBlob } from "./unseal";

type State = "idle" | "loading" | "menu" | "stripe" | "processing" | "unlocked" | "error" | "unavailable";

// CEK persistence (R1 launch blocker, lineup freeze 2026-08-05 D8-2): a reader
// who paid keeps access across reloads — and across LOCAL post-settlement
// failures (plain-HTTP crypto.subtle, a crashed tab) without paying twice.
// The cached CEK decrypts exactly one article on one origin; a script able to
// read localStorage could equally read the already-decrypted DOM, so this does
// not widen the XSS surface beyond what a successful unlock exposes. The CEK
// is never transmitted anywhere by this code.
const CEK_PREFIX = "ct:cek:";
// A1 cache entry: the CEK plus the settlement pass it was released with.
// `e` (expires_at) is null/absent for no-expiry access — the common case
// until duration tiers (A2) set it. Legacy plain-string entries (pre-A1)
// still read back as a bare CEK.
interface CekEntry {
  c: string;
  p?: string; // settlement pass_id (renewal proof)
  e?: string | null; // ISO expiry of THIS cache entry; null = no expiry
}
function cekRead(contentId: string): CekEntry | null {
  try {
    const raw = window.localStorage?.getItem(CEK_PREFIX + contentId);
    if (!raw) {
      return null;
    }
    if (raw.startsWith("{")) {
      const parsed = JSON.parse(raw) as CekEntry;
      return parsed && typeof parsed.c === "string" ? parsed : null;
    }
    return { c: raw }; // legacy plain-CEK entry
  } catch {
    return null; // storage blocked (private mode) — pay flow still works
  }
}
function cekSet(contentId: string, cek: string, pass?: SettlementPass): void {
  try {
    const entry: CekEntry = pass ? { c: cek, p: pass.pass_id, e: pass.expires_at } : { c: cek };
    window.localStorage?.setItem(CEK_PREFIX + contentId, JSON.stringify(entry));
  } catch {
    /* storage blocked — session-only unlock, acceptable degradation */
  }
}
function cekClear(contentId: string): void {
  try {
    window.localStorage?.removeItem(CEK_PREFIX + contentId);
  } catch {
    /* ignore */
  }
}

const card: CSSProperties = {
  border: "1px solid var(--ct-border)",
  background: "var(--ct-surface)",
  borderRadius: 14,
  padding: 20,
  marginTop: 16,
};

// Price for the idle card (fresh-eyes audit 2026-08-25): a reader should never
// have to click "Unlock" blind. The mount carries the publisher's configured
// price (data-price-micros / data-currency) — no network call needed; the
// rail menu after the click still shows the offer-authoritative price.
function fmtIdlePrice(micros: number, currency: string): string | null {
  if (!Number.isFinite(micros) || micros <= 0) return null;
  const v = micros / 1_000_000;
  const str = v >= 0.01 ? v.toFixed(2) : v.toFixed(4).replace(/0+$/, "").replace(/\.$/, "");
  const sym: Record<string, string> = { USD: "$", EUR: "€", GBP: "£" };
  return (sym[currency] ?? currency + " ") + str;
}

// A3 (spec §5.4): publisher-editable wall templates ride the mount as
// data-wall-* attributes (plain text, kses-stripped server-side). Placeholders
// substitute HERE — the client is the only place that knows {price}/{remaining}
// for this reader. A placeholder with no value (unknown name, empty var)
// degrades the WHOLE string to the built-in default: a raw "{…}" never
// reaches the reader.
function tpl(template: string | undefined, vars: Record<string, string>): string | null {
  if (!template) {
    return null;
  }
  let missing = false;
  const out = template.replace(/\{([a-z_]+)\}/g, (m, name: string) => {
    const v = vars[name];
    if (v === undefined || v === "") {
      missing = true;
      return m;
    }
    return v;
  });
  return missing || /\{[a-z_]+\}/.test(out) ? null : out;
}

export function App({ mount, blob }: { mount: HTMLElement; blob: SealedBlob | null }) {
  const contentId = mount.dataset.contentId || blob?.content_id || "";
  const ready = !!blob && blob.magic === "ct_sealed_v1" && contentId !== "";
  const idlePrice = fmtIdlePrice(
    parseInt(mount.dataset.priceMicros || "0", 10),
    mount.dataset.currency || "USD",
  );

  // A3: resolved wall templates (site settings + per-article override, merged
  // server-side). Each falls back to the shipped copy when unset or when its
  // placeholders can't all be substituted for this reader.
  const siteVars: Record<string, string> = { site_name: mount.dataset.siteName || "" };
  const wallHeading = tpl(mount.dataset.wallHeading, siteVars) || "Keep reading";
  const wallValue = tpl(mount.dataset.wallValue, { ...siteVars, price: idlePrice || "", currency: mount.dataset.currency || "USD" });
  const meterVars = (m: { count: number; window_days: number; remaining: number }): Record<string, string> => ({
    ...siteVars,
    count: String(m.count),
    window_days: String(m.window_days),
    remaining: String(m.remaining),
  });

  const [state, setState] = useState<State>(ready ? "idle" : "unavailable");
  const [offer, setOffer] = useState<SignedOffer | null>(null);
  const [tiles, setTiles] = useState<RailTile[]>([]);
  const [error, setError] = useState("");
  const [bodyHtml, setBodyHtml] = useState("");
  const [walletHint, setWalletHint] = useState(false);
  const [renewing, setRenewing] = useState(false);
  // A2: after an expired duration tier fails silent renewal, the wall returns
  // with a one-line "access ended" note so the re-pay is understood as a
  // renewal, not a double charge. (A3 refines the full renew copy.)
  const [renewNote, setRenewNote] = useState("");
  const [stripePass, setStripePass] = useState("");
  const [expressUp, setExpressUp] = useState(false);
  const stripeNode = useRef<HTMLDivElement>(null);
  const stripeExpressNode = useRef<HTMLDivElement>(null);
  const stripeConfirm = useRef<null | (() => Promise<PaidRelease>)>(null);

  const fail = (e: unknown) => {
    setError(e instanceof UnlockError ? e.message : "Something went wrong.");
    setState("error");
  };

  const applyOffer = (o: SignedOffer) => {
    // Metered free articles: persist the (possibly re-minted) token so the next
    // visit — here or on another article of this section — keeps the allowance.
    if (o.meter && o.meter.token) {
      const path = typeof (o.meter.token as { path?: unknown }).path === "string"
        ? (o.meter.token as { path: string }).path
        : "/";
      meterTokenStore(path, o.meter.token);
    }
    setOffer(o);
    setTiles(offerToRails(o, { hasStripeKey: hasStripeKey(), hasWallet: hasWallet() }));
  };

  // Prefetch the offer on mount so the idle card can LEAD with the free-read
  // option when the reader has meter allowance left (Chris QA 2026-08-28: the
  // old click-to-discover flow hid "Read free now" behind a price button — a
  // reader with free reads should never see a price first). One cheap POST per
  // premium pageview; no allowance is burned until the reader actually reads.
  useEffect(() => {
    if (!ready) {
      return;
    }
    let cancelled = false;
    fetchOffer(contentId)
      .then((o) => {
        if (!cancelled) {
          applyOffer(o);
        }
      })
      .catch(() => {
        /* silent — the Unlock button retries via loadMenu and surfaces the error */
      });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Paywall fade cleanup: the gate wraps the free preview in a masked
  // ".crawlertoll-fade-preview" div so the text visually dissolves at the
  // cut. Once unlocked, paying readers get crisp text — strip the mask.
  useEffect(() => {
    if (state !== "unlocked") {
      return;
    }
    document.querySelectorAll<HTMLElement>(".crawlertoll-fade-preview").forEach((el) => {
      el.style.removeProperty("-webkit-mask-image");
      el.style.removeProperty("mask-image");
    });
  }, [state]);

  const reveal = async (cek: string, fromCache = false, pass?: SettlementPass) => {
    if (!blob) {
      return;
    }
    if (!fromCache) {
      // Persist at RELEASE time, before decrypt: the registry only reaches this
      // point after a verified settlement, so a local failure afterwards (plain
      // HTTP, tab crash) must never force a second payment — the retry unlocks
      // from this cache. The settlement pass rides along (A1): it is the
      // re-access proof when this cache entry expires or partially survives.
      cekSet(contentId, cek, pass);
    }
    try {
      setBodyHtml(sanitizeBody(await unseal(blob, cek)));
      setState("unlocked");
    } catch (e) {
      if (fromCache) {
        // Stale cache (publisher edited + re-sealed under the same content_id):
        // drop it and send the reader back through the pay flow — never show a
        // bogus "could not be decrypted" for a key that was once valid.
        cekClear(contentId);
        setState("idle");
        return;
      }
      setError(
        e instanceof InsecureContextError
          ? "This page needs a secure (https) connection to decrypt the content. Reload the page with https:// — your payment is saved on this device and you will not be charged again."
          : "Unlock failed — the content could not be decrypted.",
      );
      setState("error");
    }
  };

  // A1: silent renewal. The cache entry expired (duration-limited access) but
  // the settlement pass may still be valid — re-present it for a fresh CEK,
  // no new payment. On pass_expired the wall returns (it IS the renew path:
  // the reader simply pays again — A3 adds the explicit "access ended" copy).
  const renewWithPass = async (passId: string) => {
    setRenewing(true);
    setState("processing");
    try {
      const r = await renewPass(contentId, passId);
      setRenewNote("");
      await reveal(r.cek, false, r.pass ?? { pass_id: passId, expires_at: null });
    } catch (e) {
      cekClear(contentId);
      if (e instanceof UnlockError && e.code === "pass_expired") {
        setRenewNote("Your access to this article has ended — renew below.");
      }
      setState("idle");
    } finally {
      setRenewing(false);
    }
  };

  // Returning reader: a previously released CEK unlocks instantly, no payment.
  // An EXPIRED cache entry tries the silent pass renewal before giving up.
  useEffect(() => {
    if (!ready) {
      return;
    }
    const cached = cekRead(contentId);
    if (!cached) {
      return;
    }
    if (cached.e && Date.now() > Date.parse(cached.e)) {
      cekClear(contentId); // the dead CEK is worthless…
      if (cached.p) {
        void renewWithPass(cached.p); // …but the pass may still be valid
      }
      return;
    }
    void reveal(cached.c, true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const loadMenu = async (force = false) => {
    if (offer && !force) {
      setState("menu"); // already prefetched on mount
      return;
    }
    setState("loading");
    try {
      const o = await fetchOffer(contentId);
      applyOffer(o);
      const t = offerToRails(o, { hasStripeKey: hasStripeKey(), hasWallet: hasWallet() });
      if (t.length === 0 && !(o.meter && o.meter.remaining > 0)) {
        setState("unavailable");
        return;
      }
      setState("menu");
    } catch (e) {
      fail(e);
    }
  };

  // Metered free read: redeem the allowance instead of paying. A rejected grant
  // (exhausted in another tab, expired token) re-fetches the offer — the menu
  // then shows the paid rails with remaining 0.
  const readFree = async () => {
    if (!offer?.meter) {
      return;
    }
    setState("processing");
    try {
      const { cek } = await redeemMeter(contentId, offer.meter.token);
      await reveal(cek);
    } catch (e) {
      if (e instanceof UnlockError && (e.code === "unsettled" || e.code.startsWith("key_"))) {
        await loadMenu(true); // force a fresh offer — the allowance may be gone
        return;
      }
      fail(e);
    }
  };

  const pick = async (tile: RailTile) => {
    if (!tile.enabled) {
      return;
    }
    if (tile.rail === "stripe" && tile.passId) {
      setStripePass(tile.passId);
      setState("stripe");
    } else if (tile.rail === "x402" && offer) {
      setState("processing");
      // A LOCKED wallet makes eth_requestAccounts hang silently — no rejection,
      // no event. If we're still waiting after a few seconds, say where the
      // reader should look instead of spinning "Processing…" forever.
      setWalletHint(false);
      const hintTimer = window.setTimeout(() => setWalletHint(true), 2500);
      try {
        // A2: a tier tile pays that tier's price and echoes its tier_id — the
        // registry re-derives both server-side (spec §3).
        const r = await payX402(contentId, offer, tile.tier);
        setRenewNote("");
        await reveal(r.cek, false, r.pass);
      } catch (e) {
        fail(e);
      } finally {
        window.clearTimeout(hintTimer);
        setWalletHint(false);
      }
    }
  };

  // Mount the Stripe Payment Element once the stripe node is in the DOM.
  // The Express Checkout slot (one-tap Apple Pay / Google Pay / Link) mounts
  // above the card form; it collapses itself when no wallet is available.
  useEffect(() => {
    if (state !== "stripe" || !stripeNode.current || !stripePass) {
      return;
    }
    let cancelled = false;
    startStripe(
      contentId,
      stripePass,
      stripeNode.current,
      stripeExpressNode.current,
      async (res) => {
        if (!cancelled) {
          await reveal(res.cek, false, res.pass);
        }
      },
      (message) => {
        if (!cancelled) {
          setError(message);
          setState("error");
        }
      },
      (available) => {
        if (!cancelled) {
          setExpressUp(available);
        }
      },
    )
      .then((confirm) => {
        if (!cancelled) {
          stripeConfirm.current = confirm;
        }
      })
      .catch((e) => {
        if (!cancelled) {
          fail(e);
        }
      });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state, stripePass, contentId]);

  const confirmStripe = async () => {
    if (!stripeConfirm.current) {
      return;
    }
    setState("processing");
    try {
      const r = await stripeConfirm.current();
      await reveal(r.cek, false, r.pass);
    } catch (e) {
      fail(e);
    }
  };

  if (state === "unlocked") {
    // The decrypted body, sanitized. (Raw post_content — dynamic blocks/shortcodes
    // render via the_content server-side in a follow-up; static HTML renders here.)
    return <div className="ct-unlocked" dangerouslySetInnerHTML={{ __html: bodyHtml }} />;
  }

  const footer = (
    <p style={{ marginTop: 12, fontSize: 11, color: "var(--ct-muted)" }}>
      Powered by CrawlerToll · you pay the publisher directly
    </p>
  );

  return (
    <div className="ct-pro" style={card}>
      {state === "unavailable" ? (
        <p style={{ fontSize: 14, color: "var(--ct-muted)" }}>The rest of this content is currently unavailable to unlock.</p>
      ) : state === "error" ? (
        <>
          <p style={{ fontSize: 14, fontWeight: 600 }}>{error}</p>
          <button type="button" onClick={() => setState("menu")} style={btn}>
            Try again
          </button>
        </>
      ) : state === "idle" ? (
        <>
          {renewNote ? (
            <p style={{ fontSize: 13, fontWeight: 600, color: "var(--ct-text)", margin: "0 0 8px" }}>{renewNote}</p>
          ) : null}
          {offer?.meter && offer.meter.remaining > 0 ? (
          <>
            <p style={{ fontSize: 15, fontWeight: 600 }}>{wallHeading}</p>
            <p style={{ fontSize: 13, color: "var(--ct-muted)", margin: "4px 0 12px" }}>
              {offer.meter.remaining} of {offer.meter.count} free articles left in this {offer.meter.window_days}-day window.
            </p>
            <button type="button" onClick={readFree} style={btn}>
              Read free now
            </button>
            <p style={{ marginTop: 10, fontSize: 12 }}>
              <button
                type="button"
                onClick={() => loadMenu()}
                style={{ background: "none", border: "none", padding: 0, color: "var(--ct-muted)", textDecoration: "underline", cursor: "pointer", fontSize: 12 }}
              >
                {idlePrice
                  ? `or pay ${idlePrice} once for this article — keeps your free reads for other articles`
                  : "or pay once for this article — keeps your free reads for other articles"}
              </button>
            </p>
            {footer}
          </>
        ) : (
          <>
            <p style={{ fontSize: 15, fontWeight: 600 }}>{wallHeading}</p>
            <p style={{ fontSize: 13, color: "var(--ct-muted)", margin: "4px 0 12px" }}>
              {wallValue ||
                (idlePrice
                  ? `Unlock the rest of this article for ${idlePrice} — one-time, no subscription.`
                  : "Unlock the rest of this article.")}
            </p>
            {offer?.meter && offer.meter.remaining <= 0 ? (
              <p style={{ fontSize: 12, color: "var(--ct-muted)", margin: "0 0 10px" }}>
                {tpl(mount.dataset.wallMeterOut, meterVars(offer.meter)) ||
                  `You've read your ${offer.meter.count} free article${offer.meter.count === 1 ? "" : "s"} for this ${offer.meter.window_days}-day window.`}
              </p>
            ) : null}
            <button type="button" onClick={() => loadMenu()} style={btn}>
              {idlePrice ? `Unlock for ${idlePrice}` : "Unlock"}
            </button>
            {footer}
          </>
          )}
        </>
      ) : state === "loading" || state === "processing" ? (
        <>
          <p style={{ fontSize: 14, color: "var(--ct-muted)" }}>
            {state === "processing" ? (renewing ? "Restoring your access…" : "Confirming payment…") : "Loading…"}
          </p>
          {state === "processing" && walletHint ? (
            <p style={{ fontSize: 13, color: "var(--ct-muted)", marginTop: 6 }}>
              Waiting for your wallet — it may be locked or holding a confirmation. Check MetaMask to continue.
            </p>
          ) : null}
        </>
      ) : state === "stripe" ? (
        <>
          <div ref={stripeExpressNode} style={{ marginBottom: 4 }} />
          {expressUp ? (
            <p style={{ fontSize: 12, color: "var(--ct-muted)", textAlign: "center", margin: "8px 0" }}>or pay with card</p>
          ) : null}
          <div ref={stripeNode} style={{ minHeight: 40 }} />
          <button type="button" onClick={confirmStripe} style={btn}>
            Pay &amp; unlock
          </button>
          {footer}
        </>
      ) : (
        <>
          {renewNote ? (
            <p style={{ fontSize: 13, fontWeight: 600, color: "var(--ct-text)", margin: "0 0 8px" }}>{renewNote}</p>
          ) : null}
          <p style={{ fontSize: 15, fontWeight: 600, marginBottom: 8 }}>Choose how to unlock</p>
          <div style={{ display: "grid", gap: 8 }}>
            {offer?.meter && offer.meter.remaining > 0 ? (
              <button type="button" onClick={readFree} style={{ ...tileBtn, border: "1px solid var(--ct-accent)" }}>
                <span style={{ fontWeight: 600 }}>Read free now</span>
                <span style={{ color: "var(--ct-muted)" }}>
                  uses 1 free read — {offer.meter.remaining - 1} of {offer.meter.count} left after this one
                </span>
              </button>
            ) : null}
            {offer?.meter && offer.meter.remaining <= 0 ? (
              <p style={{ fontSize: 12, color: "var(--ct-muted)", margin: "0 0 4px" }}>
                {tpl(mount.dataset.wallMeterOut, meterVars(offer.meter)) ||
                  `You've read your ${offer.meter.count} free article${offer.meter.count === 1 ? "" : "s"} for this ${offer.meter.window_days}-day window — unlock to keep reading.`}
              </p>
            ) : null}
            {tiles.map((t) => (
              <button
                key={t.key}
                type="button"
                disabled={!t.enabled}
                onClick={() => pick(t)}
                title={t.reason}
                style={{ ...tileBtn, opacity: t.enabled ? 1 : 0.55, cursor: t.enabled ? "pointer" : "not-allowed" }}
              >
                <span style={{ fontWeight: 600 }}>{t.label}</span>
                <span style={{ color: "var(--ct-muted)" }}>{t.enabled ? t.priceLabel : t.reason}</span>
              </button>
            ))}
          </div>
          {footer}
        </>
      )}
    </div>
  );
}

const btn: CSSProperties = {
  marginTop: 4,
  padding: "9px 18px",
  borderRadius: 10,
  border: "none",
  background: "var(--ct-accent)",
  color: "#fff",
  fontSize: 14,
  fontWeight: 600,
  cursor: "pointer",
};

const tileBtn: CSSProperties = {
  display: "flex",
  justifyContent: "space-between",
  alignItems: "center",
  padding: "12px 14px",
  borderRadius: 10,
  border: "1px solid var(--ct-border)",
  background: "var(--ct-elevated)",
  color: "var(--ct-text)",
  fontSize: 13,
};
