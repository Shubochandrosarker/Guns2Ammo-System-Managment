import { useCallback, useEffect, useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { api } from '@/lib/api'
import type { AIInsight } from '@/types/analytics'

const priorityStyle: Record<string, string> = {
  high:   'pill-red',
  medium: 'pill-amber',
  low:    'pill-gray',
}

// Server payload from Phase 11 may include these extras when the insight
// has already been acted on. Extending the base type inline keeps
// analytics.ts unchanged for callers who don't need them.
type AIInsightWithDecision = AIInsight & { converted?: boolean; taskId?: string }

export function AIInsights() {
  const [insights, setInsights] = useState<AIInsightWithDecision[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [pending, setPending] = useState<Set<string>>(new Set())

  const refresh = useCallback(async () => {
    try {
      setInsights(await api.insights.list() as AIInsightWithDecision[])
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    }
  }, [])

  useEffect(() => {
    void refresh()
  }, [refresh])

  async function approve(id: string) {
    setPending(p => new Set(p).add(id))
    setError(null)
    try {
      const task = await api.insights.approve(id)
      // Optimistic: mark the card converted so the user sees the outcome
      // before the /ai/insights re-fetch runs.
      setInsights(list =>
        list?.map(i => (i.id === id ? { ...i, converted: true, taskId: task.id } : i)) ?? null,
      )
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setPending(p => {
        const next = new Set(p)
        next.delete(id)
        return next
      })
    }
  }

  async function dismiss(id: string) {
    setPending(p => new Set(p).add(id))
    setError(null)
    try {
      await api.insights.dismiss(id)
      setInsights(list => list?.filter(i => i.id !== id) ?? null)
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setPending(p => {
        const next = new Set(p)
        next.delete(id)
        return next
      })
    }
  }

  if (insights === null) return <Spinner />

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

      {error && (
        <div
          className="mb-4 text-sm rounded-lg px-3 py-2"
          style={{ background: 'var(--danger-soft)', color: 'var(--danger)' }}
        >
          {error}
        </div>
      )}

      {insights.length === 0 ? (
        <Card>
          <div className="text-sm" style={{ color: 'var(--text-muted)' }}>
            Nothing to review right now. The hourly insight generator will refill this list.
          </div>
        </Card>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {insights.map(i => (
            <Card key={i.id}>
              <div className="flex items-center justify-between mb-3 flex-wrap gap-2">
                <span className={priorityStyle[i.priority] ?? 'pill-gray'}>{i.priority} priority</span>
                <span className="text-[11px] uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>
                  {i.category}
                </span>
              </div>
              <h3 className="font-semibold" style={{ color: 'var(--text-primary)' }}>{i.title}</h3>
              <p className="text-sm mt-1" style={{ color: 'var(--text-secondary)' }}>{i.summary}</p>

              <div className="mt-4 space-y-2 text-sm">
                <Row label="Why" value={i.reason} />
                <Row label="Do"  value={i.action} />
                <Row label="Impact" value={i.expectedImpact} />
              </div>

              {i.converted ? (
                <div
                  className="mt-5 text-xs rounded-lg px-3 py-2"
                  style={{ background: 'var(--success-soft)', color: 'var(--success)' }}
                >
                  Converted to task {i.taskId ?? ''}. Track it in Tasks.
                </div>
              ) : (
                <div className="mt-5 flex items-center gap-2">
                  <button
                    className="btn-primary text-xs"
                    disabled={pending.has(i.id)}
                    onClick={() => void approve(i.id)}
                  >
                    Approve → create task
                  </button>
                  <button
                    className="btn-secondary text-xs"
                    disabled={pending.has(i.id)}
                    onClick={() => void dismiss(i.id)}
                  >
                    Dismiss
                  </button>
                </div>
              )}
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex gap-3">
      <div
        className="w-14 shrink-0 text-[11px] uppercase tracking-wide pt-0.5"
        style={{ color: 'var(--text-muted)' }}
      >
        {label}
      </div>
      <div style={{ color: 'var(--text-secondary)' }}>{value}</div>
    </div>
  )
}
