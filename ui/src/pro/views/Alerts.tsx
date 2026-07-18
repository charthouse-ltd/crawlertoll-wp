import { useState, type CSSProperties } from "react";
import { saveAlerts } from "../api";
import type { AlertSettings, SettingsResponse } from "../types";
import { Card, SaveButton, SettingsGate, Toggle } from "../components/ui";

const inputStyle: CSSProperties = {
  border: "1px solid var(--ct-border)",
  background: "var(--ct-elevated)",
  color: "var(--ct-text)",
  borderRadius: 8,
  padding: "8px 10px",
  fontSize: 13,
  width: "100%",
  maxWidth: 360,
};

const ROWS: { key: keyof Omit<AlertSettings, "email">; label: string; desc: string }[] = [
  { key: "daily", label: "Daily summary", desc: "A digest of yesterday’s crawler activity every morning." },
  { key: "weekly", label: "Weekly summary", desc: "A roll-up of the week’s crawls, revenue, and top bots." },
  { key: "spike", label: "Spike alerts", desc: "Get notified when crawler traffic jumps unusually." },
];

function AlertsForm({ settings }: { settings: SettingsResponse }) {
  const [alerts, setAlerts] = useState<AlertSettings>(settings.alerts);

  return (
    <Card>
      <div className="grid gap-4">
        {ROWS.map((row) => (
          <div key={row.key} className="flex items-start justify-between gap-4">
            <div>
              <div className="text-[14px] font-semibold">{row.label}</div>
              <div className="text-[12px]" style={{ color: "var(--ct-muted)" }}>
                {row.desc}
              </div>
            </div>
            <Toggle checked={alerts[row.key]} onChange={(v) => setAlerts((a) => ({ ...a, [row.key]: v }))} label="" />
          </div>
        ))}

        <div className="border-t pt-4" style={{ borderColor: "var(--ct-border)" }}>
          <label className="text-[11px] font-semibold uppercase tracking-wide" style={{ color: "var(--ct-muted)" }}>
            Recipient email
          </label>
          <input
            style={inputStyle}
            type="email"
            className="mt-1"
            placeholder={settings.meta.fallback_email}
            value={alerts.email}
            onChange={(e) => setAlerts((a) => ({ ...a, email: e.target.value }))}
          />
          <p className="mt-1 text-[12px]" style={{ color: "var(--ct-muted)" }}>
            Leave blank to use the site admin address ({settings.meta.fallback_email}).
          </p>
        </div>

        <div className="border-t pt-4" style={{ borderColor: "var(--ct-border)" }}>
          <SaveButton onSave={async () => void (await saveAlerts(alerts))} label="Save alerts" />
        </div>
      </div>
    </Card>
  );
}

export function Alerts() {
  return (
    <div className="grid gap-4" style={{ paddingTop: 4 }}>
      <div>
        <h2 className="text-lg font-bold tracking-tight">Email alerts</h2>
        <p className="text-[13px]" style={{ color: "var(--ct-muted)" }}>
          Stay on top of crawler activity without opening the dashboard.
        </p>
      </div>
      <SettingsGate render={(s) => <AlertsForm settings={s} />} />
    </div>
  );
}
