import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';

interface Settings {
  mode: 'live' | 'stub';
  chat_endpoint: string;
  embed_endpoint: string;
  chat_model: string;
  embed_model: string;
  api_key: string;
  api_key_configured?: boolean;
  temperature: number;
  max_tokens: number;
  request_timeout: number;
}

interface ToolSpec { type: string; function: { name: string; description: string } }

export default function AiSettings() {
  const [cfg, setCfg] = useState<Settings | null>(null);
  const [tools, setTools] = useState<ToolSpec[]>([]);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    (async () => {
      const r = await get<{ gateway: Settings; tools: ToolSpec[] }>('/ai/settings');
      setCfg(r.gateway);
      setTools(r.tools || []);
    })();
  }, []);

  const save = async () => {
    if (!cfg) return;
    setSaving(true);
    try {
      const r = await post<{ gateway: Settings }>('/ai/settings', cfg);
      setCfg(r.gateway);
    } finally { setSaving(false); }
  };

  if (!cfg) return <div>Loading…</div>;

  return (
    <div>
      <PageHeader title="AI Settings" subtitle="Connect the agent to a local LLM (Ollama / vLLM / any OpenAI-compatible endpoint). Stub mode lets the rest of the stack run without an LLM." />

      <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3 md:grid-cols-4">
        <div>
          <label className="block text-xs mb-1">Mode</label>
          <select className="input w-full" value={cfg.mode} onChange={(e) => setCfg({...cfg, mode: e.target.value as Settings['mode']})}>
            <option value="stub">Stub (no LLM)</option>
            <option value="live">Live (route to endpoint)</option>
          </select>
        </div>
        <div>
          <label className="block text-xs mb-1">Chat model</label>
          <input className="input w-full" value={cfg.chat_model} onChange={(e) => setCfg({...cfg, chat_model: e.target.value})} />
        </div>
        <div>
          <label className="block text-xs mb-1">Embedding model</label>
          <input className="input w-full" value={cfg.embed_model} onChange={(e) => setCfg({...cfg, embed_model: e.target.value})} />
        </div>
        <div>
          <label className="block text-xs mb-1">API key {cfg.api_key_configured ? '(configured — leave blank to keep)' : '(optional)'}</label>
          <input className="input w-full" type="password" value={cfg.api_key} onChange={(e) => setCfg({...cfg, api_key: e.target.value})} />
        </div>
        <div className="md:col-span-2">
          <label className="block text-xs mb-1">Chat endpoint (OpenAI-compatible /v1/chat/completions)</label>
          <input className="input w-full" placeholder="http://ollama:11434/v1/chat/completions"
            value={cfg.chat_endpoint} onChange={(e) => setCfg({...cfg, chat_endpoint: e.target.value})} />
        </div>
        <div className="md:col-span-2">
          <label className="block text-xs mb-1">Embedding endpoint</label>
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
        <div className="md:col-span-4 flex justify-end">
          <button className="btn-primary" onClick={save} disabled={saving}>{saving ? 'Saving…' : 'Save settings'}</button>
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
