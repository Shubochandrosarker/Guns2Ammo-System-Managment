import { useCallback, useEffect, useState } from 'react';
import { get, post, errorMessage } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Shift { id: number; user_id: number; shift_start: string; shift_end: string; status: string }
interface Rental { id: number; customer_name: string; caliber: string; rounds_issued: number; rounds_returned: number; total_charged_cents: number; issued_at: string; closed_at: string | null }
interface Brass { id: number; customer_name: string; caliber: string; weight_lbs: string | null; count_rounds: number | null; payout_cents: number; payout_method: string; received_at: string }
interface FirearmRental {
  id: number;
  serial_number: string;
  manufacturer: string | null;
  model: string | null;
  caliber: string | null;
  customer_name: string;
  id_type: string;
  id_last4: string;
  rate_cents: number;
  deposit_cents: number;
  total_charged_cents: number;
  status: string;
  issued_at: string;
  due_back_at: string | null;
  returned_at: string | null;
}

interface RsoForm { user_id?: number; shift_start?: string; shift_end?: string }
interface AmmoForm { customer_name?: string; caliber?: string; rounds_issued?: number; unit_price_cents?: number }
interface BrassForm {
  customer_name?: string; customer_id?: number; caliber?: string;
  weight_lbs?: number; count_rounds?: number; payout?: number; payout_method: string;
}
interface FirearmForm {
  serial_number?: string; manufacturer?: string; model?: string; caliber?: string;
  customer_name?: string; id_type?: string; id_last4?: string;
  rate?: number; deposit?: number; due_back_at?: string; condition_out?: string;
  id_verified: boolean; waiver_verified: boolean;
}

const emptyFirearmForm: FirearmForm = { id_verified: false, waiver_verified: false, id_type: 'drivers_license' };

const dollars = (cents: number) => `$${((cents || 0) / 100).toFixed(2)}`;

