import { useEffect, useState } from 'react';
import { get, post, errorMessage } from '../api';
import PageHeader from '../components/PageHeader';
import { useDialogs } from '../components/Dialogs';

interface TenderLine {
  id: number;
  tender_method: string;
  amount: string;
  change_due: string;
  reference: string | null;
  status: string;
  refunded_amount: string;
  captured_at: string;
}

interface Balance {
  ok: boolean;
  order_id: number;
  grand_total: number;
  captured: number;
  owed: number;
  fully_paid: boolean;
  tenders: TenderLine[];
}

interface TenderForm {
  tender_method: string;
  amount?: string;
  code?: string;
  customer_id?: number;
  external_ref?: string;
  last4?: string;
  reference?: string;
  [key: string]: string | number | undefined;
}

const METHODS = [
  { key: 'cash',            label: 'Cash',            needs: [] },
  { key: 'card',            label: 'Card (Stripe)',   needs: ['external_ref', 'last4'] },
  { key: 'gift_card',       label: 'Gift Card',       needs: ['code'] },
  { key: 'store_credit',    label: 'Store Credit',    needs: ['customer_id'] },
  { key: 'tradein_credit',  label: 'Trade-In Credit', needs: ['customer_id'] },
  { key: 'check',           label: 'Check',           needs: ['reference'] },
  { key: 'house_charge',    label: 'House Charge',    needs: ['customer_id'] },
];

