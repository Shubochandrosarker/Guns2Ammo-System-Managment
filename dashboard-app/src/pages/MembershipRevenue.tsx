// Phase D: rewired to the canonical-envelope GET /analytics/memberships?from&to
// (lifecycle counts + planBreakdown + renewals/churn + daily new-member
// series + trends). Five explicit states + stale banner + trend chips.

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
import { formatCents, formatDateTimeInZone, formatNumber, formatPercent } from '@/lib/format'
import {
  Bar,
  BarChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'

export function MembershipRevenue() {
  // No explicit range → the server applies its default window (last 30 days).
  const q = useAsync(() => api.analytics.memberships(), [])
  const res = q.data // Enveloped<MembershipsAnalyticsData> | null
  const d = res?.data
  const meta = res?.meta

  return (
    <div>
      <PageHeader
        eyebrow={meta ? `Last 30 days · ${meta.timezone}` : 'Memberistic'}
        title="Membership Revenue"
        subtitle="Active members, new sign-ups, renewals, failed renewals, churn, and per-plan revenue performance."
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
            These membership figures may be out of date
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
          message={`Couldn't load membership analytics: ${q.error ?? 'Unknown error'}`}
          onRetry={q.refresh}
        />
      ) : meta?.freshness.status === 'unavailable' ? (
        <Card title="Memberships unavailable">
          <div className="text-sm text-ink-600 bg-ink-50 border border-ink-100 rounded-lg p-3">
            <span className="pill-gray">Unavailable</span>
            <div className="mt-2 text-xs text-ink-500">
              {sourceNames(meta?.source ?? [])} is missing or inactive, so there is no
              membership data to report. Activate it in the WordPress admin to populate this page.
            </div>
          </div>
        </Card>
      ) : d.active === 0 && d.new === 0 && d.expired === 0 && d.mrrCents === 0 ? (
        <Card>
          <EmptyState
            title="No membership activity in this period"
            hint="Memberistic is active but recorded nothing in the selected window."
          />
        </Card>
      ) : (
        <>
          {/* Trend chips — new members + revenue vs the previous window */}
          <div className="mb-4 flex flex-wrap items-center gap-2 text-sm">
            <TrendDelta label="New members" pct={d.trends.deltas.countPct} />
            <TrendDelta label="Revenue" pct={d.trends.deltas.revenuePct} />
            <span className="text-xs text-ink-500">
              vs previous period: {formatNumber(d.trends.previous.count)} new ·{' '}
              {formatCents(d.trends.previous.revenueCents)} revenue ·{' '}
              {formatCents(d.trends.previous.netCents)} net
            </span>
          </div>

          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            <StatCard label="Active members" value={formatNumber(d.active)} intent="success" />
            <StatCard label="MRR"            value={formatCents(d.mrrCents)} />
            <StatCard label="New in range"   value={formatNumber(d.newInRange)} />
            <StatCard
              label="Churn rate"
              value={d.churn.rate === null ? 'n/a' : formatPercent(d.churn.rate)}
              intent={d.churn.rate !== null && d.churn.rate > 0 ? 'danger' : 'default'}
              sublabel={d.churn.calculation}
            />
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-6">
            <Card title="New members per day" subtitle="Zero-filled — quiet days render as zero" className="lg:col-span-2">
              <div className="h-64">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={d.series} margin={{ top: 10, right: 10, left: 0, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-grid)" />
                    <XAxis dataKey="date" tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} tickFormatter={dt => dt.slice(5)} />
                    <YAxis tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} width={36} allowDecimals={false} />
                    <Tooltip
                      contentStyle={{ fontSize: 12 }}
                      formatter={(v: number) => [formatNumber(v), 'New members']}
                    />
                    <Bar dataKey="newMembers" name="New members" fill="var(--brand)" radius={[4, 4, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </Card>

            <Card title="Renewal picture">
              <ul className="space-y-3 text-sm">
                <li className="flex justify-between"><span>Renewals in range</span><span className="pill-green">{formatNumber(d.renewalsInRange)}</span></li>
                <li className="flex justify-between"><span>Failed renewals</span><span className={d.failedRenewals > 0 ? 'pill-red' : 'pill-green'}>{formatNumber(d.failedRenewals)}</span></li>
                <li className="flex justify-between"><span>Expired</span><span className="pill-amber">{formatNumber(d.expired)}</span></li>
                <li className="flex justify-between"><span>New in range</span><span className="pill-blue">{formatNumber(d.newInRange)}</span></li>
              </ul>
              <p className="mt-4 text-xs text-ink-500">
                Churn: {d.churn.rate === null ? 'not computable for this window' : formatPercent(d.churn.rate)} —{' '}
                {d.churn.calculation}. Renewal reminders (30/7/1-day) live in Automation Center.
              </p>
            </Card>
          </div>

          <Card title="Plan breakdown" className="mt-6">
            {d.planBreakdown.length === 0 ? (
              <EmptyState title="No plans reported" hint="Memberistic returned an empty plan list for this window." />
            ) : (
              <div className="overflow-x-auto -mx-1 px-1"><table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-xs uppercase tracking-wide text-ink-500 border-b border-ink-100">
                    <th className="py-2">Plan</th>
                    <th className="py-2 text-right">Active</th>
                    <th className="py-2 text-right">MRR</th>
                    <th className="py-2 text-right">ARPU</th>
                  </tr>
                </thead>
                <tbody>
                  {d.planBreakdown.map(p => (
                    <tr key={p.planId} className="border-b border-ink-100 last:border-0">
                      <td className="py-2 font-medium text-ink-800">{p.name}</td>
                      <td className="py-2 text-right">{formatNumber(p.active)}</td>
                      <td className="py-2 text-right">{formatCents(p.mrrCents)}</td>
                      <td className="py-2 text-right text-ink-600">
                        {p.active ? formatCents(Math.round(p.mrrCents / p.active)) : '—'}
                      </td>
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
