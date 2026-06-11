import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Layaway {
  id: number;
  layaway_number: string;
  layaway_type: string;
  customer_name: string;
  grand_total: string;
  paid_amount: string;
  balance_due: string;
  status: string;
  opened_at: string;
  expires_at: string | null;
}

export default function Layaways() {
  const [rows, setRows] = useState<Layaway[]>([]);
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState('');
  const [type, setType] = useState('');

  const refresh = async () => {
    setLoading(true);
    try {
      const r = await get<{ items: Layaway[] }>('/layaways', { status, layaway_type: type });
      setRows(r.items || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { refresh(); },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [status, type]);

  const pay = async (id: number) => {
    const amount = parseFloat(prompt('Payment amount?') || '0');
    if (!amount) return;
    await post(`/layaways/${id}/pay`, { amount, payment_method: 'cash' });
    await refresh();
  };

  const cancel = async (id: number) => {
    const reason = prompt('Cancellation reason?');
    if (!reason) return;
    await post(`/layaways/${id}/cancel`, { reason, forfeited: 0 });
    await refresh();
  };

  const cols: Column<Layaway>[] = [
    { key: 'layaway_number', label: 'Number' },
    { key: 'layaway_type', label: 'Type' },
    { key: 'customer_name', label: 'Customer' },
    { key: 'grand_total', label: 'Total', align: 'right', render: (r) => `$${parseFloat(r.grand_total).toFixed(2)}` },
    { key: 'paid_amount', label: 'Paid', align: 'right', render: (r) => `$${parseFloat(r.paid_amount).toFixed(2)}` },
    { key: 'balance_due', label: 'Balance', align: 'right', render: (r) => `$${parseFloat(r.balance_due).toFixed(2)}` },
    { key: 'status', label: 'Status', render: (r) => <span className="badge-zinc">{r.status}</span> },
    { key: 'expires_at', label: 'Expires' },
    { key: 'actions', label: '', render: (r) => r.status === 'active' ? (
      <div className="flex gap-2 justify-end">
        <button className="btn-sm" onClick={() => pay(r.id)}>+ Pay</button>
        <button className="btn-sm" onClick={() => cancel(r.id)}>Cancel</button>
      </div>
    ) : null },
  ];

  return (
    <div>
      <PageHeader title="Layaway / Special Order" subtitle="Deposit-and-payment-plan workflow. Auto-reminders fire 7 days before expiry." />
      <div className="card p-3 mb-4 flex gap-2">
        <select className="input" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="paid">Paid</option>
          <option value="cancelled">Cancelled</option>
        </select>
        <select className="input" value={type} onChange={(e) => setType(e.target.value)}>
          <option value="">All types</option>
          <option value="layaway">Layaway</option>
          <option value="special_order">Special order</option>
        </select>
      </div>
      <DataTable<Layaway> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No layaways." />
    </div>
  );
}
