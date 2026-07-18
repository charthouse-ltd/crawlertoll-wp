import { compact } from "../api";
import type { TimeseriesResponse } from "../types";

export interface FilledDay {
  day: string;
  crawls: number;
  revenue: number;
}

// Zero-fill the daily buckets across the requested range so the chart x-axis is
// continuous even on days with no crawls. wpdb returns numerics as strings →
// Number() coerces. Local date parts (not toISOString) avoid a UTC day shift.
export function fillSeries(resp: TimeseriesResponse): FilledDay[] {
  const map = new Map(resp.days.map((d) => [d.day, d]));
  const out: FilledDay[] = [];
  const end = new Date(`${resp.period.to}T00:00:00`);
  const cur = new Date(`${resp.period.from}T00:00:00`);
  let guard = 0;
  while (cur <= end && guard < 400) {
    const key = `${cur.getFullYear()}-${String(cur.getMonth() + 1).padStart(2, "0")}-${String(cur.getDate()).padStart(2, "0")}`;
    const b = map.get(key);
    out.push({ day: key, crawls: Number(b?.crawls ?? 0), revenue: Number(b?.revenue_micros ?? 0) });
    cur.setDate(cur.getDate() + 1);
    guard++;
  }
  return out;
}

export function fmtDay(key: string): string {
  const d = new Date(`${key}T00:00:00`);
  if (Number.isNaN(d.getTime())) {
    return key;
  }
  return d.toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

// Lightweight area+line sparkline. viewBox is stretched to the container width
// (preserveAspectRatio="none"); the stroke stays crisp via non-scaling-stroke.
export function Sparkline({
  id,
  values,
  startLabel,
  endLabel,
  color,
  height = 76,
}: {
  id: string;
  values: number[];
  startLabel: string;
  endLabel: string;
  color: string;
  height?: number;
}) {
  const W = 600;
  const H = height;
  const pad = 8;
  const n = values.length;
  const max = Math.max(1, ...values);
  const xs = (i: number) => (n <= 1 ? W / 2 : pad + (i * (W - 2 * pad)) / (n - 1));
  const ys = (v: number) => H - pad - (v / max) * (H - 2 * pad);
  const line = values.map((v, i) => `${i ? "L" : "M"}${xs(i).toFixed(1)} ${ys(v).toFixed(1)}`).join(" ");
  const area = n > 0 ? `${line} L ${xs(n - 1).toFixed(1)} ${H - pad} L ${xs(0).toFixed(1)} ${H - pad} Z` : "";
  const last = n - 1;

  return (
    <div>
      <svg viewBox={`0 0 ${W} ${H}`} preserveAspectRatio="none" width="100%" height={height} role="img" aria-label="trend">
        <defs>
          <linearGradient id={`spark-${id}`} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={color} stopOpacity="0.28" />
            <stop offset="100%" stopColor={color} stopOpacity="0" />
          </linearGradient>
        </defs>
        {area && <path d={area} fill={`url(#spark-${id})`} />}
        {n > 1 && (
          <path d={line} fill="none" stroke={color} strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
        )}
        {n > 0 && <circle cx={xs(last)} cy={ys(values[last])} r={2.5} fill={color} vectorEffect="non-scaling-stroke" />}
      </svg>
      <div className="mt-1 flex justify-between text-[11px]" style={{ color: "var(--ct-muted)" }}>
        <span>{startLabel}</span>
        <span>{endLabel}</span>
      </div>
    </div>
  );
}

export function ActionBreakdown({ allowed, charged, blocked }: { allowed: number; charged: number; blocked: number }) {
  const segs = [
    { label: "Allowed", v: allowed, color: "var(--ct-success)" },
    { label: "Charged (402)", v: charged, color: "var(--ct-accent)" },
    { label: "Blocked (403)", v: blocked, color: "var(--ct-danger)" },
  ];
  const total = segs.reduce((s, x) => s + x.v, 0) || 1;
  return (
    <div>
      <div className="flex h-3 overflow-hidden rounded-full" style={{ background: "var(--ct-surface)" }}>
        {segs.map((s) =>
          s.v > 0 ? <div key={s.label} title={`${s.label}: ${s.v}`} style={{ width: `${(s.v / total) * 100}%`, background: s.color }} /> : null,
        )}
      </div>
      <div className="mt-3 flex flex-wrap gap-x-5 gap-y-2">
        {segs.map((s) => (
          <div key={s.label} className="flex items-center gap-1.5 text-[12px]">
            <span className="h-2.5 w-2.5 rounded-full" style={{ background: s.color }} />
            <span className="font-semibold tabular-nums">{compact(s.v)}</span>
            <span style={{ color: "var(--ct-muted)" }}>{s.label}</span>
          </div>
        ))}
      </div>
    </div>
  );
}
