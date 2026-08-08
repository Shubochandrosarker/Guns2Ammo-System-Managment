// Domain types for the Guns2Ammo Business Control Center.
// The WordPress plugin `g2a-business-api` should serve payloads that
// conform to these shapes. Any deviation should be fixed on the API
// side, not by widening these types.

export type ISODate = string // YYYY-MM-DD or ISO 8601 datetime

export type Trend = 'up' | 'down' | 'flat'

export interface Range {
  from: ISODate
  to: ISODate
}

export interface StatValue {
  label: string
  value: number
  format?: 'currency' | 'number' | 'percent'
  deltaPct?: number // vs previous comparable window
  trend?: Trend
  sublabel?: string
}

export interface SeriesPoint {
  date: ISODate
  value: number
}

export interface RevenueOverview {
  range: Range
  totalRevenue: number
  woocommerceRevenue: number
  bookingRevenue: number
  membershipRevenue: number
  averageOrderValue: number
  revenueGrowthPct: number
  bestRevenueSource: 'woocommerce' | 'booking' | 'membership'
  series: SeriesPoint[]
}

// NOTE (Phase D): the legacy BookingAnalytics / MembershipAnalytics /
// StoreAnalytics shapes were deleted — /analytics/bookings and
// /analytics/memberships now speak the canonical envelope (src/types/api.ts)
// and /analytics/store was superseded by /analytics/woocommerce.

export interface ShooterInsights {
  knownShooters: number
  repeatRatePct: number
  firstTimeThisMonth: number
  lapsed90d: number
  segments: { key: string; label: string; value: number; hint: string }[]
  opportunities: string[]
}

export interface InsightisticAnalytics {
  range: Range
  sessions: number
  engagedSessions: number
  revenueAttributed: number // USD cents
  bounceRate: number        // percent
  sessionsSeries: SeriesPoint[]
}

export interface SeoAnalytics {
  range: Range
  clicks: number
  impressions: number
  ctr: number
  position: number
  topPages: { url: string; clicks: number; deltaPct: number }[]
  droppingPages: { url: string; clicks: number; deltaPct: number }[]
  topQueries: { query: string; clicks: number; position: number }[]
  clicksSeries: SeriesPoint[]
}

export interface BusinessGap {
  id: string
  problem: string
  evidence: string
  impact: string
  fix: string
  priority: 'high' | 'medium' | 'low'
  owner: string
}

export interface AIInsight {
  id: string
  title: string
  category:
    | 'seo'
    | 'booking'
    | 'membership'
    | 'store'
    | 'operations'
    | 'customer'
    | 'revenue'
  summary: string
  reason: string
  action: string
  priority: 'high' | 'medium' | 'low'
  expectedImpact: string
  createdAt: ISODate
}

export interface Automation {
  id: string
  slug: string
  name: string
  category:
    | 'booking'
    | 'membership'
    | 'waiver'
    | 'email'
    | 'sales'
    | 'seo'
    | 'staff'
    | 'reports'
    | 'agents'
  trigger: string
  action: string
  interval: 'hourly' | 'twicedaily' | 'daily' | 'weekly'
  status: 'active' | 'paused' | 'failing'
  lastRun: ISODate | null
  lastResult: 'ok' | 'error' | null
  runsLast7d: number
}

export interface Agent {
  id: string
  name: string
  department:
    | 'seo'
    | 'sales'
    | 'booking'
    | 'membership'
    | 'support'
    | 'email'
    | 'inventory'
    | 'reports'
    | 'automation'
    | 'analyst'
    | 'compliance'
  status: 'active' | 'paused' | 'needs_review'
  model: string
  assignedTasks: number
  lastRun: ISODate | null
  lastOutput: string
  confidence: number
  reviewRequired: boolean
}

export interface ModelConnection {
  id: string
  provider:
    | 'anthropic'
    | 'openai'
    | 'gemini'
    | 'openrouter'
    | 'ollama'
    | 'custom'
  displayName: string
  apiBaseUrl: string
  modelName: string
  contextLimit: number
  costLevel: 'free' | 'low' | 'medium' | 'high'
  useCase: string
  status: 'ok' | 'error' | 'untested'
  fallbackId: string | null
  keyMasked: string
}

export interface SystemHealthCheck {
  id: string
  label: string
  group: 'plugins' | 'apis' | 'cron' | 'jobs' | 'webhooks' | 'messaging' | 'ai' | 'security'
  status: 'ok' | 'warn' | 'error'
  detail: string
  lastCheckedAt: ISODate
}

