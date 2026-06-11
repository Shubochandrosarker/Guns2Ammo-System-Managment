import { useEffect, useState } from 'react';
import { get, errorMessage } from '../api';
import PageHeader from '../components/PageHeader';

interface VendorRow {
  vendor_name: string;
  vendor_sku: string;
  upc: string | null;
  wholesale_price: number;
  effective_cost: number;
  msrp: number | null;
  map_price: number | null;
  stock_qty: number;
  can_dropship: boolean;
  suggested_sell_price: number;
  margin_dollars: number;
  margin_pct: number;
  sellable: boolean;
}

interface Recommendation {
  source: string;
  reason: string;
  sell_price?: number | null;
  vendor_name?: string;
  vendor_sku?: string;
  wholesale_price?: number;
  margin_pct?: number;
}

interface SourcesPayload {
  shelf: { on_hand: number; price: number | null; suggested_sell_price: number | null };
  vendors: VendorRow[];
  map_price: number | null;
  recommendation: Recommendation;
  markup_pct_used: number;
}

interface Line {
  sku: string | null;
  name: string | null;
  product_id: number;
  quantity: number;
  identity: { id: number; canonical_code: string; manufacturer: string; model: string; caliber: string | null } | null;
  sources: SourcesPayload | null;
  recommendation: Recommendation;
}

interface OrderResponse {
  order_id: number;
  order_status: string;
  lines: Line[];
  opts: { markup_pct: number; max_overage_pct: number; dropship_fee: number };
}

const REC_BADGE: Record<string, string> = {
  shelf: 'border-emerald-400 bg-emerald-50 dark:bg-emerald-900/20',
  vendor_stock: 'border-amber-400 bg-amber-50 dark:bg-amber-900/20',
  dropship: 'border-sky-400 bg-sky-50 dark:bg-sky-900/20',
  unresolved: 'border-rose-400 bg-rose-50 dark:bg-rose-900/20',
  none: 'border-rose-400 bg-rose-50 dark:bg-rose-900/20',
  identity_error: 'border-rose-400 bg-rose-50 dark:bg-rose-900/20',
};

