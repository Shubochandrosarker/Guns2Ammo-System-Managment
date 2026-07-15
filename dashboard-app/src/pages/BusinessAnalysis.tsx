import { PageHeader } from '@/components/ui/PageHeader'
import { StatCard } from '@/components/ui/StatCard'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { ErrorState } from '@/components/ui/ErrorState'
import { useAsync } from '@/lib/hooks'
import { api } from '@/lib/api'
import { formatCents, formatCurrency, formatNumber, formatPercent } from '@/lib/format'
import { BarChart, Bar, ResponsiveContainer, Tooltip, XAxis, YAxis, CartesianGrid, LineChart, Line } from 'recharts'

export function BusinessAnalysis() {
  // revenueOverview + seo are still legacy endpoints (migrate in a later
  // phase); bookings/memberships/woocommerce are the Phase-D canonical
  // envelopes — the old /analytics/bookings|memberships payload shapes and
  // /analytics/store no longer exist on the wire.
  const revenue    = useAsync(() => api.analytics.revenueOverview(), [])
  const bookings   = useAsync(() => api.analytics.bookings(),        [])
  const memberships= useAsync(() => api.analytics.memberships(),     [])
  const store      = useAsync(() => api.analytics.woocommerce(),     [])
  const seo        = useAsync(() => api.analytics.seo(),             [])

  if (revenue.loading) return <Spinner label="Crunching numbers…" />

  const firstError = revenue.error ?? bookings.error ?? memberships.error ?? store.error ?? seo.error
  if (firstError) {
    return (
      <Card title="Business Analysis">
        <ErrorState
          message={firstError}
          onRetry={() => {
            revenue.refresh()
            bookings.refresh()
            memberships.refresh()
            store.refresh()
            seo.refresh()
          }}
        />
      </Card>
    )
  }

  if (!revenue.data || !bookings.data || !memberships.data || !store.data || !seo.data) {
    return <Card title="No data"><div className="text-sm text-ink-500">Try refreshing.</div></Card>
  }

  const r = revenue.data
  const b = bookings.data.data
  const m = memberships.data.data
  const s = store.data.data
  const seoData = seo.data

  const channelBars = [
    { channel: 'Store',      revenue: r.woocommerceRevenue },
    { channel: 'Bookings',   revenue: r.bookingRevenue },
    { channel: 'Membership', revenue: r.membershipRevenue },
  ]

  return (
    <div>
      <PageHeader
        eyebrow="Rolling 30 days"
        title="Business Analysis"
        subtitle="Where the money comes from, where it&apos;s leaking, and where the next lever is."
      />

      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        <StatCard label="Total revenue"    value={r.totalRevenue}       format="currency" deltaPct={r.revenueGrowthPct} />
        <StatCard label="Avg order value"  value={r.averageOrderValue}  format="currency" sublabel="Store orders" />
        <StatCard label="Bookings"         value={formatNumber(b.count)} sublabel={`${formatNumber(b.paid)} paid · ${formatNumber(b.unpaid)} unpaid`} intent="success" />
        <StatCard label="Store orders"     value={formatNumber(s.orders)} sublabel={s.truncated ? 'First 5,000 orders of the period' : undefined} intent="warn" />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-6">
        <Card title="Revenue by channel" subtitle="Store vs Bookings vs Membership" className="lg:col-span-2">
          <div className="h-64">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={channelBars} margin={{ top: 10, right: 10, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-grid)" />
                <XAxis dataKey="channel" tick={{ fontSize: 12, fill: 'var(--chart-axis)' }} />
                <YAxis tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} tickFormatter={v => `$${Math.round(v / 100000)}k`} width={48} />
                <Tooltip formatter={(v: number) => [formatCurrency(v), 'Revenue']} />
                <Bar dataKey="revenue" fill="var(--brand)" radius={[6, 6, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Card title="Growth signals">
          <ul className="space-y-3 text-sm">
            <Signal label="Revenue growth"     value={formatPercent(r.revenueGrowthPct)} good={r.revenueGrowthPct >= 0} />
            <Signal
              label="Membership churn"
              value={m.churn.rate === null ? 'n/a' : formatPercent(m.churn.rate)}
              good={m.churn.rate === null || m.churn.rate < 5}
            />
            <Signal label="Failed renewals"    value={formatNumber(m.failedRenewals)}  good={m.failedRenewals === 0} />
            <Signal label="SEO clicks"         value={formatNumber(seoData.clicks)}    good />
            <Signal
              label="Bookings vs prev. period"
              value={b.trends.deltas.countPct === null ? 'n/a' : formatPercent(b.trends.deltas.countPct)}
              good={b.trends.deltas.countPct === null || b.trends.deltas.countPct >= 0}
            />
          </ul>
        </Card>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">
        <Card title="Category revenue (Woo)" subtitle="Top-selling product categories">
          <div className="h-56">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={s.byCategory} layout="vertical" margin={{ top: 5, right: 10, left: 30, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-grid)" />
                <XAxis type="number" tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} tickFormatter={v => formatCents(v - (v % 100))} />
                <YAxis dataKey="name" type="category" tick={{ fontSize: 12, fill: 'var(--chart-axis)' }} width={100} />
                <Tooltip formatter={(v: number) => [formatCents(v), 'Revenue']} />
                <Bar dataKey="revenueCents" fill="var(--brand)" radius={[0, 4, 4, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Card title="Booking revenue trend" subtitle="Daily booking revenue">
          <div className="h-56">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={b.series} margin={{ top: 10, right: 10, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-grid)" />
                <XAxis dataKey="date" tickFormatter={d => d.slice(5)} tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} />
                <YAxis tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} tickFormatter={v => formatCents(v - (v % 100))} width={54} />
                <Tooltip formatter={(v: number) => [formatCents(v), 'Revenue']} />
                <Line type="monotone" dataKey="revenueCents" stroke="var(--info)" strokeWidth={2.5} dot={false} />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </Card>
      </div>
    </div>
  )
}

function Signal({ label, value, good }: { label: string; value: string; good: boolean }) {
  return (
    <li className="flex items-center justify-between">
      <span className="text-ink-700">{label}</span>
      <span className={good ? 'pill-green' : 'pill-red'}>{value}</span>
    </li>
  )
}
