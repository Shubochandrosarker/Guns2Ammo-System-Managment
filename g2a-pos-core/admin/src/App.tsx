import { useEffect, useState } from 'react';
import { applyTheme, currentTheme, toggleTheme } from './theme';
import Sidebar, { NAV, type NavKey } from './components/Sidebar';
import Topbar from './components/Topbar';
import Dashboard from './views/Dashboard';
import Kpis from './views/Kpis';
import Orders from './views/Orders';
import Inventory from './views/Inventory';
import CatalogIdentities from './views/CatalogIdentities';
import UsedFirearms from './views/UsedFirearms';
import InventoryImport from './views/InventoryImport';
import Distributors from './views/Distributors';
import Wholesalers from './views/Wholesalers';
import VendorCatalog from './views/VendorCatalog';
import MapPricing from './views/MapPricing';
import DropShipOrders from './views/DropShipOrders';
import PurchaseOrders from './views/PurchaseOrders';
import CycleCounts from './views/CycleCounts';
import Shipping from './views/Shipping';
import BoundBook from './views/BoundBook';
import Forms4473 from './views/Forms4473';
import Form4473Calibration from './views/Form4473Calibration';
import NicsQueue from './views/NicsQueue';
import CcwExemption from './views/CcwExemption';
import Reports from './views/Reports';
import Registers from './views/Registers';
import StateRules from './views/StateRules';
import Webhooks from './views/Webhooks';
import Membership from './views/Membership';
import Waivers from './views/Waivers';
import LaneReservations from './views/LaneReservations';
import Classes from './views/Classes';
import Customers from './views/Customers';
import Loyalty from './views/Loyalty';
import GiftCards from './views/GiftCards';
import Layaways from './views/Layaways';
import Consignments from './views/Consignments';
import Repairs from './views/Repairs';
import SplitTender from './views/SplitTender';
import OrderSourcing from './views/OrderSourcing';
import TradeIns from './views/TradeIns';
import RangeOps from './views/RangeOps';
import RangeSafety from './views/RangeSafety';
import Nfa from './views/Nfa';
import LocationTransfers from './views/LocationTransfers';
import Hardware from './views/Hardware';
import ComplianceCalendar from './views/ComplianceCalendar';
import AceAudit from './views/AceAudit';
import FflRouting from './views/FflRouting';
import FflTransfers from './views/FflTransfers';
import Messaging from './views/Messaging';
import AiSettings from './views/AiSettings';
import AiBrain from './views/AiBrain';
import AiAudit from './views/AiAudit';
import AgentOmnibar from './components/AgentOmnibar';
import Settings from './views/Settings';

const VIEWS: Record<NavKey, () => JSX.Element> = {
  dashboard: Dashboard,
  kpis: Kpis,
  orders: Orders,
  inventory: Inventory,
  catalog_identities: CatalogIdentities,
  used_firearms: UsedFirearms,
  inventory_import: InventoryImport,
  distributors: Distributors,
  wholesalers: Wholesalers,
  vendor_catalog: VendorCatalog,
  map_pricing: MapPricing,
  dropship_orders: DropShipOrders,
  purchase_orders: PurchaseOrders,
  cycle_counts: CycleCounts,
  shipping: Shipping,
  bound_book: BoundBook,
  forms_4473: Forms4473,
  forms_4473_calibration: Form4473Calibration,
  nics: NicsQueue,
  ccw_exemption: CcwExemption,
  reports: Reports,
  registers: Registers,
  state_rules: StateRules,
  webhooks: Webhooks,
  membership: Membership,
  waivers: Waivers,
  lane_reservations: LaneReservations,
  classes: Classes,
  customers: Customers,
  loyalty: Loyalty,
  gift_cards: GiftCards,
  layaways: Layaways,
  consignments: Consignments,
  repairs: Repairs,
  split_tender: SplitTender,
  order_sourcing: OrderSourcing,
  tradeins: TradeIns,
  range_ops: RangeOps,
  range_safety: RangeSafety,
  nfa: Nfa,
  location_transfers: LocationTransfers,
  hardware: Hardware,
  compliance_calendar: ComplianceCalendar,
  ace_audit: AceAudit,
  ffl_routing: FflRouting,
  ffl_transfers: FflTransfers,
  messaging: Messaging,
  ai_settings: AiSettings,
  ai_brain: AiBrain,
  ai_audit: AiAudit,
  settings: Settings,
};

export default function App() {
  const [view, setView] = useState<NavKey>(() => {
    const hash = window.location.hash.replace(/^#/, '') as NavKey;
    return NAV.some((n) => n.key === hash) ? hash : 'dashboard';
  });
  const [theme, setTheme] = useState(currentTheme());
  const [sidebarOpen, setSidebarOpen] = useState(true);

  useEffect(() => {
    applyTheme(theme);
  }, [theme]);

  useEffect(() => {
    window.location.hash = view;
  }, [view]);

  useEffect(() => {
    const handler = () => {
      const hash = window.location.hash.replace(/^#/, '') as NavKey;
      if (NAV.some((n) => n.key === hash)) setView(hash);
    };
    window.addEventListener('hashchange', handler);
    return () => window.removeEventListener('hashchange', handler);
  }, []);

  const View = VIEWS[view];

  return (
    <div className="flex h-full min-h-screen w-full bg-zinc-50 text-zinc-900 dark:bg-zinc-950 dark:text-zinc-100">
      <Sidebar current={view} onSelect={setView} open={sidebarOpen} />
      <div className="flex min-w-0 flex-1 flex-col">
        <Topbar
          theme={theme}
          onToggleTheme={() => setTheme(toggleTheme())}
          onToggleSidebar={() => setSidebarOpen((o) => !o)}
        />
        <main className="w-full min-w-0 flex-1 overflow-auto p-4 sm:p-6">
          <View />
        </main>
      </div>
      <AgentOmnibar />
    </div>
  );
}
