import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "../index.css";
import { App, type ProView } from "./App";
import { proConfig } from "./api";
import { clientError } from "../unlock/api";
import { ErrorBoundary, describeError } from "../shared/ErrorBoundary";
import { AdminFallback } from "../shared/AdminFallback";

// Pro app — ships only in the Freemius premium bundle (stripped from the free
// zip). Mounts into #crawlertoll-pro-app with our own bundled React 19 root and
// renders the view named by the node's data-view attribute (set by each Pro
// tab's PHP). Replaces the server-rendered fallback (progressive enhancement).
// A thrown render shows a card (retry / reload) and reports to Settings → Health.
const VIEWS: ProView[] = ["dashboard", "logs", "pricing", "alerts", "rails"];
const mount = document.getElementById("crawlertoll-pro-app");
if (mount) {
  const raw = mount.dataset.view ?? "dashboard";
  const view: ProView = (VIEWS as string[]).includes(raw) ? (raw as ProView) : "dashboard";
  createRoot(mount).render(
    <StrictMode>
      <ErrorBoundary
        onError={(err) => clientError(proConfig.restUrl, "pro-app", describeError(err), proConfig.nonce)}
        fallback={(err, reset) => <AdminFallback error={err} onRetry={reset} />}
      >
        <App view={view} />
      </ErrorBoundary>
    </StrictMode>,
  );
}
