import { useEffect, useState } from 'react';
import { get } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Form4473 {
  id: number;
  form_serial: string;
  transferee_last: string;
  transferee_first: string;
  transferee_state: string;
  transaction_status: string;
  nics_response?: string;
  nics_exempt?: number;
  pos_order_id?: number | null;
  transferred_at?: string;
  created_at: string;
}

export default function Forms4473() {
  const [rows, setRows] = useState<Form4473[]>([]);
  const [loading, setLoading] = useState(true);
  const [q, setQ] = useState('');
  const [status, setStatus] = useState('');

  const refresh = async () => {
    setLoading(true);
    try {
      const r = await get<{ items: Form4473[] }>('/atf/4473', { q, transaction_status: status, limit: 200 });
      setRows(r.items || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { refresh(); },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [status]);

  const cfg = window.G2A_POS_ADMIN;

  const cols: Column<Form4473>[] = [
    { key: 'form_serial', label: 'Form #', render: (r) => <span className="font-mono">{r.form_serial}</span> },
    { key: 'transferee', label: 'Transferee', render: (r) => `${r.transferee_last}, ${r.transferee_first}` },
    { key: 'transferee_state', label: 'State' },
    { key: 'transaction_status', label: 'Status', render: (r) => <StatusBadge status={r.transaction_status} /> },
    { key: 'nics', label: 'NICS', render: (r) => r.nics_exempt
      ? <span className="badge-amber">exempt (CCW)</span>
      : (r.nics_response ? <span className="badge-zinc">{r.nics_response}</span> : '—') },
    { key: 'pos_order_id', label: 'Order', render: (r) => r.pos_order_id ? <a className="link" href={`#orders`}>#{r.pos_order_id}</a> : '—' },
    { key: 'transferred_at', label: 'Transferred' },
    { key: 'created_at', label: 'Created' },
    { key: 'pdf', label: '', width: '170px', align: 'right',
      render: (r) => (
        <div className="flex gap-1 justify-end">
          <a className="btn-sm" href={`${cfg?.restBase}/atf/4473/${r.id}/pdf?_wpnonce=${encodeURIComponent(cfg?.nonce || '')}`} target="_blank" rel="noopener noreferrer">PDF</a>
          <a className="btn-sm" href={`${cfg?.restBase}/atf/4473/${r.id}/xml?_wpnonce=${encodeURIComponent(cfg?.nonce || '')}`} target="_blank" rel="noopener noreferrer">XML</a>
        </div>
      ) },
  ];

  return (
    <div>
      <PageHeader
        title="ATF Form 4473 — Firearms Transaction Record"
        subtitle="Officially designated ATF Form 4473 / Form 5300.9 (same form, two names). Completed at the counter for every retail firearm transfer: Section A (transferor/FFL), Section B (transferee identity, citizenship, prohibited-person questions), Section C (NICS), Section D (transferor cert), Section E (transferee cert). 20-year retention per 27 CFR 478.129. Continuation: Form 5300.9A for >3 firearms." />
      <div className="card p-3 mb-4 flex gap-2">
        <input className="input flex-1" placeholder="Search last name / form #" value={q}
          onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && refresh()} />
        <select className="input" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All statuses</option>
          <option value="in_progress">in_progress</option>
          <option value="awaiting_nics">awaiting_nics</option>
          <option value="transferred">transferred</option>
          <option value="voided">voided</option>
          <option value="denied">denied</option>
        </select>
        <button className="btn-primary" onClick={refresh}>Search</button>
      </div>
      <DataTable<Form4473> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No 4473s started yet." />
    </div>
  );
}

function StatusBadge({ status }: { status: string }) {
  const cls = status === 'transferred' ? 'badge-green' : status === 'denied' || status === 'voided' ? 'badge-red' : 'badge-amber';
  return <span className={cls}>{status}</span>;
}
