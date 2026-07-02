import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { useAsync } from '@/lib/hooks'
import { api } from '@/lib/api'
import type { Agent } from '@/types/analytics'

const STATUS_STYLE: Record<Agent['status'], string> = {
  active:        'pill-green',
  paused:        'pill-gray',
  needs_review:  'pill-amber',
}

export function AIAgents() {
  const q = useAsync(() => api.agents.list(), [])
  if (q.loading) return <Spinner />
  const agents = q.data ?? []

  return (
    <div>
      <PageHeader
        eyebrow="Agent departments"
        title="AI Agents"
        subtitle="Your agency of always-on AI agents. Each is scoped to a department and connected to a specific model."
      />

      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        {agents.map(a => (
          <Card key={a.id} className="hover:shadow-lg transition-shadow">
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="text-[11px] uppercase tracking-wide text-ink-500">
                  {a.department}
                </div>
                <div className="mt-1 font-semibold text-ink-800">{a.name}</div>
              </div>
              <span className={STATUS_STYLE[a.status]}>{a.status.replace('_', ' ')}</span>
            </div>

            <div className="mt-3 space-y-1.5 text-xs text-ink-600">
              <Row label="Model"        value={a.model} />
              <Row label="Assigned"     value={`${a.assignedTasks} task${a.assignedTasks === 1 ? '' : 's'}`} />
              <Row label="Last run"     value={a.lastRun ? new Date(a.lastRun).toLocaleString() : '—'} />
              <Row label="Confidence"   value={`${Math.round(a.confidence * 100)}%`} />
            </div>

            <div className="mt-3 rounded-lg bg-ink-50 p-3 text-sm text-ink-700">
              <div className="text-[11px] uppercase tracking-wide text-ink-500 mb-1">
                Last output
              </div>
              {a.lastOutput}
            </div>

            <div className="mt-4 flex items-center gap-2">
              <button className="btn-primary text-xs">Run now</button>
              <button className="btn-secondary text-xs">History</button>
              {a.reviewRequired && (
                <span className="ml-auto pill-amber">Review required</span>
              )}
            </div>
          </Card>
        ))}
      </div>
    </div>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between">
      <span className="text-ink-500">{label}</span>
      <span className="text-ink-800 font-medium">{value}</span>
    </div>
  )
}
