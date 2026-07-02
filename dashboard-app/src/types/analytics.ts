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

export interface BookingAnalytics {
  range: Range
  bookingsByType: { type: string; count: number; revenue: number }[]
  paidVsUnpaid: { paid: number; unpaid: number }
  cancellationRate: number
  noShowRate: number
  conversionRate: number
  topBookingType: string
  revenueSeries: SeriesPoint[]
}

export interface MembershipAnalytics {
  range: Range
  active: number
  newThisPeriod: number
  expired: number
  renewals: number
  corporate: number
  mrr: number
  churnRiskCount: number
  planPerformance: { plan: string; active: number; revenue: number }[]
  renewalOpportunityCount: number
}

export interface StoreAnalytics {
  range: Range
  orders: number
  revenue: number
  averageOrderValue: number
  repeatCustomerPct: number
  refundCount: number
  refundAmount: number
  topProducts: { id: number; name: string; sku: string; revenue: number; units: number }[]
  categoryRevenue: { category: string; revenue: number }[]
  brandRevenue: { brand: string; revenue: number }[]
  slowMovers: { id: number; name: string; daysWithoutSale: number }[]
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
