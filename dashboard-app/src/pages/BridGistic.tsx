import { FormEvent, useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { api, type BridGisticAction, type BridGisticAskResult } from '@/lib/api'

interface HistoryItem extends BridGisticAskResult {
  ts: string
}

const SUGGESTIONS = [
  'Show this week&apos;s booking revenue',
  'Find members expiring this month',
  'Analyze why store sales dropped',
  'Draft a customer follow-up email',
  'Show pending FFL transfers',
  'Create a task to improve the CCW class page',
  'Generate this week&apos;s business report',
]

export function BridGistic() {
  const [params] = useSearchParams()
  const [input, setInput] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [history, setHistory] = useState<HistoryItem[]>([])
  const [pending, setPending] = useState<BridGisticAction[]>([])

  const refreshPending = useCallback(async () => {
    try {
      const list = await api.bridgistic.pending()
      setPending(list)
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    }
  }, [])

  const submitQuery = useCallback(
    async (q: string) => {
      const trimmed = q.trim()
      if (!trimmed) return
      setBusy(true)
      setError(null)
      try {
        const result = await api.bridgistic.ask(trimmed)
        setHistory(h => [{ ...result, ts: new Date().toISOString() }, ...h])
        if (result.category === 'action') {
          void refreshPending()
        }
      } catch (err) {
        setError(err instanceof Error ? err.message : String(err))
      } finally {
        setBusy(false)
      }
    },
    [refreshPending],
  )

  // Deep-linked query from the topbar `?q=...` runs on mount.
  useEffect(() => {
    const q = params.get('q')
    if (q) queueMicrotask(() => submitQuery(q))
  }, [params, submitQuery])

  // Load the pending queue on mount + whenever we approve/reject.
  useEffect(() => {
    queueMicrotask(refreshPending)
  }, [refreshPending])

  function submit(e: FormEvent) {
    e.preventDefault()
    void submitQuery(input)
    setInput('')
  }

  async function approve(id: string) {
    setBusy(true)
    setError(null)
    try {
      await api.bridgistic.approve(id)
      await refreshPending()
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  async function reject(id: string) {
    setBusy(true)
    setError(null)
    try {
      await api.bridgistic.reject(id)
      await refreshPending()
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <PageHeader
        eyebrow="Claude command bridge"
        title="BridGistic"
        subtitle="Natural-language commands routed through a permission-checked action bridge. Reads run immediately, drafts + actions need owner approval."
      />

      <Card title="Ask BridGistic">
        <form onSubmit={submit} className="flex items-center gap-2">
          <input
            className="input"
            value={input}
            onChange={e => setInput(e.target.value)}
            placeholder='e.g. "show pending FFL transfers"'
            disabled={busy}
          />
          <button className="btn-primary" type="submit" disabled={busy}>
            {busy ? '…' : 'Ask'}
          </button>
        </form>

        <div className="mt-3 flex flex-wrap gap-2">
          {SUGGESTIONS.map(s => (
            <button
              key={s}
              type="button"
              onClick={() => setInput(s.replace(/&apos;/g, "'"))}
              className="text-xs px-2.5 py-1 rounded-full bg-ink-100 text-ink-700 hover:bg-ink-200"
              dangerouslySetInnerHTML={{ __html: s }}
            />
          ))}
        </div>

        {error && (
          <div className="mt-3 text-sm text-rose-700 bg-rose-50 border border-rose-100 rounded-lg px-3 py-2">
            {error}
          </div>
        )}
      </Card>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-6">
        <Card title="Permission model" className="lg:col-span-1">
          <ul className="text-sm space-y-2">
            <Rule label="Read analytics" ok />
            <Rule label="Draft report / email" ok />
            <Rule label="Create task" ok />
            <Rule label="Send email" approval />
            <Rule label="Change booking / order / member" approval />
            <Rule label="Refund / payment / POS" manual />
            <Rule label="Modify plugin / settings" manual />
          </ul>
        </Card>

        <Card title="Recent commands" className="lg:col-span-2">
          {history.length === 0 ? (
            <div className="text-sm text-ink-500">Ask BridGistic anything above.</div>
          ) : (
            <ul className="space-y-3">
              {history.map(h => (
                <li key={h.ts} className="rounded-lg border border-ink-100 p-3">
                  <div className="flex items-center gap-2">
                    <span className={badgeFor(h.category)}>{h.category}</span>
                    <span className="text-xs text-ink-500">
                      {new Date(h.ts).toLocaleTimeString()}
                    </span>
                    {h.requiresApproval && (
                      <span className="ml-auto pill-amber">Approval required</span>
                    )}
                  </div>
                  <div className="mt-2 font-medium text-ink-800">{h.query}</div>
                  <div className="mt-1 text-sm text-ink-600">{h.answer}</div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      <Card title="Pending owner approval" subtitle="Actions waiting to execute" className="mt-6">
        {pending.length === 0 ? (
          <div className="text-sm text-ink-500">
            The queue is empty. Actions BridGistic classifies as `action` land here for you to approve.
          </div>
        ) : (
          <ul className="divide-y divide-ink-100">
            {pending.map(a => (
              <li key={a.id} className="py-3 flex items-center gap-3">
                <span className="pill-red">action</span>
                <div className="min-w-0 flex-1">
                  <div className="text-sm font-medium text-ink-800 truncate">{a.query}</div>
                  <div className="text-xs text-ink-500">
                    queued {new Date(a.createdAt).toLocaleString()}
                  </div>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                  <button className="btn-secondary text-xs" onClick={() => void reject(a.id)} disabled={busy}>
                    Reject
                  </button>
                  <button className="btn-primary text-xs" onClick={() => void approve(a.id)} disabled={busy}>
                    Approve
                  </button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}

function badgeFor(category: 'read' | 'draft' | 'action') {
  if (category === 'action') return 'pill-red'
  if (category === 'draft') return 'pill-amber'
  return 'pill-green'
}

function Rule({
  label,
  ok,
  approval,
  manual,
}: {
  label: string
  ok?: boolean
  approval?: boolean
  manual?: boolean
}) {
  const badge = ok ? 'pill-green' : approval ? 'pill-amber' : manual ? 'pill-red' : 'pill-gray'
  const text = ok ? 'auto' : approval ? 'approval' : manual ? 'manual' : '—'
  return (
    <li className="flex items-center justify-between">
      <span className="text-ink-700">{label}</span>
      <span className={badge}>{text}</span>
    </li>
  )
}
