import { useState } from 'react';
import PageHeader from '../components/PageHeader';

export default function AceAudit() {
  const [from, setFrom] = useState(() => new Date(Date.now() - 365 * 86400000).toISOString().slice(0, 10));
  const [to, setTo] = useState(() => new Date().toISOString().slice(0, 10));

  const cfg = window.G2A_POS_ADMIN;
  const base = cfg?.restBase || '/wp-json/g2a-pos/v1';

  const download = (path: string) => {
    const url = `${base}${path}?from=${from}&to=${to}&_wpnonce=${encodeURIComponent(cfg?.nonce || '')}`;
    window.open(url, '_blank');
  };

  return (
    <div>
      <PageHeader
        title="ACE Audit Pack"
        subtitle="One-click bundle for the ATF's ACE (Annual Compliance Exam) — Bound Book (A&D, 27 CFR 478.125) in classic + eForms CSV + eForms XML, every 4473 (ATF Form 5300.9) in the date range with status, NICS log, theft/loss + multi-sale reports, hash-chain integrity proof, and a MANIFEST.json. Hand to an IOI on inspection day." />
      <div className="card p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3">
        <div>
          <label className="block text-xs mb-1">From</label>
          <input className="input w-full" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        </div>
        <div>
          <label className="block text-xs mb-1">To</label>
          <input className="input w-full" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </div>
        <button className="btn-primary md:col-span-2" onClick={() => download('/atf/audit/ace-pack')}>Download ACE pack (.zip)</button>
      </div>
      <div className="card p-4">
        <h3 className="font-semibold mb-2">What&apos;s inside</h3>
        <ul className="text-sm space-y-1 list-disc pl-5">
          <li>bound_book/bound_book.csv — classic 27 CFR 478.125(e) columns</li>
          <li>bound_book/eforms_bound_book.csv + .xml — ATF eForms 7CR upload shape</li>
          <li>bound_book/chain_verification.json — hash-chain integrity proof</li>
          <li>forms_4473/4473_index.csv — every 4473 in the date range with status</li>
          <li>nics/nics_log.csv — every NICS transaction</li>
          <li>reports/reports_index.csv — theft & loss + multi-sale reports</li>
          <li>MANIFEST.json — counts + integrity summary + date range</li>
        </ul>
      </div>
    </div>
  );
}
