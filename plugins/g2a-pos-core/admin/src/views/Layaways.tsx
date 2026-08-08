import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import { useDialogs } from '../components/Dialogs';
import { useAction, ActionFeedback } from '../components/useAction';
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
  const dialogs = useDialogs();
  const { run, busy, error, notice } = useAction();
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
  useEffect(() => { queueMicrotask(refresh); },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [status, type]);

  // Payment method was hard-coded to 'cash' regardless of how the customer
  // actually paid, so the ledger recorded the wrong tender every time it was
  // anything else. It is now chosen explicitly.
  const pay = async (row: Layaway) => {
    const values = await dialogs.prompt({
      title: `Record payment — ${row.layaway_number}`,
      body: `${row.customer_name}. Balance $${Number(row.balance_due ?? 0).toFixed(2)}.`,
      confirmLabel: 'Record payment',
      fields: [
        { name: 'amount', label: 'Amount', type: 'number', required: true, min: 0.01, step: 0.01 },
        { name: 'payment_method', label: 'Method', type: 'select', options: [
          { value: 'cash', label: 'Cash' },
          { value: 'card', label: 'Card' },
          { value: 'check', label: 'Check' },
          { value: 'store_credit', label: 'Store credit' },
        ] },
      ],
    });
    if (!values) return;
    await run(async () => {
      await post(`/layaways/${row.id}/pay`, values);
      await refresh();
    }, 'Payment recorded.');
  };

  const cancel = async (row: Layaway) => {
    const values = await dialogs.prompt({
      title: `Cancel ${row.layaway_number}?`,
      body: 'Cancelling releases the reserved items. Forfeiting keeps deposits already paid.',
      danger: true,
      confirmLabel: 'Cancel layaway',
      cancelLabel: 'Keep it',
      fields: [
        { name: 'reason', label: 'Reason', type: 'textarea', required: true },
        { name: 'forfeited', label: 'Deposit handling', type: 'select', options: [
          { value: '0', label: 'Refund deposits paid' },
          { value: '1', label: 'Forfeit deposits paid' },
        ] },
      ],
    });
    if (!values) return;
    await run(async () => {
      await post(`/layaways/${row.id}/cancel`, {
        reason: values.reason,
        forfeited: Number(values.forfeited),
      });
      await refresh();
    }, 'Layaway cancelled.');
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
        <button className="btn-sm" disabled={busy} onClick={() => pay(r)}>+ Pay</button>
        <button className="btn-sm" disabled={busy} onClick={() => cancel(r)}>Cancel</button>
      </div>
    ) : null },
  ];

  return (
    <div>
      <PageHeader title="Layaway / Special Order" subtitle="Deposit-and-payment-plan workflow. Auto-reminders fire 7 days before expiry." />

      <ActionFeedback error={error} notice={notice} />
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
