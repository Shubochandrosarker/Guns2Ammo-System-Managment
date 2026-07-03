import { LineChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis, CartesianGrid } from 'recharts'
import { PageHeader } from '@/components/ui/PageHeader'
import { StatCard } from '@/components/ui/StatCard'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { useAsync } from '@/lib/hooks'
import { api } from '@/lib/api'
import { formatNumber } from '@/lib/format'

export function InsightisticAnalytics() {
  const q = useAsync(() => api.analytics.insightistic(), [])
  if (q.loading) return <Spinner label="Loading web analytics…" />
  if (!q.data) return <Card title="No data" />
  const d = q.data

  const engagementPct = d.sessions > 0 ? Math.round((d.engagedSessions / d.sessions) * 100) : 0

  return (
    <div>
      <PageHeader
        eyebrow="Web + attribution"
        title="Insightistic Analytics"
        subtitle="Google Analytics 4 sessions, engagement, and revenue attribution overlaid on the WordPress/WooCommerce truth-set."
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Sessions / 30d"     value={d.sessions}          format="number" />
        <StatCard label="Engaged sessions"   value={d.engagedSessions}   format="number" intent="success" />
        <StatCard label="Revenue attributed" value={d.revenueAttributed} format="currency" />
        <StatCard label="Bounce rate"        value={d.bounceRate}        format="percent" intent="warn" />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-6">
        <Card title="Sessions trend" subtitle="Daily sessions" className="lg:col-span-2">
          <div className="h-64">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={d.sessionsSeries} margin={{ top: 10, right: 10, left: 0, bottom: 0 }}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-grid)" />
                <XAxis dataKey="date" tickFormatter={x => x.slice(5)} tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} />
                <YAxis tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} width={40} />
                <Tooltip formatter={(v: number) => [formatNumber(v), 'Sessions']} />
                <Line type="monotone" dataKey="value" stroke="var(--info)" strokeWidth={2.5} dot={false} />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Card title="Engagement">
          <div className="space-y-3 text-sm">
            <Row label="Engagement rate"      value={`${engagementPct}%`} />
            <Row label="Sessions"             value={formatNumber(d.sessions)} />
            <Row label="Engaged"              value={formatNumber(d.engagedSessions)} />
            <Row label="Bounce rate"          value={`${d.bounceRate.toFixed(1)}%`} />
          </div>
          <div className="mt-4 text-xs text-ink-500">
            GA4 counts a session as engaged when it lasts ≥10s, converts, or has
            ≥2 page views. Higher = better.
          </div>
        </Card>
      </div>
    </div>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between">
      <span className="text-ink-700">{label}</span>
      <span className="font-semibold text-ink-800">{value}</span>
    </div>
  )
}
