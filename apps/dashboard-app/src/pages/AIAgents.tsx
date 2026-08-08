import { useEffect, useRef, useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { useAsync, useDialogA11y } from '@/lib/hooks'
import { api, type AgentHistoryEntry, type PromptVersion } from '@/lib/api'
import type { Agent, BrainQueryResult, BrainStatsData } from '@/types/analytics'
import { cn } from '@/lib/cn'
import { formatNumber } from '@/lib/format'

const STATUS_STYLE: Record<Agent['status'], string> = {
  active:        'pill-green',
  paused:        'pill-gray',
  needs_review:  'pill-amber',
}

type DrawerKind = 'history' | 'prompt'

interface DrawerState {
  agent: Agent
  kind: DrawerKind
}

export function AIAgents() {
  const q = useAsync(() => api.agents.list(), [])
  const [drawer, setDrawer] = useState<DrawerState | null>(null)

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
              <Row label="Model"      value={a.model} />
              <Row label="Assigned"   value={`${a.assignedTasks} task${a.assignedTasks === 1 ? '' : 's'}`} />
              <Row label="Last run"   value={a.lastRun ? new Date(a.lastRun).toLocaleString() : '—'} />
              <Row label="Confidence" value={`${Math.round(a.confidence * 100)}%`} />
            </div>

            <div className="mt-3 rounded-lg bg-ink-50 p-3 text-sm text-ink-700">
              <div className="text-[11px] uppercase tracking-wide text-ink-500 mb-1">
                Last output
              </div>
              {a.lastOutput}
            </div>

            <div className="mt-4 flex flex-wrap items-center gap-2">
              <button
                className="btn-primary text-xs"
                onClick={() => void api.agents.run(a.id)}
              >
                Run now
              </button>
              <button
                className="btn-secondary text-xs"
                onClick={() => setDrawer({ agent: a, kind: 'history' })}
              >
                History
              </button>
              <button
                className="btn-ghost text-xs"
                onClick={() => setDrawer({ agent: a, kind: 'prompt' })}
              >
                Edit prompt
              </button>
              {a.reviewRequired && (
                <span className="ml-auto pill-amber">Review required</span>
              )}
            </div>
          </Card>
        ))}
      </div>

      <div className="mt-6">
        <BrainSection />
      </div>

      {drawer && (
        <Drawer
          agent={drawer.agent}
          kind={drawer.kind}
          onClose={() => setDrawer(null)}
        />
      )}
    </div>
  )
}

function BrainSection() {
  const stats = useAsync(() => api.brain.stats(), [])
  const data = stats.data

  return (
    <Card
      title="Business Knowledge Brain"
      subtitle="The shared knowledge store every agent reads from — g2a-pos-core's AI Brain"
    >
      {stats.loading ? (
        <Spinner label="Checking knowledge brain…" />
      ) : !data || !data.ok ? (
        <div className="text-sm text-ink-600 bg-ink-50 border border-ink-100 rounded-lg p-3">
          <div className="font-medium text-ink-800">Knowledge brain not active</div>
          <div className="mt-1 text-xs text-ink-500">
            Connect the in-store AI plugin to enable the shared knowledge brain.
          </div>
        </div>
      ) : (
        <BrainStatsGrid data={data.results} />
      )}

      <div className="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">
        <BrainIngestForm onIngested={() => stats.refresh()} />
        <BrainSearchTool />
      </div>
    </Card>
  )
}

