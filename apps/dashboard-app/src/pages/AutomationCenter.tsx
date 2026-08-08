import { useRef, useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { useAsync, useDialogA11y } from '@/lib/hooks'
import { api, type AutomationInterval } from '@/lib/api'
import type { Automation } from '@/types/analytics'
import { cn } from '@/lib/cn'

const CATEGORIES: { key: Automation['category']; label: string }[] = [
  { key: 'booking',    label: 'Booking' },
  { key: 'membership', label: 'Membership' },
  { key: 'waiver',     label: 'Waiver' },
  { key: 'email',      label: 'Email' },
  { key: 'sales',      label: 'Sales' },
  { key: 'seo',        label: 'SEO' },
  { key: 'staff',      label: 'Staff' },
  { key: 'reports',    label: 'Reports' },
  { key: 'agents',     label: 'AI Agents' },
]

const INTERVAL_OPTIONS: Array<{ value: AutomationInterval; label: string }> = [
  { value: 'hourly',     label: 'Hourly' },
  { value: 'twicedaily', label: 'Twice daily' },
  { value: 'daily',      label: 'Daily' },
  { value: 'weekly',     label: 'Weekly' },
]

export function AutomationCenter() {
  const q = useAsync(() => api.automations.list(), [])
  const [filter, setFilter] = useState<Automation['category'] | 'all'>('all')
  const [list, setList] = useState<Automation[] | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [requestOpen, setRequestOpen] = useState(false)

  if (q.loading && !list) return <Spinner />
  const rows = list ?? q.data ?? []
  const visible = filter === 'all' ? rows : rows.filter(a => a.category === filter)

  async function updateAutomation(slug: string, patch: { interval?: AutomationInterval; status?: 'active' | 'paused' }) {
    setBusy(slug)
    try {
      const updated = await api.automations.updateSchedule(slug, patch)
      setList(rows.map(a => (a.slug === slug ? { ...a, ...updated } : a)))
    } catch (err) {
      console.error(err)
    } finally {
      setBusy(null)
    }
  }

  return (
    <div>
      <PageHeader
        eyebrow="Automations"
        title="Automation Center"
        subtitle="All triggers and actions that run without a human. Change the recurrence, toggle on/off, and inspect last-run state."
        actions={<button className="btn-primary text-sm" onClick={() => setRequestOpen(true)}>+ New automation</button>}
      />

      <div className="flex flex-wrap gap-2 mb-4">
        <FilterChip active={filter === 'all'} onClick={() => setFilter('all')}>
          All ({rows.length})
        </FilterChip>
        {CATEGORIES.map(c => {
          const n = rows.filter(a => a.category === c.key).length
          if (n === 0) return null
          return (
            <FilterChip key={c.key} active={filter === c.key} onClick={() => setFilter(c.key)}>
              {c.label} ({n})
            </FilterChip>
          )
        })}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {visible.map(a => (
          <Card key={a.id}>
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="flex items-center gap-2 mb-1">
                  <span className="text-[11px] uppercase tracking-wide text-ink-500">{a.category}</span>
                  <StatusPill status={a.status} />
                </div>
                <h3 className="font-semibold text-ink-800">{a.name}</h3>
              </div>
              <label className="inline-flex items-center gap-2 text-xs cursor-pointer">
                <input
                  type="checkbox"
                  checked={a.status === 'active'}
                  disabled={busy === a.slug}
                  onChange={e => void updateAutomation(a.slug, { status: e.target.checked ? 'active' : 'paused' })}
                  className="h-4 w-4 rounded border-ink-300 text-brand-500 focus:ring-brand-400"
                />
                <span className="text-ink-600">Enabled</span>
              </label>
            </div>

            <div className="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
              <KV label="Trigger">{a.trigger}</KV>
              <KV label="Action">{a.action}</KV>
              <KV label="Last run">{a.lastRun ? new Date(a.lastRun).toLocaleString() : '—'}</KV>
              <KV label="Runs / 7d">{a.runsLast7d}</KV>
              <div className="sm:col-span-2">
                <div className="text-[11px] uppercase tracking-wide text-ink-500">Schedule</div>
                <select
                  className="input mt-1 text-sm"
                  value={a.interval ?? 'daily'}
                  disabled={busy === a.slug}
                  onChange={e => void updateAutomation(a.slug, { interval: e.target.value as AutomationInterval })}
                >
                  {INTERVAL_OPTIONS.map(o => (
                    <option key={o.value} value={o.value}>{o.label}</option>
                  ))}
                </select>
              </div>
            </div>
          </Card>
        ))}
      </div>

      {requestOpen && <NewAutomationDrawer onClose={() => setRequestOpen(false)} />}
    </div>
  )
}

/**
 * Automations here are a fixed, developer-implemented catalog (each is
 * wired to a real WP-Cron handler) — there's no generic no-code builder to
 * open. "+ New automation" is honestly wired to what actually exists: it
 * files a real staff task (the same Tasks system used elsewhere in this
 * dashboard) describing the requested automation, rather than silently
 * doing nothing or faking a builder the backend can't run.
 */
function NewAutomationDrawer({ onClose }: { onClose: () => void }) {
  const [name, setName] = useState('')
  const [details, setDetails] = useState('')
  const [busy, setBusy] = useState(false)
  const [status, setStatus] = useState<{ ok: boolean; msg: string } | null>(null)
  const dialogRef = useRef<HTMLDivElement>(null)
  useDialogA11y(dialogRef, onClose)

  async function submit() {
    if (!name.trim()) return
    setBusy(true)
    setStatus(null)
    try {
      await api.tasks.create(`New automation request: ${name.trim()}`, details.trim(), 'automation-center')
      setStatus({ ok: true, msg: 'Request filed — the team will follow up in Tasks.' })
      setName('')
      setDetails('')
    } catch (err) {
      setStatus({ ok: false, msg: err instanceof Error ? err.message : String(err) })
    } finally {
      setBusy(false)
    }
  }

  return (
    <div
      className="fixed inset-0 z-30"
      style={{ backgroundColor: 'var(--bg-overlay)' }}
      onClick={onClose}
    >
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-label="Request a new automation"
        tabIndex={-1}
        className={cn(
          'absolute inset-y-0 right-0 shadow-xl',
          'w-full sm:max-w-lg overflow-y-auto',
        )}
        style={{ backgroundColor: 'var(--bg-surface)' }}
        onClick={e => e.stopPropagation()}
      >
        <div
          className="sticky top-0 px-4 sm:px-5 py-3 flex items-center justify-between"
          style={{ backgroundColor: 'var(--bg-surface)', borderBottom: '1px solid var(--border-subtle)' }}
        >
          <div>
            <div className="text-[11px] uppercase tracking-wide text-ink-500">Automation Center</div>
            <div className="font-semibold text-ink-800">Request a new automation</div>
          </div>
          <button onClick={onClose} className="btn-ghost !px-2">✕</button>
        </div>

        <div className="p-4 sm:p-5 space-y-4">
          <p className="text-sm text-ink-600">
            Every automation here runs real, developer-wired code — there&apos;s no
            drag-and-drop builder to create one on the fly. Describe what you
            want automated and it&apos;ll land in Tasks for the team to scope and
            build.
          </p>

          <div>
            <label className="text-[11px] uppercase tracking-wide text-ink-500" htmlFor="g2a-auto-req-name">
              What should it do?
            </label>
            <input
              id="g2a-auto-req-name"
              className="input mt-1 text-sm w-full"
              value={name}
              onChange={e => setName(e.target.value)}
              placeholder="e.g. Email a manager when a rental firearm is overdue"
              disabled={busy}
            />
          </div>

          <div>
            <label className="text-[11px] uppercase tracking-wide text-ink-500" htmlFor="g2a-auto-req-details">
              Trigger / details (optional)
            </label>
            <textarea
              id="g2a-auto-req-details"
              className="input mt-1 text-sm w-full"
              rows={4}
              value={details}
              onChange={e => setDetails(e.target.value)}
              placeholder="When should it fire, and what should happen?"
              disabled={busy}
            />
          </div>

          {status && (
            <div
              className="text-sm rounded-lg px-3 py-2"
              style={status.ok
                ? { background: 'var(--success-soft, #ecfdf5)', color: 'var(--success, #047857)' }
                : { background: 'var(--danger-soft)', color: 'var(--danger)' }}
            >
              {status.msg}
            </div>
          )}

          <div className="flex items-center gap-2">
            <button className="btn-primary text-sm" disabled={busy || !name.trim()} onClick={() => void submit()}>
              {busy ? 'Filing…' : 'File request'}
            </button>
            <button className="btn-ghost text-sm" onClick={onClose} disabled={busy}>Cancel</button>
          </div>
        </div>
      </div>
    </div>
  )
}

function StatusPill({ status }: { status: Automation['status'] }) {
  const map: Record<Automation['status'], string> = {
    active:  'pill-green',
    paused:  'pill-gray',
    failing: 'pill-red',
  }
  return <span className={map[status]}>{status}</span>
}

function KV({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <div className="text-[11px] uppercase tracking-wide text-ink-500">{label}</div>
      <div className="text-ink-700">{children}</div>
    </div>
  )
}

function FilterChip({
  active,
  onClick,
  children,
}: { active: boolean; onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      onClick={onClick}
      className={cn(
        'px-3 py-1.5 rounded-full text-xs font-medium transition-colors',
        active ? 'bg-ink-900 text-white' : 'bg-white border border-ink-200 text-ink-700 hover:bg-ink-50',
      )}
    >
      {children}
    </button>
  )
}
