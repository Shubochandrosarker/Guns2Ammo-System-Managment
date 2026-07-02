import { FormEvent, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'

interface HistoryItem {
  q: string
  ts: string
  category: 'read' | 'draft' | 'action'
  answer: string
  requiresApproval: boolean
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

// Deterministic classifier so demo behavior is reproducible. The real
// classifier lives inside the g2a-business-api plugin behind /bridgistic/ask.
function classify(q: string): HistoryItem {
  const lower = q.toLowerCase()
  const isWrite = /(send|create|update|change|refund|modify|schedule|book|charge)/.test(lower)
  const isDraft = /(draft|prepare|write)/.test(lower)
  return {
    q,
    ts: new Date().toISOString(),
    category: isWrite ? 'action' : isDraft ? 'draft' : 'read',
    answer: isWrite
      ? 'Action detected. This request requires owner approval before BridGistic will execute it against the WordPress systems.'
      : isDraft
      ? 'Drafted response is ready for owner review. Nothing has been sent.'
      : 'Read-only query — served from the analytics API without any state change.',
    requiresApproval: isWrite,
  }
}

export function BridGistic() {
  const [params] = useSearchParams()
  const [input, setInput] = useState('')
  const [history, setHistory] = useState<HistoryItem[]>([])

  useEffect(() => {
    const q = params.get('q')
    if (q) {
      setHistory(h => [classify(q), ...h])
    }
  }, [params])

  function submit(e: FormEvent) {
    e.preventDefault()
    const q = input.trim()
    if (!q) return
    setHistory(h => [classify(q), ...h])
    setInput('')
  }

  return (
    <div>
      <PageHeader
        eyebrow="Claude command bridge"
        title="BridGistic"
        subtitle="Natural-language commands routed through a permission-checked action bridge. Reads are free, writes need approval."
      />

      <Card title="Ask BridGistic">
        <form onSubmit={submit} className="flex items-center gap-2">
          <input
            className="input"
            value={input}
            onChange={e => setInput(e.target.value)}
            placeholder='e.g. "show pending FFL transfers"'
          />
          <button className="btn-primary" type="submit">Ask</button>
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
                    <span
                      className={
                        h.category === 'action' ? 'pill-red'
                        : h.category === 'draft' ? 'pill-amber'
                        : 'pill-green'
                      }
                    >
                      {h.category}
                    </span>
                    <span className="text-xs text-ink-500">{new Date(h.ts).toLocaleTimeString()}</span>
                    {h.requiresApproval && <span className="ml-auto pill-amber">Approval required</span>}
                  </div>
                  <div className="mt-2 font-medium text-ink-800">{h.q}</div>
                  <div className="mt-1 text-sm text-ink-600">{h.answer}</div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </div>
  )
}

function Rule({ label, ok, approval, manual }: { label: string; ok?: boolean; approval?: boolean; manual?: boolean }) {
  const badge = ok ? 'pill-green' : approval ? 'pill-amber' : manual ? 'pill-red' : 'pill-gray'
  const text  = ok ? 'auto' : approval ? 'approval' : manual ? 'manual' : '—'
  return (
    <li className="flex items-center justify-between">
      <span className="text-ink-700">{label}</span>
      <span className={badge}>{text}</span>
    </li>
  )
}
