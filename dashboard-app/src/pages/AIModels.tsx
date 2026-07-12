import { useEffect, useRef, useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { useAsync, useDialogA11y } from '@/lib/hooks'
import { api, type ModelCatalogEntry, type ModelPatch, type ModelTestResult } from '@/lib/api'
import type { BrainStatsData, ModelConnection } from '@/types/analytics'
import { cn } from '@/lib/cn'
import { formatNumber } from '@/lib/format'

const COST_STYLE: Record<ModelConnection['costLevel'], string> = {
  free:   'pill-green',
  low:    'pill-blue',
  medium: 'pill-amber',
  high:   'pill-red',
}

const STATUS_STYLE: Record<ModelConnection['status'], string> = {
  ok:       'pill-green',
  error:    'pill-red',
  untested: 'pill-gray',
}

const PROVIDERS: Array<ModelConnection['provider']> = [
  'anthropic', 'openai', 'gemini', 'openrouter', 'ollama', 'custom',
]

interface TestState {
  running: boolean
  result: ModelTestResult | null
}

type EditorState = { mode: 'create' } | { mode: 'edit'; model: ModelConnection } | null

export function AIModelsRAGs() {
  const q = useAsync(() => api.models.list(), [])
  const [models, setModels] = useState<ModelConnection[] | null>(null)
  const [results, setResults] = useState<Record<string, TestState>>({})
  const [editor, setEditor] = useState<EditorState>(null)
  const [busyDelete, setBusyDelete] = useState<string | null>(null)

  useEffect(() => {
    if (q.data && !models) setModels(q.data)
  }, [q.data, models])

  if (q.loading && !models) return <Spinner />
  const rows = models ?? []

  async function runTest(m: ModelConnection) {
    setResults(prev => ({ ...prev, [m.id]: { running: true, result: null } }))
    try {
      const result = await api.models.test(m.id)
      setResults(prev => ({ ...prev, [m.id]: { running: false, result } }))
    } catch (err) {
      setResults(prev => ({
        ...prev,
        [m.id]: {
          running: false,
          result: { ok: false, provider: m.provider, error: err instanceof Error ? err.message : String(err) },
        },
      }))
    }
  }

  async function del(m: ModelConnection) {
    if (!confirm(`Delete ${m.displayName}? This also removes the stored API key.`)) return
    setBusyDelete(m.id)
    try {
      await api.models.remove(m.id)
      setModels(rows.filter(x => x.id !== m.id))
    } catch (err) {
      alert(err instanceof Error ? err.message : String(err))
    } finally {
      setBusyDelete(null)
    }
  }

  async function saveEditor(patch: ModelPatch) {
    if (!editor) return
    if (editor.mode === 'create') {
      const created = await api.models.create(patch)
      setModels([created, ...rows])
    } else {
      const updated = await api.models.update(editor.model.id, patch)
      setModels(rows.map(m => (m.id === updated.id ? updated : m)))
    }
    setEditor(null)
  }

  return (
    <div>
      <PageHeader
        eyebrow="Providers, keys, routing, RAG stores"
        title="AI Models & RAGs"
        subtitle="Connect Anthropic, OpenAI, Gemini, OpenRouter, local Ollama endpoints and custom RAG stores. Never expose keys in the frontend — this UI reads/writes through the API only."
        actions={
          <button className="btn-primary text-sm" onClick={() => setEditor({ mode: 'create' })}>
            + Add connection
          </button>
        }
      />

      <Card title="Connected models" bodyClassName="p-0">
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-ink-50 text-xs uppercase tracking-wide text-ink-500">
              <tr>
                <th className="text-left px-4 py-2">Provider</th>
                <th className="text-left px-4 py-2">Display name</th>
                <th className="text-left px-4 py-2">Model</th>
                <th className="text-right px-4 py-2">Context</th>
                <th className="text-left px-4 py-2">Cost</th>
                <th className="text-left px-4 py-2">Use case</th>
                <th className="text-left px-4 py-2">Key</th>
                <th className="text-left px-4 py-2">Status</th>
                <th className="text-right px-4 py-2"></th>
              </tr>
            </thead>
            <tbody>
              {rows.map(m => {
                const state = results[m.id]
                return (
                  <tr key={m.id} className="border-t border-ink-100 align-top">
                    <td className="px-4 py-3 capitalize font-medium">{m.provider}</td>
                    <td className="px-4 py-3">{m.displayName}</td>
                    <td className="px-4 py-3 font-mono text-xs text-ink-600">{m.modelName}</td>
                    <td className="px-4 py-3 text-right text-ink-600">{m.contextLimit.toLocaleString()}</td>
                    <td className="px-4 py-3"><span className={COST_STYLE[m.costLevel]}>{m.costLevel}</span></td>
                    <td className="px-4 py-3 text-ink-600">{m.useCase}</td>
                    <td className="px-4 py-3 font-mono text-xs text-ink-500">{m.keyMasked}</td>
                    <td className="px-4 py-3">
                      <div className="flex flex-col gap-1">
                        <span className={STATUS_STYLE[m.status]}>{m.status}</span>
                        {state?.result && <TestResultBadge result={state.result} />}
                      </div>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <div className="flex flex-wrap items-center justify-end gap-1">
                        <button className="btn-ghost text-xs" onClick={() => void runTest(m)} disabled={state?.running}>
                          {state?.running ? 'Testing…' : 'Test'}
                        </button>
                        <button className="btn-ghost text-xs" onClick={() => setEditor({ mode: 'edit', model: m })}>
                          Edit
                        </button>
                        <button
                          className="btn-ghost text-xs"
                          style={{ color: 'var(--danger)' }}
                          onClick={() => void del(m)}
                          disabled={busyDelete === m.id}
                        >
                          {busyDelete === m.id ? '…' : 'Delete'}
                        </button>
                      </div>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </Card>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">
        <RoutingCard models={rows} />

        <RagStoresCard />
      </div>

      {editor && (
        <ConnectionEditor
          initial={editor.mode === 'edit' ? editor.model : undefined}
          onClose={() => setEditor(null)}
          onSave={saveEditor}
          onRotateKey={editor.mode === 'edit'
            ? async apiKey => {
                const { keyMasked } = await api.models.setKey(editor.model.id, apiKey)
                setModels(rows.map(m => (m.id === editor.model.id ? { ...m, keyMasked } : m)))
              }
            : undefined}
        />
      )}
    </div>
  )
}

function RoutingCard({ models }: { models: ModelConnection[] }) {
  const q = useAsync(() => api.routing.get(), [])
  const [routing, setRouting] = useState<Record<string, string | null> | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (q.data && !routing) setRouting(q.data.routing)
  }, [q.data, routing])

  if (q.loading || !q.data) {
    return (
      <Card title="Model routing" subtitle="Which model handles what">
        <Spinner label="Loading routing…" />
      </Card>
    )
  }

  const purposes = q.data.purposes
  const labels   = q.data.labels

  async function pick(purpose: string, modelId: string) {
    setBusy(purpose)
    setError(null)
    try {
      const patch: Record<string, string | null> = { [purpose]: modelId === '' ? null : modelId }
      const fresh = await api.routing.update(patch)
      setRouting(fresh)
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setBusy(null)
    }
  }

  return (
    <Card title="Model routing" subtitle="Which model handles what">
      {error && (
        <div className="text-xs rounded-lg px-3 py-2 bg-rose-50 text-rose-800 border border-rose-100 mb-3">
          {error}
        </div>
      )}
      <ul className="space-y-2 text-sm">
        {purposes.map(p => (
          <li key={p} className="flex items-center justify-between gap-2">
            <span className="text-ink-700">{labels[p] ?? p}</span>
            <select
              className="input !py-1 !text-xs max-w-[220px]"
              value={routing?.[p] ?? ''}
              disabled={busy === p}
              onChange={e => void pick(p, e.target.value)}
            >
              <option value="">— unset —</option>
              {models.map(m => (
                <option key={m.id} value={m.id}>{m.displayName}</option>
              ))}
            </select>
          </li>
        ))}
      </ul>
    </Card>
  )
}

/**
 * The dashboard already has a fully working RAG backend — g2a-pos-core's
 * shared AI Brain, bridged in-process by g2a-business-api's Brain_Client
 * (GET /brain/stats), which reports the real backend (`cloudflare` when
 * the cloudflare-rag-worker is configured, `local` otherwise) plus real
 * document/chunk counts. This card used to hardcode "not configured"
 * regardless of that live state; it now reads it, matching the same
 * api.brain.stats() surface the AI Agents page already uses.
 */
function RagStoresCard() {
  const stats = useAsync(() => api.brain.stats(), [])
  const data = stats.data

  return (
    <Card title="RAG stores" subtitle="Vector indexes powering agents">
      {stats.loading ? (
        <Spinner label="Checking knowledge brain…" />
      ) : !data || !data.ok ? (
        <div className="text-sm text-ink-600">
          <div>No RAG stores are configured yet.</div>
          <div className="mt-2 text-xs text-ink-500">
            RAG stores will appear here once a knowledge backend is connected
            to the g2a-business-api plugin.
          </div>
        </div>
      ) : (
        <RagStatsGrid data={data.results} />
      )}
    </Card>
  )
}

function RagStatsGrid({ data }: { data: BrainStatsData }) {
  return (
    <div>
      <div className="grid grid-cols-2 gap-3">
        <MiniStat label="Documents" value={formatNumber(data.documents)} />
        <MiniStat label="Chunks"    value={formatNumber(data.chunks)} />
        <MiniStat label="Embedded"  value={`${data.embedded_pct.toFixed(1)}%`} />
        <MiniStat label="Backend"   value={data.backend === 'cloudflare' ? 'Cloudflare' : 'Local'} />
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
      <div className="text-sm font-semibold text-ink-800 mt-0.5">{value}</div>
    </div>
  )
}

function TestResultBadge({ result }: { result: ModelTestResult }) {
  if (result.ok) {
    return (
      <span
        className={cn('text-[11px] rounded px-2 py-0.5 self-start whitespace-nowrap',
          'bg-emerald-50 text-emerald-800 border border-emerald-100')}
        title={`${result.provider}${result.probe ? ` · ${result.probe}` : ''}`}
      >
        ✓ {result.latencyMs ?? '—'}ms
      </span>
    )
  }
  return (
    <span
      className={cn('text-[11px] rounded px-2 py-0.5 self-start max-w-[180px] truncate',
        'bg-rose-50 text-rose-800 border border-rose-100')}
      title={result.error ?? 'Failed'}
    >
      ✗ {result.error ?? 'Failed'}
    </span>
  )
}

function ConnectionEditor({
  initial,
  onClose,
  onSave,
  onRotateKey,
}: {
  initial?: ModelConnection
  onClose: () => void
  onSave: (patch: ModelPatch) => Promise<void>
  onRotateKey?: (apiKey: string) => Promise<void>
}) {
  const [values, setValues] = useState<ModelPatch>({
    provider:     initial?.provider    ?? 'anthropic',
    displayName:  initial?.displayName  ?? '',
    modelName:    initial?.modelName    ?? '',
    apiBaseUrl:   initial?.apiBaseUrl   ?? '',
    contextLimit: initial?.contextLimit ?? 128_000,
    costLevel:    initial?.costLevel    ?? 'medium',
    useCase:      initial?.useCase      ?? '',
  })
  const [apiKey, setApiKey] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [catalog, setCatalog] = useState<ModelCatalogEntry[] | null>(null)
  const dialogRef = useRef<HTMLDivElement>(null)
  useDialogA11y(dialogRef, onClose)

  // The provider's model list makes the modelName field a picker instead of
  // guesswork. Only possible for saved connections (the catalog call needs
  // the stored key) — and if the fetch fails, free text still works.
  useEffect(() => {
    if (!initial) return
    let cancelled = false
    api.models.catalog(initial.id)
      .then(res => {
        if (!cancelled && res.ok && res.models.length > 0) setCatalog(res.models)
      })
      .catch(() => {
        /* degrade to free text */
      })
    return () => {
      cancelled = true
    }
  }, [initial])

  async function save() {
    setBusy(true)
    setError(null)
    try {
      const patch: ModelPatch = { ...values }
      if (!initial && apiKey.trim()) patch.apiKey = apiKey.trim()
      await onSave(patch)
      if (initial && apiKey.trim() && onRotateKey) await onRotateKey(apiKey.trim())
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="fixed inset-0 z-30" style={{ backgroundColor: 'var(--bg-overlay)' }} onClick={onClose}>
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-label={initial ? 'Edit connection' : 'New connection'}
        tabIndex={-1}
        className="absolute inset-y-0 right-0 shadow-xl w-full sm:max-w-md overflow-y-auto"
        style={{ backgroundColor: 'var(--bg-surface)' }}
        onClick={e => e.stopPropagation()}
      >
        <div
          className="sticky top-0 px-4 sm:px-5 py-3 flex items-center justify-between"
          style={{ backgroundColor: 'var(--bg-surface)', borderBottom: '1px solid var(--border-subtle)' }}
        >
          <div>
            <div className="text-[11px] uppercase tracking-wide text-ink-500">
              {initial ? 'Edit connection' : 'New connection'}
            </div>
            <div className="font-semibold text-ink-800">{initial?.displayName ?? 'Add model'}</div>
          </div>
          <button onClick={onClose} className="btn-ghost !px-2">✕</button>
        </div>

        <div className="p-4 sm:p-5 space-y-3 text-sm">
          <Field label="Provider">
            <select
              className="input"
              value={values.provider}
              onChange={e => setValues({ ...values, provider: e.target.value as ModelPatch['provider'] })}
            >
              {PROVIDERS.map(p => <option key={p} value={p}>{p}</option>)}
            </select>
          </Field>
          <Field label="Display name">
            <input className="input" value={values.displayName ?? ''} onChange={e => setValues({ ...values, displayName: e.target.value })} />
          </Field>
          <Field
            label="Model name"
            hint={catalog ? 'Suggestions come from the provider — free text also works.' : undefined}
          >
            <input
              className="input"
              list={catalog ? 'g2a-model-catalog' : undefined}
              value={values.modelName ?? ''}
              onChange={e => setValues({ ...values, modelName: e.target.value })}
            />
            {catalog && (
              <datalist id="g2a-model-catalog">
                {catalog.map(m => (
                  <option key={m.id} value={m.id}>{m.name}</option>
                ))}
              </datalist>
            )}
          </Field>
          <Field label="API base URL" hint="Required for Ollama + custom providers.">
            <input className="input" value={values.apiBaseUrl ?? ''} onChange={e => setValues({ ...values, apiBaseUrl: e.target.value })} />
          </Field>
          <Field label="Context limit (tokens)">
            <input
              type="number"
              className="input"
              value={values.contextLimit ?? 0}
              onChange={e => setValues({ ...values, contextLimit: Number(e.target.value) })}
            />
          </Field>
          <Field label="Cost">
            <select
              className="input"
              value={values.costLevel}
              onChange={e => setValues({ ...values, costLevel: e.target.value as ModelPatch['costLevel'] })}
            >
              <option value="free">free</option>
              <option value="low">low</option>
              <option value="medium">medium</option>
              <option value="high">high</option>
            </select>
          </Field>
          <Field label="Use case">
            <input className="input" value={values.useCase ?? ''} onChange={e => setValues({ ...values, useCase: e.target.value })} />
          </Field>
          <Field label={initial ? 'Rotate API key (leave blank to keep current)' : 'API key'}
                 hint="Encrypted at rest via AUTH_KEY-derived AES-256-GCM.">
            <input
              type="password"
              className="input"
              autoComplete="off"
              value={apiKey}
              onChange={e => setApiKey(e.target.value)}
              placeholder={initial ? initial.keyMasked : ''}
            />
          </Field>

          {error && (
            <div className="text-sm rounded-lg px-3 py-2 bg-rose-50 text-rose-800 border border-rose-100">
              {error}
            </div>
          )}

          <div className="flex items-center gap-2 pt-2">
            <button className="btn-primary text-sm" onClick={() => void save()} disabled={busy}>
              {busy ? 'Saving…' : initial ? 'Save changes' : 'Create'}
            </button>
            <button className="btn-secondary text-sm" onClick={onClose} disabled={busy}>Cancel</button>
          </div>
        </div>
      </div>
    </div>
  )
}

function Field({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="text-xs font-medium uppercase tracking-wide text-ink-500">{label}</span>
      <div className="mt-1">{children}</div>
      {hint && <div className="mt-1 text-xs text-ink-500">{hint}</div>}
    </label>
  )
}
