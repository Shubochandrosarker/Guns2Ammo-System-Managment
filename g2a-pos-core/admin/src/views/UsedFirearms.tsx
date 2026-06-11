import { useEffect, useState } from 'react';
import { get, post, errorMessage } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Intake {
  id: number;
  intake_number: string;
  customer_name: string;
  manufacturer: string;
  model: string;
  serial_number: string;
  caliber: string | null;
  firearm_type: string | null;
  condition_grade: string | null;
  appraised_value: string | null;
  payout_amount: string;
  payout_method: string | null;
  status: string;
  identity_id: number | null;
  identity_confidence: string | null;
  received_at: string;
}

const STATUS_BADGE: Record<string, string> = {
  received: 'badge-amber', appraised: 'badge-amber', paid: 'badge-green',
  listed: 'badge-zinc', sold: 'badge-green', rejected: 'badge-red',
};

interface IntakeForm {
  customer_name?: string; customer_id?: number; customer_id_number?: string;
  customer_phone?: string; customer_email?: string; customer_address?: string;
  manufacturer?: string; model?: string; serial_number?: string;
  firearm_type?: string; caliber?: string; barrel_length_in?: number;
  condition_grade?: string; appraised_value?: number; condition_notes?: string;
}

const CONDITIONS = ['A — like new', 'B — excellent', 'C — good', 'D — fair', 'E — poor'];

