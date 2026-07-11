import { Link } from 'react-router-dom'
import { LineChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis, CartesianGrid } from 'recharts'
import { PageHeader } from '@/components/ui/PageHeader'
import { StatCard } from '@/components/ui/StatCard'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { api } from '@/lib/api'
import { useAsync } from '@/lib/hooks'
import { formatCurrency, formatNumber } from '@/lib/format'

export function DashboardHome() {
  const revenue    = useAsync(() => api.analytics.revenueOverview(), [])
  const bookings   = useAsync(() => api.analytics.bookings(),        [])
  const memberships= useAsync(() => api.analytics.memberships(),     [])
  const seo        = useAsync(() => api.analytics.seo(),             [])
  const insights   = useAsync(() => api.insights.list(),             [])
  const health     = useAsync(() => api.health.checks(),             [])
  const integrations = useAsync(() => api.system.integrations(),     [])

  if (revenue.loading || bookings.loading || memberships.loading || seo.loading) {
    return <Spinner label="Loading business snapshot…" />
  }
  if (revenue.error || !revenue.data || !bookings.data || !memberships.data || !seo.data) {
    return (
      <Card title="Failed to load dashboard">
        <div className="text-rose-600 text-sm">{revenue.error || 'Unknown error'}</div>
      </Card>
    )
  }

  const r  = revenue.data
  const b  = bookings.data
  const m  = memberships.data
  const s  = seo.data
  const criticalHealth = (health.data ?? []).filter(c => c.status !== 'ok')
  const highInsights   = (insights.data ?? []).filter(i => i.priority === 'high').slice(0, 3)

  return (
    <div>
      <PageHeader
        eyebrow="Last 30 days"
        title="Dashboard Home"
        subtitle="Central operating view for revenue, bookings, memberships, growth signals, and AI-recommended actions."
      />

      <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        <StatCard label="Total revenue"      value={r.totalRevenue}       format="currency" deltaPct={r.revenueGrowthPct} sublabel={`Best source: ${r.bestRevenueSource}`} />
        <StatCard label="Store revenue"      value={r.woocommerceRevenue} format="currency" sublabel={`${formatNumber(2)}× the booking channel`} />
        <StatCard label="Booking revenue"    value={r.bookingRevenue}     format="currency" sublabel={`Top: ${b.topBookingType}`} intent="success" />
        <StatCard label="Membership MRR"     value={m.mrr}                format="currency" sublabel={`${m.active} active`} intent="warn" />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-6">
        <Card
          title="Revenue trend"
          subtitle="All channels combined"
          className="lg:col-span-2"
          actions={<Link to="/business-analysis" className="btn-ghost text-xs">Open Business Analysis →</Link>}
        >
          <div className="h-64">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={r.series} margin={{ top: 10, right: 10, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-grid)" />
                <XAxis dataKey="date" tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} tickFormatter={d => d.slice(5)} />
                <YAxis tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} tickFormatter={v => `$${Math.round(v / 100)}`} width={44} />
                <Tooltip
                  contentStyle={{ fontSize: 12 }}
                  labelFormatter={(d: string) => d}
                  formatter={(v: number) => [formatCurrency(v), 'Revenue']}
                />
                <Line type="monotone" dataKey="value" stroke="var(--brand)" strokeWidth={2.5} dot={false} />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Card
          title="System health"
          subtitle={`${(health.data ?? []).length} checks`}
          actions={<Link to="/system-health" className="btn-ghost text-xs">Details →</Link>}
        >
          {health.loading ? <Spinner label="Checking…" /> : criticalHealth.length === 0 ? (
            <div className="text-sm text-emerald-700 bg-emerald-50 rounded-lg p-3">
              All systems healthy.
            </div>
          ) : (
            <ul className="space-y-2">
              {criticalHealth.slice(0, 5).map(c => (
                <li key={c.id} className="flex items-start gap-3">
                  <span className={c.status === 'error' ? 'pill-red' : 'pill-amber'}>
                    {c.status}
                  </span>
                  <div className="min-w-0">
                    <div className="text-sm font-medium text-ink-800 truncate">{c.label}</div>
                    <div className="text-xs text-ink-500 truncate">{c.detail}</div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-6">
        <Card title="SEO snapshot" subtitle="Search Console — last 30d">
          {integrations.data && !integrations.data.gscConfigured ? (
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
          ) : (
            <>
              <div className="grid grid-cols-2 gap-3">
                <MiniStat label="Clicks"      value={formatNumber(s.clicks)} />
                <MiniStat label="Impressions" value={formatNumber(s.impressions)} />
                <MiniStat label="CTR"         value={`${s.ctr.toFixed(1)}%`} />
                <MiniStat label="Avg pos."    value={s.position.toFixed(1)} />
              </div>
              <div className="mt-4">
                <div className="text-xs font-medium text-ink-500 uppercase tracking-wide mb-1">Top pages</div>
                <ul className="text-sm space-y-1">
                  {s.topPages.slice(0, 3).map(p => (
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

        <Card title="Booking pulse" subtitle={`${b.paidVsUnpaid.paid + b.paidVsUnpaid.unpaid} bookings`}>
          <ul className="space-y-2">
            {b.bookingsByType.map(t => (
              <li key={t.type} className="flex items-center justify-between">
                <span className="text-sm text-ink-700">{t.type}</span>
                <span className="text-sm font-medium text-ink-800">{formatNumber(t.count)} <span className="text-ink-400">·</span> {formatCurrency(t.revenue)}</span>
              </li>
            ))}
          </ul>
          <div className="mt-4 grid grid-cols-3 gap-2 text-xs">
            <StatChip label="Paid"   value={formatNumber(b.paidVsUnpaid.paid)}   intent="ok" />
            <StatChip label="Unpaid" value={formatNumber(b.paidVsUnpaid.unpaid)} intent="warn" />
            <StatChip label="No-show %" value={`${b.noShowRate.toFixed(1)}%`}    intent="warn" />
          </div>
        </Card>

        <Card
          title="AI highlights"
          subtitle="Top-priority insights"
          actions={<Link to="/ai-insights" className="btn-ghost text-xs">All insights →</Link>}
        >
          {highInsights.length === 0 ? (
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

function MiniStat({ label, value }: { label: string; value: string }) {
  return (
    <div className="bg-ink-50 rounded-lg px-3 py-2">
      <div className="text-[11px] uppercase tracking-wide text-ink-500">{label}</div>
      <div className="text-base font-semibold text-ink-800">{value}</div>
    </div>
  )
}

function StatChip({ label, value, intent }: { label: string; value: string; intent: 'ok' | 'warn' | 'bad' }) {
  const cls =
    intent === 'ok' ? 'pill-green' : intent === 'warn' ? 'pill-amber' : 'pill-red'
  return (
    <div className="flex items-center gap-2">
      <span className={cls}>{value}</span>
      <span className="text-ink-500">{label}</span>
    </div>
  )
}
