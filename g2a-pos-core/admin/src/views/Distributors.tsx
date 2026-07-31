import { useEffect, useState } from 'react';
import { get, post, del } from '../api';
import PageHeader from '../components/PageHeader';
import { useDialogs } from '../components/Dialogs';
import { useAction, ActionFeedback } from '../components/useAction';

interface Distributor {
  id: number;
  adapter: string;
  name: string;
  source_type: string;
  endpoint_url?: string;
  schedule: string;
  default_markup_pct: number | string;
  auto_publish: number;
  enabled: number;
  last_synced_at?: string;
  credentials?: Record<string, string>;
}

interface SyncRun {
  id: number;
  started_at: string;
  finished_at?: string;
  status: string;
  rows_seen: number;
  rows_created: number;
  rows_updated: number;
  rows_skipped: number;
  errors_count: number;
  error_summary?: string;
}

interface Adapter { slug: string; label: string; }

export default function Distributors() {
  const dialogs = useDialogs();
  const { run, error, notice } = useAction();
  const [items, setItems] = useState<Distributor[]>([]);
  const [adapters, setAdapters] = useState<Adapter[]>([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState<Partial<Distributor> | null>(null);
  const [runs, setRuns] = useState<Record<number, SyncRun[]>>({});

  const load = async () => {
    setLoading(true);
    try {
      const [d, a] = await Promise.all([
        get<{ distributors: Distributor[] }>('/inventory/distributors'),
        get<{ adapters: Adapter[] }>('/inventory/import/adapters'),
      ]);
      setItems(d.distributors || []);
      setAdapters(a.adapters || []);
    } finally { setLoading(false); }
  };

  useEffect(() => { queueMicrotask(load); }, []);

  const loadRuns = async (id: number) => {
    const r = await get<{ runs: SyncRun[] }>(`/inventory/distributors/${id}/runs`);
    setRuns((s) => ({ ...s, [id]: r.runs || [] }));
  };

  const save = async () => {
    if (!editing) return;
    if (editing.id) {
      await post(`/inventory/distributors/${editing.id}`, editing);
    } else {
      await post('/inventory/distributors', editing);
    }
    setEditing(null);
    await load();
  };

  const remove = async (id: number) => {
    const ok = await dialogs.confirm({
      title: 'Delete this distributor configuration?',
      body: 'Stored credentials for this distributor are removed.',
      danger: true,
      confirmLabel: 'Delete',
    });
    if (!ok) return;
    await run(async () => {
      await del(`/inventory/distributors/${id}`);
      await load();
    }, 'Distributor deleted.');
  };

  const syncNow = async (id: number) => {
    await post(`/inventory/distributors/${id}/sync`, {});
    await load();
    await loadRuns(id);
  };

  return (
    <div>
      <PageHeader
        title="Distributors"
        subtitle="Lipsey's, RSR, Davidson's, Sports South. Daily cron syncs enabled feeds; manual sync available per-row."
        actions={
          <button className="btn-primary" onClick={() => setEditing({ adapter: 'lipseys', name: '', source_type: 'http', schedule: 'daily', enabled: 1 })}>
            Add distributor
          </button>
        }
      />

      <ActionFeedback error={error} notice={notice} />

      {loading && <div className="card p-6 text-sm text-zinc-500">Loading…</div>}

      {!loading && items.length === 0 && (
        <div className="card p-6 text-sm text-zinc-500 dark:text-zinc-400">
          No distributors configured. Add one above. Without credentials you can still drop a CSV at <code>uploads/g2a-distributor.csv</code> and point <strong>endpoint_url</strong> at the absolute path with source type <code>local_file</code>.
        </div>
      )}

      <div className="space-y-4">
        {items.map((d) => (
          <div key={d.id} className="card p-5">
            <div className="flex items-start justify-between">
              <div>
                <h3 className="text-base font-semibold">{d.name}</h3>
                <div className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                  <span className="font-mono">{d.adapter}</span> · {d.source_type} · {d.schedule} ·
                  markup {d.default_markup_pct}% · {d.auto_publish ? 'auto-publish' : 'draft'} ·
                  {d.enabled ? <span className="badge-green ml-1">enabled</span> : <span className="badge-zinc ml-1">disabled</span>}
                </div>
                {d.endpoint_url && <div className="mt-2 break-all font-mono text-xs text-zinc-500 dark:text-zinc-400">{d.endpoint_url}</div>}
                {d.last_synced_at && <div className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Last synced {d.last_synced_at}</div>}
              </div>
              <div className="flex gap-2">
                <button className="btn-secondary" onClick={() => loadRuns(d.id)}>History</button>
                <button className="btn-secondary" onClick={() => syncNow(d.id)}>Sync now</button>
                <button className="btn-secondary" onClick={() => setEditing(d)}>Edit</button>
                <button className="text-sm text-rose-600 hover:underline" onClick={() => remove(d.id)}>Delete</button>
              </div>
            </div>
            {runs[d.id] && (
              <table className="mt-4 w-full text-xs">
                <thead className="text-left text-zinc-500"><tr>
                  <th className="py-1">Started</th>
                  <th>Status</th>
                  <th className="text-right">Seen</th>
                  <th className="text-right">New</th>
                  <th className="text-right">Upd</th>
                  <th className="text-right">Skip</th>
                  <th className="text-right">Err</th>
                  <th>Summary</th>
                </tr></thead>
                <tbody>
                  {runs[d.id].length === 0 && <tr><td colSpan={8} className="py-2 text-zinc-500">No runs yet.</td></tr>}
                  {runs[d.id].map((r) => (
                    <tr key={r.id} className="border-t border-zinc-100 dark:border-zinc-800">
                      <td className="py-1 font-mono">{r.started_at}</td>
                      <td><span className={r.status === 'success' ? 'badge-green' : r.status === 'failed' ? 'badge-red' : 'badge-amber'}>{r.status}</span></td>
                      <td className="text-right">{r.rows_seen}</td>
                      <td className="text-right">{r.rows_created}</td>
                      <td className="text-right">{r.rows_updated}</td>
                      <td className="text-right">{r.rows_skipped}</td>
                      <td className="text-right">{r.errors_count}</td>
                      <td className="text-zinc-500">{r.error_summary ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        ))}
      </div>

      {editing && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="presentation" onClick={() => setEditing(null)}>
          <div className="card w-full max-w-xl p-6" role="dialog" aria-modal="true" aria-labelledby="distributor-dialog-title" onClick={(e) => e.stopPropagation()}>
            <h3 id="distributor-dialog-title" className="mb-4 text-lg font-semibold">{editing.id ? 'Edit distributor' : 'Add distributor'}</h3>
            <div className="grid gap-3">
              <Field label="Name"><input className="input" value={editing.name || ''} onChange={(e) => setEditing({ ...editing, name: e.target.value })} /></Field>
              <Field label="Adapter">
                <select className="input" value={editing.adapter || ''} onChange={(e) => setEditing({ ...editing, adapter: e.target.value })}>
                  {adapters.map((a) => <option key={a.slug} value={a.slug}>{a.label}</option>)}
                </select>
              </Field>
              <Field label="Source type">
                <select className="input" value={editing.source_type || 'http'} onChange={(e) => setEditing({ ...editing, source_type: e.target.value })}>
                  <option value="http">HTTP / HTTPS</option>
                  <option value="local_file">Local file path</option>
                </select>
              </Field>
              <Field label="Endpoint URL / path">
                <input className="input" value={editing.endpoint_url || ''} onChange={(e) => setEditing({ ...editing, endpoint_url: e.target.value })} placeholder="https://feed.example.com/inventory.csv or /var/www/uploads/feed.csv" />
              </Field>
              <Field label="Schedule">
                <select className="input" value={editing.schedule || 'daily'} onChange={(e) => setEditing({ ...editing, schedule: e.target.value })}>
                  <option value="hourly">Hourly</option>
                  <option value="daily">Daily</option>
                  <option value="manual">Manual only</option>
                </select>
              </Field>
              <Field label="Markup over cost (%)">
                <input type="number" step="0.01" className="input" value={editing.default_markup_pct ?? 0} onChange={(e) => setEditing({ ...editing, default_markup_pct: parseFloat(e.target.value) || 0 })} />
              </Field>
              <Field label="Basic auth (optional)">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                  <input className="input" placeholder="username" onChange={(e) => setEditing({ ...editing, credentials: { ...(editing.credentials || {}), basic_user: e.target.value } })} />
                  <input className="input" type="password" placeholder="password" onChange={(e) => setEditing({ ...editing, credentials: { ...(editing.credentials || {}), basic_pass: e.target.value } })} />
                </div>
              </Field>
              <Field label="Bearer token (optional)">
                <input className="input" onChange={(e) => setEditing({ ...editing, credentials: { ...(editing.credentials || {}), bearer: e.target.value } })} />
              </Field>
              <div className="flex gap-4">
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!editing.auto_publish} onChange={(e) => setEditing({ ...editing, auto_publish: e.target.checked ? 1 : 0 })} /> Auto-publish</label>
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!editing.enabled} onChange={(e) => setEditing({ ...editing, enabled: e.target.checked ? 1 : 0 })} /> Enabled</label>
              </div>
            </div>
            <div className="mt-6 flex justify-end gap-2">
              <button className="btn-secondary" onClick={() => setEditing(null)}>Cancel</button>
              <button className="btn-primary" onClick={save}>Save</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <label className="text-sm"><div className="mb-1 font-medium">{label}</div>{children}</label>;
}
