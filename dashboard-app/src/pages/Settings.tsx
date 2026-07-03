import { useEffect, useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { api, type DashboardSettings } from '@/lib/api'
import { cn } from '@/lib/cn'

const RANGES: Array<{ value: DashboardSettings['defaultRange']; label: string }> = [
  { value: 'last-7',           label: 'Last 7 days' },
  { value: 'last-30',          label: 'Last 30 days' },
  { value: 'last-90',          label: 'Last 90 days' },
  { value: 'month-to-date',    label: 'Month-to-date' },
  { value: 'quarter-to-date',  label: 'Quarter-to-date' },
  { value: 'year-to-date',     label: 'Year-to-date' },
]

const DAYS: Array<{ value: DashboardSettings['weeklyReportDay']; label: string }> = [
  { value: 'monday',    label: 'Monday' },
  { value: 'tuesday',   label: 'Tuesday' },
  { value: 'wednesday', label: 'Wednesday' },
  { value: 'thursday',  label: 'Thursday' },
  { value: 'friday',    label: 'Friday' },
  { value: 'saturday',  label: 'Saturday' },
  { value: 'sunday',    label: 'Sunday' },
]

const HOURS = Array.from({ length: 24 }, (_, i) => i)

export function Settings() {
  const [values, setValues] = useState<DashboardSettings | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [status, setStatus] = useState<{ ok: boolean; msg: string } | null>(null)

  useEffect(() => {
    let cancelled = false
    void api.settings
      .get()
      .then(s => {
        if (cancelled) return
        setValues(s)
        setLoading(false)
      })
      .catch(err => {
        if (cancelled) return
        setStatus({ ok: false, msg: err instanceof Error ? err.message : String(err) })
        setLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [])

  async function save() {
    if (!values) return
    setSaving(true)
    setStatus(null)
    try {
      const fresh = await api.settings.update(values)
      setValues(fresh)
      setStatus({ ok: true, msg: 'Settings saved.' })
    } catch (err) {
      setStatus({ ok: false, msg: err instanceof Error ? err.message : String(err) })
    } finally {
      setSaving(false)
    }
  }

  if (loading) return <Spinner />

  return (
    <div>
      <PageHeader
        eyebrow="Configuration"
        title="Settings"
        subtitle="Dashboard defaults you can change. Sensitive values (API keys, webhook secrets) live in the WordPress admin, never in the browser."
      />

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <Card title="Roles & access">
          <div className="space-y-3 text-sm">
            <Row label="Owner"    value="Full access — including model keys and approval-required actions." />
            <Row label="Manager"  value="Full analytics + approval on drafts. No key management." />
            <Row label="Analyst"  value="Read-only analytics." />
            <Row label="Staff"    value="Not intended for this dashboard. Use POS at pos.guns2ammo.com." />
          </div>
        </Card>

        <Card title="Data connections">
          <div className="space-y-3 text-sm">
            <Row label="WordPress base URL"    value="https://guns2ammo.com" />
            <Row label="g2a-business-api"      value="Connected · v0.1.0" />
            <Row label="GA4 property"          value="properties/412896xxxx" />
            <Row label="Search Console"        value="sc-domain:guns2ammo.com" />
            <Row label="WooCommerce REST"      value="Connected · read/write" />
            <Row label="Stripe webhook secret" value="•••• (in WP secrets)" />
          </div>
        </Card>

        {values && (
          <Card title="Defaults" subtitle="These persist server-side and drive automation cron.">
            <div className="space-y-4 text-sm">
              <Field label="Default analytics range">
                <select
                  className="input"
                  value={values.defaultRange}
                  onChange={e => setValues({ ...values, defaultRange: e.target.value as DashboardSettings['defaultRange'] })}
                >
                  {RANGES.map(r => (
                    <option key={r.value} value={r.value}>{r.label}</option>
                  ))}
                </select>
              </Field>

              <Field label="Currency">
                <select className="input" value={values.currency} disabled>
                  <option value="USD">USD (only supported currency)</option>
                </select>
              </Field>

              <Field label="Weekly report">
                <div className="flex gap-2">
                  <select
                    className="input flex-1"
                    value={values.weeklyReportDay}
                    onChange={e => setValues({ ...values, weeklyReportDay: e.target.value as DashboardSettings['weeklyReportDay'] })}
                  >
                    {DAYS.map(d => (
                      <option key={d.value} value={d.value}>{d.label}</option>
                    ))}
                  </select>
                  <select
                    className="input w-28"
                    value={values.weeklyReportHour}
                    onChange={e => setValues({ ...values, weeklyReportHour: Number(e.target.value) })}
                  >
                    {HOURS.map(h => (
                      <option key={h} value={h}>{h.toString().padStart(2, '0')}:00</option>
                    ))}
                  </select>
                </div>
              </Field>

              <Field label="Daily summary hour">
                <select
                  className="input w-28"
                  value={values.dailySummaryHour}
                  onChange={e => setValues({ ...values, dailySummaryHour: Number(e.target.value) })}
                >
                  {HOURS.map(h => (
                    <option key={h} value={h}>{h.toString().padStart(2, '0')}:00</option>
                  ))}
                </select>
              </Field>

              <Field label="Owner email" hint="Where operational alerts and manual review notifications go.">
                <input
                  type="email"
                  className="input"
                  placeholder="owner@guns2ammo.com"
                  value={values.ownerEmail}
                  onChange={e => setValues({ ...values, ownerEmail: e.target.value })}
                />
              </Field>

              <Field label="Timezone" hint="America/Phoenix (AZ does not observe DST).">
                <input
                  type="text"
                  className="input"
                  value={values.timezone}
                  onChange={e => setValues({ ...values, timezone: e.target.value })}
                />
              </Field>

              <div className="flex items-center gap-3 pt-2">
                <button
                  className="btn-primary text-sm"
                  onClick={() => void save()}
                  disabled={saving}
                >
                  {saving ? 'Saving…' : 'Save changes'}
                </button>
                {status && (
                  <span
                    className={cn(
                      'text-sm rounded-lg px-3 py-1',
                      status.ok
                        ? 'bg-emerald-50 text-emerald-800 border border-emerald-100'
                        : 'bg-rose-50 text-rose-800 border border-rose-100',
                    )}
                  >
                    {status.msg}
                  </span>
                )}
              </div>
            </div>
          </Card>
        )}

        <Card title="Danger zone">
          <DangerZone />
        </Card>
      </div>
    </div>
  )
}

type DangerAction = 'rag' | 'keys' | 'sessions'

const DANGER_COPY: Record<DangerAction, { label: string; confirm: string; success: (r: Record<string, unknown>) => string }> = {
  rag: {
    label:   'Rebuild RAG stores',
    confirm: 'Enqueue a RAG rebuild? Existing agents keep serving the current index until the rebuild completes.',
    success: r => `Rebuild queued at ${String(r.at ?? '')}.`,
  },
  keys: {
    label:   'Rotate API keys',
    confirm: 'Mark every stored model key for rotation? The dashboard will keep working; you must supply new keys via the Test/Edit drawer.',
    success: r => `${Number(r.marked ?? 0)} model connection(s) marked for rotation.`,
  },
  sessions: {
    label:   'Force re-auth all sessions',
    confirm: 'Revoke every WordPress application password for dashboard users? You will be signed out too.',
    success: r => `Revoked ${Number(r.revoked ?? 0)} password(s) across ${Number(r.users ?? 0)} user(s).`,
  },
}

function DangerZone() {
  const [busy, setBusy] = useState<DangerAction | null>(null)
  const [status, setStatus] = useState<{ ok: boolean; msg: string } | null>(null)

  async function run(kind: DangerAction) {
    const copy = DANGER_COPY[kind]
    if (!confirm(copy.confirm)) return
    setBusy(kind)
    setStatus(null)
    try {
      const res =
        kind === 'rag'      ? await api.system.rebuildRag()      :
        kind === 'keys'     ? await api.system.rotateKeys()      :
                              await api.system.revokeSessions()
      setStatus({ ok: true, msg: copy.success(res.result ?? {}) })
    } catch (err) {
      setStatus({ ok: false, msg: err instanceof Error ? err.message : String(err) })
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="text-sm text-ink-600 space-y-3">
      <p>
        Actions here affect production integrations. BridGistic will not
        perform any of these — the owner must click through in this UI.
      </p>
      <div className="flex flex-wrap gap-2">
        {(Object.keys(DANGER_COPY) as DangerAction[]).map(k => (
          <button
            key={k}
            className="btn-secondary text-xs"
            disabled={busy !== null}
            onClick={() => void run(k)}
          >
            {busy === k ? 'Working…' : DANGER_COPY[k].label}
          </button>
        ))}
      </div>
      {status && (
        <div
          className={cn('text-xs rounded-lg px-3 py-2',
            status.ok
              ? 'bg-emerald-50 text-emerald-800 border border-emerald-100'
              : 'bg-rose-50 text-rose-800 border border-rose-100')}
        >
          {status.msg}
        </div>
      )}
    </div>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-start justify-between gap-4">
      <span className="text-ink-500 shrink-0">{label}</span>
      <span className="text-ink-800 text-right">{value}</span>
    </div>
  )
}

function Field({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="text-xs font-medium uppercase tracking-wide text-ink-500">{label}</span>
      <div className="mt-1">{children}</div>
      {hint && <div className="mt-1 text-xs text-ink-500">{hint}</div>}
    </label>
  )
}
