import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { LineChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis, CartesianGrid } from 'recharts'
import { PageHeader } from '@/components/ui/PageHeader'
import { StatCard } from '@/components/ui/StatCard'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { Skeleton } from '@/components/ui/Skeleton'
import { ErrorState } from '@/components/ui/ErrorState'
import { api } from '@/lib/api'
import { useAsync } from '@/lib/hooks'
import { formatCents, formatDateTimeInZone, formatNumber } from '@/lib/format'
import type { ModuleStatus, OverviewAlert, OverviewModuleKey, SystemHealthData } from '@/types/api'

// Text label + accessible pill per alert severity — the label word (not just
// the color) always renders, so severity is never communicated color-only.
const ALERT_SEVERITY: Record<string, { label: string; pill: string }> = {
  critical: { label: 'Critical', pill: 'pill-red' },
  error:    { label: 'Error',    pill: 'pill-red' },
  warning:  { label: 'Warning',  pill: 'pill-amber' },
  info:     { label: 'Info',     pill: 'pill-blue' },
}

// Human names for the plugin slugs modules[].source carries, so the
// UNAVAILABLE state can say which plugin is missing/inactive.
const SOURCE_LABEL: Record<string, string> = {
  'g2a-booking-engine': 'G2A Booking Engine',
  memberistic: 'Memberistic',
  woocommerce: 'WooCommerce',
  'g2a-waivers': 'G2A Waivers',
  formistic: 'Formistic',
  messageistic: 'Messageistic',
}

const sourceNames = (source: string[]): string =>
  source.length > 0 ? source.map(s => SOURCE_LABEL[s] ?? s).join(', ') : 'its source plugin'

