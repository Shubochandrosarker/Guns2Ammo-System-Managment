import { useEffect, useState } from 'react';
import { api, get, post, errorMessage } from '../api';
import PageHeader from '../components/PageHeader';

interface QualifyingState {
  state_code: string;
  state_name: string;
  permit_label: string;
  max_age_years: number;
  handgun_only: boolean;
  citation: string;
}

interface EvalForm {
  firearm_type: string;
  state?: string;
  number?: string;
  type?: string;
  issued_on?: string;
  expires_on?: string;
  resident_state?: string;
}

interface EvalResult {
  qualifies: boolean;
  reason?: string;
  message?: string;
  permit_state?: string;
  permit_type?: string;
  permit_number?: string;
  issued_on?: string | null;
  expires_on?: string | null;
  citation?: string;
}

export default function CcwExemption() {
  const [states, setStates] = useState<QualifyingState[]>([]);
  const [evalForm, setEvalForm] = useState<EvalForm>({ firearm_type: 'handgun' });
  const [evalResult, setEvalResult] = useState<EvalResult | null>(null);
  const [recordFormId, setRecordFormId] = useState<string>('');
  const [recordBusy, setRecordBusy] = useState(false);
  const [recordResult, setRecordResult] = useState<string | null>(null);

  useEffect(() => {
    (async () => {
      try {
        const r = await get<{ qualifying_states: QualifyingState[] }>('/atf/ccw/qualifying-states');
        setStates(r.qualifying_states || []);
      } catch { /* surface on first eval */ }
    })();
  }, []);

  const evaluate = async () => {
    setEvalResult(null);
    if (!evalForm.state || !evalForm.number || !evalForm.resident_state) return;
    try {
      const r = await post<EvalResult>('/compliance/ccw/evaluate', {
        permit: {
          state: evalForm.state,
          number: evalForm.number,
          type: evalForm.type,
          issued_on: evalForm.issued_on,
          expires_on: evalForm.expires_on,
        },
        resident_state: evalForm.resident_state,
        firearms: [{ firearm_type: evalForm.firearm_type }],
      });
      setEvalResult(r);
    } catch (e) {
      setEvalResult({
        qualifies: false,
        reason: 'error',
        message: errorMessage(e, 'Evaluation failed'),
      });
    }
  };

  const recordOnForm = async () => {
    setRecordResult(null);
    const formId = parseInt(recordFormId, 10);
    if (!formId || !evalResult?.qualifies) return;
    setRecordBusy(true);
    try {
      const r = await post<{ exemption?: { citation?: string } }>(`/atf/4473/${formId}/ccw-exemption`, {
        permit: {
          state: evalForm.state,
          number: evalForm.number,
          type: evalForm.type,
          issued_on: evalForm.issued_on,
          expires_on: evalForm.expires_on,
        },
        firearms: [{ firearm_type: evalForm.firearm_type }],
      });
      setRecordResult(`✓ Recorded on 4473 #${formId} — NICS now exempt (${r.exemption?.citation ?? ''}). 4473 form itself is still required.`);
    } catch (e) {
      setRecordResult(`✗ ${errorMessage(e, 'Failed')}`);
    } finally { setRecordBusy(false); }
  };

  const clearOnForm = async () => {
    const formId = parseInt(recordFormId, 10);
    if (!formId) return;
    if (!confirm(`Clear CCW exemption on 4473 #${formId}? NICS will be required again.`)) return;
    setRecordBusy(true);
    try {
      await api('DELETE', `/atf/4473/${formId}/ccw-exemption`);
      setRecordResult(`✓ Exemption cleared on 4473 #${formId} — NICS is required again.`);
    } catch (e) {
      setRecordResult(`✗ ${errorMessage(e, 'Failed')}`);
    } finally { setRecordBusy(false); }
  };

  return (
    <div>
      <PageHeader
        title="CCW → NICS Bypass (18 USC 922(t)(3))"
        subtitle="When a buyer presents a qualifying state concealed-carry permit, the NICS background check is skipped — but the 4473 form itself is still required. The permit details are recorded on the 4473 in place of the NTN. Qualifying state list and max-age window come from the state rule sheet (ATF Permanent Brady Permit Chart)."
      />

      <div className="grid gap-4 md:grid-cols-2">
        <div className="card p-4">
          <h3 className="font-semibold mb-3">Evaluate a permit</h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs mb-1">Buyer&apos;s resident state</label>
              <input className="input w-full" maxLength={2} placeholder="AZ"
                value={evalForm.resident_state ?? ''}
                onChange={(e) => setEvalForm({ ...evalForm, resident_state: e.target.value.toUpperCase() })} />
            </div>
            <div>
              <label className="block text-xs mb-1">Permit state</label>
              <input className="input w-full" maxLength={2} placeholder="AZ"
                value={evalForm.state ?? ''}
                onChange={(e) => setEvalForm({ ...evalForm, state: e.target.value.toUpperCase() })} />
            </div>
            <div className="col-span-2">
              <label className="block text-xs mb-1">Permit number</label>
              <input className="input w-full" value={evalForm.number ?? ''}
                onChange={(e) => setEvalForm({ ...evalForm, number: e.target.value })} />
            </div>
            <div>
              <label className="block text-xs mb-1">Issued on</label>
              <input className="input w-full" type="date" value={evalForm.issued_on ?? ''}
                onChange={(e) => setEvalForm({ ...evalForm, issued_on: e.target.value })} />
            </div>
            <div>
              <label className="block text-xs mb-1">Expires on</label>
              <input className="input w-full" type="date" value={evalForm.expires_on ?? ''}
                onChange={(e) => setEvalForm({ ...evalForm, expires_on: e.target.value })} />
            </div>
            <div>
              <label className="block text-xs mb-1">Permit type (label)</label>
              <input className="input w-full" placeholder="AZ CCW" value={evalForm.type ?? ''}
                onChange={(e) => setEvalForm({ ...evalForm, type: e.target.value })} />
            </div>
            <div>
              <label className="block text-xs mb-1">Firearm type</label>
              <select className="input w-full" value={evalForm.firearm_type}
                onChange={(e) => setEvalForm({ ...evalForm, firearm_type: e.target.value })}>
                <option value="handgun">handgun</option>
                <option value="long_gun">long_gun</option>
                <option value="rifle">rifle</option>
                <option value="shotgun">shotgun</option>
              </select>
            </div>
            <button className="btn-primary col-span-2" onClick={evaluate}>Evaluate</button>
          </div>

          {evalResult && (
            <div className={`card p-3 mt-4 ${evalResult.qualifies ? 'border-emerald-400 bg-emerald-50 dark:bg-emerald-900/20' : 'border-rose-400 bg-rose-50 dark:bg-rose-900/20'}`}>
              <div className="font-semibold">
                {evalResult.qualifies ? '✓ Qualifies — NICS may be skipped' : '✗ Does not qualify'}
              </div>
              {evalResult.message && <div className="text-sm mt-1">{evalResult.message}</div>}
              {evalResult.citation && <div className="text-xs text-zinc-500 mt-1">Citation: {evalResult.citation}</div>}
              {evalResult.reason && !evalResult.qualifies && (
                <div className="text-xs mt-1"><code className="bg-zinc-100 dark:bg-zinc-800 px-1 rounded">{evalResult.reason}</code></div>
              )}
            </div>
          )}
        </div>

        <div className="card p-4">
          <h3 className="font-semibold mb-1">Record on a 4473</h3>
          <p className="text-xs text-zinc-500 mb-3">
            After evaluating, attach the exemption to the 4473 by its database ID. The form itself stays in-progress
            and still needs the standard signatures — only the NICS check is skipped, with the permit details
            recorded on the form in place of the NTN.
          </p>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <input className="input col-span-2" placeholder="4473 form ID (numeric)" value={recordFormId}
              onChange={(e) => setRecordFormId(e.target.value)} />
            <button className="btn-primary col-span-1" disabled={recordBusy || !evalResult?.qualifies || !recordFormId} onClick={recordOnForm}>
              {recordBusy ? 'Working…' : 'Record exemption'}
            </button>
            <button className="btn-secondary col-span-1" disabled={recordBusy || !recordFormId} onClick={clearOnForm}>
              Clear exemption
            </button>
          </div>
          {recordResult && (
            <div className="text-sm mt-3">{recordResult}</div>
          )}
        </div>
      </div>

      <div className="card p-4 mt-4">
        <h3 className="font-semibold mb-3">Qualifying states ({states.length})</h3>
        <p className="text-xs text-zinc-500 mb-3">
          From the state rule sheet. Source: ATF &ldquo;Permanent Brady Permit Chart&rdquo; — list shifts over time, verify with current ATF guidance.
        </p>
        <table className="w-full text-sm">
          <thead>
            <tr className="text-left">
              <th>Code</th><th>State</th><th>Permit</th>
              <th className="text-right">Max age (yrs)</th><th>Handgun only</th><th>Citation</th>
            </tr>
          </thead>
          <tbody>
            {states.map((s) => (
              <tr key={s.state_code} className="border-t border-zinc-100 dark:border-zinc-800">
                <td className="font-mono font-semibold">{s.state_code}</td>
                <td>{s.state_name}</td>
                <td>{s.permit_label}</td>
                <td className="text-right">{s.max_age_years}</td>
                <td>{s.handgun_only ? <span className="badge-amber">yes</span> : '—'}</td>
                <td className="text-xs">{s.citation}</td>
              </tr>
            ))}
            {states.length === 0 && (
              <tr><td colSpan={6} className="text-zinc-500 text-center py-4">Loading — or no qualifying states in the seed.</td></tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
