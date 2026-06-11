import { useEffect, useState } from 'react';
import { get } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Entry {
  id: number;
  entry_number: number;
  entry_type: string;
  manufacturer: string;
  model: string;
  serial_number: string;
  firearm_type: string;
  caliber: string;
  acq_date?: string;
  disp_date?: string;
  acq_source_name?: string;
  disp_buyer_name?: string;
}

export default function BoundBook() {
  const [rows, setRows] = useState<Entry[]>([]);
  const [loading, setLoading] = useState(true);
  const [serial, setSerial] = useState('');
  const [chain, setChain] = useState<{ ok?: boolean; rows_checked?: number; broken?: number[] } | null>(null);

  const load = async () => {
    setLoading(true);
    try {
      const r = await get<{ entries: Entry[] }>('/atf/bound-book', { serial, per_page: 100 });
      setRows(r.entries || []);
    } finally { setLoading(false); }
  };
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { load(); }, []);

  const verifyChain = async () => {
    const r = await get<{ ok: boolean; rows_checked: number; broken: number[] }>('/atf/bound-book/verify');
    setChain(r);
  };

  const cols: Column<Entry>[] = [
    { key: 'entry_number', label: '#', width: '70px', render: (r) => <span className="font-mono">{r.entry_number}</span> },
    { key: 'entry_type', label: 'Type', render: (r) => <EntryBadge type={r.entry_type} /> },
    { key: 'manufacturer', label: 'Mfr / Model', render: (r) => <span><span className="font-medium">{r.manufacturer}</span> {r.model}</span> },
    { key: 'serial_number', label: 'Serial', render: (r) => <span className="font-mono">{r.serial_number}</span> },
    { key: 'firearm_type', label: 'Firearm type' },
    { key: 'caliber', label: 'Caliber' },
    { key: 'acq_date', label: 'Acquired' },
    { key: 'disp_date', label: 'Disposed' },
  ];

  return (
    <div>
      <PageHeader
        title="ATF Bound Book (A&D Record)"
        subtitle="The dealer's internal acquisition and disposition log required under 27 CFR 478.125. This is NOT a customer-facing form — it is the FFL's permanent firearm inventory record, hash-chained for audit integrity. The 4473 (ATF Form 5300.9) is a separate transaction record kept per transfer."
        actions={
          <>
            <a className="btn-secondary" href={window.G2A_POS_ADMIN?.restBase + '/reports/bound-book.csv?_wpnonce=' + window.G2A_POS_ADMIN?.nonce}>Export CSV</a>
            <button className="btn-primary" onClick={verifyChain}>Verify chain</button>
          </>
        }
      />

      {chain && (
        <div className={`mb-4 rounded-lg border px-4 py-3 text-sm ${chain.ok ? 'border-emerald-300 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-900/20 dark:text-emerald-300' : 'border-rose-300 bg-rose-50 text-rose-700 dark:border-rose-900 dark:bg-rose-900/20 dark:text-rose-300'}`}>
          {chain.ok
            ? `Chain verified — ${chain.rows_checked} rows are intact.`
            : `Tamper detected on rows: ${(chain.broken || []).join(', ')}.`}
        </div>
      )}

      <div className="card mb-4 flex gap-2 p-4">
        <input className="input" placeholder="Filter by serial number" value={serial} onChange={(e) => setSerial(e.target.value)} />
        <button className="btn-secondary" onClick={load}>Search</button>
      </div>

      <DataTable<Entry> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No entries yet." />
    </div>
  );
}

function EntryBadge({ type }: { type: string }) {
  const cls = type === 'acquisition' ? 'badge-green' : 'badge-amber';
  return <span className={cls}>{type}</span>;
}
