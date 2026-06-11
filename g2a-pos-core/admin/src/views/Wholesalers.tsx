import { useEffect, useState } from 'react';
import { get, post, errorMessage } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Wholesaler {
  id: number;
  provider_code: string;
  display_name: string;
  account_number?: string;
  api_endpoint?: string;
  status: string;
  last_sync_at?: string;
  settings?: Record<string, unknown>;
}

interface SyncRow {
  ok: boolean;
  rows_updated?: number;
  error?: string;
}

const EMPTY_FORM = {
  provider_code: 'lipseys',
  display_name: "Lipsey's — Production",
  account_number: '',
  api_endpoint: 'https://api.lipseys.com',
  status: 'active',
  email: '',
  password: '',
};

export default function Wholesalers() {
  const [items, setItems] = useState<Wholesaler[]>([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState({ ...EMPTY_FORM });
  const [busy, setBusy] = useState<string | null>(null);
  const [syncResult, setSyncResult] = useState<Record<number, SyncRow>>({});
  const [error, setError] = useState<string | null>(null);

  const load = async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await get<{ ok: boolean; wholesalers: Wholesaler[] }>('/wholesalers');
      setItems(res.wholesalers || []);
    } catch (e) {
      setError(errorMessage(e, 'Failed to load wholesalers'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, []);

  const save = async () => {
    setBusy('save');
    setError(null);
    try {
      await post('/wholesalers', {
        provider_code: form.provider_code,
        display_name: form.display_name,
        account_number: form.account_number,
        api_endpoint: form.api_endpoint,
        status: form.status,
        credentials: { email: form.email, password: form.password },
      });
      setForm({ ...EMPTY_FORM });
      await load();
    } catch (e) {
      setError(errorMessage(e, 'Save failed'));
    } finally {
      setBusy(null);
    }
  };

  const runSync = async (id: number) => {
    setBusy(`sync-${id}`);
    try {
      const r = await post<SyncRow>(`/wholesalers/${id}/inventory/sync`);
      setSyncResult((s) => ({ ...s, [id]: r }));
      await load();
    } catch (e) {
      setSyncResult((s) => ({ ...s, [id]: { ok: false, error: errorMessage(e, 'sync failed') } }));
    } finally {
      setBusy(null);
    }
  };

  const cols: Column<Wholesaler>[] = [
    { key: 'id', label: 'ID', width: '60px' },
    { key: 'provider_code', label: 'Provider' },
    { key: 'display_name', label: 'Display Name' },
    { key: 'account_number', label: 'Account' },
    {
      key: 'status', label: 'Status', render: (r) => (
        <span className={`inline-flex rounded px-2 py-0.5 text-xs ${r.status === 'active' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-zinc-200 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'}`}>{r.status}</span>
      ),
    },
    { key: 'last_sync_at', label: 'Last Sync', render: (r) => r.last_sync_at || '—' },
    {
      key: 'actions', label: '', render: (r) => (
        <div className="flex items-center gap-2">
          <button className="btn-secondary" disabled={busy === `sync-${r.id}`} onClick={() => runSync(r.id)}>
            {busy === `sync-${r.id}` ? 'Syncing…' : 'Sync inventory'}
          </button>
          {syncResult[r.id] && (
            <span className={`text-xs ${syncResult[r.id].ok ? 'text-emerald-600' : 'text-rose-600'}`}>
              {syncResult[r.id].ok ? `↻ ${syncResult[r.id].rows_updated ?? 0} rows` : syncResult[r.id].error}
            </span>
          )}
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title="Wholesalers"
        subtitle="Configure distributor API credentials. Credentials are stored AES-256-CBC encrypted, keyed off your WordPress AUTH_KEY."
      />

      {error && <div className="card mb-4 border-l-4 border-rose-500 p-4 text-sm text-rose-600">{error}</div>}

      <DataTable rows={items} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No wholesalers configured yet." />

      <div className="card mt-6 p-6">
        <h3 className="mb-4 text-lg font-semibold">Add / update wholesaler</h3>
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
          <Field label="Provider">
            <select className="input" value={form.provider_code} onChange={(e) => setForm({ ...form, provider_code: e.target.value })}>
              <option value="lipseys">Lipsey&apos;s Inc.</option>
            </select>
          </Field>
          <Field label="Display name">
            <input className="input" value={form.display_name} onChange={(e) => setForm({ ...form, display_name: e.target.value })} />
          </Field>
          <Field label="Account number">
            <input className="input" value={form.account_number} onChange={(e) => setForm({ ...form, account_number: e.target.value })} />
          </Field>
          <Field label="API endpoint">
            <input className="input" value={form.api_endpoint} onChange={(e) => setForm({ ...form, api_endpoint: e.target.value })} />
          </Field>
          <Field label="API email">
            <input className="input" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          </Field>
          <Field label="API password">
            <input className="input" type="password" autoComplete="new-password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} />
          </Field>
          <Field label="Status">
            <select className="input" value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
              <option value="active">active</option>
              <option value="paused">paused</option>
              <option value="disabled">disabled</option>
            </select>
          </Field>
        </div>
        <div className="mt-4 flex justify-end">
          <button className="btn-primary" disabled={busy === 'save'} onClick={save}>
            {busy === 'save' ? 'Saving…' : 'Save wholesaler'}
          </button>
        </div>
      </div>
    </>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1 text-sm">
      <span className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{label}</span>
      {children}
    </label>
  );
}
