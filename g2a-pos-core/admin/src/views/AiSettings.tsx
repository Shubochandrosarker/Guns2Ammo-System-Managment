import { useEffect, useState } from 'react';
import { errorMessage, get, post } from '../api';
import PageHeader from '../components/PageHeader';

interface Settings {
  mode: 'live' | 'stub';
  provider: 'openrouter' | 'openai_compatible' | 'ollama';
  chat_endpoint: string;
  embed_endpoint: string;
  chat_model: string;
  embed_model: string;
  embed_provider: '' | 'openai' | 'ollama' | 'cloudflare' | 'none';
  api_key: string;
  api_key_configured?: boolean;
  embed_api_key: string;
  embed_api_key_configured?: boolean;
  brain_backend: 'local' | 'cloudflare';
  cloudflare_worker_url: string;
  cloudflare_worker_token: string;
  cloudflare_worker_token_configured?: boolean;
  temperature: number;
  max_tokens: number;
  request_timeout: number;
}

interface ToolSpec { type: string; function: { name: string; description: string } }

interface GatewayTestResult {
  ok: boolean;
  error?: string | null;
  provider?: string;
  model?: string;
  content?: string;
  latency_ms?: number | null;
}

interface WorkerTestResult { ok: boolean; error?: string; vectors?: number; dimensions?: number }

interface MigrateResult {
  ok: boolean;
  error?: string;
  total?: number;
  next_offset?: number;
  done?: boolean;
  pushed_documents?: number;
  pushed_chunks?: number;
  failed_documents?: number;
  last_error?: string | null;
}

const PROVIDER_HINTS: Record<Settings['provider'], { endpoint: string; model: string }> = {
  openrouter: { endpoint: 'https://openrouter.ai/api/v1/chat/completions', model: 'anthropic/claude-sonnet-4.5' },
  openai_compatible: { endpoint: 'https://your-host/v1/chat/completions', model: 'your-model-id' },
  ollama: { endpoint: 'http://ollama:11434/v1/chat/completions', model: 'qwen2.5:7b-instruct' },
};

