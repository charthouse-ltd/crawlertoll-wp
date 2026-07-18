import { useState, type CSSProperties } from "react";
import { saveRails, useBots } from "../api";
import type { SettingsResponse } from "../types";
import { Card, SaveButton, SettingsGate } from "../components/ui";

const selectStyle: CSSProperties = {
  border: "1px solid var(--ct-border)",
  background: "var(--ct-elevated)",
  color: "var(--ct-text)",
  borderRadius: 8,
  padding: "6px 8px",
  fontSize: 13,
  minWidth: 220,
};

function RailsForm({ settings }: { settings: SettingsResponse }) {
  const bots = useBots();
  const [overrides, setOverrides] = useState<Record<string, string>>(settings.rail_overrides ?? {});

  const railOptions = Object.entries(settings.meta.rail_options);
  const defaultLabel = settings.meta.rail_options[settings.rail] ?? settings.rail;

  const set = (bot: string, rail: string) =>
    setOverrides((o) => {
      const next = { ...o };
      if (rail === "") {
        delete next[bot];
      } else {
        next[bot] = rail;
      }
      return next;
    });

  const overrideCount = Object.keys(overrides).length;

  return (
    <Card>
      <p className="mb-4 text-[13px]" style={{ color: "var(--ct-muted)" }}>
        Route specific crawlers to a different settlement rail. Anything left on{" "}
        <strong>Site default</strong> uses your configured rail ({defaultLabel}).{" "}
        {overrideCount > 0 && <span style={{ color: "var(--ct-text)" }}>{overrideCount} override{overrideCount === 1 ? "" : "s"} set.</span>}
      </p>

      {bots.loading && !bots.data ? (
        <div className="grid gap-2">
          {[0, 1, 2, 3, 4].map((i) => (
            <div key={i} className="ct-skeleton h-9 w-full" />
          ))}
        </div>
      ) : (
        <div className="grid gap-1.5" style={{ maxHeight: 420, overflowY: "auto" }}>
          {(bots.data ?? []).map((b) => (
            <div key={b.name} className="flex items-center justify-between gap-3 rounded-lg px-2 py-1.5" style={{ background: "var(--ct-surface)" }}>
              <div className="min-w-0">
                <div className="truncate text-[13px] font-semibold">{b.name}</div>
                <div className="text-[11px]" style={{ color: "var(--ct-muted)" }}>
                  {b.operator}
                </div>
              </div>
              <select style={selectStyle} value={overrides[b.name] ?? ""} onChange={(e) => set(b.name, e.target.value)}>
                <option value="">Site default ({defaultLabel})</option>
                {railOptions.map(([value, label]) => (
                  <option key={value} value={value}>
                    {label}
                  </option>
                ))}
              </select>
            </div>
          ))}
        </div>
      )}

      <div className="mt-5 border-t pt-4" style={{ borderColor: "var(--ct-border)" }}>
        <SaveButton onSave={async () => void (await saveRails(overrides))} label="Save rail routing" />
      </div>
    </Card>
  );
}

export function Rails() {
  return (
    <div className="grid gap-4" style={{ paddingTop: 4 }}>
      <div>
        <h2 className="text-lg font-bold tracking-tight">Rail routing</h2>
        <p className="text-[13px]" style={{ color: "var(--ct-muted)" }}>
          Send each crawler’s payment down the rail you prefer.
        </p>
      </div>
      <SettingsGate render={(s) => <RailsForm settings={s} />} />
    </div>
  );
}
