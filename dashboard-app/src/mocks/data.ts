// Bundled sample data. Everything is USD cents to match the on-wire shape.
// Realistic-looking numbers so demo screenshots don't look empty.

import type {
  AIInsight,
  Agent,
  Automation,
  BookingAnalytics,
  BrainQueryResult,
  BrainStats,
  BusinessGap,
  EmailOverview,
  InsightisticAnalytics,
  IntegrationsStatus,
  Lead,
  LeadStats,
  MembershipAnalytics,
  ModelConnection,
  NamespacesStatus,
  RevenueOverview,
  SeoAnalytics,
  SiteHealthSummary,
  StoreAnalytics,
  WpContentItem,
} from '@/types/analytics'
import type {
  ApiMeta,
  ApiSuccess,
  DashboardOverviewData,
  SystemHealthData,
} from '@/types/api'

const day = (n: number) => {
  const d = new Date('2026-07-02T00:00:00Z')
  d.setUTCDate(d.getUTCDate() - (29 - n))
  return d.toISOString().slice(0, 10)
}

const trend = (base: number, spread: number) =>
  Array.from({ length: 30 }, (_, i) => ({
    date: day(i),
    value: Math.max(
      0,
      Math.round(base + Math.sin(i / 3.4) * spread + (i / 30) * spread * 0.6),
    ),
  }))

export const revenueOverview: RevenueOverview = {
  range: { from: day(0), to: day(29) },
  totalRevenue: 4_812_400,
  woocommerceRevenue: 2_106_800,
  bookingRevenue: 1_486_200,
  membershipRevenue: 1_219_400,
  averageOrderValue: 8_940,
  revenueGrowthPct: 12.4,
  bestRevenueSource: 'woocommerce',
  series: trend(160_000, 55_000),
}

export const bookings: BookingAnalytics = {
  range: revenueOverview.range,
  bookingsByType: [
    { type: 'Range Lane',    count: 412, revenue: 828_400 },
    { type: 'CCW Class',     count:  74, revenue: 336_600 },
    { type: 'Ladies Tuesday',count:  61, revenue:  92_400 },
    { type: 'Private Event', count:  18, revenue: 178_200 },
    { type: 'Group Training',count:  22, revenue:  50_600 },
  ],
  paidVsUnpaid: { paid: 507, unpaid: 80 },
  cancellationRate: 6.2,
  noShowRate: 3.4,
  conversionRate: 41.7,
  topBookingType: 'Range Lane',
  revenueSeries: trend(49_000, 18_000),
}

export const memberships: MembershipAnalytics = {
  range: revenueOverview.range,
  active: 612,
  newThisPeriod: 48,
  expired: 27,
  renewals: 39,
  corporate: 11,
  mrr: 1_219_400,
  churnRiskCount: 34,
  planPerformance: [
    { plan: 'Defender',   active: 318, revenue: 445_200 },
    { plan: 'Patriot',    active: 201, revenue: 502_500 },
    { plan: 'Guardian',   active:  82, revenue: 246_000 },
    { plan: 'Instructor', active:  11, revenue:  25_700 },
  ],
  renewalOpportunityCount: 46,
}

export const store: StoreAnalytics = {
  range: revenueOverview.range,
  orders: 236,
  revenue: 2_106_800,
  averageOrderValue: 8_925,
  repeatCustomerPct: 34.1,
  refundCount: 4,
  refundAmount: 41_200,
  topProducts: [
    { id: 101, name: 'Federal 9mm 115gr FMJ 1000rd',      sku: 'FED-9M-1000', revenue: 218_400, units: 66 },
    { id: 102, name: 'Glock 19 Gen5 9mm',                  sku: 'G19-G5',      revenue: 189_600, units: 12 },
    { id: 103, name: 'Range Membership Pack — CCW',        sku: 'PACK-CCW',    revenue: 152_100, units: 21 },
    { id: 104, name: 'AR-15 Mid-length 5.56 Complete',     sku: 'AR15-ML',     revenue: 138_800, units:  8 },
    { id: 105, name: 'Sig Sauer P365 XL',                  sku: 'P365-XL',     revenue: 126_400, units: 11 },
  ],
  categoryRevenue: [
    { category: 'Ammunition', revenue: 812_000 },
    { category: 'Handguns',   revenue: 604_200 },
    { category: 'Rifles',     revenue: 318_400 },
    { category: 'Optics',     revenue: 172_600 },
    { category: 'Accessories',revenue: 199_600 },
  ],
  brandRevenue: [
    { brand: 'Federal',    revenue: 412_600 },
    { brand: 'Glock',      revenue: 306_200 },
    { brand: 'Sig Sauer',  revenue: 241_800 },
    { brand: 'Smith & Wesson', revenue: 188_400 },
    { brand: 'CZ',         revenue: 106_800 },
  ],
  slowMovers: [
    { id: 220, name: 'Bulk Lead Round Nose 38SPL',        daysWithoutSale: 41 },
    { id: 221, name: 'Cheap Import Holster Left-Hand',    daysWithoutSale: 62 },
    { id: 222, name: '.22LR Subsonic — 500 rd',           daysWithoutSale: 38 },
  ],
}

export const seo: SeoAnalytics = {
  range: revenueOverview.range,
  clicks: 5_412,
  impressions: 184_600,
  ctr: 2.9,
  position: 12.4,
  topPages: [
    { url: '/ccw-class/',            clicks: 812, deltaPct: 18.4 },
    { url: '/range/',                clicks: 604, deltaPct:  6.2 },
    { url: '/memberships/',          clicks: 411, deltaPct: 11.9 },
    { url: '/ladies-tuesday/',       clicks: 288, deltaPct: 22.6 },
    { url: '/training/',             clicks: 264, deltaPct:  3.1 },
  ],
  droppingPages: [
    { url: '/blog/az-ccw-guide/',    clicks: 118, deltaPct: -34.2 },
    { url: '/rentals/',              clicks:  94, deltaPct: -22.7 },
    { url: '/blog/first-time-shooters/', clicks: 71, deltaPct: -18.4 },
  ],
  topQueries: [
    { query: 'mesa ccw class',            clicks: 214, position: 3.1 },
    { query: 'indoor shooting range mesa',clicks: 188, position: 4.7 },
    { query: 'ladies range night mesa',   clicks: 141, position: 2.4 },
    { query: 'guns2ammo mesa',            clicks: 132, position: 1.2 },
  ],
  clicksSeries: trend(180, 60),
}

