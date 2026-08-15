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
 *  cents, not micros) and converts to micros only on save. */
interface Row {
  path: string;
  amount: string; // e.g. "0.01"
}

function toRow(r: PathRule, currency: string): Row {
  void currency;
  return { path: r.path, amount: (Number(r.price_micros) / 1_000_000).toString() };
}

function toRule(r: Row, fallbackMicros: number, currency: string): PathRule {
  const parsed = parseFloat(r.amount);
  const micros = Number.isFinite(parsed) && parsed >= 0 ? Math.round(parsed * 1_000_000) : fallbackMicros;
  return { path: r.path.trim(), price_micros: micros, currency };
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
    setRows((rs) => [...rs, { path: "", amount: (settings.price_micros / 1_000_000).toString() }]);

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
            <div className="grid items-center gap-2 sm:grid-cols-[1fr_150px_36px]">
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
            </p>
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
