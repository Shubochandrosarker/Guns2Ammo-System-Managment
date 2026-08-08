// Phase C/D canonical API envelope + payload types for the enveloped endpoints:
//
//   GET {API_BASE}/dashboard/overview?from=YYYY-MM-DD&to=YYYY-MM-DD
//   GET {API_BASE}/system/health
//   GET {API_BASE}/analytics/bookings?from&to      (Phase D)
//   GET {API_BASE}/analytics/memberships?from&to   (Phase D)
//   GET {API_BASE}/analytics/woocommerce?from&to   (Phase D)
//   GET {API_BASE}/analytics/waivers               (Phase D)
//
// FIXED CONTRACT — the backend is being built to exactly these shapes:
//   success  → {"success":true,"data":<payload>,"meta":{...}}
//   failure  → {"success":false,"error":{"code","message","requestId"}}
//
// All money is integer USD cents on the wire. All datetimes are ISO-8601
// UTC strings; the UI renders them in the business timezone carried in
// `meta.timezone`.

import type { ISODate } from './analytics'

// ---------------------------------------------------------------------------
// Envelope
// ---------------------------------------------------------------------------

export type FreshnessStatus = 'fresh' | 'stale' | 'unavailable'

export interface Freshness {
  status: FreshnessStatus
  /** ISO-8601 UTC; null when the source has never produced data. */
  lastUpdatedAt: ISODate | null
}

export interface ApiMeta {
  requestId: string
  generatedAt: ISODate
  /** IANA business timezone, e.g. 'America/Phoenix'. Render datetimes in it. */
  timezone: string
  currency: 'USD'
  /** Which plugins/integrations produced this payload. */
  source: string[]
  freshness: Freshness
}

export interface ApiErrorPayload {
  code: string
  message: string
  requestId: string
}

export interface ApiSuccess<T> {
  success: true
  data: T
  meta: ApiMeta
}

export interface ApiFailure {
  success: false
  error: ApiErrorPayload
}

export type ApiEnvelope<T> = ApiSuccess<T> | ApiFailure

/** What api.ts hands to callers after unwrapping: payload + surfaced meta. */
export interface Enveloped<T> {
  data: T
  meta: ApiMeta
}

// ---------------------------------------------------------------------------
// GET /dashboard/overview
// ---------------------------------------------------------------------------

// Severity values assumed pending backend confirmation; the alert renderer
// treats anything unknown as 'info' so a new severity can never crash the UI.
export type AlertSeverity = 'critical' | 'error' | 'warning' | 'info'

export interface OverviewAlert {
  code: string
  severity: AlertSeverity
  message: string
}

export type OverviewModuleKey = 'bookings' | 'memberships' | 'woocommerce' | 'waivers'

export interface ModuleStatus {
  /** false → source plugin missing/inactive. NEVER render its zeros as data. */
  available: boolean
  /** Plugin slug(s) backing the module, e.g. ['g2a-booking-engine']. */
  source: string[]
  freshness: Freshness
}

export interface OverviewRevenue {
  bookingsCents: number
  membershipsCents: number
  wooCents: number
  totalCents: number
}

// NOTE: the fixed contract specifies `bookings:{...}` etc. without inner
// fields. The field sets below are the frontend's minimal assumption and are
// flagged as a contract deviation risk until the backend payloads land.
export interface OverviewBookings {
  count: number
  paid: number
  unpaid: number
  revenueCents: number
}

export interface OverviewMemberships {
  active: number
  new: number
  expired: number
  mrrCents: number
}

export interface OverviewWoocommerce {
  orders: number
  revenueCents: number
}

export interface OverviewWaivers {
  signed: number
  pending: number
}

/** One day of the overview revenue series (zero-filled; all integer cents). */
export interface OverviewSeriesPoint {
  /** YYYY-MM-DD in the business timezone. */
  date: ISODate
  bookingsCents: number
  membershipsCents: number
  wooCents: number
  totalCents: number
}

export interface DashboardOverviewData {
  revenue: OverviewRevenue
  bookings: OverviewBookings
  memberships: OverviewMemberships
  woocommerce: OverviewWoocommerce
  waivers: OverviewWaivers
  alerts: OverviewAlert[]
  modules: Record<OverviewModuleKey, ModuleStatus>
  /** Daily per-channel revenue, zero-filled across the requested range. */
  series: OverviewSeriesPoint[]
}

