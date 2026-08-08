import { useState } from 'react';
import { get } from '../api';
import PageHeader from '../components/PageHeader';

interface TaxReport {
  orders: number;
  taxable_subtotal: string | number;
  tax_collected: string | number;
  grand: string | number;
}

interface AttachReport {
  firearm_orders: number;
  firearm_orders_with_ammo: number;
  firearm_orders_with_accessory: number;
  attach_rate_ammo?: number;
  attach_rate_accessory?: number;
}

interface EmployeeRow {
  employee_id: number;
  orders: number;
  total: string | number;
}

export default function Reports() {
  const [from, setFrom] = useState(firstOfMonth());
  const [to, setTo] = useState(today());
  const [tax, setTax] = useState<TaxReport | null>(null);
  const [byEmp, setByEmp] = useState<EmployeeRow[]>([]);
  const [attach, setAttach] = useState<AttachReport | null>(null);
  const [loading, setLoading] = useState(false);

  const run = async () => {
    setLoading(true);
    try {
      const [t, e, a] = await Promise.all([
        get<TaxReport>('/reports/tax', { from, to }),
        get<{ rows: EmployeeRow[] }>('/reports/sales-by-employee', { from, to }),
        get<AttachReport>('/reports/attach-rate', { from, to }),
      ]);
      setTax(t);
      setByEmp(e.rows || []);
      setAttach(a);
    } finally { setLoading(false); }
  };

  return (
    <div>
      <PageHeader title="Reports" subtitle="Z/X register reports, sales by employee, attach rate, tax summary." />

      <div className="card mb-4 flex flex-wrap items-end gap-3 p-4">
        <Field label="From">
          <input type="datetime-local" className="input" value={from} onChange={(e) => setFrom(e.target.value)} />
        </Field>
        <Field label="To">
          <input type="datetime-local" className="input" value={to} onChange={(e) => setTo(e.target.value)} />
        </Field>
        <button className="btn-primary" onClick={run} disabled={loading}>{loading ? 'Running…' : 'Run reports'}</button>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <ReportCard title="Tax summary">
          {tax ? (
            <dl className="space-y-1 text-sm">
              <DT label="Orders">{tax.orders}</DT>
              <DT label="Taxable subtotal">${parseFloat(String(tax.taxable_subtotal || 0)).toFixed(2)}</DT>
              <DT label="Tax collected">${parseFloat(String(tax.tax_collected || 0)).toFixed(2)}</DT>
              <DT label="Grand total">${parseFloat(String(tax.grand || 0)).toFixed(2)}</DT>
            </dl>
          ) : <Empty />}
        </ReportCard>

        <ReportCard title="Attach rate">
          {attach ? (
            <dl className="space-y-1 text-sm">
              <DT label="Firearm orders">{attach.firearm_orders}</DT>
              <DT label="With ammo">{attach.firearm_orders_with_ammo}</DT>
              <DT label="With accessory">{attach.firearm_orders_with_accessory}</DT>
              <DT label="Ammo attach">{pct(attach.attach_rate_ammo)}</DT>
              <DT label="Accessory attach">{pct(attach.attach_rate_accessory)}</DT>
            </dl>
          ) : <Empty />}
        </ReportCard>

        <ReportCard title="Sales by employee">
          {byEmp.length ? (
            <ul className="space-y-1 text-sm">
              {byEmp.map((r) => (
                <li key={r.employee_id} className="flex justify-between">
                  <span>#{r.employee_id}</span>
                  <span className="text-zinc-500">{r.orders} orders</span>
                  <span className="font-medium">${parseFloat(String(r.total)).toFixed(2)}</span>
                </li>
              ))}
            </ul>
          ) : <Empty />}
        </ReportCard>
      </div>
    </div>
  );
}

function ReportCard({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="card p-5">
      <h3 className="mb-3 text-base font-semibold">{title}</h3>
      {children}
    </div>
  );
}
function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1 text-xs font-medium text-zinc-500 dark:text-zinc-400">
      {label}
      {children}
    </label>
  );
}
function DT({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex justify-between">
      <dt className="text-zinc-500 dark:text-zinc-400">{label}</dt>
      <dd className="font-medium">{children}</dd>
    </div>
  );
}
function Empty() {
  return <div className="text-sm text-zinc-400">Run a report to populate.</div>;
}
function pct(v?: number) {
  if (v === undefined || v === null) return '—';
  return (v * 100).toFixed(1) + '%';
}
function firstOfMonth() {
  const d = new Date();
  return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 16);
}
function today() {
  return new Date().toISOString().slice(0, 16);
}
