// The free-app's first real surface: the settings-page status header, rendered
// from server data passed via window.crawlertollFree (set by an inline script in
// CrawlerToll_Admin::enqueue_assets). It reuses the existing admin.css .ct-*
// classes so it's visually identical to the PHP fallback it replaces — the point
// of WS1 is to prove the PHP→React data seam and the mount, not to restyle yet.

interface CtFreeData {
  enabled: boolean;
  botCount: number;
  policyGroups: number;
  priceMicros: number;
  currency: string;
}

declare global {
  interface Window {
    crawlertollFree?: CtFreeData;
  }
}

const FALLBACK: CtFreeData = {
  enabled: true,
  botCount: 0,
  policyGroups: 0,
  priceMicros: 0,
  currency: "USD",
};

export function App() {
  const d = window.crawlertollFree ?? FALLBACK;
  const price = (d.priceMicros / 1_000_000).toFixed(4);

  return (
    <div className="ct-status-bar">
      <div className="ct-stat-card">
        <div className={`ct-stat-icon ${d.enabled ? "green" : "amber"}`}>
          <span
            className={`dashicons ${d.enabled ? "dashicons-shield" : "dashicons-shield-alt"}`}
          />
        </div>
        <div className="ct-stat-value">{d.enabled ? "Active" : "Paused"}</div>
        <div className="ct-stat-label">Enforcement</div>
        {/* Tailwind utilities (no Preflight) — also marks the live React render. */}
        <span className="mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white" style={{ background: "var(--ct-accent)" }}>
          Live
        </span>
      </div>
      <div className="ct-stat-card">
        <div className="ct-stat-icon purple">
          <span className="dashicons dashicons-networking" />
        </div>
        <div className="ct-stat-value">{d.botCount}</div>
        <div className="ct-stat-label">AI Crawlers Detected</div>
      </div>
      <div className="ct-stat-card">
        <div className="ct-stat-icon blue">
          <span className="dashicons dashicons-admin-generic" />
        </div>
        <div className="ct-stat-value">{d.policyGroups}</div>
        <div className="ct-stat-label">Policy Groups</div>
      </div>
      <div className="ct-stat-card">
        <div className={`ct-stat-icon ${d.priceMicros > 0 ? "green" : "amber"}`}>
          <span className="dashicons dashicons-money" />
        </div>
        <div className="ct-stat-value">${price}</div>
        <div className="ct-stat-label">Per Crawl</div>
      </div>
    </div>
  );
}
