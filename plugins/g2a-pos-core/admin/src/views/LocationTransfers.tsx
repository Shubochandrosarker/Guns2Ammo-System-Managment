import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Transfer {
  id: number;
  transfer_number: string;
  from_location_id: number;
  to_location_id: number;
  status: string;
  carrier: string;
  tracking_number: string;
  shipped_at: string | null;
  received_at: string | null;
}

interface TransferItem {
  quantity: number;
  serial_number?: string;
}

interface TransferForm {
  from_location_id?: number;
  to_location_id?: number;
  carrier?: string;
  tracking_number?: string;
  items: TransferItem[];
}

export default function LocationTransfers() {
  const [rows, setRows] = useState<Transfer[]>([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState<TransferForm>({ items: [{ quantity: 1 }] });

  const refresh = async () => {
    setLoading(true);
    try {
      const r = await get<{ items: Transfer[] }>('/transfers/location');
      setRows(r.items || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { queueMicrotask(refresh); }, []);

  const create = async () => {
    if (!form.from_location_id || !form.to_location_id) return;
    await post('/transfers/location', form);
    setForm({ items: [{ quantity: 1 }] });
    await refresh();
  };

  const ship = async (id: number) => { await post(`/transfers/location/${id}/ship`, {}); await refresh(); };
  const receive = async (id: number) => { await post(`/transfers/location/${id}/receive`, {}); await refresh(); };

  const cols: Column<Transfer>[] = [
    { key: 'transfer_number', label: 'Transfer #' },
    { key: 'from_location_id', label: 'From loc' },
    { key: 'to_location_id', label: 'To loc' },
    { key: 'status', label: 'Status', render: (r) => <span className="badge-zinc">{r.status}</span> },
    { key: 'carrier', label: 'Carrier' },
    { key: 'tracking_number', label: 'Tracking' },
    { key: 'actions', label: '', render: (r) => (
      <div className="flex gap-2 justify-end">
        {r.status === 'pending' && <button className="btn-sm" onClick={() => ship(r.id)}>Ship</button>}
        {r.status === 'in_transit' && <button className="btn-sm" onClick={() => receive(r.id)}>Receive</button>}
      </div>
    ) },
  ];

  return (
    <div>
      <PageHeader title="Multi-Location Transfers"
        subtitle="Serialized firearm movement between stores. Disposition entry at source, acquisition entry at destination." />
      <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3 md:grid-cols-4">
        <input className="input" type="number" placeholder="From location ID" value={form.from_location_id ?? ''}
          onChange={(e) => setForm({...form, from_location_id: parseInt(e.target.value)})} />
        <input className="input" type="number" placeholder="To location ID" value={form.to_location_id ?? ''}
          onChange={(e) => setForm({...form, to_location_id: parseInt(e.target.value)})} />
        <input className="input" placeholder="Carrier" value={form.carrier ?? ''} onChange={(e) => setForm({...form, carrier: e.target.value})} />
        <input className="input" placeholder="Tracking" value={form.tracking_number ?? ''} onChange={(e) => setForm({...form, tracking_number: e.target.value})} />
        {form.items.map((it: TransferItem, i: number) => (
          <input key={i} className="input md:col-span-3" placeholder="Serial number"
            value={it.serial_number ?? ''}
            onChange={(e) => { const items = [...form.items]; items[i].serial_number = e.target.value; setForm({...form, items}); }} />
        ))}
        <button className="btn-sm md:col-span-1" onClick={() => setForm({...form, items: [...form.items, { quantity: 1 }]})}>+ line</button>
        <button className="btn-primary md:col-span-4" onClick={create}>Create transfer</button>
      </div>
      <DataTable<Transfer> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No transfers." />
    </div>
  );
}