export default function OrderSourcing() {
  const [orderId, setOrderId] = useState('');
  const [markupPct, setMarkupPct] = useState('');
  const [data, setData] = useState<OrderResponse | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  const load = async (idOverride?: number) => {
    const useId = idOverride ?? parseInt(orderId, 10);
    if (!useId || isNaN(useId)) { setData(null); return; }
    setError(null);
    setLoading(true);
    try {
      const r = await get<OrderResponse>(`/orders/${useId}/sourcing`,
        markupPct ? { markup_pct: parseFloat(markupPct) } : undefined);
      setData(r);
    } catch (e) {
      setData(null);
      setError(errorMessage(e, 'Failed to load'));
    } finally { setLoading(false); }
  };

  useEffect(() => {
    const m = window.location.hash.match(/order=(\d+)/);
    if (m) { setOrderId(m[1]); setTimeout(() => load(parseInt(m[1], 10)), 0); }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <div>
      <PageHeader
        title="Order-Sheet Sourcing"
        subtitle="For each line item on an order: shelf stock, every linked wholesaler's stock + wholesale price, MAP-aware suggested sell price, and a sourcing recommendation. Pulls from the Identity Graph + MAP rules + global markup."
      />

      <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 items-end">
        <div className="col-span-2">
          <label className="block text-xs mb-1">Order ID</label>
          <input className="input w-full" placeholder="e.g. 42" value={orderId}
            onChange={(e) => setOrderId(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && load()} />
        </div>
        <div>
          <label className="block text-xs mb-1">Markup % (override)</label>
          <input className="input w-full" type="number" step="0.1" placeholder="default"
            value={markupPct} onChange={(e) => setMarkupPct(e.target.value)} />
        </div>
        <button className="btn-primary" onClick={() => load()} disabled={loading}>
          {loading ? 'Loading…' : 'Load'}
        </button>
      </div>

      {error && <div className="card p-3 mb-4 border-rose-400 text-rose-700">{error}</div>}

      {data && (
        <>
          <div className="text-sm text-zinc-500 mb-3">
            Order #{data.order_id} · status: <span className="badge-zinc">{data.order_status}</span> ·
            markup: {data.opts.markup_pct}% · max overage: {data.opts.max_overage_pct}% ·
            drop-ship fee: ${data.opts.dropship_fee.toFixed(2)} · {data.lines.length} line item{data.lines.length === 1 ? '' : 's'}
          </div>

          {data.lines.map((line, i) => (
            <div key={i} className="card p-4 mb-4">
              <div className="flex justify-between items-start mb-2">
                <div>
                  <h3 className="font-semibold">{line.name || line.sku || `Line #${i + 1}`}</h3>
                  <p className="text-xs text-zinc-500">
                    {line.sku && <>SKU <span className="font-mono">{line.sku}</span> · </>}
                    qty {line.quantity}
                    {line.identity && (
                      <> · identity <span className="font-mono">{line.identity.canonical_code}</span> — {line.identity.manufacturer} {line.identity.model}{line.identity.caliber && <> ({line.identity.caliber})</>}</>
                    )}
                  </p>
                </div>
              </div>

              <div className={`card p-3 mb-3 ${REC_BADGE[line.recommendation.source] || 'border-zinc-300'}`}>
                <div className="text-xs uppercase font-semibold text-zinc-500">Sourcing recommendation</div>
                <div className="text-sm font-medium mt-1">{line.recommendation.reason}</div>
                {line.recommendation.sell_price !== undefined && line.recommendation.sell_price !== null && (
                  <div className="text-sm mt-1">
                    Suggested sell price: <strong>${line.recommendation.sell_price.toFixed(2)}</strong>
                    {line.recommendation.margin_pct !== undefined && <span className="text-zinc-500"> · margin {line.recommendation.margin_pct}%</span>}
                  </div>
                )}
              </div>

              {line.sources && (
                <>
                  <div className="text-sm mb-2">
                    <strong>Shelf:</strong> {line.sources.shelf.on_hand} on hand
                    {line.sources.shelf.price !== null && <> · current price ${line.sources.shelf.price.toFixed(2)}</>}
                    {line.sources.shelf.suggested_sell_price !== null && line.sources.shelf.suggested_sell_price !== line.sources.shelf.price && (
                      <> · suggested ${line.sources.shelf.suggested_sell_price.toFixed(2)}</>
                    )}
                    {line.sources.map_price !== null && (
                      <span className="text-xs text-zinc-500 ml-2">MAP floor ${line.sources.map_price.toFixed(2)}</span>
                    )}
                  </div>

                  <table className="w-full text-sm">
                    <thead className="text-xs uppercase text-zinc-500">
                      <tr className="text-left border-b border-zinc-200 dark:border-zinc-800">
                        <th className="py-1">Vendor</th><th>SKU</th>
                        <th className="text-right">Wholesale</th>
                        <th className="text-right">Stock</th>
                        <th className="text-right">Suggested</th>
                        <th className="text-right">Margin</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      {line.sources.vendors.map((v, j) => (
                        <tr key={j} className="border-b border-zinc-100 dark:border-zinc-800">
                          <td className="py-1">{v.vendor_name}</td>
                          <td className="font-mono text-xs">{v.vendor_sku}</td>
                          <td className="text-right">${v.wholesale_price.toFixed(2)}</td>
                          <td className="text-right">{v.stock_qty}</td>
                          <td className="text-right font-semibold">${v.suggested_sell_price.toFixed(2)}</td>
                          <td className="text-right">{v.margin_pct}%</td>
                          <td>
                            {v.stock_qty > 0 ? <span className="badge-green">in stock</span>
                              : v.can_dropship ? <span className="badge-amber">drop-ship</span>
                              : <span className="badge-red">unavailable</span>}
                          </td>
                        </tr>
                      ))}
                      {line.sources.vendors.length === 0 && (
                        <tr><td colSpan={7} className="text-center text-zinc-500 py-3">No wholesaler links for this identity.</td></tr>
                      )}
                    </tbody>
                  </table>
                </>
              )}

              {!line.identity && (
                <div className="text-xs text-zinc-500">
                  No canonical identity matched. Run the Identity Graph backfill, or add a link manually
                  ({line.sku && <>SKU <code>{line.sku}</code></>}{line.product_id > 0 && <> · Woo product <code>{line.product_id}</code></>}).
                </div>
              )}
            </div>
          ))}
        </>
      )}
    </div>
  );
}
