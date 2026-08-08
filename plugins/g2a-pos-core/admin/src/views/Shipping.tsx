import { useEffect, useState } from 'react';
import { get, post, errorMessage } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';
import { useDialogs } from '../components/Dialogs';
import { useAction, ActionFeedback } from '../components/useAction';

interface Label {
  id: number;
  carrier: string;
  service: string;
  tracking_number: string;
  label_url: string;
  to_name: string;
  to_city: string;
  to_state: string;
  status: string;
  cost_amount: string | null;
  created_at: string;
}

interface ShipAddress {
  name?: string;
  address?: string;
  city?: string;
  state?: string;
  zip?: string;
  phone?: string;
}

interface LabelForm {
  carrier: string;
  service_code?: string;
  from: ShipAddress;
  to: ShipAddress;
  weight_oz: number;
  length_in: number;
  width_in: number;
  height_in: number;
}

export default function Shipping() {
  const dialogs = useDialogs();
  const { error, notice, setNotice, setError } = useAction();
  const [rows, setRows] = useState<Label[]>([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState<LabelForm>({
    carrier: 'ups',
    from: {}, to: {},
    weight_oz: 32, length_in: 12, width_in: 8, height_in: 4,
  });

  const refresh = async () => {
    setLoading(true);
    try {
      const r = await get<{ items: Label[] }>('/shipping/labels');
      setRows(r.items || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { queueMicrotask(refresh); }, []);

  const create = async () => {
    try {
      const res = await post<{ tracking_number?: string }>('/shipping/labels', form);
      if (res?.tracking_number) setNotice(`Label created — tracking ${res.tracking_number}.`);
      await refresh();
    } catch (e) {
      setError(errorMessage(e, 'Label creation failed — check carrier credentials under Settings → Shipping.'));
    }
  };

  const voidLabel = async (id: number) => {
    const ok = await dialogs.confirm({
      title: 'Void this label?',
      body: 'The carrier will cancel the shipment. This cannot be undone.',
      danger: true,
      confirmLabel: 'Void label',
    });
    if (!ok) return;
    await post(`/shipping/labels/${id}/void`, {});
    await refresh();
  };

  const set = (group: 'from' | 'to', key: string, val: string) => setForm({ ...form, [group]: { ...form[group], [key]: val } });

  const cols: Column<Label>[] = [
    { key: 'carrier', label: 'Carrier' },
    { key: 'tracking_number', label: 'Tracking' },
    { key: 'to', label: 'Recipient', render: (r) => `${r.to_name} — ${r.to_city}, ${r.to_state}` },
    { key: 'status', label: 'Status' },
    { key: 'cost_amount', label: 'Cost', align: 'right', render: (r) => r.cost_amount ? `$${parseFloat(r.cost_amount).toFixed(2)}` : '—' },
    { key: 'created_at', label: 'Created' },
    { key: 'actions', label: '', render: (r) => (
      <div className="flex gap-2 justify-end">
        {r.label_url && <a className="btn-sm" href={r.label_url} target="_blank" rel="noreferrer">Label</a>}
        {r.status === 'created' && <button className="btn-sm" onClick={() => voidLabel(r.id)}>Void</button>}
      </div>
    ) },
  ];

  return (
    <div>
      <PageHeader title="Shipping Labels" subtitle="UPS / FedEx label creation with Adult-Signature-Required pre-set (21+ for firearms)." />

      <ActionFeedback error={error} notice={notice} />
      <div className="card p-4 mb-4">
        <div className="flex gap-2 mb-3">
          <select className="input" value={form.carrier} onChange={(e) => setForm({...form, carrier: e.target.value})}>
            <option value="ups">UPS</option><option value="fedex">FedEx</option>
          </select>
          <input className="input" placeholder="Service code (e.g. 03 for UPS Ground)" value={form.service_code ?? ''} onChange={(e) => setForm({...form, service_code: e.target.value})} />
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          {(['from', 'to'] as const).map((g) => (
            <div key={g}>
              <div className="text-sm font-semibold mb-2 capitalize">{g}</div>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <input className="input col-span-2" placeholder="Name" onChange={(e) => set(g, 'name', e.target.value)} />
                <input className="input col-span-2" placeholder="Address" onChange={(e) => set(g, 'address', e.target.value)} />
                <input className="input" placeholder="City" onChange={(e) => set(g, 'city', e.target.value)} />
                <input className="input" placeholder="State" onChange={(e) => set(g, 'state', e.target.value)} />
                <input className="input" placeholder="ZIP" onChange={(e) => set(g, 'zip', e.target.value)} />
                <input className="input" placeholder="Phone" onChange={(e) => set(g, 'phone', e.target.value)} />
              </div>
            </div>
          ))}
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2 mt-3">
          <input className="input" type="number" placeholder="Weight oz" value={form.weight_oz} onChange={(e) => setForm({...form, weight_oz: parseFloat(e.target.value)})} />
          <input className="input" type="number" placeholder="L in" value={form.length_in} onChange={(e) => setForm({...form, length_in: parseFloat(e.target.value)})} />
          <input className="input" type="number" placeholder="W in" value={form.width_in} onChange={(e) => setForm({...form, width_in: parseFloat(e.target.value)})} />
          <input className="input" type="number" placeholder="H in" value={form.height_in} onChange={(e) => setForm({...form, height_in: parseFloat(e.target.value)})} />
        </div>

        <div className="mt-3 text-right">
          <button className="btn-primary" onClick={create}>Create label (Adult Sig + 21+)</button>
        </div>
      </div>

      <DataTable<Label> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No labels created." />
    </div>
  );
}
