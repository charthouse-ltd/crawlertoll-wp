import { useState, type CSSProperties } from "react";
import { money, savePricing } from "../api";
import type { PathRule, SettingsResponse } from "../types";
import { Card, SaveButton, SettingsGate } from "../components/ui";

const SYMBOLS: Record<string, string> = { USD: "$", USDC: "$", EUR: "€", GBP: "£" };

const inputStyle: CSSProperties = {
  border: "1px solid var(--ct-border)",
  background: "var(--ct-elevated)",
  color: "var(--ct-text)",
  borderRadius: 8,
  padding: "8px 10px",
  fontSize: 13,
  width: "100%",
};

/** A rule row keeps the price in CURRENCY UNITS for the UI (people think in
 *  cents, not micros) and converts to micros only on save. Meter fields are
 *  plain integers — "" means the meter is off for this section. */
interface TierRow {
  amount: string; // currency units, e.g. "0.005"
  duration: string; // 'none' | '24' | '168' | '720' | 'custom'
  customDays: string; // only when duration === 'custom'
}

interface Row {
  path: string;
  amount: string; // e.g. "0.01"
  freeArticles: string; // "" = off, "3" = 3 free reads per window
  windowDays: string; // "" = 30
  tiers: TierRow[]; // access tiers (A2) — empty = legacy single price
  bundle: boolean; // bundle pass (A4) — sell whole-section access
  bundleTiers: TierRow[]; // bundle price rows (only offered when bundle is on)
}

const DUR_CHOICES: Array<[string, string]> = [
  ["none", "No expiry"],
  ["24", "24 hours"],
  ["168", "7 days"],
  ["720", "30 days"],
  ["custom", "Custom days…"],
];

function tierToRow(t: { price_micros: number | string; duration_hours: number | null }): TierRow {
  const dur = t.duration_hours;
  if (dur === null || dur === undefined) {
    return { amount: (Number(t.price_micros) / 1_000_000).toString(), duration: "none", customDays: "" };
  }
  const h = Number(dur);
  if (h === 24 || h === 168 || h === 720) {
    return { amount: (Number(t.price_micros) / 1_000_000).toString(), duration: String(h), customDays: "" };
  }
  return { amount: (Number(t.price_micros) / 1_000_000).toString(), duration: "custom", customDays: String(Math.max(1, Math.round(h / 24))) };
}

function tierFromRow(t: TierRow): { price_micros: number; duration_hours: number | null } | null {
  const parsed = parseFloat(t.amount);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    return null;
  }
  let dur: number | null = null;
  if (t.duration === "custom") {
    const days = parseInt(t.customDays, 10);
    dur = Number.isFinite(days) && days > 0 ? Math.min(365, days) * 24 : 30 * 24;
  } else if (t.duration !== "none") {
    dur = parseInt(t.duration, 10);
  }
  return { price_micros: Math.round(parsed * 1_000_000), duration_hours: dur };
}

/** Shared price×duration rows editor — used for access tiers (A2) and bundle
 *  prices (A4). Owns row add/update/remove and reports whole-array changes. */
