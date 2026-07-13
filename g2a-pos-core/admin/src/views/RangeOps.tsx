import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Shift { id: number; user_id: number; shift_start: string; shift_end: string; status: string }
interface Rental { id: number; customer_name: string; caliber: string; rounds_issued: number; rounds_returned: number; total_charged_cents: number; issued_at: string; closed_at: string | null }
interface Brass { id: number; customer_name: string; caliber: string; weight_lbs: string | null; count_rounds: number | null; payout_cents: number; payout_method: string; received_at: string }

interface RsoForm { user_id?: number; shift_start?: string; shift_end?: string }
interface AmmoForm { customer_name?: string; caliber?: string; rounds_issued?: number; unit_price_cents?: number }
interface BrassForm {
  customer_name?: string; customer_id?: number; caliber?: string;
  weight_lbs?: number; count_rounds?: number; payout?: number; payout_method: string;
}

export default function RangeOps() {
  const [tab, setTab] = useState<'rso' | 'ammo' | 'brass'>('rso');
  const [shifts, setShifts] = useState<Shift[]>([]);
  const [rentals, setRentals] = useState<Rental[]>([]);
  const [brass, setBrass] = useState<Brass[]>([]);
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [rsoForm, setRsoForm] = useState<RsoForm>({});
  const [ammoForm, setAmmoForm] = useState<AmmoForm>({});
  const [brassForm, setBrassForm] = useState<BrassForm>({ payout_method: 'store_credit' });

  const refresh = async () => {
    const [s, a, b] = await Promise.all([
      get<{ shifts: Shift[] }>('/range/rso', { date }),
      get<{ items: Rental[] }>('/range/ammo-rentals'),
      get<{ items: Brass[] }>('/range/brass'),
    ]);
    setShifts(s.shifts || []);
    setRentals(a.items || []);
    setBrass(b.items || []);
  };
  useEffect(() => {
    queueMicrotask(refresh);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [date]);

  const assignRso = async () => {
    if (!rsoForm.user_id || !rsoForm.shift_start || !rsoForm.shift_end) return;
    await post('/range/rso', rsoForm);
    setRsoForm({});
    await refresh();
  };
  const updateRso = async (id: number, status: string) => { await post(`/range/rso/${id}/status`, { status }); await refresh(); };

  const issueAmmo = async () => {
    if (!ammoForm.customer_name || !ammoForm.caliber || !ammoForm.rounds_issued) return;
    await post('/range/ammo-rentals', ammoForm);
    setAmmoForm({});
    await refresh();
  };
  const closeAmmo = async (id: number) => {
    const r = parseInt(prompt('Rounds returned?') || '0');
    await post(`/range/ammo-rentals/${id}/close`, { rounds_returned: r });
    await refresh();
  };

  const recordBrass = async () => {
    if (!brassForm.caliber) return;
    await post('/range/brass', brassForm);
    setBrassForm({ payout_method: 'store_credit' });
    await refresh();
  };

  const rsoCols: Column<Shift>[] = [
    { key: 'user_id', label: 'User ID' },
    { key: 'shift_start', label: 'Start' },
    { key: 'shift_end', label: 'End' },
    { key: 'status', label: 'Status', render: (r) => <span className="badge-zinc">{r.status}</span> },
    { key: 'actions', label: '', render: (r) => (
      <div className="flex gap-1 justify-end">
        {r.status === 'scheduled' && <button className="btn-sm" onClick={() => updateRso(r.id, 'on_duty')}>On duty</button>}
        {r.status === 'on_duty' && <button className="btn-sm" onClick={() => updateRso(r.id, 'off_duty')}>Off duty</button>}
      </div>
    ) },
  ];

  const ammoCols: Column<Rental>[] = [
    { key: 'customer_name', label: 'Customer' },
    { key: 'caliber', label: 'Caliber' },
    { key: 'rounds_issued', label: 'Issued', align: 'right' },
    { key: 'rounds_returned', label: 'Returned', align: 'right' },
    { key: 'total_charged_cents', label: 'Charge', align: 'right', render: (r) => `$${(r.total_charged_cents/100).toFixed(2)}` },
    { key: 'issued_at', label: 'Issued at' },
    { key: 'actions', label: '', render: (r) => !r.closed_at && <button className="btn-sm" onClick={() => closeAmmo(r.id)}>Close</button> },
  ];

  const brassCols: Column<Brass>[] = [
    { key: 'customer_name', label: 'Customer' },
    { key: 'caliber', label: 'Caliber' },
    { key: 'weight_lbs', label: 'Lbs', align: 'right' },
    { key: 'count_rounds', label: 'Rounds', align: 'right' },
    { key: 'payout_cents', label: 'Payout', align: 'right', render: (r) => `$${(r.payout_cents/100).toFixed(2)}` },
    { key: 'payout_method', label: 'Method' },
    { key: 'received_at', label: 'Received' },
  ];

  return (
    <div>
      <PageHeader title="Range Operations" subtitle="RSO shifts, ammo rentals, brass buyback (auto-credits store credit)." />

      <div className="mb-4 flex gap-2">
        <button className={tab === 'rso' ? 'btn-primary' : 'btn'} onClick={() => setTab('rso')}>RSO shifts</button>
        <button className={tab === 'ammo' ? 'btn-primary' : 'btn'} onClick={() => setTab('ammo')}>Ammo rentals</button>
        <button className={tab === 'brass' ? 'btn-primary' : 'btn'} onClick={() => setTab('brass')}>Brass buyback</button>
      </div>

      {tab === 'rso' && (
        <>
          <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
            <input className="input" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
            <input className="input" type="number" placeholder="User ID" value={rsoForm.user_id ?? ''} onChange={(e) => setRsoForm({...rsoForm, user_id: parseInt(e.target.value)})} />
            <input className="input" type="datetime-local" value={rsoForm.shift_start ?? ''} onChange={(e) => setRsoForm({...rsoForm, shift_start: e.target.value.replace('T', ' ') + ':00'})} />
            <input className="input" type="datetime-local" value={rsoForm.shift_end ?? ''} onChange={(e) => setRsoForm({...rsoForm, shift_end: e.target.value.replace('T', ' ') + ':00'})} />
            <button className="btn-primary md:col-span-4" onClick={assignRso}>Assign RSO</button>
          </div>
          <DataTable<Shift> rows={shifts} columns={rsoCols} loading={false} rowKey={(r) => r.id} empty="No shifts." />
        </>
      )}

      {tab === 'ammo' && (
        <>
          <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
            <input className="input" placeholder="Customer" value={ammoForm.customer_name ?? ''} onChange={(e) => setAmmoForm({...ammoForm, customer_name: e.target.value})} />
            <input className="input" placeholder="Caliber" value={ammoForm.caliber ?? ''} onChange={(e) => setAmmoForm({...ammoForm, caliber: e.target.value})} />
            <input className="input" type="number" placeholder="Rounds" value={ammoForm.rounds_issued ?? ''} onChange={(e) => setAmmoForm({...ammoForm, rounds_issued: parseInt(e.target.value)})} />
            <input className="input" type="number" placeholder="¢/round" value={ammoForm.unit_price_cents ?? ''} onChange={(e) => setAmmoForm({...ammoForm, unit_price_cents: parseInt(e.target.value)})} />
            <button className="btn-primary md:col-span-4" onClick={issueAmmo}>Issue rental</button>
          </div>
          <DataTable<Rental> rows={rentals} columns={ammoCols} loading={false} rowKey={(r) => r.id} empty="No rentals." />
        </>
      )}

      {tab === 'brass' && (
        <>
          <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
            <input className="input" placeholder="Customer" value={brassForm.customer_name ?? ''} onChange={(e) => setBrassForm({...brassForm, customer_name: e.target.value})} />
            <input className="input" type="number" placeholder="Customer ID (for credit)" value={brassForm.customer_id ?? ''} onChange={(e) => setBrassForm({...brassForm, customer_id: parseInt(e.target.value)})} />
            <input className="input" placeholder="Caliber" value={brassForm.caliber ?? ''} onChange={(e) => setBrassForm({...brassForm, caliber: e.target.value})} />
            <input className="input" type="number" step="0.01" placeholder="Weight lbs" value={brassForm.weight_lbs ?? ''} onChange={(e) => setBrassForm({...brassForm, weight_lbs: parseFloat(e.target.value)})} />
            <input className="input" type="number" placeholder="Round count" value={brassForm.count_rounds ?? ''} onChange={(e) => setBrassForm({...brassForm, count_rounds: parseInt(e.target.value)})} />
            <input className="input" type="number" step="0.01" placeholder="Payout $" value={brassForm.payout ?? ''} onChange={(e) => setBrassForm({...brassForm, payout: parseFloat(e.target.value)})} />
            <select className="input" value={brassForm.payout_method} onChange={(e) => setBrassForm({...brassForm, payout_method: e.target.value})}>
              <option value="store_credit">Store credit</option>
              <option value="cash">Cash</option>
            </select>
            <button className="btn-primary" onClick={recordBrass}>Record buyback</button>
          </div>
          <DataTable<Brass> rows={brass} columns={brassCols} loading={false} rowKey={(r) => r.id} empty="No buybacks." />
        </>
      )}
    </div>
  );
}
