import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Ticket {
  id: number;
  ticket_number: string;
  customer_name: string;
  firearm_make: string;
  firearm_model: string;
  firearm_serial: string;
  status: string;
  grand_total: string;
  received_at: string;
  promised_at: string | null;
}

const STATUSES = ['intake', 'diagnosed', 'awaiting_parts', 'in_work', 'ready', 'returned'];

interface RepairForm {
  customer_name?: string;
  customer_phone?: string;
  customer_email?: string;
  firearm_make?: string;
  firearm_model?: string;
  firearm_serial?: string;
  firearm_caliber?: string;
  promised_at?: string;
  problem_description?: string;
}

export default function Repairs() {
  const [rows, setRows] = useState<Ticket[]>([]);
  const [loading, setLoading] = useState(true);
  const [showNew, setShowNew] = useState(false);
  const [form, setForm] = useState<RepairForm>({});

  const refresh = async () => {
    setLoading(true);
    try {
      const r = await get<{ items: Ticket[] }>('/repairs');
      setRows(r.items || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { refresh(); }, []);

  const create = async () => {
    if (!form.customer_name || !form.problem_description) return;
    await post('/repairs', form);
    setShowNew(false);
    setForm({});
    await refresh();
  };

  const cols: Column<Ticket>[] = [
    { key: 'ticket_number', label: 'Ticket' },
    { key: 'customer_name', label: 'Customer' },
    { key: 'firearm_make', label: 'Make/Model', render: (r) => `${r.firearm_make} ${r.firearm_model}` },
    { key: 'firearm_serial', label: 'Serial' },
    { key: 'status', label: 'Status', render: (r) => <span className="badge-zinc">{r.status}</span> },
    { key: 'grand_total', label: 'Total', align: 'right', render: (r) => `$${parseFloat(r.grand_total || '0').toFixed(2)}` },
    { key: 'received_at', label: 'Received' },
    { key: 'promised_at', label: 'Promised' },
  ];

  return (
    <div>
      <PageHeader
        title="Gunsmithing / Repairs"
        subtitle="Intake → diagnosis → parts/labor → ready → returned. Tracks photos, estimate signoff, totals."
        actions={<button className="btn-primary" onClick={() => setShowNew((s) => !s)}>{showNew ? 'Close' : '+ New ticket'}</button>}
      />

      {showNew && (
        <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
          <input className="input" placeholder="Customer name *" value={form.customer_name ?? ''} onChange={(e) => setForm({...form, customer_name: e.target.value})} />
          <input className="input" placeholder="Phone" value={form.customer_phone ?? ''} onChange={(e) => setForm({...form, customer_phone: e.target.value})} />
          <input className="input" placeholder="Email" value={form.customer_email ?? ''} onChange={(e) => setForm({...form, customer_email: e.target.value})} />
          <input className="input" placeholder="Make" value={form.firearm_make ?? ''} onChange={(e) => setForm({...form, firearm_make: e.target.value})} />
          <input className="input" placeholder="Model" value={form.firearm_model ?? ''} onChange={(e) => setForm({...form, firearm_model: e.target.value})} />
          <input className="input" placeholder="Serial" value={form.firearm_serial ?? ''} onChange={(e) => setForm({...form, firearm_serial: e.target.value})} />
          <input className="input" placeholder="Caliber" value={form.firearm_caliber ?? ''} onChange={(e) => setForm({...form, firearm_caliber: e.target.value})} />
          <input className="input" type="datetime-local" placeholder="Promised at" value={form.promised_at ?? ''} onChange={(e) => setForm({...form, promised_at: e.target.value})} />
          <textarea className="input col-span-2" rows={3} placeholder="Problem description *"
            value={form.problem_description ?? ''} onChange={(e) => setForm({...form, problem_description: e.target.value})} />
          <div className="col-span-2 flex justify-end gap-2">
            <button className="btn" onClick={() => setShowNew(false)}>Cancel</button>
            <button className="btn-primary" onClick={create}>Create ticket</button>
          </div>
        </div>
      )}

      <DataTable<Ticket> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No tickets yet." />
      <div className="mt-2 text-xs text-zinc-500">Statuses: {STATUSES.join(' → ')}</div>
    </div>
  );
}
