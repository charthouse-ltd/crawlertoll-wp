import { useState } from "react";
import { compact, money, proConfig, useStats, useTimeseries, type AsyncState } from "../api";
import type { Period, StatsResponse, TimeseriesResponse, TopBot } from "../types";
import { Card, EmptyState, ErrorBox } from "../components/ui";
import { ActionBreakdown, fillSeries, fmtDay, Sparkline } from "../components/Charts";

function PeriodToggle({ value, onChange }: { value: Period; onChange: (p: Period) => void }) {
  const opts: Period[] = ["7d", "30d"];
  return (
    <div
      className="inline-flex items-center rounded-full p-1"
      style={{ background: "var(--ct-surface)", border: "1px solid var(--ct-border)" }}
    >
      {opts.map((o) => {
        const active = o === value;
        return (
          <button
            key={o}
            type="button"
            onClick={() => onChange(o)}
            className="rounded-full px-3.5 py-1 text-xs font-semibold transition-colors"
            style={{
              background: active ? "var(--ct-elevated)" : "transparent",
              color: active ? "var(--ct-text)" : "var(--ct-muted)",
              boxShadow: active ? "var(--ct-shadow)" : "none",
            }}
          >
            {o === "7d" ? "7 days" : "30 days"}
          </button>
        );
      })}
    </div>
  );
}

function Delta({ pct }: { pct: number }) {
  const up = pct >= 0;
  return (
    <span
      className="inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[11px] font-semibold"
      style={{
        color: up ? "var(--ct-success)" : "var(--ct-danger)",
        background: `color-mix(in srgb, ${up ? "var(--ct-success)" : "var(--ct-danger)"} 12%, transparent)`,
      }}
    >
      {up ? "▲" : "▼"} {Math.abs(pct)}%
    </span>
  );
}

function KpiCard({
  label,
  value,
  accent,
  delta,
  gradient,
}: {
  label: string;
  value: string;
  accent: string;
  delta?: number;
  gradient?: boolean;
}) {
  return (
    <div
      className="ct-pro-card relative overflow-hidden p-4"
      style={
        gradient
          ? { background: "linear-gradient(135deg, var(--ct-accent), var(--ct-accent-2))", border: "none", color: "#fff" }
          : undefined
      }
    >
      <div className="mb-2 h-1.5 w-8 rounded-full" style={{ background: gradient ? "rgba(255,255,255,.6)" : accent }} />
      <div className="flex items-end justify-between gap-2">
        <div className="text-2xl font-bold tracking-tight tabular-nums">{value}</div>
        {delta !== undefined && !gradient && <Delta pct={delta} />}
      </div>
      <div className="mt-1 text-xs font-medium" style={{ color: gradient ? "rgba(255,255,255,.85)" : "var(--ct-muted)" }}>
        {label}
      </div>
    </div>
  );
}

function BarRow({ bot, max, currency }: { bot: TopBot; max: number; currency: string }) {
  const pct = Math.max(2, Math.round((bot.crawls / max) * 100));
  return (
    <div className="flex items-center gap-3 py-1.5">
      <div className="w-36 shrink-0 truncate text-right text-[13px] font-semibold">{bot.bot_name}</div>
      <div className="h-6 flex-1 overflow-hidden rounded-md" style={{ background: "var(--ct-surface)" }}>
        <div
          className="ct-bar-fill h-full rounded-md"
          style={{ width: `${pct}%`, background: "linear-gradient(90deg, var(--ct-accent), var(--ct-accent-2))" }}
        />
      </div>
      <div className="w-20 shrink-0 text-right text-xs font-semibold tabular-nums">{compact(bot.crawls)}</div>
      <div className="w-20 shrink-0 text-right text-xs font-semibold tabular-nums" style={{ color: "var(--ct-success)" }}>
        {money(bot.revenue_micros, currency)}
      </div>
    </div>
  );
}

function DashboardSkeleton() {
  return (
    <div className="grid gap-4">
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className="ct-pro-card p-4">
            <div className="ct-skeleton mb-3 h-1.5 w-8" />
            <div className="ct-skeleton h-7 w-24" />
            <div className="ct-skeleton mt-2 h-3 w-20" />
          </div>
        ))}
      </div>
      <div className="ct-pro-card p-5">
        <div className="ct-skeleton h-4 w-40" />
        <div className="mt-4 grid gap-2.5">
          {[0, 1, 2, 3, 4].map((i) => (
            <div key={i} className="ct-skeleton h-6 w-full" />
          ))}
        </div>
      </div>
    </div>
  );
}

function TrendCard({ title, id, values, days, color, format }: { title: string; id: string; values: number[]; days: string[]; color: string; format: (v: number) => string }) {
  const peak = values.length ? Math.max(...values) : 0;
  return (
    <Card title={title} desc={`Peak ${format(peak)}`}>
      {days.length === 0 ? (
        <EmptyState>No data in this period yet.</EmptyState>
      ) : (
        <Sparkline id={id} values={values} startLabel={fmtDay(days[0])} endLabel={fmtDay(days[days.length - 1])} color={color} />
      )}
    </Card>
  );
}