export const insightistic: InsightisticAnalytics = {
  range: revenueOverview.range,
  sessions: 18_412,
  engagedSessions: 11_208,
  revenueAttributed: 4_812_400,
  bounceRate: 38.2,
  sessionsSeries: trend(600, 180),
}

export const gaps: BusinessGap[] = [
  {
    id: 'gap-1',
    problem: 'CCW class page has high search traffic but low booking conversion',
    evidence: '/ccw-class/ received 812 organic clicks (+18%) but only 42 booking submits (5.2% conv).',
    impact: 'Estimated 12 additional CCW class bookings/mo (~$5,760 revenue).',
    fix: 'Move booking CTA above the fold, add visible next-class dates, add instructor trust block.',
    priority: 'high',
    owner: 'Marketing',
  },
  {
    id: 'gap-2',
    problem: 'Expired members are not being renewed',
    evidence: '27 memberships expired last 30d, only 39 renewed. Churn risk queue = 34.',
    impact: 'Preventing 15 churns worth ~$27,000 MRR annualized.',
    fix: 'Enable the 30/7/1-day renewal email + SMS automations; add renewal offer.',
    priority: 'high',
    owner: 'Membership Ops',
  },
  {
    id: 'gap-3',
    problem: 'Product views on Glock 19 not converting',
    evidence: '2,140 product views, 12 sales (0.56% conv). Trust/stock signals weak.',
    impact: '~5–8 additional sales/mo (~$9,000).',
    fix: 'Add stock indicator, "we do the FFL transfer" line, financing microcopy.',
    priority: 'medium',
    owner: 'Ecommerce',
  },
  {
    id: 'gap-4',
    problem: 'Ladies Tuesday page ranks well but page lacks upsell to CCW',
    evidence: '/ladies-tuesday/ +22.6% clicks; 0 internal links to CCW class.',
    impact: '~4 additional CCW enrollments/mo.',
    fix: 'Add "Comfortable? Take your CCW class here" block linking /ccw-class/.',
    priority: 'medium',
    owner: 'Content',
  },
]

export const insights: AIInsight[] = [
  {
    id: 'ins-1',
    title: 'CCW class page is leaking traffic',
    category: 'seo',
    summary: '812 clicks landed on /ccw-class/, but only 42 users started the booking flow.',
    reason: 'The booking widget is below the trust/instructor sections and next-class dates are hidden.',
    action: 'Promote next-class chip + CTA above the fold; add instructor credential row underneath.',
    priority: 'high',
    expectedImpact: '+12 bookings / mo, ~$5,760 revenue.',
    createdAt: '2026-07-02',
  },
  {
    id: 'ins-2',
    title: 'Membership renewals are underperforming',
    category: 'membership',
    summary: '27 expired vs 39 renewed = 59% renewal rate (industry: 72%).',
    reason: 'Renewal reminders are off; expired users get no re-engagement email.',
    action: 'Enable 30/7/1-day renewal + a one-time "come back" offer at day 14 post-expiry.',
    priority: 'high',
    expectedImpact: 'Recover 15 memberships/mo (~$27k ARR).',
    createdAt: '2026-07-02',
  },
  {
    id: 'ins-3',
    title: 'Ladies Tuesday bookings are climbing — capture the momentum',
    category: 'booking',
    summary: '61 bookings + 288 organic clicks (+22.6%).',
    reason: 'Fastest-growing lane category, but no post-booking upsell.',
    action: 'Trigger "add a friend for $15" and "graduate to CCW class" post-booking emails.',
    priority: 'medium',
    expectedImpact: '+8 upsells / mo (~$1,200 + 3 CCW enrollments).',
    createdAt: '2026-07-02',
  },
  {
    id: 'ins-4',
    title: 'Slow movers are tying up shelf space',
    category: 'store',
    summary: '3 SKUs unsold >38 days: 38SPL bulk lead, cheap left-hand holster, .22LR subsonic.',
    reason: 'Buyer intent shifted; these are legacy inventory.',
    action: 'Bundle with best-sellers or run a 25% clearance email to newsletter.',
    priority: 'low',
    expectedImpact: 'Free shelf space + ~$4k cash recovery.',
    createdAt: '2026-07-02',
  },
]

export const automations: Automation[] = [
  { id: 'a1', slug: 'booking-reminder-24h',        name: 'Booking reminder (24h before)',   category: 'booking',    trigger: '24h before booking start', action: 'Send SMS + email',       interval: 'hourly',     status: 'active', lastRun: '2026-07-02T09:12:00Z', lastResult: 'ok',    runsLast7d: 87 },
  { id: 'a2', slug: 'waiver-reminder',             name: 'Waiver reminder',                 category: 'waiver',     trigger: 'Booking made w/o waiver', action: 'Send waiver link email',  interval: 'hourly',     status: 'active', lastRun: '2026-07-02T08:44:00Z', lastResult: 'ok',    runsLast7d: 63 },
  { id: 'a3', slug: 'membership-renewal-reminders',name: 'Membership renewal 30/7/1-day',   category: 'membership', trigger: 'Days before expiry',      action: 'Send renewal reminder',   interval: 'daily',      status: 'paused', lastRun: null,                    lastResult: null,    runsLast7d:  0 },
  { id: 'a4', slug: 'abandoned-inquiry-alert',     name: 'Abandoned inquiry alert',         category: 'sales',      trigger: 'Contact form no reply 48h',action: 'Notify staff channel',   interval: 'hourly',     status: 'active', lastRun: '2026-07-01T22:01:00Z', lastResult: 'ok',    runsLast7d: 12 },
  { id: 'a5', slug: 'low-stock-alert',             name: 'Low-stock alert',                 category: 'staff',      trigger: 'Stock <= threshold',      action: 'Notify manager email',    interval: 'twicedaily', status: 'active', lastRun: '2026-07-02T06:30:00Z', lastResult: 'ok',    runsLast7d:  9 },
  { id: 'a6', slug: 'seo-click-drop-alert',        name: 'SEO click-drop alert',            category: 'seo',        trigger: 'Page clicks drop 25%',    action: 'Create SEO task',         interval: 'daily',      status: 'active', lastRun: '2026-07-02T05:14:00Z', lastResult: 'ok',    runsLast7d:  3 },
  { id: 'a7', slug: 'weekly-business-report',      name: 'Weekly business report',          category: 'reports',    trigger: 'Every Monday 07:00',      action: 'Email + Slack summary',    interval: 'weekly',     status: 'active', lastRun: '2026-06-30T14:00:00Z', lastResult: 'ok',    runsLast7d:  1 },
  { id: 'a8', slug: 'post-booking-upsell-ladies',  name: 'Post-booking upsell (Ladies)',    category: 'email',      trigger: 'Ladies Tuesday booking',  action: 'Send friend/CCW email',    interval: 'hourly',     status: 'active', lastRun: '2026-07-02T02:11:00Z', lastResult: 'ok',    runsLast7d: 17 },
  { id: 'a9', slug: 'agent-churn-risk-outreach',   name: 'Agent: churn-risk outreach',      category: 'agents',     trigger: 'Membership churn score',  action: 'Draft outreach email',     interval: 'daily',      status: 'active', lastRun: '2026-07-02T04:00:00Z', lastResult: 'ok',    runsLast7d:  4 },
]

