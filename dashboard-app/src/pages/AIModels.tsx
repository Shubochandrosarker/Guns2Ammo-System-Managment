import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { useAsync } from '@/lib/hooks'
import { api } from '@/lib/api'
import type { ModelConnection } from '@/types/analytics'

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

export function AIModelsRAGs() {
  const q = useAsync(() => api.models.list(), [])
  if (q.loading) return <Spinner />
  const models = q.data ?? []

  return (
    <div>
      <PageHeader
        eyebrow="Providers, keys, routing, RAG stores"
        title="AI Models & RAGs"
        subtitle="Connect Anthropic, OpenAI, Gemini, OpenRouter, local Ollama endpoints and custom RAG stores. Never expose keys in the frontend — this UI reads/writes through the API only."
        actions={<button className="btn-primary text-sm">+ Add connection</button>}
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
              {models.map(m => (
                <tr key={m.id} className="border-t border-ink-100">
                  <td className="px-4 py-3 capitalize font-medium">{m.provider}</td>
                  <td className="px-4 py-3">{m.displayName}</td>
                  <td className="px-4 py-3 font-mono text-xs text-ink-600">{m.modelName}</td>
                  <td className="px-4 py-3 text-right text-ink-600">{m.contextLimit.toLocaleString()}</td>
                  <td className="px-4 py-3"><span className={COST_STYLE[m.costLevel]}>{m.costLevel}</span></td>
                  <td className="px-4 py-3 text-ink-600">{m.useCase}</td>
                  <td className="px-4 py-3 font-mono text-xs text-ink-500">{m.keyMasked}</td>
                  <td className="px-4 py-3"><span className={STATUS_STYLE[m.status]}>{m.status}</span></td>
                  <td className="px-4 py-3 text-right">
                    <button className="btn-ghost text-xs">Test</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">
        <Card title="Model routing" subtitle="Which model handles what">
          <ul className="space-y-2 text-sm">
            <Row label="Deep business analysis"    value="Claude Opus 4.7" />
            <Row label="SEO analysis"              value="Claude Opus 4.7" />
            <Row label="Booking suggestions"       value="GPT-5.5 Turbo"    />
            <Row label="Customer support classify" value="Gemini Pro 2"     />
            <Row label="Email drafts"              value="Qwen 2.5"         />
            <Row label="Cheap daily summaries"     value="Qwen 2.5"         />
            <Row label="Private inventory"         value="Local Llama 3.1"  />
          </ul>
        </Card>

        <Card title="RAG stores" subtitle="Vector indexes powering agents">
          <ul className="space-y-2 text-sm">
            <Row label="Product catalog RAG"       value="pgvector · 1,204 docs" />
            <Row label="Membership plans RAG"      value="pgvector · 36 docs"    />
            <Row label="Training curriculum RAG"   value="pgvector · 84 docs"    />
            <Row label="FAQ + policies RAG"        value="pgvector · 172 docs"   />
            <Row label="Historical support RAG"    value="pgvector · 3,410 docs" />
          </ul>
          <div className="mt-4 text-xs text-ink-500">
            RAG chunks are indexed by the g2a-business-api plugin. Rebuild is
            nightly; on-demand rebuild lives in Settings.
          </div>
        </Card>
      </div>
    </div>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <li className="flex items-center justify-between">
      <span className="text-ink-700">{label}</span>
      <span className="font-medium text-ink-800">{value}</span>
    </li>
  )
}