export function DashboardHome() {
  // NEW canonical endpoint: revenue + per-module summaries + alerts + module
  // availability all come from GET /dashboard/overview (no explicit range →
  // the server's default last-30-days window).
  const overview = useAsync(() => api.dashboard.overview(), [])
  // NEW canonical endpoint for the health summary card.
  const health = useAsync(() => api.system.health(), [])
  // NOT covered by /dashboard/overview (no daily series in its contract):
  // the trend chart keeps its legacy source until a later phase migrates it.
  const trendQ = useAsync(() => api.analytics.revenueOverview(), [])
  // Other modules' plumbing — untouched, they migrate in later phases.
  const seo = useAsync(() => api.analytics.seo(), [])
  const insights = useAsync(() => api.insights.list(), [])
  const integrations = useAsync(() => api.system.integrations(), [])

  const ov = overview.data // Enveloped<DashboardOverviewData> | null
  const tz = ov?.meta.timezone
  const highInsights = (insights.data ?? []).filter(i => i.priority === 'high').slice(0, 3)

  const moduleState = (key: OverviewModuleKey): ModuleStatus | undefined => ov?.data.modules?.[key]

  return (
    <div>
      <PageHeader
        eyebrow={tz ? `Last 30 days · ${tz}` : 'Last 30 days'}
        title="Dashboard Home"
        subtitle="Central operating view for revenue, bookings, memberships, growth signals, and AI-recommended actions."
      />

      {/* Stale-data banner (meta.freshness) */}
      {ov?.meta.freshness.status === 'stale' && (
        <div
          role="status"
          className="mb-4 text-sm rounded-lg px-3 py-2 flex flex-wrap items-center gap-2"
          style={{ background: 'var(--warn-soft)', color: 'var(--warn)' }}
        >
          <span className="pill-amber">Stale</span>
          <span>
            These figures may be out of date
            {ov.meta.freshness.lastUpdatedAt
              ? ` — last updated ${formatDateTimeInZone(ov.meta.freshness.lastUpdatedAt, ov.meta.timezone)}.`
              : '.'}
          </span>
        </div>
      )}

      {/* Alerts strip */}
      {ov && ov.data.alerts.length > 0 && (
        <ul className="mb-4 space-y-2" aria-label="Business alerts">
          {ov.data.alerts.map(alert => (
            <AlertRow key={alert.code} alert={alert} />
          ))}
        </ul>
      )}

      {/* Revenue stat row — /dashboard/overview revenue block, all cents via formatCents */}
      {overview.loading ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4" aria-busy="true">
          {[0, 1, 2, 3].map(i => (
            <div key={i} className="card">
              <div className="card-body space-y-3">
                <Skeleton className="h-3 w-24" />
                <Skeleton className="h-7 w-32" />
                <Skeleton className="h-3 w-20" />
              </div>
            </div>
          ))}
        </div>
      ) : overview.error || !ov ? (
        <ErrorState
          message={`Couldn't load the business overview: ${overview.error ?? 'Unknown error'}`}
          onRetry={overview.refresh}
        />
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
          <StatCard label="Total revenue"      value={formatCents(ov.data.revenue.totalCents)} />
          <StatCard label="Store revenue"      value={formatCents(ov.data.revenue.wooCents)} sublabel="WooCommerce channel" />
          <StatCard label="Booking revenue"    value={formatCents(ov.data.revenue.bookingsCents)} intent="success" />
          <StatCard label="Membership revenue" value={formatCents(ov.data.revenue.membershipsCents)} intent="warn" sublabel={ov.data.modules.memberships?.available ? `${formatNumber(ov.data.memberships.active)} active members` : undefined} />
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-6">
        <Card
          title="Revenue trend"
          subtitle="All channels combined"
          className="lg:col-span-2"
          actions={<Link to="/business-analysis" className="btn-ghost text-xs">Open Business Analysis →</Link>}
        >
          {trendQ.loading ? (
            <Spinner label="Loading trend…" />
          ) : trendQ.error || !trendQ.data ? (
            <ErrorState message={trendQ.error ?? 'No trend data'} onRetry={trendQ.refresh} />
          ) : (
            <div className="h-64">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={trendQ.data.series} margin={{ top: 10, right: 10, left: 0, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-grid)" />
                  <XAxis dataKey="date" tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} tickFormatter={d => d.slice(5)} />
                  <YAxis tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} tickFormatter={v => formatCents(v - (v % 100))} width={54} />
                  <Tooltip
                    contentStyle={{ fontSize: 12 }}
                    labelFormatter={(d: string) => d}
                    formatter={(v: number) => [formatCents(v), 'Revenue']}
                  />
                  <Line type="monotone" dataKey="value" stroke="var(--brand)" strokeWidth={2.5} dot={false} />
                </LineChart>
              </ResponsiveContainer>
            </div>
          )}
        </Card>

        <Card
          title="System health"
          subtitle="Plugins, sessions, audit, integrations"
          actions={<Link to="/system-health" className="btn-ghost text-xs">Details →</Link>}
        >
          {health.loading ? (
            <div className="space-y-2" aria-busy="true">
              <Skeleton className="h-4 w-full" />
              <Skeleton className="h-4 w-4/5" />
              <Skeleton className="h-4 w-3/5" />
            </div>
          ) : health.error || !health.data ? (
            <ErrorState message={health.error ?? 'Unknown error'} onRetry={health.refresh} />
          ) : (
            <HealthSummary
              issues={healthIssues(health.data.data)}
            />
          )}
        </Card>
      </div>

      {/* Per-module cards — five explicit states each */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-6">
        <ModuleCard
          title="Bookings"
          loading={overview.loading}
          error={overview.error}
          onRetry={overview.refresh}
          module={moduleState('bookings')}
          empty={!!ov && ov.data.bookings.count === 0 && ov.data.bookings.revenueCents === 0}
          timezone={tz}
        >
          {ov && (
            <>
              <BigMetric value={formatNumber(ov.data.bookings.count)} label="bookings" />
              <MetricRow label="Revenue" value={formatCents(ov.data.bookings.revenueCents)} />
              <MetricRow label="Paid" value={formatNumber(ov.data.bookings.paid)} />
              <MetricRow label="Unpaid" value={formatNumber(ov.data.bookings.unpaid)} />
            </>
          )}
        </ModuleCard>

        <ModuleCard
          title="Memberships"
          loading={overview.loading}
          error={overview.error}
          onRetry={overview.refresh}
          module={moduleState('memberships')}
          empty={
            !!ov &&
            ov.data.memberships.active === 0 &&
            ov.data.memberships.new === 0 &&
            ov.data.memberships.expired === 0 &&
            ov.data.memberships.mrrCents === 0
          }
          timezone={tz}
        >
          {ov && (
            <>
              <BigMetric value={formatNumber(ov.data.memberships.active)} label="active members" />
              <MetricRow label="MRR" value={formatCents(ov.data.memberships.mrrCents)} />
              <MetricRow label="New this period" value={formatNumber(ov.data.memberships.new)} />
              <MetricRow label="Expired" value={formatNumber(ov.data.memberships.expired)} />
            </>
          )}
        </ModuleCard>

        <ModuleCard
          title="WooCommerce store"
          loading={overview.loading}
          error={overview.error}
          onRetry={overview.refresh}
          module={moduleState('woocommerce')}
          empty={!!ov && ov.data.woocommerce.orders === 0 && ov.data.woocommerce.revenueCents === 0}
          timezone={tz}
        >
          {ov && (
            <>
              <BigMetric value={formatNumber(ov.data.woocommerce.orders)} label="orders" />
              <MetricRow label="Revenue" value={formatCents(ov.data.woocommerce.revenueCents)} />
            </>
          )}
        </ModuleCard>

        <ModuleCard
          title="Waivers"
          loading={overview.loading}
          error={overview.error}
          onRetry={overview.refresh}
          module={moduleState('waivers')}
          empty={!!ov && ov.data.waivers.signed === 0 && ov.data.waivers.pending === 0}
          timezone={tz}
        >
          {ov && (
            <>
              <BigMetric value={formatNumber(ov.data.waivers.signed)} label="signed" />
              <MetricRow label="Pending" value={formatNumber(ov.data.waivers.pending)} />
            </>
          )}
        </ModuleCard>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">
        <Card title="SEO snapshot" subtitle="Search Console — last 30d">
          {seo.loading ? (
            <Spinner label="Loading SEO…" />
          ) : integrations.data && !integrations.data.gscConfigured ? (
            <div className="text-sm text-ink-600 bg-ink-50 border border-ink-100 rounded-lg p-3">
              <div className="font-medium text-ink-800">Search Console not configured</div>
              <div className="mt-1 text-xs text-ink-500">
                These numbers stay at zero until a Google service-account key
                and property are connected in the WordPress admin.
              </div>
              <Link to="/system-health" className="btn-ghost text-xs mt-2 inline-block">
                View integration status →
              </Link>
            </div>
          ) : seo.error || !seo.data ? (
            <ErrorState message={seo.error ?? 'No SEO data'} onRetry={seo.refresh} />
          ) : (
            <>
              <div className="grid grid-cols-2 gap-3">
                <MiniStat label="Clicks"      value={formatNumber(seo.data.clicks)} />
                <MiniStat label="Impressions" value={formatNumber(seo.data.impressions)} />
                <MiniStat label="CTR"         value={`${seo.data.ctr.toFixed(1)}%`} />
                <MiniStat label="Avg pos."    value={seo.data.position.toFixed(1)} />
              </div>
              <div className="mt-4">
                <div className="text-xs font-medium text-ink-500 uppercase tracking-wide mb-1">Top pages</div>
                <ul className="text-sm space-y-1">
                  {seo.data.topPages.slice(0, 3).map(p => (
                    <li key={p.url} className="flex justify-between gap-3">
                      <span className="truncate text-ink-700">{p.url}</span>
                      <span className="text-emerald-600 text-xs shrink-0">+{p.deltaPct.toFixed(1)}%</span>
                    </li>
                  ))}
                </ul>
              </div>
            </>
          )}
        </Card>

        <Card
          title="AI highlights"
          subtitle="Top-priority insights"
          actions={<Link to="/ai-insights" className="btn-ghost text-xs">All insights →</Link>}
        >
          {insights.loading ? (
            <Spinner label="Loading insights…" />
          ) : highInsights.length === 0 ? (
            <div className="text-sm text-ink-500">No high-priority insights right now.</div>
          ) : (
            <ul className="space-y-3">
              {highInsights.map(i => (
                <li key={i.id} className="rounded-lg border border-ink-100 p-3">
                  <div className="flex items-center justify-between">
                    <span className="pill-red">high</span>
                    <span className="text-xs text-ink-500 uppercase tracking-wide">{i.category}</span>
                  </div>
                  <div className="mt-2 text-sm font-medium text-ink-800">{i.title}</div>
                  <div className="mt-1 text-xs text-ink-500">{i.expectedImpact}</div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </div>
  )
}

// ---------------------------------------------------------------------------
// Alerts
// ---------------------------------------------------------------------------

function AlertRow({ alert }: { alert: OverviewAlert }) {
  const sev = ALERT_SEVERITY[alert.severity] ?? ALERT_SEVERITY.info
  return (
    <li
      className="card px-3 py-2 flex items-start gap-3 text-sm"
      role={alert.severity === 'critical' ? 'alert' : undefined}
    >
      <span className={`${sev.pill} shrink-0 mt-0.5`}>{sev.label}</span>
      <span style={{ color: 'var(--text-primary)' }}>{alert.message}</span>
    </li>
  )
}

// ---------------------------------------------------------------------------
// Per-module card — the five explicit states:
//   1. loading   → skeleton
//   2. error     → request failed, retry button
//   3. unavailable (module.available === false) → say which plugin is
//      missing/inactive; NEVER render the module's zeros as data
//   4. empty     → available but zero rows: "No data in this period"
//   5. data      → children
// ---------------------------------------------------------------------------

function ModuleCard({
  title,
  loading,
  error,
  onRetry,
  module,
  empty,
  timezone,
  children,
}: {
  title: string
  loading: boolean
  error: string | null
  onRetry: () => void
  module: ModuleStatus | undefined
  empty: boolean
  timezone: string | undefined
  children: ReactNode
}) {
  const stale = module?.available && module.freshness.status === 'stale'

  return (
    <Card
      title={title}
      actions={stale ? <span className="pill-amber" title={
        module?.freshness.lastUpdatedAt && timezone
          ? `Last updated ${formatDateTimeInZone(module.freshness.lastUpdatedAt, timezone)}`
          : undefined
      }>Stale</span> : undefined}
    >
      {loading ? (
        <div className="space-y-3" aria-busy="true">
          <Skeleton className="h-7 w-24" />
          <Skeleton className="h-4 w-full" />
          <Skeleton className="h-4 w-4/5" />
        </div>
      ) : error ? (
        <ErrorState message={error} onRetry={onRetry} />
      ) : module && !module.available ? (
        <div className="text-sm text-ink-600 bg-ink-50 border border-ink-100 rounded-lg p-3">
          <div className="flex items-center gap-2">
            <span className="pill-gray">Unavailable</span>
          </div>
          <div className="mt-2 text-xs text-ink-500">
            {sourceNames(module.source)} is missing or inactive, so this module has no
            data to report. Activate it in the WordPress admin to populate this card.
          </div>
        </div>
      ) : empty ? (
        <div className="text-sm text-ink-500 bg-ink-50 rounded-lg p-3">No data in this period.</div>
      ) : (
        <div className="space-y-1.5">{children}</div>
      )}
    </Card>
  )
}

// ---------------------------------------------------------------------------
// System health summary (home card)
// ---------------------------------------------------------------------------

interface HealthIssue {
  key: string
  label: string
  detail: string
  level: 'warn' | 'error'
}

function healthIssues(h: SystemHealthData): HealthIssue[] {
  const issues: HealthIssue[] = []
  for (const p of h.plugins.filter(p => !p.active)) {
    issues.push({ key: `plugin-${p.slug}`, label: p.name, detail: `Plugin inactive (v${p.version})`, level: 'warn' })
  }
  if (!h.sessions.table) {
    issues.push({ key: 'sessions-table', label: 'Sessions table', detail: 'Dashboard sessions table is missing', level: 'error' })
  }
  if (h.audit.recentFailures24h > 0) {
    issues.push({
      key: 'audit-failures',
      label: 'Audit log',
      detail: `${formatNumber(h.audit.recentFailures24h)} failure${h.audit.recentFailures24h === 1 ? '' : 's'} in the last 24h`,
      level: 'warn',
    })
  }
  if (!h.integrations.stripe.configured) {
    issues.push({ key: 'stripe', label: 'Stripe', detail: 'Not configured', level: 'warn' })
  }
  if (!h.integrations.woo.active) {
    issues.push({ key: 'woo', label: 'WooCommerce', detail: 'Not active', level: 'warn' })
  }
  return issues
}

function HealthSummary({ issues }: { issues: HealthIssue[] }) {
  if (issues.length === 0) {
    return (
      <div className="text-sm rounded-lg p-3" style={{ background: 'var(--success-soft)', color: 'var(--success)' }}>
        All systems healthy.
      </div>
    )
  }
  return (
    <ul className="space-y-2">
      {issues.slice(0, 5).map(i => (
        <li key={i.key} className="flex items-start gap-3">
          <span className={i.level === 'error' ? 'pill-red' : 'pill-amber'}>
            {i.level === 'error' ? 'error' : 'warn'}
          </span>
          <div className="min-w-0">
            <div className="text-sm font-medium text-ink-800 truncate">{i.label}</div>
            <div className="text-xs text-ink-500 truncate">{i.detail}</div>
          </div>
        </li>
      ))}
    </ul>
  )
}

// ---------------------------------------------------------------------------
// Small display atoms (unchanged design language)
// ---------------------------------------------------------------------------

function BigMetric({ value, label }: { value: string; label: string }) {
  return (
    <div className="flex items-baseline gap-2 mb-1">
      <span className="text-xl sm:text-2xl font-semibold" style={{ color: 'var(--text-primary)' }}>{value}</span>
      <span className="text-xs text-ink-500">{label}</span>
    </div>
  )
}

function MetricRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between text-sm">
      <span className="text-ink-500">{label}</span>
      <span className="font-medium text-ink-800">{value}</span>
    </div>
  )
}

function MiniStat({ label, value }: { label: string; value: string }) {
  return (
    <div className="bg-ink-50 rounded-lg px-3 py-2">
      <div className="text-[11px] uppercase tracking-wide text-ink-500">{label}</div>
      <div className="text-base font-semibold text-ink-800">{value}</div>
    </div>
  )
}
