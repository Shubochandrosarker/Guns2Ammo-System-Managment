import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface MaintTicket {
  id: number; ticket_number: string; lane_code: string; ticket_type: string;
  severity: string; status: string; description: string | null;
  lane_out_of_service: number; opened_at: string; closed_at: string | null;
}

interface Incident {
  id: number; incident_number: string; lane_code: string | null;
  incident_type: string; severity: string; description: string;
  occurred_at: string; status: string; customer_flag_applied: number;
}

interface MaintForm {
  lane_code?: string; ticket_type: string; severity: string;
  lane_out_of_service?: boolean; equipment_failed?: string; description?: string;
}
interface IncForm {
  lane_code?: string; incident_type: string; severity: string; occurred_at?: string;
  rso_name?: string; witness_names?: string; description?: string;
  action_taken?: string; escalated_to_law_enforcement?: boolean;
}
interface IncCustomer {
  customer_name: string; customer_id?: number | null; role: string; notes?: string;
}

const MAINT_TYPES = ['cleaning', 'repair', 'inspection', 'scheduled_service', 'equipment_failure'];
const SEVERITY_BADGE: Record<string, string> = {
  info: 'badge-zinc', warning: 'badge-amber', serious: 'badge-amber',
  eject: 'badge-red', banned: 'badge-red',
  normal: 'badge-zinc', low: 'badge-zinc', high: 'badge-red',
};

