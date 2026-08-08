import { useEffect, useState } from 'react';
import { errorMessage, get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';
import { useDialogs } from '../components/Dialogs';

interface NicsRow {
  id: number;
  form_4473_id: number;
  ntn: string | null;
  response_status: 'pending' | 'proceed' | 'delayed' | 'denied' | 'cancelled';
  initiated_at: string;
  default_proceed_eligible_at: string | null;
  transferred: number;
  form_serial: string | null;
  transferee_last: string | null;
  transferee_first: string | null;
}

const STATUS_BADGE: Record<string, string> = {
  pending: 'badge-amber',
  delayed: 'badge-amber',
  proceed: 'badge-green',
  denied: 'badge-red',
  cancelled: 'badge-zinc',
};

export default function NicsQueue() {
  const dialogs = useDialogs();
  const [rows, setRows] = useState<NicsRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState('');
  const [error, setError] = useState('');
  const [recordingId, setRecordingId] = useState<number | null>(null);

  const refresh = async () => {
    setLoading(true);
    setError('');
    try {
      const r = await get<{ items: NicsRow[] }>('/atf/nics', status ? { status } : undefined);
      setRows(r.items || []);
    } catch (e) {
      setError(errorMessage(e, 'Could not load the NICS queue.'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    queueMicrotask(refresh);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status]);

  const recordResponse = async (id: number, response_status: 'proceed' | 'denied' | 'delayed') => {
    let ntn: string | undefined;
    if (response_status !== 'denied') {
      // Cancelling now aborts the whole action. Previously a dismissed prompt
      // was indistinguishable from "no NTN" and the response was recorded
      // anyway, leaving the check with no transaction number against it.
      const values = await dialogs.prompt({
        title: `Record NICS ${response_status}`,
        body: 'The NTN is optional — leave it blank to skip.',
        confirmLabel: 'Record response',
        fields: [
          { name: 'ntn', label: 'NTN (transaction number)', placeholder: 'Optional' },
        ],
      });
      if (!values) return;
      ntn = String(values.ntn).trim() || undefined;
    }
    setRecordingId(id);
    try {
      await post(`/atf/nics/${id}/response`, { response_status, ntn: ntn || undefined });
      await refresh();
    } catch (e) {
      setError(errorMessage(e, 'Could not record the NICS response.'));
    } finally {
      setRecordingId(null);
    }
  };

  const cols: Column<NicsRow>[] = [
    { key: 'id', label: '#', width: '60px' },
    {
      key: 'transferee_last',
      label: 'Customer',
      render: (r) => {
        const name = [r.transferee_first, r.transferee_last].filter(Boolean).join(' ');
        return name || <span className="text-zinc-400">Form #{r.form_4473_id}</span>;
      },
    },
    { key: 'form_serial', label: 'Form serial', render: (r) => r.form_serial || '—' },
    { key: 'ntn', label: 'NTN', render: (r) => r.ntn || '—' },
    {
      key: 'response_status',
      label: 'Status',
      render: (r) => <span className={STATUS_BADGE[r.response_status] || 'badge-zinc'}>{r.response_status}</span>,
    },
    { key: 'initiated_at', label: 'Initiated' },
    {
      key: 'default_proceed_eligible_at',
      label: 'Default-proceed eligible',
      render: (r) => r.default_proceed_eligible_at || '—',
    },
    {
      key: 'actions',
      label: '',
      sortable: false,
      render: (r) =>
        (r.response_status === 'pending' || r.response_status === 'delayed') && (
          <div className="flex justify-end gap-1">
            <button className="btn-sm" disabled={recordingId === r.id} onClick={() => recordResponse(r.id, 'proceed')}>Proceed</button>
            <button className="btn-sm" disabled={recordingId === r.id} onClick={() => recordResponse(r.id, 'delayed')}>Delay</button>
            <button className="btn-sm" disabled={recordingId === r.id} onClick={() => recordResponse(r.id, 'denied')}>Deny</button>
          </div>
        ),
    },
  ];

  return (
    <div>
      <PageHeader
        title="NICS Queue"
        subtitle="National Instant Criminal Background Check System (FBI) — initiated as part of the 4473 transaction. State POC states route through their own portal. Status flow: pending → proceed / delayed / denied. Federal default-proceed at 3 business days per 18 USC § 922(t)(1)(B). When a qualifying state CCW is presented, NICS is bypassed under 18 USC § 922(t)(3) — the 4473 form is still required. See Compliance → CCW Bypass." />

      <div className="card p-4 mb-4 flex flex-wrap items-center gap-3">
        <label className="text-xs text-zinc-500">Filter by status</label>
        <select className="input w-auto" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">All</option>
          <option value="pending">Pending</option>
          <option value="delayed">Delayed</option>
          <option value="proceed">Proceed</option>
          <option value="denied">Denied</option>
          <option value="cancelled">Cancelled</option>
        </select>
        {error && <span className="text-sm text-red-600">{error}</span>}
      </div>

      <DataTable<NicsRow> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No NICS checks yet." caption="Records" />
    </div>
  );
}
