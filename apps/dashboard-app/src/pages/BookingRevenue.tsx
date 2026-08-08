// Phase D: rewired to the canonical-envelope GET /analytics/bookings?from&to
// (overview booking fields + byType + zero-filled daily series + trends).
// Renders the Phase-C five explicit states: loading / error / unavailable
// (naming the missing plugin) / empty / data — plus the stale-meta banner
// and trend-delta chips.

import { PageHeader } from '@/components/ui/PageHeader'
import { StatCard } from '@/components/ui/StatCard'
import { Card } from '@/components/ui/Card'
import { Skeleton } from '@/components/ui/Skeleton'
import { ErrorState } from '@/components/ui/ErrorState'
import { EmptyState } from '@/components/ui/EmptyState'
import { TrendDelta } from '@/components/ui/TrendDelta'
import { useAsync } from '@/lib/hooks'
import { api } from '@/lib/api'
import { sourceNames } from '@/lib/sources'
import { formatCents, formatDateTimeInZone, formatNumber } from '@/lib/format'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'

export function BookingRevenue() {
  // No explicit range → the server applies its default window (last 30 days).
  const q = useAsync(() => api.analytics.bookings(), [])
  const res = q.data // Enveloped<BookingsAnalyticsData> | null
  const d = res?.data
  const meta = res?.meta

  return (
    <div>
      <PageHeader
        eyebrow={meta ? `Last 30 days · ${meta.timezone}` : 'Range · Class · Events'}
        title="Booking Revenue"
        subtitle="Lanes, classes, Ladies Tuesday, CCW, private events — where bookings convert and where they leak."
      />

      {/* Stale banner (meta.freshness) */}
      {meta?.freshness.status === 'stale' && (
        <div
          role="status"
          className="mb-4 text-sm rounded-lg px-3 py-2 flex flex-wrap items-center gap-2"
          style={{ background: 'var(--warn-soft)', color: 'var(--warn)' }}
        >
          <span className="pill-amber">Stale</span>
          <span>
            These booking figures may be out of date
            {meta.freshness.lastUpdatedAt
              ? ` — last updated ${formatDateTimeInZone(meta.freshness.lastUpdatedAt, meta.timezone)}.`
              : '.'}
          </span>
        </div>
      )}

      {q.loading ? (
        <LoadingSkeleton />
      ) : q.error || !res || !d ? (
        <ErrorState
          message={`Couldn't load booking analytics: ${q.error ?? 'Unknown error'}`}
          onRetry={q.refresh}
        />
      ) : meta?.freshness.status === 'unavailable' ? (
        <Card title="Bookings unavailable">
          <div className="text-sm text-ink-600 bg-ink-50 border border-ink-100 rounded-lg p-3">
            <span className="pill-gray">Unavailable</span>
            <div className="mt-2 text-xs text-ink-500">
              {sourceNames(meta?.source ?? [])} is missing or inactive, so there is no booking
              data to report. Activate it in the WordPress admin to populate this page.
            </div>
          </div>
        </Card>
      ) : d.count === 0 && d.revenueCents === 0 && d.byType.length === 0 ? (
        <Card>
          <EmptyState
            title="No bookings in this period"
            hint="The booking engine is active but recorded nothing in the selected window."
          />
        </Card>
      ) : (
        <>
          {/* Trend chips — count + revenue vs the previous comparable window */}
          <div className="mb-4 flex flex-wrap items-center gap-2 text-sm">
            <TrendDelta label="Bookings" pct={d.trends.deltas.countPct} />
            <TrendDelta label="Revenue" pct={d.trends.deltas.revenuePct} />
            <span className="text-xs text-ink-500">
              vs previous period: {formatNumber(d.trends.previous.count)} bookings ·{' '}
              {formatCents(d.trends.previous.revenueCents)} revenue ·{' '}
              {formatCents(d.trends.previous.netCents)} net
            </span>
          </div>

          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            <StatCard label="Bookings"        value={formatNumber(d.count)} />
            <StatCard label="Booking revenue" value={formatCents(d.revenueCents)} intent="success" />
            <StatCard label="Paid"            value={formatNumber(d.paid)} />
            <StatCard label="Unpaid"          value={formatNumber(d.unpaid)} intent={d.unpaid > 0 ? 'warn' : 'default'} />
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-6">
            <Card title="Daily bookings & revenue" subtitle="Zero-filled — quiet days render as zero" className="lg:col-span-2">
              <div className="h-64">
                <ResponsiveContainer width="100%" height="100%">
                  <LineChart data={d.series} margin={{ top: 10, right: 10, left: 0, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-grid)" />
                    <XAxis dataKey="date" tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} tickFormatter={dt => dt.slice(5)} />
                    <YAxis yAxisId="count" tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} width={36} allowDecimals={false} />
                    <YAxis
                      yAxisId="cents"
                      orientation="right"
                      tick={{ fontSize: 11, fill: 'var(--chart-axis)' }}
                      tickFormatter={v => formatCents(v - (v % 100))}
                      width={58}
                    />
                    <Tooltip
                      contentStyle={{ fontSize: 12 }}
                      formatter={(v: number, name: string) =>
                        name === 'Revenue' ? [formatCents(v), name] : [formatNumber(v), name]
                      }
                    />
                    <Line yAxisId="count" type="monotone" dataKey="count"        name="Bookings" stroke="var(--info)"  strokeWidth={2} dot={false} />
                    <Line yAxisId="cents" type="monotone" dataKey="revenueCents" name="Revenue"  stroke="var(--brand)" strokeWidth={2.5} dot={false} />
                  </LineChart>
                </ResponsiveContainer>
              </div>
            </Card>

            <Card title="Paid vs Unpaid">
              <div className="space-y-3">
                <RatioBar label="Paid"   value={d.paid}   total={d.paid + d.unpaid} color="bg-emerald-500" />
                <RatioBar label="Unpaid" value={d.unpaid} total={d.paid + d.unpaid} color="bg-amber-500" />
              </div>
              <div className="mt-6 space-y-2 text-sm">
                <div className="flex justify-between"><span>Total bookings</span><span className="font-semibold">{formatNumber(d.count)}</span></div>
                <div className="flex justify-between"><span>Revenue</span><span className="font-semibold">{formatCents(d.revenueCents)}</span></div>
              </div>
            </Card>
          </div>

          <Card title="Bookings by type" className="mt-6">
            {d.byType.length === 0 ? (
              <EmptyState title="No booking types recorded" hint="Nothing was booked in this window." />
            ) : (
              <>
                <div className="h-56">
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={d.byType} margin={{ top: 10, right: 10, left: 0, bottom: 0 }}>
                      <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-grid)" />
                      <XAxis dataKey="name" tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} />
                      <YAxis
                        tick={{ fontSize: 11, fill: 'var(--chart-axis)' }}
                        tickFormatter={v => formatCents(v - (v % 100))}
                        width={58}
                      />
                      <Tooltip
                        contentStyle={{ fontSize: 12 }}
                        formatter={(v: number) => [formatCents(v), 'Revenue']}
                      />
                      <Bar dataKey="revenueCents" name="Revenue" fill="var(--brand)" radius={[4, 4, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
                <div className="overflow-x-auto -mx-1 px-1 mt-4"><table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-xs uppercase tracking-wide text-ink-500 border-b border-ink-100">
                      <th className="py-2">Type</th>
                      <th className="py-2 text-right">Bookings</th>
                      <th className="py-2 text-right">Revenue</th>
                      <th className="py-2 text-right">Avg</th>
                    </tr>
                  </thead>
                  <tbody>
                    {d.byType.map(row => (
                      <tr key={row.typeId} className="border-b border-ink-100 last:border-0">
                        <td className="py-2 font-medium text-ink-800">{row.name}</td>
                        <td className="py-2 text-right">{formatNumber(row.count)}</td>
                        <td className="py-2 text-right">{formatCents(row.revenueCents)}</td>
                        <td className="py-2 text-right text-ink-600">
                          {row.count ? formatCents(Math.round(row.revenueCents / row.count)) : '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table></div>
              </>
            )}
          </Card>
        </>
      )}
    </div>
  )
}

function LoadingSkeleton() {
  return (
    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4" aria-busy="true">
      {[0, 1, 2, 3].map(i => (
        <div key={i} className="card">
          <div className="card-body space-y-3">
            <Skeleton className="h-3 w-24" />
            <Skeleton className="h-7 w-28" />
          </div>
        </div>
      ))}
    </div>
  )
}

function RatioBar({ label, value, total, color }: { label: string; value: number; total: number; color: string }) {
  const pct = total ? Math.round((value / total) * 100) : 0
  return (
    <div>
      <div className="flex items-center justify-between text-xs text-ink-500 mb-1">
        <span>{label}</span>
        <span className="font-semibold text-ink-800">{formatNumber(value)} ({pct}%)</span>
      </div>
      <div className="h-2 rounded-full bg-ink-100 overflow-hidden">
        <div className={color + ' h-full'} style={{ width: pct + '%' }} />
      </div>
    </div>
  )
}