export const agents: Agent[] = [
  { id: 'ag-seo',       name: 'SEO Growth Agent',        department: 'seo',        status: 'active',        model: 'Claude Opus 4.8',   assignedTasks: 6, lastRun: '2026-07-02T05:20:00Z', lastOutput: 'CCW class page has ranking opportunity for "mesa ccw class" — drafted internal link plan.', confidence: 0.86, reviewRequired: false },
  { id: 'ag-analyst',   name: 'Business Analyst Agent',  department: 'analyst',    status: 'active',        model: 'Claude Opus 4.8',   assignedTasks: 4, lastRun: '2026-07-02T04:12:00Z', lastOutput: 'Detected renewal gap (59% vs 72% industry) — proposed enabling automation A3.', confidence: 0.92, reviewRequired: false },
  { id: 'ag-booking',   name: 'Booking Agent',           department: 'booking',    status: 'active',        model: 'GPT-4o mini',      assignedTasks: 3, lastRun: '2026-07-02T02:44:00Z', lastOutput: 'Ladies Tuesday climbing +22%; suggested capacity boost on 2026-07-14.', confidence: 0.78, reviewRequired: true  },
  { id: 'ag-member',    name: 'Membership Agent',        department: 'membership', status: 'active',        model: 'Claude Opus 4.8',   assignedTasks: 5, lastRun: '2026-07-02T04:01:00Z', lastOutput: 'Drafted retention emails for 34 churn-risk members.', confidence: 0.81, reviewRequired: true  },
  { id: 'ag-support',   name: 'Customer Support Agent',  department: 'support',    status: 'active',        model: 'Gemini 2.5 Flash',      assignedTasks: 2, lastRun: '2026-07-02T07:11:00Z', lastOutput: 'Classified 26 support emails, escalated 3 urgent.', confidence: 0.74, reviewRequired: false },
  { id: 'ag-email',     name: 'Email Manager Agent',     department: 'email',      status: 'active',        model: 'Qwen 2.5 (OpenRouter)', assignedTasks: 7, lastRun: '2026-07-02T06:44:00Z', lastOutput: 'Drafted 8 replies pending approval.', confidence: 0.69, reviewRequired: true },
  { id: 'ag-sales',     name: 'Sales Follow-up Agent',   department: 'sales',      status: 'paused',        model: 'Claude Sonnet 5',    assignedTasks: 0, lastRun: '2026-06-28T14:00:00Z', lastOutput: 'Paused pending owner review of tone.', confidence: 0.61, reviewRequired: true },
  { id: 'ag-inv',       name: 'Inventory Insight Agent', department: 'inventory',  status: 'active',        model: 'Local Ollama Llama 3.1', assignedTasks: 1, lastRun: '2026-07-02T01:00:00Z', lastOutput: 'Flagged 3 slow-moving SKUs and drafted clearance bundle.', confidence: 0.72, reviewRequired: false },
  { id: 'ag-reports',   name: 'Report Agent',            department: 'reports',    status: 'active',        model: 'Claude Opus 4.8',   assignedTasks: 1, lastRun: '2026-06-30T14:00:00Z', lastOutput: 'Weekly report generated — sent to owner.', confidence: 0.94, reviewRequired: false },
  { id: 'ag-auto',      name: 'Automation Manager Agent',department: 'automation', status: 'needs_review',  model: 'GPT-4o mini',      assignedTasks: 2, lastRun: '2026-07-02T00:20:00Z', lastOutput: 'Recommends enabling A3 (renewal reminders). Requires approval.', confidence: 0.88, reviewRequired: true  },
  { id: 'ag-compliance',name: 'Compliance Workflow Agent',department: 'compliance',status: 'active',        model: 'Claude Opus 4.8',   assignedTasks: 3, lastRun: '2026-07-02T03:12:00Z', lastOutput: 'Verified FFL transfer queue; no anomalies.', confidence: 0.97, reviewRequired: false },
]