export default function UsedFirearms() {
  const [rows, setRows] = useState<Intake[]>([]);
  const [loading, setLoading] = useState(true);
  const [showNew, setShowNew] = useState(false);
  const [form, setForm] = useState<IntakeForm>({ firearm_type: 'handgun' });
  const [status, setStatus] = useState('');

  const refresh = async () => {
    setLoading(true);
    try {
      const r = await get<{ items: Intake[] }>('/used-firearms', { status, limit: 100 });
      setRows(r.items || []);
    } finally { setLoading(false); }
  };
  useEffect(() => {
    refresh();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status]);

  const create = async () => {
    if (!form.customer_name || !form.manufacturer || !form.serial_number) return;
    const res = await post<{
      identity?: { decision?: string; score?: number };
      intake: { intake_number: string };
    }>('/used-firearms', form);
    if (res?.identity?.decision) {
      const d = res.identity.decision;
      const score = res.identity.score?.toFixed?.(3);
      alert(`Intake #${res.intake.intake_number} created — identity decision: ${d} (score ${score})`);
    }
    setForm({ firearm_type: 'handgun' });
    setShowNew(false);
    await refresh();
  };

  const payout = async (id: number) => {
    const amount = parseFloat(prompt('Payout amount?') || '0');
    if (!amount) return;
    const method = prompt('Payout method (cash / check / store_credit)?', 'cash') || 'cash';
    const reference = method === 'check' ? (prompt('Check number?') ?? '') : '';
    try {
      await post(`/used-firearms/${id}/payout`, { payout_amount: amount, payout_method: method, payout_reference: reference });
      await refresh();
    } catch (e) { alert(errorMessage(e, 'Payout failed')); }
  };

  const listForSale = async (id: number) => {
    const wc = prompt('Linked Woo product ID (optional, blank to just flip status):');
    await post(`/used-firearms/${id}/list`, wc ? { wc_product_id: parseInt(wc) } : {});
    await refresh();
  };

  const cols: Column<Intake>[] = [
    { key: 'intake_number', label: 'Intake' },
    { key: 'customer_name', label: 'Customer' },
    { key: 'firearm', label: 'Firearm', render: (r) => `${r.manufacturer} ${r.model}` },
    { key: 'serial_number', label: 'Serial', render: (r) => <span className="font-mono text-xs">{r.serial_number}</span> },
    { key: 'caliber', label: 'Caliber' },
    { key: 'condition_grade', label: 'Cond.' },
    { key: 'identity', label: 'Identity', render: (r) => r.identity_id
      ? <span className="text-xs">#{r.identity_id} <span className="text-zinc-500">({r.identity_confidence ? parseFloat(r.identity_confidence).toFixed(2) : '?'})</span></span>
      : '—' },
    { key: 'appraised_value', label: 'Appraised', align: 'right', render: (r) => r.appraised_value ? `$${parseFloat(r.appraised_value).toFixed(2)}` : '—' },
    { key: 'payout_amount', label: 'Paid', align: 'right', render: (r) => r.payout_amount && parseFloat(r.payout_amount) > 0 ? `$${parseFloat(r.payout_amount).toFixed(2)} ${r.payout_method ? '(' + r.payout_method + ')' : ''}` : '—' },
    { key: 'status', label: 'Status', render: (r) => <span className={STATUS_BADGE[r.status] || 'badge-zinc'}>{r.status}</span> },
    { key: 'actions', label: '', render: (r) => (
      <div className="flex gap-1 justify-end">
        {r.status === 'received' && <button className="btn-sm" onClick={() => payout(r.id)}>Pay out</button>}
        {(r.status === 'paid' || r.status === 'appraised') && <button className="btn-sm" onClick={() => listForSale(r.id)}>List</button>}
      </div>
    ) },
  ];

  return (
    <div>
      <PageHeader
        title="Used-Firearm Intake"
        subtitle="Customer brings in a used gun; the shop buys it outright. Distinct from consignment (we hold for them) and trade-in (immediate store credit toward another purchase). On receipt, the gun is auto-resolved to a canonical item identity in the Identity Graph — so used and new stock of the same make/model share one catalog truth."
        actions={<button className="btn-primary" onClick={() => setShowNew((s) => !s)}>{showNew ? 'Close' : '+ New intake'}</button>}
      />

      {showNew && (
        <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
          <input className="input" placeholder="Customer name *" value={form.customer_name ?? ''} onChange={(e) => setForm({ ...form, customer_name: e.target.value })} />
          <input className="input" placeholder="Customer ID (numeric)" value={form.customer_id ?? ''} onChange={(e) => setForm({ ...form, customer_id: parseInt(e.target.value) })} />
          <input className="input" placeholder="ID number (DL / passport)" value={form.customer_id_number ?? ''} onChange={(e) => setForm({ ...form, customer_id_number: e.target.value })} />
          <input className="input" placeholder="Phone" value={form.customer_phone ?? ''} onChange={(e) => setForm({ ...form, customer_phone: e.target.value })} />
          <input className="input" placeholder="Email" value={form.customer_email ?? ''} onChange={(e) => setForm({ ...form, customer_email: e.target.value })} />
          <input className="input" placeholder="Address" value={form.customer_address ?? ''} onChange={(e) => setForm({ ...form, customer_address: e.target.value })} />

          <input className="input" placeholder="Manufacturer *" value={form.manufacturer ?? ''} onChange={(e) => setForm({ ...form, manufacturer: e.target.value })} />
          <input className="input" placeholder="Model" value={form.model ?? ''} onChange={(e) => setForm({ ...form, model: e.target.value })} />
          <input className="input" placeholder="Serial number *" value={form.serial_number ?? ''} onChange={(e) => setForm({ ...form, serial_number: e.target.value })} />
          <select className="input" value={form.firearm_type} onChange={(e) => setForm({ ...form, firearm_type: e.target.value })}>
            <option value="handgun">handgun</option>
            <option value="long_gun">long_gun</option>
            <option value="rifle">rifle</option>
            <option value="shotgun">shotgun</option>
          </select>
          <input className="input" placeholder="Caliber" value={form.caliber ?? ''} onChange={(e) => setForm({ ...form, caliber: e.target.value })} />
          <input className="input" type="number" step="0.01" placeholder="Barrel length (in)" value={form.barrel_length_in ?? ''} onChange={(e) => setForm({ ...form, barrel_length_in: parseFloat(e.target.value) })} />

          <select className="input" value={form.condition_grade ?? ''} onChange={(e) => setForm({ ...form, condition_grade: e.target.value })}>
            <option value="">Condition grade…</option>
            {CONDITIONS.map((c) => <option key={c[0]} value={c[0]}>{c}</option>)}
          </select>
          <input className="input" type="number" step="0.01" placeholder="Appraised value $" value={form.appraised_value ?? ''} onChange={(e) => setForm({ ...form, appraised_value: parseFloat(e.target.value) })} />
          <textarea className="input col-span-2 md:col-span-3" rows={2} placeholder="Condition notes" value={form.condition_notes ?? ''} onChange={(e) => setForm({ ...form, condition_notes: e.target.value })} />

          <div className="col-span-2 md:col-span-3 flex justify-end gap-2">
            <button className="btn" onClick={() => setShowNew(false)}>Cancel</button>
            <button className="btn-primary" onClick={create}>Receive intake</button>
          </div>
        </div>
      )}

      <div className="card p-3 mb-4 flex gap-2">
        <select className="input" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All statuses</option>
          <option value="received">received</option>
          <option value="appraised">appraised</option>
          <option value="paid">paid</option>
          <option value="listed">listed</option>
          <option value="sold">sold</option>
          <option value="rejected">rejected</option>
        </select>
      </div>

      <DataTable<Intake> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No intakes yet." />
    </div>
  );
}
