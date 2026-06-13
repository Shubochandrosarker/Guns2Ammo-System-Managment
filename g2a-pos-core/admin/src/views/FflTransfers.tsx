import { useEffect, useState } from 'react';
import { errorMessage, get } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Transfer {
  id: number;
  transfer_ref: string;
  order_id: number;
  customer: string;
  email: string;
  dealer: string;
  dealer_city: string;
  dealer_state: string;
  item: string;
  sku: string;
  tracking: string;
  status: string;
  updated_at: string;
}

export default function FflTransfers() {
  const [rows, setRows] = useState<Transfer[]>([]);
  const [active, setActive] = useState(true);
  const [loading, setLoading] = useState(true);
  const [q, setQ] = useState('');
  const [status, setStatus] = useState('');
  const [err, setErr] = useState('');

  const load = async () => {
    setLoading(true);
    setErr('');
    try {
      const r = await get<{ active: boolean; transfers: Transfer[] }>('/integrations/ffl/transfers', { q, status, limit: 200 });
      setActive(r.active);
      setRows(r.transfers || []);
    } catch (e) {
      setErr(errorMessage(e));
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => { load(); }, []); // eslint-disable-line

  const cols: Column<Transfer>[] = [
    { key: 'transfer_ref', label: 'Ref', render: (r) => <span className="font-mono text-xs">{r.transfer_ref || `#${r.id}`}</span> },
    { key: 'order_id', label: 'Order', render: (r) => (r.order_id ? `#${r.order_id}` : '—') },
    { key: 'customer', label: 'Customer', render: (r) => (
      <div>
        <div className="font-medium">{r.customer || '—'}</div>
        <div className="text-xs text-zinc-500">{r.email}</div>
      </div>
    ) },
    { key: 'dealer', label: 'Dealer', render: (r) => (
      <div>
        <div>{r.dealer}</div>
        {(r.dealer_city || r.dealer_state) && <div className="text-xs text-zinc-500">{r.dealer_city}{r.dealer_city && r.dealer_state ? ', ' : ''}{r.dealer_state}</div>}
      </div>
    ) },
    { key: 'item', label: 'Item', render: (r) => (
      <div>
        <div className="max-w-md truncate">{r.item || '—'}</div>
        {r.sku && <div className="font-mono text-xs text-zinc-500">{r.sku}</div>}
      </div>
    ) },
    { key: 'tracking', label: 'Tracking', render: (r) => <span className="font-mono text-xs">{r.tracking || '—'}</span> },
    { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    { key: 'updated_at', label: 'Updated' },
  ];

  return (
    <div>
      <PageHeader
        title="FFL Transfers (Checkout)"
        subtitle="Read-only view of WooCommerce-order FFL transfers tracked by the Advanced FFL Checkout plugin."
      />
      {!active && !loading && (
        <div className="card mb-4 border-l-4 border-amber-500 p-4 text-sm">
          The Advanced FFL Checkout plugin is not installed/active — no transfer data available.
        </div>
      )}
      {err && <div className="mb-4 rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-900 dark:bg-rose-900/20 dark:text-rose-300">
        {err} <button className="btn-sm ml-2" onClick={load}>Retry</button>
      </div>}
      <div className="card mb-4 flex flex-wrap items-center gap-2 p-3">
        <input className="input max-w-sm" placeholder="Search ref / customer / dealer / item…" value={q}
          onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && load()} />
        <select className="input max-w-[200px]" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All statuses</option>
          <option value="dealer_selected">dealer_selected</option>
          <option value="shipped">shipped</option>
          <option value="dealer_received">dealer_received</option>
          <option value="nics_pending">nics_pending</option>
          <option value="completed">completed</option>
          <option value="cancelled">cancelled</option>
        </select>
        <button className="btn-primary" onClick={load}>Apply</button>
      </div>
      <DataTable<Transfer> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No FFL checkout transfers found." />
    </div>
  );
}

function StatusBadge({ status }: { status: string }) {
  const cls = status === 'completed' ? 'badge-green'
    : status === 'cancelled' ? 'badge-red'
    : 'badge-amber';
  return <span className={cls}>{status}</span>;
}
