import { useEffect, useState } from 'react';
import { get, post, errorMessage } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface DropShipOrder {
  id: number;
  wholesaler_id: number;
  pos_order_id?: number | null;
  external_order_ref?: string;
  order_type: string;
  status: string;
  ship_to_name?: string;
  ship_to_city?: string;
  ship_to_state?: string;
  ship_to_zip?: string;
  ship_to_ffl?: string;
  ship_method?: string;
  tracking_number?: string;
  submitted_at?: string;
  created_at?: string;
  error_message?: string;
}

interface Wholesaler { id: number; provider_code: string; display_name: string; }

type Address = { name: string; address: string; city: string; state: string; zip: string; ffl?: string };

interface DropShipResult {
  ok: boolean;
  error?: string;
  external_ref?: string;
  local_order_id?: number;
}

const EMPTY_FORM = {
  wholesaler_id: 0,
  warehouse: '',
  po_number: '',
  overnight: false,
  message: '',
  items: [{ vendor_sku: '', quantity: 1, note: '' }],
  billing: { name: '', address: '', city: '', state: '', zip: '' } as Address,
  ship_to: { name: '', address: '', city: '', state: '', zip: '', ffl: '' } as Address,
};

export default function DropShipOrders() {
  const [rows, setRows] = useState<DropShipOrder[]>([]);
  const [wholesalers, setWholesalers] = useState<Wholesaler[]>([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState({ ...EMPTY_FORM });
  const [submitting, setSubmitting] = useState(false);
  const [result, setResult] = useState<DropShipResult | null>(null);

  const load = async () => {
    setLoading(true);
    try {
      const [orders, ws] = await Promise.all([
        get<{ ok: boolean; orders: DropShipOrder[] }>('/wholesalers/orders', { limit: 100 }),
        get<{ ok: boolean; wholesalers: Wholesaler[] }>('/wholesalers'),
      ]);
      setRows(orders.orders || []);
      setWholesalers(ws.wholesalers || []);
      if (ws.wholesalers?.length && !form.wholesaler_id) {
        setForm((f) => ({ ...f, wholesaler_id: ws.wholesalers[0].id }));
      }
    } finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []); // eslint-disable-line

  const setItem = (i: number, patch: Partial<typeof form.items[0]>) =>
    setForm((f) => ({ ...f, items: f.items.map((it, j) => (i === j ? { ...it, ...patch } : it)) }));

  const submit = async () => {
    setSubmitting(true);
    setResult(null);
    try {
      const items = form.items.filter((i) => i.vendor_sku);
      if (!items.length || !form.wholesaler_id) return;
      const r = await post<DropShipResult>(`/wholesalers/${form.wholesaler_id}/dropship`, {
        warehouse: form.warehouse || undefined,
        po_number: form.po_number || undefined,
        overnight: form.overnight,
        message_for_sales_exec: form.message || undefined,
        items,
        billing: form.billing,
        ship_to: form.ship_to,
      });
      setResult(r);
      if (r?.ok) {
        setForm({ ...EMPTY_FORM, wholesaler_id: form.wholesaler_id });
        await load();
      }
    } catch (e) {
      setResult({ ok: false, error: errorMessage(e, 'failed') });
    } finally { setSubmitting(false); }
  };

  const cols: Column<DropShipOrder>[] = [
    { key: 'id', label: 'ID', width: '60px' },
    {
      key: 'status', label: 'Status', render: (r) => {
        const tone = r.status === 'submitted' ? 'emerald' : r.status === 'failed' ? 'rose' : 'amber';
        const map = {
          emerald: 'bg-emerald-100 text-emerald-700',
          rose: 'bg-rose-100 text-rose-700',
          amber: 'bg-amber-100 text-amber-700',
        }[tone];
        return <span className={`rounded px-1.5 py-0.5 text-xs ${map}`}>{r.status}</span>;
      },
    },
    { key: 'external_order_ref', label: 'PO Ref', render: (r) => r.external_order_ref || '—' },
    { key: 'wholesaler_id', label: 'Wholesaler #' },
    { key: 'pos_order_id', label: 'POS Order' },
    {
      key: 'ship_to', label: 'Ship to', render: (r) => (
        <div className="text-xs leading-tight">
          <div>{r.ship_to_name || '—'}</div>
          <div className="text-zinc-500">{[r.ship_to_city, r.ship_to_state, r.ship_to_zip].filter(Boolean).join(', ')}</div>
          {r.ship_to_ffl && <div className="text-zinc-500">FFL: {r.ship_to_ffl}</div>}
        </div>
      ),
    },
    { key: 'tracking_number', label: 'Tracking', render: (r) => r.tracking_number || '—' },
    { key: 'submitted_at', label: 'Submitted' },
  ];

  return (
    <>
      <PageHeader
        title="Drop-ship orders"
        subtitle="Submit and track third-party drop-ship orders against your wholesaler accounts."
      />

      <DataTable rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No drop-ship orders yet." />

      <div className="card mt-6 p-6">
        <h3 className="mb-4 text-lg font-semibold">Submit a drop-ship order</h3>

        <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
          <Field label="Wholesaler">
            <select className="input" value={form.wholesaler_id} onChange={(e) => setForm({ ...form, wholesaler_id: Number(e.target.value) })}>
              {wholesalers.map((w) => <option key={w.id} value={w.id}>{w.display_name}</option>)}
            </select>
          </Field>
          <Field label="Warehouse (optional)">
            <input className="input" value={form.warehouse} placeholder="e.g. AR" onChange={(e) => setForm({ ...form, warehouse: e.target.value })} />
          </Field>
          <Field label="PO number (optional)">
            <input className="input" value={form.po_number} placeholder="auto-generated if blank" onChange={(e) => setForm({ ...form, po_number: e.target.value })} />
          </Field>
        </div>

        <div className="mt-4">
          <h4 className="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500">Items</h4>
          {form.items.map((it, i) => (
            <div key={i} className="mb-2 grid grid-cols-1 gap-2 md:grid-cols-12">
              <input className="input md:col-span-5" placeholder="Vendor SKU" value={it.vendor_sku} onChange={(e) => setItem(i, { vendor_sku: e.target.value })} />
              <input className="input md:col-span-2" type="number" min={1} value={it.quantity} onChange={(e) => setItem(i, { quantity: Number(e.target.value) })} />
              <input className="input md:col-span-4" placeholder="Note (optional)" value={it.note} onChange={(e) => setItem(i, { note: e.target.value })} />
              <button className="btn-secondary md:col-span-1" onClick={() => setForm((f) => ({ ...f, items: f.items.filter((_, j) => j !== i) }))} disabled={form.items.length <= 1}>×</button>
            </div>
          ))}
          <button className="btn-secondary text-xs" onClick={() => setForm((f) => ({ ...f, items: [...f.items, { vendor_sku: '', quantity: 1, note: '' }] }))}>+ Add item</button>
        </div>

        <div className="mt-6 grid grid-cols-1 gap-6 md:grid-cols-2">
          <AddressGroup title="Billing" data={form.billing} onChange={(d) => setForm({ ...form, billing: d })} />
          <AddressGroup title="Ship to (typically transferring FFL)" data={form.ship_to} onChange={(d) => setForm({ ...form, ship_to: d })} withFfl />
        </div>

        <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={form.overnight} onChange={(e) => setForm({ ...form, overnight: e.target.checked })} />
            Overnight shipping
          </label>
          <label className="flex flex-col gap-1 text-sm md:col-span-2">
            <span className="text-xs font-medium uppercase tracking-wide text-zinc-500">Message for sales exec</span>
            <input className="input" value={form.message} placeholder="auto: 'Transfer FFL: <ffl>' if blank" onChange={(e) => setForm({ ...form, message: e.target.value })} />
          </label>
        </div>

        <div className="mt-4 flex items-center justify-between">
          {result && (
            <span className={`text-sm ${result.ok ? 'text-emerald-600' : 'text-rose-600'}`}>
              {result.ok ? `Submitted (ref ${result.external_ref || result.local_order_id})` : `Failed: ${result.error || 'unknown'}`}
            </span>
          )}
          <button className="btn-primary ml-auto" disabled={submitting || !form.wholesaler_id} onClick={submit}>
            {submitting ? 'Submitting…' : 'Submit drop-ship'}
          </button>
        </div>
      </div>
    </>
  );
}

function AddressGroup({ title, data, onChange, withFfl = false }: { title: string; data: Address; onChange: (d: Address) => void; withFfl?: boolean }) {
  return (
    <div>
      <h4 className="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500">{title}</h4>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
        <input className="input col-span-2" placeholder="Name" value={data.name} onChange={(e) => onChange({ ...data, name: e.target.value })} />
        <input className="input col-span-2" placeholder="Address" value={data.address} onChange={(e) => onChange({ ...data, address: e.target.value })} />
        <input className="input" placeholder="City" value={data.city} onChange={(e) => onChange({ ...data, city: e.target.value })} />
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
          <input className="input" placeholder="State" value={data.state} onChange={(e) => onChange({ ...data, state: e.target.value })} />
          <input className="input" placeholder="ZIP" value={data.zip} onChange={(e) => onChange({ ...data, zip: e.target.value })} />
        </div>
        {withFfl && <input className="input col-span-2" placeholder="Transferring FFL #" value={data.ffl || ''} onChange={(e) => onChange({ ...data, ffl: e.target.value })} />}
      </div>
    </div>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1 text-sm">
      <span className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{label}</span>
      {children}
    </label>
  );
}