export const models: ModelConnection[] = [
  { id: 'm1', provider: 'anthropic',  displayName: 'Claude Opus 4.8',       apiBaseUrl: 'https://api.anthropic.com',  modelName: 'claude-opus-4-8',        contextLimit: 200_000, costLevel: 'high',   useCase: 'Deep business analysis, SEO, agents', status: 'ok',       fallbackId: 'm3', keyMasked: 'sk-ant-****abcd' },
  { id: 'm2', provider: 'openai',     displayName: 'GPT-4o mini',           apiBaseUrl: 'https://api.openai.com/v1',  modelName: 'gpt-4o-mini',          contextLimit: 128_000, costLevel: 'medium', useCase: 'Booking + email drafting',            status: 'ok',       fallbackId: 'm4', keyMasked: 'sk-****9f12' },
  { id: 'm3', provider: 'gemini',     displayName: 'Gemini 2.5 Flash',      apiBaseUrl: 'https://generativelanguage.googleapis.com', modelName: 'gemini-2.5-flash', contextLimit: 128_000, costLevel: 'medium', useCase: 'Customer support classification',    status: 'ok',       fallbackId: 'm4', keyMasked: 'AIza****kL7q' },
  { id: 'm4', provider: 'openrouter', displayName: 'Qwen 2.5 (OpenRouter)', apiBaseUrl: 'https://openrouter.ai/api/v1', modelName: 'qwen/qwen-2.5-72b-instruct',     contextLimit:  32_000, costLevel: 'low',    useCase: 'Cheap email drafts + summaries',      status: 'ok',       fallbackId: null, keyMasked: 'sk-or-****771a' },
  { id: 'm5', provider: 'ollama',     displayName: 'Local Llama 3.1',       apiBaseUrl: 'http://ollama.local:11434',  modelName: 'llama3.1:70b',           contextLimit:  32_000, costLevel: 'free',   useCase: 'Private inventory analysis',          status: 'untested', fallbackId: 'm4', keyMasked: '(local)' },
]

// Mock provider model catalog — mirrors GET /model-connections/{id}/catalog.
export const modelCatalog = [
  { id: 'claude-opus-4-8',            name: 'Claude Opus 4.8' },
  { id: 'claude-sonnet-4-6',          name: 'Claude Sonnet 4.6' },
  { id: 'claude-haiku-4-5',           name: 'Claude Haiku 4.5' },
  { id: 'gpt-4o-mini',                name: 'gpt-4o-mini' },
  { id: 'gemini-2.5-flash',           name: 'Gemini 2.5 Flash' },
  { id: 'qwen/qwen-2.5-72b-instruct', name: 'Qwen 2.5 72B Instruct' },
]

// ---------------------------------------------------------------------------
// Phase C canonical-envelope fixtures — shapes MUST match src/types/api.ts
// (the fixed backend contract) exactly.
// ---------------------------------------------------------------------------

const envelopeMeta = (requestId: string, source: string[]): ApiMeta => ({
  requestId,
  generatedAt: '2026-07-02T09:20:00Z',
  timezone: 'America/Phoenix',
  currency: 'USD',
  source,
  freshness: { status: 'fresh', lastUpdatedAt: '2026-07-02T09:19:12Z' },
})

// GET /dashboard/overview — demonstrates the honest data states on purpose:
//   bookings/memberships → available + data
//   woocommerce          → available but STALE (banner/pill demo)
//   waivers              → UNAVAILABLE (plugin inactive; card must never
//                          render its zeros as real data)
export const dashboardOverview: ApiSuccess<DashboardOverviewData> = {
  success: true,
  data: {
    revenue: {
      bookingsCents: 1_486_200,
      membershipsCents: 1_219_400,
      wooCents: 2_106_800,
      totalCents: 4_812_400,
    },
    bookings: { count: 587, paid: 507, unpaid: 80, revenueCents: 1_486_200 },
    memberships: { active: 612, new: 48, expired: 27, mrrCents: 1_219_400 },
    woocommerce: { orders: 236, revenueCents: 2_106_800 },
    waivers: { signed: 0, pending: 0 },
    alerts: [
      {
        code: 'stripe_webhook_silent',
        severity: 'critical',
        message: 'No Stripe webhook events received in 26 hours — booking payments may not be reconciling.',
      },
      {
        code: 'membership_renewal_gap',
        severity: 'warning',
        message: '27 memberships expired in this period against 39 renewals — renewal reminders are paused.',
      },
      {
        code: 'woo_sync_delayed',
        severity: 'info',
        message: 'WooCommerce order sync is running 40 minutes behind; store figures may lag slightly.',
      },
    ],
    modules: {
      bookings: {
        available: true,
        source: ['g2a-booking-engine'],
        freshness: { status: 'fresh', lastUpdatedAt: '2026-07-02T09:19:12Z' },
      },
      memberships: {
        available: true,
        source: ['memberistic'],
        freshness: { status: 'fresh', lastUpdatedAt: '2026-07-02T09:18:40Z' },
      },
      woocommerce: {
        available: true,
        source: ['woocommerce'],
        freshness: { status: 'stale', lastUpdatedAt: '2026-07-02T08:40:00Z' },
      },
      waivers: {
        available: false,
        source: ['g2a-waivers'],
        freshness: { status: 'unavailable', lastUpdatedAt: null },
      },
    },
  },
  meta: envelopeMeta('req_mock_overview_01', ['g2a-booking-engine', 'memberistic', 'woocommerce']),
}

// GET /system/health — canonical envelope.
export const systemHealth: ApiSuccess<SystemHealthData> = {
  success: true,
  data: {
    plugins: [
      { slug: 'g2a-business-api',   name: 'G2A Business API',   active: true,  version: '0.1.1' },
      { slug: 'g2a-booking-engine', name: 'G2A Booking Engine', active: true,  version: '1.14.6' },
      { slug: 'memberistic',        name: 'Memberistic',        active: true,  version: '1.9.9.4' },
      { slug: 'woocommerce',        name: 'WooCommerce',        active: true,  version: '9.8.2' },
      { slug: 'formistic',          name: 'Formistic',          active: true,  version: '2.3.0' },
      { slug: 'messageistic',       name: 'Messageistic',       active: true,  version: '1.6.1' },
      { slug: 'g2a-waivers',        name: 'G2A Waivers',        active: false, version: '0.9.0' },
    ],
    cron: [
      { hook: 'g2aba_generate_insights',        nextRunAt: '2026-07-02T10:00:00Z' },
      { hook: 'g2aba_run_booking_reminder',     nextRunAt: '2026-07-02T09:45:00Z' },
      { hook: 'g2aba_run_membership_renewal',   nextRunAt: '2026-07-03T07:00:00Z' },
      { hook: 'g2aba_run_weekly_report',        nextRunAt: '2026-07-06T14:00:00Z' },
      { hook: 'g2aba_run_waiver_reminder',      nextRunAt: null },
    ],
    sessions: { table: true, activeCount: 3 },
    audit: { recentFailures24h: 1 },
    integrations: {
      stripe: { configured: true },
      woo: { active: true },
    },
  },
  meta: envelopeMeta('req_mock_health_01', ['wordpress-core', 'g2a-business-api']),
}