export default function AiSettings() {
  const [cfg, setCfg] = useState<Settings | null>(null);
  const [tools, setTools] = useState<ToolSpec[]>([]);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [testResult, setTestResult] = useState<string>('');
  const [cfTesting, setCfTesting] = useState(false);
  const [cfResult, setCfResult] = useState<string>('');
  const [migrating, setMigrating] = useState(false);
  const [migrateStatus, setMigrateStatus] = useState<string>('');

  useEffect(() => {
    (async () => {
      const r = await get<{ gateway: Settings; tools: ToolSpec[] }>('/ai/settings');
      setCfg(r.gateway);
      setTools(r.tools || []);
    })();
  }, []);

  const save = async (): Promise<Settings | null> => {
    if (!cfg) return null;
    setSaving(true);
    try {
      const r = await post<{ gateway: Settings }>('/ai/settings', cfg);
      setCfg(r.gateway);
      return r.gateway;
    } finally { setSaving(false); }
  };

  const testGateway = async () => {
    setTesting(true);
    setTestResult('');
    try {
      await save(); // test what's on screen, not a stale saved copy
      const r = await post<GatewayTestResult>('/ai/gateway/test');
      setTestResult(r.ok
        ? `✓ Connected — ${r.provider} / ${r.model} replied in ${r.latency_ms ?? '?'}ms`
        : `✗ ${r.error || 'Connection failed'}`);
    } catch (e) {
      setTestResult('✗ ' + errorMessage(e));
    } finally { setTesting(false); }
  };

  const testWorker = async () => {
    setCfTesting(true);
    setCfResult('');
    try {
      await save();
      const r = await post<WorkerTestResult>('/ai/brain/cloudflare/test');
      setCfResult(r.ok
        ? `✓ Worker reachable — ${r.vectors ?? 0} vectors, ${r.dimensions ?? 0} dims`
        : `✗ ${r.error || 'Worker test failed'}`);
    } catch (e) {
      setCfResult('✗ ' + errorMessage(e));
    } finally { setCfTesting(false); }
  };

  const migrate = async () => {
    setMigrating(true);
    setMigrateStatus('Starting migration…');
    try {
      await save();
      let offset = 0;
      let docs = 0;
      let chunks = 0;
      let failed = 0;
      // Batched loop so big brains migrate without hitting PHP time limits.
      for (;;) {
        const r = await post<MigrateResult>('/ai/brain/migrate-cloudflare', { offset, limit: 20 });
        if (!r.ok) { setMigrateStatus(`✗ ${r.error || 'Migration failed'}`); return; }
        docs += r.pushed_documents ?? 0;
        chunks += r.pushed_chunks ?? 0;
        failed += r.failed_documents ?? 0;
        offset = r.next_offset ?? offset + 20;
        setMigrateStatus(`Pushed ${docs} documents (${chunks} chunks) of ${r.total ?? '?'}…${failed ? ` ${failed} failed (${r.last_error})` : ''}`);
        if (r.done) break;
      }
      setMigrateStatus(`✓ Migration complete: ${docs} documents / ${chunks} chunks pushed${failed ? `, ${failed} failed — re-run to retry` : ''}`);
    } catch (e) {
      setMigrateStatus('✗ ' + errorMessage(e));
    } finally { setMigrating(false); }
  };

  if (!cfg) return <div>Loading…</div>;

  const hint = PROVIDER_HINTS[cfg.provider] || PROVIDER_HINTS.openrouter;

  return (
    <div>
      <PageHeader title="AI Settings" subtitle="Pick a provider (OpenRouter recommended for hosted models), paste an API key and test the connection. Stub mode lets the rest of the stack run without an LLM." />

      <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3 md:grid-cols-4">
        <div>
          <label className="block text-xs mb-1">Mode</label>
          <select className="input w-full" value={cfg.mode} onChange={(e) => setCfg({...cfg, mode: e.target.value as Settings['mode']})}>
            <option value="stub">Stub (no LLM)</option>
            <option value="live">Live (route to provider)</option>
          </select>
        </div>
        <div>
          <label className="block text-xs mb-1">Provider</label>
          <select className="input w-full" value={cfg.provider} onChange={(e) => setCfg({...cfg, provider: e.target.value as Settings['provider']})}>
            <option value="openrouter">OpenRouter (recommended)</option>
            <option value="openai_compatible">OpenAI-compatible endpoint</option>
            <option value="ollama">Ollama (local)</option>
          </select>
        </div>
        <div>
          <label className="block text-xs mb-1">Chat model</label>
          <input className="input w-full" placeholder={hint.model} value={cfg.chat_model} onChange={(e) => setCfg({...cfg, chat_model: e.target.value})} />
        </div>
        <div>
          <label className="block text-xs mb-1">API key {cfg.api_key_configured ? '(configured — leave blank to keep)' : '(optional)'}</label>
          <input className="input w-full" type="password" value={cfg.api_key} onChange={(e) => setCfg({...cfg, api_key: e.target.value})} />
        </div>
        <div className="md:col-span-4">
          <label className="block text-xs mb-1">Chat endpoint (leave blank to use the provider default: {hint.endpoint})</label>
          <input className="input w-full" placeholder={hint.endpoint}
            value={cfg.chat_endpoint} onChange={(e) => setCfg({...cfg, chat_endpoint: e.target.value})} />
        </div>
        <div>
          <label className="block text-xs mb-1">Embeddings provider</label>
          <select className="input w-full" value={cfg.embed_provider} onChange={(e) => setCfg({...cfg, embed_provider: e.target.value as Settings['embed_provider']})}>
            <option value="">Custom endpoint (legacy)</option>
            <option value="openai">OpenAI (text-embedding-3-small)</option>
            <option value="ollama">Ollama (nomic-embed-text)</option>
            <option value="cloudflare">Cloudflare Worker (embeds server-side)</option>
            <option value="none">None (keyword search fallback)</option>
          </select>
        </div>
        <div>
          <label className="block text-xs mb-1">Embedding model</label>
          <input className="input w-full" value={cfg.embed_model} onChange={(e) => setCfg({...cfg, embed_model: e.target.value})} />
        </div>
        <div>
          <label className="block text-xs mb-1">Embeddings API key {cfg.embed_api_key_configured ? '(configured)' : '(only for OpenAI embeddings)'}</label>
          <input className="input w-full" type="password" value={cfg.embed_api_key} onChange={(e) => setCfg({...cfg, embed_api_key: e.target.value})} />
        </div>
        <div>
          <label className="block text-xs mb-1">Embedding endpoint (custom / Ollama)</label>
          <input className="input w-full" placeholder="http://ollama:11434/api/embeddings"
            value={cfg.embed_endpoint} onChange={(e) => setCfg({...cfg, embed_endpoint: e.target.value})} />
        </div>
        <div>
          <label className="block text-xs mb-1">Temperature</label>
          <input className="input w-full" type="number" step="0.05" value={cfg.temperature} onChange={(e) => setCfg({...cfg, temperature: parseFloat(e.target.value)})} />
        </div>
        <div>
          <label className="block text-xs mb-1">Max tokens</label>
          <input className="input w-full" type="number" value={cfg.max_tokens} onChange={(e) => setCfg({...cfg, max_tokens: parseInt(e.target.value)})} />
        </div>
        <div>
          <label className="block text-xs mb-1">Timeout (s)</label>
          <input className="input w-full" type="number" value={cfg.request_timeout} onChange={(e) => setCfg({...cfg, request_timeout: parseInt(e.target.value)})} />
        </div>
        <div className="md:col-span-4 flex items-center justify-end gap-2">
          {testResult && <span className="text-sm mr-auto">{testResult}</span>}
          <button className="btn" onClick={testGateway} disabled={testing || saving}>{testing ? 'Testing…' : 'Test connection'}</button>
          <button className="btn-primary" onClick={save} disabled={saving}>{saving ? 'Saving…' : 'Save settings'}</button>
        </div>
      </div>

      <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3 md:grid-cols-4">
        <div className="md:col-span-4">
          <h3 className="font-semibold">Brain backend (RAG storage)</h3>
          <p className="text-xs text-zinc-600 dark:text-zinc-400">
            Local keeps embeddings in MySQL. Cloudflare pushes chunks to the RAG Worker
            (see /cloudflare-rag-worker in the repo) which embeds with Workers AI and searches with
            Vectorize — documents stay stored locally as the fallback either way.
          </p>
        </div>
        <div>
          <label className="block text-xs mb-1">Backend</label>
          <select className="input w-full" value={cfg.brain_backend} onChange={(e) => setCfg({...cfg, brain_backend: e.target.value as Settings['brain_backend']})}>
            <option value="local">Local (MySQL)</option>
            <option value="cloudflare">Cloudflare Worker</option>
          </select>
        </div>
        <div className="md:col-span-2">
          <label className="block text-xs mb-1">Worker URL</label>
          <input className="input w-full" placeholder="https://g2a-rag-worker.your-account.workers.dev"
            value={cfg.cloudflare_worker_url} onChange={(e) => setCfg({...cfg, cloudflare_worker_url: e.target.value})} />
        </div>
        <div>
          <label className="block text-xs mb-1">Worker token {cfg.cloudflare_worker_token_configured ? '(configured — leave blank to keep)' : ''}</label>
          <input className="input w-full" type="password" value={cfg.cloudflare_worker_token} onChange={(e) => setCfg({...cfg, cloudflare_worker_token: e.target.value})} />
        </div>
        <div className="md:col-span-4 flex items-center justify-end gap-2">
          {(cfResult || migrateStatus) && <span className="text-sm mr-auto">{cfResult} {migrateStatus}</span>}
          <button className="btn" onClick={testWorker} disabled={cfTesting || saving}>{cfTesting ? 'Testing…' : 'Test Cloudflare Worker'}</button>
          <button className="btn-secondary" onClick={migrate} disabled={migrating || saving}>{migrating ? 'Migrating…' : 'Migrate brain to Cloudflare'}</button>
        </div>
      </div>

      <div className="card p-4">
        <h3 className="font-semibold mb-3">Registered tools ({tools.length})</h3>
        <ul className="space-y-2 text-sm">
          {tools.map((t) => (
            <li key={t.function.name} className="border-b border-zinc-100 dark:border-zinc-800 pb-2 last:border-0">
              <div className="font-mono text-brand">{t.function.name}</div>
              <div className="text-xs text-zinc-600 dark:text-zinc-400">{t.function.description}</div>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}
