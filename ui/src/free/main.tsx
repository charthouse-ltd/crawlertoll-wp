import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "../index.css";
import { App } from "./App";
import { clientError } from "../unlock/api";
import { ErrorBoundary, describeError } from "../shared/ErrorBoundary";
import { AdminFallback } from "../shared/AdminFallback";

// Mount into our own node with our own bundled React 19 root — never share a
// tree with WordPress core's React 18 (wp-element). Guard on the node so a page
// without the mount div doesn't throw. A thrown render shows a card and reports
// to Settings → Health (via the REST root the inline data carries, if any).
const mount = document.getElementById("crawlertoll-free-app");
if (mount) {
  const restBase = mount.dataset.restUrl ?? "";
  createRoot(mount).render(
    <StrictMode>
      <ErrorBoundary
        onError={(err) => clientError(restBase, "free-app", describeError(err))}
        fallback={(err, reset) => <AdminFallback error={err} onRetry={reset} />}
      >
        <App />
      </ErrorBoundary>
    </StrictMode>,
  );
}