// Phase 6 mocks.
export const emailDrafts = [
  {
    id: 'em_abc123', query: 'send follow-up email to Priya about her renewal',
    to: '', subject: 'A quick note about your Guns2Ammo membership',
    body: 'Hi Priya, wanted to check in about your Defender membership — it renewed on the card ending in 4128 but…',
    status: 'pending_send' as const,
    createdAt: '2026-07-02T09:14:00Z', sentAt: null, error: null,
  },
  {
    id: 'em_def456', query: 'draft weekly business summary for owner review',
    to: '', subject: 'Weekly business summary — 07/02',
    body: 'Revenue up 12.4%. Best channel: Store. Highest-priority gap: renewals (59% vs 72% target).',
    status: 'pending_send' as const,
    createdAt: '2026-07-02T07:00:00Z', sentAt: null, error: null,
  },
]

export const cancellations = [
  {
    id: 'cx_abc123', bookingId: '92841', query: 'cancel booking 92841 for Priya (schedule conflict)',
    status: 'awaiting_manual_action' as const,
    createdAt: '2026-07-02T08:42:00Z', resolvedAt: null, notes: null,
  },
  {
    id: 'cx_def456', bookingId: '90112', query: 'cancel booking 90112 — customer no longer available',
    status: 'awaiting_manual_action' as const,
    createdAt: '2026-07-01T18:22:00Z', resolvedAt: null, notes: null,
  },
]

export const agentHistory: Record<string, Array<{ ts: string; output: string; confidence: number }>> = {
  'ag-seo': [
    { ts: '2026-07-02T05:20:00Z', output: 'CCW class page has ranking opportunity for "mesa ccw class" — drafted internal link plan.', confidence: 0.86 },
    { ts: '2026-07-01T05:20:00Z', output: '/ladies-tuesday/ up 22.6%; /rentals/ down 22.7%. Add a CCW upsell block to /ladies-tuesday/.', confidence: 0.81 },
  ],
  'ag-analyst': [
    { ts: '2026-07-02T04:12:00Z', output: 'Detected renewal gap (59% vs 72% industry) — proposed enabling automation A3.', confidence: 0.92 },
  ],
}

export const auditLog = [
  { ts: '2026-07-03T09:44:00Z', kind: 'email.sent', summary: 'Sent draft em_abc123 to priya@example.com', actor: 12, meta: { draftId: 'em_abc123' } },
  { ts: '2026-07-03T08:22:00Z', kind: 'cancellation.completed', summary: 'Cancellation cx_abc123 marked completed', actor: 12, meta: {} },
  { ts: '2026-07-02T18:10:00Z', kind: 'email.discarded', summary: 'Discarded draft em_zzz999', actor: 12, meta: {} },
]

export const shooterInsights = {
  knownShooters: 2168,
  repeatRatePct: 34.1,
  firstTimeThisMonth: 148,
  lapsed90d: 318,
  segments: [
    { key: 'range-regulars',    label: 'Range regulars',           value: 612, hint: '≥ 2 lane bookings / 30d' },
    { key: 'ccw-graduates',     label: 'CCW graduates',            value: 204, hint: 'Completed CCW class, no gun purchase yet' },
    { key: 'ammo-bulk-buyers',  label: 'Ammo bulk buyers',         value: 118, hint: '≥ 1000rd order in last 60d' },
    { key: 'ladies-tuesday',    label: 'Ladies Tuesday attend',    value:  97, hint: 'Attended in last 90d' },
    { key: 'expired-30-90d',    label: 'Members expired 30-90d',   value:  46, hint: 'High-value win-back list' },
  ],
  opportunities: [
    'Send CCW alumni a rifle-class invite (204 shooters)',
    'Ammo bulk buyers → member conversion offer (118)',
    'Expired members within 90d → renewal + return credit (46)',
    'New first-time visitors last 14d → "come back for lane #2" (72)',
  ],
}

export const tasks = [
  {
    id: 'task_abc123',
    title: 'CCW class page is leaking traffic',
    body: 'Summary: 812 clicks landed on /ccw-class/, but only 42 users started the booking flow.\nAction: Promote next-class chip + CTA above the fold.',
    source: 'ai_insight' as const,
    sourceId: 'ins-1',
    owner: '',
    status: 'open' as const,
    createdAt: '2026-07-02T09:20:00Z',
    resolvedAt: null,
  },
]

export const reports = [
  { id: 'daily-summary',   label: 'Daily summary',            description: "Yesterday's revenue, bookings, membership churn, top SEO movers.", schedule: 'Every day 07:00', cronHook: 'g2aba_report_daily_summary', handlerSlug: 'daily-summary',   lastDeliveredAt: '2026-07-02T07:00:00Z', hasLatest: true },
  { id: 'weekly-business', label: 'Weekly business report',   description: 'Last-7-day roll-up across revenue, bookings, memberships, store, SEO.', schedule: 'Monday 07:00', cronHook: 'g2aba_automation_weekly-business-report', handlerSlug: 'weekly-business', lastDeliveredAt: '2026-06-30T07:00:00Z', hasLatest: true },
  { id: 'monthly-growth',  label: 'Monthly growth report',    description: 'Trailing 30 days vs the previous 30.', schedule: '1st of month 08:00', cronHook: 'g2aba_report_monthly_growth', handlerSlug: 'monthly-growth', lastDeliveredAt: '2026-06-01T08:00:00Z', hasLatest: true },
  { id: 'seo-report',      label: 'SEO report',               description: 'GSC clicks, impressions, position deltas.', schedule: 'Monday 08:00', cronHook: 'g2aba_report_seo', handlerSlug: 'seo', lastDeliveredAt: '2026-06-30T08:00:00Z', hasLatest: true },
  { id: 'booking-report',  label: 'Booking report',           description: 'Bookings by type, conversion, cancellations.', schedule: 'Monday 08:15', cronHook: 'g2aba_report_booking', handlerSlug: 'booking', lastDeliveredAt: '2026-06-30T08:15:00Z', hasLatest: true },
  { id: 'member-report',   label: 'Membership report',        description: 'Active / renewed / expired + MRR + churn.', schedule: 'Monday 08:30', cronHook: 'g2aba_report_member', handlerSlug: 'member', lastDeliveredAt: '2026-06-30T08:30:00Z', hasLatest: true },
  { id: 'woo-report',      label: 'WooCommerce report',       description: 'Store revenue, orders, AOV, top products.', schedule: 'Monday 08:45', cronHook: 'g2aba_report_woo', handlerSlug: 'woo', lastDeliveredAt: '2026-06-30T08:45:00Z', hasLatest: true },
  { id: 'ai-recs',         label: 'AI recommendations report', description: 'Open AI insights + business gaps + suggested tasks.', schedule: 'Wednesday 09:00', cronHook: 'g2aba_report_ai_recs', handlerSlug: 'ai-recs', lastDeliveredAt: '2026-06-24T09:00:00Z', hasLatest: true },
]

