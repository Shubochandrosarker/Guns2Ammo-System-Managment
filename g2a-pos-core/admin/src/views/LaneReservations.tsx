import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Reservation {
  id: number;
  lane_code: string;
  customer_name: string;
  party_size: number;
  starts_at: string;
  ends_at: string;
  status: string;
  source?: string;
  read_only?: boolean;
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
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [rows, setRows] = useState<Reservation[]>([]);
  const [loading, setLoading] = useState(true);
  const [engineActive, setEngineActive] = useState(false);
  const [form, setForm] = useState<ReservationForm>({ party_size: 1 });

  const refresh = async () => {
    setLoading(true);
    try {
      const r = await get<{ reservations: Reservation[]; engine_bookings?: Reservation[]; engine_active?: boolean }>('/range/lanes', { date });
      const pos = (r.reservations || []).map((x) => ({ ...x, source: x.source || 'pos' }));
      const engine = (r.engine_bookings || []).map((x) => ({ ...x, read_only: true }));
      setEngineActive(!!r.engine_active);
      setRows([...pos, ...engine].sort((a, b) => String(a.starts_at).localeCompare(String(b.starts_at))));
    } finally { setLoading(false); }
  };
  useEffect(() => {
    queueMicrotask(refresh);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [date]);

  const reserve = async () => {
    if (!form.lane_code || !form.customer_name || !form.starts_at || !form.ends_at) return;
    const res = await post<{ ok?: boolean; error?: string }>('/range/lanes/reserve', form);
    if (!res?.ok) alert('Could not reserve: ' + (res?.error ?? 'unknown'));
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
    { key: 'source', label: 'Source', render: (r) => (
      r.source === 'booking-engine'
        ? <span className="badge-amber">web booking</span>
        : <span className="badge-zinc">POS</span>
    ) },
    { key: 'party_size', label: 'Party', align: 'right' },
    { key: 'starts_at', label: 'Starts' },
    { key: 'ends_at', label: 'Ends' },
    { key: 'status', label: 'Status', render: (r) => <span className="badge-zinc">{r.status}</span> },
    { key: 'actions', label: '', render: (r) => (
      r.read_only ? (
        <div className="text-right text-xs text-zinc-400">read-only</div>
      ) : (
        <div className="flex gap-1 justify-end">
          {r.status === 'reserved' && <button className="btn-sm" onClick={() => update(r.id, 'checked_in')}>Check in</button>}
          {r.status === 'checked_in' && <button className="btn-sm" onClick={() => update(r.id, 'checked_out')}>Check out</button>}
          {r.status === 'reserved' && <button className="btn-sm" onClick={() => update(r.id, 'no_show')}>No-show</button>}
        </div>
      )
    ) },
  ];

  return (
    <div>
      <PageHeader title="Lane Reservations" subtitle="Conflict-checked range-lane bookings. Auto-blocks double-booking the same lane window. Web bookings from the booking engine appear read-only with a source badge." />
      <div className="card p-3 mb-4 flex items-center gap-2">
        <label className="text-sm">Date</label>
        <input className="input max-w-xs" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
        {engineActive && <span className="badge-green">Booking engine connected</span>}
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

      <DataTable<Reservation> rows={rows} columns={cols} loading={loading} rowKey={(r) => `${r.source || 'pos'}-${r.id}`} empty="No reservations for this day." />
    </div>
  );
}
