import { useEffect, useMemo, useState } from 'react';
import { get, post, errorMessage } from '../api';
import PageHeader from '../components/PageHeader';

type Coord = {
  page: number;
  x: number;
  y: number;
  size?: number;
  font?: string;
  style?: string;
  align?: 'L' | 'C' | 'R';
  width?: number | null;
  height?: number | null;
};
type Fields = Record<string, Coord>;
type Status = { template_installed: boolean; template_path: string; fields_path: string; overlay_mode: boolean };

export default function Form4473Calibration() {
  const [status, setStatus] = useState<Status | null>(null);
  const [fields, setFields] = useState<Fields>({});
  const [source, setSource] = useState<string>('empty');
  const [search, setSearch] = useState('');
  const [pageFilter, setPageFilter] = useState<string>('');
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState('');

  const load = async () => {
    setMsg('');
    const [s, f] = await Promise.all([
      get<Status>('/atf/4473/template/status'),
      get<{ fields: Fields; source: string }>('/atf/4473/template/fields'),
    ]);
    setStatus(s);
    setFields(f.fields || {});
    setSource(f.source);
  };
  useEffect(() => { load(); }, []);

  const upload = async (file: File) => {
    setBusy(true); setMsg('');
    try {
      const pdf_b64 = await new Promise<string>((resolve, reject) => {
        const fr = new FileReader();
        fr.onload = () => resolve(((fr.result as string).split(',')[1]) || '');
        fr.onerror = () => reject(fr.error);
        fr.readAsDataURL(file);
      });
      await post('/atf/4473/template/upload', { pdf_b64 });
      setMsg('Template uploaded.');
      await load();
    } catch (e) {
      setMsg(errorMessage(e, 'Upload failed.'));
    } finally { setBusy(false); }
  };

  const save = async () => {
    setBusy(true); setMsg('');
    try {
      await post('/atf/4473/template/fields', { fields });
      setMsg('Coordinates saved.');
      await load();
    } catch (e) {
      setMsg(errorMessage(e, 'Save failed.'));
    } finally { setBusy(false); }
  };

  const updateCoord = (key: string, patch: Partial<Coord>) => {
    setFields((s) => ({ ...s, [key]: { ...s[key], ...patch } }));
  };

  const pages = useMemo(() => {
    const set = new Set<number>();
    Object.values(fields).forEach((c) => set.add(c.page || 1));
    return Array.from(set).sort((a, b) => a - b);
  }, [fields]);

  const visible = useMemo(() => {
    const s = search.toLowerCase();
    return Object.entries(fields)
      .filter(([k]) => (s ? k.toLowerCase().includes(s) : true))
      .filter(([, c]) => (pageFilter ? String(c.page || 1) === pageFilter : true))
      .sort(([a], [b]) => a.localeCompare(b));
  }, [fields, search, pageFilter]);

  return (
    <div>
      <PageHeader
        title="ATF Form 4473 — Template & Calibration"
        subtitle="Drop the official ATF Form 4473 (5300.9) PDF in once, then tune the field-overlay coordinates here. Generated 4473 PDFs use overlay mode automatically when both the template and a saved field map exist. The template is the ATF's own form — we overlay your captured Section A/B/C/D/E data onto it."
      />

      {msg && <div className="mb-4 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900 dark:bg-emerald-900/20 dark:text-emerald-300">{msg}</div>}

      <div className="card mb-4 p-5">
        <div className="grid gap-4 md:grid-cols-3">
          <Status label="Template installed" value={status?.template_installed ? 'Yes' : 'No'} good={status?.template_installed} />
          <Status label="Field map" value={source === 'live' ? 'Saved' : source === 'example' ? 'Example loaded' : 'Empty'} good={source === 'live'} />
          <Status label="Overlay mode" value={status?.overlay_mode ? 'Active' : 'Fallback (transcript)'} good={status?.overlay_mode} />
        </div>
        <div className="mt-4 flex flex-wrap items-center gap-3">
          <label className="btn-secondary cursor-pointer">
            Upload 5300.9 PDF
            <input
              type="file"
              accept="application/pdf"
              className="hidden"
              onChange={(e) => { const f = e.target.files?.[0]; if (f) upload(f); }}
            />
          </label>
          <span className="text-xs text-zinc-500">Stored at <code>{status?.template_path}</code></span>
        </div>
      </div>

      <div className="card mb-4 p-5">
        <div className="flex flex-wrap items-end gap-3">
          <label className="text-sm flex-1">
            <div className="mb-1 font-medium">Search field key</div>
            <input className="input" placeholder="e.g. transferee.last" value={search} onChange={(e) => setSearch(e.target.value)} />
          </label>
          <label className="text-sm">
            <div className="mb-1 font-medium">Page</div>
            <select className="input" value={pageFilter} onChange={(e) => setPageFilter(e.target.value)}>
              <option value="">All</option>
              {pages.map((p) => <option key={p} value={p}>{p}</option>)}
            </select>
          </label>
          <button className="btn-secondary" onClick={load}>Reload</button>
          <button className="btn-primary" onClick={save} disabled={busy}>{busy ? 'Saving…' : 'Save coordinates'}</button>
        </div>
        <div className="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
          Coordinates are in millimeters from the top-left of each page (FPDF default). Open the official PDF in a viewer with a ruler/grid (Preview on macOS, Adobe Reader&apos;s measure tool) to find the exact x/y of each field.
        </div>
      </div>

      <div className="card">
        <div className="w-full overflow-x-auto">
        <table className="w-full min-w-[720px] text-sm">
          <thead className="bg-zinc-50 dark:bg-zinc-900/60">
            <tr>
              <th className="table-head px-3 py-2">Field</th>
              <th className="table-head px-3 py-2 w-16">Page</th>
              <th className="table-head px-3 py-2 w-20">X (mm)</th>
              <th className="table-head px-3 py-2 w-20">Y (mm)</th>
              <th className="table-head px-3 py-2 w-20">Size</th>
              <th className="table-head px-3 py-2 w-24">Align</th>
              <th className="table-head px-3 py-2 w-20">W (mm)</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-100 dark:divide-zinc-800">
            {visible.length === 0 && <tr><td colSpan={7} className="px-3 py-4 text-center text-zinc-500">No fields. Upload the template, then reload — the example field map will populate.</td></tr>}
            {visible.map(([key, c]) => (
              <tr key={key}>
                <td className="px-3 py-2 font-mono text-xs">{key}</td>
                <td className="px-3 py-2"><input className="input" type="number" value={c.page || 1} onChange={(e) => updateCoord(key, { page: parseInt(e.target.value) || 1 })} /></td>
                <td className="px-3 py-2"><input className="input" type="number" step="0.5" value={c.x} onChange={(e) => updateCoord(key, { x: parseFloat(e.target.value) || 0 })} /></td>
                <td className="px-3 py-2"><input className="input" type="number" step="0.5" value={c.y} onChange={(e) => updateCoord(key, { y: parseFloat(e.target.value) || 0 })} /></td>
                <td className="px-3 py-2"><input className="input" type="number" step="0.5" value={c.size ?? 10} onChange={(e) => updateCoord(key, { size: parseFloat(e.target.value) || 10 })} /></td>
                <td className="px-3 py-2">
                  <select className="input" value={c.align || 'L'} onChange={(e) => updateCoord(key, { align: e.target.value as Coord['align'] })}>
                    <option value="L">Left</option><option value="C">Center</option><option value="R">Right</option>
                  </select>
                </td>
                <td className="px-3 py-2"><input className="input" type="number" step="0.5" value={c.width ?? ''} onChange={(e) => updateCoord(key, { width: e.target.value ? parseFloat(e.target.value) : null })} /></td>
              </tr>
            ))}
          </tbody>
        </table>
        </div>
      </div>
    </div>
  );
}

function Status({ label, value, good }: { label: string; value: string; good?: boolean }) {
  return (
    <div>
      <div className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{label}</div>
      <div className={`mt-1 text-lg font-semibold ${good ? 'text-emerald-600' : 'text-amber-600'}`}>{value}</div>
    </div>
  );
}
