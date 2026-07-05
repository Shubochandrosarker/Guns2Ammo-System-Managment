import { useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'
import { Spinner } from '@/components/ui/Spinner'
import { useAsync } from '@/lib/hooks'
import { api, type LeadsListParams } from '@/lib/api'
import type { Agent, Lead, LeadStatus } from '@/types/analytics'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'

const PER_PAGE = 20

const CATEGORIES: Array<{ key: string; label: string }> = [
  { key: 'lane_booking',  label: 'Lane Booking' },
  { key: 'ccw_class',     label: 'CCW Class' },
  { key: 'range_enquiry', label: 'Range Enquiry' },
  { key: 'new_member',    label: 'New Member' },
  { key: 'ffl_transfer',  label: 'FFL Transfer' },
  { key: 'nfa',           label: 'NFA' },
  { key: 'membership',    label: 'Membership' },
  { key: 'event_booking', label: 'Event Booking' },
  { key: 'general',       label: 'General' },
]

const CATEGORY_LABEL: Record<string, string> = Object.fromEntries(
  CATEGORIES.map(c => [c.key, c.label]),
)

const STATUSES: Array<{ key: LeadStatus; label: string }> = [
  { key: 'new',         label: 'New' },
  { key: 'in_progress', label: 'In Progress' },
  { key: 'contacted',   label: 'Contacted' },
  { key: 'won',         label: 'Won' },
  { key: 'lost',        label: 'Lost' },
  { key: 'spam',        label: 'Spam' },
]

const STATUS_STYLE: Record<LeadStatus, string> = {
  new:         'pill-blue',
  in_progress: 'pill-amber',
  contacted:   'pill-gray',
  won:         'pill-green',
  lost:        'pill-red',
  spam:        'pill-gray',
}

export function Leads() {
  const [category, setCategory] = useState<string>('all')
  const [statusTab, setStatusTab] = useState<LeadStatus | 'all'>('all')
  const [page, setPage] = useState(1)
  const [selectedId, setSelectedId] = useState<number | null>(null)

  const stats = useAsync(() => api.leads.stats(), [])

  const params: LeadsListParams = {
    category: category !== 'all' ? category : undefined,
    status: statusTab !== 'all' ? statusTab : undefined,
    page,
    perPage: PER_PAGE,
  }
  const list = useAsync(() => api.leads.list(params), [category, statusTab, page])

  function refreshAll() {
    list.refresh()
    stats.refresh()
  }

  function selectCategory(key: string) {
    setCategory(key)
    setPage(1)
  }

  function selectStatus(key: LeadStatus | 'all') {
    setStatusTab(key)
    setPage(1)
  }

  const byCategory = stats.data?.byCategory ?? {}
  const byStatus = stats.data?.byStatus ?? {}
  const allCategoryCount = Object.values(byCategory).reduce((sum, n) => sum + n, 0)
  const totalLeads = Object.values(byStatus).reduce((sum, n) => sum + n, 0)
  const topCategoryEntry = Object.entries(byCategory).sort((a, b) => b[1] - a[1])[0]

  // The backend only filters on an exact status match — there is no
  // "not spam" filter — so the default "all" tab fetches unfiltered and
  // hides spam client-side instead. This means the Prev/Next total below
  // (drawn from the server's raw count) can include spam rows even while
  // they're hidden from the visible list.
  const visibleItems = (list.data?.items ?? []).filter(
    l => statusTab !== 'all' || l.status !== 'spam',
  )
  const total = list.data?.total ?? 0
  const hasMore = page * PER_PAGE < total

  const selectedLead = selectedId != null ? (list.data?.items ?? []).find(l => l.id === selectedId) ?? null : null

  return (
    <div>
      <PageHeader
        eyebrow="Inbound pipeline"
        title="Leads"
        subtitle="Every Formistic enquiry, booking-engine submission, and membership signup lands here, categorized automatically."
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Total leads" value={totalLeads} />
        <StatCard label="New today" value={stats.data?.today ?? 0} intent="warn" />
        <StatCard label="This week" value={stats.data?.last7d ?? 0} intent="success" />
        <StatCard
          label="Top category"
          value={topCategoryEntry ? topCategoryEntry[1] : 0}
          sublabel={topCategoryEntry ? (CATEGORY_LABEL[topCategoryEntry[0]] ?? topCategoryEntry[0]) : '—'}
        />
      </div>

      <div className="mt-6 flex flex-wrap gap-2">
        <FilterPill
          active={category === 'all'}
          label="All"
          count={allCategoryCount}
          onClick={() => selectCategory('all')}
        />
        {CATEGORIES.map(c => (
          <FilterPill
            key={c.key}
            active={category === c.key}
            label={c.label}
            count={byCategory[c.key] ?? 0}
            onClick={() => selectCategory(c.key)}
          />
        ))}
      </div>

      <div className="mt-3 flex flex-wrap gap-2 border-b pb-3" style={{ borderColor: 'var(--border-subtle)' }}>
        <StatusTab active={statusTab === 'all'} label="All (excl. spam)" onClick={() => selectStatus('all')} />
        {STATUSES.map(s => (
          <StatusTab key={s.key} active={statusTab === s.key} label={s.label} onClick={() => selectStatus(s.key)} />
        ))}
      </div>

      <Card className="mt-4">
        {list.loading ? (
          <Spinner label="Loading leads…" />
        ) : list.error ? (
          <div className="text-sm rounded-lg px-3 py-2" style={{ background: 'var(--danger-soft)', color: 'var(--danger)' }}>
            {list.error}
          </div>
        ) : visibleItems.length === 0 ? (
          <div className="text-sm text-ink-500">No leads match these filters.</div>
        ) : (
          <div className="overflow-x-auto -mx-1 px-1">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-xs uppercase tracking-wide text-ink-500 border-b border-ink-100">
                  <th className="py-2">Contact</th>
                  <th className="py-2">Subject</th>
                  <th className="py-2">Source</th>
                  <th className="py-2">Created</th>
                  <th className="py-2">Status</th>
                </tr>
              </thead>
              <tbody>
                {visibleItems.map(lead => (
                  <tr
                    key={lead.id}
                    className="border-b border-ink-100 last:border-0 cursor-pointer hover:bg-ink-50"
                    onClick={() => setSelectedId(lead.id)}
                  >
                    <td className="py-2">
                      <div className="font-medium text-ink-800">{lead.contactName || '(no name)'}</div>
                      <div className="text-xs text-ink-500">{lead.contactEmail || '—'}</div>
                    </td>
                    <td className="py-2 max-w-xs">
                      <div className="text-ink-800 truncate">{lead.subject || '(no subject)'}</div>
                      {lead.excerpt && <div className="text-xs text-ink-500 truncate">{lead.excerpt}</div>}
                    </td>
                    <td className="py-2 text-ink-600">{lead.source}</td>
                    <td className="py-2 text-ink-600 whitespace-nowrap">{new Date(lead.createdAt).toLocaleDateString()}</td>
                    <td className="py-2">
                      <span className={STATUS_STYLE[lead.status]}>{lead.status.replace('_', ' ')}</span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {!list.loading && !list.error && (
          <div className="mt-4 flex items-center justify-between text-xs text-ink-500">
            <span>{formatNumber(total)} total</span>
            <div className="flex items-center gap-2">
              <button
                className="btn-secondary text-xs"
                disabled={page <= 1}
                onClick={() => setPage(p => Math.max(1, p - 1))}
              >
                Prev
              </button>
              <span>Page {page}</span>
              <button
                className="btn-secondary text-xs"
                disabled={!hasMore}
                onClick={() => setPage(p => p + 1)}
              >
                Next
              </button>
            </div>
          </div>
        )}
      </Card>

      {selectedLead && (
        <LeadDrawer
          lead={selectedLead}
          onClose={() => setSelectedId(null)}
          onChanged={refreshAll}
        />
      )}
    </div>
  )
}

function FilterPill({
  active,
  label,
  count,
  onClick,
}: { active: boolean; label: string; count: number; onClick: () => void }) {
  return (
    <button
      onClick={onClick}
      className={cn(
        'px-3 py-1.5 rounded-full text-xs font-medium border transition-colors',
        active ? 'text-white' : 'text-ink-600 hover:text-ink-800',
      )}
      style={{
        backgroundColor: active ? 'var(--brand)' : 'var(--bg-surface)',
        borderColor: active ? 'var(--brand)' : 'var(--border-subtle)',
      }}
    >
      {label} <span className={active ? 'opacity-80' : 'text-ink-400'}>({formatNumber(count)})</span>
    </button>
  )
}

function StatusTab({ active, label, onClick }: { active: boolean; label: string; onClick: () => void }) {
  return (
    <button
      onClick={onClick}
      className={cn(
        'px-3 py-1.5 text-xs font-medium border-b-2 -mb-px transition-colors',
        active ? 'border-brand-500' : 'border-transparent text-ink-500 hover:text-ink-800',
      )}
      style={{ color: active ? 'var(--brand)' : undefined, borderBottomColor: active ? 'var(--brand)' : 'transparent' }}
    >
      {label}
    </button>
  )
}

function LeadDrawer({
  lead,
  onClose,
  onChanged,
}: { lead: Lead; onClose: () => void; onChanged: () => void }) {
  const agents = useAsync(() => api.agents.list(), [])
  const [current, setCurrent] = useState(lead)
  const [busy, setBusy] = useState(false)
  const [status, setStatus] = useState<{ ok: boolean; msg: string } | null>(null)

  async function patch(next: { status?: LeadStatus; assignedAgent?: string | null }) {
    setBusy(true)
    setStatus(null)
    try {
      const updated = await api.leads.updateStatus(current.id, next)
      setCurrent(updated)
      onChanged()
      setStatus({ ok: true, msg: 'Saved.' })
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
        className={cn(
          'absolute inset-y-0 right-0 shadow-xl',
          'w-full sm:max-w-lg lg:max-w-xl overflow-y-auto',
        )}
        style={{ backgroundColor: 'var(--bg-surface)' }}
        onClick={e => e.stopPropagation()}
      >
        <div
          className="sticky top-0 px-4 sm:px-5 py-3 flex items-center justify-between"
          style={{
            backgroundColor: 'var(--bg-surface)',
            borderBottom: '1px solid var(--border-subtle)',
          }}
        >
          <div>
            <div className="text-[11px] uppercase tracking-wide text-ink-500">
              {CATEGORY_LABEL[current.category] ?? current.category}
            </div>
            <div className="font-semibold text-ink-800">{current.contactName || `Lead #${current.id}`}</div>
          </div>
          <button onClick={onClose} className="btn-ghost !px-2">✕</button>
        </div>

        <div className="p-4 sm:p-5 space-y-4">
          <div className="space-y-1.5 text-sm">
            <Row label="Subject"  value={current.subject || '—'} />
            <Row label="Source"   value={current.source} />
            <Row label="Ref ID"   value={current.sourceRefId || '—'} />
            <Row label="Email"    value={current.contactEmail || '—'} />
            <Row label="Phone"    value={current.contactPhone || '—'} />
            <Row label="Created"  value={new Date(current.createdAt).toLocaleString()} />
            <Row label="Updated"  value={new Date(current.updatedAt).toLocaleString()} />
          </div>

          {current.excerpt && (
            <div className="rounded-lg bg-ink-50 p-3 text-sm text-ink-700 whitespace-pre-wrap">
              {current.excerpt}
            </div>
          )}

          {current.meta && (
            <div>
              <div className="text-[11px] uppercase tracking-wide text-ink-500 mb-1">Meta</div>
              <pre className="font-mono text-[11px] text-ink-700 whitespace-pre-wrap rounded-lg border border-ink-100 p-2 max-h-40 overflow-y-auto">
                {JSON.stringify(current.meta, null, 2)}
              </pre>
            </div>
          )}

          <div>
            <div className="text-[11px] uppercase tracking-wide text-ink-500 mb-1">Status</div>
            <select
              className="input"
              value={current.status}
              disabled={busy}
              onChange={e => void patch({ status: e.target.value as LeadStatus })}
            >
              {STATUSES.map(s => (
                <option key={s.key} value={s.key}>{s.label}</option>
              ))}
            </select>
          </div>

          <div>
            <div className="text-[11px] uppercase tracking-wide text-ink-500 mb-1">Assign to agent</div>
            {agents.loading ? (
              <Spinner label="Loading agents…" />
            ) : (
              <select
                className="input"
                value={current.assignedAgent ?? ''}
                disabled={busy}
                onChange={e => void patch({ assignedAgent: e.target.value || null })}
              >
                <option value="">Unassigned</option>
                {(agents.data ?? []).map((a: Agent) => (
                  <option key={a.id} value={a.id}>{a.name}</option>
                ))}
              </select>
            )}
          </div>

          {status && (
            <div
              className={cn(
                'text-sm rounded-lg px-3 py-2',
                status.ok ? 'bg-emerald-50 text-emerald-800 border border-emerald-100'
                          : 'bg-rose-50 text-rose-800 border border-rose-100',
              )}
            >
              {status.msg}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-start justify-between gap-3">
      <span className="text-ink-500 shrink-0">{label}</span>
      <span className="text-ink-800 font-medium text-right break-words">{value}</span>
    </div>
  )
}
