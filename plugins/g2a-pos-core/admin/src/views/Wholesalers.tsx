import { useEffect, useState } from 'react';
import { get, post, errorMessage, ApiError } from '../api';
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

interface CatalogStats {
  rows_total?: number;
  rows_created?: number;
  rows_updated?: number;
  rows_skipped?: number;
  rows_failed?: number;
}

interface SyncRow {
  ok: boolean;
  rows_updated?: number;
  stats?: CatalogStats;
  error?: string;
  detail?: string;
}

interface TestRow {
  ok: boolean;
  error?: string;
  detail?: string;
}

const EMPTY_FORM = {
  id: 0,
  provider_code: 'lipseys',
  display_name: "Lipsey's — Production",
  account_number: '',
  api_endpoint: 'https://api.lipseys.com',
  status: 'active',
  email: '',
  password: '',
};

/**
 * Extract a { error, detail } pair from a failed request. The REST layer
 * returns WP_Error bodies ({ code, message, data: { detail: { error, detail } } })
 * for sync failures and plain { ok, error, detail } payloads for
 * test-credentials, so check both shapes.
 */
function apiFailure(e: unknown, fallback: string): { error: string; detail?: string } {
  if (e instanceof ApiError) {
    const p = e.payload as {
      message?: string;
      error?: string;
      detail?: string;
      data?: { detail?: { error?: string; detail?: string } };
    } | null;
    const nested = p?.data?.detail;
    return {
      error: nested?.error || p?.error || p?.message || e.message || fallback,
      detail: nested?.detail || p?.detail || undefined,
    };
  }
  return { error: errorMessage(e, fallback) };
}

export default function Wholesalers() {
  const [items, setItems] = useState<Wholesaler[]>([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState({ ...EMPTY_FORM });
  const [busy, setBusy] = useState<string | null>(null);
  const [syncResult, setSyncResult] = useState<Record<number, SyncRow>>({});
  const [testResult, setTestResult] = useState<Record<number, TestRow>>({});
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
        id: form.id || undefined,
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

  const edit = (r: Wholesaler) => {
    setForm({
      id: r.id,
      provider_code: r.provider_code,
      display_name: r.display_name,
      account_number: r.account_number || '',
      api_endpoint: r.api_endpoint || 'https://api.lipseys.com',
      status: r.status,
      email: '',
      password: '',
    });
  };

  const runSync = async (id: number) => {
    setBusy(`sync-${id}`);
    try {
      const r = await post<SyncRow>(`/wholesalers/${id}/inventory/sync`);
      setSyncResult((s) => ({ ...s, [id]: r }));
      await load();
    } catch (e) {
      setSyncResult((s) => ({ ...s, [id]: { ok: false, ...apiFailure(e, 'sync failed') } }));
    } finally {
      setBusy(null);
    }
  };

  const runCatalogSync = async (id: number) => {
    setBusy(`catalog-${id}`);
    try {
      const r = await post<SyncRow>(`/wholesalers/${id}/catalog/api-sync`);
      setSyncResult((s) => ({ ...s, [id]: r }));
      await load();
    } catch (e) {
      setSyncResult((s) => ({ ...s, [id]: { ok: false, ...apiFailure(e, 'catalog sync failed') } }));
    } finally {
      setBusy(null);
    }
  };

  const runTest = async (id: number) => {
    setBusy(`test-${id}`);
    try {
      const r = await post<TestRow>(`/wholesalers/${id}/test-credentials`);
      setTestResult((s) => ({ ...s, [id]: r }));
    } catch (e) {
      setTestResult((s) => ({ ...s, [id]: { ok: false, ...apiFailure(e, 'credential test failed') } }));
    } finally {
      setBusy(null);
    }
  };

  const syncSummary = (r: SyncRow): string => {
    if (!r.ok) return [r.error, r.detail].filter(Boolean).join(' — ');
    if (r.stats) {
      const s = r.stats;
      return `✓ ${s.rows_total ?? 0} items (created ${s.rows_created ?? 0}, updated ${s.rows_updated ?? 0}, failed ${s.rows_failed ?? 0})`;
    }
    return `↻ ${r.rows_updated ?? 0} rows`;
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
        <div className="flex flex-col gap-1">
          <div className="flex items-center gap-2">
            <button className="btn-secondary" onClick={() => edit(r)}>Edit</button>
            <button className="btn-secondary" disabled={busy === `test-${r.id}`} onClick={() => runTest(r.id)}>
              {busy === `test-${r.id}` ? 'Testing…' : 'Test credentials'}
            </button>
            <button className="btn-secondary" disabled={busy === `sync-${r.id}`} onClick={() => runSync(r.id)}>
              {busy === `sync-${r.id}` ? 'Syncing…' : 'Sync inventory'}
            </button>
            <button className="btn-secondary" disabled={busy === `catalog-${r.id}`} onClick={() => runCatalogSync(r.id)}>
              {busy === `catalog-${r.id}` ? 'Importing…' : 'Sync catalog'}
            </button>
          </div>
          {testResult[r.id] && (
            <span className={`text-xs ${testResult[r.id].ok ? 'text-emerald-600' : 'text-rose-600'}`}>
              {testResult[r.id].ok
                ? '✓ Credentials OK — Lipsey\'s login succeeded'
                : [testResult[r.id].error, testResult[r.id].detail].filter(Boolean).join(' — ')}
            </span>
          )}
          {syncResult[r.id] && (
            <span className={`text-xs ${syncResult[r.id].ok ? 'text-emerald-600' : 'text-rose-600'}`}>
              {syncSummary(syncResult[r.id])}
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
        <h3 className="mb-4 text-lg font-semibold">
          {form.id ? `Edit wholesaler #${form.id}` : 'Add / update wholesaler'}
        </h3>
        <div className="mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
          <strong>Lipsey&apos;s API note:</strong> API integration credentials are <em>separate</em> from your
          dealer portal login and must be enabled by Lipsey&apos;s before they will authenticate. Feed/catalog
          access is a separate entitlement on top of that — if &quot;Test credentials&quot; succeeds but syncs fail
          with <code>lipseys_api_rejected</code>, your account lacks the feed entitlement; contact your
          Lipsey&apos;s rep to enable it.
          {form.id ? ' When editing, leave the email/password blank to keep the stored values.' : ''}
        </div>
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
            <span className="text-xs text-zinc-500 dark:text-zinc-400">
              Required to run more than one account for the same provider (e.g. separate Lipsey&apos;s
              firearms and accessories accounts) — feeds and syncs are matched to accounts by this
              number. Saving without an account number always creates a new entry; it never updates
              an existing one.
            </span>
          </Field>
          <Field label="API endpoint">
            <input className="input" value={form.api_endpoint} onChange={(e) => setForm({ ...form, api_endpoint: e.target.value })} />
          </Field>
          <Field label={form.id ? 'API email (blank = keep stored)' : 'API email'}>
            <input className="input" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          </Field>
          <Field label={form.id ? 'API password (blank = keep stored)' : 'API password'}>
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
        <div className="mt-4 flex justify-end gap-2">
          {form.id > 0 && (
            <button className="btn-secondary" disabled={busy === 'save'} onClick={() => setForm({ ...EMPTY_FORM })}>
              Cancel edit
            </button>
          )}
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
