import { useState } from 'react';
import { get } from '../api';
import PageHeader from '../components/PageHeader';

interface FflMatch {
  id: number;
  ffl_number: string;
  business_name: string;
  contact_name: string;
  address: string;
  city: string;
  state: string;
  zip: string;
  phone: string;
  email: string;
}

export default function FflRouting() {
  const [state, setState] = useState('');
  const [zip, setZip] = useState('');
  const [results, setResults] = useState<FflMatch[]>([]);

  const lookup = async () => {
    if (!state) return;
    const r = await get<{ suggestions: FflMatch[] }>('/ffl/route', { state, zip });
    setResults(r.suggestions || []);
  };

  return (
    <div>
      <PageHeader title="Customer → FFL Routing"
        subtitle="Given a customer state + ZIP, picks the nearest active FFL partner. Ranks by state match, then ZIP prefix proximity." />
      <div className="card p-4 mb-4 flex gap-2 items-end">
        <div className="flex-1">
          <label className="block text-xs mb-1">Customer state (2-letter)</label>
          <input className="input w-full" placeholder="e.g. TX" value={state} onChange={(e) => setState(e.target.value.toUpperCase())} />
        </div>
        <div className="flex-1">
          <label className="block text-xs mb-1">Customer ZIP</label>
          <input className="input w-full" placeholder="e.g. 75201" value={zip} onChange={(e) => setZip(e.target.value)} />
        </div>
        <button className="btn-primary" onClick={lookup}>Find FFLs</button>
      </div>
      {results.length > 0 && (
        <div className="card p-4">
          <h3 className="font-semibold mb-3">Suggested FFLs ({results.length})</h3>
          <ul className="space-y-2">
            {results.map((m) => (
              <li key={m.id} className="border-b pb-2 last:border-0">
                <div className="font-semibold">{m.business_name} <span className="text-xs text-zinc-500 font-mono">{m.ffl_number}</span></div>
                <div className="text-sm">{m.address}, {m.city}, {m.state} {m.zip}</div>
                <div className="text-xs text-zinc-500">{m.phone} · {m.email}</div>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
