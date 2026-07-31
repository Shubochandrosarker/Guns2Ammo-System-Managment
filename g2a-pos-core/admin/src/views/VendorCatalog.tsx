import { useEffect, useMemo, useState } from 'react';
import { get, post, errorMessage } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Wholesaler { id: number; provider_code: string; display_name: string; }

interface VendorProduct {
  id: number;
  vendor_sku: string;
  upc?: string;
  manufacturer?: string;
  name: string;
  vendor_category?: string;
  item_type?: string;
  stock_qty: number;
  current_price?: number | string;
  wholesale_price?: number | string;
  map_price?: number | string;
  msrp?: number | string;
  can_dropship: number;
  ffl_required: number;
  image_filename?: string;
  wc_product_id?: number | null;
}

const PAGE_SIZE = 50;
/** Rows per bulk-mirror request. Each row is an HTTP fetch + a sideload, so
 *  keep the batch small enough to stay inside a stock PHP max_execution_time. */
const BULK_BATCH = 25;

interface BulkFailure { vendor_sku: string; url?: string; error?: string; message?: string; }
interface BulkStats { processed: number; imported: number; reused: number; skipped: number; failed: number; }
interface BulkResponse {
  ok?: boolean;
  stats?: BulkStats;
  failures?: BulkFailure[];
  next_offset?: number;
  total?: number;
  remaining?: number;
  done?: boolean;
}
interface BulkState {
  running: boolean;
  totals: BulkStats;
  total: number | null;
  failures: BulkFailure[];
  error?: string;
}

