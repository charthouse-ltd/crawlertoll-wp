// Shapes returned by GET /crawlertoll/v1/stats (see rest_stats() +
// CrawlerToll_Pricing::revenue_comparison in the plugin).

export interface StatsTotals {
  total_crawls: number;
  charged: number;
  blocked: number;
  allowed: number;
  total_revenue_micros: number;
}

export interface TopBot {
  bot_name: string;
  crawls: number;
  revenue_micros?: number;
}

export interface TopPath {
  request_path: string;
  crawls: number;
  revenue_micros?: number;
}

export interface StatsCurrent {
  totals: StatsTotals;
  top_bots: TopBot[];
  top_paths: TopPath[];
}

export interface StatsResponse {
  period: { from: string; to: string };
  current: StatsCurrent;
  change_pct: number;
}

export type Period = "7d" | "30d";

// GET /crawlertoll/v1/stats/timeseries — per-day buckets (wpdb returns numeric
// columns as strings, so coerce with Number() before charting).
export interface DayBucket {
  day: string;
  crawls: number | string;
  allowed: number | string;
  charged: number | string;
  blocked: number | string;
  revenue_micros: number | string;
}

export interface TimeseriesResponse {
  period: { from: string; to: string };
  days: DayBucket[];
}

// Shapes for GET /crawlertoll/v1/logs (see rest_logs() + CrawlerToll_DB::query).

export interface LogEntry {
  request_time: string;
  bot_name: string;
  bot_operator: string;
  request_path: string;
  action: string; // 'allow' | '402' | 'block'
  price_micros: number;
  currency?: string;
  content_hash?: string;
}

export interface LogsResponse {
  entries: LogEntry[];
  total: number;
}

export type LogOrderBy = "request_time" | "bot_name";
export type Order = "ASC" | "DESC";

export interface LogFilters {
  bot: string;
  action: string;
  from: string;
  to: string;
  page: number;
  orderby: LogOrderBy;
  order: Order;
}

export interface Bot {
  name: string;
  operator: string;
  category: string;
}

// GET/POST /crawlertoll/v1/settings — backs the React Pro forms.
export interface PathRule {
  path: string;
  price_micros: number | string;
  currency: string;
}

export interface AlertSettings {
  daily: boolean;
  weekly: boolean;
  spike: boolean;
  email: string;
}

export interface SettingsResponse {
  price_micros: number;
  currency: string;
  rail: string;
  path_pricing: PathRule[];
  rail_overrides: Record<string, string>;
  alerts: AlertSettings;
  retention_days: number;
  meta: {
    rail_options: Record<string, string>;
    currencies: string[];
    fallback_email: string;
  };
}
