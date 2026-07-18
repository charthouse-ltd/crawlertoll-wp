import { useState, type CSSProperties } from "react";
import { money, savePricing } from "../api";
import type { PathRule, SettingsResponse } from "../types";
import { Card, SaveButton, SettingsGate } from "../components/ui";

const inputStyle: CSSProperties = {
  border: "1px solid var(--ct-border)",
  background: "var(--ct-elevated)",
  color: "var(--ct-text)",
  borderRadius: 8,
  padding: "8px 10px",
  fontSize: 13,
};

function PricingForm({ settings }: { settings: SettingsResponse }) {
  const [rows, setRows] = useState<PathRule[]>(() =>
    settings.path_pricing.map((r) => ({ path: r.path, price_micros: Number(r.price_micros), currency: r.currency })),
  );

  const update = (i: number, patch: Partial<PathRule>) =>
    setRows((rs) => rs.map((r, j) => (j === i ? { ...r, ...patch } : r)));
  const remove = (i: number) => setRows((rs) => rs.filter((_, j) => j !== i));
  const add = () =>
    setRows((rs) => [...rs, { path: "", price_micros: settings.price_micros, currency: settings.currency }]);

  return (
    <Card>
      <p className="mb-4 text-[13px]" style={{ color: "var(--ct-muted)" }}>
        Longest-prefix path rules override the flat per-crawl price ({money(settings.price_micros, settings.currency, 4)}) for
        matching request paths. Use <code className="font-mono">/premium/*</code>-style globs.
      </p>

      <div className="grid gap-2">
        <div className="hidden gap-2 px-1 text-[11px] font-semibold uppercase tracking-wide sm:grid sm:grid-cols-[1fr_140px_110px_40px]" style={{ color: "var(--ct-muted)" }}>
          <span>Path pattern</span>
          <span>Price (micros)</span>
          <span>Currency</span>
          <span />
        </div>
        {rows.length === 0 && (
          <p className="py-3 text-[13px]" style={{ color: "var(--ct-muted)" }}>
            No path rules — every crawl uses the flat price. Add one below.
          </p>
        )}
        {rows.map((r, i) => (
          <div key={i} className="grid items-center gap-2 sm:grid-cols-[1fr_140px_110px_40px]">
            <input
              style={inputStyle}
              placeholder="/premium/*"
              value={r.path}
              onChange={(e) => update(i, { path: e.target.value })}
            />
            <input
              style={inputStyle}
              type="number"
              min={0}
              value={String(r.price_micros)}
              onChange={(e) => update(i, { price_micros: Math.max(0, Number(e.target.value)) })}
            />
            <select style={inputStyle} value={r.currency} onChange={(e) => update(i, { currency: e.target.value })}>
              {settings.meta.currencies.map((c) => (
                <option key={c} value={c}>
                  {c}
                </option>
              ))}
            </select>
            <button
              type="button"
              onClick={() => remove(i)}
              aria-label="Remove rule"
              className="rounded-lg py-2 text-[15px] font-bold"
              style={{ border: "1px solid var(--ct-border)", color: "var(--ct-muted)" }}
            >
              ×
            </button>
          </div>
        ))}
      </div>

      <button
        type="button"
        onClick={add}
        className="mt-3 rounded-lg px-3 py-1.5 text-[12px] font-semibold"
        style={{ border: "1px dashed var(--ct-border)", color: "var(--ct-text)" }}
      >
        + Add path rule
      </button>

      <div className="mt-5 border-t pt-4" style={{ borderColor: "var(--ct-border)" }}>
        <SaveButton onSave={async () => void (await savePricing(rows))} label="Save pricing" />
      </div>
    </Card>
  );
}

export function Pricing() {
  return (
    <div className="grid gap-4" style={{ paddingTop: 4 }}>
      <div>
        <h2 className="text-lg font-bold tracking-tight">Per-path pricing</h2>
        <p className="text-[13px]" style={{ color: "var(--ct-muted)" }}>
          Charge more for premium paths, less for the long tail.
        </p>
      </div>
      <SettingsGate render={(s) => <PricingForm settings={s} />} />
    </div>
  );
}
