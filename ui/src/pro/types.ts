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
  // Metered free articles (Pro): N free reads per visitor per rolling window.
  // Absent/0 = paywall from the first article.
  meter_count?: number | string;
  meter_window?: number | string;
  // M1: per-IP ceiling multiple (1..20) — how many fresh identities from one
  // IP may burn meter slots per day. Absent = registry default (4×).
  meter_ip_ceiling?: number | string;
  // Access tiers (Pro, A2): up to 4 price×duration offers. duration_hours
  // null = "no expiry". Absent = legacy single-price.
  tiers?: AccessTier[];
  // Bundle (Pro, A4): sell one pass covering EVERYTHING under this path.
  // bundle_tiers are priced like access tiers (usually above single-article).
  bundle?: boolean;
  bundle_tiers?: AccessTier[];
  // Email gate (Pro, A5, spec §5.5): humans may unlock free after verifying
  // an email address (publisher gains a reachable subscriber). Crawlers pay.
  email_gate?: boolean;
}

export interface AccessTier {
  price_micros: number;
  duration_hours: number | null;
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

// Realised revenue (W1, 2026-09-06): money actually collected, from the
// registry's unlock receipts — as opposed to the priced-402 "potential" above.
export interface RealisedRail {
  rail: string;
  currency: string | null;
  count: number;
  amount_micros: number;
}
export interface RealisedResponse {
  days: number;
  unlocks: number;
  paid_unlocks: number;
  by_rail: RealisedRail[];
  by_day: Array<{ day: string; rail: string; count: number; amount_micros: number }>;
  enrolled: boolean;
}

// Traffic visibility (W5): every front-end request by kind + the sealed funnel.
export interface TrafficResponse {
  days: number;
  classes: { browser: number; ai_crawler: number; search_engine: number; automation: number };
  funnel: {
    sealed_views: number;
    walls_shown: number;
    walls_unavailable: number;
    unlocks: number;
    paid_unlocks: number;
    by_rail: { stripe: number; x402: number; meter: number; email: number; renewal: number; cache: number };
    wall_rate: number;
    unlock_rate: number;
  };
  top_automation: Array<{ ua: string; count: number }>;
  series: Array<Record<string, number | string>>;
}
