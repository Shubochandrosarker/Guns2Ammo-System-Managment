import { PageHeader } from '@/components/ui/PageHeader'
import { StatCard } from '@/components/ui/StatCard'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { ErrorState } from '@/components/ui/ErrorState'
import { useAsync } from '@/lib/hooks'
import { api } from '@/lib/api'
import { formatCurrency, formatNumber, formatPercent } from '@/lib/format'
import { BarChart, Bar, ResponsiveContainer, Tooltip, XAxis, YAxis, CartesianGrid } from 'recharts'

export function BookingRevenue() {
  const b = useAsync(() => api.analytics.bookings(), [])

  if (b.loading) return <Spinner />
  if (b.error) return <Card title="Booking Revenue"><ErrorState message={b.error} onRetry={b.refresh} /></Card>
  if (!b.data) return <Card title="No data" />

  const d = b.data
  const totalRevenue = d.bookingsByType.reduce((sum, t) => sum + t.revenue, 0)

  return (
    <div>
      <PageHeader
        eyebrow="Range · Class · Events"
        title="Booking Revenue"
        subtitle="Lanes, classes, Ladies Tuesday, CCW, private events — where bookings convert and where they leak."
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Booking revenue" value={totalRevenue}          format="currency" intent="success" />
        <StatCard label="Conversion"      value={d.conversionRate}      format="percent"  sublabel="Landing → booked" />
        <StatCard label="Cancellation"    value={d.cancellationRate}    format="percent"  intent="warn" />
        <StatCard label="No-show rate"    value={d.noShowRate}          format="percent"  intent="warn" />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-6">
        <Card title="Bookings by type" subtitle={`Top type: ${d.topBookingType}`} className="lg:col-span-2">
          <div className="h-64">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={d.bookingsByType} margin={{ top: 10, right: 10, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-grid)" />
                <XAxis dataKey="type" tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} />
                <YAxis yAxisId="left"  tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} tickFormatter={v => `${v}`} width={40} />
                <YAxis yAxisId="right" orientation="right" tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} tickFormatter={v => `$${Math.round(v / 100)}`} width={50} />
                <Tooltip />
                <Bar yAxisId="left"  dataKey="count"   fill="var(--info)" radius={[4, 4, 0, 0]} name="Bookings" />
                <Bar yAxisId="right" dataKey="revenue" fill="var(--brand)" radius={[4, 4, 0, 0]} name="Revenue"  />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Card title="Paid vs Unpaid">
          <div className="space-y-3">
            <RatioBar
              label="Paid"
              value={d.paidVsUnpaid.paid}
              total={d.paidVsUnpaid.paid + d.paidVsUnpaid.unpaid}
              color="bg-emerald-500"
            />
            <RatioBar
              label="Unpaid"
              value={d.paidVsUnpaid.unpaid}
              total={d.paidVsUnpaid.paid + d.paidVsUnpaid.unpaid}
              color="bg-amber-500"
            />
          </div>
          <div className="mt-6 space-y-2 text-sm">
            <div className="flex justify-between"><span>Total bookings</span><span className="font-semibold">{formatNumber(d.paidVsUnpaid.paid + d.paidVsUnpaid.unpaid)}</span></div>
            <div className="flex justify-between"><span>Avg per day</span><span className="font-semibold">{formatNumber(Math.round((d.paidVsUnpaid.paid + d.paidVsUnpaid.unpaid) / 30))}</span></div>
            <div className="flex justify-between"><span>Conversion</span><span className="font-semibold">{formatPercent(d.conversionRate)}</span></div>
          </div>
        </Card>
      </div>

      <Card title="Revenue detail by type" className="mt-6">
        <div className="overflow-x-auto -mx-1 px-1"><table className="w-full text-sm">
          <thead>
            <tr className="text-left text-xs uppercase tracking-wide text-ink-500 border-b border-ink-100">
              <th className="py-2">Type</th>
              <th className="py-2 text-right">Bookings</th>
              <th className="py-2 text-right">Revenue</th>
              <th className="py-2 text-right">Avg</th>
            </tr>
          </thead>
          <tbody>
            {d.bookingsByType.map(row => (
              <tr key={row.type} className="border-b border-ink-100 last:border-0">
                <td className="py-2 font-medium text-ink-800">{row.type}</td>
                <td className="py-2 text-right">{formatNumber(row.count)}</td>
                <td className="py-2 text-right">{formatCurrency(row.revenue)}</td>
                <td className="py-2 text-right text-ink-600">{row.count ? formatCurrency(Math.round(row.revenue / row.count)) : '—'}</td>
              </tr>
            ))}
          </tbody>
        </table></div>
      </Card>
    </div>
  )
}

function RatioBar({ label, value, total, color }: { label: string; value: number; total: number; color: string }) {
  const pct = total ? Math.round((value / total) * 100) : 0
  return (
    <div>
      <div className="flex items-center justify-between text-xs text-ink-500 mb-1">
        <span>{label}</span>
        <span className="font-semibold text-ink-800">{value} ({pct}%)</span>
      </div>
      <div className="h-2 rounded-full bg-ink-100 overflow-hidden">
        <div className={color + ' h-full'} style={{ width: pct + '%' }} />
      </div>
    </div>
  )
}