function TrendsSection({ ts, currency }: { ts: AsyncState<TimeseriesResponse>; currency: string }) {
  if (ts.loading && !ts.data) {
    return (
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="ct-pro-card p-5">
          <div className="ct-skeleton h-4 w-28" />
          <div className="ct-skeleton mt-4 h-[76px] w-full" />
        </div>
        <div className="ct-pro-card p-5">
          <div className="ct-skeleton h-4 w-28" />
          <div className="ct-skeleton mt-4 h-[76px] w-full" />
        </div>
      </div>
    );
  }
  if (!ts.data) {
    return null;
  }
  const filled = fillSeries(ts.data);
  const days = filled.map((d) => d.day);
  const crawls = filled.map((d) => d.crawls);
  const revenue = filled.map((d) => d.revenue / 1_000_000);
  const sym = money(0, currency).replace(/0.*/, "");
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <TrendCard title="Crawls / day" id="crawls" values={crawls} days={days} color="var(--ct-accent)" format={(v) => compact(v)} />
      <TrendCard title="Revenue / day" id="revenue" values={revenue} days={days} color="var(--ct-success)" format={(v) => `${sym}${v.toFixed(2)}`} />
    </div>
  );
}

function DashboardBody({ data, ts }: { data: StatsResponse; ts: AsyncState<TimeseriesResponse> }) {
  const { totals, top_bots, top_paths } = data.current;
  const currency = proConfig.currency;
  const maxCrawls = Math.max(1, ...top_bots.map((b) => b.crawls));

  return (
    <div className="grid gap-4">
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <KpiCard label="Potential revenue" value={money(totals.total_revenue_micros, currency)} accent="var(--ct-accent)" delta={data.change_pct} gradient />
        <KpiCard label="Total AI crawls" value={compact(totals.total_crawls)} accent="var(--ct-accent-2)" />
        <KpiCard label="Charged (402)" value={compact(totals.charged)} accent="var(--ct-success)" />
        <KpiCard label="Blocked (403)" value={compact(totals.blocked)} accent="var(--ct-danger)" />
      </div>

      <TrendsSection ts={ts} currency={currency} />

      <Card title="Request outcomes" desc="How CrawlerToll responded to AI-crawler requests this period.">
        <ActionBreakdown allowed={Number(totals.allowed)} charged={Number(totals.charged)} blocked={Number(totals.blocked)} />
      </Card>

      <Card title="Top AI crawlers" desc="Crawlers hitting your site most. Revenue = crawls × your per-path price on disallowed paths.">
        {top_bots.length === 0 ? (
          <EmptyState>No crawler activity yet. Data appears as AI bots visit your site.</EmptyState>
        ) : (
          <div>
            {top_bots.map((b) => (
              <BarRow key={b.bot_name} bot={b} max={maxCrawls} currency={currency} />
            ))}
          </div>
        )}
      </Card>

      <Card title="Most crawled pages" desc="Pages AI crawlers target most. Higher counts on premium pages mean more potential revenue.">
        {top_paths.length === 0 ? (
          <EmptyState>No page-level data yet.</EmptyState>
        ) : (
          <table className="w-full text-[13px]">
            <thead>
              <tr style={{ color: "var(--ct-muted)", borderBottom: "1px solid var(--ct-border)" }}>
                <th className="py-2 pr-3 text-left text-[11px] font-semibold uppercase tracking-wide">Path</th>
                <th className="py-2 px-3 text-right text-[11px] font-semibold uppercase tracking-wide">Crawls</th>
                <th className="py-2 pl-3 text-right text-[11px] font-semibold uppercase tracking-wide">Revenue</th>
              </tr>
            </thead>
            <tbody>
              {top_paths.map((p) => (
                <tr key={p.request_path} style={{ borderBottom: "1px solid color-mix(in srgb, var(--ct-border) 50%, transparent)" }}>
                  <td className="py-2 pr-3 font-mono">{p.request_path}</td>
                  <td className="py-2 px-3 text-right font-semibold tabular-nums">{compact(p.crawls)}</td>
                  <td className="py-2 pl-3 text-right font-semibold tabular-nums" style={{ color: "var(--ct-success)" }}>
                    {money(p.revenue_micros, currency)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
    </div>
  );
}

export function Dashboard() {
  const [period, setPeriod] = useState<Period>("30d");
  const [tick, setTick] = useState(0);
  const { data, loading, error } = useStats(period, tick);
  const ts = useTimeseries(period, tick);

  return (
    <div className="grid gap-4" style={{ paddingTop: 4 }}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-bold tracking-tight">Revenue dashboard</h2>
          <p className="text-[13px]" style={{ color: "var(--ct-muted)" }}>
            {data ? `${data.period.from} → ${data.period.to}` : "AI-crawler activity and earnings"}
          </p>
        </div>
        <PeriodToggle value={period} onChange={setPeriod} />
      </div>

      {error ? (
        <ErrorBox message={error} onRetry={() => setTick((t) => t + 1)} />
      ) : loading || !data ? (
        <DashboardSkeleton />
      ) : (
        <DashboardBody data={data} ts={ts} />
      )}
    </div>
  );
}
