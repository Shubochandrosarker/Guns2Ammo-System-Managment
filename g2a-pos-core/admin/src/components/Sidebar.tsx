import { useMemo, useState } from 'react';

export type NavKey =
  | 'dashboard'
  | 'kpis'
  | 'orders'
  | 'order_sourcing'
  | 'inventory'
  | 'catalog_identities'
  | 'used_firearms'
  | 'inventory_import'
  | 'distributors'
  | 'wholesalers'
  | 'vendor_catalog'
  | 'map_pricing'
  | 'dropship_orders'
  | 'purchase_orders'
  | 'cycle_counts'
  | 'bound_book'
  | 'forms_4473'
  | 'forms_4473_calibration'
  | 'ccw_exemption'
  | 'nics'
  | 'reports'
  | 'registers'
  | 'state_rules'
  | 'webhooks'
  | 'membership'
  | 'waivers'
  | 'lane_reservations'
  | 'classes'
  | 'customers'
  | 'loyalty'
  | 'gift_cards'
  | 'layaways'
  | 'consignments'
  | 'repairs'
  | 'split_tender'
  | 'tradeins'
  | 'range_ops'
  | 'range_safety'
  | 'nfa'
  | 'location_transfers'
  | 'hardware'
  | 'compliance_calendar'
  | 'ace_audit'
  | 'ffl_routing'
  | 'shipping'
  | 'messaging'
  | 'ai_settings'
  | 'ai_brain'
  | 'ai_audit'
  | 'settings';

interface NavGroup { label: string; items: { key: NavKey; label: string; icon: string }[] }

export const NAV_GROUPS: NavGroup[] = [
  { label: 'Overview', items: [
    { key: 'dashboard', label: 'Dashboard',  icon: '📊' },
    { key: 'kpis',      label: 'KPI Board',  icon: '📈' },
  ] },
  { label: 'Sales', items: [
    { key: 'orders',       label: 'Sales',         icon: '🧾' },
    { key: 'registers',    label: 'Registers',     icon: '💵' },
    { key: 'split_tender', label: 'Split Tender',  icon: '💳' },
    { key: 'order_sourcing', label: 'Order Sourcing', icon: '🧭' },
    { key: 'layaways',     label: 'Layaway',       icon: '🪙' },
    { key: 'consignments', label: 'Consignments',  icon: '🤝' },
    { key: 'tradeins',     label: 'Trade-Ins',     icon: '🔄' },
    { key: 'gift_cards',   label: 'Gift Cards',    icon: '🎁' },
    { key: 'loyalty',      label: 'Loyalty',       icon: '⭐' },
  ] },
  { label: 'CRM & Range', items: [
    { key: 'customers',         label: 'Customers',    icon: '👥' },
    { key: 'membership',        label: 'Memberships',  icon: '🎟️' },
    { key: 'lane_reservations', label: 'Lane Reserv.', icon: '🎯' },
    { key: 'range_ops',         label: 'Range Ops',    icon: '🥽' },
    { key: 'range_safety',      label: 'Range Safety', icon: '🚨' },
    { key: 'classes',           label: 'Classes',      icon: '🎓' },
    { key: 'waivers',           label: 'Waivers',      icon: '✍️' },
    { key: 'repairs',           label: 'Gunsmithing',  icon: '🔧' },
  ] },
  { label: 'Inventory', items: [
    { key: 'inventory',          label: 'Inventory',      icon: '📦' },
    { key: 'catalog_identities', label: 'Identity Graph', icon: '🧬' },
    { key: 'used_firearms',      label: 'Used Firearms',  icon: '🔁' },
    { key: 'inventory_import',   label: 'Import CSV',     icon: '⬆️' },
    { key: 'distributors',       label: 'Distributors',   icon: '🚚' },
    { key: 'wholesalers',        label: 'Wholesalers',    icon: '🏭' },
    { key: 'vendor_catalog',     label: 'Vendor Catalog', icon: '🗂️' },
    { key: 'purchase_orders',    label: 'Purchase Orders',icon: '📥' },
    { key: 'cycle_counts',       label: 'Cycle Counts',   icon: '🧮' },
    { key: 'location_transfers', label: 'Loc Transfers',  icon: '🔁' },
    { key: 'map_pricing',        label: 'MAP Pricing',    icon: '💰' },
    { key: 'dropship_orders',    label: 'Drop-Ship',      icon: '📮' },
    { key: 'shipping',           label: 'Shipping',       icon: '📦' },
    { key: 'ffl_routing',        label: 'FFL Routing',    icon: '📍' },
  ] },
  { label: 'Compliance', items: [
    { key: 'bound_book',             label: 'Bound Book',   icon: '📖' },
    { key: 'forms_4473',             label: '4473s',        icon: '📝' },
    { key: 'forms_4473_calibration', label: '4473 Template',icon: '🧭' },
    { key: 'nics',                   label: 'NICS Queue',   icon: '🛡️' },
    { key: 'ccw_exemption',          label: 'CCW Bypass',   icon: '🪪' },
    { key: 'nfa',                    label: 'NFA (Class III)', icon: '🇺🇸' },
    { key: 'compliance_calendar',    label: 'Compl. Cal.',  icon: '🗓️' },
    { key: 'ace_audit',              label: 'ACE Audit',    icon: '📂' },
    { key: 'state_rules',            label: 'State Rules',  icon: '🗺️' },
  ] },
  { label: 'AI Agent', items: [
    { key: 'ai_settings', label: 'AI Settings', icon: '🤖' },
    { key: 'ai_brain',    label: 'Brain (RAG)', icon: '🧠' },
    { key: 'ai_audit',    label: 'Audit Log',   icon: '📜' },
  ] },
  { label: 'Ops', items: [
    { key: 'reports',   label: 'Reports',   icon: '📈' },
    { key: 'messaging', label: 'Messaging', icon: '✉️' },
    { key: 'hardware',  label: 'Hardware',  icon: '🖨️' },
    { key: 'webhooks',  label: 'Webhooks',  icon: '🔔' },
    { key: 'settings',  label: 'Settings',  icon: '⚙️' },
  ] },
];

