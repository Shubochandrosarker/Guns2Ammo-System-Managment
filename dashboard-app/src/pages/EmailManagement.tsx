import { useCallback, useEffect, useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'
import { Spinner } from '@/components/ui/Spinner'
import { api, type EmailDraft } from '@/lib/api'

const CATEGORIES = [
  { key: 'customer',  label: 'Customer inquiries', count: 26 },
  { key: 'booking',   label: 'Booking emails',     count: 44 },
  { key: 'member',    label: 'Membership emails',  count: 31 },
  { key: 'waiver',    label: 'Waiver emails',      count: 12 },
  { key: 'sales',     label: 'Sales follow-up',    count: 18 },
  { key: 'support',   label: 'Support',            count: 22 },
  { key: 'reviews',   label: 'Review requests',    count:  9 },
  { key: 'internal',  label: 'Internal alerts',    count:  6 },
  { key: 'reports',   label: 'Reports',            count:  4 },
  { key: 'marketing', label: 'Marketing campaigns',count:  3 },
]

export function EmailManagement() {
  const [drafts, setDrafts] = useState<EmailDraft[] | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [inputs, setInputs] = useState<Record<string, string>>({})

  const refresh = useCallback(async () => {
    try {
      const list = await api.emailDrafts.list('pending')
      setDrafts(list)
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    }
  }, [])

  useEffect(() => {
    void refresh()
  }, [refresh])

  async function send(id: string) {
    setBusy(true)
    setError(null)
    try {
      await api.emailDrafts.send(id, { to: inputs[id] || '' })
      await refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  async function discard(id: string) {
    setBusy(true)
    setError(null)
    try {
      await api.emailDrafts.discard(id)
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
        eyebrow="Inbox intelligence"
        title="Email Management"
        subtitle="Auto-classified inbound email plus BridGistic-generated drafts. Nothing goes out until you click Send."
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Unread"        value={38} intent="warn" />
        <StatCard label="AI drafted"    value={drafts?.length ?? 0} intent="success" />
        <StatCard label="Urgent"        value={4}  intent="danger" />
        <StatCard label="Sent last 24h" value={97} />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-6">
        <Card title="Categories" className="lg:col-span-1">
          <ul className="space-y-1.5 text-sm">
            {CATEGORIES.map(c => (
              <li key={c.key} className="flex justify-between hover:bg-ink-50 rounded px-2 py-1 cursor-pointer">
                <span className="text-ink-700">{c.label}</span>
                <span className="pill-gray">{c.count}</span>
              </li>
            ))}
          </ul>
        </Card>

        <Card
          title="Pending owner approval"
          subtitle="BridGistic drafts ready to send"
          className="lg:col-span-2"
        >
          {error && (
            <div className="mb-3 text-sm text-rose-700 bg-rose-50 border border-rose-100 rounded-lg px-3 py-2">
              {error}
            </div>
          )}

          {drafts === null ? (
            <Spinner label="Loading drafts…" />
          ) : drafts.length === 0 ? (
            <div className="text-sm text-ink-500">
              No pending drafts. When BridGistic classifies a request as
              &quot;action&quot; and you approve it, the draft lands here.
            </div>
          ) : (
            <ul className="divide-y divide-ink-100">
              {drafts.map(d => (
                <li key={d.id} className="py-3">
                  <div className="flex items-start gap-3">
                    <span className="pill-amber">draft</span>
                    <div className="min-w-0 flex-1">
                      <div className="text-sm font-medium text-ink-800 truncate">{d.subject}</div>
                      <div className="text-xs text-ink-500">from BridGistic · {new Date(d.createdAt).toLocaleString()}</div>
                    </div>
                  </div>
                  <div className="mt-2 rounded-lg bg-ink-50 border border-ink-100 p-3 text-sm text-ink-700 whitespace-pre-wrap">
                    {d.body}
                  </div>
                  <div className="mt-3 flex flex-col md:flex-row md:items-center gap-2">
                    <input
                      className="input md:flex-1"
                      type="email"
                      placeholder="recipient@example.com"
                      value={inputs[d.id] ?? d.to ?? ''}
                      onChange={e => setInputs(prev => ({ ...prev, [d.id]: e.target.value }))}
                    />
                    <div className="flex items-center gap-2 shrink-0">
                      <button className="btn-secondary text-xs" disabled={busy} onClick={() => void discard(d.id)}>
                        Discard
                      </button>
                      <button className="btn-primary text-xs" disabled={busy} onClick={() => void send(d.id)}>
                        Send
                      </button>
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </div>
  )
}
