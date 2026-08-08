import { useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';

interface Balance { points: number; credit_cents: number; tier?: string }
interface HistoryRow {
  id: number; tx_type: string; delta_points: number; delta_credit_cents: number;
  balance_points_after: number; balance_credit_cents_after: number;
  reason: string; created_at: string;
}

export default function Loyalty() {
  const [customerId, setCustomerId] = useState('');
  const [balance, setBalance] = useState<Balance | null>(null);
  const [history, setHistory] = useState<HistoryRow[]>([]);

  const load = async () => {
    if (!customerId) return;
    const [b, h] = await Promise.all([
      get<Balance>(`/loyalty/${customerId}`),
      get<{ items: HistoryRow[] }>(`/loyalty/${customerId}/history`),
    ]);
    setBalance(b);
    setHistory(h.items || []);
  };

  const adjust = async (delta_points: number, delta_credit_cents: number, reason: string) => {
    await post(`/loyalty/${customerId}/adjust`, { tx_type: 'manual_adjust', delta_points, delta_credit_cents, reason });
    await load();
  };

  return (
    <div>
      <PageHeader title="Loyalty & Store Credit" subtitle="Per-customer points + store-credit ledger. Append-only with running balances." />
      <div className="card p-4 mb-4 flex gap-2">
        <input className="input flex-1" placeholder="Customer ID" value={customerId} onChange={(e) => setCustomerId(e.target.value)} />
        <button className="btn-primary" onClick={load}>Load</button>
      </div>

      {balance && (
        <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <div><span className="text-zinc-500 text-sm">Points</span><div className="text-2xl font-semibold">{balance.points}</div></div>
          <div><span className="text-zinc-500 text-sm">Store credit</span><div className="text-2xl font-semibold">${(balance.credit_cents / 100).toFixed(2)}</div></div>
          <div><span className="text-zinc-500 text-sm">Tier</span><div className="text-lg font-semibold">{balance.tier ?? 'standard'}</div></div>
          <div className="col-span-3 flex flex-wrap gap-2">
            <button className="btn" onClick={() => adjust(100, 0, 'manual grant')}>+ 100 pts</button>
            <button className="btn" onClick={() => adjust(-100, 0, 'manual deduct')}>− 100 pts</button>
            <button className="btn" onClick={() => adjust(0, 2500, 'manual store credit grant')}>+ $25 credit</button>
            <button className="btn" onClick={() => adjust(0, -2500, 'manual store credit deduct')}>− $25 credit</button>
          </div>
        </div>
      )}

      {history.length > 0 && (
        <div className="card p-4">
          <h4 className="font-semibold mb-3">History</h4>
          <table className="w-full text-sm">
            <thead><tr><th className="text-left">When</th><th className="text-left">Type</th><th className="text-right">Δ pts</th><th className="text-right">Δ credit</th><th>Reason</th></tr></thead>
            <tbody>{history.map((r) => (
              <tr key={r.id}><td>{r.created_at}</td><td>{r.tx_type}</td><td className="text-right">{r.delta_points}</td><td className="text-right">${(r.delta_credit_cents/100).toFixed(2)}</td><td>{r.reason}</td></tr>
            ))}</tbody>
          </table>
        </div>
      )}
    </div>
  );
}
