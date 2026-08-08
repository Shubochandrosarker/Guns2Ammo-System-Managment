import { useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { useAsync } from '@/lib/hooks'
import { api } from '@/lib/api'

export function BusinessGaps() {
  const g = useAsync(() => api.gaps.list(), [])
  const [taskedIds, setTaskedIds] = useState<Record<string, string>>({})
  const [pending, setPending]     = useState<Set<string>>(new Set())
  const [error, setError]         = useState<string | null>(null)

  if (g.loading) return <Spinner />
  const gaps = g.data ?? []

  const priorityStyle: Record<string, string> = {
    high:   'pill-red',
    medium: 'pill-amber',
    low:    'pill-gray',
  }

  async function createTask(gapId: string) {
    setPending(p => new Set(p).add(gapId))
    setError(null)
    try {
      const task = await api.gapActions.createTask(gapId)
      setTaskedIds(t => ({ ...t, [gapId]: task.id }))
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setPending(p => {
        const next = new Set(p)
        next.delete(gapId)
        return next
      })
    }
  }

  return (
    <div>
      <PageHeader
        eyebrow="Where money leaks"
        title="Business Gaps"
        subtitle="Concrete, evidence-backed problems the business should fix — each with a suggested owner and impact."
      />

      {error && (
        <div
          className="mb-4 text-sm rounded-lg px-3 py-2"
          style={{ background: 'var(--danger-soft)', color: 'var(--danger)' }}
        >
          {error}
        </div>
      )}

      <div className="space-y-4">
        {gaps.map(gap => {
          const taskId = taskedIds[gap.id]
          return (
            <Card key={gap.id}>
              <div className="flex items-start justify-between gap-3 sm:gap-4 flex-wrap">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2 mb-1 flex-wrap">
                    <span className={priorityStyle[gap.priority] ?? 'pill-gray'}>{gap.priority}</span>
                    <span
                      className="text-xs uppercase tracking-wide"
                      style={{ color: 'var(--text-muted)' }}
                    >
                      {gap.owner}
                    </span>
                  </div>
                  <h3 className="font-semibold" style={{ color: 'var(--text-primary)' }}>
                    {gap.problem}
                  </h3>
                  <p className="text-sm mt-1" style={{ color: 'var(--text-secondary)' }}>
                    {gap.evidence}
                  </p>
                </div>
                {taskId ? (
                  <span className="pill-green shrink-0">Task {taskId}</span>
                ) : (
                  <button
                    className="btn-secondary shrink-0 text-xs"
                    disabled={pending.has(gap.id)}
                    onClick={() => void createTask(gap.id)}
                  >
                    {pending.has(gap.id) ? 'Creating…' : 'Create task'}
                  </button>
                )}
              </div>

              <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                <div>
                  <div
                    className="text-[11px] font-semibold uppercase tracking-wide mb-1"
                    style={{ color: 'var(--text-muted)' }}
                  >
                    Business impact
                  </div>
                  <div className="text-sm" style={{ color: 'var(--text-secondary)' }}>
                    {gap.impact}
                  </div>
                </div>
                <div>
                  <div
                    className="text-[11px] font-semibold uppercase tracking-wide mb-1"
                    style={{ color: 'var(--text-muted)' }}
                  >
                    Recommended fix
                  </div>
                  <div className="text-sm" style={{ color: 'var(--text-secondary)' }}>
                    {gap.fix}
                  </div>
                </div>
              </div>
            </Card>
          )
        })}
      </div>
    </div>
  )
}
