import { useEffect, useRef, useState, type CSSProperties } from "react";
import { fetchOffer, hasStripeKey, hasWallet, meterTokenStore, redeemMeter, UnlockError } from "./api";
import { offerToRails, type RailTile, type SignedOffer } from "./offer";
import { payX402, startStripe } from "./payments";
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
function cekGet(contentId: string): string | null {
  try {
    return window.localStorage?.getItem(CEK_PREFIX + contentId) ?? null;
  } catch {
    return null; // storage blocked (private mode) — pay flow still works
  }
}
function cekSet(contentId: string, cek: string): void {
  try {
    window.localStorage?.setItem(CEK_PREFIX + contentId, cek);
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

export function App({ mount, blob }: { mount: HTMLElement; blob: SealedBlob | null }) {
  const contentId = mount.dataset.contentId || blob?.content_id || "";
  const ready = !!blob && blob.magic === "ct_sealed_v1" && contentId !== "";
  const idlePrice = fmtIdlePrice(
    parseInt(mount.dataset.priceMicros || "0", 10),
    mount.dataset.currency || "USD",
  );

  const [state, setState] = useState<State>(ready ? "idle" : "unavailable");
  const [offer, setOffer] = useState<SignedOffer | null>(null);
  const [tiles, setTiles] = useState<RailTile[]>([]);
  const [error, setError] = useState("");
  const [bodyHtml, setBodyHtml] = useState("");
  const [walletHint, setWalletHint] = useState(false);
  const [stripePass, setStripePass] = useState("");
  const [expressUp, setExpressUp] = useState(false);
  const stripeNode = useRef<HTMLDivElement>(null);
  const stripeExpressNode = useRef<HTMLDivElement>(null);
  const stripeConfirm = useRef<null | (() => Promise<string>)>(null);

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

  const reveal = async (cek: string, fromCache = false) => {
    if (!blob) {
      return;
    }
    if (!fromCache) {
      // Persist at RELEASE time, before decrypt: the registry only reaches this
      // point after a verified settlement, so a local failure afterwards (plain
      // HTTP, tab crash) must never force a second payment — the retry unlocks
      // from this cache.
      cekSet(contentId, cek);
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

  // Returning reader: a previously released CEK unlocks instantly, no payment.
  useEffect(() => {
    if (!ready) {
      return;
    }
    const cached = cekGet(contentId);
    if (cached) {
      void reveal(cached, true);
    }
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
        await reveal(await payX402(contentId, offer));
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
      async (cek) => {
        if (!cancelled) {
          await reveal(cek);
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
      await reveal(await stripeConfirm.current());
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
        offer?.meter && offer.meter.remaining > 0 ? (
          <>
            <p style={{ fontSize: 15, fontWeight: 600 }}>Keep reading</p>
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
                {idlePrice ? `or unlock forever for ${idlePrice} — one-time` : "or unlock forever — one-time"}
              </button>
            </p>
            {footer}
          </>
        ) : (
          <>
            <p style={{ fontSize: 15, fontWeight: 600 }}>Keep reading</p>
            <p style={{ fontSize: 13, color: "var(--ct-muted)", margin: "4px 0 12px" }}>
              {idlePrice
                ? `Unlock the rest of this article for ${idlePrice} — one-time, no subscription.`
                : "Unlock the rest of this article."}
            </p>
            {offer?.meter && offer.meter.remaining <= 0 ? (
              <p style={{ fontSize: 12, color: "var(--ct-muted)", margin: "0 0 10px" }}>
                You've read your {offer.meter.count} free article{offer.meter.count === 1 ? "" : "s"} for this {offer.meter.window_days}-day window.
              </p>
            ) : null}
            <button type="button" onClick={() => loadMenu()} style={btn}>
              {idlePrice ? `Unlock for ${idlePrice}` : "Unlock"}
            </button>
            {footer}
          </>
        )
      ) : state === "loading" || state === "processing" ? (
        <>
          <p style={{ fontSize: 14, color: "var(--ct-muted)" }}>{state === "processing" ? "Confirming payment…" : "Loading…"}</p>
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
          <p style={{ fontSize: 15, fontWeight: 600, marginBottom: 8 }}>Choose how to unlock</p>
          <div style={{ display: "grid", gap: 8 }}>
            {offer?.meter && offer.meter.remaining > 0 ? (
              <button type="button" onClick={readFree} style={{ ...tileBtn, border: "1px solid var(--ct-accent)" }}>
                <span style={{ fontWeight: 600 }}>Read free now</span>
                <span style={{ color: "var(--ct-muted)" }}>
                  {offer.meter.remaining - 1} of {offer.meter.count} free articles left after this one
                </span>
              </button>
            ) : null}
            {offer?.meter && offer.meter.remaining <= 0 ? (
              <p style={{ fontSize: 12, color: "var(--ct-muted)", margin: "0 0 4px" }}>
                You've read your {offer.meter.count} free article{offer.meter.count === 1 ? "" : "s"} for this {offer.meter.window_days}-day window — unlock to keep reading.
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