// GET /emails/overview — real email traffic for the Email Management page.
// Each section carries `active: false` + null metrics when its source
// plugin isn't installed, so the UI can degrade gracefully.

export interface EmailOverviewSubmission {
  id: number
  formName: string
  name: string
  email: string
  subject: string
  messageExcerpt: string
  createdAt: ISODate
  status: 'new' | 'read' | 'replied'
}

export interface MessageisticStats {
  totalSent: number
  delivered: number
  failed: number
  replies: number
  contacts: number
  optedOut: number
  activeCampaigns: number
}

export interface EmailOverview {
  formistic: {
    active: boolean
    totalSubmissions: number | null
    newToday: number | null
    last7d: number | null
    byStatus: { new: number; read: number; replied: number } | null
    recentSubmissions: EmailOverviewSubmission[]
  }
  // Newsletter subscribers are deliberately a separate section — the owner
  // wants the newsletter split from the enquiry inbox.
  subscribers: {
    active: boolean
    total: number | null
    last30d: number | null
  }
  messageistic: {
    active: boolean
    stats: MessageisticStats | null
  }
}

// GET /system/integrations — configured/active booleans so pages can show
// "Not configured — connect in Settings" instead of zeroed charts.
export interface IntegrationsStatus {
  ga4Configured: boolean
  gscConfigured: boolean
  gbpConfigured: boolean
  wooActive: boolean
  bookingEngineActive: boolean
  memberisticActive: boolean
  messageisticActive: boolean
  formisticActive: boolean
  aiConnectionConfigured: boolean
}

// GET /leads, /leads/stats, /leads/{id}, PATCH /leads/{id} — g2a-business-api's
// Leads_Repository::shape_row() (includes/leads/class-leads-repository.php)
// is the source of truth for these field names; do not rename to "look nicer"
// without updating the PHP first.

export type LeadStatus = 'new' | 'in_progress' | 'contacted' | 'won' | 'lost' | 'spam'

// Mirrors the CATEGORY_* constants in includes/leads/class-lead-categorizer.php.
export type LeadCategory =
  | 'lane_booking'
  | 'ccw_class'
  | 'range_enquiry'
  | 'new_member'
  | 'ffl_transfer'
  | 'nfa'
  | 'membership'
  | 'event_booking'
  | 'general'

export interface Lead {
  id: number
  category: string
  source: string
  sourceRefId: string | null
  status: LeadStatus
  contactName: string | null
  contactEmail: string | null
  contactPhone: string | null
  subject: string | null
  excerpt: string | null
  assignedAgent: string | null
  meta: Record<string, unknown> | null
  createdAt: ISODate
  updatedAt: ISODate
}

export interface LeadsPage {
  items: Lead[]
  total: number
}

// Mirrors Leads_Repository::stats()'s return shape exactly.
export interface LeadStats {
  byCategory: Record<string, number>
  byStatus: Record<string, number>
  today: number
  last7d: number
  last30d: number
}

// GET /brain/query, /brain/stats, POST /brain/ingest — thin passthrough onto
// g2a-pos-core's BrainFacade (see Brain_Client in g2a-business-api). Every
// method degrades to `{ ok:false, results:[], reason:'pos_brain_inactive' }`
// (or `reason:'error: ...'`) when g2a-pos-core is inactive or throws, so
// every consumer must check `ok` before trusting `results`.
//
// Field names below are taken directly from g2a-pos-core's
// AiBrainRepository::search()/search_text() (local backends),
// CloudflareBrain::query() (cloudflare backend — normalized to the same
// keys), and AiBrainRepository::stats()/BrainFacade::stats() — NOT guessed
// camelCase equivalents.

// `score` comes from the vector/cloudflare backends; `match_score` from the
// keyword-tokenized local-text fallback; the plain LIKE fallback (no
// tokens matched) has neither.
export interface BrainQueryHit {
  id: string | number
  document_id: number
  text_content: string
  source_type: string
  source_label: string
  source_uri: string
  score?: number
  match_score?: number
}

// Discriminated on `ok` so callers narrow `results` just by checking it —
// matches Brain_Client's actual contract (ok:false is a normal 200 response
// with an empty `results` array, not a thrown error).
export type BrainQueryResult =
  | { ok: true; results: BrainQueryHit[] }
  | { ok: false; results: []; reason: string }

// AiBrainRepository::stats()'s per-source_type breakdown.
export interface BrainSourceTypeStat {
  source_type: string
  documents: number
  chunks: number
}

