// Bundled sample data. Everything is USD cents to match the on-wire shape.
// Realistic-looking numbers so demo screenshots don't look empty.

import type {
  AIInsight,
  Agent,
  Automation,
  BookingAnalytics,
  BusinessGap,
  InsightisticAnalytics,
  MembershipAnalytics,
  ModelConnection,
  RevenueOverview,
  SeoAnalytics,
  StoreAnalytics,
  SystemHealthCheck,
} from '@/types/analytics'

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
  { id: 'a1', name: 'Booking reminder (24h before)',   category: 'booking',    trigger: '24h before booking start', action: 'Send SMS + email',       status: 'active', lastRun: '2026-07-02T09:12:00Z', lastResult: 'ok',    runsLast7d: 87 },
  { id: 'a2', name: 'Waiver reminder',                 category: 'waiver',     trigger: 'Booking made w/o waiver', action: 'Send waiver link email',  status: 'active', lastRun: '2026-07-02T08:44:00Z', lastResult: 'ok',    runsLast7d: 63 },
  { id: 'a3', name: 'Membership renewal 30/7/1-day',   category: 'membership', trigger: 'Days before expiry',      action: 'Send renewal reminder',   status: 'paused', lastRun: null,                    lastResult: null,    runsLast7d:  0 },
  { id: 'a4', name: 'Abandoned inquiry alert',         category: 'sales',      trigger: 'Contact form no reply 48h',action: 'Notify staff channel',   status: 'active', lastRun: '2026-07-01T22:01:00Z', lastResult: 'ok',    runsLast7d: 12 },
  { id: 'a5', name: 'Low-stock alert',                 category: 'staff',      trigger: 'Stock <= threshold',      action: 'Notify manager email',    status: 'active', lastRun: '2026-07-02T06:30:00Z', lastResult: 'ok',    runsLast7d:  9 },
  { id: 'a6', name: 'SEO click-drop alert',            category: 'seo',        trigger: 'Page clicks drop 25%',    action: 'Create SEO task',         status: 'active', lastRun: '2026-07-02T05:14:00Z', lastResult: 'ok',    runsLast7d:  3 },
  { id: 'a7', name: 'Weekly business report',          category: 'reports',    trigger: 'Every Monday 07:00',      action: 'Email + Slack summary',    status: 'active', lastRun: '2026-06-30T14:00:00Z', lastResult: 'ok',    runsLast7d:  1 },
  { id: 'a8', name: 'Post-booking upsell (Ladies)',    category: 'email',      trigger: 'Ladies Tuesday booking',  action: 'Send friend/CCW email',    status: 'active', lastRun: '2026-07-02T02:11:00Z', lastResult: 'ok',    runsLast7d: 17 },
  { id: 'a9', name: 'Agent: churn-risk outreach',      category: 'agents',     trigger: 'Membership churn score',  action: 'Draft outreach email',     status: 'active', lastRun: '2026-07-02T04:00:00Z', lastResult: 'ok',    runsLast7d:  4 },
]

export const agents: Agent[] = [
  { id: 'ag-seo',       name: 'SEO Growth Agent',        department: 'seo',        status: 'active',        model: 'Claude Opus 4.7',   assignedTasks: 6, lastRun: '2026-07-02T05:20:00Z', lastOutput: 'CCW class page has ranking opportunity for "mesa ccw class" — drafted internal link plan.', confidence: 0.86, reviewRequired: false },
  { id: 'ag-analyst',   name: 'Business Analyst Agent',  department: 'analyst',    status: 'active',        model: 'Claude Opus 4.7',   assignedTasks: 4, lastRun: '2026-07-02T04:12:00Z', lastOutput: 'Detected renewal gap (59% vs 72% industry) — proposed enabling automation A3.', confidence: 0.92, reviewRequired: false },
  { id: 'ag-booking',   name: 'Booking Agent',           department: 'booking',    status: 'active',        model: 'GPT-5.5 Turbo',      assignedTasks: 3, lastRun: '2026-07-02T02:44:00Z', lastOutput: 'Ladies Tuesday climbing +22%; suggested capacity boost on 2026-07-14.', confidence: 0.78, reviewRequired: true  },
  { id: 'ag-member',    name: 'Membership Agent',        department: 'membership', status: 'active',        model: 'Claude Opus 4.7',   assignedTasks: 5, lastRun: '2026-07-02T04:01:00Z', lastOutput: 'Drafted retention emails for 34 churn-risk members.', confidence: 0.81, reviewRequired: true  },
  { id: 'ag-support',   name: 'Customer Support Agent',  department: 'support',    status: 'active',        model: 'Gemini Pro 2',      assignedTasks: 2, lastRun: '2026-07-02T07:11:00Z', lastOutput: 'Classified 26 support emails, escalated 3 urgent.', confidence: 0.74, reviewRequired: false },
  { id: 'ag-email',     name: 'Email Manager Agent',     department: 'email',      status: 'active',        model: 'Qwen 2.5 (OpenRouter)', assignedTasks: 7, lastRun: '2026-07-02T06:44:00Z', lastOutput: 'Drafted 8 replies pending approval.', confidence: 0.69, reviewRequired: true },
  { id: 'ag-sales',     name: 'Sales Follow-up Agent',   department: 'sales',      status: 'paused',        model: 'Claude Sonnet 5',    assignedTasks: 0, lastRun: '2026-06-28T14:00:00Z', lastOutput: 'Paused pending owner review of tone.', confidence: 0.61, reviewRequired: true },
  { id: 'ag-inv',       name: 'Inventory Insight Agent', department: 'inventory',  status: 'active',        model: 'Local Ollama Llama 3.1', assignedTasks: 1, lastRun: '2026-07-02T01:00:00Z', lastOutput: 'Flagged 3 slow-moving SKUs and drafted clearance bundle.', confidence: 0.72, reviewRequired: false },
  { id: 'ag-reports',   name: 'Report Agent',            department: 'reports',    status: 'active',        model: 'Claude Opus 4.7',   assignedTasks: 1, lastRun: '2026-06-30T14:00:00Z', lastOutput: 'Weekly report generated — sent to owner.', confidence: 0.94, reviewRequired: false },
  { id: 'ag-auto',      name: 'Automation Manager Agent',department: 'automation', status: 'needs_review',  model: 'GPT-5.5 Turbo',      assignedTasks: 2, lastRun: '2026-07-02T00:20:00Z', lastOutput: 'Recommends enabling A3 (renewal reminders). Requires approval.', confidence: 0.88, reviewRequired: true  },
  { id: 'ag-compliance',name: 'Compliance Workflow Agent',department: 'compliance',status: 'active',        model: 'Claude Opus 4.7',   assignedTasks: 3, lastRun: '2026-07-02T03:12:00Z', lastOutput: 'Verified FFL transfer queue; no anomalies.', confidence: 0.97, reviewRequired: false },
]