export const dashboardSettings = {
  defaultRange: 'last-30' as const,
  currency: 'USD' as const,
  weeklyReportDay: 'monday' as const,
  weeklyReportHour: 7,
  dailySummaryHour: 7,
  ownerEmail: 'owner@guns2ammo.com',
  timezone: 'America/Phoenix',
  allowedOrigins: ['https://app.guns2ammo.com'],
}

// GET /emails/overview — Formistic inbox + newsletter + Messageistic SMS.
export const emailOverview: EmailOverview = {
  formistic: {
    active: true,
    totalSubmissions: 486,
    newToday: 6,
    last7d: 41,
    byStatus: { new: 14, read: 22, replied: 450 },
    recentSubmissions: [
      { id: 486, formName: 'Contact',        name: 'Priya Sharma',  email: 'priya@example.com',  subject: 'CCW class availability', messageExcerpt: 'Hi — do you have any CCW class seats left for the July 12th session? Two of us would like to join…', createdAt: '2026-07-02T15:24:00Z', status: 'new' },
      { id: 485, formName: 'Range Booking',  name: 'Mark DeLuca',   email: 'mark.d@example.com', subject: 'Lane rental question',   messageExcerpt: 'Can I bring my own ammo for the rental pistols, or is range ammo required?', createdAt: '2026-07-02T13:02:00Z', status: 'new' },
      { id: 484, formName: 'Contact',        name: 'Angela Reyes',  email: 'angela@example.com', subject: 'Ladies Tuesday',         messageExcerpt: 'Is Ladies Tuesday beginner-friendly? I have never fired a handgun before.', createdAt: '2026-07-02T09:48:00Z', status: 'read' },
      { id: 483, formName: 'FFL Transfer',   name: 'Tom Brandt',    email: 'tom.b@example.com',  subject: 'Incoming transfer',      messageExcerpt: 'Bud’s order #99321 shipping to you — what do you need from me for pickup?', createdAt: '2026-07-01T19:15:00Z', status: 'replied' },
      { id: 482, formName: 'Contact',        name: 'Dana Whitfield',email: 'dana.w@example.com', subject: 'Membership upgrade',     messageExcerpt: 'I want to move from Defender to Patriot — does the annual price prorate?', createdAt: '2026-07-01T16:40:00Z', status: 'replied' },
    ],
  },
  subscribers: {
    active: true,
    total: 1842,
    last30d: 96,
  },
  messageistic: {
    active: true,
    stats: {
      totalSent: 1204,
      delivered: 1181,
      failed: 23,
      replies: 88,
      contacts: 2214,
      optedOut: 41,
      activeCampaigns: 2,
    },
  },
}

// GET /system/integrations — everything green in the demo except GBP.
export const integrations: IntegrationsStatus = {
  ga4Configured: true,
  gscConfigured: true,
  gbpConfigured: false,
  wooActive: true,
  bookingEngineActive: true,
  memberisticActive: true,
  messageisticActive: true,
  formisticActive: true,
  aiConnectionConfigured: true,
}

