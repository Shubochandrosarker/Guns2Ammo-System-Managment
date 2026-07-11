import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'
import { Spinner } from '@/components/ui/Spinner'
import { ErrorState } from '@/components/ui/ErrorState'
import { useAsync } from '@/lib/hooks'
import { api } from '@/lib/api'

export function ShooterInsights() {
  const q = useAsync(() => api.analytics.shooterInsights(), [])
  if (q.loading) return <Spinner label="Loading shooter segments…" />
  if (q.error) return <Card title="Shooter Insights"><ErrorState message={q.error} onRetry={q.refresh} /></Card>
  if (!q.data) return <Card title="No data" />
  const d = q.data

  return (
    <div>
      <PageHeader
        eyebrow="Customer intelligence"
        title="Shooter Insights"
        subtitle="Who is walking through the door, who is coming back, and who is at risk of drifting away."
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
        <StatCard label="Known shooters"        value={d.knownShooters} />
        <StatCard label="Repeat rate"           value={d.repeatRatePct}       format="percent" intent="warn" />
        <StatCard label="First-time this month" value={d.firstTimeThisMonth}  intent="success" />
        <StatCard label="Lapsed (90d silent)"   value={d.lapsed90d}           intent="danger" />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">
        <Card title="Segments" subtitle="Auto-detected from booking + purchase behavior">
          <ul className="space-y-3 text-sm">
            {d.segments.map(s => (
              <li key={s.key} className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="font-medium" style={{ color: 'var(--text-primary)' }}>{s.label}</div>
                  <div className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>{s.hint}</div>
                </div>
                <span className="pill-blue shrink-0">{s.value}</span>
              </li>
            ))}
          </ul>
        </Card>

        <Card title="Top follow-up opportunities">
          {d.opportunities.length === 0 ? (
            <div className="text-sm" style={{ color: 'var(--text-muted)' }}>
              Not enough behavioural signal yet — keep collecting bookings + orders and this list will fill in.
            </div>
          ) : (
            <ul className="text-sm space-y-2">
              {d.opportunities.map((o, i) => (
                <li key={i} style={{ color: 'var(--text-secondary)' }}>{o}</li>
              ))}
            </ul>
          )}
          <div className="mt-4 text-xs" style={{ color: 'var(--text-muted)' }}>
            These segments feed the Automation Center and the Email Manager Agent.
          </div>
        </Card>
      </div>
    </div>
  )
}
