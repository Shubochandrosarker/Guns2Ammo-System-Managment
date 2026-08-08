import { useEffect, useState } from 'react';
import { get, post, errorMessage } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Product {
  id: number;
  name?: string;
  sku?: string;
  price?: string | number;
  item_type?: string;
  stock?: number;
}

interface WooScanStats {
  scanned?: number;
  linked?: number;
  created_refs?: number;
  updated_refs?: number;
  barcodes?: number;
  skipped?: number;
  error?: string;
}

export default function Inventory() {
  const [q, setQ] = useState('');
  const [rows, setRows] = useState<Product[]>([]);
  const [loading, setLoading] = useState(false);
  const [err, setErr] = useState('');
  const [scanning, setScanning] = useState(false);
  const [scanMsg, setScanMsg] = useState('');

  const scanWoo = async () => {
    setScanning(true);
    setScanMsg('Scanning WooCommerce catalog… this can take a minute on large stores.');
    try {
      const r = await post<{ ok: boolean; stats: WooScanStats }>('/integrations/woo/scan');
      const s = r.stats || {};
      setScanMsg(
        r.ok
          ? `Scan complete: ${s.scanned ?? 0} products scanned · ${s.created_refs ?? 0} newly linked · ${s.updated_refs ?? 0} refreshed · ${s.barcodes ?? 0} barcodes added · ${s.skipped ?? 0} skipped.`
          : `Scan failed: ${s.error || 'unknown error'}`
      );
    } catch (e) {
      setScanMsg(`Scan failed: ${errorMessage(e)}`);
    } finally {
      setScanning(false);
    }
  };

  const search = async () => {
    if (!q.trim()) { setRows([]); return; }
    setLoading(true);
    setErr('');
    try {
      // ProductController::search returns { items: [...] }. Reading `results`
      // here meant inventory search always came back empty.
      const r = await get<{ items: Product[] }>('/products/search', { q });
      setRows(r.items || []);
    } catch (e) { setErr(errorMessage(e)); }
    finally { setLoading(false); }
  };

  useEffect(() => {
    if (q.trim().length >= 2) {
      const t = setTimeout(search, 250);
      return () => clearTimeout(t);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q]);

  const cols: Column<Product>[] = [
    { key: 'id', label: 'ID', width: '70px', render: (r) => <span className="font-mono text-zinc-500">{r.id}</span> },
    { key: 'name', label: 'Name', render: (r) => <span className="font-medium">{r.name || '—'}</span> },
    { key: 'sku', label: 'SKU', render: (r) => <span className="font-mono text-xs">{r.sku || '—'}</span> },
    { key: 'item_type', label: 'Type', render: (r) => <TypeBadge type={r.item_type} /> },
    { key: 'price', label: 'Price', align: 'right', render: (r) => r.price ? `$${parseFloat(String(r.price)).toFixed(2)}` : '—' },
    { key: 'stock', label: 'Stock', align: 'right' },
  ];

  return (
    <div>
      <PageHeader
        title="Inventory"
        subtitle="Products, ammo, accessories. Firearms appear with serial-tracked rows."
        actions={
          <>
            <button className="btn-secondary" onClick={scanWoo} disabled={scanning}>
              {scanning ? 'Scanning…' : '🔄 Scan WooCommerce Products'}
            </button>
            <a className="btn-secondary" href="#inventory_import">Import CSV</a>
            <button className="btn-primary">New product</button>
          </>
        }
      />
      {scanMsg && (
        <div className="card mb-4 border-l-4 border-brand p-4 text-sm">{scanMsg}</div>
      )}
      <div className="card mb-4 p-4">
        <input
          className="input"
          placeholder="Search by name, SKU, or UPC…"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          autoFocus
        />
      </div>
      {err && <div className="mb-4 rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-900 dark:bg-rose-900/20 dark:text-rose-300">{err}</div>}
      <DataTable<Product>
        rows={rows}
        columns={cols}
        loading={loading}
        rowKey={(r) => r.id}
        empty="Type 2+ chars to search the catalog."
      />
    </div>
  );
}

function TypeBadge({ type }: { type?: string }) {
  if (!type) return <span className="text-zinc-400">—</span>;
  const cls = type === 'firearm' ? 'badge-amber' : 'badge-zinc';
  return <span className={cls}>{type}</span>;
}
