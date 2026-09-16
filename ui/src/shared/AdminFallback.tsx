import { describeError } from "./ErrorBoundary";

/** Error-boundary fallback for the wp-admin apps: a card, two buttons, the detail. */
export function AdminFallback({ error, onRetry }: { error: Error; onRetry: () => void }) {
  return (
    <div className="ct-card ct-card--warn" role="alert" style={{ margin: "16px 0" }}>
      <h3 style={{ marginTop: 0 }}>This screen hit an error and stopped.</h3>
      <p>
        Nothing was lost. Try again or reload the page. The error is now listed under Settings → CrawlerToll → Health
        and diagnostics, ready to copy for support.
      </p>
      <p>
        <button type="button" className="button button-primary" onClick={onRetry}>
          Try again
        </button>{" "}
        <button type="button" className="button" onClick={() => window.location.reload()}>
          Reload
        </button>
      </p>
      <details>
        <summary style={{ cursor: "pointer" }}>Details</summary>
        <code>{describeError(error)}</code>
      </details>
    </div>
  );
}
