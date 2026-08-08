// Phase D: rewired to the canonical-envelope GET /analytics/woocommerce?from&to
// (aggregates + topProducts/byCategory (≤10 each) + zero-filled daily series +
// trends + `truncated`). Five explicit states + stale banner + trend chips +
// a visible warning whenever the backend capped the period at 5,000 orders.

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

export function WooStoreAnalytics() {
  // No explicit range → the server applies its default window (last 30 days).
  const q = useAsync(() => api.analytics.woocommerce(), [])
  const res = q.data // Enveloped<WooAnalyticsData> | null
  const d = res?.data
  const meta = res?.meta

  return (
    <div>
      <PageHeader
        eyebrow={meta ? `Last 30 days · ${meta.timezone}` : 'WooCommerce'}
        title="Woo Store Analytics"
        subtitle="Orders, revenue, best-selling products, and category performance from the WooCommerce store."
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
            These store figures may be out of date
            {meta.freshness.lastUpdatedAt
              ? ` — last updated ${formatDateTimeInZone(meta.freshness.lastUpdatedAt, meta.timezone)}.`
              : '.'}
          </span>
        </div>
      )}

      {/* Truncation warning — the backend capped the period at 5,000 orders */}
      {d?.truncated && (
        <div
          role="status"
          className="mb-4 text-sm rounded-lg px-3 py-2 flex flex-wrap items-center gap-2"
          style={{ background: 'var(--warn-soft)', color: 'var(--warn)' }}
        >
          <span className="pill-amber">Truncated</span>
          <span>
            Large period — showing the first 5,000 orders of the period. Totals and
            breakdowns below may undercount; narrow the date range for exact figures.
          </span>
        </div>
      )}

      {q.loading ? (
        <LoadingSkeleton />
      ) : q.error || !res || !d ? (
        <ErrorState
          message={`Couldn't load store analytics: ${q.error ?? 'Unknown error'}`}
          onRetry={q.refresh}
        />
      ) : meta?.freshness.status === 'unavailable' ? (
        <Card title="Store unavailable">
          <div className="text-sm text-ink-600 bg-ink-50 border border-ink-100 rounded-lg p-3">
            <span className="pill-gray">Unavailable</span>
            <div className="mt-2 text-xs text-ink-500">
              {sourceNames(meta?.source ?? [])} is missing or inactive, so there is no
              store data to report. Activate it in the WordPress admin to populate this page.
            </div>
          </div>
        </Card>
      ) : d.orders === 0 && d.revenueCents === 0 ? (
        <Card>
          <EmptyState
            title="No orders in this period"
            hint="WooCommerce is active but recorded no orders in the selected window."
          />
        </Card>
      ) : (
        <>
          {/* Trend chips — orders + revenue vs the previous comparable window */}
          <div className="mb-4 flex flex-wrap items-center gap-2 text-sm">
            <TrendDelta label="Orders" pct={d.trends.deltas.countPct} />
            <TrendDelta label="Revenue" pct={d.trends.deltas.revenuePct} />
            <span className="text-xs text-ink-500">
              vs previous period: {formatNumber(d.trends.previous.count)} orders ·{' '}
              {formatCents(d.trends.previous.revenueCents)} revenue ·{' '}
              {formatCents(d.trends.previous.netCents)} net
            </span>
          </div>

          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            <StatCard label="Orders"        value={formatNumber(d.orders)} />
            <StatCard label="Store revenue" value={formatCents(d.revenueCents)} intent="success" />
            <StatCard
              label="Avg order value"
              value={d.orders ? formatCents(Math.round(d.revenueCents / d.orders)) : '—'}
              sublabel="Derived: revenue ÷ orders"
            />
            <StatCard
              label="Period coverage"
              value={d.truncated ? 'Partial' : 'Complete'}
              intent={d.truncated ? 'warn' : 'success'}
              sublabel={d.truncated ? 'First 5,000 orders only' : 'All orders included'}
            />
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-6">
            <Card title="Daily orders & revenue" subtitle="Zero-filled — quiet days render as zero" className="lg:col-span-2">
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
                    <Line yAxisId="count" type="monotone" dataKey="count"        name="Orders"  stroke="var(--info)"  strokeWidth={2} dot={false} />
                    <Line yAxisId="cents" type="monotone" dataKey="revenueCents" name="Revenue" stroke="var(--brand)" strokeWidth={2.5} dot={false} />
                  </LineChart>
                </ResponsiveContainer>
              </div>
            </Card>

            <Card title="Category revenue" subtitle="Top categories (max 10)">
              {d.byCategory.length === 0 ? (
                <EmptyState title="No category data" hint="No categorized sales in this window." />
              ) : (
                <div className="h-64">
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={d.byCategory} layout="vertical" margin={{ top: 5, right: 10, left: 30, bottom: 0 }}>
                      <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-grid)" />
                      <XAxis
                        type="number"
                        tick={{ fontSize: 11, fill: 'var(--chart-axis)' }}
                        tickFormatter={v => formatCents(v - (v % 100))}
                      />
                      <YAxis dataKey="name" type="category" tick={{ fontSize: 12, fill: 'var(--chart-axis)' }} width={90} />
                      <Tooltip
                        contentStyle={{ fontSize: 12 }}
                        formatter={(v: number) => [formatCents(v), 'Revenue']}
                      />
                      <Bar dataKey="revenueCents" name="Revenue" fill="var(--brand)" radius={[0, 4, 4, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              )}
            </Card>
          </div>

          <Card title="Top products" subtitle="Best sellers by revenue (max 10)" className="mt-6">
            {d.topProducts.length === 0 ? (
              <EmptyState title="No product sales" hint="No products sold in this window." />
            ) : (
              <div className="overflow-x-auto -mx-1 px-1"><table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-xs uppercase tracking-wide text-ink-500 border-b border-ink-100">
                    <th className="py-2">Product</th>
                    <th className="py-2 text-right">Qty</th>
                    <th className="py-2 text-right">Revenue</th>
                  </tr>
                </thead>
                <tbody>
                  {d.topProducts.map(p => (
                    <tr key={p.productId} className="border-b border-ink-100 last:border-0">
                      <td className="py-2 font-medium text-ink-800">{p.name}</td>
                      <td className="py-2 text-right">{formatNumber(p.qty)}</td>
                      <td className="py-2 text-right">{formatCents(p.revenueCents)}</td>
                    </tr>
                  ))}
                </tbody>
              </table></div>
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
