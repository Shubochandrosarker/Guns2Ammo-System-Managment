// Phase C canonical API envelope + payload types for the two NEW endpoints:
//
//   GET {API_BASE}/dashboard/overview?from=YYYY-MM-DD&to=YYYY-MM-DD
//   GET {API_BASE}/system/health
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

export interface DashboardOverviewData {
  revenue: OverviewRevenue
  bookings: OverviewBookings
  memberships: OverviewMemberships
  woocommerce: OverviewWoocommerce
  waivers: OverviewWaivers
  alerts: OverviewAlert[]
  modules: Record<OverviewModuleKey, ModuleStatus>
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