export default function RangeOps() {
  const [tab, setTab] = useState<'rso' | 'firearms' | 'ammo' | 'brass'>('rso');
  const [shifts, setShifts] = useState<Shift[]>([]);
  const [rentals, setRentals] = useState<Rental[]>([]);
  const [firearms, setFirearms] = useState<FirearmRental[]>([]);
  const [brass, setBrass] = useState<Brass[]>([]);
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [rsoForm, setRsoForm] = useState<RsoForm>({});
  const [ammoForm, setAmmoForm] = useState<AmmoForm>({});
  const [brassForm, setBrassForm] = useState<BrassForm>({ payout_method: 'store_credit' });
  const [firearmForm, setFirearmForm] = useState<FirearmForm>(emptyFirearmForm);

  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);

  // Return flow replaces the old window.prompt(): a modal so staff can record
  // condition and flag an incident, none of which a prompt could capture.
  const [returning, setReturning] = useState<FirearmRental | null>(null);
  const [returnForm, setReturnForm] = useState<{ condition_in: string; status: string; total?: number }>({ condition_in: '', status: 'returned' });
  const [closingAmmo, setClosingAmmo] = useState<Rental | null>(null);
  const [roundsReturned, setRoundsReturned] = useState('');

  const refresh = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      const [s, a, f, b] = await Promise.all([
        get<{ shifts: Shift[] }>('/range/rso', { date }),
        get<{ items: Rental[] }>('/range/ammo-rentals'),
        get<{ items: FirearmRental[] }>('/range/firearm-rentals'),
        get<{ items: Brass[] }>('/range/brass'),
      ]);
      setShifts(s.shifts || []);
      setRentals(a.items || []);
      setFirearms(f.items || []);
      setBrass(b.items || []);
    } catch (e) {
      setError(errorMessage(e, 'Could not load range operations.'));
    } finally {
      setLoading(false);
    }
  }, [date]);

  // Deferred via queueMicrotask so the first setState lands outside the effect
  // body — same pattern the rest of these views use (react-hooks/set-state-in-effect).
  useEffect(() => { queueMicrotask(refresh); }, [refresh]);

  // Every mutation runs through this so a failed call always surfaces a reason
  // instead of silently doing nothing, which is how the old handlers behaved.
  const run = async (fn: () => Promise<unknown>, success: string) => {
    setBusy(true);
    setError('');
    setNotice('');
    try {
      await fn();
      setNotice(success);
      await refresh();
      return true;
    } catch (e) {
      setError(errorMessage(e));
      return false;
    } finally {
      setBusy(false);
    }
  };

  const assignRso = () => {
    if (!rsoForm.user_id || !rsoForm.shift_start || !rsoForm.shift_end) {
      setError('User ID, shift start and shift end are all required.');
      return;
    }
    void run(async () => { await post('/range/rso', rsoForm); setRsoForm({}); }, 'RSO shift assigned.');
  };
  const updateRso = (id: number, status: string) =>
    void run(() => post(`/range/rso/${id}/status`, { status }), 'Shift updated.');

  const issueAmmo = () => {
    if (!ammoForm.customer_name || !ammoForm.caliber || !ammoForm.rounds_issued) {
      setError('Customer, caliber and rounds are required.');
      return;
    }
    void run(async () => { await post('/range/ammo-rentals', ammoForm); setAmmoForm({}); }, 'Ammo issued.');
  };

  const submitAmmoClose = () => {
    if (!closingAmmo) return;
    const r = parseInt(roundsReturned || '0', 10);
    if (Number.isNaN(r) || r < 0) { setError('Rounds returned must be zero or more.'); return; }
    if (r > closingAmmo.rounds_issued) { setError(`Cannot return more than the ${closingAmmo.rounds_issued} rounds issued.`); return; }
    void run(async () => {
      await post(`/range/ammo-rentals/${closingAmmo.id}/close`, { rounds_returned: r });
      setClosingAmmo(null);
      setRoundsReturned('');
    }, 'Ammo rental closed.');
  };

  const issueFirearm = () => {
    const f = firearmForm;
    if (!f.serial_number?.trim()) { setError('Serial number is required.'); return; }
    if (!f.customer_name?.trim()) { setError('Customer name is required.'); return; }
    // Mirrors the server-side rule so staff get the reason immediately rather
    // than a 422 after submitting.
    if (!f.id_verified) { setError('Photo ID must be checked before a firearm goes out.'); return; }
    if (!f.waiver_verified) { setError('A signed waiver must be confirmed before a firearm goes out.'); return; }

    void run(async () => {
      await post('/range/firearm-rentals', {
        ...f,
        rate_cents: Math.round((f.rate ?? 0) * 100),
        deposit_cents: Math.round((f.deposit ?? 0) * 100),
      });
      setFirearmForm(emptyFirearmForm);
    }, 'Firearm checked out.');
  };

  const submitFirearmReturn = () => {
    if (!returning) return;
    void run(async () => {
      await post(`/range/firearm-rentals/${returning.id}/return`, {
        condition_in: returnForm.condition_in,
        status: returnForm.status,
        ...(returnForm.total !== undefined ? { total_charged_cents: Math.round(returnForm.total * 100) } : {}),
      });
      setReturning(null);
      setReturnForm({ condition_in: '', status: 'returned' });
    }, 'Firearm returned.');
  };

  const recordBrass = () => {
    if (!brassForm.caliber) { setError('Caliber is required.'); return; }
    void run(async () => {
      await post('/range/brass', brassForm);
      setBrassForm({ payout_method: 'store_credit' });
    }, 'Buyback recorded.');
  };

  const rsoCols: Column<Shift>[] = [
    { key: 'user_id', label: 'User ID', sortable: true },
    { key: 'shift_start', label: 'Start', sortable: true },
    { key: 'shift_end', label: 'End', sortable: true },
    { key: 'status', label: 'Status', sortable: true, render: (r) => <span className="badge-zinc">{r.status}</span> },
    { key: 'actions', label: '', render: (r) => (
      <div className="flex gap-1 justify-end">
        {r.status === 'scheduled' && <button className="btn-sm" disabled={busy} onClick={() => updateRso(r.id, 'on_duty')}>On duty</button>}
        {r.status === 'on_duty' && <button className="btn-sm" disabled={busy} onClick={() => updateRso(r.id, 'off_duty')}>Off duty</button>}
      </div>
    ) },
  ];

  const firearmCols: Column<FirearmRental>[] = [
    { key: 'serial_number', label: 'Serial', sortable: true, render: (r) => <span className="font-mono text-xs">{r.serial_number}</span> },
    { key: 'firearm', label: 'Firearm', render: (r) => [r.manufacturer, r.model].filter(Boolean).join(' ') || '—' },
    { key: 'caliber', label: 'Caliber', sortable: true, render: (r) => r.caliber || '—' },
    { key: 'customer_name', label: 'Customer', sortable: true },
    { key: 'id_last4', label: 'ID', render: (r) => r.id_last4 ? <span className="font-mono text-xs">···{r.id_last4}</span> : '—' },
    { key: 'issued_at', label: 'Out', sortable: true },
    { key: 'due_back_at', label: 'Due', sortable: true, render: (r) => r.due_back_at || '—' },
    { key: 'status', label: 'Status', sortable: true, render: (r) => (
      <span className={r.status === 'overdue' || r.status === 'incident' ? 'badge-red' : 'badge-zinc'}>{r.status}</span>
    ) },
    { key: 'total_charged_cents', label: 'Charge', align: 'right', sortable: true, render: (r) => dollars(r.total_charged_cents) },
    { key: 'actions', label: '', render: (r) => (
      r.status !== 'returned' && (
        <div className="flex justify-end">
          <button className="btn-sm" disabled={busy} onClick={() => { setReturning(r); setReturnForm({ condition_in: '', status: 'returned' }); }}>
            Return
          </button>
        </div>
      )
    ) },
  ];

  const ammoCols: Column<Rental>[] = [
    { key: 'customer_name', label: 'Customer', sortable: true },
    { key: 'caliber', label: 'Caliber', sortable: true },
    { key: 'rounds_issued', label: 'Issued', align: 'right', sortable: true },
    { key: 'rounds_returned', label: 'Returned', align: 'right', sortable: true },
    { key: 'total_charged_cents', label: 'Charge', align: 'right', sortable: true, render: (r) => dollars(r.total_charged_cents) },
    { key: 'issued_at', label: 'Issued at', sortable: true },
    { key: 'actions', label: '', render: (r) => !r.closed_at && (
      <div className="flex justify-end">
        <button className="btn-sm" disabled={busy} onClick={() => { setClosingAmmo(r); setRoundsReturned(''); }}>Close</button>
      </div>
    ) },
  ];

  const brassCols: Column<Brass>[] = [
    { key: 'customer_name', label: 'Customer', sortable: true },
    { key: 'caliber', label: 'Caliber', sortable: true },
    { key: 'weight_lbs', label: 'Lbs', align: 'right', sortable: true },
    { key: 'count_rounds', label: 'Rounds', align: 'right', sortable: true },
    { key: 'payout_cents', label: 'Payout', align: 'right', sortable: true, render: (r) => dollars(r.payout_cents) },
    { key: 'payout_method', label: 'Method', sortable: true },
    { key: 'received_at', label: 'Received', sortable: true },
  ];

  const outCount = firearms.filter((f) => f.status !== 'returned').length;
  const overdueCount = firearms.filter((f) => f.status === 'overdue').length;

  const tabs: Array<{ id: typeof tab; label: string; badge?: number }> = [
    { id: 'rso', label: 'RSO shifts' },
    { id: 'firearms', label: 'Firearm rentals', badge: outCount },
    { id: 'ammo', label: 'Ammo rentals' },
    { id: 'brass', label: 'Brass buyback' },
  ];

  return (
    <div>
      <PageHeader
        title="Range Operations"
        subtitle="RSO shifts, firearm rentals, ammo rentals, brass buyback (auto-credits store credit)."
      />

      {error && (
        <div className="mb-3 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">
          {error}
        </div>
      )}
      {notice && (
        <div className="mb-3 rounded border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800" role="status">
          {notice}
        </div>
      )}
      {overdueCount > 0 && tab !== 'firearms' && (
        <div className="mb-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
          {overdueCount} firearm rental{overdueCount === 1 ? ' is' : 's are'} overdue.{' '}
          <button className="underline" onClick={() => setTab('firearms')}>Review</button>
        </div>
      )}

      <div className="mb-4 flex flex-wrap gap-2" role="tablist">
        {tabs.map((t) => (
          <button
            key={t.id}
            role="tab"
            aria-selected={tab === t.id}
            className={tab === t.id ? 'btn-primary' : 'btn'}
            onClick={() => setTab(t.id)}
          >
            {t.label}
            {t.badge ? <span className="ml-2 badge-zinc">{t.badge}</span> : null}
          </button>
        ))}
      </div>

      {tab === 'rso' && (
        <>
          <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
            <label className="sr-only" htmlFor="rso-date">Date</label>
            <input id="rso-date" className="input" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
            <input className="input" type="number" placeholder="User ID" value={rsoForm.user_id ?? ''} onChange={(e) => setRsoForm({ ...rsoForm, user_id: parseInt(e.target.value, 10) })} />
            <input className="input" type="datetime-local" value={rsoForm.shift_start ?? ''} onChange={(e) => setRsoForm({ ...rsoForm, shift_start: e.target.value.replace('T', ' ') + ':00' })} />
            <input className="input" type="datetime-local" value={rsoForm.shift_end ?? ''} onChange={(e) => setRsoForm({ ...rsoForm, shift_end: e.target.value.replace('T', ' ') + ':00' })} />
            <button className="btn-primary md:col-span-4" disabled={busy} onClick={assignRso}>Assign RSO</button>
          </div>
          <DataTable<Shift> rows={shifts} columns={rsoCols} loading={loading} rowKey={(r) => r.id} empty="No shifts." />
        </>
      )}

      {tab === 'firearms' && (
        <>
          <div className="card p-4 mb-4">
            <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500">Check out a firearm</h3>
            <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
              <input className="input" placeholder="Serial number *" value={firearmForm.serial_number ?? ''} onChange={(e) => setFirearmForm({ ...firearmForm, serial_number: e.target.value })} />
              <input className="input" placeholder="Manufacturer" value={firearmForm.manufacturer ?? ''} onChange={(e) => setFirearmForm({ ...firearmForm, manufacturer: e.target.value })} />
              <input className="input" placeholder="Model" value={firearmForm.model ?? ''} onChange={(e) => setFirearmForm({ ...firearmForm, model: e.target.value })} />
              <input className="input" placeholder="Caliber" value={firearmForm.caliber ?? ''} onChange={(e) => setFirearmForm({ ...firearmForm, caliber: e.target.value })} />

              <input className="input" placeholder="Customer name *" value={firearmForm.customer_name ?? ''} onChange={(e) => setFirearmForm({ ...firearmForm, customer_name: e.target.value })} />
              <select className="input" value={firearmForm.id_type ?? 'drivers_license'} onChange={(e) => setFirearmForm({ ...firearmForm, id_type: e.target.value })}>
                <option value="drivers_license">Driver&apos;s license</option>
                <option value="state_id">State ID</option>
                <option value="passport">Passport</option>
                <option value="military_id">Military ID</option>
              </select>
              <input className="input" maxLength={4} placeholder="ID last 4" value={firearmForm.id_last4 ?? ''} onChange={(e) => setFirearmForm({ ...firearmForm, id_last4: e.target.value })} />
              <input className="input" type="datetime-local" value={firearmForm.due_back_at ?? ''} onChange={(e) => setFirearmForm({ ...firearmForm, due_back_at: e.target.value.replace('T', ' ') + ':00' })} />

              <input className="input" type="number" step="0.01" min="0" placeholder="Rate $" value={firearmForm.rate ?? ''} onChange={(e) => setFirearmForm({ ...firearmForm, rate: parseFloat(e.target.value) })} />
              <input className="input" type="number" step="0.01" min="0" placeholder="Deposit $" value={firearmForm.deposit ?? ''} onChange={(e) => setFirearmForm({ ...firearmForm, deposit: parseFloat(e.target.value) })} />
              <input className="input md:col-span-2" placeholder="Condition on the way out" value={firearmForm.condition_out ?? ''} onChange={(e) => setFirearmForm({ ...firearmForm, condition_out: e.target.value })} />
            </div>

            <div className="mt-3 flex flex-col gap-2 rounded border border-amber-300 bg-amber-50 p-3 text-sm">
              <span className="font-medium text-amber-900">Required before the firearm leaves the counter</span>
              <label className="flex items-center gap-2 text-amber-900">
                <input type="checkbox" checked={firearmForm.id_verified} onChange={(e) => setFirearmForm({ ...firearmForm, id_verified: e.target.checked })} />
                Photo ID checked and matches the customer
              </label>
              <label className="flex items-center gap-2 text-amber-900">
                <input type="checkbox" checked={firearmForm.waiver_verified} onChange={(e) => setFirearmForm({ ...firearmForm, waiver_verified: e.target.checked })} />
                Signed range waiver on file and current
              </label>
            </div>

            <button
              className="btn-primary mt-3 w-full"
              disabled={busy || !firearmForm.id_verified || !firearmForm.waiver_verified}
              onClick={issueFirearm}
            >
              Check out firearm
            </button>
          </div>

          <DataTable<FirearmRental> rows={firearms} columns={firearmCols} loading={loading} rowKey={(r) => r.id} empty="No firearms currently out." />
        </>
      )}

      {tab === 'ammo' && (
        <>
          <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
            <input className="input" placeholder="Customer" value={ammoForm.customer_name ?? ''} onChange={(e) => setAmmoForm({ ...ammoForm, customer_name: e.target.value })} />
            <input className="input" placeholder="Caliber" value={ammoForm.caliber ?? ''} onChange={(e) => setAmmoForm({ ...ammoForm, caliber: e.target.value })} />
            <input className="input" type="number" min="1" placeholder="Rounds" value={ammoForm.rounds_issued ?? ''} onChange={(e) => setAmmoForm({ ...ammoForm, rounds_issued: parseInt(e.target.value, 10) })} />
            <input className="input" type="number" min="0" placeholder="¢/round" value={ammoForm.unit_price_cents ?? ''} onChange={(e) => setAmmoForm({ ...ammoForm, unit_price_cents: parseInt(e.target.value, 10) })} />
            <button className="btn-primary md:col-span-4" disabled={busy} onClick={issueAmmo}>Issue rental</button>
          </div>
          <DataTable<Rental> rows={rentals} columns={ammoCols} loading={loading} rowKey={(r) => r.id} empty="No rentals." />
        </>
      )}

      {tab === 'brass' && (
        <>
          <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
            <input className="input" placeholder="Customer" value={brassForm.customer_name ?? ''} onChange={(e) => setBrassForm({ ...brassForm, customer_name: e.target.value })} />
            <input className="input" type="number" placeholder="Customer ID (for credit)" value={brassForm.customer_id ?? ''} onChange={(e) => setBrassForm({ ...brassForm, customer_id: parseInt(e.target.value, 10) })} />
            <input className="input" placeholder="Caliber" value={brassForm.caliber ?? ''} onChange={(e) => setBrassForm({ ...brassForm, caliber: e.target.value })} />
            <input className="input" type="number" step="0.01" placeholder="Weight lbs" value={brassForm.weight_lbs ?? ''} onChange={(e) => setBrassForm({ ...brassForm, weight_lbs: parseFloat(e.target.value) })} />
            <input className="input" type="number" placeholder="Round count" value={brassForm.count_rounds ?? ''} onChange={(e) => setBrassForm({ ...brassForm, count_rounds: parseInt(e.target.value, 10) })} />
            <input className="input" type="number" step="0.01" placeholder="Payout $" value={brassForm.payout ?? ''} onChange={(e) => setBrassForm({ ...brassForm, payout: parseFloat(e.target.value) })} />
            <select className="input" value={brassForm.payout_method} onChange={(e) => setBrassForm({ ...brassForm, payout_method: e.target.value })}>
              <option value="store_credit">Store credit</option>
              <option value="cash">Cash</option>
            </select>
            <button className="btn-primary" disabled={busy} onClick={recordBrass}>Record buyback</button>
          </div>
          <DataTable<Brass> rows={brass} columns={brassCols} loading={loading} rowKey={(r) => r.id} empty="No buybacks." />
        </>
      )}

      {returning && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-label="Return firearm">
          <div className="card w-full max-w-md p-4">
            <h3 className="mb-1 text-base font-semibold">Return firearm</h3>
            <p className="mb-3 text-sm text-zinc-500">
              <span className="font-mono">{returning.serial_number}</span> · {returning.customer_name}
            </p>
            <div className="grid gap-3">
              <label className="text-sm">
                Condition on return
                <input className="input mt-1 w-full" value={returnForm.condition_in} onChange={(e) => setReturnForm({ ...returnForm, condition_in: e.target.value })} placeholder="e.g. no damage, function checked" />
              </label>
              <label className="text-sm">
                Outcome
                <select className="input mt-1 w-full" value={returnForm.status} onChange={(e) => setReturnForm({ ...returnForm, status: e.target.value })}>
                  <option value="returned">Returned in good order</option>
                  <option value="incident">Incident — damage, loss, or not returned</option>
                </select>
              </label>
              <label className="text-sm">
                Final charge $ (optional)
                <input className="input mt-1 w-full" type="number" step="0.01" min="0" placeholder={((returning.total_charged_cents || 0) / 100).toFixed(2)} value={returnForm.total ?? ''} onChange={(e) => setReturnForm({ ...returnForm, total: e.target.value === '' ? undefined : parseFloat(e.target.value) })} />
              </label>
              {returnForm.status === 'incident' && (
                <p className="rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                  The rental stays on the outstanding list so it is not lost. Record what happened in the condition field.
                </p>
              )}
            </div>
            <div className="mt-4 flex justify-end gap-2">
              <button className="btn" disabled={busy} onClick={() => setReturning(null)}>Cancel</button>
              <button className="btn-primary" disabled={busy} onClick={submitFirearmReturn}>Confirm return</button>
            </div>
          </div>
        </div>
      )}

      {closingAmmo && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-label="Close ammo rental">
          <div className="card w-full max-w-sm p-4">
            <h3 className="mb-1 text-base font-semibold">Close ammo rental</h3>
            <p className="mb-3 text-sm text-zinc-500">
              {closingAmmo.customer_name} · {closingAmmo.rounds_issued} rounds issued
            </p>
            <label className="text-sm">
              Rounds returned
              <input className="input mt-1 w-full" type="number" min="0" max={closingAmmo.rounds_issued} value={roundsReturned} onChange={(e) => setRoundsReturned(e.target.value)} autoFocus />
            </label>
            <div className="mt-4 flex justify-end gap-2">
              <button className="btn" disabled={busy} onClick={() => setClosingAmmo(null)}>Cancel</button>
              <button className="btn-primary" disabled={busy} onClick={submitAmmoClose}>Close rental</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
