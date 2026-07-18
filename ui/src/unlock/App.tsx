import { useEffect, useRef, useState, type CSSProperties } from "react";
import { fetchOffer, hasStripeKey, hasWallet, UnlockError } from "./api";
import { offerToRails, type RailTile, type SignedOffer } from "./offer";
import { payX402, startStripe } from "./payments";
import { sanitizeBody } from "./sanitize";
import { unseal, type SealedBlob } from "./unseal";

type State = "idle" | "loading" | "menu" | "stripe" | "processing" | "unlocked" | "error" | "unavailable";

const card: CSSProperties = {
  border: "1px solid var(--ct-border)",
  background: "var(--ct-surface)",
  borderRadius: 14,
  padding: 20,
  marginTop: 16,
};

export function App({ mount, blob }: { mount: HTMLElement; blob: SealedBlob | null }) {
  const contentId = mount.dataset.contentId || blob?.content_id || "";
  const ready = !!blob && blob.magic === "ct_sealed_v1" && contentId !== "";

  const [state, setState] = useState<State>(ready ? "idle" : "unavailable");
  const [offer, setOffer] = useState<SignedOffer | null>(null);
  const [tiles, setTiles] = useState<RailTile[]>([]);
  const [error, setError] = useState("");
  const [bodyHtml, setBodyHtml] = useState("");
  const [stripePass, setStripePass] = useState("");
  const stripeNode = useRef<HTMLDivElement>(null);
  const stripeConfirm = useRef<null | (() => Promise<string>)>(null);

  const fail = (e: unknown) => {
    setError(e instanceof UnlockError ? e.message : "Something went wrong.");
    setState("error");
  };

  const reveal = async (cek: string) => {
    if (!blob) {
      return;
    }
    try {
      setBodyHtml(sanitizeBody(await unseal(blob, cek)));
      setState("unlocked");
    } catch {
      setError("Unlock failed — the content could not be decrypted.");
      setState("error");
    }
  };

  const loadMenu = async () => {
    setState("loading");
    try {
      const o = await fetchOffer(contentId);
      const t = offerToRails(o, { hasStripeKey: hasStripeKey(), hasWallet: hasWallet() });
      if (t.length === 0) {
        setState("unavailable");
        return;
      }
      setOffer(o);
      setTiles(t);
      setState("menu");
    } catch (e) {
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
      try {
        await reveal(await payX402(contentId, offer));
      } catch (e) {
        fail(e);
      }
    }
  };

  // Mount the Stripe Payment Element once the stripe node is in the DOM.
  useEffect(() => {
    if (state !== "stripe" || !stripeNode.current || !stripePass) {
      return;
    }
    let cancelled = false;
    startStripe(contentId, stripePass, stripeNode.current)
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
        <>
          <p style={{ fontSize: 15, fontWeight: 600 }}>Keep reading</p>
          <p style={{ fontSize: 13, color: "var(--ct-muted)", margin: "4px 0 12px" }}>Unlock the rest of this article.</p>
          <button type="button" onClick={loadMenu} style={btn}>
            Unlock
          </button>
          {footer}
        </>
      ) : state === "loading" || state === "processing" ? (
        <p style={{ fontSize: 14, color: "var(--ct-muted)" }}>{state === "processing" ? "Confirming payment…" : "Loading…"}</p>
      ) : state === "stripe" ? (
        <>
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
