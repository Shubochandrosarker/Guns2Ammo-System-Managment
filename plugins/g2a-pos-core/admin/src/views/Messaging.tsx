import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Msg {
  id: number;
  channel: string;
  template_key: string;
  to_address: string;
  subject: string;
  status: string;
  attempts: number;
  scheduled_at: string;
  sent_at: string | null;
  last_error: string | null;
}

interface Template {
  id: number;
  template_key: string;
  channel: string;
  subject: string;
  body: string;
  enabled: number;
}

interface SendForm {
  channel: string;
  template_key?: string;
  to_address?: string;
  subject?: string;
  body?: string;
}

interface TemplateForm {
  template_key?: string;
  channel: string;
  subject?: string;
  body?: string;
  enabled: number;
}

export default function Messaging() {
  const [tab, setTab] = useState<'outbox' | 'templates' | 'send'>('outbox');
  const [outbox, setOutbox] = useState<Msg[]>([]);
  const [templates, setTemplates] = useState<Template[]>([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState<SendForm>({ channel: 'email' });
  const [tpl, setTpl] = useState<TemplateForm>({ channel: 'email', enabled: 1 });

  const refresh = async () => {
    setLoading(true);
    try {
      const [o, t] = await Promise.all([
        get<{ items: Msg[] }>('/messaging/outbox'),
        get<{ items: Template[] }>('/messaging/templates'),
      ]);
      setOutbox(o.items || []);
      setTemplates(t.items || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { queueMicrotask(refresh); }, []);

  const send = async () => {
    if (!form.to_address || !form.body) return;
    await post('/messaging/send', form);
    setForm({ channel: 'email' });
    setTab('outbox');
    await refresh();
  };

  const flush = async () => { await post('/messaging/flush', {}); await refresh(); };
  const saveTpl = async () => { await post('/messaging/templates', tpl); setTpl({ channel: 'email', enabled: 1 }); await refresh(); };

  const cols: Column<Msg>[] = [
    { key: 'channel', label: 'Channel' },
    { key: 'to_address', label: 'To' },
    { key: 'subject', label: 'Subject' },
    { key: 'template_key', label: 'Template' },
    { key: 'status', label: 'Status', render: (r) => <span className="badge-zinc">{r.status}</span> },
    { key: 'attempts', label: 'Attempts', align: 'right' },
    { key: 'scheduled_at', label: 'Scheduled' },
    { key: 'sent_at', label: 'Sent' },
  ];

  return (
    <div>
      <PageHeader title="Messaging" subtitle="Transactional email + SMS outbox. wp_mail for email, Twilio for SMS (configure in Settings)."
        actions={<button className="btn" onClick={flush}>Flush queue now</button>} />

      <div className="mb-4 flex gap-2">
        <button className={tab === 'outbox' ? 'btn-primary' : 'btn'} onClick={() => setTab('outbox')}>Outbox</button>
        <button className={tab === 'send' ? 'btn-primary' : 'btn'} onClick={() => setTab('send')}>Send</button>
        <button className={tab === 'templates' ? 'btn-primary' : 'btn'} onClick={() => setTab('templates')}>Templates</button>
      </div>

      {tab === 'outbox' && <DataTable<Msg> rows={outbox} columns={cols} loading={loading} rowKey={(r) => r.id} empty="Outbox empty." />}

      {tab === 'send' && (
        <div className="card p-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
          <select className="input" value={form.channel} onChange={(e) => setForm({...form, channel: e.target.value})}>
            <option value="email">Email</option><option value="sms">SMS</option>
          </select>
          <input className="input" placeholder="Template key (optional)" value={form.template_key ?? ''} onChange={(e) => setForm({...form, template_key: e.target.value})} />
          <input className="input col-span-2" placeholder="To address / phone" value={form.to_address ?? ''} onChange={(e) => setForm({...form, to_address: e.target.value})} />
          {form.channel === 'email' && <input className="input col-span-2" placeholder="Subject" value={form.subject ?? ''} onChange={(e) => setForm({...form, subject: e.target.value})} />}
          <textarea className="input col-span-2" rows={6} placeholder="Body (HTML for email, text for SMS). Use {{var}} placeholders if you set template_key."
            value={form.body ?? ''} onChange={(e) => setForm({...form, body: e.target.value})} />
          <button className="btn-primary col-span-2" onClick={send}>Queue message</button>
        </div>
      )}

      {tab === 'templates' && (
        <>
          <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
            <input className="input" placeholder="template_key (e.g. layaway_reminder)" value={tpl.template_key ?? ''} onChange={(e) => setTpl({...tpl, template_key: e.target.value})} />
            <select className="input" value={tpl.channel} onChange={(e) => setTpl({...tpl, channel: e.target.value})}>
              <option value="email">Email</option><option value="sms">SMS</option>
            </select>
            <input className="input col-span-2" placeholder="Subject (email only)" value={tpl.subject ?? ''} onChange={(e) => setTpl({...tpl, subject: e.target.value})} />
            <textarea className="input col-span-2" rows={6} placeholder="Body — use {{name}}, {{balance}}, {{expires}}, etc." value={tpl.body ?? ''} onChange={(e) => setTpl({...tpl, body: e.target.value})} />
            <label className="flex items-center gap-2"><input type="checkbox" checked={!!tpl.enabled} onChange={(e) => setTpl({...tpl, enabled: e.target.checked ? 1 : 0})} /> Enabled</label>
            <button className="btn-primary" onClick={saveTpl}>Save template</button>
          </div>
          <ul className="space-y-2">
            {templates.map((t) => (
              <li key={t.id} className="card p-3"><div className="flex justify-between"><b>{t.template_key}</b><span className="badge-zinc">{t.channel}</span></div><div className="text-xs text-zinc-500">{t.subject}</div></li>
            ))}
            {templates.length === 0 && <li className="text-zinc-500">No templates yet.</li>}
          </ul>
        </>
      )}
    </div>
  );
}
