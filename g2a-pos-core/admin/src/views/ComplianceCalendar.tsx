import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Event {
  id: number;
  event_type: string;
  title: string;
  due_at: string;
  advance_notice_days: number;
  recurrence: string | null;
  status: string;
  completed_at: string | null;
}

interface EventForm {
  event_type?: string;
  title?: string;
  due_at?: string;
  advance_notice_days: number;
  recurrence: string;
  description?: string;
}

const SAMPLE_TYPES = [
  { type: 'ffl_renewal', title: 'FFL renewal (Form 8)' },
  { type: 'employee_training', title: 'Employee compliance training refresh' },
  { type: 'ca_apps_renewal', title: 'CA APPS annual renewal' },
  { type: 'ny_safe_annual', title: 'NY SAFE annual return' },
  { type: 'multi_sale_report', title: 'Multi-sale long-gun report' },
  { type: 'custom', title: 'Custom' },
];

export default function ComplianceCalendar() {
  const [rows, setRows] = useState<Event[]>([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState<EventForm>({ advance_notice_days: 30, recurrence: '' });

  const refresh = async () => {
    setLoading(true);
    try {
      const r = await get<{ items: Event[] }>('/compliance/calendar');
      setRows(r.items || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { refresh(); }, []);

  const create = async () => {
    if (!form.title || !form.due_at) return;
    await post('/compliance/calendar', form);
    setForm({ advance_notice_days: 30, recurrence: '' });
    await refresh();
  };

  const complete = async (id: number) => { await post(`/compliance/calendar/${id}/complete`, {}); await refresh(); };

  const cols: Column<Event>[] = [
    { key: 'event_type', label: 'Type', render: (r) => <span className="badge-zinc">{r.event_type}</span> },
    { key: 'title', label: 'Title' },
    { key: 'due_at', label: 'Due' },
    { key: 'advance_notice_days', label: 'Notice days', align: 'right' },
    { key: 'recurrence', label: 'Recurrence' },
    { key: 'status', label: 'Status', render: (r) => <span className={r.status === 'open' ? 'badge-amber' : 'badge-green'}>{r.status}</span> },
    { key: 'actions', label: '', render: (r) => r.status === 'open' && <button className="btn-sm" onClick={() => complete(r.id)}>Complete</button> },
  ];

  return (
    <div>
      <PageHeader title="Compliance Calendar"
        subtitle="FFL renewal, employee training, CA APPS, NY SAFE, multi-sale reports. Daily cron auto-emails admin N days before due." />

      <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
        <select className="input" value={form.event_type ?? ''} onChange={(e) => {
          const sel = SAMPLE_TYPES.find((s) => s.type === e.target.value);
          setForm({...form, event_type: e.target.value, title: sel ? sel.title : form.title});
        }}>
          <option value="">Type…</option>
          {SAMPLE_TYPES.map((s) => <option key={s.type} value={s.type}>{s.title}</option>)}
        </select>
        <input className="input" placeholder="Title *" value={form.title ?? ''} onChange={(e) => setForm({...form, title: e.target.value})} />
        <input className="input" type="datetime-local" value={form.due_at ?? ''} onChange={(e) => setForm({...form, due_at: e.target.value.replace('T', ' ') + ':00'})} />
        <input className="input" type="number" placeholder="Notice days" value={form.advance_notice_days} onChange={(e) => setForm({...form, advance_notice_days: parseInt(e.target.value)})} />
        <select className="input" value={form.recurrence ?? ''} onChange={(e) => setForm({...form, recurrence: e.target.value})}>
          <option value="">No recurrence</option>
          <option value="monthly">Monthly</option>
          <option value="quarterly">Quarterly</option>
          <option value="biannual">Bi-annual</option>
          <option value="annual">Annual</option>
          <option value="triennial">Tri-annual (FFL)</option>
        </select>
        <textarea className="input md:col-span-3" rows={2} placeholder="Description" value={form.description ?? ''} onChange={(e) => setForm({...form, description: e.target.value})} />
        <button className="btn-primary md:col-span-4" onClick={create}>Schedule event</button>
      </div>

      <DataTable<Event> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No events scheduled." />
    </div>
  );
}
