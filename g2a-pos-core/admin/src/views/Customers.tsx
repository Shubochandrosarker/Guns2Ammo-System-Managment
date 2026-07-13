import { useEffect, useState } from 'react';
import { download, errorMessage, get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface CustomerRow {
  id: number;
  name: string;
  email: string;
  phone: string;
  pos_order_count: number;
  last_pos_order_at: string | null;
}

interface Profile {
  marketing_email_optin?: number;
  marketing_sms_optin?: number;
  do_not_sell?: number;
  denied_buyer?: number;
  denied_reason?: string;
  lifetime_spend?: string;
  lifetime_orders?: number;
  preferred_caliber?: string;
  preferred_brand?: string;
  internal_notes?: string;
}

interface Integrations {
  membership?: { active: boolean; plan: string; expires_at?: string | null; source: string } | null;
  waiver?: { source: string; status: string; waiver_date?: string | null; valid_until?: string | null } | null;
  bookings?: { id: number; title: string; starts_at: string; status: string; party: number }[];
  ffl_transfer_count?: number;
}

interface CustomerDetail {
  id: number;
  name: string;
  email: string;
  phone: string;
  profile: Profile;
  loyalty: { points: number; credit_cents: number; tier?: string };
  history: { id: number; grand_total: string; payment_status: string; created_at: string; line_count: number }[];
  integrations?: Integrations;
}

export default function Customers() {
  const [q, setQ] = useState('');
  const [rows, setRows] = useState<CustomerRow[]>([]);
  const [loading, setLoading] = useState(false);
  const [selected, setSelected] = useState<CustomerDetail | null>(null);
  const [exporting, setExporting] = useState(false);
  const [exportErr, setExportErr] = useState('');

  const exportCsv = async () => {
    setExporting(true);
    setExportErr('');
    try {
      await download('/crm/export.csv', `g2a-customers-${new Date().toISOString().slice(0, 10)}.csv`);
    } catch (e) {
      setExportErr(errorMessage(e, 'Export failed'));
    } finally {
      setExporting(false);
    }
  };

  const search = async () => {
    setLoading(true);
    try {
      const r = await get<{ items: CustomerRow[] }>('/crm/search', { q });
      setRows(r.items || []);
    } finally { setLoading(false); }
  };

  useEffect(() => { queueMicrotask(search); },
    // eslint-disable-next-line react-hooks/exhaustive-deps
    []);

  const openCustomer = async (id: number) => {
    const r = await get<CustomerDetail>(`/crm/${id}`);
    setSelected(r);
  };

  const saveProfile = async () => {
    if (!selected) return;
    const p = selected.profile;
    await post(`/crm/${selected.id}/profile`, p);
    await openCustomer(selected.id);
  };

  const cols: Column<CustomerRow>[] = [
    { key: 'name', label: 'Customer', render: (r) => <button className="link" onClick={() => openCustomer(r.id)}>{r.name}</button> },
    { key: 'email', label: 'Email' },
    { key: 'phone', label: 'Phone' },
    { key: 'pos_order_count', label: 'POS Orders', align: 'right' },
    { key: 'last_pos_order_at', label: 'Last order' },
  ];

  return (
    <div>
      <PageHeader
        title="Customers (CRM)"
        subtitle="Purchase history, lifetime value, marketing consent, denied-buyer flag."
        actions={
          <button className="btn-secondary" onClick={exportCsv} disabled={exporting}>
            {exporting ? 'Exporting…' : '⬇ Export CSV'}
          </button>
        }
      />
      {exportErr && <div className="mb-4 rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-900 dark:bg-rose-900/20 dark:text-rose-300">{exportErr}</div>}
      <div className="card mb-4 flex items-center gap-2 p-3">
        <input className="input flex-1" placeholder="Search name / email…" value={q}
          onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && search()} />
        <button className="btn-primary" onClick={search}>Search</button>
      </div>

      <div className="grid gap-4 md:grid-cols-3">
        <div className="md:col-span-2">
          <DataTable<CustomerRow> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} />
        </div>

        {selected && (
          <div className="card p-4">
            <h3 className="text-lg font-semibold">{selected.name}</h3>
            <p className="text-xs text-zinc-500">{selected.email} · {selected.phone}</p>

            <div className="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
              <div><span className="text-zinc-500">Lifetime spend</span><div className="font-semibold">${selected.profile.lifetime_spend ?? '0.00'}</div></div>
              <div><span className="text-zinc-500">Orders</span><div className="font-semibold">{selected.profile.lifetime_orders ?? 0}</div></div>
              <div><span className="text-zinc-500">Loyalty pts</span><div className="font-semibold">{selected.loyalty.points}</div></div>
              <div><span className="text-zinc-500">Store credit</span><div className="font-semibold">${(selected.loyalty.credit_cents / 100).toFixed(2)}</div></div>
            </div>

            <div className="mt-4 space-y-2 text-sm">
              <label className="flex items-center gap-2"><input type="checkbox" checked={!!selected.profile.marketing_email_optin}
                onChange={(e) => setSelected({...selected, profile: {...selected.profile, marketing_email_optin: e.target.checked ? 1 : 0}})} /> Email opt-in</label>
              <label className="flex items-center gap-2"><input type="checkbox" checked={!!selected.profile.marketing_sms_optin}
                onChange={(e) => setSelected({...selected, profile: {...selected.profile, marketing_sms_optin: e.target.checked ? 1 : 0}})} /> SMS opt-in</label>
              <label className="flex items-center gap-2"><input type="checkbox" checked={!!selected.profile.do_not_sell}
                onChange={(e) => setSelected({...selected, profile: {...selected.profile, do_not_sell: e.target.checked ? 1 : 0}})} /> Do not sell</label>
              <label className="flex items-center gap-2"><input type="checkbox" checked={!!selected.profile.denied_buyer}
                onChange={(e) => setSelected({...selected, profile: {...selected.profile, denied_buyer: e.target.checked ? 1 : 0}})} /> Denied buyer</label>
              {!!selected.profile.denied_buyer && (
                <input className="input w-full" placeholder="Denied reason" value={selected.profile.denied_reason ?? ''}
                  onChange={(e) => setSelected({...selected, profile: {...selected.profile, denied_reason: e.target.value}})} />
              )}
              <input className="input w-full" placeholder="Preferred caliber" value={selected.profile.preferred_caliber ?? ''}
                onChange={(e) => setSelected({...selected, profile: {...selected.profile, preferred_caliber: e.target.value}})} />
              <input className="input w-full" placeholder="Preferred brand" value={selected.profile.preferred_brand ?? ''}
                onChange={(e) => setSelected({...selected, profile: {...selected.profile, preferred_brand: e.target.value}})} />
              <textarea className="input w-full" placeholder="Internal notes" rows={3} value={selected.profile.internal_notes ?? ''}
                onChange={(e) => setSelected({...selected, profile: {...selected.profile, internal_notes: e.target.value}})} />
              <button className="btn-primary w-full" onClick={saveProfile}>Save profile</button>
            </div>

            <div className="mt-4 rounded-lg border border-zinc-200 p-3 dark:border-zinc-800">
              <h4 className="text-sm font-semibold mb-2">Integrations</h4>
              <div className="space-y-2 text-xs">
                <div className="flex items-center justify-between">
                  <span className="text-zinc-500">Membership</span>
                  {selected.integrations?.membership ? (
                    <span className={selected.integrations.membership.active ? 'badge-green' : 'badge-amber'}>
                      {selected.integrations.membership.plan || 'member'} · {selected.integrations.membership.active ? 'active' : 'inactive'}
                      {selected.integrations.membership.expires_at ? ` · until ${String(selected.integrations.membership.expires_at).slice(0, 10)}` : ''}
                    </span>
                  ) : <span className="text-zinc-400">none</span>}
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-zinc-500">Waiver</span>
                  {selected.integrations?.waiver ? (
                    <span className="badge-green">
                      {selected.integrations.waiver.status} · {selected.integrations.waiver.source}
                      {selected.integrations.waiver.waiver_date ? ` · ${String(selected.integrations.waiver.waiver_date).slice(0, 10)}` : ''}
                    </span>
                  ) : <span className="badge-red">none on file</span>}
                </div>
                <div className="flex items-center justify-between">
                  <span className="text-zinc-500">FFL transfers</span>
                  <span className="badge-zinc">{selected.integrations?.ffl_transfer_count ?? 0}</span>
                </div>
                <div>
                  <div className="text-zinc-500 mb-1">Upcoming bookings</div>
                  {(selected.integrations?.bookings?.length ?? 0) > 0 ? (
                    <ul className="space-y-1">
                      {(selected.integrations?.bookings ?? []).map((b) => (
                        <li key={b.id} className="flex justify-between">
                          <span>{b.title} · party {b.party}</span>
                          <span className="text-zinc-500">{String(b.starts_at).slice(0, 16)} · {b.status}</span>
                        </li>
                      ))}
                    </ul>
                  ) : <span className="text-zinc-400">none</span>}
                </div>
              </div>
            </div>

            <div className="mt-4">
              <h4 className="text-sm font-semibold mb-2">Recent purchases</h4>
              <ul className="text-xs space-y-1">
                {selected.history.map((h) => (
                  <li key={h.id} className="flex justify-between">
                    <span>#{h.id} · {h.created_at}</span>
                    <span>${h.grand_total} · {h.payment_status}</span>
                  </li>
                ))}
                {selected.history.length === 0 && <li className="text-zinc-500">No purchase history.</li>}
              </ul>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
