import { useEffect, useState } from 'react';
import { get, post, errorMessage } from '../api';
import PageHeader from '../components/PageHeader';

interface ProviderRow { slug: string; label: string; detected: boolean; active: boolean; }
interface MetaMap { active_key?: string; plan_key?: string; expires_key?: string; member_id_key?: string; }

export default function Membership() {
  const [providers, setProviders] = useState<ProviderRow[]>([]);
  const [pinned, setPinned] = useState('');
  const [metaMap, setMetaMap] = useState<MetaMap>({});
  const [testId, setTestId] = useState('');
  const [testResult, setTestResult] = useState<unknown>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  const load = async () => {
    setLoading(true);
    try {
      const r = await get<{ providers: ProviderRow[]; pinned: string; meta_map: MetaMap }>('/membership/providers');
      setProviders(r.providers || []);
      setPinned(r.pinned || '');
      setMetaMap(r.meta_map || {});
    } finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);

  const save = async () => {
    setSaving(true);
    try {
      await post('/membership/configure', { provider: pinned, meta_map: metaMap });
      await load();
    } finally { setSaving(false); }
  };

  const runTest = async () => {
    setTestResult(null);
    if (!testId) return;
    try {
      const r = await get('/membership/lookup', { customer_id: testId });
      setTestResult(r);
    } catch (e) {
      setTestResult({ error: errorMessage(e) });
    }
  };

  return (
    <div>
      <PageHeader
        title="Range Membership"
        subtitle="Detects WooCommerce Memberships, PMPro, MemberPress, or a custom user-meta schema. Host themes can override via the g2a_pos_membership_lookup filter."
      />

      {loading ? <div className="card p-6 text-sm text-zinc-500">Loading…</div> : (
        <>
          <div className="card mb-4 p-5">
            <h3 className="text-base font-semibold">Detected providers</h3>
            <div className="mt-3 grid gap-2">
              {providers.map((p) => (
                <label key={p.slug} className="flex items-center justify-between rounded-lg border border-zinc-200 p-3 dark:border-zinc-800">
                  <span className="flex items-center gap-3">
                    <input
                      type="radio"
                      name="provider"
                      checked={pinned === p.slug}
                      onChange={() => setPinned(p.slug)}
                    />
                    <span className="font-medium">{p.label}</span>
                    <code className="text-xs text-zinc-500">{p.slug}</code>
                  </span>
                  <span className="flex gap-2">
                    {p.detected && <span className="badge-green">detected</span>}
                    {p.active && <span className="badge-amber">active</span>}
                  </span>
                </label>
              ))}
              <label className="flex items-center gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-800">
                <input type="radio" name="provider" checked={pinned === ''} onChange={() => setPinned('')} />
                <span className="font-medium">Auto-detect</span>
                <span className="text-xs text-zinc-500">Try WC Memberships → PMPro → MemberPress → user-meta.</span>
              </label>
            </div>
          </div>

          <div className="card mb-4 p-5">
            <h3 className="text-base font-semibold">Custom user-meta map</h3>
            <p className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
              Use this if Guns 2 Ammo runs a bespoke schema (e.g. WPistic-specific keys). Point each row at the actual meta key your theme writes.
            </p>
            <div className="mt-4 grid gap-3 md:grid-cols-2">
              {(['active_key', 'plan_key', 'expires_key', 'member_id_key'] as const).map((k) => (
                <label key={k} className="text-sm">
                  <div className="mb-1 font-medium">{k}</div>
                  <input className="input" value={metaMap[k] || ''} onChange={(e) => setMetaMap({ ...metaMap, [k]: e.target.value })} placeholder={`e.g. ${k === 'active_key' ? 'g2a_member_active' : 'g2a_member_' + k.replace('_key', '')}`} />
                </label>
              ))}
            </div>
            <details className="mt-4 text-sm text-zinc-500">
              <summary className="cursor-pointer">Filter override (for theme/plugin authors)</summary>
              <pre className="mt-2 overflow-auto rounded bg-zinc-100 p-3 text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{`add_filter('g2a_pos_membership_lookup', function ($null, $customer_id) {
    return [
        'active' => true,
        'plan' => 'Range Premium',
        'plan_slug' => 'premium',
        'expires_at' => '2027-01-01 00:00:00',
        'member_id' => 'WPISTIC-' . $customer_id,
        'discounts' => [],
    ];
}, 10, 2);`}</pre>
            </details>
          </div>

          <div className="card mb-4 p-5">
            <h3 className="text-base font-semibold">Test lookup</h3>
            <div className="mt-3 flex flex-wrap items-end gap-2">
              <label className="text-sm">
                <div className="mb-1 font-medium">Customer (WP user) ID</div>
                <input className="input" type="number" value={testId} onChange={(e) => setTestId(e.target.value)} />
              </label>
              <button className="btn-secondary" onClick={runTest}>Look up</button>
            </div>
            {testResult != null && (
              <pre className="mt-4 overflow-auto rounded bg-zinc-100 p-3 text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                {JSON.stringify(testResult, null, 2)}
              </pre>
            )}
          </div>

          <div className="flex justify-end gap-2">
            <button className="btn-primary" onClick={save} disabled={saving}>{saving ? 'Saving…' : 'Save configuration'}</button>
          </div>
        </>
      )}
    </div>
  );
}