// Flattened for backwards compatibility with App.tsx hash lookup.
export const NAV = NAV_GROUPS.flatMap((g) => g.items);

export default function Sidebar({
  current,
  onSelect,
  open,
}: {
  current: NavKey;
  onSelect: (k: NavKey) => void;
  open: boolean;
}) {
  const [query, setQuery] = useState('');
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>({});
  const groups = useMemo(() => {
    const needle = query.trim().toLowerCase();
    if (!needle) return NAV_GROUPS;
    return NAV_GROUPS.map((group) => ({
      ...group,
      items: group.items.filter((item) => `${item.label} ${group.label}`.toLowerCase().includes(needle)),
    })).filter((group) => group.items.length > 0);
  }, [query]);

  return (
    <aside
      aria-label="Primary navigation"
      className={`flex flex-shrink-0 flex-col border-r border-zinc-200 bg-white transition-all duration-200 dark:border-zinc-800 dark:bg-zinc-900 ${
        open ? 'w-72' : 'w-16'
      }`}
    >
      <div className="flex h-16 items-center gap-3 border-b border-zinc-200 px-3 dark:border-zinc-800">
        <div className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-brand to-orange-700 font-black text-white shadow-lg shadow-orange-500/20">G2A</div>
        {open && <div><div className="text-[10px] font-semibold uppercase tracking-[.18em] text-zinc-400">Guns 2 Ammo</div><div className="text-sm font-bold tracking-tight">Operations Suite</div></div>}
      </div>
      {open && (
        <div className="border-b border-zinc-100 p-3 dark:border-zinc-800">
          <label className="relative block">
            <span className="pointer-events-none absolute inset-y-0 left-3 grid place-items-center text-zinc-400" aria-hidden="true">⌕</span>
            <span className="sr-only">Search navigation</span>
            <input
              className="input h-9 pl-9"
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Find a workspace…"
            />
          </label>
        </div>
      )}
      <nav className="flex-1 space-y-2 overflow-auto p-2">
        {groups.map((group) => {
          const isCollapsed = !query && collapsed[group.label];
          return (
            <div key={group.label}>
              {open && (
                <button
                  type="button"
                  className="flex w-full items-center justify-between rounded-md px-3 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-[.14em] text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200"
                  onClick={() => setCollapsed((value) => ({ ...value, [group.label]: !value[group.label] }))}
                  aria-expanded={!isCollapsed}
                >
                  <span>{group.label}</span><span aria-hidden="true">{isCollapsed ? '›' : '⌄'}</span>
                </button>
              )}
              {!isCollapsed && group.items.map((item) => {
                const active = item.key === current;
                return (
                  <button
                    key={item.key}
                    type="button"
                    title={!open ? item.label : undefined}
                    onClick={() => onSelect(item.key)}
                    aria-current={active ? 'page' : undefined}
                    className={`group flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors ${
                      active
                        ? 'bg-brand/10 text-brand ring-1 ring-brand/15'
                        : 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800'
                    }`}
                  >
                    <span className={`grid h-7 w-7 shrink-0 place-items-center rounded-lg text-base leading-none ${active ? 'bg-brand/10' : 'bg-zinc-100 group-hover:bg-white dark:bg-zinc-800 dark:group-hover:bg-zinc-700'}`}>{item.icon}</span>
                    {open && <span className="truncate">{item.label}</span>}
                  </button>
                );
              })}
            </div>
          );
        })}
        {open && groups.length === 0 && <div className="px-3 py-8 text-center text-xs text-zinc-500">No matching workspace.</div>}
      </nav>
      <div className="border-t border-zinc-200 p-3 dark:border-zinc-800">
        {open ? (
          <div className="rounded-xl bg-zinc-50 px-3 py-2 dark:bg-zinc-950/60">
            <div className="text-[10px] font-semibold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">System ready</div>
            <div className="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Core v{(window.G2A_POS_ADMIN as { version?: string } | undefined)?.version || '—'}</div>
          </div>
        ) : <div className="text-center text-xs text-zinc-400">v{String((window.G2A_POS_ADMIN as { version?: string } | undefined)?.version || '').split('.')[0]}</div>}
      </div>
    </aside>
  );
}
