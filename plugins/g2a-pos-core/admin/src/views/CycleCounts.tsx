import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';
import { useDialogs } from '../components/Dialogs';
import { useAction, ActionFeedback } from '../components/useAction';

interface Count {
  id: number;
  count_code: string;
  scope_type: string;
  scope_value: string;
  count_type: string;
  status: string;
  items_total: number;
  items_counted: number;
  variance_units: string;
  variance_value: string;
  opened_at: string;
}

interface CountDetail extends Count {
  items: {
    id: number; product_id: number; sku: string; serial_number: string | null;
    expected_qty: string; counted_qty: string | null;
    variance_qty: string; variance_value: string; status: string;
  }[];
}

export default function CycleCounts() {
  const dialogs = useDialogs();
  const { run, error, notice } = useAction();
  const [rows, setRows] = useState<Count[]>([]);
  const [loading, setLoading] = useState(true);
  const [open, setOpen] = useState<CountDetail | null>(null);

  const refresh = async () => {
    setLoading(true);
    try {
      const r = await get<{ items: Count[] }>('/cycle-counts');
      setRows(r.items || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { queueMicrotask(refresh); }, []);

  const create = async () => {
    const values = await dialogs.prompt({
      title: 'Start a cycle count',
      confirmLabel: 'Start count',
      fields: [
        { name: 'scope', label: 'Scope value', required: true, placeholder: 'e.g. category name, location' },
      ],
    });
    if (!values) return;
    const scope = String(values.scope);
    const r = await post<{ count: CountDetail }>('/cycle-counts', {
      scope_type: 'category',
      scope_value: scope,
      items: [],
    });
    setOpen(r.count);
    await refresh();
  };

  const openOne = async (id: number) => {
    const r = await get<{ count: CountDetail }>(`/cycle-counts/${id}`);
    setOpen(r.count);
  };

  const record = async (item_id: number) => {
    if (!open) return;
    // A blind count of zero is meaningful, so this must accept 0 while still
    // rejecting a cancel — which the old parseFloat(x || '0') could not tell
    // apart, recording 0 either way.
    const values = await dialogs.prompt({
      title: 'Record counted quantity',
      confirmLabel: 'Record',
      fields: [
        { name: 'counted_qty', label: 'Counted quantity', type: 'number', required: true, min: 0, step: 1 },
      ],
    });
    if (!values) return;
    await run(async () => {
      await post(`/cycle-counts/${open.id}/record`, { item_id, counted_qty: values.counted_qty });
      await openOne(open.id);
      await refresh();
    }, 'Count recorded.');
  };

  const close = async () => {
    if (!open) return;
    await post(`/cycle-counts/${open.id}/close`, {});
    await openOne(open.id);
    await refresh();
  };

  const cols: Column<Count>[] = [
    { key: 'count_code', label: 'Code', render: (r) => <button className="link" onClick={() => openOne(r.id)}>{r.count_code}</button> },
    { key: 'scope_value', label: 'Scope' },
    { key: 'count_type', label: 'Type' },
    { key: 'status', label: 'Status', render: (r) => <span className="badge-zinc">{r.status}</span> },
    { key: 'items', label: 'Items', align: 'right', render: (r) => `${r.items_counted} / ${r.items_total}` },
    { key: 'variance_value', label: 'Variance $', align: 'right', render: (r) => `$${parseFloat(r.variance_value || '0').toFixed(2)}` },
    { key: 'opened_at', label: 'Opened' },
  ];

  return (
    <div>
      <PageHeader title="Cycle Counts" subtitle="Blind count workflow with variance reporting. Out-of-tolerance lines auto-flagged."
        actions={<button className="btn-primary" onClick={create}>+ Open new count</button>} />

      <ActionFeedback error={error} notice={notice} />
      <DataTable<Count> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No counts." />

      {open && (
        <div className="card p-4 mt-4">
          <div className="flex justify-between items-start mb-3">
            <div>
              <h3 className="font-semibold">{open.count_code} — {open.scope_value}</h3>
              <p className="text-xs text-zinc-500">Status: {open.status} · Total variance: ${parseFloat(open.variance_value || '0').toFixed(2)}</p>
            </div>
            <div className="flex gap-2">
              {open.status === 'open' && <button className="btn-primary" onClick={close}>Close count</button>}
              <button className="btn" onClick={() => setOpen(null)}>Close panel</button>
            </div>
          </div>
          <table className="w-full text-sm">
            <thead><tr><th>Product</th><th>SKU</th><th>Serial</th><th className="text-right">Expected</th><th className="text-right">Counted</th><th className="text-right">Var</th><th></th></tr></thead>
            <tbody>{open.items.map((it) => (
              <tr key={it.id}>
                <td>{it.product_id}</td>
                <td>{it.sku}</td>
                <td>{it.serial_number ?? ''}</td>
                <td className="text-right">{it.expected_qty}</td>
                <td className="text-right">{it.counted_qty ?? '—'}</td>
                <td className="text-right">{it.variance_qty}</td>
                <td>{open.status === 'open' && <button className="btn-sm" onClick={() => record(it.id)}>Record</button>}</td>
              </tr>
            ))}</tbody>
          </table>
          {open.items.length === 0 && <div className="text-zinc-500 text-sm">Add items via the API to seed this count.</div>}
        </div>
      )}
    </div>
  );
}
