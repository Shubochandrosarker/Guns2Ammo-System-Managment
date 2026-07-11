import { PageHeader } from '@/components/ui/PageHeader'
import { StatCard } from '@/components/ui/StatCard'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { ErrorState } from '@/components/ui/ErrorState'
import { useAsync } from '@/lib/hooks'
import { api } from '@/lib/api'
import { formatCurrency, formatNumber, formatPercent } from '@/lib/format'
import { BarChart, Bar, ResponsiveContainer, Tooltip, XAxis, YAxis, CartesianGrid } from 'recharts'

export function WooStoreAnalytics() {
  const s = useAsync(() => api.analytics.store(), [])
  if (s.loading) return <Spinner />
  if (s.error) return <Card title="Woo Store Analytics"><ErrorState message={s.error} onRetry={s.refresh} /></Card>
  if (!s.data) return <Card title="No data" />
  const d = s.data

  return (
    <div>
      <PageHeader
        eyebrow="WooCommerce"
        title="Woo Store Analytics"
        subtitle="Orders, product performance, category and brand revenue, refunds, and slow-movers."
      />

      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        <StatCard label="Orders"           value={d.orders}             format="number" />
        <StatCard label="Store revenue"    value={d.revenue}            format="currency" intent="success" />
        <StatCard label="Avg order value"  value={d.averageOrderValue}  format="currency" />
        <StatCard label="Repeat customers" value={d.repeatCustomerPct}  format="percent"  intent="warn" />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">
        <Card title="Category revenue">
          <div className="h-56">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={d.categoryRevenue} layout="vertical" margin={{ top: 5, right: 10, left: 30, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-grid)" />
                <XAxis type="number" tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} tickFormatter={v => `$${Math.round(v / 100)}`} />
                <YAxis dataKey="category" type="category" tick={{ fontSize: 12, fill: 'var(--chart-axis)' }} width={100} />
                <Tooltip formatter={(v: number) => [formatCurrency(v), 'Revenue']} />
                <Bar dataKey="revenue" fill="var(--brand)" radius={[0, 4, 4, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Card title="Brand revenue">
          <div className="h-56">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={d.brandRevenue} layout="vertical" margin={{ top: 5, right: 10, left: 30, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-grid)" />
                <XAxis type="number" tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} tickFormatter={v => `$${Math.round(v / 100)}`} />
                <YAxis dataKey="brand" type="category" tick={{ fontSize: 12, fill: 'var(--chart-axis)' }} width={110} />
                <Tooltip formatter={(v: number) => [formatCurrency(v), 'Revenue']} />
                <Bar dataKey="revenue" fill="var(--info)" radius={[0, 4, 4, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Card>
      </div>

      <Card title="Best sellers" className="mt-6">
        <div className="overflow-x-auto -mx-1 px-1"><table className="w-full text-sm">
          <thead>
            <tr className="text-left text-xs uppercase tracking-wide text-ink-500 border-b border-ink-100">
              <th className="py-2">Product</th>
              <th className="py-2">SKU</th>
              <th className="py-2 text-right">Units</th>
              <th className="py-2 text-right">Revenue</th>
            </tr>
          </thead>
          <tbody>
            {d.topProducts.map(p => (
              <tr key={p.id} className="border-b border-ink-100 last:border-0">
                <td className="py-2 font-medium text-ink-800">{p.name}</td>
                <td className="py-2 text-ink-500 font-mono text-xs">{p.sku}</td>
                <td className="py-2 text-right">{formatNumber(p.units)}</td>
                <td className="py-2 text-right">{formatCurrency(p.revenue)}</td>
              </tr>
            ))}
          </tbody>
        </table></div>
      </Card>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">
        <Card title="Refunds">
          <div className="flex items-center gap-4">
            <StatCard label="Count"  value={d.refundCount}  format="number"  intent="danger" />
            <StatCard label="Amount" value={d.refundAmount} format="currency" intent="danger" />
          </div>
          <p className="mt-4 text-xs text-ink-500">
            Refund rate: {formatPercent((d.refundAmount / d.revenue) * 100)} of store revenue.
          </p>
        </Card>

        <Card title="Slow movers" subtitle="Consider bundling or clearance">
          <ul className="space-y-2 text-sm">
            {d.slowMovers.map(sm => (
              <li key={sm.id} className="flex justify-between">
                <span className="text-ink-700 truncate mr-3">{sm.name}</span>
                <span className="pill-amber shrink-0">{sm.daysWithoutSale}d unsold</span>
              </li>
            ))}
          </ul>
        </Card>
      </div>
    </div>
  )
}
