import { useEffect, useState } from 'react';
import { get } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface Session {
  id: number;
  register_code: string;
  location_id: number;
  opened_by: number;
  opening_cash: string;
  status: string;
  opened_at: string;
}

export default function Registers() {
  const [rows, setRows] = useState<Session[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    (async () => {
      try {
        // RegisterController::open_list returns { items: [...] }. Reading
        // `sessions` here produced an empty list on every load, silently.
        const r = await get<{ items: Session[] }>('/register/open-list');
        setRows(r.items || []);
      } finally { setLoading(false); }
    })();
  }, []);

  const cols: Column<Session>[] = [
    { key: 'register_code', label: 'Register', render: (r) => <span className="font-mono">{r.register_code}</span> },
    { key: 'location_id', label: 'Location' },
    { key: 'opened_by', label: 'Opened by' },
    { key: 'opening_cash', label: 'Opening cash', align: 'right', render: (r) => `$${parseFloat(r.opening_cash).toFixed(2)}` },
    { key: 'status', label: 'Status', render: (r) => <span className={r.status === 'open' ? 'badge-green' : 'badge-zinc'}>{r.status}</span> },
    { key: 'opened_at', label: 'Opened' },
  ];

  return (
    <div>
      <PageHeader title="Registers" subtitle="Open till sessions, cash movements, X/Z reports." />
      <DataTable<Session> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No open registers." />
    </div>
  );
}