export default function RangeSafety() {
  const [tab, setTab] = useState<'maintenance' | 'incidents'>('maintenance');
  const [tickets, setTickets] = useState<MaintTicket[]>([]);
  const [incidents, setIncidents] = useState<Incident[]>([]);
  const [maintForm, setMaintForm] = useState<MaintForm>({ ticket_type: 'cleaning', severity: 'normal' });
  const [incForm, setIncForm] = useState<IncForm>({ severity: 'warning', incident_type: 'safety_violation' });
  const [incCustomers, setIncCustomers] = useState<IncCustomer[]>([]);

  const refresh = async () => {
    const [m, i] = await Promise.all([
      get<{ items: MaintTicket[] }>('/range/maintenance'),
      get<{ items: Incident[] }>('/range/incidents'),
    ]);
    setTickets(m.items || []);
    setIncidents(i.items || []);
  };
  useEffect(() => { queueMicrotask(refresh); }, []);

  const openTicket = async () => {
    if (!maintForm.lane_code) return;
    await post('/range/maintenance', maintForm);
    setMaintForm({ ticket_type: 'cleaning', severity: 'normal' });
    await refresh();
  };
  const startTicket = async (id: number) => { await post(`/range/maintenance/${id}/start`, {}); await refresh(); };
  const closeTicket = async (id: number) => {
    const labor = parseInt(prompt('Labor minutes?') || '0') || 0;
    const cost = parseFloat(prompt('Cost $?') || '0') || 0;
    await post(`/range/maintenance/${id}/close`, { labor_minutes: labor, cost_amount: cost });
    await refresh();
  };

  const addIncCustomer = () => setIncCustomers((c) => [...c, { customer_name: '', role: 'involved' }]);
  const updateIncCustomer = (i: number, k: keyof IncCustomer, v: string | number | null) => setIncCustomers((c) => {
    const next = [...c]; next[i] = { ...next[i], [k]: v }; return next;
  });

  const reportIncident = async () => {
    if (!incForm.description) return;
    const body = { ...incForm, customers: incCustomers };
    const res = await post<{ incident?: { customer_flag_applied?: number } }>('/range/incidents', body);
    if (res?.incident?.customer_flag_applied) {
      alert('Incident logged. Customer flag(s) applied to CRM profile per severity ladder.');
    } else {
      alert('Incident logged.');
    }
    setIncForm({ severity: 'warning', incident_type: 'safety_violation' });
    setIncCustomers([]);
    await refresh();
  };
  const closeIncident = async (id: number) => { await post(`/range/incidents/${id}/close`, {}); await refresh(); };

  const maintCols: Column<MaintTicket>[] = [
    { key: 'ticket_number', label: 'Ticket' },
    { key: 'lane_code', label: 'Lane' },
    { key: 'ticket_type', label: 'Type' },
    { key: 'severity', label: 'Severity', render: (r) => <span className={SEVERITY_BADGE[r.severity] || 'badge-zinc'}>{r.severity}</span> },
    { key: 'status', label: 'Status', render: (r) => <span className="badge-zinc">{r.status}</span> },
    { key: 'lane_out_of_service', label: 'OOS', render: (r) => r.lane_out_of_service ? <span className="badge-red">OOS</span> : '—' },
    { key: 'opened_at', label: 'Opened' },
    { key: 'actions', label: '', render: (r) => (
      <div className="flex gap-1 justify-end">
        {r.status === 'open' && <button className="btn-sm" onClick={() => startTicket(r.id)}>Start</button>}
        {r.status !== 'done' && <button className="btn-sm" onClick={() => closeTicket(r.id)}>Close</button>}
      </div>
    ) },
  ];

  const incCols: Column<Incident>[] = [
    { key: 'incident_number', label: 'Number' },
    { key: 'lane_code', label: 'Lane' },
    { key: 'incident_type', label: 'Type' },
    { key: 'severity', label: 'Severity', render: (r) => <span className={SEVERITY_BADGE[r.severity] || 'badge-zinc'}>{r.severity}</span> },
    { key: 'description', label: 'Description', render: (r) => <span className="text-xs">{r.description.slice(0, 80)}{r.description.length > 80 ? '…' : ''}</span> },
    { key: 'occurred_at', label: 'Occurred' },
    { key: 'customer_flag_applied', label: 'Flagged', render: (r) => r.customer_flag_applied ? <span className="badge-red">CRM flag</span> : '—' },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: '', render: (r) => r.status === 'open' && <button className="btn-sm" onClick={() => closeIncident(r.id)}>Close</button> },
  ];

  return (
    <div>
      <PageHeader
        title="Range Safety"
        subtitle="Lane maintenance tickets (cleaning / repair / inspection / scheduled / equipment failure) + range-incident reports with severity ladder info → warning → serious → eject → banned. Eject auto-applies do_not_sell to the customer CRM profile; banned additionally sets denied_buyer + reason."
      />

      <div className="mb-4 flex gap-2">
        <button className={tab === 'maintenance' ? 'btn-primary' : 'btn'} onClick={() => setTab('maintenance')}>Lane maintenance</button>
        <button className={tab === 'incidents' ? 'btn-primary' : 'btn'} onClick={() => setTab('incidents')}>Incidents</button>
      </div>

      {tab === 'maintenance' && (
        <>
          <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
            <input className="input" placeholder="Lane code (e.g. R-1) *" value={maintForm.lane_code ?? ''} onChange={(e) => setMaintForm({ ...maintForm, lane_code: e.target.value })} />
            <select className="input" value={maintForm.ticket_type} onChange={(e) => setMaintForm({ ...maintForm, ticket_type: e.target.value })}>
              {MAINT_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
            </select>
            <select className="input" value={maintForm.severity} onChange={(e) => setMaintForm({ ...maintForm, severity: e.target.value })}>
              <option value="normal">normal</option>
              <option value="low">low</option>
              <option value="high">high</option>
            </select>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!maintForm.lane_out_of_service} onChange={(e) => setMaintForm({ ...maintForm, lane_out_of_service: e.target.checked })} /> Lane out of service</label>
            <input className="input col-span-2 md:col-span-3" placeholder="Equipment / part failed" value={maintForm.equipment_failed ?? ''} onChange={(e) => setMaintForm({ ...maintForm, equipment_failed: e.target.value })} />
            <textarea className="input col-span-2 md:col-span-4" rows={2} placeholder="Description" value={maintForm.description ?? ''} onChange={(e) => setMaintForm({ ...maintForm, description: e.target.value })} />
            <button className="btn-primary md:col-span-4" onClick={openTicket}>Open ticket</button>
          </div>
          <DataTable<MaintTicket> rows={tickets} columns={maintCols} loading={false} rowKey={(r) => r.id} empty="No maintenance tickets." />
        </>
      )}

      {tab === 'incidents' && (
        <>
          <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
            <input className="input" placeholder="Lane code (optional)" value={incForm.lane_code ?? ''} onChange={(e) => setIncForm({ ...incForm, lane_code: e.target.value })} />
            <select className="input" value={incForm.incident_type} onChange={(e) => setIncForm({ ...incForm, incident_type: e.target.value })}>
              <option value="safety_violation">safety_violation</option>
              <option value="muzzle_discipline">muzzle_discipline</option>
              <option value="target_damage">target_damage</option>
              <option value="customer_complaint">customer_complaint</option>
              <option value="weapon_malfunction">weapon_malfunction</option>
              <option value="injury">injury</option>
              <option value="other">other</option>
            </select>
            <select className="input" value={incForm.severity} onChange={(e) => setIncForm({ ...incForm, severity: e.target.value })}>
              <option value="info">info</option>
              <option value="warning">warning</option>
              <option value="serious">serious</option>
              <option value="eject">eject → CRM do_not_sell</option>
              <option value="banned">banned → CRM denied_buyer</option>
            </select>
            <input className="input" type="datetime-local" value={incForm.occurred_at ?? ''} onChange={(e) => setIncForm({ ...incForm, occurred_at: e.target.value.replace('T', ' ') + ':00' })} />
            <input className="input col-span-2" placeholder="RSO name" value={incForm.rso_name ?? ''} onChange={(e) => setIncForm({ ...incForm, rso_name: e.target.value })} />
            <input className="input col-span-2" placeholder="Witness names" value={incForm.witness_names ?? ''} onChange={(e) => setIncForm({ ...incForm, witness_names: e.target.value })} />
            <textarea className="input col-span-2 md:col-span-4" rows={3} placeholder="Description *" value={incForm.description ?? ''} onChange={(e) => setIncForm({ ...incForm, description: e.target.value })} />
            <input className="input col-span-2 md:col-span-3" placeholder="Action taken (e.g. warning issued, escorted out)" value={incForm.action_taken ?? ''} onChange={(e) => setIncForm({ ...incForm, action_taken: e.target.value })} />
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={!!incForm.escalated_to_law_enforcement} onChange={(e) => setIncForm({ ...incForm, escalated_to_law_enforcement: e.target.checked })} />
              Law enforcement called
            </label>

            <div className="col-span-2 md:col-span-4 border-t pt-3">
              <div className="flex items-center justify-between mb-2">
                <span className="text-sm font-semibold">Customers involved ({incCustomers.length})</span>
                <button className="btn-sm" onClick={addIncCustomer}>+ Add customer</button>
              </div>
              {incCustomers.map((c, i) => (
                <div key={i} className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-2 mb-2">
                  <input className="input" placeholder="Customer name *" value={c.customer_name} onChange={(e) => updateIncCustomer(i, 'customer_name', e.target.value)} />
                  <input className="input" type="number" placeholder="Customer ID (for CRM flag)" value={c.customer_id ?? ''} onChange={(e) => updateIncCustomer(i, 'customer_id', parseInt(e.target.value) || null)} />
                  <select className="input" value={c.role} onChange={(e) => updateIncCustomer(i, 'role', e.target.value)}>
                    <option value="involved">involved</option>
                    <option value="reporter">reporter</option>
                    <option value="witness">witness</option>
                    <option value="injured">injured</option>
                  </select>
                  <input className="input" placeholder="Notes" value={c.notes ?? ''} onChange={(e) => updateIncCustomer(i, 'notes', e.target.value)} />
                </div>
              ))}
              {incCustomers.length === 0 && <p className="text-xs text-zinc-500">No customers attached. eject/banned severities only apply CRM flags to customers with a customer_id.</p>}
            </div>

            <button className="btn-primary md:col-span-4" onClick={reportIncident}>Report incident</button>
          </div>
          <DataTable<Incident> rows={incidents} columns={incCols} loading={false} rowKey={(r) => r.id} empty="No incidents reported." />
        </>
      )}
    </div>
  );
}
