import { useEffect, useState } from 'react';
import { get, post, errorMessage } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Waiver {
  id: number;
  source: string;
  unique_ref?: string;
  first_name: string;
  last_name: string;
  email?: string;
  phone?: string;
  dob?: string;
  age?: number;
  participant_type: string;
  waiver_date?: string;
  signed_on?: string;
  certificate_url?: string;
  certificate_attachment_id?: number | null;
  check_in_count: number;
  status: string;
}

interface ImportResult {
  ok: boolean;
  error?: string;
  stats?: {
    rows_total?: number;
    rows_created?: number;
    rows_updated?: number;
    rows_failed?: number;
  };
}

export default function Waivers() {
  const [rows, setRows] = useState<Waiver[]>([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState({ q: '', email: '', phone: '', from: '', to: '' });
  const [busy, setBusy] = useState<string | null>(null);
  const [importResult, setImportResult] = useState<ImportResult | null>(null);

  const load = async () => {
    setLoading(true);
    try {
      const r = await get<{ ok: boolean; waivers: Waiver[] }>('/range/waivers', {
        q: filter.q,
        email: filter.email,
        phone: filter.phone,
        from: filter.from || undefined,
        to: filter.to || undefined,
        limit: 100,
      });
      setRows(r.waivers || []);
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => { load(); }, []); // eslint-disable-line

  const importCsv = async (file: File) => {
    setBusy('import');
    setImportResult(null);
    try {
      const b64 = await fileToBase64(file);
      const r = await post<ImportResult>('/range/waivers/import', { csv_b64: b64 });
      setImportResult(r);
      await load();
    } catch (e) {
      setImportResult({ ok: false, error: errorMessage(e, 'failed') });
    } finally {
      setBusy(null);
    }
  };

  const checkin = async (id: number) => {
    setBusy(`checkin-${id}`);
    try {
      await post(`/range/waivers/${id}/checkin`);
      await load();
    } finally {
      setBusy(null);
    }
  };

  const mirrorPdf = async (id: number) => {
    setBusy(`pdf-${id}`);
    try {
      await post(`/range/waivers/${id}/mirror-pdf`);
      await load();
    } finally {
      setBusy(null);
    }
  };

  const cols: Column<Waiver>[] = [
    { key: 'id', label: 'ID', width: '60px' },
    {
      key: 'name', label: 'Name', render: (r) => (
        <div>
          <div className="font-medium">{r.first_name} {r.last_name}</div>
          <div className="text-xs text-zinc-500">{r.dob} · {r.age} yrs</div>
        </div>
      ),
    },
    {
      key: 'contact', label: 'Contact', render: (r) => (
        <div className="text-xs">
          {r.email && <div>{r.email}</div>}
          {r.phone && <div className="text-zinc-500">{r.phone}</div>}
        </div>
      ),
    },
    { key: 'participant_type', label: 'Type' },
    { key: 'waiver_date', label: 'Signed', render: (r) => (r.waiver_date ? r.waiver_date.replace('T', ' ').substring(0, 16) : '—') },
    {
      key: 'check_in_count', label: 'Check-ins', align: 'right', render: (r) => (
        <span className="rounded bg-zinc-200 px-1.5 py-0.5 text-xs dark:bg-zinc-800">{r.check_in_count}</span>
      ),
    },
    {
      key: 'actions', label: '', render: (r) => (
        <div className="flex items-center gap-2">
          <button className="btn-secondary text-xs" disabled={busy === `checkin-${r.id}`} onClick={() => checkin(r.id)}>
            {busy === `checkin-${r.id}` ? '…' : '✓ Check in'}
          </button>
          {r.certificate_url && !r.certificate_attachment_id && (
            <button className="btn-secondary text-xs" disabled={busy === `pdf-${r.id}`} onClick={() => mirrorPdf(r.id)}>
              {busy === `pdf-${r.id}` ? '…' : '📄 Save PDF'}
            </button>
          )}
          {r.certificate_attachment_id && (
            <span className="text-xs text-emerald-600">PDF #{r.certificate_attachment_id}</span>
          )}
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title="Range waivers"
        subtitle="OtterWaiver-compatible waiver storage with PDF certificate mirroring and per-customer check-ins."
        actions={
          <>
            <label className="btn-primary cursor-pointer">
              {busy === 'import' ? 'Importing…' : 'Import CSV'}
              <input
                type="file"
                accept=".csv,text/csv"
                className="hidden"
                onChange={(e) => e.target.files?.[0] && importCsv(e.target.files[0])}
              />
            </label>
          </>
        }
      />

      {importResult && (
        <div className={`card mb-4 border-l-4 p-4 text-sm ${importResult.ok ? 'border-emerald-500 text-emerald-700' : 'border-rose-500 text-rose-700'}`}>
          {importResult.ok
            ? `Imported ${importResult.stats?.rows_total ?? 0} rows · ${importResult.stats?.rows_created ?? 0} created · ${importResult.stats?.rows_updated ?? 0} updated · ${importResult.stats?.rows_failed ?? 0} failed`
            : `Import failed: ${importResult.error || 'unknown'}`}
        </div>
      )}

      <div className="card mb-4 p-4">
        <div className="grid grid-cols-1 gap-3 md:grid-cols-6">
          <input className="input md:col-span-2" placeholder="Search (name / email / phone)" value={filter.q} onChange={(e) => setFilter({ ...filter, q: e.target.value })} />
          <input className="input" placeholder="Email exact" value={filter.email} onChange={(e) => setFilter({ ...filter, email: e.target.value })} />
          <input className="input" placeholder="Phone exact" value={filter.phone} onChange={(e) => setFilter({ ...filter, phone: e.target.value })} />
          <input className="input" type="date" value={filter.from} onChange={(e) => setFilter({ ...filter, from: e.target.value })} />
          <input className="input" type="date" value={filter.to} onChange={(e) => setFilter({ ...filter, to: e.target.value })} />
          <div className="md:col-span-6 flex justify-end">
            <button className="btn-primary" onClick={load}>Apply</button>
          </div>
        </div>
      </div>

      <DataTable rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No waivers yet. Import an OtterWaiver CSV above." />
    </>
  );
}

function fileToBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => {
      const r = String(reader.result);
      resolve(r.includes(',') ? r.split(',', 2)[1] : r);
    };
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}