// GET /leads — a handful of leads spanning every category + status the
// categorizer/repository support (see Lead_Categorizer / Leads_Repository).
export const leads: Lead[] = [
  {
    id: 501, category: 'lane_booking', source: 'g2a_booking_engine', sourceRefId: '92841',
    status: 'new', contactName: 'Priya Sharma', contactEmail: 'priya@example.com', contactPhone: '480-555-0142',
    subject: 'Lane reservation — Sat 10am', excerpt: 'Would like 2 lanes Saturday morning, first time renting rifles.',
    assignedAgent: null, meta: { lanes: 2 }, createdAt: '2026-07-05T08:12:00Z', updatedAt: '2026-07-05T08:12:00Z',
  },
  {
    id: 500, category: 'ccw_class', source: 'formistic', sourceRefId: '486',
    status: 'in_progress', contactName: 'Mark DeLuca', contactEmail: 'mark.d@example.com', contactPhone: null,
    subject: 'CCW class availability', excerpt: 'Do you have any CCW class seats left for the July 12th session?',
    assignedAgent: 'ag-booking', meta: null, createdAt: '2026-07-04T15:24:00Z', updatedAt: '2026-07-05T09:02:00Z',
  },
  {
    id: 499, category: 'range_enquiry', source: 'formistic', sourceRefId: '485',
    status: 'contacted', contactName: 'Angela Reyes', contactEmail: 'angela@example.com', contactPhone: '602-555-0110',
    subject: 'Lane rental question', excerpt: 'Can I bring my own ammo for the rental pistols, or is range ammo required?',
    assignedAgent: 'ag-support', meta: null, createdAt: '2026-07-03T13:02:00Z', updatedAt: '2026-07-03T18:40:00Z',
  },
  {
    id: 498, category: 'new_member', source: 'memberistic', sourceRefId: '7741',
    status: 'won', contactName: 'Dana Whitfield', contactEmail: 'dana.w@example.com', contactPhone: '602-555-0199',
    subject: 'New Defender membership', excerpt: 'Signed up for the Defender plan after the open-house event.',
    assignedAgent: 'ag-member', meta: { plan: 'Defender' }, createdAt: '2026-07-02T16:40:00Z', updatedAt: '2026-07-02T17:05:00Z',
  },
  {
    id: 497, category: 'ffl_transfer', source: 'formistic', sourceRefId: '483',
    status: 'in_progress', contactName: 'Tom Brandt', contactEmail: 'tom.b@example.com', contactPhone: '480-555-0177',
    subject: 'Incoming transfer', excerpt: "Bud's order #99321 shipping to you — what do you need from me for pickup?",
    assignedAgent: null, meta: null, createdAt: '2026-07-01T19:15:00Z', updatedAt: '2026-07-01T19:15:00Z',
  },
  {
    id: 496, category: 'nfa', source: 'formistic', sourceRefId: '479',
    status: 'new', contactName: 'Ellis Cho', contactEmail: 'ellis.cho@example.com', contactPhone: null,
    subject: 'Suppressor trust question', excerpt: 'Looking to set up a gun trust before ordering a suppressor — do you help with that?',
    assignedAgent: null, meta: null, createdAt: '2026-06-30T11:05:00Z', updatedAt: '2026-06-30T11:05:00Z',
  },
  {
    id: 495, category: 'membership', source: 'formistic', sourceRefId: '482',
    status: 'contacted', contactName: 'Dana Whitfield', contactEmail: 'dana.w@example.com', contactPhone: null,
    subject: 'Membership upgrade', excerpt: 'I want to move from Defender to Patriot — does the annual price prorate?',
    assignedAgent: 'ag-member', meta: null, createdAt: '2026-06-29T16:40:00Z', updatedAt: '2026-06-30T09:00:00Z',
  },
  {
    id: 494, category: 'event_booking', source: 'g2a_booking_engine', sourceRefId: '90112',
    status: 'lost', contactName: 'Group Sales', contactEmail: 'events@example.com', contactPhone: '602-555-0133',
    subject: 'Private corporate event — 20 guests', excerpt: 'Requested a Friday evening private event slot; went with a competitor over pricing.',
    assignedAgent: 'ag-sales', meta: { guests: 20 }, createdAt: '2026-06-27T14:22:00Z', updatedAt: '2026-06-28T10:00:00Z',
  },
  {
    id: 493, category: 'general', source: 'formistic', sourceRefId: '470',
    status: 'spam', contactName: 'Unknown', contactEmail: 'noreply@spamdomain.example', contactPhone: null,
    subject: 'SEO services for your website', excerpt: 'We noticed your website could rank higher — reply for a free audit.',
    assignedAgent: null, meta: null, createdAt: '2026-06-25T04:10:00Z', updatedAt: '2026-06-25T04:10:00Z',
  },
]

// GET /leads/stats
export const leadStats: LeadStats = {
  byCategory: {
    lane_booking: 64,
    ccw_class: 41,
    range_enquiry: 37,
    new_member: 22,
    ffl_transfer: 18,
    nfa: 6,
    membership: 15,
    event_booking: 9,
    general: 12,
  },
  byStatus: {
    new: 38,
    in_progress: 27,
    contacted: 44,
    won: 62,
    lost: 19,
    spam: 34,
  },
  today: 6,
  last7d: 58,
  last30d: 224,
}

// GET /brain/stats — the shared knowledge brain is "active" in mock mode so
// the ingest form / search tool are browsable without a live g2a-pos-core.
// Field names match AiBrainRepository::stats() + BrainFacade::stats() in
// g2a-pos-core exactly (documents/chunks/embedded_chunks/embedded_pct/
// by_source_type/backend) — not guessed camelCase.
export const brainStats: BrainStats = {
  ok: true,
  results: {
    documents: 128,
    chunks: 942,
    embedded_chunks: 908,
    embedded_pct: 96.4,
    backend: 'local',
    by_source_type: [
      { source_type: 'formistic_faq', documents: 54, chunks: 402 },
      { source_type: 'range_policy',  documents: 22, chunks: 210 },
      { source_type: 'manual',        documents: 52, chunks: 330 },
    ],
  },
}

// GET /system/namespaces — the raw, real namespace list plus computed
// `detected` flags. Mock mode pretends Rank Math is installed (so the
// System Health page can be screenshotted with a populated card) but
// Redirection/Elementor are not — demonstrating the "omit when absent"
// behaviour the real endpoint drives.
export const namespacesStatus: NamespacesStatus = {
  namespaces: [
    'wp/v2',
    'g2a/v1',
    'g2a-pos/v1',
    'g2a-booking/v1',
    'memberistic/v1',
    'wpistic-ffl/v1',
    'formistic/v1',
    'messageistic/v1',
    'rankmath/v1',
    'wp-site-health/v1',
    'oembed/1.0',
  ],
  detected: {
    rankMath: true,
    redirection: false,
    elementor: false,
    wooCommerce: false,
  },
}

// GET /system/site-health — a healthy-looking demo summary.
export const siteHealthSummary: SiteHealthSummary = {
  wpVersion: '6.7.1',
  phpVersion: '8.3.14',
  activePluginCount: 14,
  debugMode: false,
  displayErrors: false,
  diskFreeBytes: 48_500_000_000,
  criticalIssues: 0,
  recommendedImprovements: 1,
  testsRun: ['background-updates', 'loopback-requests', 'https-status', 'authorization-header'],
  degraded: false,
}