export default function SplitTender() {
  const [orderId, setOrderId] = useState<string>('');
  const [balance, setBalance] = useState<Balance | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [form, setForm] = useState<TenderForm>({ tender_method: 'cash' });
  const [busy, setBusy] = useState(false);
  const dialogs = useDialogs();

  const load = async (id?: number) => {
    const useId = id ?? parseInt(orderId, 10);
    if (!useId || isNaN(useId)) { setBalance(null); return; }
    setError(null);
    try {
      const b = await get<Balance>(`/orders/${useId}/tenders`);
      setBalance(b);
      // Pre-fill amount with what's owed.
      setForm((f) => ({ ...f, amount: b.owed.toFixed(2) }));
    } catch (e) {
      setBalance(null);
      setError(errorMessage(e, 'Failed to load order'));
    }
  };

  useEffect(() => {
    // Allow ?order=42 in the URL hash to deep-link.
    const m = window.location.hash.match(/order=(\d+)/);
    if (m) { const id = m[1]; queueMicrotask(() => { setOrderId(id); void load(parseInt(id, 10)); }); }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const submit = async () => {
    if (!balance) return;
    const payload: Record<string, unknown> = {
      tender_method: form.tender_method,
      amount: parseFloat(form.amount ?? ''),
    };
    const def = METHODS.find((m) => m.key === form.tender_method);
    (def?.needs || []).forEach((k) => { if (form[k] !== undefined && form[k] !== '') payload[k] = form[k]; });
    setBusy(true);
    setError(null);
    try {
      const r = await post<{ change_due: number; balance?: { owed?: number } }>(`/orders/${balance.order_id}/tenders`, payload);
      // Kept as an acknowledge-me dialog, not a passive notice: the cashier
      // has to see this before handing money back. Now in-app rather than a
      // browser alert(), so it matches the rest of the UI and is styleable.
      if (r.change_due > 0) {
        await dialogs.confirm({
          title: `Change due: $${r.change_due.toFixed(2)}`,
          body: 'Hand this back to the customer.',
          confirmLabel: 'Done',
          cancelLabel: 'Close',
        });
      }
      await load(balance.order_id);
      setForm({ tender_method: 'cash', amount: r.balance?.owed?.toFixed(2) ?? '' });
    } catch (e) {
      setError(errorMessage(e, 'Tender failed'));
    } finally { setBusy(false); }
  };

  // Voids and refunds move money, so both now confirm in-app with a typed,
  // validated form and surface failures. Previously each was a window.prompt()
  // whose result went straight to the API, and a rejected request did nothing
  // visible at all.
  const voidLine = async (line: TenderLine) => {
    const values = await dialogs.prompt({
      title: 'Void this tender line?',
      body: `${line.tender_method} · $${Number(line.amount).toFixed(2)}. This cannot be undone.`,
      danger: true,
      confirmLabel: 'Void tender',
      fields: [
        { name: 'reason', label: 'Reason', type: 'textarea', required: true, placeholder: 'Why is this being voided?' },
      ],
    });
    if (!values) return;

    setBusy(true);
    setError('');
    try {
      await post(`/tenders/${line.id}/void`, { reason: values.reason });
      await load(balance?.order_id);
    } catch (e) {
      setError(errorMessage(e, 'Void failed'));
    } finally {
      setBusy(false);
    }
  };

  const refundLine = async (line: TenderLine) => {
    // Cap at what is actually still refundable so an over-refund is rejected
    // before it reaches the API rather than after.
    const alreadyRefunded = Number(line.refunded_amount) || 0;
    const refundable = Math.max(0, (Number(line.amount) || 0) - alreadyRefunded);

    if (refundable <= 0) {
      setError('This tender line has already been fully refunded.');
      return;
    }

    const values = await dialogs.prompt({
      title: 'Refund tender line',
      body: `${line.tender_method} · $${refundable.toFixed(2)} available to refund.`,
      confirmLabel: 'Refund',
      fields: [
        {
          name: 'amount',
          label: 'Refund amount',
          type: 'number',
          required: true,
          min: 0.01,
          max: refundable,
          step: 0.01,
          value: refundable.toFixed(2),
          help: `Maximum $${refundable.toFixed(2)}.`,
        },
      ],
    });
    if (!values) return;

    setBusy(true);
    setError('');
    try {
      await post(`/tenders/${line.id}/refund`, { amount: values.amount });
      await load(balance?.order_id);
    } catch (e) {
      setError(errorMessage(e, 'Refund failed'));
    } finally {
      setBusy(false);
    }
  };

  const def = METHODS.find((m) => m.key === form.tender_method);

  return (
    <div>
      <PageHeader title="Split-Tender Checkout"
        subtitle="One order, many payment methods — cash, card, gift card, store credit, trade-in credit, check, house charge." />

      <div className="card p-4 mb-4 flex gap-2 items-end">
        <div className="flex-1">
          <label className="block text-xs mb-1">Order ID</label>
          <input className="input w-full" placeholder="e.g. 42" value={orderId}
            onChange={(e) => setOrderId(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && load()} />
        </div>
        <button className="btn-primary" onClick={() => load()}>Load</button>
      </div>

      {error && <div className="card p-3 mb-4 border-rose-300 text-rose-700">{error}</div>}

      {balance && (
        <div className="grid gap-4 md:grid-cols-3 mb-4">
          <div className="card p-4"><div className="text-xs text-zinc-500">Grand total</div>
            <div className="text-2xl font-semibold">${balance.grand_total.toFixed(2)}</div></div>
          <div className="card p-4"><div className="text-xs text-zinc-500">Captured</div>
            <div className="text-2xl font-semibold">${balance.captured.toFixed(2)}</div></div>
          <div className={`card p-4 ${balance.fully_paid ? 'border-emerald-400' : 'border-amber-400'}`}>
            <div className="text-xs text-zinc-500">Still owed</div>
            <div className={`text-2xl font-semibold ${balance.fully_paid ? 'text-emerald-600' : 'text-amber-600'}`}>
              ${balance.owed.toFixed(2)}{balance.fully_paid && ' ✓'}
            </div>
          </div>
        </div>
      )}

      {balance && !balance.fully_paid && (
        <div className="card p-4 mb-4">
          <h3 className="font-semibold mb-3">Add tender</h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 md:grid-cols-4">
            <select className="input" value={form.tender_method}
              onChange={(e) => setForm({ ...form, tender_method: e.target.value })}>
              {METHODS.map((m) => <option key={m.key} value={m.key}>{m.label}</option>)}
            </select>
            <input className="input" type="number" step="0.01" placeholder="Amount $"
              value={form.amount ?? ''} onChange={(e) => setForm({ ...form, amount: e.target.value })} />
            {def?.needs.includes('code') && (
              <input className="input md:col-span-2" placeholder="Gift card code (e.g. ABCD-EFGH-IJKL-MNOP)"
                value={form.code ?? ''} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} />
            )}
            {def?.needs.includes('customer_id') && (
              <input className="input md:col-span-2" type="number" placeholder="Customer ID"
                value={form.customer_id ?? ''} onChange={(e) => setForm({ ...form, customer_id: parseInt(e.target.value) })} />
            )}
            {def?.needs.includes('external_ref') && (
              <input className="input" placeholder="Stripe payment_intent_id"
                value={form.external_ref ?? ''} onChange={(e) => setForm({ ...form, external_ref: e.target.value })} />
            )}
            {def?.needs.includes('last4') && (
              <input className="input" placeholder="Card last 4 (optional)"
                value={form.last4 ?? ''} onChange={(e) => setForm({ ...form, last4: e.target.value })} />
            )}
            {def?.needs.includes('reference') && (
              <input className="input md:col-span-2" placeholder="Check number / reference"
                value={form.reference ?? ''} onChange={(e) => setForm({ ...form, reference: e.target.value })} />
            )}
            <button className="btn-primary md:col-span-4" onClick={submit} disabled={busy || !form.amount}>
              {busy ? 'Capturing…' : 'Capture tender'}
            </button>
          </div>
          <p className="text-xs text-zinc-500 mt-2">
            Cash is the only method that may exceed the balance owed — overage is returned as change.
          </p>
        </div>
      )}

      {balance && balance.tenders.length > 0 && (
        <div className="card p-4">
          <h3 className="font-semibold mb-3">Tender lines ({balance.tenders.length})</h3>
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left">
                <th>Captured</th><th>Method</th><th className="text-right">Amount</th>
                <th className="text-right">Change</th><th>Reference</th>
                <th className="text-right">Refunded</th><th>Status</th><th></th>
              </tr>
            </thead>
            <tbody>
              {balance.tenders.map((t) => (
                <tr key={t.id} className="border-t border-zinc-100 dark:border-zinc-800">
                  <td className="py-2">{t.captured_at}</td>
                  <td><span className="badge-zinc">{t.tender_method}</span></td>
                  <td className="text-right">${parseFloat(t.amount).toFixed(2)}</td>
                  <td className="text-right">${parseFloat(t.change_due).toFixed(2)}</td>
                  <td className="text-xs">{t.reference ?? '—'}</td>
                  <td className="text-right">${parseFloat(t.refunded_amount).toFixed(2)}</td>
                  <td><span className={t.status === 'captured' ? 'badge-green'
                      : t.status === 'voided' ? 'badge-red' : 'badge-amber'}>{t.status}</span></td>
                  <td className="text-right">
                    {t.status === 'captured' && (
                      <div className="flex gap-1 justify-end">
                        <button className="btn-sm" disabled={busy} onClick={() => refundLine(t)}>Refund</button>
                        <button className="btn-sm" disabled={busy} onClick={() => voidLine(t)}>Void</button>
                      </div>
                    )}
                    {/* Was written as `(captured || partially_refunded) && !captured`,
                        which is just `partially_refunded` with extra steps. */}
                    {t.status === 'partially_refunded' && (
                      <button className="btn-sm" disabled={busy} onClick={() => refundLine(t)}>Refund more</button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