function TierRows({
  sym,
  tiers,
  onChange,
  addNoun,
}: {
  sym: string;
  tiers: TierRow[];
  onChange: (tiers: TierRow[]) => void;
  addNoun: string;
}) {
  const updateTier = (j: number, patch: Partial<TierRow>) =>
    onChange(tiers.map((t, l) => (l === j ? { ...t, ...patch } : t)));
  const addTier = () => {
    if (tiers.length < 4) {
      onChange([...tiers, { amount: "", duration: "none", customDays: "" }]);
    }
  };
  const removeTier = (j: number) => onChange(tiers.filter((_, l) => l !== j));
  return (
    <>
      {tiers.map((t, j) => (
        <div key={j} className="mt-2 grid items-center gap-2 sm:grid-cols-[130px_150px_110px_30px]">
          <div className="flex items-center gap-1">
            <span className="text-[13px] font-semibold" style={{ color: "var(--ct-muted)" }}>{sym}</span>
            <input
              style={inputStyle}
              type="number"
              min={0}
              step="0.0001"
              placeholder="0.005"
              value={t.amount}
              onChange={(e) => updateTier(j, { amount: e.target.value })}
            />
          </div>
          <select style={inputStyle} value={t.duration} onChange={(e) => updateTier(j, { duration: e.target.value })}>
            {DUR_CHOICES.map(([v, label]) => (
              <option key={v} value={v}>{label}</option>
            ))}
          </select>
          {t.duration === "custom" ? (
            <input
              style={inputStyle}
              type="number"
              min={1}
              max={365}
              step={1}
              placeholder="days"
              value={t.customDays}
              onChange={(e) => updateTier(j, { customDays: e.target.value })}
            />
          ) : (
            <span />
          )}
          <button
            type="button"
            onClick={() => removeTier(j)}
            aria-label="Remove row"
            title="Remove row"
            className="rounded-lg py-1 text-[14px] font-bold"
            style={{ border: "1px solid var(--ct-border)", background: "var(--ct-surface)", color: "var(--ct-muted)" }}
          >
            ×
          </button>
        </div>
      ))}
      {tiers.length < 4 && (
        <button
          type="button"
          onClick={addTier}
          className="mt-2 rounded-lg px-3 py-1 text-[12px] font-semibold"
          style={{ border: "1px dashed var(--ct-border)", background: "transparent", color: "var(--ct-accent)" }}
        >
          + Add a {addNoun}{4 - tiers.length < 4 ? ` (${4 - tiers.length} left)` : ""}
        </button>
      )}
    </>
  );
}

function toRow(r: PathRule, currency: string): Row {
  void currency;
  return {
    path: r.path,
    amount: (Number(r.price_micros) / 1_000_000).toString(),
    freeArticles: r.meter_count ? String(Number(r.meter_count)) : "",
    windowDays: r.meter_window ? String(Number(r.meter_window)) : "",
    tiers: Array.isArray(r.tiers) ? r.tiers.map(tierToRow) : [],
    bundle: r.bundle === true,
    bundleTiers: Array.isArray(r.bundle_tiers) ? r.bundle_tiers.map(tierToRow) : [],
  };
}

function toRule(r: Row, fallbackMicros: number, currency: string): PathRule {
  const parsed = parseFloat(r.amount);
  const micros = Number.isFinite(parsed) && parsed >= 0 ? Math.round(parsed * 1_000_000) : fallbackMicros;
  const rule: PathRule = { path: r.path.trim(), price_micros: micros, currency };
  const free = parseInt(r.freeArticles, 10);
  if (Number.isFinite(free) && free > 0) {
    rule.meter_count = Math.min(50, free);
    const win = parseInt(r.windowDays, 10);
    rule.meter_window = Number.isFinite(win) && win > 0 ? Math.min(365, win) : 30;
  }
  const tiers = r.tiers.map(tierFromRow).filter((t): t is NonNullable<typeof t> => t !== null).slice(0, 4);
  if (tiers.length > 0) {
    rule.tiers = tiers;
  }
  // Bundle (A4): only serialized when the checkbox is on AND at least one
  // valid price row exists — no rows means the bundle isn't offered.
  if (r.bundle) {
    const btiers = r.bundleTiers.map(tierFromRow).filter((t): t is NonNullable<typeof t> => t !== null).slice(0, 4);
    if (btiers.length > 0) {
      rule.bundle = true;
      rule.bundle_tiers = btiers;
    }
  }
  return rule;
}

/** Plain-language coverage hint under a path input. */
function coverageHint(path: string): string {
  const p = path.trim();
  if (!p) {
    return "Type a path, e.g. /articles/* — every page under /articles/ gets this price.";
  }
  if (p.endsWith("/*")) {
    return `Covers every page starting with ${p.slice(0, -1)} — e.g. ${p.slice(0, -1)}my-first-post`;
  }
  return `Covers exactly ${p} (add /* at the end to cover a whole section)`;
}

