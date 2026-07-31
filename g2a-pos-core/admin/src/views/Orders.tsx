import { useEffect, useState } from 'react';
import { download, get } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';
import { useAction, ActionFeedback } from '../components/useAction';

interface Order {
  id: number;
  grand_total: string;
  payment_status: string;
  payment_method: string | null;
  compliance_state: string;
  status: string;
  created_at: string;
  employee_id: number;
  location_id: number;
  customer_id: number | null;
}

export default function Orders() {
  const { error, notice, setError } = useAction();
  const [rows, setRows] = useState<Order[]>([]);
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState('');
  const [exporting, setExporting] = useState(false);

  const exportCsv = async () => {
    setExporting(true);
    try {
      await download('/orders/export.csv', `g2a-orders-${new Date().toISOString().slice(0, 10)}.csv`, { status });
    } catch {
      setError('Export failed — you may not have permission.');
    } finally {
      setExporting(false);
    }
  };

  const refresh = async () => {
    setLoading(true);
    try {
      const r = await get<{ items: Order[] }>('/orders', { status, limit: 200 });
      setRows(r.items || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { queueMicrotask(refresh); },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [status]);

  const cols: Column<Order>[] = [
    { key: 'id', label: 'Order', width: '80px', render: (r) => <span className="font-mono">#{r.id}</span> },
    { key: 'grand_total', label: 'Total', align: 'right', render: (r) => `$${parseFloat(r.grand_total).toFixed(2)}` },
    { key: 'payment_status', label: 'Payment', render: (r) => <PaymentBadge status={r.payment_status} method={r.payment_method} /> },
    { key: 'compliance_state', label: 'Compliance', render: (r) => <ComplianceBadge state={r.compliance_state} /> },
    { key: 'status', label: 'Status', render: (r) => <span className="badge-zinc">{r.status}</span> },
    { key: 'location_id', label: 'Loc' },
    { key: 'customer_id', label: 'Customer', render: (r) => r.customer_id ?? '—' },
    { key: 'created_at', label: 'Created' },
    { key: 'actions', label: '', render: (r) => (
      <div className="flex gap-1 justify-end">
        <a className="btn-sm" href={`#split_tender?order=${r.id}`}>Tender</a>
        <a className="btn-sm" href={`#order_sourcing?order=${r.id}`}>Sourcing</a>
      </div>
    ) },
  ];

  return (
    <div>
      <PageHeader
        title="Sales / Orders"
        subtitle="POS tickets including firearm transactions. Status + payment + compliance state tracked independently. Click an order to tender split payments or evaluate sourcing across shelf + wholesalers."
        actions={
          <button className="btn-secondary" onClick={exportCsv} disabled={exporting}>
            {exporting ? 'Exporting…' : '⬇ Export CSV'}
          </button>
        }
      />

      <ActionFeedback error={error} notice={notice} />
      <div className="card p-3 mb-4 flex gap-2">
        <select className="input" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All statuses</option>
          <option value="open">open</option>
          <option value="hold">hold</option>
          <option value="completed">completed</option>
          <option value="cancelled">cancelled</option>
          <option value="refunded">refunded</option>
        </select>
      </div>
      <DataTable<Order>
        rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id}
        empty="No orders yet. Ring one through the cashier lane."
      />
    </div>
  );
}

function PaymentBadge({ status, method }: { status: string; method: string | null }) {
  const cls = status === 'paid' ? 'badge-green' : status === 'refunded' || status === 'voided' ? 'badge-red' : 'badge-amber';
  return <span className={cls}>{status}{method && ` · ${method}`}</span>;
}

function ComplianceBadge({ state }: { state: string }) {
  const cls = state === 'approved' || state === 'cleared' || state === 'n/a' ? 'badge-green'
    : state === 'rejected' ? 'badge-red' : 'badge-amber';
  return <span className={cls}>{state}</span>;
}
