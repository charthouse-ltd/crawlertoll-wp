import { useState, type CSSProperties } from "react";
import { compact, exportUrl, LOGS_PER_PAGE, money, proConfig, saveRetention, useBots, useLogs, useSettings } from "../api";
import type { LogEntry, LogFilters, LogOrderBy } from "../types";
import { ActionBadge, Card, EmptyState, ErrorBox, Field, SaveButton } from "../components/ui";

const DEFAULT_FILTERS: LogFilters = {
  bot: "",
  action: "",
  from: "",
  to: "",
  page: 1,
  orderby: "request_time",
  order: "DESC",
};

const inputStyle: CSSProperties = {
  border: "1px solid var(--ct-border)",
  background: "var(--ct-elevated)",
  color: "var(--ct-text)",
  borderRadius: 8,
  padding: "6px 10px",
  fontSize: 13,
};

function fmtTime(s: string): string {
  const d = new Date(s.replace(" ", "T"));
  if (Number.isNaN(d.getTime())) {
    return s;
  }
  return d.toLocaleString(undefined, { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });
}

function SortHeader({
  label,
  col,
  filters,
  onSort,
  align = "left",
}: {
  label: string;
  col: LogOrderBy;
  filters: LogFilters;
  onSort: (c: LogOrderBy) => void;
  align?: "left" | "right";
}) {
  const active = filters.orderby === col;
  return (
    <th className={`py-2.5 px-3 text-${align}`}>
      <button
        type="button"
        onClick={() => onSort(col)}
        className="inline-flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide"
        style={{ color: active ? "var(--ct-text)" : "var(--ct-muted)" }}
      >
        {label}
        <span style={{ opacity: active ? 1 : 0.25 }}>{active && filters.order === "ASC" ? "↑" : "↓"}</span>
      </button>
    </th>
  );
}

function LogRow({ entry }: { entry: LogEntry }) {
  const currency = entry.currency || proConfig.currency;
  const price = entry.action === "402" && entry.price_micros > 0 ? money(entry.price_micros, currency, 4) : "—";
  const hash = entry.content_hash ? `${entry.content_hash.slice(0, 16)}…` : "—";
  return (
    <tr style={{ borderBottom: "1px solid color-mix(in srgb, var(--ct-border) 50%, transparent)" }}>
      <td className="whitespace-nowrap py-2 px-3 text-[12px]" style={{ color: "var(--ct-muted)" }}>
        {fmtTime(entry.request_time)}
      </td>
      <td className="py-2 px-3">
        <div className="text-[13px] font-semibold">{entry.bot_name}</div>
        <div className="text-[11px]" style={{ color: "var(--ct-muted)" }}>
          {entry.bot_operator}
        </div>
      </td>
      <td className="max-w-[280px] truncate py-2 px-3 font-mono text-[12px]" title={entry.request_path}>
        {entry.request_path}
      </td>
      <td className="py-2 px-3 text-center">
        <ActionBadge action={entry.action} />
      </td>
      <td
        className="py-2 px-3 text-right text-[13px] font-semibold tabular-nums"
        style={{ color: entry.action === "402" ? "var(--ct-success)" : "var(--ct-muted)" }}
      >
        {price}
      </td>
      <td className="py-2 px-3 font-mono text-[11px]" style={{ color: "var(--ct-accent)" }}>
        {hash}
      </td>
    </tr>
  );
}

function RetentionControl() {
  const { data } = useSettings();
  const [days, setDays] = useState<number | null>(null);
  if (!data) {
    return null;
  }
  const value = days ?? data.retention_days;
  return (
    <div className="ct-pro-card flex flex-wrap items-center gap-3 p-3">
      <span className="text-[13px] font-semibold">Keep logs for</span>
      <input
        type="number"
        min={0}
        value={value}
        onChange={(e) => setDays(Math.max(0, Number(e.target.value)))}
        style={{ ...inputStyle, width: 80 }}
      />
      <span className="text-[13px]" style={{ color: "var(--ct-muted)" }}>
        days — older entries are purged daily (0 = keep forever).
      </span>
      <SaveButton onSave={async () => void (await saveRetention(value))} label="Save" />
    </div>
  );
}

function TableSkeleton() {
  return (
    <div className="grid gap-2 p-4">
      {[0, 1, 2, 3, 4, 5, 6, 7].map((i) => (
        <div key={i} className="ct-skeleton h-8 w-full" />
      ))}
    </div>
  );
}

