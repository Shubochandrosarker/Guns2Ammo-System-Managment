import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface ClassRow {
  id: number;
  class_code: string;
  title: string;
  duration_minutes: number;
  capacity: number;
  price_amount: string;
}

interface Session {
  id: number;
  class_id: number;
  title: string;
  class_code: string;
  starts_at: string;
  ends_at: string;
  seats_taken: number;
  capacity: number;
  price_amount: string;
  status: string;
}

export default function Classes() {
  const [classes, setClasses] = useState<ClassRow[]>([]);
  const [sessions, setSessions] = useState<Session[]>([]);
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState<'sessions' | 'catalog'>('sessions');
  const [newClass, setNewClass] = useState<Partial<{
    class_code: string;
    title: string;
    duration_minutes: number;
    capacity: number;
    price_amount: number;
  }>>({});
  const [newSession, setNewSession] = useState<Partial<{
    class_id: number;
    starts_at: string;
    ends_at: string;
  }>>({});

  const refresh = async () => {
    setLoading(true);
    try {
      const [cs, ss] = await Promise.all([
        get<{ classes: ClassRow[] }>('/classes'),
        get<{ sessions: Session[] }>('/classes/sessions'),
      ]);
      setClasses(cs.classes || []);
      setSessions(ss.sessions || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { refresh(); }, []);

  const createClass = async () => {
    if (!newClass.class_code || !newClass.title) return;
    await post('/classes', newClass);
    setNewClass({});
    await refresh();
  };

  const createSession = async () => {
    if (!newSession.class_id || !newSession.starts_at || !newSession.ends_at) return;
    await post('/classes/sessions', newSession);
    setNewSession({});
    await refresh();
  };

  const enroll = async (sessionId: number) => {
    const name = prompt('Student name?');
    if (!name) return;
    const email = prompt('Email?') ?? '';
    await post(`/classes/sessions/${sessionId}/enroll`, { customer_name: name, customer_email: email });
    await refresh();
  };

  const classCols: Column<ClassRow>[] = [
    { key: 'class_code', label: 'Code' },
    { key: 'title', label: 'Title' },
    { key: 'duration_minutes', label: 'Duration (min)', align: 'right' },
    { key: 'capacity', label: 'Capacity', align: 'right' },
    { key: 'price_amount', label: 'Price', align: 'right', render: (r) => `$${parseFloat(r.price_amount).toFixed(2)}` },
  ];

  const sessionCols: Column<Session>[] = [
    { key: 'starts_at', label: 'Starts' },
    { key: 'title', label: 'Class', render: (r) => `${r.title} (${r.class_code})` },
    { key: 'seats', label: 'Seats', align: 'right', render: (r) => `${r.seats_taken} / ${r.capacity}` },
    { key: 'price', label: 'Price', align: 'right', render: (r) => `$${parseFloat(r.price_amount).toFixed(2)}` },
    { key: 'status', label: 'Status', render: (r) => <span className="badge-zinc">{r.status}</span> },
    { key: 'actions', label: '', render: (r) => (
      <button className="btn-sm" onClick={() => enroll(r.id)} disabled={r.seats_taken >= r.capacity}>Enroll</button>
    ) },
  ];

  return (
    <div>
      <PageHeader title="Classes & Training" subtitle="CCW, intro to pistol, etc. Course catalog + scheduled sessions + roster." />

      <div className="mb-4 flex gap-2">
        <button className={tab === 'sessions' ? 'btn-primary' : 'btn'} onClick={() => setTab('sessions')}>Sessions</button>
        <button className={tab === 'catalog' ? 'btn-primary' : 'btn'} onClick={() => setTab('catalog')}>Catalog</button>
      </div>

      {tab === 'catalog' && (
        <>
          <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3 md:grid-cols-4">
            <input className="input" placeholder="Code (e.g. CCW-101)" value={newClass.class_code ?? ''} onChange={(e) => setNewClass({...newClass, class_code: e.target.value})} />
            <input className="input" placeholder="Title" value={newClass.title ?? ''} onChange={(e) => setNewClass({...newClass, title: e.target.value})} />
            <input className="input" type="number" placeholder="Duration min" value={newClass.duration_minutes ?? ''} onChange={(e) => setNewClass({...newClass, duration_minutes: parseInt(e.target.value)})} />
            <input className="input" type="number" placeholder="Capacity" value={newClass.capacity ?? ''} onChange={(e) => setNewClass({...newClass, capacity: parseInt(e.target.value)})} />
            <input className="input" type="number" step="0.01" placeholder="Price" value={newClass.price_amount ?? ''} onChange={(e) => setNewClass({...newClass, price_amount: parseFloat(e.target.value)})} />
            <button className="btn-primary col-span-3" onClick={createClass}>Save class</button>
          </div>
          <DataTable<ClassRow> rows={classes} columns={classCols} loading={loading} rowKey={(r) => r.id} empty="No classes yet." />
        </>
      )}

      {tab === 'sessions' && (
        <>
          <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3 md:grid-cols-4">
            <select className="input" value={newSession.class_id ?? ''} onChange={(e) => setNewSession({...newSession, class_id: parseInt(e.target.value)})}>
              <option value="">Class…</option>
              {classes.map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}
            </select>
            <input className="input" type="datetime-local" value={newSession.starts_at ?? ''} onChange={(e) => setNewSession({...newSession, starts_at: e.target.value.replace('T', ' ') + ':00'})} />
            <input className="input" type="datetime-local" value={newSession.ends_at ?? ''} onChange={(e) => setNewSession({...newSession, ends_at: e.target.value.replace('T', ' ') + ':00'})} />
            <button className="btn-primary" onClick={createSession}>Schedule session</button>
          </div>
          <DataTable<Session> rows={sessions} columns={sessionCols} loading={loading} rowKey={(r) => r.id} empty="No upcoming sessions." />
        </>
      )}
    </div>
  );
}