export default function VendorCatalog() {
  const [wholesalers, setWholesalers] = useState<Wholesaler[]>([]);
  const [wholesalerId, setWholesalerId] = useState<number | null>(null);
  const [items, setItems] = useState<VendorProduct[]>([]);
  const [loading, setLoading] = useState(false);
  const [page, setPage] = useState(1);
  const [filter, setFilter] = useState({ q: '', category: '', item_type: '', in_stock: false, dropship_only: false });
  const [mirror, setMirror] = useState<Record<string, { busy?: boolean; ok?: boolean; msg?: string }>>({});
  const [bulk, setBulk] = useState<BulkState | null>(null);

  const loadWholesalers = async () => {
    const r = await get<{ ok: boolean; wholesalers: Wholesaler[] }>('/wholesalers');
    setWholesalers(r.wholesalers || []);
    if (r.wholesalers?.length && wholesalerId === null) {
      setWholesalerId(r.wholesalers[0].id);
    }
  };
  useEffect(() => {
    loadWholesalers();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const loadProducts = async () => {
    if (!wholesalerId) return;
    setLoading(true);
    try {
      const r = await get<{ ok: boolean; items: VendorProduct[] }>(
        `/wholesalers/${wholesalerId}/products`,
        {
          q: filter.q,
          category: filter.category,
          item_type: filter.item_type,
          in_stock: filter.in_stock ? 1 : '',
          dropship_only: filter.dropship_only ? 1 : '',
          limit: PAGE_SIZE,
          offset: (page - 1) * PAGE_SIZE,
        }
      );
      setItems(r.items || []);
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => { loadProducts(); }, [wholesalerId, page]); // eslint-disable-line

  const mirrorImage = async (row: VendorProduct) => {
    setMirror((s) => ({ ...s, [row.vendor_sku]: { busy: true } }));
    try {
      // The SKU travels in the body, not the path. Vendor SKUs routinely
      // contain '/', '#' and spaces, and a slash inside a path segment is
      // decoded by the web server before WP matches routes — those rows came
      // back as "No route was found matching the URL and request method"
      // however carefully the client encoded them.
      const r = await post<{ ok?: boolean; reused?: boolean; attachment_id?: number; error?: string; message?: string }>(
        `/wholesalers/${wholesalerId}/mirror-image`,
        { vendor_sku: row.vendor_sku, set_featured: !!row.wc_product_id, wc_product_id: row.wc_product_id || 0 }
      );
      setMirror((s) => ({
        ...s,
        [row.vendor_sku]: {
          ok: !!r.ok,
          msg: r.ok
            ? r.reused
              ? `Reused #${r.attachment_id}`
              : `Imported #${r.attachment_id}`
            : r.message || r.error || 'Failed',
        },
      }));
    } catch (e) {
      setMirror((s) => ({ ...s, [row.vendor_sku]: { ok: false, msg: errorMessage(e, 'failed') } }));
    }
  };

  /** Walk the whole vendor catalog, mirroring every row that has an image. */
  const mirrorAll = async () => {
    if (!wholesalerId || bulk?.running) return;
    const totals = { processed: 0, imported: 0, reused: 0, skipped: 0, failed: 0 };
    setBulk({ running: true, totals, total: null, failures: [] });
    let offset = 0;
    try {
      for (;;) {
        const r = await post<BulkResponse>(`/wholesalers/${wholesalerId}/mirror-images`, {
          limit: BULK_BATCH,
          offset,
          set_featured: true,
        });
        (Object.keys(totals) as (keyof typeof totals)[]).forEach((k) => {
          totals[k] += r.stats?.[k] || 0;
        });
        offset = r.next_offset ?? offset + BULK_BATCH;
        setBulk({
          running: true,
          totals: { ...totals },
          total: r.total ?? null,
          failures: (r.failures || []).slice(0, 10),
        });
        if (r.done || !r.stats || r.stats.processed === 0) break;
      }
      setBulk((s) => (s ? { ...s, running: false } : s));
      await loadProducts();
    } catch (e) {
      setBulk((s) => ({
        running: false,
        totals: s?.totals ?? totals,
        total: s?.total ?? null,
        failures: s?.failures ?? [],
        error: errorMessage(e, 'Bulk mirroring failed'),
      }));
    }
  };

  const money = (v?: number | string) => {
    if (v === undefined || v === null || v === '') return '—';
    const n = typeof v === 'number' ? v : parseFloat(v);
    return isNaN(n) ? '—' : `$${n.toFixed(2)}`;
  };

  const cols: Column<VendorProduct>[] = useMemo(() => [
    { key: 'vendor_sku', label: 'SKU', render: (r) => <code className="text-xs">{r.vendor_sku}</code> },
    { key: 'upc', label: 'UPC', render: (r) => r.upc || '—' },
    { key: 'manufacturer', label: 'Mfg' },
    { key: 'name', label: 'Name' },
    { key: 'vendor_category', label: 'Category' },
    { key: 'stock_qty', label: 'Qty', align: 'right' },
    { key: 'current_price', label: 'Cost', align: 'right', render: (r) => money(r.current_price ?? r.wholesale_price) },
    { key: 'map_price', label: 'MAP', align: 'right', render: (r) => money(r.map_price) },
    { key: 'msrp', label: 'MSRP', align: 'right', render: (r) => money(r.msrp) },
    {
      key: 'flags', label: 'Flags', render: (r) => (
        <div className="flex flex-wrap gap-1">
          {r.can_dropship ? <Tag color="emerald">DropShip</Tag> : null}
          {r.ffl_required ? <Tag color="amber">FFL</Tag> : null}
          {r.wc_product_id ? <Tag color="blue">WC #{r.wc_product_id}</Tag> : null}
        </div>
      ),
    },
    {
      key: 'actions', label: 'Image', render: (r) => {
        if (!r.image_filename) return <span className="text-xs text-zinc-400">no image</span>;
        const m = mirror[r.vendor_sku];
        return (
          <div className="flex items-center gap-2">
            <button className="btn-secondary text-xs" disabled={m?.busy} onClick={() => mirrorImage(r)}>
              {m?.busy ? 'Mirroring…' : 'Mirror image'}
            </button>
            {m && !m.busy && (
              <span className={`text-xs ${m.ok ? 'text-emerald-600' : 'text-rose-600'}`}>{m.msg}</span>
            )}
          </div>
        );
      },
    },
    // eslint-disable-next-line react-hooks/exhaustive-deps
  ], [mirror]);

  return (
    <>
      <PageHeader
        title="Vendor catalog"
        subtitle="Browse and search wholesaler-side product catalog. Mirror images to your media library on demand."
      />

      <div className="card mb-4 p-4">
        <div className="grid grid-cols-1 gap-3 md:grid-cols-6">
          <label className="flex flex-col gap-1 text-xs md:col-span-2">
            Wholesaler
            <select className="input" value={wholesalerId ?? ''} onChange={(e) => { setWholesalerId(Number(e.target.value)); setPage(1); }}>
              {wholesalers.map((w) => (
                <option key={w.id} value={w.id}>{w.display_name} (#{w.id})</option>
              ))}
            </select>
          </label>
          <label className="flex flex-col gap-1 text-xs md:col-span-2">
            Search
            <input className="input" placeholder="UPC, SKU, name, manufacturer" value={filter.q} onChange={(e) => setFilter({ ...filter, q: e.target.value })} />
          </label>
          <label className="flex flex-col gap-1 text-xs">
            Category
            <input className="input" value={filter.category} onChange={(e) => setFilter({ ...filter, category: e.target.value })} />
          </label>
          <label className="flex flex-col gap-1 text-xs">
            Item type
            <select className="input" value={filter.item_type} onChange={(e) => setFilter({ ...filter, item_type: e.target.value })}>
              <option value="">Any</option>
              <option value="firearm">firearm</option>
              <option value="ammunition">ammunition</option>
              <option value="accessory">accessory</option>
              <option value="optic">optic</option>
              <option value="nfa">nfa</option>
            </select>
          </label>
          <label className="flex items-center gap-2 text-xs">
            <input type="checkbox" checked={filter.in_stock} onChange={(e) => setFilter({ ...filter, in_stock: e.target.checked })} /> In stock
          </label>
          <label className="flex items-center gap-2 text-xs">
            <input type="checkbox" checked={filter.dropship_only} onChange={(e) => setFilter({ ...filter, dropship_only: e.target.checked })} /> Drop-ship only
          </label>
          <div className="md:col-span-6 flex flex-wrap items-center justify-end gap-2">
            <button className="btn-secondary" disabled={!wholesalerId || !!bulk?.running} onClick={mirrorAll}>
              {bulk?.running ? 'Mirroring catalog…' : 'Mirror all images'}
            </button>
            <button className="btn-primary" onClick={() => { setPage(1); loadProducts(); }}>Apply filters</button>
          </div>
        </div>
      </div>

      {bulk && (
        <div className="card mb-4 p-4 text-sm">
          <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
            <strong>{bulk.running ? 'Mirroring vendor catalog images…' : 'Catalog image mirroring finished'}</strong>
            <span>{bulk.totals.processed}{bulk.total !== null ? ` / ${bulk.total}` : ''} rows</span>
            <span className="text-emerald-600">{bulk.totals.imported} imported</span>
            <span className="text-zinc-500">{bulk.totals.reused} reused</span>
            <span className="text-zinc-500">{bulk.totals.skipped} no image</span>
            {bulk.totals.failed > 0 && <span className="text-rose-600">{bulk.totals.failed} failed</span>}
          </div>
          {bulk.error && <p className="mt-2 text-rose-600">{bulk.error}</p>}
          {bulk.failures.length > 0 && (
            <ul className="mt-2 space-y-0.5 text-xs text-rose-600">
              {bulk.failures.map((f) => (
                <li key={f.vendor_sku}>
                  <code>{f.vendor_sku}</code> — {f.message || f.error}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      <DataTable rows={items} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No products. Upload a vendor catalog CSV via Inventory → Import CSV." />

      <div className="mt-4 flex items-center justify-between text-sm text-zinc-500">
        <span>Page {page} · {items.length} rows shown</span>
        <div className="flex gap-2">
          <button className="btn-secondary" disabled={page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))}>← Prev</button>
          <button className="btn-secondary" disabled={items.length < PAGE_SIZE} onClick={() => setPage((p) => p + 1)}>Next →</button>
        </div>
      </div>
    </>
  );
}

function Tag({ color, children }: { color: 'emerald' | 'amber' | 'blue'; children: React.ReactNode }) {
  const map = {
    emerald: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    amber: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    blue: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
  };
  return <span className={`rounded px-1.5 py-0.5 text-[10px] font-medium ${map[color]}`}>{children}</span>;
}