function BrainStatsGrid({ data }: { data: BrainStatsData }) {
  return (
    <div>
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <MiniStat label="Documents" value={formatNumber(data.documents)} />
        <MiniStat label="Chunks"    value={formatNumber(data.chunks)} />
        <MiniStat label="Embedded"  value={`${data.embedded_pct.toFixed(1)}% (${formatNumber(data.embedded_chunks)})`} />
        <MiniStat label="Backend"   value={data.backend} />
      </div>
      {data.by_source_type.length > 0 && (
        <div className="mt-4">
          <div className="text-[11px] uppercase tracking-wide text-ink-500 mb-1.5">By source type</div>
          <ul className="text-sm space-y-1">
            {data.by_source_type.map(t => (
              <li key={t.source_type} className="flex items-center justify-between">
                <span className="text-ink-700">{t.source_type}</span>
                <span className="font-medium text-ink-800">
                  {formatNumber(t.documents)} doc{t.documents === 1 ? '' : 's'} · {formatNumber(t.chunks)} chunk{t.chunks === 1 ? '' : 's'}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}

function MiniStat({ label, value }: { label: string; value: string }) {
  return (
    <div className="bg-ink-50 rounded-lg px-3 py-2">
      <div className="text-[11px] uppercase tracking-wide text-ink-500">{label}</div>
      <div className="text-base font-semibold text-ink-800">{value}</div>
    </div>
  )
}

function BrainIngestForm({ onIngested }: { onIngested: () => void }) {
  const [label, setLabel] = useState('')
  const [body, setBody] = useState('')
  const [tags, setTags] = useState('')
  const [busy, setBusy] = useState(false)
  const [status, setStatus] = useState<{ ok: boolean; msg: string } | null>(null)

  const bodyTrimmed = body.trim()
  const disabled = busy || label.trim() === '' || bodyTrimmed.length < 10

  async function submit() {
    setBusy(true)
    setStatus(null)
    try {
      const res = await api.brain.ingest(label.trim(), bodyTrimmed, { tags: tags.trim() || undefined })
      if (!res.ok) {
        // Outer ok:false — g2a-pos-core isn't active (or threw).
        setStatus({
          ok: false,
          msg: res.reason === 'pos_brain_inactive'
            ? 'Connect the in-store AI plugin to enable the shared knowledge brain.'
            : res.reason,
        })
      } else if (!res.results.ok) {
        // Inner ok:false — brain is active, but this specific ingest failed
        // (e.g. body normalized to empty).
        setStatus({ ok: false, msg: res.results.error })
      } else {
        setStatus({
          ok: true,
          msg: res.results.skipped
            ? 'Already up to date in the knowledge brain — no changes needed.'
            : `Added to the knowledge brain (${res.results.chunks} chunk${res.results.chunks === 1 ? '' : 's'}, embedded: ${res.results.embedded ? 'yes' : 'no'}).`,
        })
        setLabel('')
        setBody('')
        setTags('')
        onIngested()
      }
    } catch (err) {
      setStatus({ ok: false, msg: err instanceof Error ? err.message : String(err) })
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <div className="text-sm font-semibold text-ink-800 mb-2">Add to knowledge brain</div>
      <div className="space-y-2">
        <input
          className="input"
          placeholder="Label (e.g. Range hours)"
          value={label}
          onChange={e => setLabel(e.target.value)}
        />
        <textarea
          className="input min-h-[100px] text-sm"
          placeholder="Body — at least 10 characters"
          value={body}
          onChange={e => setBody(e.target.value)}
        />
        <input
          className="input"
          placeholder="Tags (optional)"
          value={tags}
          onChange={e => setTags(e.target.value)}
        />
        {bodyTrimmed.length > 0 && bodyTrimmed.length < 10 && (
          <div className="text-xs text-ink-500">Body must be at least 10 characters.</div>
        )}
        <button className="btn-primary text-sm" disabled={disabled} onClick={() => void submit()}>
          {busy ? 'Adding…' : 'Add to knowledge brain'}
        </button>
      </div>
      {status && (
        <div
          className={cn(
            'mt-3 text-sm rounded-lg px-3 py-2',
            status.ok ? 'bg-emerald-50 text-emerald-800 border border-emerald-100'
                      : 'bg-rose-50 text-rose-800 border border-rose-100',
          )}
        >
          {status.msg}
        </div>
      )}
    </div>
  )
}

function BrainSearchTool() {
  const [q, setQ] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [result, setResult] = useState<BrainQueryResult | null>(null)

  async function search() {
    const query = q.trim()
    if (query === '') return
    setBusy(true)
    setError(null)
    try {
      setResult(await api.brain.query(query))
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <div className="text-sm font-semibold text-ink-800 mb-2">Search the knowledge brain</div>
      <div className="flex items-center gap-2">
        <input
          className="input flex-1"
          placeholder="Ask a question…"
          value={q}
          onChange={e => setQ(e.target.value)}
          onKeyDown={e => {
            if (e.key === 'Enter') void search()
          }}
        />
        <button className="btn-secondary text-sm shrink-0" disabled={busy || q.trim() === ''} onClick={() => void search()}>
          {busy ? 'Searching…' : 'Search'}
        </button>
      </div>

      {error && (
        <div className="mt-3 text-sm rounded-lg px-3 py-2 bg-rose-50 text-rose-800 border border-rose-100">
          {error}
        </div>
      )}

      {result && !result.ok && (
        <div className="mt-3 text-sm text-ink-500">
          {result.reason === 'pos_brain_inactive'
            ? 'Connect the in-store AI plugin to enable the shared knowledge brain.'
            : (result.reason ?? 'Search failed.')}
        </div>
      )}

      {result && result.ok && result.results.length === 0 && (
        <div className="mt-3 text-sm text-ink-500">No matches yet.</div>
      )}

      {result && result.ok && result.results.length > 0 && (
        <ul className="mt-3 space-y-2">
          {result.results.map(r => (
            <li key={r.id} className="rounded-lg border border-ink-100 p-3 text-sm">
              {r.source_label && <div className="font-medium text-ink-800">{r.source_label}</div>}
              <div className="text-ink-700 whitespace-pre-wrap">{r.text_content}</div>
              {(typeof r.score === 'number' || typeof r.match_score === 'number') && (
                <div className="mt-1 text-xs text-ink-500">
                  {typeof r.score === 'number' ? `score ${r.score.toFixed(2)}` : `match ${r.match_score}`}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
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

function Drawer({ agent, kind, onClose }: { agent: Agent; kind: DrawerKind; onClose: () => void }) {
  const dialogRef = useRef<HTMLDivElement>(null)
  useDialogA11y(dialogRef, onClose)

  return (
    <div
      className="fixed inset-0 z-30"
      style={{ backgroundColor: 'var(--bg-overlay)' }}
      onClick={onClose}
    >
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-label={kind === 'history' ? 'Run history' : 'Prompt template'}
        tabIndex={-1}
        className={cn(
          'absolute inset-y-0 right-0 shadow-xl',
          'w-full sm:max-w-lg lg:max-w-xl overflow-y-auto',
        )}
        style={{ backgroundColor: 'var(--bg-surface)' }}
        onClick={e => e.stopPropagation()}
      >
        <div
          className="sticky top-0 px-4 sm:px-5 py-3 flex items-center justify-between"
          style={{
            backgroundColor: 'var(--bg-surface)',
            borderBottom: '1px solid var(--border-subtle)',
          }}
        >
          <div>
            <div className="text-[11px] uppercase tracking-wide text-ink-500">
              {kind === 'history' ? 'Run history' : 'Prompt template'}
            </div>
            <div className="font-semibold text-ink-800">{agent.name}</div>
          </div>
          <button onClick={onClose} className="btn-ghost !px-2">✕</button>
        </div>
        <div className="p-4 sm:p-5">
          {kind === 'history' ? <HistoryPane agentId={agent.id} /> : <PromptPane agent={agent} />}
        </div>
      </div>
    </div>
  )
}

function HistoryPane({ agentId }: { agentId: string }) {
  const q = useAsync(() => api.agentHistory.list(agentId), [agentId])
  if (q.loading) return <Spinner label="Loading history…" />
  const entries: AgentHistoryEntry[] = q.data ?? []
  if (entries.length === 0) {
    return (
      <div className="text-sm text-ink-500">
        No history yet. Click <em>Run now</em> to schedule this agent's first run.
      </div>
    )
  }
  return (
    <ul className="space-y-3">
      {entries.map(e => (
        <li key={e.ts} className="rounded-lg border border-ink-100 p-3">
          <div className="flex items-center justify-between text-xs text-ink-500">
            <span>{new Date(e.ts).toLocaleString()}</span>
            <span className="pill-blue">confidence {Math.round(e.confidence * 100)}%</span>
          </div>
          <div className="mt-2 text-sm text-ink-800 whitespace-pre-wrap">{e.output}</div>
        </li>
      ))}
    </ul>
  )
}

function PromptPane({ agent }: { agent: Agent }) {
  const [template, setTemplate] = useState('')
  const [history, setHistory] = useState<PromptVersion[]>([])
  const [loaded, setLoaded] = useState(false)
  const [status, setStatus] = useState<{ ok: boolean; msg: string } | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    let cancelled = false
    void api.agentPrompt
      .get(agent.id)
      .then(res => {
        if (cancelled) return
        setTemplate(res.template)
        setHistory(res.history)
        setLoaded(true)
      })
      .catch(err => {
        if (cancelled) return
        setStatus({ ok: false, msg: err instanceof Error ? err.message : String(err) })
        setLoaded(true)
      })
    return () => {
      cancelled = true
    }
  }, [agent.id])

  async function save() {
    setBusy(true)
    setStatus(null)
    try {
      const res = await api.agentPrompt.set(agent.id, template)
      // Re-fetch history — set_prompt appended the previous version.
      const fresh = await api.agentPrompt.get(agent.id)
      setHistory(fresh.history)
      setStatus({
        ok: true,
        msg: res.placeholder
          ? 'Prompt saved.'
          : 'Prompt saved — reminder: it does not contain the `{{snapshot}}` placeholder, so the runner will not inject data.',
      })
    } catch (err) {
      setStatus({ ok: false, msg: err instanceof Error ? err.message : String(err) })
    } finally {
      setBusy(false)
    }
  }

  if (!loaded) return <Spinner label="Loading prompt…" />

  return (
    <div>
      <p className="text-sm text-ink-600 mb-3">
        Edit the prompt template. Use <code className="font-mono text-xs">{'{{snapshot}}'}</code> as the
        placeholder — the runner replaces it with a JSON analytics snapshot for the last 30 days.
      </p>
      <textarea
        className="input font-mono text-xs min-h-[280px]"
        value={template}
        onChange={e => setTemplate(e.target.value)}
        placeholder={`You are the analyst for Guns2Ammo.\n\nSNAPSHOT:\n{{snapshot}}`}
      />
      <div className="mt-3 flex items-center gap-2">
        <button className="btn-primary text-sm" onClick={() => void save()} disabled={busy || template.trim() === ''}>
          {busy ? 'Saving…' : 'Save prompt'}
        </button>
      </div>
      {status && (
        <div
          className={cn(
            'mt-3 text-sm rounded-lg px-3 py-2',
            status.ok ? 'bg-emerald-50 text-emerald-800 border border-emerald-100'
                      : 'bg-rose-50 text-rose-800 border border-rose-100',
          )}
        >
          {status.msg}
        </div>
      )}

      {history.length > 0 && (
        <div className="mt-6">
          <div className="text-xs font-semibold uppercase tracking-wide text-ink-500 mb-2">
            Previous versions ({history.length})
          </div>
          <ul className="space-y-3">
            {history.map(v => (
              <li key={v.ts} className="rounded-lg border border-ink-100 p-3">
                <div className="flex items-center justify-between text-xs text-ink-500 mb-1">
                  <span>{new Date(v.ts).toLocaleString()}</span>
                  <button
                    className="btn-ghost !py-0.5 !px-2 text-[11px]"
                    onClick={() => setTemplate(v.template)}
                  >
                    Restore
                  </button>
                </div>
                <pre className="font-mono text-[11px] text-ink-700 whitespace-pre-wrap max-h-40 overflow-y-auto">
                  {v.template}
                </pre>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}