// ---------------------------------------------------------------------------
// Phase D analytics endpoints — shared building blocks
// ---------------------------------------------------------------------------

/** One zero-filled day of an analytics series (count + integer cents). */
export interface AnalyticsSeriesPoint {
  /** YYYY-MM-DD. */
  date: ISODate
  count: number
  revenueCents: number
}

/**
 * Previous-comparable-window trend block shared by the bookings /
 * memberships / woocommerce analytics payloads. `deltas.*Pct` are null when
 * the previous window has no baseline (division by zero) — render "n/a",
 * never 0%.
 */
export interface AnalyticsTrends {
  previous: {
    count: number
    revenueCents: number
    netCents: number
  }
  deltas: {
    countPct: number | null
    revenuePct: number | null
  }
}

// ---------------------------------------------------------------------------
// GET /analytics/bookings?from&to
// ---------------------------------------------------------------------------

export interface BookingsByType {
  typeId: number
  name: string
  count: number
  revenueCents: number
}

/** Overview booking fields PLUS byType / series / trends. */
export interface BookingsAnalyticsData extends OverviewBookings {
  byType: BookingsByType[]
  series: AnalyticsSeriesPoint[]
  trends: AnalyticsTrends
}

// ---------------------------------------------------------------------------
// GET /analytics/memberships?from&to
// ---------------------------------------------------------------------------

export interface MembershipPlanBreakdown {
  planId: number
  name: string
  active: number
  mrrCents: number
}

export interface MembershipChurn {
  /** Percent (e.g. 4.2) or null when it cannot be computed. */
  rate: number | null
  /** Human-readable formula the backend used, shown verbatim in the UI. */
  calculation: string
}

/** One day of the memberships series — new members signed that day. */
export interface MembershipSeriesPoint {
  /** YYYY-MM-DD. */
  date: ISODate
  newMembers: number
}

/** Overview membership lifecycle counts PLUS plan/renewal/churn detail. */
export interface MembershipsAnalyticsData extends OverviewMemberships {
  planBreakdown: MembershipPlanBreakdown[]
  newInRange: number
  renewalsInRange: number
  failedRenewals: number
  churn: MembershipChurn
  series: MembershipSeriesPoint[]
  trends: AnalyticsTrends
}

// ---------------------------------------------------------------------------
// GET /analytics/woocommerce?from&to
// ---------------------------------------------------------------------------

export interface WooTopProduct {
  productId: number
  name: string
  qty: number
  revenueCents: number
}

export interface WooCategoryRevenue {
  /** Category term slug. */
  term: string
  name: string
  revenueCents: number
  qty: number
}

/** Overview Woo aggregates PLUS products/categories/series/trends. */
export interface WooAnalyticsData extends OverviewWoocommerce {
  /** ≤ 10 rows. */
  topProducts: WooTopProduct[]
  /** ≤ 10 rows. */
  byCategory: WooCategoryRevenue[]
  series: AnalyticsSeriesPoint[]
  trends: AnalyticsTrends
  /** true → the backend capped the period at its first 5,000 orders. */
  truncated: boolean
}

// ---------------------------------------------------------------------------
// GET /analytics/waivers  (counts only — NO PII on this wire)
// ---------------------------------------------------------------------------

export interface WaiversMonthCount {
  /** YYYY-MM. */
  month: string
  count: number
}

/** Overview waiver fields PLUS expiry buckets + 12-month signing history. */
export interface WaiversAnalyticsData extends OverviewWaivers {
  expiring: {
    d30: number
    d60: number
    d90: number
  }
  signings: {
    /** Last 12 months, oldest first. */
    archive: WaiversMonthCount[]
    people: WaiversMonthCount[]
  }
}

// ---------------------------------------------------------------------------
// GET /system/health
// ---------------------------------------------------------------------------

export interface HealthPlugin {
  slug: string
  name: string
  active: boolean
  version: string
}

export interface HealthCronJob {
  hook: string
  /** ISO-8601 UTC; null → hook exists but nothing is scheduled. */
  nextRunAt: ISODate | null
}

export interface SystemHealthData {
  plugins: HealthPlugin[]
  cron: HealthCronJob[]
  sessions: {
    /** Whether the dashboard sessions table exists. */
    table: boolean
    activeCount: number
  }
  audit: {
    recentFailures24h: number
  }
  integrations: {
    stripe: { configured: boolean }
    woo: { active: boolean }
  }
}