function PricingForm({ settings }: { settings: SettingsResponse }) {
  const sym = SYMBOLS[(settings.currency || "USD").toUpperCase()] ?? "";
  const [rows, setRows] = useState<Row[]>(() => settings.path_pricing.map((r) => toRow(r, settings.currency)));

  const update = (i: number, patch: Partial<Row>) => setRows((rs) => rs.map((r, j) => (j === i ? { ...r, ...patch } : r)));
  const remove = (i: number) => setRows((rs) => rs.filter((_, j) => j !== i));
  const add = () =>
    setRows((rs) => [
      ...rs,
      {
        path: "",
        amount: (settings.price_micros / 1_000_000).toString(),
        freeArticles: "",
        windowDays: "",
        tiers: [],
        bundle: false,
        bundleTiers: [],
      },
    ]);

  return (
    <Card>
      <p className="mb-1 text-[13px]" style={{ color: "var(--ct-muted)" }}>
        Charge a different price for whole sections of your site. A rule like{" "}
        <code className="font-mono">/articles/*</code> gives every page under <code className="font-mono">/articles/</code>{" "}
        the same price. The most specific rule wins; everything else uses your standard price (
        {money(settings.price_micros, settings.currency, 4)}).
      </p>

      <div className="mt-4 grid gap-3">
        {rows.length === 0 && (
          <div
            className="rounded-xl border border-dashed p-4 text-[13px]"
            style={{ borderColor: "var(--ct-border)", color: "var(--ct-muted)" }}
          >
            No section prices yet — every page uses your standard price. Add your first section below.
          </div>
        )}
        {rows.map((r, i) => (
          <div
            key={i}
            className="rounded-xl border p-3"
            style={{ borderColor: "var(--ct-border)", background: "var(--ct-surface)" }}
          >
            <div className="grid items-center gap-2 sm:grid-cols-[1fr_150px_130px_110px_36px]">
              <div>
                <label className="mb-1 block text-[11px] font-semibold uppercase tracking-wide" style={{ color: "var(--ct-muted)" }}>
                  Section (path)
                </label>
                <input
                  style={inputStyle}
                  placeholder="/articles/*"
                  value={r.path}
                  onChange={(e) => update(i, { path: e.target.value })}
                />
              </div>
              <div>
                <label className="mb-1 block text-[11px] font-semibold uppercase tracking-wide" style={{ color: "var(--ct-muted)" }}>
                  Price per unlock
                </label>
                <div className="flex items-center gap-1">
                  <span className="text-[14px] font-semibold" style={{ color: "var(--ct-muted)" }}>
                    {sym}
                  </span>
                  <input
                    style={inputStyle}
                    type="number"
                    min={0}
                    step="0.0001"
                    value={r.amount}
                    onChange={(e) => update(i, { amount: e.target.value })}
                  />
                </div>
              </div>
              <div>
                <label className="mb-1 block text-[11px] font-semibold uppercase tracking-wide" style={{ color: "var(--ct-muted)" }}>
                  Free articles
                </label>
                <input
                  style={inputStyle}
                  type="number"
                  min={0}
                  max={50}
                  step={1}
                  placeholder="0"
                  value={r.freeArticles}
                  onChange={(e) => update(i, { freeArticles: e.target.value })}
                />
              </div>
              <div>
                <label className="mb-1 block text-[11px] font-semibold uppercase tracking-wide" style={{ color: "var(--ct-muted)" }}>
                  Every … days
                </label>
                <input
                  style={inputStyle}
                  type="number"
                  min={1}
                  max={365}
                  step={1}
                  placeholder="30"
                  value={r.windowDays}
                  onChange={(e) => update(i, { windowDays: e.target.value })}
                />
              </div>
              <button
                type="button"
                onClick={() => remove(i)}
                aria-label="Remove rule"
                title="Remove rule"
                className="mt-5 rounded-lg py-2 text-[15px] font-bold"
                style={{ border: "1px solid var(--ct-border)", background: "var(--ct-elevated)", color: "var(--ct-muted)" }}
              >
                ×
              </button>
            </div>
            <p className="mt-2 text-[12px]" style={{ color: "var(--ct-muted)" }}>
              {coverageHint(r.path)}
              {r.freeArticles && Number(r.freeArticles) > 0
                ? ` Readers get ${Number(r.freeArticles)} free article${Number(r.freeArticles) === 1 ? "" : "s"} every ${Number(r.windowDays) || 30} days on this section — AI crawlers always pay.`
                : ""}
            </p>

            {/* Access tiers (A2): optional price×duration offers for this section. */}
            <div className="mt-3 rounded-lg p-3" style={{ background: "var(--ct-elevated)" }}>
              <p className="text-[12px] font-semibold">Access tiers <span style={{ color: "var(--ct-muted)", fontWeight: 400 }}>(optional)</span></p>
              <p className="mt-1 text-[12px]" style={{ color: "var(--ct-muted)" }}>
                Offer temporary access at a lower price. A reader who picks 24 hours can return within 24 h without
                paying again; after that they're asked to renew. One button per row on the paywall — no rows means the
                single price above, with no expiry.
              </p>
              <TierRows sym={sym} tiers={r.tiers} onChange={(ts) => update(i, { tiers: ts })} addNoun="tier" />
            </div>

            {/* Bundle (A4): one pass covering EVERYTHING under this section. */}
            <div className="mt-3 rounded-lg p-3" style={{ background: "var(--ct-elevated)" }}>
              <label className="flex items-center gap-2 text-[12px] font-semibold" style={{ cursor: "pointer" }}>
                <input
                  type="checkbox"
                  checked={r.bundle}
                  onChange={(e) => update(i, { bundle: e.target.checked })}
                />
                Sell a bundle <span style={{ color: "var(--ct-muted)", fontWeight: 400 }}>(optional)</span>
              </label>
              <p className="mt-1 text-[12px]" style={{ color: "var(--ct-muted)" }}>
                One pass that unlocks EVERYTHING under this section — the reader pays once and roams every covered
                article for the chosen duration. Your single-article prices stay on the paywall beside it, so price
                the bundle higher than one article. Articles a reader already bought separately are not refunded or
                credited.
              </p>
              {r.bundle && (
                <>
                  {r.bundleTiers.length === 0 && (
                    <p className="mt-2 text-[12px]" style={{ color: "var(--ct-muted)" }}>
                      Add at least one price row — without one the bundle isn&apos;t offered.
                    </p>
                  )}
                  <TierRows
                    sym={sym}
                    tiers={r.bundleTiers}
                    onChange={(ts) => update(i, { bundleTiers: ts })}
                    addNoun="bundle price"
                  />
                </>
              )}
            </div>
          </div>
        ))}
      </div>

      <button
        type="button"
        onClick={add}
        className="mt-3 rounded-lg px-4 py-2 text-[13px] font-semibold"
        style={{ border: "1px dashed var(--ct-accent)", background: "var(--ct-elevated)", color: "var(--ct-accent)" }}
      >
        + Add a section price
      </button>

      <div className="mt-5 border-t pt-4" style={{ borderColor: "var(--ct-border)" }}>
        <SaveButton
          onSave={async () => void (await savePricing(rows.map((r) => toRule(r, settings.price_micros, settings.currency))))}
          label="Save pricing"
        />
      </div>
    </Card>
  );
}

export function Pricing() {
  return (
    <div className="grid gap-4" style={{ paddingTop: 4 }}>
      <div>
        <h2 className="text-lg font-bold tracking-tight">Section pricing</h2>
        <p className="text-[13px]" style={{ color: "var(--ct-muted)" }}>
          Charge more for premium sections, less for the long tail.
        </p>
      </div>
      <SettingsGate render={(s) => <PricingForm settings={s} />} />
    </div>
  );
}
