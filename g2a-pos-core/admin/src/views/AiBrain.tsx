import { useEffect, useState } from 'react';
import { api, get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Doc {
  id: number;
  source_type: string;
  source_label: string;
  source_uri: string | null;
  chunk_count: number;
  tags: string;
  scope: string;
  ingested_at: string;
}

interface TextForm {
  scope: string;
  label?: string;
  body?: string;
  tags?: string;
}

interface UrlForm {
  scope: string;
  url?: string;
  label?: string;
}

interface IngestResult {
  ok?: boolean;
  document_id?: number;
  chunks?: number;
  embedded?: boolean;
  error?: string;
}

interface SearchHit {
  id: number;
  source_label: string;
  score?: number;
  text_content?: string;
}

interface SiteRefreshResult {
  ok?: boolean;
  default_sections?: number;
  default_pack?: number;
  live_sections?: number;
  products?: number;
  chunks?: number;
  live_source?: string | null;
}

interface SeedDefaultsResult {
  ok?: boolean;
  documents?: number;
  chunks?: number;
  skipped?: number;
}

interface BrainStats {
  documents: number;
  chunks: number;
  embedded_chunks: number;
  embedded_pct: number;
  by_source_type: { source_type: string; documents: number; chunks: number }[];
  backend: string;
  last_refresh_at?: string | null;
  cloudflare?: { ok?: boolean; vectors?: number; error?: string };
}

export default function AiBrain() {
  const [rows, setRows] = useState<Doc[]>([]);
  const [stats, setStats] = useState<BrainStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshingSite, setRefreshingSite] = useState(false);
  const [seeding, setSeeding] = useState(false);
  const [siteResult, setSiteResult] = useState<string>('');
  const [tab, setTab] = useState<'text' | 'url'>('text');
  const [textForm, setTextForm] = useState<TextForm>({ scope: 'staff' });
  const [urlForm, setUrlForm] = useState<UrlForm>({ scope: 'staff' });
  const [searchQ, setSearchQ] = useState('');
  const [searchResults, setSearchResults] = useState<SearchHit[]>([]);
  const [searchNotice, setSearchNotice] = useState<string>('');

  const refresh = async () => {
    setLoading(true);
    try {
      const r = await get<{ items: Doc[] }>('/ai/brain');
      setRows(r.items || []);
      try {
        setStats(await get<BrainStats>('/ai/brain/stats'));
      } catch { /* stats are decorative — never block the doc list */ }
    } finally { setLoading(false); }
  };
  useEffect(() => { queueMicrotask(refresh); }, []);

  const ingestText = async () => {
    if (!textForm.label || !textForm.body) return;
    const r = await post<IngestResult>('/ai/brain/ingest-text', textForm);
    alert(`Ingested document ${r.document_id} with ${r.chunks} chunk(s). Embeddings: ${r.embedded ? 'yes' : 'no (text fallback)'}`);
    setTextForm({ scope: 'staff' });
    await refresh();
  };

  const ingestUrl = async () => {
    if (!urlForm.url) return;
    const r = await post<IngestResult>('/ai/brain/ingest-url', urlForm);
    if (!r.ok) { alert('Failed: ' + (r.error ?? '?')); return; }
    alert(`Crawled ${urlForm.url} → document ${r.document_id} with ${r.chunks} chunk(s)`);
    setUrlForm({ scope: 'staff' });
    await refresh();
  };

  const remove = async (id: number) => {
    if (!confirm('Delete this document and all its chunks?')) return;
    await api('DELETE', `/ai/brain/${id}`);
    await refresh();
  };

  const refreshSite = async () => {
    setRefreshingSite(true);
    setSiteResult('');
    try {
      const r = await post<SiteRefreshResult>('/ai/brain/refresh-site');
      setSiteResult(
        `Website knowledge refreshed: ${r.default_sections ?? 0} curated sections, ` +
        `${r.default_pack ?? 0} default-pack docs, ` +
        `${r.live_sections ?? 0} live site sections (${r.live_source || 'llms-full.txt unavailable'}), ` +
        `${r.products ?? 0} products, ` +
        `${r.chunks ?? 0} chunks. Unchanged documents are skipped (hash-gated); embeddings run automatically when configured.`
      );
      await refresh();
    } catch {
      setSiteResult('Refresh failed — check the AI gateway settings and try again.');
    } finally {
      setRefreshingSite(false);
    }
  };

  const seedDefaults = async () => {
    setSeeding(true);
    setSiteResult('');
    try {
      const r = await post<SeedDefaultsResult>('/ai/brain/seed-defaults');
      setSiteResult(
        `Guns2Ammo defaults seeded: ${r.documents ?? 0} documents (${r.skipped ?? 0} unchanged, skipped), ` +
        `${r.chunks ?? 0} chunks. Covers identity/hours, memberships, training, instructors and facility facts.`
      );
      await refresh();
    } catch {
      setSiteResult('Seeding failed — check the AI gateway settings and try again.');
    } finally {
      setSeeding(false);
    }
  };

  const runSearch = async () => {
    if (!searchQ) return;
    const r = await get<{ hits: SearchHit[]; backend?: string; notice?: string | null }>('/ai/brain/search', { q: searchQ, k: 8 });
    setSearchResults(r.hits || []);
    setSearchNotice([r.backend ? `backend: ${r.backend}` : '', r.notice || ''].filter(Boolean).join(' — '));
  };

  const cols: Column<Doc>[] = [
    { key: 'source_type', label: 'Type', render: (r) => <span className="badge-zinc">{r.source_type}</span> },
    { key: 'source_label', label: 'Label' },
    { key: 'chunk_count', label: 'Chunks', align: 'right' },
    { key: 'scope', label: 'Scope' },
    { key: 'tags', label: 'Tags' },
    { key: 'ingested_at', label: 'Ingested' },
    { key: 'actions', label: '', render: (r) => <button className="btn-sm" onClick={() => remove(r.id)}>Delete</button> },
  ];

  return (
    <div>
      <PageHeader
        title="AI Brain"
        subtitle="Ingest documents (PDFs, URLs, store policies, ATF guides, training material) into the agent's RAG knowledge base."
        actions={
          <>
            <button className="btn-secondary" onClick={seedDefaults} disabled={seeding || refreshingSite}>
              {seeding ? 'Seeding…' : 'Seed Guns2Ammo defaults'}
            </button>
            <button className="btn-secondary" onClick={refreshSite} disabled={refreshingSite || seeding}>
              {refreshingSite ? 'Refreshing…' : '↻ Refresh website knowledge'}
            </button>
          </>
        }
      />

      {stats && (
        <div className="card p-4 mb-4 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3 text-sm">
          <div>
            <div className="text-xs text-zinc-500">Documents</div>
            <div className="text-lg font-semibold">{stats.documents}</div>
          </div>
          <div>
            <div className="text-xs text-zinc-500">Chunks</div>
            <div className="text-lg font-semibold">{stats.chunks}</div>
          </div>
          <div>
            <div className="text-xs text-zinc-500">Embedded</div>
            <div className="text-lg font-semibold">
              {stats.backend === 'cloudflare'
                ? `${stats.cloudflare?.ok ? stats.cloudflare.vectors ?? 0 : '?'} vectors (CF)`
                : `${stats.embedded_pct}%`}
            </div>
          </div>
          <div>
            <div className="text-xs text-zinc-500">Backend</div>
            <div className="text-lg font-semibold">{stats.backend}</div>
          </div>
          <div>
            <div className="text-xs text-zinc-500">Last refresh</div>
            <div className="text-lg font-semibold">{stats.last_refresh_at || 'never'}</div>
          </div>
          <div className="col-span-full text-xs text-zinc-600 dark:text-zinc-400">
            {stats.by_source_type.map((t) => `${t.source_type}: ${t.documents} docs / ${t.chunks} chunks`).join(' · ')}
          </div>
        </div>
      )}

      {siteResult && (
        <div className="card mb-4 border-l-4 border-brand p-4 text-sm">{siteResult}</div>
      )}

      <div className="mb-4 flex gap-2">
        <button className={tab === 'text' ? 'btn-primary' : 'btn'} onClick={() => setTab('text')}>Paste text</button>
        <button className={tab === 'url' ? 'btn-primary' : 'btn'} onClick={() => setTab('url')}>Crawl URL</button>
      </div>

      {tab === 'text' && (
        <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
          <input className="input" placeholder="Label (e.g. CCW class outline)" value={textForm.label ?? ''} onChange={(e) => setTextForm({...textForm, label: e.target.value})} />
          <select className="input" value={textForm.scope} onChange={(e) => setTextForm({...textForm, scope: e.target.value})}>
            <option value="public">Public</option>
            <option value="staff">Staff</option>
            <option value="admin">Admin only</option>
          </select>
          <input className="input col-span-2" placeholder="Tags (comma-separated)" value={textForm.tags ?? ''} onChange={(e) => setTextForm({...textForm, tags: e.target.value})} />
          <textarea className="input col-span-2" rows={6} placeholder="Paste the text to ingest…"
            value={textForm.body ?? ''} onChange={(e) => setTextForm({...textForm, body: e.target.value})} />
          <button className="btn-primary col-span-2" onClick={ingestText}>Ingest</button>
        </div>
      )}

      {tab === 'url' && (
        <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
          <input className="input col-span-2" placeholder="https://example.com/policy.html" value={urlForm.url ?? ''} onChange={(e) => setUrlForm({...urlForm, url: e.target.value})} />
          <input className="input" placeholder="Label override (optional)" value={urlForm.label ?? ''} onChange={(e) => setUrlForm({...urlForm, label: e.target.value})} />
          <select className="input" value={urlForm.scope} onChange={(e) => setUrlForm({...urlForm, scope: e.target.value})}>
            <option value="public">Public</option>
            <option value="staff">Staff</option>
            <option value="admin">Admin only</option>
          </select>
          <button className="btn-primary col-span-2" onClick={ingestUrl}>Crawl & ingest</button>
        </div>
      )}

      <div className="card p-4 mb-4 flex gap-2">
        <input className="input flex-1" placeholder="Search the brain…" value={searchQ} onChange={(e) => setSearchQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && runSearch()} />
        <button className="btn" onClick={runSearch}>Search</button>
      </div>
      {searchResults.length > 0 && (
        <div className="card p-4 mb-4">
          <h4 className="font-semibold mb-2">Top results ({searchResults.length}){searchNotice && <span className="ml-2 text-xs font-normal text-zinc-500">{searchNotice}</span>}</h4>
          <ul className="space-y-2 text-sm">
            {searchResults.map((h) => (
              <li key={h.id} className="border-b border-zinc-100 pb-2 last:border-0">
                <div className="text-xs text-zinc-500">[{h.source_label}] {h.score !== undefined && `score=${h.score.toFixed(3)}`}</div>
                <div>{(h.text_content || '').slice(0, 400)}…</div>
              </li>
            ))}
          </ul>
        </div>
      )}

      <DataTable<Doc> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="Brain is empty. Ingest some documents above." />
    </div>
  );
}
