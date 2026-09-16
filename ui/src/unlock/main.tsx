import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "../index.css";
import { App } from "./App";
import { clientError, wallEvent } from "./api";
import { ErrorBoundary, describeError } from "../shared/ErrorBoundary";
import type { SealedBlob } from "./unseal";

// Front-end unlock app. Mounts into the gate's locked region (3.3 emits
// <div class="ct-sealed-body crawlertoll-locked" data-...> + an inline
// <script type="application/ct-sealed+json"> blob). No fixed #id — query the
// marker the gate already renders. Guard so a page without it never throws.
const mount = document.querySelector<HTMLElement>(".ct-sealed-body.crawlertoll-locked");
const blobEl = document.querySelector('script[type="application/ct-sealed+json"]');

let blob: SealedBlob | null = null;
if (blobEl?.textContent) {
  try {
    blob = JSON.parse(blobEl.textContent) as SealedBlob;
  } catch {
    blob = null;
  }
}

// A thrown render must never leave the reader a blank sealed region: keep the
// Sealed look, offer retry/reload, count it as an unavailable wall and report
// the error to the site (Settings → Health). The body stays encrypted.
function WallFallback({ mount, onRetry }: { mount: HTMLElement; onRetry: () => void }) {
  const text = mount.dataset.wallUnavailable || "This article is sealed, and the unlock panel could not start.";
  return (
    <div className="ct-pro ct-wall" role="alert">
      <div className="ct-wall__head">
        <span className="ct-wall__seal" aria-hidden="true">
          ✕
        </span>
        <h3 className="ct-wall__title">Sealed</h3>
      </div>
      <p className="ct-wall__lede">{text}</p>
      <p className="ct-wall__error">Something went wrong while starting the unlock panel.</p>
      <p>
        <button type="button" className="ct-btn" onClick={onRetry}>
          Try again
        </button>{" "}
        <button type="button" className="ct-link" onClick={() => window.location.reload()}>
          Reload the page
        </button>
      </p>
      <p className="ct-wall__foot">The error was reported to this site.</p>
    </div>
  );
}

if (mount) {
  const restBase = mount.dataset.restUrl ?? "";
  createRoot(mount).render(
    <StrictMode>
      <ErrorBoundary
        onError={(err) => {
          wallEvent(restBase, "wall_unavailable");
          clientError(restBase, "unlock-app", describeError(err));
        }}
        fallback={(_err, reset) => <WallFallback mount={mount} onRetry={reset} />}
      >
        <App mount={mount} blob={blob} />
      </ErrorBoundary>
    </StrictMode>,
  );
}
