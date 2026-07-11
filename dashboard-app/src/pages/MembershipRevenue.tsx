import { PageHeader } from '@/components/ui/PageHeader'
import { StatCard } from '@/components/ui/StatCard'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { ErrorState } from '@/components/ui/ErrorState'
import { useAsync } from '@/lib/hooks'
import { api } from '@/lib/api'
import { formatCurrency, formatNumber } from '@/lib/format'

export function MembershipRevenue() {
  const m = useAsync(() => api.analytics.memberships(), [])
  if (m.loading) return <Spinner />
  if (m.error) return <Card title="Membership Revenue"><ErrorState message={m.error} onRetry={m.refresh} /></Card>
  if (!m.data) return <Card title="No data" />
  const d = m.data

  return (
    <div>
      <PageHeader
        eyebrow="Memberistic"
        title="Membership Revenue"
        subtitle="Active members, renewals, churn risk, corporate accounts, and per-plan revenue performance."
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Active members"     value={d.active}       format="number"  intent="success" />
        <StatCard label="New this period"    value={d.newThisPeriod}format="number"  />
        <StatCard label="MRR"                value={d.mrr}          format="currency" sublabel={`Corporate: ${d.corporate}`} />
        <StatCard label="Churn risk"         value={d.churnRiskCount}format="number" intent="danger" />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-6">
        <Card title="Plan performance" className="lg:col-span-2">
          <div className="overflow-x-auto -mx-1 px-1"><table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs uppercase tracking-wide text-ink-500 border-b border-ink-100">
                <th className="py-2">Plan</th>
                <th className="py-2 text-right">Active</th>
                <th className="py-2 text-right">Revenue</th>
                <th className="py-2 text-right">ARPU</th>
              </tr>
            </thead>
            <tbody>
              {d.planPerformance.map(p => (
                <tr key={p.plan} className="border-b border-ink-100 last:border-0">
                  <td className="py-2 font-medium text-ink-800">{p.plan}</td>
                  <td className="py-2 text-right">{formatNumber(p.active)}</td>
                  <td className="py-2 text-right">{formatCurrency(p.revenue)}</td>
                  <td className="py-2 text-right text-ink-600">{p.active ? formatCurrency(Math.round(p.revenue / p.active)) : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table></div>
        </Card>

        <Card title="Renewal picture">
          <ul className="space-y-3 text-sm">
            <li className="flex justify-between"><span>Renewals</span><span className="pill-green">{d.renewals}</span></li>
            <li className="flex justify-between"><span>Expired</span><span className="pill-red">{d.expired}</span></li>
            <li className="flex justify-between"><span>Renewal rate</span><span className="pill-blue">
              {d.renewals + d.expired ? Math.round((d.renewals / (d.renewals + d.expired)) * 100) : 0}%
            </span></li>
            <li className="flex justify-between"><span>Renewal opps</span><span className="pill-amber">{d.renewalOpportunityCount}</span></li>
          </ul>
          <p className="mt-4 text-xs text-ink-500">
            Renewal reminders (30/7/1-day) live in Automation Center — enable to
            recover expected churn revenue.
          </p>
        </Card>
      </div>
    </div>
  )
}
