import { useState } from "react";
import { compact, money, proConfig, useRealised, useStats, useTimeseries, useTraffic, type AsyncState } from "../api";
import type { Period, RealisedResponse, StatsResponse, TimeseriesResponse, TopBot, TrafficResponse } from "../types";
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

const RAIL_LABEL: Record<string, string> = {
  stripe: "Cards (your Stripe)",
  x402: "USDC (your wallet)",
  "stripe-renewal": "Card pass renewals",
  "x402-renewal": "USDC pass renewals",
  meter: "Free metered reads",
  email: "Email-gate unlocks",
};

function realisedTotal(r: RealisedResponse | null): { micros: number; currency: string } {
  if (!r) return { micros: 0, currency: proConfig.currency };
  const paid = r.by_rail.filter((x) => x.amount_micros > 0);
  const currency = paid[0]?.currency || proConfig.currency;
  return { micros: paid.filter((x) => (x.currency || currency) === currency).reduce((a, x) => a + x.amount_micros, 0), currency };
}

function RealisedSection({ realised }: { realised: AsyncState<RealisedResponse> }) {
  const r = realised.data;
  return (
    <Card title="Realised revenue" desc="What readers and agents actually paid — read back from the unlock service's receipts. Cards land on your Stripe account, USDC in your wallet; nothing passes through CrawlerToll.">
      {realised.error ? (
        <EmptyState>{realised.error}</EmptyState>
      ) : !r ? (
        <EmptyState>Loading receipts…</EmptyState>
      ) : !r.enrolled ? (
        <EmptyState>Your site enrolls with the unlock service the first time an article is sealed. Receipts appear here after the first unlock.</EmptyState>
      ) : r.unlocks === 0 ? (
        <EmptyState>No unlocks in this period yet.</EmptyState>
      ) : (
        <table className="w-full text-[13px]">
          <thead>
            <tr style={{ color: "var(--ct-muted)", borderBottom: "1px solid var(--ct-border)" }}>
              <th className="py-2 pr-3 text-left text-[11px] font-semibold uppercase tracking-wide">Rail</th>
              <th className="py-2 px-3 text-right text-[11px] font-semibold uppercase tracking-wide">Unlocks</th>
              <th className="py-2 pl-3 text-right text-[11px] font-semibold uppercase tracking-wide">Collected</th>
            </tr>
          </thead>
          <tbody>
            {r.by_rail.map((x) => (
              <tr key={x.rail + (x.currency || "")} style={{ borderBottom: "1px solid color-mix(in srgb, var(--ct-border) 50%, transparent)" }}>
                <td className="py-2 pr-3">{RAIL_LABEL[x.rail] || x.rail}</td>
                <td className="py-2 px-3 text-right font-semibold tabular-nums">{compact(x.count)}</td>
                <td className="py-2 pl-3 text-right font-semibold tabular-nums" style={{ color: x.amount_micros > 0 ? "var(--ct-success)" : "var(--ct-muted)" }}>
                  {x.amount_micros > 0 ? money(x.amount_micros, x.currency || proConfig.currency) : "—"}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </Card>
  );
}


function pct(n: number): string {
  return `${Math.round(n * 100)}%`;
}

function TrafficSection({ traffic }: { traffic: AsyncState<TrafficResponse> }) {
  const t = traffic.data;
  const tiles: Array<{ key: keyof TrafficResponse["classes"]; label: string; hint: string; accent: string }> = [
    { key: "browser", label: "People (browsers)", hint: "Readers and anything indistinguishable from one.", accent: "var(--ct-accent)" },
    { key: "ai_crawler", label: "Declared AI crawlers", hint: "From the catalogue — charged or blocked by your policy.", accent: "var(--ct-accent-2)" },
    { key: "search_engine", label: "Search engines", hint: "Never charged (safe mode).", accent: "var(--ct-success)" },
    { key: "automation", label: "Undeclared automation", hint: "curl, scripts, headless browsers, generic bots. A user-agent paywall cannot bill these — sealing gives them the encrypted body.", accent: "var(--ct-danger)" },
  ];
  return (
    <Card title="Traffic — who is at the door" desc="Every front-end request, classified by user agent. Honest about its limit: a headless browser wearing a real browser string counts as a person — which is exactly why the content itself is the lock.">
      {traffic.error ? (
        <EmptyState>{traffic.error}</EmptyState>
      ) : !t ? (
        <EmptyState>Loading traffic…</EmptyState>
      ) : (
        <div className="grid gap-4">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {tiles.map((x) => (
              <div key={x.key} className="ct-pro-card p-3">
                <div className="mb-2 h-1.5 w-8 rounded-full" style={{ background: x.accent }} />
                <div className="text-xl font-bold tabular-nums">{compact(t.classes[x.key])}</div>
                <div className="text-xs font-medium">{x.label}</div>
                <div className="mt-1 text-[11px]" style={{ color: "var(--ct-muted)" }}>{x.hint}</div>
              </div>
            ))}
          </div>
          <div>
            <div className="mb-2 text-[11px] font-semibold uppercase tracking-wide" style={{ color: "var(--ct-muted)" }}>Sealed-content funnel</div>
            <div className="grid grid-cols-3 gap-3">
              <div className="ct-pro-card p-3"><div className="text-xl font-bold tabular-nums">{compact(t.funnel.sealed_views)}</div><div className="text-xs">Views of sealed posts</div></div>
              <div className="ct-pro-card p-3"><div className="text-xl font-bold tabular-nums">{compact(t.funnel.walls_shown)}</div><div className="text-xs">Walls shown <span style={{ color: "var(--ct-muted)" }}>({pct(t.funnel.wall_rate)} of views)</span></div></div>
              <div className="ct-pro-card p-3"><div className="text-xl font-bold tabular-nums">{compact(t.funnel.unlocks)}</div><div className="text-xs">Unlocks <span style={{ color: "var(--ct-muted)" }}>({pct(t.funnel.unlock_rate)} of walls · {compact(t.funnel.paid_unlocks)} paid)</span></div></div>
            </div>
            <div className="mt-2 text-[12px]" style={{ color: "var(--ct-muted)" }}>
              By rail — cards {compact(t.funnel.by_rail.stripe)} · USDC {compact(t.funnel.by_rail.x402)} · free metered {compact(t.funnel.by_rail.meter)} · email {compact(t.funnel.by_rail.email)} · pass renewals {compact(t.funnel.by_rail.renewal)} · returning device {compact(t.funnel.by_rail.cache)}
              {t.funnel.walls_unavailable > 0 ? ` · unlock unavailable ${compact(t.funnel.walls_unavailable)} (unlock service unreachable — nothing was charged)` : ""}
            </div>
          </div>
          {t.top_automation.length > 0 ? (
            <div>
              <div className="mb-2 text-[11px] font-semibold uppercase tracking-wide" style={{ color: "var(--ct-muted)" }}>Most frequent undeclared automation</div>
              <table className="w-full text-[12px]">
                <tbody>
                  {t.top_automation.map((u) => (
                    <tr key={u.ua} style={{ borderBottom: "1px solid color-mix(in srgb, var(--ct-border) 50%, transparent)" }}>
                      <td className="py-1 pr-3 font-mono" style={{ wordBreak: "break-all" }}>{u.ua}</td>
                      <td className="py-1 text-right font-semibold tabular-nums">{compact(u.count)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null}
        </div>
      )}
    </Card>
  );
}

function DashboardBody({ data, ts, realised, traffic }: { data: StatsResponse; ts: AsyncState<TimeseriesResponse>; realised: AsyncState<RealisedResponse>; traffic: AsyncState<TrafficResponse> }) {
  const { totals, top_bots, top_paths } = data.current;
  const currency = proConfig.currency;
  const maxCrawls = Math.max(1, ...top_bots.map((b) => b.crawls));
  const rt = realisedTotal(realised.data);

  return (
    <div className="grid gap-4">
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
        <KpiCard label="Realised revenue" value={realised.data ? money(rt.micros, rt.currency) : "…"} accent="var(--ct-success)" gradient />
        <KpiCard label="Potential revenue (priced 402s)" value={money(totals.total_revenue_micros, currency)} accent="var(--ct-accent)" delta={data.change_pct} />
        <KpiCard label="Total AI crawls" value={compact(totals.total_crawls)} accent="var(--ct-accent-2)" />
        <KpiCard label="Charged (402)" value={compact(totals.charged)} accent="var(--ct-success)" />
        <KpiCard label="Blocked (403)" value={compact(totals.blocked)} accent="var(--ct-danger)" />
      </div>

      <RealisedSection realised={realised} />

      <TrafficSection traffic={traffic} />

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
  const realised = useRealised(period, tick);
  const traffic = useTraffic(period, tick);

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
        <DashboardBody data={data} ts={ts} realised={realised} traffic={traffic} />
      )}
    </div>
  );
}