export const models: ModelConnection[] = [
  { id: 'm1', provider: 'anthropic',  displayName: 'Claude Opus 4.7',       apiBaseUrl: 'https://api.anthropic.com',  modelName: 'claude-opus-4-7',        contextLimit: 200_000, costLevel: 'high',   useCase: 'Deep business analysis, SEO, agents', status: 'ok',       fallbackId: 'm3', keyMasked: 'sk-ant-****abcd' },
  { id: 'm2', provider: 'openai',     displayName: 'GPT-5.5 Turbo',         apiBaseUrl: 'https://api.openai.com/v1',  modelName: 'gpt-5.5-turbo',          contextLimit: 128_000, costLevel: 'medium', useCase: 'Booking + email drafting',            status: 'ok',       fallbackId: 'm4', keyMasked: 'sk-****9f12' },
  { id: 'm3', provider: 'gemini',     displayName: 'Gemini Pro 2',          apiBaseUrl: 'https://generativelanguage.googleapis.com', modelName: 'gemini-pro-2', contextLimit: 128_000, costLevel: 'medium', useCase: 'Customer support classification',    status: 'ok',       fallbackId: 'm4', keyMasked: 'AIza****kL7q' },
  { id: 'm4', provider: 'openrouter', displayName: 'Qwen 2.5 (OpenRouter)', apiBaseUrl: 'https://openrouter.ai/api/v1', modelName: 'qwen/qwen2.5-72b',     contextLimit:  32_000, costLevel: 'low',    useCase: 'Cheap email drafts + summaries',      status: 'ok',       fallbackId: null, keyMasked: 'sk-or-****771a' },
  { id: 'm5', provider: 'ollama',     displayName: 'Local Llama 3.1',       apiBaseUrl: 'http://ollama.local:11434',  modelName: 'llama3.1:70b',           contextLimit:  32_000, costLevel: 'free',   useCase: 'Private inventory analysis',          status: 'untested', fallbackId: 'm4', keyMasked: '(local)' },
]

export const health: SystemHealthCheck[] = [
  { id: 'h1',  label: 'g2a-business-api plugin',      group: 'plugins',   status: 'ok',    detail: 'v0.1.0 running',                          lastCheckedAt: '2026-07-02T09:20:00Z' },
  { id: 'h2',  label: 'g2a-booking-engine plugin',    group: 'plugins',   status: 'ok',    detail: 'v1.14.6',                                 lastCheckedAt: '2026-07-02T09:20:00Z' },
  { id: 'h3',  label: 'memberistic plugin',           group: 'plugins',   status: 'ok',    detail: 'v1.9.9.4',                                lastCheckedAt: '2026-07-02T09:20:00Z' },
  { id: 'h4',  label: 'GA4 Data API',                 group: 'apis',      status: 'ok',    detail: 'authorized property 4128... (2ms auth)',   lastCheckedAt: '2026-07-02T09:20:00Z' },
  { id: 'h5',  label: 'Google Search Console',        group: 'apis',      status: 'ok',    detail: 'authorized property sc-domain:guns2ammo', lastCheckedAt: '2026-07-02T09:20:00Z' },
  { id: 'h6',  label: 'WooCommerce REST',             group: 'apis',      status: 'ok',    detail: 'read/write consumer key valid',           lastCheckedAt: '2026-07-02T09:20:00Z' },
  { id: 'h7',  label: 'WordPress cron',               group: 'cron',      status: 'warn',  detail: 'DISABLE_WP_CRON off — recommend real cron',lastCheckedAt: '2026-07-02T09:20:00Z' },
  { id: 'h8',  label: 'Booking webhook (Stripe)',     group: 'webhooks',  status: 'ok',    detail: 'last event 12m ago, signature valid',      lastCheckedAt: '2026-07-02T09:20:00Z' },
  { id: 'h9',  label: 'Messageistic email queue',     group: 'messaging', status: 'ok',    detail: '0 stuck, 24 sent last hour',              lastCheckedAt: '2026-07-02T09:20:00Z' },
  { id: 'h10', label: 'Anthropic model connection',   group: 'ai',        status: 'ok',    detail: 'latency 240ms',                            lastCheckedAt: '2026-07-02T09:20:00Z' },
  { id: 'h11', label: 'Local Ollama endpoint',        group: 'ai',        status: 'warn',  detail: 'Not tested since 2026-06-20',              lastCheckedAt: '2026-07-02T09:20:00Z' },
  { id: 'h12', label: 'Security: 2FA on owner accounts',group: 'security',status: 'ok',    detail: 'All 3 owner accounts enrolled',            lastCheckedAt: '2026-07-02T09:20:00Z' },
]
