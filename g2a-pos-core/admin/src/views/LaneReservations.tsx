import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';
import { useAction, ActionFeedback } from '../components/useAction';

interface Reservation {
  id: number;
  lane_code: string;
  customer_name: string;
  party_size: number;
  starts_at: string;
  ends_at: string;
  status: string;
}

interface ReservationForm {
  lane_code?: string;
  customer_name?: string;
  customer_phone?: string;
  party_size: number;
  starts_at?: string;
  ends_at?: string;
}

export default function LaneReservations() {
  const { error, notice, setError } = useAction();
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [rows, setRows] = useState<Reservation[]>([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState<ReservationForm>({ party_size: 1 });

  const refresh = async () => {
    setLoading(true);
    try {
      const r = await get<{ reservations: Reservation[] }>('/range/lanes', { date });
      setRows(r.reservations || []);
    } finally { setLoading(false); }
  };
  useEffect(() => {
    queueMicrotask(refresh);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [date]);

  const reserve = async () => {
    if (!form.lane_code || !form.customer_name || !form.starts_at || !form.ends_at) return;
    const res = await post<{ ok?: boolean; error?: string }>('/range/lanes/reserve', form);
    if (!res?.ok) setError('Could not reserve: ' + (res?.error ?? 'unknown'));
    setForm({ party_size: 1 });
    await refresh();
  };

  const update = async (id: number, status: string) => {
    await post(`/range/lanes/${id}/status`, { status });
    await refresh();
  };

  const cols: Column<Reservation>[] = [
    { key: 'lane_code', label: 'Lane' },
    { key: 'customer_name', label: 'Customer' },
    { key: 'party_size', label: 'Party', align: 'right' },
    { key: 'starts_at', label: 'Starts' },
    { key: 'ends_at', label: 'Ends' },
    { key: 'status', label: 'Status', render: (r) => <span className="badge-zinc">{r.status}</span> },
    { key: 'actions', label: '', render: (r) => (
      <div className="flex gap-1 justify-end">
        {r.status === 'reserved' && <button className="btn-sm" onClick={() => update(r.id, 'checked_in')}>Check in</button>}
        {r.status === 'checked_in' && <button className="btn-sm" onClick={() => update(r.id, 'checked_out')}>Check out</button>}
        {r.status === 'reserved' && <button className="btn-sm" onClick={() => update(r.id, 'no_show')}>No-show</button>}
      </div>
    ) },
  ];

  return (
    <div>
      <PageHeader title="Lane Reservations" subtitle="Conflict-checked range-lane bookings. Auto-blocks double-booking the same lane window." />

      <ActionFeedback error={error} notice={notice} />
      <div className="card p-3 mb-4 flex items-center gap-2">
        <label className="text-sm">Date</label>
        <input className="input" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
      </div>

      <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3 md:grid-cols-4">
        <input className="input" placeholder="Lane (e.g. R-1)" value={form.lane_code ?? ''} onChange={(e) => setForm({...form, lane_code: e.target.value})} />
        <input className="input" placeholder="Customer" value={form.customer_name ?? ''} onChange={(e) => setForm({...form, customer_name: e.target.value})} />
        <input className="input" type="number" min={1} placeholder="Party" value={form.party_size} onChange={(e) => setForm({...form, party_size: parseInt(e.target.value)})} />
        <input className="input" placeholder="Phone" value={form.customer_phone ?? ''} onChange={(e) => setForm({...form, customer_phone: e.target.value})} />
        <input className="input" type="datetime-local" value={form.starts_at ?? ''} onChange={(e) => setForm({...form, starts_at: e.target.value.replace('T', ' ') + ':00'})} />
        <input className="input" type="datetime-local" value={form.ends_at ?? ''} onChange={(e) => setForm({...form, ends_at: e.target.value.replace('T', ' ') + ':00'})} />
        <button className="btn-primary col-span-2" onClick={reserve}>Reserve lane</button>
      </div>

      <DataTable<Reservation> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No reservations for this day." />
    </div>
  );
}