// AiBrainRepository::stats() + BrainFacade::stats()'s added `backend` field.
export interface BrainStatsData {
  documents: number
  chunks: number
  embedded_chunks: number
  embedded_pct: number
  by_source_type: BrainSourceTypeStat[]
  backend: string
}

export type BrainStats =
  | { ok: true; results: BrainStatsData }
  | { ok: false; results: []; reason: string }

// BrainService::ingest_text()'s return shape — the inner `results` of a
// successful /brain/ingest call. Its own `ok:false` means "empty body after
// normalization" (`error` field), distinct from the outer `ok:false`
// ("brain unavailable", `reason` field on BrainIngestResult below).
export type BrainIngestData =
  | { ok: true; document_id: number; chunks: number; embedded: boolean; skipped?: boolean; notice?: string }
  | { ok: false; error: string }

export type BrainIngestResult =
  | { ok: true; results: BrainIngestData }
  | { ok: false; results: []; reason: string }

// GET /system/namespaces — dynamic REST-namespace discovery. `namespaces`
// is the raw, real list WordPress core has registered (rest_get_server()
// ->get_namespaces()); `detected` is computed from that same list, never
// hardcoded, so third-party plugin cards only render when actually present.
export interface NamespacesStatus {
  namespaces: string[]
  detected: {
    rankMath: boolean
    redirection: boolean
    elementor: boolean
    wooCommerce: boolean
  }
}

// GET /system/site-health — Site_Health_Provider::summary()'s shape.
// `degraded: true` means core Site Health internals weren't reachable and
// this is the constants-only fallback (still real data, just a smaller set).
export interface SiteHealthSummary {
  wpVersion: string
  phpVersion: string
  activePluginCount: number
  debugMode: boolean
  displayErrors: boolean
  diskFreeBytes: number | null
  criticalIssues: number
  recommendedImprovements: number
  testsRun: string[]
  degraded: boolean
}

// GET /content/{posts|pages|media|categories|tags} — thin passthrough onto
// wp/v2, so field names below mirror wp/v2's own response shape (only the
// subset the Website Content page actually renders).
export interface WpContentRendered {
  rendered: string
}

export interface WpMediaSize {
  source_url: string
  width?: number
  height?: number
}

export interface WpContentItem {
  id: number
  date: ISODate
  status?: string
  link: string
  title?: WpContentRendered
  name?: string // categories/tags use `name`, not `title`.
  slug?: string
  count?: number // categories/tags term post-count.
  media_type?: string
  source_url?: string
  media_details?: { sizes?: Record<string, WpMediaSize> }
}

export interface ContentListParams {
  perPage?: number
  page?: number
  search?: string
  status?: string
}

export type ContentResourceType = 'posts' | 'pages' | 'media' | 'categories' | 'tags'

export interface ContentPage {
  items: WpContentItem[]
  total: number
  totalPages: number
}

// ---------------------------------------------------------------------------
// Waivers (g2a/v1/waivers — Memberistic unified waiver archive)
// ---------------------------------------------------------------------------

export type WaiverStatus = 'current' | 'expiring' | 'expired' | 'superseded'

export interface WaiverItem {
  id: number
  name: string
  email: string
  phone: string
  dob: string
  signed_at: string | null
  expires_at: string | null
  source: string
  minor_name: string
  is_current: boolean
  status: WaiverStatus
  matched: boolean
  has_pdf: boolean
}

export interface WaiversPage {
  active: boolean
  items: WaiverItem[]
  total: number
  page: number
}

export interface WaiverPeopleCounts {
  signed: number
  missing: number
  expired: number
  needs_review: number
  total: number
}

export interface WaiverStats {
  active: boolean
  on_file?: number
  current?: number
  expiring_30d?: number
  expired?: number
  people?: WaiverPeopleCounts
  upcoming_missing_48h?: number
  validity_days?: number
}

export interface WaiverHistoryRow {
  signature_id: number
  signed_at: string
  expires_at: string
  source: string
  version: number | null
  has_pdf: boolean
}

export interface WaiverDetail extends WaiverItem {
  emergency_name: string
  emergency_phone: string
  minor_age: string
  history: WaiverHistoryRow[]
}

export interface WaiverTodayItem {
  booking_id: number
  name: string
  email: string
  start_at: string
  status: string
  waiver_signed: boolean
  waiver_ok: boolean
}

export interface WaiverTodayPage {
  active: boolean
  items: WaiverTodayItem[]
}
