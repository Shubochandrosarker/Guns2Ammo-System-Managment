import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';

interface Sub {
  url: string;
  secret: string;
  events: string[];
  enabled: boolean;
}

export default function Webhooks() {
  const [events, setEvents] = useState<string[]>([]);
  const [subs, setSubs] = useState<Sub[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    (async () => {
      try {
        const r = await get<{ events: string[]; subscribers: Sub[] }>('/webhooks');
        setEvents(r.events || []);
        setSubs(r.subscribers || []);
      } finally { setLoading(false); }
    })();
  }, []);

  const save = async () => {
    setSaving(true);
    try {
      await post('/webhooks', { subscribers: subs });
    } finally { setSaving(false); }
  };

  const update = (i: number, patch: Partial<Sub>) => {
    setSubs((s) => s.map((sub, j) => j === i ? { ...sub, ...patch } : sub));
  };
  const addSub = () => setSubs((s) => [...s, { url: '', secret: '', events: [], enabled: true }]);
  const remove = (i: number) => setSubs((s) => s.filter((_, j) => j !== i));

  return (
    <div>
      <PageHeader
        title="Webhooks"
        subtitle="Outbound HTTP webhooks signed with HMAC SHA-256."
        actions={
          <>
            <button className="btn-secondary" onClick={addSub}>Add subscriber</button>
            <button className="btn-primary" onClick={save} disabled={saving}>{saving ? 'Saving…' : 'Save'}</button>
          </>
        }
      />
      {loading ? <div className="text-sm text-zinc-500">Loading…</div> : (
        <div className="space-y-4">
          {subs.length === 0 && <div className="card p-6 text-sm text-zinc-500">No subscribers. Add one above.</div>}
          {subs.map((s, i) => (
            <div key={i} className="card p-5">
              <div className="grid gap-3 md:grid-cols-2">
                <label className="text-sm">
                  <div className="mb-1 font-medium">Webhook URL</div>
                  <input className="input" value={s.url} onChange={(e) => update(i, { url: e.target.value })} placeholder="https://hooks.example.com/g2a" />
                </label>
                <label className="text-sm">
                  <div className="mb-1 font-medium">Signing secret</div>
                  <input className="input" value={s.secret} onChange={(e) => update(i, { secret: e.target.value })} placeholder="shared secret" />
                </label>
              </div>
              <div className="mt-4">
                <div className="mb-2 text-sm font-medium">Subscribed events</div>
                <div className="flex flex-wrap gap-2">
                  {events.map((ev) => {
                    const on = s.events.includes(ev);
                    return (
                      <button
                        key={ev}
                        onClick={() => update(i, { events: on ? s.events.filter((e) => e !== ev) : [...s.events, ev] })}
                        className={`rounded-full px-3 py-1 text-xs font-medium ${on ? 'bg-brand text-white' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300'}`}
                      >
                        {ev}
                      </button>
                    );
                  })}
                </div>
              </div>
              <div className="mt-4 flex items-center justify-between">
                <label className="flex items-center gap-2 text-sm">
                  <input type="checkbox" checked={s.enabled} onChange={(e) => update(i, { enabled: e.target.checked })} />
                  Enabled
                </label>
                <button className="text-sm text-rose-600 hover:underline" onClick={() => remove(i)}>Remove</button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
