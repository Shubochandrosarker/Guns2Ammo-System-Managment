import { useEffect, useState } from 'react';
import { get, post } from '../api';
import PageHeader from '../components/PageHeader';
import DataTable, { type Column } from '../components/DataTable';

interface OosPolicy {
  global: 'allow' | 'block_with_override' | 'block_all';
  by_location: Record<string, string>;
  modes: string[];
  default: string;
}

const MODE_LABELS: Record<string, string> = {
  allow: 'Allow (federal/state rules still apply)',
  block_with_override: 'Block, manager may override per sale',
  block_all: 'Hard block — no override',
};

interface Rule {
  state_code: string;
  state_name: string;
  handgun_min_age: number;
  long_gun_min_age: number;
  waiting_period_hours_handgun: number;
  waiting_period_hours_long_gun: number;
  requires_foid: number;
  requires_dros: number;
  requires_permit_to_purchase_handgun: number;
  one_handgun_per_30_days: number;
  magazine_capacity_limit?: number | null;
}

export default function StateRules() {
  const [rows, setRows] = useState<Rule[]>([]);
  const [loading, setLoading] = useState(true);
  const [oos, setOos] = useState<OosPolicy | null>(null);
  const [oosSaving, setOosSaving] = useState(false);

  useEffect(() => {
    (async () => {
      try {
        setRows(((await get<{ rules: Rule[] }>('/compliance/states')).rules) || []);
        setOos(await get<OosPolicy>('/compliance/oos-policy'));
      }
      finally { setLoading(false); }
    })();
  }, []);

  const saveOosGlobal = async (mode: string) => {
    if (!oos) return;
    setOosSaving(true);
    try {
      await post('/compliance/oos-policy', { mode });
      setOos(await get<OosPolicy>('/compliance/oos-policy'));
    } finally { setOosSaving(false); }
  };

  const cols: Column<Rule>[] = [
    { key: 'state_code', label: '', width: '60px', render: (r) => <span className="font-mono font-semibold">{r.state_code}</span> },
    { key: 'state_name', label: 'State', render: (r) => <span className="font-medium">{r.state_name}</span> },
    { key: 'handgun_min_age', label: 'Handgun age', align: 'right' },
    { key: 'long_gun_min_age', label: 'Long-gun age', align: 'right' },
    { key: 'waiting_period_hours_handgun', label: 'Wait (HG hrs)', align: 'right', render: (r) => r.waiting_period_hours_handgun || '—' },
    { key: 'waiting_period_hours_long_gun', label: 'Wait (LG hrs)', align: 'right', render: (r) => r.waiting_period_hours_long_gun || '—' },
    { key: 'permit', label: 'Permit', render: (r) => r.requires_permit_to_purchase_handgun ? <span className="badge-amber">required</span> : '—' },
    { key: 'foid', label: 'FOID', render: (r) => r.requires_foid ? <span className="badge-amber">yes</span> : '—' },
    { key: 'dros', label: 'DROS', render: (r) => r.requires_dros ? <span className="badge-amber">yes</span> : '—' },
    { key: 'one_per_30', label: '1/30d', render: (r) => r.one_handgun_per_30_days ? <span className="badge-amber">yes</span> : '—' },
    { key: 'mag', label: 'Mag cap', align: 'right', render: (r) => r.magazine_capacity_limit ?? '—' },
  ];

  return (
    <div>
      <PageHeader
        title="State Compliance Rules"
        subtitle="Per-state firearm transfer requirements layered on top of federal law (GCA, NICS, NFA). FOID is Illinois-only; DROS is California-only. CCW exemption to NICS varies by state. Override per merchant. Tracks min age, waiting period, permit-to-purchase, roster, magazine limits, multi-sale long-gun report, FFL-to-FFL nonresident rule." />

      {oos && (
        <div className="card p-4 mb-4">
          <h3 className="font-semibold mb-1">Out-of-state customer firearm-sale policy</h3>
          <p className="text-xs text-zinc-500 mb-3">
            Applies when the customer&apos;s state of residence differs from the store&apos;s state. Sits ON TOP of the federal nonresident-handgun rule (18 USC 922(b)(3))
            and any state-specific block. Example use case: a single-state FFL with the standing policy &ldquo;we do not sell firearms to out-of-state residents.&rdquo;
          </p>
          <div className="grid gap-2 md:grid-cols-3">
            {oos.modes.map((m) => (
              <label key={m} className={`card p-3 cursor-pointer ${oos.global === m ? 'border-brand bg-brand/5' : ''}`}>
                <div className="flex items-start gap-2">
                  <input type="radio" name="oos-mode" checked={oos.global === m}
                    onChange={() => saveOosGlobal(m)} disabled={oosSaving} />
                  <div className="text-sm">
                    <div className="font-semibold">{m}</div>
                    <div className="text-xs text-zinc-500 mt-1">{MODE_LABELS[m] || ''}</div>
                  </div>
                </div>
              </label>
            ))}
          </div>
          <p className="text-xs text-zinc-500 mt-3">
            Current setting: <code className="bg-zinc-100 dark:bg-zinc-800 px-1 rounded">{oos.global}</code>.
            Per-location overrides via <code>POST /compliance/oos-policy/location</code>.
            Manager overrides per sale require <code>g2a_pos_manage_compliance</code> and are recorded in the audit log.
          </p>
        </div>
      )}

      <DataTable<Rule> rows={rows} columns={cols} loading={loading} rowKey={(r) => r.state_code} empty="No state rules loaded." />
    </div>
  );
}
