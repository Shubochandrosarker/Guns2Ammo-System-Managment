// Phase D: NEW page for the canonical-envelope GET /analytics/waivers
// (overview waiver fields + 30/60/90-day expiry buckets + last-12-months
// signing history). This wire carries counts only — NO PII — and the page
// renders the Phase-C five explicit states, including the unavailable state
// the demo fixture exercises (g2a-waivers inactive).

import { PageHeader } from '@/components/ui/PageHeader'
import { StatCard } from '@/components/ui/StatCard'
import { Card } from '@/components/ui/Card'
import { Skeleton } from '@/components/ui/Skeleton'
import { ErrorState } from '@/components/ui/ErrorState'
import { EmptyState } from '@/components/ui/EmptyState'
import { useAsync } from '@/lib/hooks'
import { api } from '@/lib/api'
import { sourceNames } from '@/lib/sources'
import { formatDateTimeInZone, formatNumber } from '@/lib/format'
import type { WaiversAnalyticsData } from '@/types/api'
import {
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'

// Merge the two 12-month signing series onto one axis for a grouped bar
// chart: [{ month, archive, people }].
function signingRows(d: WaiversAnalyticsData) {
  const byMonth = new Map<string, { month: string; archive: number; people: number }>()
  for (const p of d.signings.archive) {
    byMonth.set(p.month, { month: p.month, archive: p.count, people: 0 })
  }
  for (const p of d.signings.people) {
    const row = byMonth.get(p.month) ?? { month: p.month, archive: 0, people: 0 }
    row.people = p.count
    byMonth.set(p.month, row)
  }
  return [...byMonth.values()].sort((a, b) => a.month.localeCompare(b.month))
}

export function WaiverAnalytics() {
  const q = useAsync(() => api.analytics.waivers(), [])
  const res = q.data // Enveloped<WaiversAnalyticsData> | null
  const d = res?.data
  const meta = res?.meta

  const empty =
    !!d &&
    d.signed === 0 &&
    d.pending === 0 &&
    d.expiring.d30 === 0 &&
    d.expiring.d60 === 0 &&
    d.expiring.d90 === 0 &&
    d.signings.archive.every(m => m.count === 0) &&
    d.signings.people.every(m => m.count === 0)

  return (
    <div>
      <PageHeader
        eyebrow={meta ? `Signed waivers · ${meta.timezone}` : 'G2A Waivers'}
        title="Waiver Analytics"
        subtitle="Signed and pending waivers, upcoming expirations, and 12 months of signing history. Counts only — no personal information leaves the server."
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
            These waiver figures may be out of date
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
          message={`Couldn't load waiver analytics: ${q.error ?? 'Unknown error'}`}
          onRetry={q.refresh}
        />
      ) : meta?.freshness.status === 'unavailable' ? (
        <Card title="Waivers unavailable">
          <div className="text-sm text-ink-600 bg-ink-50 border border-ink-100 rounded-lg p-3">
            <span className="pill-gray">Unavailable</span>
            <div className="mt-2 text-xs text-ink-500">
              {sourceNames(meta?.source ?? [])} is missing or inactive, so there is no
              waiver data to report. Activate it in the WordPress admin to populate this page.
            </div>
          </div>
        </Card>
      ) : empty ? (
        <Card>
          <EmptyState
            title="No waiver activity recorded"
            hint="The waiver plugin is active but has no signings, pending waivers, or upcoming expirations to report."
          />
        </Card>
      ) : (
        <>
          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
            <StatCard label="Signed"  value={formatNumber(d.signed)} intent="success" />
            <StatCard label="Pending" value={formatNumber(d.pending)} intent={d.pending > 0 ? 'warn' : 'default'} />
            <StatCard label="Expiring ≤ 30d" value={formatNumber(d.expiring.d30)} intent={d.expiring.d30 > 0 ? 'danger' : 'success'} />
            <StatCard label="Expiring ≤ 60d" value={formatNumber(d.expiring.d60)} intent={d.expiring.d60 > 0 ? 'warn' : 'success'} />
            <StatCard label="Expiring ≤ 90d" value={formatNumber(d.expiring.d90)} />
          </div>

          <Card
            title="Signings — last 12 months"
            subtitle="Waivers archived vs distinct people signing, per month"
            className="mt-6"
          >
            <div className="h-64">
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={signingRows(d)} margin={{ top: 10, right: 10, left: 0, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-grid)" />
                  <XAxis dataKey="month" tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} tickFormatter={m => m.slice(2)} />
                  <YAxis tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} width={36} allowDecimals={false} />
                  <Tooltip
                    contentStyle={{ fontSize: 12 }}
                    formatter={(v: number, name: string) => [formatNumber(v), name]}
                  />
                  <Legend wrapperStyle={{ fontSize: 12 }} />
                  <Bar dataKey="archive" name="Waivers archived" fill="var(--brand)" radius={[4, 4, 0, 0]} />
                  <Bar dataKey="people"  name="People signing"   fill="var(--info)"  radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>
            <p className="mt-3 text-xs text-ink-500">
              Counts only — signer names and other personal details are never sent to this dashboard.
            </p>
          </Card>
        </>
      )}
    </div>
  )
}

function LoadingSkeleton() {
  return (
    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4" aria-busy="true">
      {[0, 1, 2, 3, 4].map(i => (
        <div key={i} className="card">
          <div className="card-body space-y-3">
            <Skeleton className="h-3 w-24" />
            <Skeleton className="h-7 w-20" />
          </div>
        </div>
      ))}
    </div>
  )
}
