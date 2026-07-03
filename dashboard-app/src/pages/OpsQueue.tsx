import { useCallback, useEffect, useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { api, type Cancellation } from '@/lib/api'
import { cn } from '@/lib/cn'

type Tab = 'cancellations' | 'audit'

export function OpsQueue() {
  const [tab, setTab] = useState<Tab>('cancellations')
  const [cancellations, setCancellations] = useState<Cancellation[] | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notes, setNotes] = useState<Record<string, string>>({})

  const refresh = useCallback(async () => {
    try {
      const list = await api.cancellations.list('awaiting')
      setCancellations(list)
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    }
  }, [])

  useEffect(() => {
    void refresh()
  }, [refresh])

  async function complete(id: string) {
    setBusy(true)
    setError(null)
    try {
      await api.cancellations.markCompleted(id, notes[id] || '')
      await refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  async function drop(id: string) {
    setBusy(true)
    setError(null)
    try {
      await api.cancellations.drop(id)
      await refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <PageHeader
        eyebrow="Operational review"
        title="Ops Queue"
        subtitle="BridGistic-created work that needs a human step: booking cancellations and, later, other approvals."
      />

      <div className="mb-4 flex gap-2 border-b border-ink-100">
        <TabButton active={tab === 'cancellations'} onClick={() => setTab('cancellations')}>
          Cancellations ({cancellations?.length ?? '—'})
        </TabButton>
        <TabButton active={tab === 'audit'} onClick={() => setTab('audit')}>
          Notes
        </TabButton>
      </div>

      {tab === 'cancellations' ? (
        <Card title="Awaiting manual action" subtitle="Finalize in the Booking Engine, then mark completed here">
          {error && (
            <div className="mb-3 text-sm text-rose-700 bg-rose-50 border border-rose-100 rounded-lg px-3 py-2">
              {error}
            </div>
          )}

          {cancellations === null ? (
            <Spinner label="Loading cancellations…" />
          ) : cancellations.length === 0 ? (
            <div className="text-sm text-ink-500">
              The queue is empty. Approved `cancel_booking` actions land here for you to finalize.
            </div>
          ) : (
            <ul className="divide-y divide-ink-100">
              {cancellations.map(c => (
                <li key={c.id} className="py-3">
                  <div className="flex items-start gap-3">
                    <span className="pill-amber">awaiting</span>
                    <div className="min-w-0 flex-1">
                      <div className="text-sm font-medium text-ink-800">
                        Booking {c.bookingId ? `#${c.bookingId}` : '(id not detected)'}
                      </div>
                      <div className="text-xs text-ink-500">
                        queued {new Date(c.createdAt).toLocaleString()}
                      </div>
                    </div>
                  </div>
                  <div className="mt-2 rounded-lg bg-ink-50 border border-ink-100 p-3 text-sm text-ink-700">
                    {c.query}
                  </div>
                  <div className="mt-3 flex flex-col md:flex-row md:items-center gap-2">
                    <input
                      className="input md:flex-1"
                      type="text"
                      placeholder="Optional notes (e.g. refunded via Stripe)"
                      value={notes[c.id] ?? ''}
                      onChange={e => setNotes(prev => ({ ...prev, [c.id]: e.target.value }))}
                    />
                    <div className="flex items-center gap-2 shrink-0">
                      <button className="btn-secondary text-xs" disabled={busy} onClick={() => void drop(c.id)}>
                        Drop
                      </button>
                      <button className="btn-primary text-xs" disabled={busy} onClick={() => void complete(c.id)}>
                        Mark completed
                      </button>
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      ) : (
        <Card title="Notes">
          <div className="text-sm text-ink-600 space-y-2">
            <p>
              Every send / discard / mark-completed / drop is written to the plugin&apos;s
              audit log (<code className="font-mono text-xs">g2aba_audit_log</code>).
              A dashboard view of that log is coming in a follow-up.
            </p>
            <p>
              For now, view it directly in WP-admin at <em>Settings → G2A · Email Drafts</em>
              and <em>Settings → G2A · Cancellations</em>.
            </p>
          </div>
        </Card>
      )}
    </div>
  )
}

function TabButton({
  active,
  onClick,
  children,
}: { active: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      onClick={onClick}
      className={cn(
        'px-3 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
        active
          ? 'border-brand-500 text-brand-600'
          : 'border-transparent text-ink-500 hover:text-ink-800',
      )}
    >
      {children}
    </button>
  )
}
