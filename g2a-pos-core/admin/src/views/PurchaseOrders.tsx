import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface PO {
  id: number;
  po_number: string;
  vendor_name: string;
  status: string;
  grand_total: string;
  expected_at: string | null;
  submitted_at: string | null;
  received_at: string | null;
}

interface POItem {
  id: number;
  description: string;
  vendor_sku: string;
  quantity_ordered: string;
  quantity_received: string;
  unit_cost: string;
  line_total: string;
}

interface POFormItem {
  vendor_sku?: string;
  description?: string;
  quantity_ordered?: number;
  unit_cost?: number;
}

interface POForm {
  vendor_name?: string;
  expected_at?: string;
  items: POFormItem[];
}

export default function PurchaseOrders() {
  const [rows, setRows] = useState<PO[]>([]);
  const [loading, setLoading] = useState(true);
  const [showNew, setShowNew] = useState(false);
  const [form, setForm] = useState<POForm>({ items: [{}] });
  const [openPo, setOpenPo] = useState<(PO & { items: POItem[] }) | null>(null);

  const refresh = async () => {
    setLoading(true);
    try {
      const r = await get<{ items: PO[] }>('/purchase-orders');
      setRows(r.items || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { queueMicrotask(refresh); }, []);

  const create = async () => {
    if (!form.items?.length) return;
    await post('/purchase-orders', form);
    setForm({ items: [{}] });
    setShowNew(false);
    await refresh();
  };

  const openOne = async (id: number) => {
    const r = await get<{ po: PO & { items: POItem[] } }>(`/purchase-orders/${id}`);
    setOpenPo(r.po);
  };

  const submit = async (id: number) => {
    await post(`/purchase-orders/${id}/submit`, {});
    await openOne(id);
    await refresh();
  };

  const receive = async (po_item_id: number) => {
    if (!openPo) return;
    const q = parseFloat(prompt('Quantity received?') || '0');
    if (!q) return;
    await post(`/purchase-orders/${openPo.id}/receive`, { po_item_id, quantity: q });
    await openOne(openPo.id);
    await refresh();
  };

  const cols: Column<PO>[] = [
    { key: 'po_number', label: 'PO #', render: (r) => <button className="link" onClick={() => openOne(r.id)}>{r.po_number}</button> },
    { key: 'vendor_name', label: 'Vendor' },
    { key: 'status', label: 'Status', render: (r) => <span className="badge-zinc">{r.status}</span> },
    { key: 'grand_total', label: 'Total', align: 'right', render: (r) => `$${parseFloat(r.grand_total).toFixed(2)}` },
    { key: 'expected_at', label: 'Expected' },
    { key: 'received_at', label: 'Received' },
  ];

  return (
    <div>
      <PageHeader title="Purchase Orders" subtitle="Stock-order to wholesalers with partial receiving against PO."
        actions={<button className="btn-primary" onClick={() => setShowNew((s) => !s)}>{showNew ? 'Close' : '+ New PO'}</button>} />

      {showNew && (
        <div className="card p-4 mb-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3">
            <input className="input" placeholder="Vendor name" value={form.vendor_name ?? ''} onChange={(e) => setForm({...form, vendor_name: e.target.value})} />
            <input className="input" type="datetime-local" placeholder="Expected at" value={form.expected_at ?? ''} onChange={(e) => setForm({...form, expected_at: e.target.value.replace('T', ' ') + ':00'})} />
          </div>
          <div className="text-sm font-semibold mb-2">Line items</div>
          {form.items.map((it: POFormItem, i: number) => (
            <div key={i} className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 mb-2">
              <input className="input" placeholder="Vendor SKU" value={it.vendor_sku ?? ''} onChange={(e) => { const items=[...form.items]; items[i].vendor_sku=e.target.value; setForm({...form, items}); }} />
              <input className="input col-span-2" placeholder="Description" value={it.description ?? ''} onChange={(e) => { const items=[...form.items]; items[i].description=e.target.value; setForm({...form, items}); }} />
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <input className="input" type="number" step="0.01" placeholder="Qty" value={it.quantity_ordered ?? ''} onChange={(e) => { const items=[...form.items]; items[i].quantity_ordered=parseFloat(e.target.value); setForm({...form, items}); }} />
                <input className="input" type="number" step="0.01" placeholder="Cost" value={it.unit_cost ?? ''} onChange={(e) => { const items=[...form.items]; items[i].unit_cost=parseFloat(e.target.value); setForm({...form, items}); }} />
              </div>
            </div>
          ))}
          <button className="btn-sm mb-3" onClick={() => setForm({...form, items: [...form.items, {}]})}>+ Add line</button>
          <div className="flex justify-end gap-2"><button className="btn" onClick={() => setShowNew(false)}>Cancel</button><button className="btn-primary" onClick={create}>Create draft PO</button></div>
        </div>
      )}

      <DataTable<PO> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No POs yet." />

      {openPo && (
        <div className="card p-4 mt-4">
          <div className="flex justify-between items-start mb-2">
            <div>
              <h3 className="font-semibold">{openPo.po_number} — {openPo.vendor_name}</h3>
              <p className="text-xs text-zinc-500">Status: {openPo.status}</p>
            </div>
            <div className="flex gap-2">
              {openPo.status === 'draft' && <button className="btn-primary" onClick={() => submit(openPo.id)}>Submit PO</button>}
              <button className="btn" onClick={() => setOpenPo(null)}>Close</button>
            </div>
          </div>
          <table className="w-full text-sm">
            <thead><tr><th className="text-left">SKU</th><th className="text-left">Description</th><th className="text-right">Ordered</th><th className="text-right">Received</th><th className="text-right">Unit</th><th className="text-right">Total</th><th></th></tr></thead>
            <tbody>{openPo.items.map((it) => (
              <tr key={it.id}>
                <td>{it.vendor_sku}</td>
                <td>{it.description}</td>
                <td className="text-right">{it.quantity_ordered}</td>
                <td className="text-right">{it.quantity_received}</td>
                <td className="text-right">${parseFloat(it.unit_cost).toFixed(2)}</td>
                <td className="text-right">${parseFloat(it.line_total).toFixed(2)}</td>
                <td><button className="btn-sm" onClick={() => receive(it.id)}>Receive</button></td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      )}
    </div>
  );
}
