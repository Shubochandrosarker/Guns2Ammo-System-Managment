import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { useAsync } from '@/lib/hooks'
import { api } from '@/lib/api'

const priorityStyle: Record<string, string> = {
  high:   'pill-red',
  medium: 'pill-amber',
  low:    'pill-gray',
}

export function AIInsights() {
  const q = useAsync(() => api.insights.list(), [])
  if (q.loading) return <Spinner />
  const insights = q.data ?? []

  return (
    <div>
      <PageHeader
        eyebrow="AI-generated"
        title="AI Insights"
        subtitle="Cross-signal recommendations combining GSC, GA4, WooCommerce, bookings, membership, and support data."
        actions={
          <button className="btn-primary text-sm">Regenerate today&apos;s insights</button>
        }
      />

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {insights.map(i => (
          <Card key={i.id}>
            <div className="flex items-center justify-between mb-3">
              <span className={priorityStyle[i.priority] ?? 'pill-gray'}>{i.priority} priority</span>
              <span className="text-[11px] uppercase tracking-wide text-ink-500">{i.category}</span>
            </div>
            <h3 className="font-semibold text-ink-800">{i.title}</h3>
            <p className="text-sm text-ink-600 mt-1">{i.summary}</p>

            <div className="mt-4 space-y-2 text-sm">
              <Row label="Why" value={i.reason} />
              <Row label="Do"  value={i.action} />
              <Row label="Impact" value={i.expectedImpact} />
            </div>

            <div className="mt-5 flex items-center gap-2">
              <button className="btn-primary text-xs">Approve → create task</button>
              <button className="btn-secondary text-xs">Dismiss</button>
            </div>
          </Card>
        ))}
      </div>
    </div>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex gap-3">
      <div className="w-14 shrink-0 text-[11px] uppercase tracking-wide text-ink-500 pt-0.5">{label}</div>
      <div className="text-ink-700">{value}</div>
    </div>
  )
}
