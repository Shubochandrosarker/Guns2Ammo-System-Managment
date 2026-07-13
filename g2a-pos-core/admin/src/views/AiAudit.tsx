import { useEffect, useState } from 'react';
import { get } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Row {
  id: number;
  conversation_id: number | null;
  event_type: string;
  actor_id: number | null;
  tool_name: string | null;
  payload_excerpt: string;
  created_at: string;
}

export default function AiAudit() {
  const [rows, setRows] = useState<Row[]>([]);
  const [loading, setLoading] = useState(true);
  const [verification, setVerification] = useState<{ ok: boolean; rows: number; corrupted_ids: number[] } | null>(null);

  const refresh = async () => {
    setLoading(true);
    try {
      const r = await get<{ items: Row[] }>('/ai/audit');
      setRows(r.items || []);
    } finally { setLoading(false); }
  };
  const verify = async () => { setVerification(await get('/ai/audit/verify')); };
  useEffect(() => { queueMicrotask(() => { refresh(); verify(); }); }, []);

  const cols: Column<Row>[] = [
    { key: 'created_at', label: 'When' },
    { key: 'event_type', label: 'Event', render: (r) => <span className="badge-zinc">{r.event_type}</span> },
    { key: 'tool_name', label: 'Tool' },
    { key: 'actor_id', label: 'Actor' },
    { key: 'conversation_id', label: 'Conv' },
    { key: 'payload_excerpt', label: 'Payload', render: (r) => <code className="text-xs">{r.payload_excerpt}</code> },
  ];

  return (
    <div>
      <PageHeader title="AI Audit Log" subtitle="Tamper-evident hash chain of every agent prompt, tool call, action approval and denial."
        actions={<button className="btn" onClick={verify}>Verify chain</button>} />
      {verification && (
        <div className={`card p-3 mb-4 ${verification.ok ? 'border-emerald-400' : 'border-rose-400'}`}>
          <strong>{verification.ok ? '✓ Chain intact' : '✗ Chain corrupted'}</strong> · {verification.rows} rows checked
          {verification.corrupted_ids.length > 0 && <span className="ml-2 text-rose-600">Bad IDs: {verification.corrupted_ids.join(', ')}</span>}
        </div>
      )}
      <DataTable<Row> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No agent activity yet." />
    </div>
  );
}
