import { useEffect, useState } from 'react';
import { get } from '../api';

interface Kpis { attach_rate_ammo?: number; attach_rate_accessory?: number; firearm_orders?: number }
interface Health { ok?: boolean; db_version?: string; tables?: Record<string, boolean>; time?: string }

const ACTIONS = [
  { href: '#orders', icon: '🧾', title: 'Review sales', detail: 'Orders, payment state, and fulfillment' },
  { href: '#inventory', icon: '📦', title: 'Check inventory', detail: 'Stock, serials, and adjustments' },
  { href: '#forms_4473', icon: '📝', title: 'Continue 4473', detail: 'Open and pending compliance forms' },
  { href: '#customers', icon: '👥', title: 'Find customer', detail: 'History, loyalty, and memberships' },
];

export default function Dashboard() {
  const [kpis, setKpis] = useState<Kpis>({});
  const [health, setHealth] = useState<Health | null>(null);
  const [loading, setLoading] = useState(true);
  const [warning, setWarning] = useState('');
  const user = window.G2A_POS_ADMIN?.currentUser;

  useEffect(() => {
    let cancelled = false;
    Promise.allSettled([get<Kpis>('/reports/attach-rate'), get<Health>('/system/health')]).then((results) => {
      if (cancelled) return;
      if (results[0].status === 'fulfilled') setKpis(results[0].value);
      if (results[1].status === 'fulfilled') setHealth(results[1].value);
      if (results.every((result) => result.status === 'rejected')) setWarning('Some management metrics are unavailable for this account. Operational links remain available.');
      setLoading(false);
    });
    return () => { cancelled = true; };
  }, []);

  const missingTables = health?.tables ? Object.values(health.tables).filter((exists) => !exists).length : 0;

  return (
    <div className="mx-auto max-w-[1500px]">
      <section className="relative overflow-hidden rounded-2xl border border-zinc-200 bg-gradient-to-br from-white via-white to-orange-50 p-6 shadow-sm dark:border-zinc-800 dark:from-zinc-900 dark:via-zinc-900 dark:to-orange-950/30 sm:p-8">
        <div className="absolute -right-12 -top-16 h-48 w-48 rounded-full bg-brand/10 blur-3xl" aria-hidden="true" />
        <div className="relative flex flex-col justify-between gap-6 lg:flex-row lg:items-end">
          <div>
            <div className="text-xs font-semibold uppercase tracking-[.18em] text-brand">Operations overview</div>
            <h1 className="mt-2 text-3xl font-bold tracking-tight sm:text-4xl">{greeting()}, {firstName(user?.name) || 'team'}.</h1>
            <p className="mt-2 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">Run today’s sales, compliance, inventory, and range operations from one focused workspace.</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <a href="#registers" className="btn-secondary">Open registers</a>
            <a href="#orders" className="btn-primary">View today’s sales</a>
          </div>
        </div>
      </section>

      {warning && <div className="mt-4 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-900/20 dark:text-amber-200">{warning}</div>}

      <section className="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Key performance indicators">
        <Kpi icon="◈" label="Firearm orders" value={loading ? '—' : String(kpis.firearm_orders ?? 0)} hint="Month to date" tone="orange" />
        <Kpi icon="↗" label="Ammo attach rate" value={loading ? '—' : pct(kpis.attach_rate_ammo)} hint="Same-ticket firearm sales" tone="emerald" />
        <Kpi icon="＋" label="Accessory attach" value={loading ? '—' : pct(kpis.attach_rate_accessory)} hint="Same-ticket firearm sales" tone="blue" />
        <Kpi icon={health?.ok ? '✓' : '•'} label="System health" value={loading ? 'Checking' : health ? (health.ok ? 'Healthy' : 'Attention') : 'Restricted'} hint={health ? `${missingTables} missing core tables · DB ${health.db_version || '—'}` : 'Manager permission required'} tone={health?.ok ? 'emerald' : 'zinc'} />
      </section>

      <section className="mt-8">
        <div className="mb-3 flex items-end justify-between"><div><h2 className="text-lg font-semibold tracking-tight">Quick actions</h2><p className="text-sm text-zinc-500">Common workflows, one click away.</p></div><span className="hidden text-xs text-zinc-400 sm:block">Press ⌘K for the G2A Agent</span></div>
        <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
          {ACTIONS.map((action) => <a key={action.href} href={action.href} className="group card flex items-center gap-4 p-4 transition hover:-translate-y-0.5 hover:border-brand/30 hover:shadow-md"><span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-zinc-100 text-xl group-hover:bg-brand/10 dark:bg-zinc-800">{action.icon}</span><span className="min-w-0"><span className="block font-semibold">{action.title}</span><span className="mt-0.5 block text-xs text-zinc-500">{action.detail}</span></span><span className="ml-auto text-zinc-300 group-hover:text-brand">›</span></a>)}
        </div>
      </section>

      <section className="mt-8 grid grid-cols-1 gap-4 xl:grid-cols-3">
        <WorkQueue title="Compliance command center" subtitle="Keep regulated transactions moving safely." icon="🛡️" links={[['NICS queue', '#nics'], ['4473 forms', '#forms_4473'], ['Bound book', '#bound_book'], ['Compliance calendar', '#compliance_calendar']]} />
        <WorkQueue title="Inventory operations" subtitle="Receive, count, route, and replenish." icon="📦" links={[['Purchase orders', '#purchase_orders'], ['Cycle counts', '#cycle_counts'], ['Vendor catalog', '#vendor_catalog'], ['Transfers', '#location_transfers']]} />
        <WorkQueue title="Customer & range" subtitle="Deliver a connected customer experience." icon="🎯" links={[['Range operations', '#range_ops'], ['Reservations', '#lane_reservations'], ['Memberships', '#membership'], ['Repairs', '#repairs']]} />
      </section>
    </div>
  );
}

function Kpi({ icon, label, value, hint, tone }: { icon: string; label: string; value: string; hint: string; tone: 'orange' | 'emerald' | 'blue' | 'zinc' }) {
  const tones = { orange: 'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300', emerald: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300', blue: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300', zinc: 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300' };
  return <div className="card p-5"><div className="flex items-start justify-between"><div className="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{label}</div><span className={`grid h-8 w-8 place-items-center rounded-lg ${tones[tone]}`}>{icon}</span></div><div className="mt-3 text-3xl font-bold tracking-tight">{value}</div><div className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{hint}</div></div>;
}

function WorkQueue({ title, subtitle, icon, links }: { title: string; subtitle: string; icon: string; links: [string, string][] }) {
  return <div className="card overflow-hidden"><div className="flex items-start gap-3 border-b border-zinc-100 p-5 dark:border-zinc-800"><span className="grid h-10 w-10 place-items-center rounded-xl bg-zinc-100 text-lg dark:bg-zinc-800">{icon}</span><div><h3 className="font-semibold">{title}</h3><p className="mt-0.5 text-xs text-zinc-500">{subtitle}</p></div></div><div className="divide-y divide-zinc-100 dark:divide-zinc-800">{links.map(([label, href]) => <a key={href} href={href} className="flex items-center justify-between px-5 py-3 text-sm font-medium hover:bg-zinc-50 hover:text-brand dark:hover:bg-zinc-800/60"><span>{label}</span><span aria-hidden="true">›</span></a>)}</div></div>;
}

function pct(value?: number): string { return value === undefined || value === null ? '—' : `${(value * 100).toFixed(1)}%`; }
function greeting(): string { const hour = new Date().getHours(); return hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'; }
function firstName(name?: string): string { return String(name || '').trim().split(/\s+/)[0] || ''; }
