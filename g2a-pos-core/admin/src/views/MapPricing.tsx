import { useEffect, useState } from 'react';
import { get, post, errorMessage } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface MapRule {
  id: number;
  wc_product_id?: number | null;
  sku?: string;
  upc?: string;
  manufacturer?: string;
  map_price: number | string;
  currency: string;
  source: string;
  display_mode: string;
  override_label?: string;
  effective_at?: string;
  expires_at?: string;
  notes?: string;
}

interface EvalResult {
  has_rule: boolean;
  map_price?: number;
  active_price?: number;
  below_map?: boolean;
  display_mode?: string;
  override_label?: string;
  source?: string;
  rule_id?: number;
}

const EMPTY = {
  wc_product_id: '',
  map_price: '',
  display_mode: 'click_to_reveal',
  override_label: '',
  effective_at: '',
  expires_at: '',
  notes: '',
};

export default function MapPricing() {
  const [rules, setRules] = useState<MapRule[]>([]);
  const [loading, setLoading] = useState(true);
  const [form, setForm] = useState({ ...EMPTY });
  const [busy, setBusy] = useState(false);
  const [evalForm, setEvalForm] = useState({ wc_product_id: '', price: '' });
  const [evalResult, setEvalResult] = useState<EvalResult | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = async () => {
    setLoading(true);
    try {
      const r = await get<{ ok: boolean; rules: MapRule[] }>('/pricing/map', { limit: 200 });
      setRules(r.rules || []);
    } finally { setLoading(false); }
  };
  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const save = async () => {
    setError(null);
    setBusy(true);
    try {
      await post('/pricing/map', {
        wc_product_id: Number(form.wc_product_id),
        map_price: Number(form.map_price),
        display_mode: form.display_mode,
        override_label: form.override_label || undefined,
        effective_at: form.effective_at || undefined,
        expires_at: form.expires_at || undefined,
        notes: form.notes || undefined,
      });
      setForm({ ...EMPTY });
      await load();
    } catch (e) {
      setError(errorMessage(e, 'Save failed'));
    } finally { setBusy(false); }
  };

  const runEval = async () => {
    setEvalResult(null);
    if (!evalForm.wc_product_id) return;
    const r = await get<{ ok: boolean; result: EvalResult }>('/pricing/map/evaluate', {
      wc_product_id: evalForm.wc_product_id,
      price: evalForm.price || undefined,
    });
    setEvalResult(r.result);
  };

  const money = (v: number | string | null | undefined) => {
    const n = typeof v === 'number' ? v : parseFloat(String(v ?? ''));
    return isNaN(n) ? '—' : `$${n.toFixed(2)}`;
  };

  const cols: Column<MapRule>[] = [
    { key: 'id', label: 'ID', width: '60px' },
    { key: 'wc_product_id', label: 'WC Product', render: (r) => r.wc_product_id ?? '—' },
    { key: 'sku', label: 'SKU' },
    { key: 'upc', label: 'UPC' },
    { key: 'manufacturer', label: 'Mfg' },
    { key: 'map_price', label: 'MAP', align: 'right', render: (r) => money(r.map_price) },
    {
      key: 'display_mode', label: 'Mode', render: (r) => (
        <span className="rounded bg-zinc-200 px-1.5 py-0.5 text-xs dark:bg-zinc-800">{r.display_mode}</span>
      ),
    },
    { key: 'source', label: 'Source' },
    { key: 'effective_at', label: 'Effective' },
    { key: 'expires_at', label: 'Expires' },
  ];

  return (
    <>
      <PageHeader
        title="MAP pricing"
        subtitle="Minimum Advertised Price rules. When a product's sale price drops below MAP, the storefront price is hidden behind a click-to-reveal — the actual cart/checkout price is unchanged."
      />

      {error && <div className="card mb-4 border-l-4 border-rose-500 p-4 text-sm text-rose-600">{error}</div>}

      <DataTable rows={rules} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No MAP rules yet. Upload a Lipsey's CSV to auto-discover MAP, or add a manual rule below." />

      <div className="grid grid-cols-1 gap-6 mt-6 lg:grid-cols-2">
        <div className="card p-6">
          <h3 className="mb-4 text-lg font-semibold">Add / update rule</h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Field label="WooCommerce product ID *">
              <input className="input" type="number" value={form.wc_product_id} onChange={(e) => setForm({ ...form, wc_product_id: e.target.value })} />
            </Field>
            <Field label="MAP price *">
              <input className="input" type="number" step="0.01" value={form.map_price} onChange={(e) => setForm({ ...form, map_price: e.target.value })} />
            </Field>
            <Field label="Display mode">
              <select className="input" value={form.display_mode} onChange={(e) => setForm({ ...form, display_mode: e.target.value })}>
                <option value="click_to_reveal">click_to_reveal</option>
                <option value="show_map">show_map</option>
              </select>
            </Field>
            <Field label="Override label">
              <input className="input" value={form.override_label} placeholder="e.g. See cart for price" onChange={(e) => setForm({ ...form, override_label: e.target.value })} />
            </Field>
            <Field label="Effective from">
              <input className="input" type="date" value={form.effective_at} onChange={(e) => setForm({ ...form, effective_at: e.target.value })} />
            </Field>
            <Field label="Expires on">
              <input className="input" type="date" value={form.expires_at} onChange={(e) => setForm({ ...form, expires_at: e.target.value })} />
            </Field>
            <label className="col-span-2 flex flex-col gap-1 text-sm">
              <span className="text-xs font-medium uppercase tracking-wide text-zinc-500">Notes</span>
              <textarea className="input" rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
            </label>
          </div>
          <div className="mt-4 flex justify-end">
            <button className="btn-primary" disabled={busy || !form.wc_product_id || !form.map_price} onClick={save}>
              {busy ? 'Saving…' : 'Save rule'}
            </button>
          </div>
        </div>

        <div className="card p-6">
          <h3 className="mb-4 text-lg font-semibold">Evaluate a product</h3>
          <p className="mb-3 text-xs text-zinc-500">Check whether a given product + sale-price combination would suppress the public price.</p>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <Field label="WooCommerce product ID">
              <input className="input" type="number" value={evalForm.wc_product_id} onChange={(e) => setEvalForm({ ...evalForm, wc_product_id: e.target.value })} />
            </Field>
            <Field label="Sale price (optional)">
              <input className="input" type="number" step="0.01" placeholder="defaults to product current price" value={evalForm.price} onChange={(e) => setEvalForm({ ...evalForm, price: e.target.value })} />
            </Field>
          </div>
          <div className="mt-4 flex justify-end">
            <button className="btn-secondary" disabled={!evalForm.wc_product_id} onClick={runEval}>Evaluate</button>
          </div>
          {evalResult && (
            <div className="mt-4 rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-sm dark:border-zinc-800 dark:bg-zinc-900/40">
              {!evalResult.has_rule && <p>No MAP rule applies. Public price will show normally.</p>}
              {evalResult.has_rule && (
                <ul className="space-y-1">
                  <li>MAP: <strong>{money(evalResult.map_price)}</strong></li>
                  <li>Active price: <strong>{money(evalResult.active_price)}</strong></li>
                  <li>Below MAP: <strong className={evalResult.below_map ? 'text-rose-600' : 'text-emerald-600'}>{evalResult.below_map ? 'YES — price will be suppressed publicly' : 'No — price displays normally'}</strong></li>
                  <li>Mode: <code>{evalResult.display_mode}</code></li>
                  {evalResult.override_label && <li>Label: &ldquo;{evalResult.override_label}&rdquo;</li>}
                  <li>Source: {evalResult.source}</li>
                </ul>
              )}
            </div>
          )}
        </div>
      </div>
    </>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label className="flex flex-col gap-1 text-sm">
      <span className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{label}</span>
      {children}
    </label>
  );
}