export function Logs() {
  const [filters, setFilters] = useState<LogFilters>(DEFAULT_FILTERS);
  const [draft, setDraft] = useState({ bot: "", action: "", from: "", to: "" });
  const [tick, setTick] = useState(0);

  const { data, loading, error } = useLogs(filters, tick);
  const bots = useBots();

  const total = data?.total ?? 0;
  const totalPages = Math.max(1, Math.ceil(total / LOGS_PER_PAGE));

  const apply = () => setFilters((f) => ({ ...f, ...draft, page: 1 }));
  const reset = () => {
    setDraft({ bot: "", action: "", from: "", to: "" });
    setFilters(DEFAULT_FILTERS);
  };
  const sort = (col: LogOrderBy) =>
    setFilters((f) => ({ ...f, orderby: col, order: f.orderby === col && f.order === "ASC" ? "DESC" : "ASC", page: 1 }));
  const goPage = (p: number) => setFilters((f) => ({ ...f, page: Math.min(totalPages, Math.max(1, p)) }));

  return (
    <div className="grid gap-4" style={{ paddingTop: 4 }}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-bold tracking-tight">Bot-request logs</h2>
          <p className="text-[13px]" style={{ color: "var(--ct-muted)" }}>
            Every AI-crawler request CrawlerToll saw, with the action it took.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <a href={exportUrl(filters, "csv")} className="rounded-lg px-3 py-1.5 text-[12px] font-semibold" style={{ border: "1px solid var(--ct-border)", color: "var(--ct-text)" }}>
            Export CSV
          </a>
          <a href={exportUrl(filters, "json")} className="rounded-lg px-3 py-1.5 text-[12px] font-semibold" style={{ border: "1px solid var(--ct-border)", color: "var(--ct-text)" }}>
            Export JSON
          </a>
        </div>
      </div>

      <RetentionControl />

      {/* Filters */}
      <Card>
        <div className="flex flex-wrap items-end gap-3">
          <Field label="Bot">
            <select style={{ ...inputStyle, minWidth: 180 }} value={draft.bot} onChange={(e) => setDraft((d) => ({ ...d, bot: e.target.value }))}>
              <option value="">All bots</option>
              {(bots.data ?? []).map((b) => (
                <option key={b.name} value={b.name}>
                  {b.name} ({b.operator})
                </option>
              ))}
            </select>
          </Field>
          <Field label="Action">
            <select style={inputStyle} value={draft.action} onChange={(e) => setDraft((d) => ({ ...d, action: e.target.value }))}>
              <option value="">All actions</option>
              <option value="allow">Allowed</option>
              <option value="402">Charged (402)</option>
              <option value="block">Blocked (403)</option>
            </select>
          </Field>
          <Field label="From">
            <input type="date" style={inputStyle} value={draft.from} onChange={(e) => setDraft((d) => ({ ...d, from: e.target.value }))} />
          </Field>
          <Field label="To">
            <input type="date" style={inputStyle} value={draft.to} onChange={(e) => setDraft((d) => ({ ...d, to: e.target.value }))} />
          </Field>
          <button type="button" onClick={apply} className="rounded-lg px-4 py-2 text-[13px] font-semibold text-white" style={{ background: "var(--ct-accent)" }}>
            Filter
          </button>
          <button type="button" onClick={reset} className="px-2 py-2 text-[12px] font-semibold underline" style={{ color: "var(--ct-muted)" }}>
            Reset
          </button>
        </div>
      </Card>

      {error ? (
        <ErrorBox message={error} onRetry={() => setTick((t) => t + 1)} />
      ) : (
        <>
          <div className="text-[13px]" style={{ color: "var(--ct-muted)" }}>
            {loading ? "Loading…" : `${compact(total)} ${total === 1 ? "entry" : "entries"} found`}
          </div>

          <div className="ct-pro-card overflow-x-auto p-0">
            {loading && !data ? (
              <TableSkeleton />
            ) : !data || data.entries.length === 0 ? (
              <EmptyState>No log entries match your filters. AI-crawler activity appears here as bots visit your site.</EmptyState>
            ) : (
              <table className="w-full text-[13px]">
                <thead>
                  <tr style={{ background: "var(--ct-surface)", borderBottom: "1px solid var(--ct-border)" }}>
                    <SortHeader label="Time" col="request_time" filters={filters} onSort={sort} />
                    <SortHeader label="Bot" col="bot_name" filters={filters} onSort={sort} />
                    <th className="py-2.5 px-3 text-left text-[11px] font-semibold uppercase tracking-wide" style={{ color: "var(--ct-muted)" }}>
                      Path
                    </th>
                    <th className="py-2.5 px-3 text-center text-[11px] font-semibold uppercase tracking-wide" style={{ color: "var(--ct-muted)" }}>
                      Action
                    </th>
                    <th className="py-2.5 px-3 text-right text-[11px] font-semibold uppercase tracking-wide" style={{ color: "var(--ct-muted)" }}>
                      Price
                    </th>
                    <th className="py-2.5 px-3 text-left text-[11px] font-semibold uppercase tracking-wide" style={{ color: "var(--ct-muted)" }}>
                      Content hash
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {data.entries.map((e, i) => (
                    <LogRow key={`${e.request_time}-${e.request_path}-${i}`} entry={e} />
                  ))}
                </tbody>
              </table>
            )}
          </div>

          {totalPages > 1 && (
            <div className="flex items-center justify-center gap-2">
              <button
                type="button"
                disabled={filters.page <= 1}
                onClick={() => goPage(filters.page - 1)}
                className="rounded-lg px-3.5 py-1.5 text-[13px] font-semibold disabled:opacity-40"
                style={{ border: "1px solid var(--ct-border)" }}
              >
                ← Prev
              </button>
              <span className="text-[13px]" style={{ color: "var(--ct-muted)" }}>
                Page {filters.page} of {totalPages}
              </span>
              <button
                type="button"
                disabled={filters.page >= totalPages}
                onClick={() => goPage(filters.page + 1)}
                className="rounded-lg px-3.5 py-1.5 text-[13px] font-semibold disabled:opacity-40"
                style={{ border: "1px solid var(--ct-border)" }}
              >
                Next →
              </button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
