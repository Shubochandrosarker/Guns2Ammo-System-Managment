import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Consignment {
  id: number;
  consignment_number: string;
  consignor_name: string;
  firearm_make: string;
  firearm_model: string;
  firearm_serial: string;
  asking_price: string;
  sold_price: string | null;
  payout_amount: string | null;
  status: string;
}

interface ConsignmentForm {
  consignor_name?: string;
  consignor_id_number?: string;
  consignor_phone?: string;
  consignor_email?: string;
  firearm_make?: string;
  firearm_model?: string;
  firearm_serial?: string;
  firearm_caliber?: string;
  asking_price?: number;
  commission_percent?: number;
  condition_notes?: string;
}

export default function Consignments() {
  const [rows, setRows] = useState<Consignment[]>([]);
  const [loading, setLoading] = useState(true);
  const [showNew, setShowNew] = useState(false);
  const [form, setForm] = useState<ConsignmentForm>({ commission_percent: 20 });

  const refresh = async () => {
    setLoading(true);
    try {
      const r = await get<{ items: Consignment[] }>('/consignments');
      setRows(r.items || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { refresh(); }, []);

  const create = async () => {
    if (!form.consignor_name || !form.firearm_serial) return;
    await post('/consignments', form);
    setForm({ commission_percent: 20 });
    setShowNew(false);
    await refresh();
  };

  const sell = async (id: number) => {
    const p = parseFloat(prompt('Sold price?') || '0');
    if (!p) return;
    await post(`/consignments/${id}/sell`, { sold_price: p });
    await refresh();
  };

  const payout = async (id: number) => {
    const ref = prompt('Payout reference (check #, ACH ID, …)?') ?? '';
    await post(`/consignments/${id}/payout`, { reference: ref });
    await refresh();
  };

  const cols: Column<Consignment>[] = [
    { key: 'consignment_number', label: 'Number' },
    { key: 'consignor_name', label: 'Consignor' },
    { key: 'firearm_make', label: 'Firearm', render: (r) => `${r.firearm_make} ${r.firearm_model}` },
    { key: 'firearm_serial', label: 'Serial' },
    { key: 'asking_price', label: 'Asking', align: 'right', render: (r) => `$${parseFloat(r.asking_price).toFixed(2)}` },
    { key: 'sold_price', label: 'Sold', align: 'right', render: (r) => r.sold_price ? `$${parseFloat(r.sold_price).toFixed(2)}` : '—' },
    { key: 'payout_amount', label: 'Payout', align: 'right', render: (r) => r.payout_amount ? `$${parseFloat(r.payout_amount).toFixed(2)}` : '—' },
    { key: 'status', label: 'Status', render: (r) => <span className="badge-zinc">{r.status}</span> },
    { key: 'actions', label: '', render: (r) => (
      <div className="flex gap-2 justify-end">
        {r.status === 'received' && <button className="btn-sm" onClick={() => sell(r.id)}>Sell</button>}
        {r.status === 'sold' && <button className="btn-sm" onClick={() => payout(r.id)}>Payout</button>}
      </div>
    ) },
  ];

  return (
    <div>
      <PageHeader
        title="Consignments"
        subtitle="Customer-owned firearms accepted for sale. Auto-computes commission + payout when sold."
        actions={<button className="btn-primary" onClick={() => setShowNew((s) => !s)}>{showNew ? 'Close' : '+ New'}</button>}
      />

      {showNew && (
        <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
          <input className="input" placeholder="Consignor name *" value={form.consignor_name ?? ''} onChange={(e) => setForm({...form, consignor_name: e.target.value})} />
          <input className="input" placeholder="ID number" value={form.consignor_id_number ?? ''} onChange={(e) => setForm({...form, consignor_id_number: e.target.value})} />
          <input className="input" placeholder="Phone" value={form.consignor_phone ?? ''} onChange={(e) => setForm({...form, consignor_phone: e.target.value})} />
          <input className="input" placeholder="Email" value={form.consignor_email ?? ''} onChange={(e) => setForm({...form, consignor_email: e.target.value})} />
          <input className="input" placeholder="Make" value={form.firearm_make ?? ''} onChange={(e) => setForm({...form, firearm_make: e.target.value})} />
          <input className="input" placeholder="Model" value={form.firearm_model ?? ''} onChange={(e) => setForm({...form, firearm_model: e.target.value})} />
          <input className="input" placeholder="Serial *" value={form.firearm_serial ?? ''} onChange={(e) => setForm({...form, firearm_serial: e.target.value})} />
          <input className="input" placeholder="Caliber" value={form.firearm_caliber ?? ''} onChange={(e) => setForm({...form, firearm_caliber: e.target.value})} />
          <input className="input" type="number" step="0.01" placeholder="Asking price *" value={form.asking_price ?? ''} onChange={(e) => setForm({...form, asking_price: parseFloat(e.target.value)})} />
          <input className="input" type="number" step="0.01" placeholder="Commission %" value={form.commission_percent ?? 20} onChange={(e) => setForm({...form, commission_percent: parseFloat(e.target.value)})} />
          <textarea className="input col-span-2" rows={2} placeholder="Condition notes" value={form.condition_notes ?? ''} onChange={(e) => setForm({...form, condition_notes: e.target.value})} />
          <div className="col-span-2 flex justify-end gap-2">
            <button className="btn" onClick={() => setShowNew(false)}>Cancel</button>
            <button className="btn-primary" onClick={create}>Receive consignment</button>
          </div>
        </div>
      )}

      <DataTable<Consignment> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No consignments." />
    </div>
  );
}
