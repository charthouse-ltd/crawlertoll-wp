import { useEffect, useState } from "react";
import type {
  AlertSettings,
  Bot,
  LogFilters,
  LogsResponse,
  PathRule,
  Period,
  SettingsResponse,
  StatsResponse,
  TimeseriesResponse,
} from "./types";

export const LOGS_PER_PAGE = 50;

// The REST seam, injected by CrawlerToll_Pro_Admin::enqueue_pro_assets as an
// inline window.crawlertollPro blob. The Pro routes are cookie-authed and need
// the X-WP-Nonce header — without this nonce React gets a 401/403. Log export is
// a separate signed admin-ajax download, hence ajaxUrl + exportNonce.
interface ProConfig {
  restUrl: string;
  nonce: string;
  currency: string;
  ajaxUrl: string;
  exportNonce: string;
}

declare global {
  interface Window {
    crawlertollPro?: ProConfig;
  }
}

export const proConfig: ProConfig = window.crawlertollPro ?? {
  restUrl: "/wp-json/crawlertoll/v1/",
  nonce: "",
  currency: "USD",
  ajaxUrl: "/wp-admin/admin-ajax.php",
  exportNonce: "",
};

async function getJson<T>(path: string, signal?: AbortSignal): Promise<T> {
  const res = await fetch(`${proConfig.restUrl}${path}`, {
    headers: { "X-WP-Nonce": proConfig.nonce },
    credentials: "same-origin",
    signal,
  });
  if (!res.ok) {
    throw new Error(`Request failed (${res.status})`);
  }
  return (await res.json()) as T;
}

async function postJson<T>(path: string, body: unknown): Promise<T> {
  const res = await fetch(`${proConfig.restUrl}${path}`, {
    method: "POST",
    headers: { "X-WP-Nonce": proConfig.nonce, "Content-Type": "application/json" },
    credentials: "same-origin",
    body: JSON.stringify(body),
  });
  if (!res.ok) {
    throw new Error(`Save failed (${res.status})`);
  }
  return (await res.json()) as T;
}

export function fetchStats(period: Period, signal?: AbortSignal): Promise<StatsResponse> {
  return getJson<StatsResponse>(`stats?period=${period}`, signal);
}

export function fetchBots(signal?: AbortSignal): Promise<Bot[]> {
  return getJson<Bot[]>("bots", signal);
}

export function fetchTimeseries(period: Period, signal?: AbortSignal): Promise<TimeseriesResponse> {
  return getJson<TimeseriesResponse>(`stats/timeseries?period=${period}`, signal);
}

export function fetchSettings(signal?: AbortSignal): Promise<SettingsResponse> {
  return getJson<SettingsResponse>("settings", signal);
}

export function savePricing(rules: PathRule[]): Promise<{ path_pricing: PathRule[] }> {
  return postJson("settings/pricing", { rules });
}

export function saveAlerts(alerts: AlertSettings): Promise<{ alerts: AlertSettings }> {
  return postJson("settings/alerts", alerts);
}

export function saveRails(overrides: Record<string, string>): Promise<{ rail_overrides: Record<string, string> }> {
  return postJson("settings/rails", { overrides });
}

export function saveRetention(days: number): Promise<{ retention_days: number }> {
  return postJson("settings/retention", { days });
}

export function fetchLogs(filters: LogFilters, signal?: AbortSignal): Promise<LogsResponse> {
  const q = new URLSearchParams({
    bot: filters.bot,
    action: filters.action,
    from: filters.from,
    to: filters.to,
    page: String(filters.page),
    per_page: String(LOGS_PER_PAGE),
    orderby: filters.orderby,
    order: filters.order,
  });
  return getJson<LogsResponse>(`logs?${q.toString()}`, signal);
}

// Log export is a browser download of a signed admin-ajax URL (handle_export()
// verifies the crawlertoll_export_logs nonce), carrying the active filters so
// the file matches the on-screen view.
export function exportUrl(filters: LogFilters, format: "csv" | "json"): string {
  const q = new URLSearchParams({
    action: "crawlertoll_export_logs",
    ct_export: format,
    ct_bot: filters.bot,
    ct_action: filters.action,
    ct_from: filters.from,
    ct_to: filters.to,
    ct_orderby: filters.orderby,
    ct_order: filters.order,
    _wpnonce: proConfig.exportNonce,
  });
  return `${proConfig.ajaxUrl}?${q.toString()}`;
}

export interface AsyncState<T> {
  data: T | null;
  loading: boolean;
  error: string | null;
}

// Generic fetch-on-deps-change hook. Aborts the in-flight request on
// change/unmount so a slow response can't clobber a newer one.
function useAsync<T>(fetcher: (signal: AbortSignal) => Promise<T>, deps: unknown[]): AsyncState<T> {
  const [state, setState] = useState<AsyncState<T>>({ data: null, loading: true, error: null });

  useEffect(() => {
    const ctrl = new AbortController();
    setState((s) => ({ ...s, loading: true, error: null }));
    fetcher(ctrl.signal)
      .then((data) => setState({ data, loading: false, error: null }))
      .catch((err: unknown) => {
        if (ctrl.signal.aborted) {
          return;
        }
        setState({ data: null, loading: false, error: err instanceof Error ? err.message : "Request failed" });
      });
    return () => ctrl.abort();
    // deps are intentionally caller-controlled.
  }, deps); // eslint-disable-line react-hooks/exhaustive-deps

  return state;
}

// `tick` lets a caller force a refresh (manual reload / retry).
export function useStats(period: Period, tick = 0): AsyncState<StatsResponse> {
  return useAsync<StatsResponse>((signal) => fetchStats(period, signal), [period, tick]);
}

export function useLogs(filters: LogFilters, tick = 0): AsyncState<LogsResponse> {
  return useAsync<LogsResponse>((signal) => fetchLogs(filters, signal), [JSON.stringify(filters), tick]);
}

export function useBots(): AsyncState<Bot[]> {
  return useAsync<Bot[]>((signal) => fetchBots(signal), []);
}

export function useTimeseries(period: Period, tick = 0): AsyncState<TimeseriesResponse> {
  return useAsync<TimeseriesResponse>((signal) => fetchTimeseries(period, signal), [period, tick]);
}

export function useSettings(tick = 0): AsyncState<SettingsResponse> {
  return useAsync<SettingsResponse>((signal) => fetchSettings(signal), [tick]);
}

const SYMBOLS: Record<string, string> = { USD: "$", USDC: "$", EUR: "€", GBP: "£" };

export function money(micros: number | undefined, currency = proConfig.currency, decimals = 2): string {
  const sym = SYMBOLS[currency] ?? "";
  return `${sym}${((micros ?? 0) / 1_000_000).toFixed(decimals)}`;
}

export function compact(n: number): string {
  return new Intl.NumberFormat(undefined, { notation: "compact", maximumFractionDigits: 1 }).format(n);
}
