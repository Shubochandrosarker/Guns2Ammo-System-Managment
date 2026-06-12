import { useEffect, useState } from 'react';
import { errorMessage, get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface PreviewRow {
  source_ref?: string;
  upc?: string;
  manufacturer?: string;
  name?: string;
  item_type?: string;
  firearm_type?: string | null;
  caliber?: string;
  magazine_capacity?: number;
  msrp?: number;
  dealer_cost?: number;
  quantity?: number;
}

interface Adapter { slug: string; label: string; signature: string[]; }

interface PreviewData { sample?: PreviewRow[]; adapter?: string; sample_count?: number; headers?: string[] }

interface ImportResult {
  stats?: {
    rows_seen?: number;
    rows_created?: number;
    rows_updated?: number;
    rows_skipped?: number;
    errors_count?: number;
  };
  needs_compliance?: unknown[];
  errors?: { ref?: string; message?: string }[];
}

export default function InventoryImport() {
  const [adapters, setAdapters] = useState<Adapter[]>([]);
  const [adapter, setAdapter] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<PreviewData | null>(null);
  const [previewing, setPreviewing] = useState(false);
  const [importing, setImporting] = useState(false);
  const [autoPublish, setAutoPublish] = useState(false);
  const [markup, setMarkup] = useState(0);
  const [result, setResult] = useState<ImportResult | null>(null);
  const [err, setErr] = useState('');
  const [loadErr, setLoadErr] = useState('');

  const loadAdapters = async () => {
    setLoadErr('');
    try {
      const r = await get<{ adapters: Adapter[] }>('/inventory/import/adapters');
      setAdapters(r.adapters || []);
    } catch (e) {
      setLoadErr(errorMessage(e, 'Could not load distributor adapters'));
    }
  };
  useEffect(() => { loadAdapters(); }, []); // eslint-disable-line

  async function readBase64(f: File): Promise<string> {
    return new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => {
        const result = reader.result as string;
        resolve(result.split(',')[1] || '');
      };
      reader.onerror = () => reject(reader.error);
      reader.readAsDataURL(f);
    });
  }

  const doPreview = async () => {
    if (!file) { setErr('Pick a CSV first.'); return; }
    setErr(''); setPreview(null); setResult(null); setPreviewing(true);
    try {
      const csv_b64 = await readBase64(file);
      const r = await post<PreviewData>('/inventory/import/preview', { csv_b64, adapter: adapter || undefined });
      setPreview(r);
      if (r.adapter && !adapter) setAdapter(r.adapter);
    } catch (e) {
      setErr(errorMessage(e, 'Preview failed'));
    } finally {
      setPreviewing(false);
    }
  };

  const doImport = async (dry_run: boolean) => {
    if (!file) { setErr('Pick a CSV first.'); return; }
    setErr(''); setResult(null); setImporting(true);
    try {
      const csv_b64 = await readBase64(file);
      const r = await post<ImportResult>('/inventory/import/commit', {
        csv_b64, adapter: adapter || undefined,
        auto_publish: autoPublish, markup_pct: markup, dry_run,
      });
      setResult(r);
    } catch (e) {
      setErr(errorMessage(e, 'Import failed'));
    } finally {
      setImporting(false);
    }
  };

  const cols: Column<PreviewRow>[] = [
    { key: 'source_ref', label: 'Ref', render: (r) => <span className="font-mono text-xs">{r.source_ref || '—'}</span> },
    { key: 'upc', label: 'UPC', render: (r) => <span className="font-mono text-xs">{r.upc || '—'}</span> },
    { key: 'name', label: 'Name', render: (r) => <span className="font-medium">{r.name || '—'}</span> },
    { key: 'manufacturer', label: 'Mfr' },
    { key: 'item_type', label: 'Type', render: (r) => <span className={r.item_type === 'firearm' ? 'badge-amber' : 'badge-zinc'}>{r.item_type}</span> },
    { key: 'firearm_type', label: 'Firearm', render: (r) => r.firearm_type ?? '—' },
    { key: 'caliber', label: 'Caliber' },
    { key: 'magazine_capacity', label: 'Mag', align: 'right' },
    { key: 'dealer_cost', label: 'Cost', align: 'right', render: (r) => r.dealer_cost ? `$${r.dealer_cost.toFixed(2)}` : '—' },
    { key: 'msrp', label: 'MSRP', align: 'right', render: (r) => r.msrp ? `$${r.msrp.toFixed(2)}` : '—' },
    { key: 'quantity', label: 'Qty', align: 'right' },
  ];

  return (
    <div>
      <PageHeader
        title="Inventory Import"
        subtitle="Upload a distributor CSV. Auto-detects Lipsey's, RSR, Davidson's, Sports South. Dedupes by source + UPC + manufacturer SKU."
      />

      {loadErr && (
        <div className="mb-4 flex items-center justify-between rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-900 dark:bg-rose-900/20 dark:text-rose-300">
          <span>{loadErr}</span>
          <button className="btn-sm" onClick={loadAdapters}>Retry</button>
        </div>
      )}
      {err && <div className="mb-4 rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-900 dark:bg-rose-900/20 dark:text-rose-300">{err}</div>}

      <div className="card mb-4 p-5 space-y-4">
        <div className="grid gap-4 md:grid-cols-2">
          <label className="text-sm">
            <div className="mb-1 font-medium">CSV file</div>
            <input type="file" accept=".csv,text/csv,text/tab-separated-values" onChange={(e) => setFile(e.target.files?.[0] || null)} className="input" />
          </label>
          <label className="text-sm">
            <div className="mb-1 font-medium">Adapter</div>
            <select className="input" value={adapter} onChange={(e) => setAdapter(e.target.value)}>
              <option value="">Auto-detect</option>
              {adapters.map((a) => <option key={a.slug} value={a.slug}>{a.label}</option>)}
            </select>
          </label>
        </div>
        <div className="grid gap-4 md:grid-cols-3">
          <label className="text-sm">
            <div className="mb-1 font-medium">Markup over cost (%)</div>
            <input type="number" className="input" value={markup} onChange={(e) => setMarkup(parseFloat(e.target.value) || 0)} />
            <div className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Used when source row has cost but no MSRP.</div>
          </label>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={autoPublish} onChange={(e) => setAutoPublish(e.target.checked)} />
            Auto-publish new products
          </label>
        </div>
        <div className="flex gap-2">
          <button className="btn-secondary" onClick={doPreview} disabled={previewing || !file}>{previewing ? 'Reading…' : 'Preview'}</button>
          <button className="btn-secondary" onClick={() => doImport(true)} disabled={importing || !file}>{importing ? 'Running…' : 'Dry-run'}</button>
          <button className="btn-primary" onClick={() => doImport(false)} disabled={importing || !file}>{importing ? 'Importing…' : 'Import'}</button>
        </div>
      </div>

      {preview && (
        <div className="card mb-4 p-5">
          <h3 className="mb-3 text-base font-semibold">Preview · {preview.adapter} · first {preview.sample_count} rows</h3>
          <DataTable<PreviewRow> rows={preview.sample || []} columns={cols} rowKey={(_, i) => i} empty="No rows mapped." />
        </div>
      )}

      {result && (
        <div className="card p-5">
          <h3 className="mb-3 text-base font-semibold">Result</h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 md:grid-cols-5">
            <Stat label="Seen" value={result.stats?.rows_seen ?? 0} />
            <Stat label="Created" value={result.stats?.rows_created ?? 0} good />
            <Stat label="Updated" value={result.stats?.rows_updated ?? 0} good />
            <Stat label="Skipped" value={result.stats?.rows_skipped ?? 0} />
            <Stat label="Errors" value={result.stats?.errors_count ?? 0} bad={(result.stats?.errors_count ?? 0) > 0} />
          </div>
          {(result.needs_compliance?.length ?? 0) > 0 && (
            <div className="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-700 dark:border-amber-900 dark:bg-amber-900/20 dark:text-amber-300">
              <strong>{result.needs_compliance?.length}</strong> firearm rows imported with missing compliance fields. Open Inventory and fill in the gaps before transferring.
            </div>
          )}
          {(result.errors?.length ?? 0) > 0 && (
            <details className="mt-4 text-sm">
              <summary className="cursor-pointer text-rose-600">Errors</summary>
              <ul className="mt-2 space-y-1">
                {(result.errors ?? []).slice(0, 20).map((e, i) => (
                  <li key={i} className="font-mono text-xs">{e.ref || '—'}: {e.message}</li>
                ))}
              </ul>
            </details>
          )}
        </div>
      )}
    </div>
  );
}

function Stat({ label, value, good, bad }: { label: string; value: number | string; good?: boolean; bad?: boolean }) {
  const cls = bad ? 'text-rose-600' : good ? 'text-emerald-600' : '';
  return (
    <div>
      <div className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{label}</div>
      <div className={`mt-1 text-2xl font-semibold ${cls}`}>{value}</div>
    </div>
  );
}
