import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface TradeIn {
  id: number;
  tradein_number: string;
  customer_name: string;
  manufacturer: string;
  model: string;
  serial_number: string;
  appraisal_amount: string;
  credit_amount: string;
  status: string;
  received_at: string;
}

interface TradeInForm {
  customer_name?: string;
  customer_id?: number;
  customer_id_number?: string;
  manufacturer?: string;
  model?: string;
  serial_number?: string;
  caliber?: string;
  condition_grade?: string;
  appraisal_amount?: number;
  credit_amount?: number;
}

export default function TradeIns() {
  const [rows, setRows] = useState<TradeIn[]>([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState<TradeInForm>({});

  const refresh = async () => {
    setLoading(true);
    try {
      const r = await get<{ items: TradeIn[] }>('/tradeins');
      setRows(r.items || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { refresh(); }, []);

  const create = async () => {
    if (!form.customer_name || !form.serial_number || !form.manufacturer) return;
    const res = await post<{ credit_amount?: string }>('/tradeins', form);
    if (res?.credit_amount) alert(`Credit issued: $${parseFloat(res.credit_amount).toFixed(2)}`);
    setForm({});
    await refresh();
  };

  const cols: Column<TradeIn>[] = [
    { key: 'tradein_number', label: 'Number' },
    { key: 'customer_name', label: 'Customer' },
    { key: 'firearm', label: 'Firearm', render: (r) => `${r.manufacturer} ${r.model}` },
    { key: 'serial_number', label: 'Serial' },
    { key: 'appraisal_amount', label: 'Appraisal', align: 'right', render: (r) => `$${parseFloat(r.appraisal_amount).toFixed(2)}` },
    { key: 'credit_amount', label: 'Credit', align: 'right', render: (r) => `$${parseFloat(r.credit_amount).toFixed(2)}` },
    { key: 'status', label: 'Status', render: (r) => <span className="badge-zinc">{r.status}</span> },
    { key: 'received_at', label: 'Received' },
  ];

  return (
    <div>
      <PageHeader title="Trade-Ins" subtitle="Customer firearm trade-in. Auto-issues store credit (single ledger that survives refunds), writes acquisition entry to bound book." />
      <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
        <input className="input" placeholder="Customer *" value={form.customer_name ?? ''} onChange={(e) => setForm({...form, customer_name: e.target.value})} />
        <input className="input" type="number" placeholder="Customer ID" value={form.customer_id ?? ''} onChange={(e) => setForm({...form, customer_id: parseInt(e.target.value)})} />
        <input className="input" placeholder="ID number" value={form.customer_id_number ?? ''} onChange={(e) => setForm({...form, customer_id_number: e.target.value})} />
        <input className="input" placeholder="Manufacturer *" value={form.manufacturer ?? ''} onChange={(e) => setForm({...form, manufacturer: e.target.value})} />
        <input className="input" placeholder="Model" value={form.model ?? ''} onChange={(e) => setForm({...form, model: e.target.value})} />
        <input className="input" placeholder="Serial *" value={form.serial_number ?? ''} onChange={(e) => setForm({...form, serial_number: e.target.value})} />
        <input className="input" placeholder="Caliber" value={form.caliber ?? ''} onChange={(e) => setForm({...form, caliber: e.target.value})} />
        <input className="input" placeholder="Condition (A/B/C/D)" value={form.condition_grade ?? ''} onChange={(e) => setForm({...form, condition_grade: e.target.value})} />
        <input className="input" type="number" step="0.01" placeholder="Appraisal $" value={form.appraisal_amount ?? ''} onChange={(e) => setForm({...form, appraisal_amount: parseFloat(e.target.value)})} />
        <input className="input" type="number" step="0.01" placeholder="Credit to issue $" value={form.credit_amount ?? ''} onChange={(e) => setForm({...form, credit_amount: parseFloat(e.target.value)})} />
        <button className="btn-primary md:col-span-3" onClick={create}>Receive trade-in & issue credit</button>
      </div>
      <DataTable<TradeIn> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No trade-ins." />
    </div>
  );
}
