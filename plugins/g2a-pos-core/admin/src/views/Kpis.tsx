import { useEffect, useState } from 'react';
import { get } from '../api';
import PageHeader from '../components/PageHeader';

interface Snapshot {
  as_of: string;
  today: { orders: number; revenue: number };
  last_30_days: { revenue: number; cogs: number; gross_margin_pct: number; attach_rate_pct: number };
  compliance: { nics_open: number; open_4473: number };
  inventory: { serials_in_stock: number; dead_stock_90d: number };
  operations: { open_registers: number; layaway_balance: number };
}

interface VendorRow {
  id: number; display_name: string; provider_code: string;
  order_count: number; fulfilled: number; shipped_count: number;
  fill_rate_pct: number; avg_ship_hours: number | null;
}

function Tile({ label, value, sub }: { label: string; value: string | number; sub?: string }) {
  return (
    <div className="card p-4">
      <div className="text-xs text-zinc-500">{label}</div>
      <div className="text-2xl font-semibold tracking-tight mt-1">{value}</div>
      {sub && <div className="text-xs text-zinc-500 mt-1">{sub}</div>}
    </div>
  );
}

export default function Kpis() {
  const [snap, setSnap] = useState<Snapshot | null>(null);
  const [vendors, setVendors] = useState<VendorRow[]>([]);
  const [days, setDays] = useState(90);

  useEffect(() => {
    (async () => {
      const [s, v] = await Promise.all([
        get<Snapshot>('/reports/kpi'),
        get<{ vendors: VendorRow[] }>('/reports/kpi/vendor-scorecard', { days }),
      ]);
      setSnap(s);
      setVendors(v.vendors || []);
    })();
  }, [days]);

  if (!snap) return <div>Loading KPIs…</div>;

  const fmt$ = (n: number) => '$' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  return (
    <div>
      <PageHeader title="KPI Dashboard" subtitle={`As of ${snap.as_of}. Margin uses vendor wholesale_price as the cost proxy.`} />

      <div className="grid gap-4 md:grid-cols-4 mb-4">
        <Tile label="Today revenue" value={fmt$(snap.today.revenue)} sub={`${snap.today.orders} orders`} />
        <Tile label="30-day revenue" value={fmt$(snap.last_30_days.revenue)} />
        <Tile label="30-day gross margin %" value={`${snap.last_30_days.gross_margin_pct}%`} />
        <Tile label="30-day attach rate %" value={`${snap.last_30_days.attach_rate_pct}%`} sub="Firearm sales with accessory/ammo/optic" />
        <Tile label="NICS open" value={snap.compliance.nics_open} />
        <Tile label="Open 4473s" value={snap.compliance.open_4473} />
        <Tile label="Serials in stock" value={snap.inventory.serials_in_stock} />
        <Tile label="Dead stock (90d)" value={snap.inventory.dead_stock_90d} />
        <Tile label="Open registers" value={snap.operations.open_registers} />
        <Tile label="Layaway balance" value={fmt$(snap.operations.layaway_balance)} />
      </div>

      <div className="card p-4">
        <div className="flex items-center justify-between mb-3">
          <h3 className="font-semibold">Vendor scorecard</h3>
          <select className="input" value={days} onChange={(e) => setDays(parseInt(e.target.value))}>
            <option value={30}>Last 30 days</option><option value={90}>Last 90 days</option><option value={180}>Last 180 days</option>
          </select>
        </div>
        <table className="w-full text-sm">
          <thead><tr><th className="text-left">Vendor</th><th className="text-right">Orders</th><th className="text-right">Fulfilled</th><th className="text-right">Fill rate</th><th className="text-right">Avg ship hrs</th></tr></thead>
          <tbody>{vendors.map((v) => (
            <tr key={v.id}><td>{v.display_name}</td><td className="text-right">{v.order_count}</td><td className="text-right">{v.fulfilled}</td><td className="text-right">{v.fill_rate_pct}%</td><td className="text-right">{v.avg_ship_hours ?? '—'}</td></tr>
          ))}{vendors.length === 0 && <tr><td colSpan={5} className="text-center text-zinc-500">No vendor orders yet.</td></tr>}</tbody>
        </table>
      </div>
    </div>
  );
}
