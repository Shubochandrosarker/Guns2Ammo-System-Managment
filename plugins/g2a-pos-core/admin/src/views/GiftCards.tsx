import { useEffect, useState } from 'react';
import { get, post, errorMessage } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';
import { useAction, ActionFeedback } from '../components/useAction';

interface Card {
  id: number;
  code: string;
  balance_cents: number;
  initial_balance_cents: number;
  currency: string;
  status: string;
  issued_at: string;
  expires_at: string | null;
  last_used_at: string | null;
}

export default function GiftCards() {
  const { error, notice, setNotice, setError } = useAction();
  const [rows, setRows] = useState<Card[]>([]);
  const [loading, setLoading] = useState(true);
  const [amount, setAmount] = useState('25');
  const [lookup, setLookup] = useState('');
  const [redeemAmount, setRedeemAmount] = useState('');

  const refresh = async () => {
    setLoading(true);
    try {
      const r = await get<{ items: Card[] }>('/gift-cards');
      setRows(r.items || []);
    } finally { setLoading(false); }
  };
  useEffect(() => { queueMicrotask(refresh); }, []);

  const issue = async () => {
    const a = parseFloat(amount);
    if (!a) return;
    const res = await post<{ code?: string }>('/gift-cards/issue', { amount: a });
    // The cleartext code is shown exactly once — it is stored hashed — so it
    // stays on screen as a notice rather than vanishing with a dismissed alert.
    if (res?.code) setNotice(`Issued code: ${res.code} — record it now, it cannot be shown again.`);
    await refresh();
  };

  const check = async () => {
    if (!lookup) return;
    try {
      const res = await get<{ balance_cents: number; status: string }>('/gift-cards/balance', { code: lookup });
      setNotice(`Balance: $${(res.balance_cents / 100).toFixed(2)} (${res.status})`);
    } catch (e) { setError(errorMessage(e, 'Gift card not found')); }
  };

  const redeem = async () => {
    if (!lookup || !redeemAmount) return;
    const cents = Math.round(parseFloat(redeemAmount) * 100);
    const res = await post<{ applied_cents: number; balance_cents: number }>('/gift-cards/redeem', { code: lookup, amount_cents: cents });
    setNotice(`Applied $${(res.applied_cents/100).toFixed(2)}. New balance: $${(res.balance_cents/100).toFixed(2)}.`);
    await refresh();
  };

  const cols: Column<Card>[] = [
    { key: 'code', label: 'Code', render: (r) => <span className="font-mono">{r.code}</span> },
    { key: 'balance_cents', label: 'Balance', align: 'right', render: (r) => `$${(r.balance_cents/100).toFixed(2)}` },
    { key: 'initial_balance_cents', label: 'Initial', align: 'right', render: (r) => `$${(r.initial_balance_cents/100).toFixed(2)}` },
    { key: 'status', label: 'Status' },
    { key: 'issued_at', label: 'Issued' },
    { key: 'expires_at', label: 'Expires' },
    { key: 'last_used_at', label: 'Last used' },
  ];

  return (
    <div>
      <PageHeader title="Gift Cards" subtitle="Issue, look up balance, and redeem. Codes stored as SHA-256 hash; only the issuance receipt shows cleartext." />

      <ActionFeedback error={error} notice={notice} />
      <div className="grid gap-4 md:grid-cols-2 mb-4">
        <div className="card p-4 flex items-end gap-2">
          <div className="flex-1">
            <label className="block text-xs mb-1">Issue new card</label>
            <input className="input w-full" type="number" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} />
          </div>
          <button className="btn-primary" onClick={issue}>Issue</button>
        </div>
        <div className="card p-4 grid grid-cols-1 sm:grid-cols-2 gap-2">
          <input className="input col-span-2" placeholder="Code" value={lookup} onChange={(e) => setLookup(e.target.value.toUpperCase())} />
          <button className="btn" onClick={check}>Check balance</button>
          <div className="flex gap-2">
            <input className="input flex-1" placeholder="Redeem $" value={redeemAmount} onChange={(e) => setRedeemAmount(e.target.value)} />
            <button className="btn-primary" onClick={redeem}>Redeem</button>
          </div>
        </div>
      </div>
      <DataTable<Card> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.id} empty="No gift cards issued." />
    </div>
  );
}