// GET /content/{posts|pages|media|categories|tags} — field names mirror
// wp/v2's own response shape (title.rendered, name for taxonomy terms,
// media_details.sizes for thumbnails), not a guessed camelCase shape.
export const contentPosts: WpContentItem[] = [
  { id: 301, date: '2026-07-01T09:00:00', status: 'publish', link: 'https://guns2ammo.com/blog/summer-ccw-class-schedule/', title: { rendered: 'Summer CCW Class Schedule Is Live' } },
  { id: 300, date: '2026-06-24T10:15:00', status: 'publish', link: 'https://guns2ammo.com/blog/az-ccw-guide/', title: { rendered: 'The Complete Arizona CCW Guide' } },
  { id: 299, date: '2026-06-18T08:30:00', status: 'draft',   link: 'https://guns2ammo.com/?p=299', title: { rendered: 'Ladies Tuesday: What To Expect Your First Night' } },
  { id: 298, date: '2026-06-10T14:00:00', status: 'publish', link: 'https://guns2ammo.com/blog/first-time-shooters/', title: { rendered: 'A Beginner’s Guide To Your First Range Visit' } },
  { id: 297, date: '2026-05-29T11:45:00', status: 'publish', link: 'https://guns2ammo.com/blog/ammo-restock-may/', title: { rendered: 'May Ammo Restock: What Just Came In' } },
]

export const contentPages: WpContentItem[] = [
  { id: 12, date: '2026-04-02T09:00:00', status: 'publish', link: 'https://guns2ammo.com/range/', title: { rendered: 'Range' } },
  { id: 11, date: '2026-04-02T09:00:00', status: 'publish', link: 'https://guns2ammo.com/ccw-class/', title: { rendered: 'CCW Class' } },
  { id: 10, date: '2026-04-02T09:00:00', status: 'publish', link: 'https://guns2ammo.com/memberships/', title: { rendered: 'Memberships' } },
  { id: 9,  date: '2026-03-14T09:00:00', status: 'publish', link: 'https://guns2ammo.com/ladies-tuesday/', title: { rendered: 'Ladies Tuesday' } },
  { id: 8,  date: '2026-02-01T09:00:00', status: 'draft',   link: 'https://guns2ammo.com/?page_id=8', title: { rendered: 'Corporate & Private Events (new)' } },
]

export const contentMedia: WpContentItem[] = [
  { id: 512, date: '2026-07-01T09:05:00', link: 'https://guns2ammo.com/wp-content/uploads/2026/07/ccw-class-hero.jpg', media_type: 'image', source_url: 'https://guns2ammo.com/wp-content/uploads/2026/07/ccw-class-hero.jpg', media_details: { sizes: { thumbnail: { source_url: 'https://guns2ammo.com/wp-content/uploads/2026/07/ccw-class-hero-150x150.jpg', width: 150, height: 150 } } } },
  { id: 498, date: '2026-06-24T10:20:00', link: 'https://guns2ammo.com/wp-content/uploads/2026/06/az-ccw-guide-cover.jpg', media_type: 'image', source_url: 'https://guns2ammo.com/wp-content/uploads/2026/06/az-ccw-guide-cover.jpg', media_details: { sizes: { thumbnail: { source_url: 'https://guns2ammo.com/wp-content/uploads/2026/06/az-ccw-guide-cover-150x150.jpg', width: 150, height: 150 } } } },
  { id: 455, date: '2026-05-29T11:50:00', link: 'https://guns2ammo.com/wp-content/uploads/2026/05/ammo-restock.jpg', media_type: 'image', source_url: 'https://guns2ammo.com/wp-content/uploads/2026/05/ammo-restock.jpg', media_details: { sizes: {} } },
]

export const contentCategories: WpContentItem[] = [
  { id: 2, date: '', link: 'https://guns2ammo.com/category/training/', name: 'Training', slug: 'training', count: 18 },
  { id: 3, date: '', link: 'https://guns2ammo.com/category/range-news/', name: 'Range News', slug: 'range-news', count: 24 },
  { id: 4, date: '', link: 'https://guns2ammo.com/category/products/', name: 'Products', slug: 'products', count: 9 },
]

export const contentTags: WpContentItem[] = [
  { id: 21, date: '', link: 'https://guns2ammo.com/tag/ccw/', name: 'CCW', slug: 'ccw', count: 12 },
  { id: 22, date: '', link: 'https://guns2ammo.com/tag/ladies-tuesday/', name: 'Ladies Tuesday', slug: 'ladies-tuesday', count: 5 },
  { id: 23, date: '', link: 'https://guns2ammo.com/tag/ammo/', name: 'Ammo', slug: 'ammo', count: 8 },
]

// GET /brain/query — hit shape matches AiBrainRepository::search()/
// search_text() (id/document_id/text_content/source_type/source_label/
// source_uri/score).
export const brainQueryResult: BrainQueryResult = {
  ok: true,
  results: [
    { id: 1, document_id: 41, text_content: 'The range is open 9am-9pm Monday through Saturday, 10am-6pm Sunday.', source_type: 'range_policy', source_label: 'Range hours', source_uri: '', score: 0.93 },
    { id: 2, document_id: 12, text_content: 'CCW class requires attendees to be 21+ and bring a valid state ID; no firearm experience required.', source_type: 'formistic_faq', source_label: 'CCW class prerequisites', source_uri: '', score: 0.88 },
    { id: 3, document_id: 41, text_content: 'Range ammo is required for all rental firearms; outside ammo is only permitted with owned firearms.', source_type: 'range_policy', source_label: 'Rental ammo policy', source_uri: '', score: 0.81 },
  ],
}

// GET /model-routing — purpose → model-connection map (moved here from
// api.ts so nothing mock-shaped survives in the production bundle).
export const mockPurposes = [
  'business_analysis', 'seo_analysis', 'booking_suggest', 'support_classify',
  'email_drafts', 'daily_summaries', 'private_inventory',
]

export const mockRoutingLabels: Record<string, string> = {
  business_analysis: 'Deep business analysis',
  seo_analysis:      'SEO analysis',
  booking_suggest:   'Booking suggestions',
  support_classify:  'Customer support classify',
  email_drafts:      'Email drafts',
  daily_summaries:   'Cheap daily summaries',
  private_inventory: 'Private inventory',
}

export const mockRouting: Record<string, string | null> = {
  business_analysis: 'm1',
  seo_analysis:      'm1',
  booking_suggest:   'm2',
  support_classify:  'm3',
  email_drafts:      'm4',
  daily_summaries:   'm4',
  private_inventory: 'm5',
}
