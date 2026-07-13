import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Item {
  id: number;
  nfa_class: string;
  manufacturer: string;
  model: string;
  serial_number: string;
  caliber: string;
  current_holder: string;
  status: string;
}

interface Trust {
  id: number;
  trust_name: string;
  grantor_name: string;
  trust_state: string;
  status: string;
}

const CLASSES = ['suppressor', 'sbr', 'sbs', 'machine_gun', 'aow', 'destructive_device'];

interface ItemForm {
  nfa_class: string;
  manufacturer?: string;
  model?: string;
  serial_number?: string;
  caliber?: string;
  current_holder?: string;
  two_stamp?: boolean;
}

interface TrustForm {
  trust_type: string;
  trust_name?: string;
  grantor_name?: string;
  trust_state?: string;
  grantor_phone?: string;
  grantor_email?: string;
}

export default function Nfa() {
  const [tab, setTab] = useState<'items' | 'trusts'>('items');
  const [items, setItems] = useState<Item[]>([]);
  const [trusts, setTrusts] = useState<Trust[]>([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState<ItemForm>({ nfa_class: 'suppressor' });
  const [trustForm, setTrustForm] = useState<TrustForm>({ trust_type: 'gun_trust' });

  const refresh = async () => {
    setLoading(true);
    try {
      const [it, tr] = await Promise.all([
        get<{ items: Item[] }>('/nfa/items'),
        get<{ items: Trust[] }>('/nfa/trusts'),
      ]);
      setItems(it.items || []);
      setTrusts(tr.items || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { queueMicrotask(refresh); }, []);

  const createItem = async () => {
    if (!form.serial_number || !form.manufacturer) return;
    await post('/nfa/items', form);
    setForm({ nfa_class: 'suppressor' });
    await refresh();
  };

  const fileForm = async (id: number) => {
    const ft = prompt('Form type (Form 3 / Form 4 / Form 5)?', 'Form 4');
    if (!ft) return;
    const stamp = parseFloat(prompt('Tax stamp $') || '200');
    await post(`/nfa/items/${id}/forms`, { form_type: ft, tax_stamp_amount: stamp });
    await refresh();
  };

  const createTrust = async () => {
    if (!trustForm.trust_name || !trustForm.grantor_name) return;
    await post('/nfa/trusts', trustForm);
    setTrustForm({ trust_type: 'gun_trust' });
    await refresh();
  };

  const itemCols: Column<Item>[] = [
    { key: 'nfa_class', label: 'Class', render: (r) => <span className="badge-zinc">{r.nfa_class}</span> },
    { key: 'manufacturer', label: 'Maker' },
    { key: 'model', label: 'Model' },
    { key: 'serial_number', label: 'Serial' },
    { key: 'caliber', label: 'Caliber' },
    { key: 'current_holder', label: 'Holder' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: '', render: (r) => (
      <button className="btn-sm" onClick={() => fileForm(r.id)}>File Form</button>
    ) },
  ];

  const trustCols: Column<Trust>[] = [
    { key: 'trust_name', label: 'Trust' },
    { key: 'grantor_name', label: 'Grantor' },
    { key: 'trust_state', label: 'State' },
    { key: 'status', label: 'Status' },
  ];

  return (
    <div>
      <PageHeader title="NFA Items (Class III / Title II)"
        subtitle="Class III / Title II inventory — suppressors, SBRs, SBSs, machine guns, AOWs, destructive devices. Kept in a separate ledger from the Title I bound book so an ACE audit isn't muddled. Tracks ATF Form 3 (FFL-to-FFL), Form 4 (FFL-to-individual), Form 5 (tax-exempt transfer), tax stamp status, CLE notification, two-stamp ownership, and gun trusts." />

      <div className="mb-4 flex gap-2">
        <button className={tab === 'items' ? 'btn-primary' : 'btn'} onClick={() => setTab('items')}>Items</button>
        <button className={tab === 'trusts' ? 'btn-primary' : 'btn'} onClick={() => setTab('trusts')}>Trusts</button>
      </div>

      {tab === 'items' && (
        <>
          <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3 md:grid-cols-4">
            <select className="input" value={form.nfa_class} onChange={(e) => setForm({...form, nfa_class: e.target.value})}>
              {CLASSES.map((c) => <option key={c} value={c}>{c}</option>)}
            </select>
            <input className="input" placeholder="Manufacturer" value={form.manufacturer ?? ''} onChange={(e) => setForm({...form, manufacturer: e.target.value})} />
            <input className="input" placeholder="Model" value={form.model ?? ''} onChange={(e) => setForm({...form, model: e.target.value})} />
            <input className="input" placeholder="Serial *" value={form.serial_number ?? ''} onChange={(e) => setForm({...form, serial_number: e.target.value})} />
            <input className="input" placeholder="Caliber" value={form.caliber ?? ''} onChange={(e) => setForm({...form, caliber: e.target.value})} />
            <input className="input" placeholder="Holder name" value={form.current_holder ?? ''} onChange={(e) => setForm({...form, current_holder: e.target.value})} />
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!form.two_stamp} onChange={(e) => setForm({...form, two_stamp: e.target.checked})} /> Two stamps</label>
            <button className="btn-primary" onClick={createItem}>Add item</button>
          </div>
          <DataTable<Item> rows={items} columns={itemCols} loading={loading} rowKey={(r) => r.id} empty="No NFA items." />
        </>
      )}

      {tab === 'trusts' && (
        <>
          <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3 md:grid-cols-3">
            <input className="input" placeholder="Trust name *" value={trustForm.trust_name ?? ''} onChange={(e) => setTrustForm({...trustForm, trust_name: e.target.value})} />
            <input className="input" placeholder="Grantor *" value={trustForm.grantor_name ?? ''} onChange={(e) => setTrustForm({...trustForm, grantor_name: e.target.value})} />
            <input className="input" placeholder="State (2-letter)" value={trustForm.trust_state ?? ''} onChange={(e) => setTrustForm({...trustForm, trust_state: e.target.value})} />
            <input className="input" placeholder="Phone" value={trustForm.grantor_phone ?? ''} onChange={(e) => setTrustForm({...trustForm, grantor_phone: e.target.value})} />
            <input className="input" placeholder="Email" value={trustForm.grantor_email ?? ''} onChange={(e) => setTrustForm({...trustForm, grantor_email: e.target.value})} />
            <button className="btn-primary" onClick={createTrust}>Add trust</button>
          </div>
          <DataTable<Trust> rows={trusts} columns={trustCols} loading={loading} rowKey={(r) => r.id} empty="No trusts." />
        </>
      )}
    </div>
  );
}
