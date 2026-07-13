import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Identity {
  id: number;
  canonical_code: string;
  item_type: string;
  manufacturer: string;
  model: string;
  family: string | null;
  caliber: string | null;
  msrp: string | null;
  status: string;
  review_required: number;
}

interface IdentityLink {
  id: number;
  source_type: string;
  source_ref: string;
  wholesaler_id: number | null;
  confidence: string;
  matched_by: string;
  last_seen_at: string;
}

interface SourcesPayload {
  ok: boolean;
  identity: Identity;
  shelf: { on_hand: number; price: number | null; woo_product_ids: number[] };
  vendors: {
    vendor_name: string;
    vendor_sku: string;
    upc: string | null;
    current_price: string | null;
    msrp: string | null;
    map_price: string | null;
    stock_qty: number;
    can_dropship: number;
  }[];
  recommendation: { source: string; reason: string };
  links: IdentityLink[];
}

export default function CatalogIdentities() {
  const [rows, setRows] = useState<Identity[]>([]);
  const [loading, setLoading] = useState(true);
  const [q, setQ] = useState('');
  const [reviewOnly, setReviewOnly] = useState(false);
  const [open, setOpen] = useState<SourcesPayload | null>(null);
  const [backfilling, setBackfilling] = useState(false);

  const refresh = async () => {
    setLoading(true);
    try {
      const r = await get<{ items: Identity[] }>('/catalog/identities', {
        q, review_required: reviewOnly ? 1 : '', limit: 100,
      });
      setRows(r.items || []);
    } finally { setLoading(false); }
  };
  useEffect(() => {
    queueMicrotask(refresh);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const openOne = async (id: number) => {
    const r = await get<SourcesPayload>(`/catalog/identities/${id}/sources`);
    setOpen(r);
  };

  const runBackfill = async () => {
    if (!confirm('Walk every wholesaler product through the matcher and link to canonical identities. Safe to re-run. Process up to 200 rows?')) return;
    setBackfilling(true);
    try {
      const r = await post<{ processed: number; created: number; matched: number }>('/catalog/identities/backfill?limit=200', {});
      alert(`Backfill: processed ${r.processed} · created ${r.created} · matched ${r.matched}`);
      await refresh();
    } finally { setBackfilling(false); }
  };

  const cols: Column<Identity>[] = [
    { key: 'canonical_code', label: 'Code', render: (r) => (
      <button className="link font-mono" onClick={() => openOne(r.id)}>{r.canonical_code}</button>
    ) },
    { key: 'manufacturer', label: 'Manufacturer', render: (r) => <span className="font-medium">{r.manufacturer}</span> },
    { key: 'model', label: 'Model' },
    { key: 'caliber', label: 'Caliber' },
    { key: 'item_type', label: 'Type' },
    { key: 'msrp', label: 'MSRP', align: 'right', render: (r) => r.msrp ? `$${parseFloat(r.msrp).toFixed(2)}` : '—' },
    { key: 'review_required', label: '', render: (r) => r.review_required ? <span className="badge-amber">review</span> : '' },
    { key: 'status', label: 'Status' },
  ];

  return (
    <div>
      <PageHeader
        title="Item Identity Graph"
        subtitle="One canonical record per real-world item, regardless of how many vendor SKUs / UPCs / Woo products / mfg part numbers / used intakes refer to it. The catalog truth across the supply chain — and the foundation for the order-sheet sourcing panel."
        actions={<button className="btn-secondary" onClick={runBackfill} disabled={backfilling}>{backfilling ? 'Backfilling…' : 'Backfill from vendors'}</button>}
      />

      <div className="card p-3 mb-4 flex gap-2 items-center">
        <input className="input flex-1" placeholder="Search make/model/code/caliber"
          value={q} onChange={(e) => setQ(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && refresh()} />
        <label className="text-sm flex items-center gap-1">
          <input type="checkbox" checked={reviewOnly} onChange={(e) => setReviewOnly(e.target.checked)} />
          Review-required only
        </label>
        <button className="btn-primary" onClick={refresh}>Search</button>
      </div>

      <DataTable<Identity> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No identities yet. Run a backfill above." />

      {open && (
        <div className="card p-4 mt-4">
          <div className="flex justify-between items-start mb-3">
            <div>
              <h3 className="font-semibold">{open.identity.canonical_code} — {open.identity.manufacturer} {open.identity.model}</h3>
              <p className="text-xs text-zinc-500">{open.identity.caliber} · {open.identity.item_type}</p>
            </div>
            <button className="btn-sm" onClick={() => setOpen(null)}>Close</button>
          </div>

          <div className={`card p-3 mb-4 ${open.recommendation.source === 'shelf' ? 'border-emerald-400' :
            open.recommendation.source === 'wholesaler' ? 'border-amber-400' : 'border-rose-400'}`}>
            <div className="text-xs uppercase font-semibold text-zinc-500">Sourcing recommendation</div>
            <div className="text-sm font-medium mt-1">{open.recommendation.reason}</div>
          </div>

          <h4 className="text-sm font-semibold mb-2">Shelf</h4>
          <div className="text-sm mb-4">
            On hand: <strong>{open.shelf.on_hand}</strong>
            {open.shelf.price !== null && <> · Price: <strong>${open.shelf.price.toFixed(2)}</strong></>}
            {open.shelf.woo_product_ids.length > 0 && (
              <span className="text-xs text-zinc-500"> · Woo IDs: {open.shelf.woo_product_ids.join(', ')}</span>
            )}
          </div>

          <h4 className="text-sm font-semibold mb-2">Wholesaler matrix ({open.vendors.length})</h4>
          <table className="w-full text-sm mb-4">
            <thead><tr className="text-left">
              <th>Vendor</th><th>SKU</th><th>UPC</th>
              <th className="text-right">Price</th><th className="text-right">MAP</th>
              <th className="text-right">Stock</th><th>Drop-ship</th>
            </tr></thead>
            <tbody>{open.vendors.map((v, i) => (
              <tr key={i} className="border-t border-zinc-100 dark:border-zinc-800">
                <td>{v.vendor_name}</td>
                <td className="font-mono text-xs">{v.vendor_sku}</td>
                <td className="font-mono text-xs">{v.upc ?? '—'}</td>
                <td className="text-right">{v.current_price ? `$${parseFloat(v.current_price).toFixed(2)}` : '—'}</td>
                <td className="text-right">{v.map_price ? `$${parseFloat(v.map_price).toFixed(2)}` : '—'}</td>
                <td className="text-right">{v.stock_qty}</td>
                <td>{v.can_dropship ? '✓' : ''}</td>
              </tr>
            ))}{open.vendors.length === 0 && (
              <tr><td colSpan={7} className="text-center text-zinc-500 py-3">No wholesaler links — backfill or add a vendor row.</td></tr>
            )}</tbody>
          </table>

          <details>
            <summary className="text-sm font-semibold cursor-pointer">Links ({open.links.length})</summary>
            <table className="w-full text-xs mt-2">
              <thead><tr className="text-left">
                <th>Source</th><th>Ref</th><th>Wholesaler</th>
                <th className="text-right">Confidence</th><th>Matched by</th><th>Last seen</th>
              </tr></thead>
              <tbody>{open.links.map((l) => (
                <tr key={l.id}>
                  <td><span className="badge-zinc">{l.source_type}</span></td>
                  <td className="font-mono">{l.source_ref}</td>
                  <td>{l.wholesaler_id ?? '—'}</td>
                  <td className="text-right">{parseFloat(l.confidence).toFixed(3)}</td>
                  <td>{l.matched_by}</td>
                  <td>{l.last_seen_at}</td>
                </tr>
              ))}</tbody>
            </table>
          </details>
        </div>
      )}
    </div>
  );
}
