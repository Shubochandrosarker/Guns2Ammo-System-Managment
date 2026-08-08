import { useRef, useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'
import { Spinner } from '@/components/ui/Spinner'
import { useAsync, useDialogA11y } from '@/lib/hooks'
import { api } from '@/lib/api'
import type { WaiverDetail, WaiverStatus, WaiverTodayItem } from '@/types/analytics'
import { formatNumber } from '@/lib/format'
import { cn } from '@/lib/cn'

const PER_PAGE = 25

const STATUSES: Array<{ key: WaiverStatus; label: string }> = [
  { key: 'current',  label: 'Current' },
  { key: 'expiring', label: 'Expiring (30d)' },
  { key: 'expired',  label: 'Expired' },
]

const STATUS_STYLE: Record<WaiverStatus, string> = {
  current:    'pill-green',
  expiring:   'pill-amber',
  expired:    'pill-red',
  superseded: 'pill-gray',
}

export function Waivers() {
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [statusTab, setStatusTab] = useState<WaiverStatus | 'all'>('all')
  const [page, setPage] = useState(1)
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [sendTo, setSendTo] = useState<string | null>(null)

  const stats = useAsync(() => api.waivers.stats(), [])
  const today = useAsync(() => api.waivers.today(), [])
  const list = useAsync(
    () => api.waivers.list({
      search: query || undefined,
      status: statusTab !== 'all' ? statusTab : undefined,
      page,
      perPage: PER_PAGE,
    }),
    [query, statusTab, page],
  )

  function refreshAll() {
    list.refresh()
    stats.refresh()
  }

  function submitSearch(e: React.FormEvent) {
    e.preventDefault()
    setPage(1)
    setQuery(search.trim())
  }

  function selectStatus(key: WaiverStatus | 'all') {
    setStatusTab(key)
    setPage(1)
  }

  const total = list.data?.total ?? 0
  const hasMore = page * PER_PAGE < total
  const items = list.data?.items ?? []
  const needsAttention = (today.data?.items ?? []).filter(b => !b.waiver_ok)

  return (
    <div>
      <PageHeader
        eyebrow="Range operations"
        title="Waivers"
        subtitle="Every signed waiver on file — Ottertext archive and new e-signatures — searchable by name, email, or phone. The single answer to “do we have a waiver for this person?”"
      />

      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
        <StatCard label="On file" value={stats.data?.on_file ?? 0} />
        <StatCard label="Current" value={stats.data?.current ?? 0} intent="success" />
        <StatCard label="Expiring in 30d" value={stats.data?.expiring_30d ?? 0} intent="warn" />
        <StatCard label="Expired" value={stats.data?.expired ?? 0} intent="danger" />
        <StatCard
          label="Bookings next 48h w/o waiver"
          value={stats.data?.upcoming_missing_48h ?? 0}
          intent={(stats.data?.upcoming_missing_48h ?? 0) > 0 ? 'warn' : 'success'}
        />
      </div>

      {needsAttention.length > 0 && (
        <Card className="mt-6">
          <h2 className="text-sm font-semibold text-ink-800">Today’s check-ins missing a waiver</h2>
          <p className="text-xs text-ink-500 mt-1">
            These bookings start today and have no waiver on file — send them the sign link before they arrive.
          </p>
          <div className="mt-3 flex flex-col gap-2">
            {needsAttention.map(b => (
              <TodayRow key={b.booking_id} booking={b} onSend={() => setSendTo(b.email)} />
            ))}
          </div>
        </Card>
      )}

      <Card className="mt-6">
        <form onSubmit={submitSearch} className="flex flex-wrap items-center gap-2">
          <input
            type="search"
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search name, email, or phone…"
            aria-label="Search waivers"
            className="flex-1 min-w-[220px] rounded-lg border px-3 py-2 text-sm bg-transparent"
            style={{ borderColor: 'var(--border-subtle)' }}
          />
          <button type="submit" className="btn-primary text-sm">Search</button>
          <button type="button" className="btn-secondary text-sm" onClick={() => setSendTo('')}>
            Send sign link…
          </button>
        </form>

        <div className="mt-3 flex flex-wrap gap-2 border-b pb-3" style={{ borderColor: 'var(--border-subtle)' }}>
          <StatusTab active={statusTab === 'all'} label="All" onClick={() => selectStatus('all')} />
          {STATUSES.map(s => (
            <StatusTab key={s.key} active={statusTab === s.key} label={s.label} onClick={() => selectStatus(s.key)} />
          ))}
        </div>

        <div className="mt-4">
          {list.loading ? (
            <Spinner label="Loading waivers…" />
          ) : list.error ? (
            <div className="text-sm rounded-lg px-3 py-2" style={{ background: 'var(--danger-soft)', color: 'var(--danger)' }}>
              {list.error}
            </div>
          ) : list.data && !list.data.active ? (
            <div className="text-sm text-ink-500">
              Memberistic’s waiver archive isn’t available on the connected site.
            </div>
          ) : items.length === 0 ? (
            <div className="text-sm text-ink-500">
              {query ? <>No waiver on file matching “{query}” — they’ll need to sign.</> : 'No waivers match these filters.'}
            </div>
          ) : (
            <div className="overflow-x-auto -mx-1 px-1">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-xs uppercase tracking-wide text-ink-500 border-b border-ink-100">
                    <th className="py-2">Person</th>
                    <th className="py-2">Signed</th>
                    <th className="py-2">Expires</th>
                    <th className="py-2">Source</th>
                    <th className="py-2">Minors</th>
                    <th className="py-2">Status</th>
                    <th className="py-2"><span className="sr-only">PDF</span></th>
                  </tr>
                </thead>
                <tbody>
                  {items.map(w => (
                    <tr
                      key={w.id}
                      className="border-b border-ink-100 last:border-0 cursor-pointer hover:bg-ink-50"
                      onClick={() => setSelectedId(w.id)}
                    >
                      <td className="py-2">
                        <div className="font-medium text-ink-800">{w.name || '(no name)'}</div>
                        <div className="text-xs text-ink-500">{w.email || w.phone || '—'}</div>
                      </td>
                      <td className="py-2 text-ink-600 whitespace-nowrap">{w.signed_at ? new Date(w.signed_at).toLocaleDateString() : '—'}</td>
                      <td className="py-2 text-ink-600 whitespace-nowrap">{w.expires_at ? new Date(w.expires_at).toLocaleDateString() : '—'}</td>
                      <td className="py-2 text-ink-600">{w.source || '—'}</td>
                      <td className="py-2 text-ink-600 max-w-[160px] truncate">{w.minor_name || '—'}</td>
                      <td className="py-2"><span className={STATUS_STYLE[w.status]}>{w.status}</span></td>
                      <td className="py-2 text-right">
                        {w.has_pdf && (
                          <a
                            href={api.waivers.pdfUrl(w.id)}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="btn-secondary text-xs"
                            aria-label={`View signed PDF for ${w.name}`}
                            onClick={e => e.stopPropagation()}
                          >
                            PDF
                          </a>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>

        {!list.loading && !list.error && (list.data?.active ?? false) && (
          <div className="mt-4 flex items-center justify-between text-xs text-ink-500">
            <span>{formatNumber(total)} total</span>
            <div className="flex items-center gap-2">
              <button className="btn-secondary text-xs" disabled={page <= 1} onClick={() => setPage(p => Math.max(1, p - 1))}>
                Prev
              </button>
              <span>Page {page}</span>
              <button className="btn-secondary text-xs" disabled={!hasMore} onClick={() => setPage(p => p + 1)}>
                Next
              </button>
            </div>
          </div>
        )}
      </Card>

      {selectedId != null && (
        <WaiverDrawer
          id={selectedId}
          onClose={() => setSelectedId(null)}
          onChanged={refreshAll}
          onSendLink={email => setSendTo(email)}
        />
      )}
      {sendTo !== null && (
        <SendLinkDialog initialEmail={sendTo} onClose={() => setSendTo(null)} />
      )}
    </div>
  )
}

function TodayRow({ booking, onSend }: { booking: WaiverTodayItem; onSend: () => void }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg px-3 py-2" style={{ background: 'var(--warn-soft)' }}>
      <div className="text-sm">
        <span className="font-medium text-ink-800">{booking.name || booking.email}</span>{' '}
        <span className="text-ink-600">
          — {new Date(booking.start_at).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })} · booking #{booking.booking_id}
        </span>
      </div>
      <button className="btn-secondary text-xs" onClick={onSend} aria-label={`Send waiver link to ${booking.email}`}>
        Send sign link
      </button>
    </div>
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

function WaiverDrawer({
  id,
  onClose,
  onChanged,
  onSendLink,
}: { id: number; onClose: () => void; onChanged: () => void; onSendLink: (email: string) => void }) {
  const detail = useAsync<WaiverDetail>(() => api.waivers.get(id), [id])
  const [busy, setBusy] = useState(false)
  const [status, setStatus] = useState<{ ok: boolean; msg: string } | null>(null)
  const dialogRef = useRef<HTMLDivElement>(null)
  useDialogA11y(dialogRef, onClose)

  const w = detail.data

  async function voidWaiver() {
    if (!w) return
    if (!window.confirm(`Void the waiver on file for ${w.name || w.email}? They will need to sign again.`)) return
    setBusy(true)
    setStatus(null)
    try {
      await api.waivers.voidWaiver(w.id)
      setStatus({ ok: true, msg: 'Waiver voided — this person must sign again.' })
      onChanged()
    } catch (err) {
      setStatus({ ok: false, msg: err instanceof Error ? err.message : String(err) })
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="fixed inset-0 z-30" style={{ backgroundColor: 'var(--bg-overlay)' }} onClick={onClose}>
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-label={w?.name || `Waiver #${id}`}
        className="absolute right-0 top-0 h-full w-full max-w-md overflow-y-auto p-5 shadow-xl"
        style={{ background: 'var(--bg-surface)' }}
        onClick={e => e.stopPropagation()}
      >
        {detail.loading ? (
          <Spinner label="Loading waiver…" />
        ) : detail.error || !w ? (
          <div className="text-sm rounded-lg px-3 py-2" style={{ background: 'var(--danger-soft)', color: 'var(--danger)' }}>
            {detail.error ?? 'Waiver not found.'}
          </div>
        ) : (
          <>
            <div className="flex items-start justify-between gap-3">
              <div>
                <h2 className="text-lg font-semibold text-ink-900">{w.name || '(no name)'}</h2>
                <div className="text-sm text-ink-500">{w.email || '—'}{w.phone ? ` · ${w.phone}` : ''}</div>
              </div>
              <button className="btn-secondary text-xs" onClick={onClose} aria-label="Close waiver detail">
                Close
              </button>
            </div>

            <div className="mt-3">
              <span className={STATUS_STYLE[w.status]}>{w.status}</span>
            </div>

            <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
              <dt className="text-ink-500">Signed</dt>
              <dd className="text-ink-800">{w.signed_at ? new Date(w.signed_at).toLocaleString() : '—'}</dd>
              <dt className="text-ink-500">Expires</dt>
              <dd className="text-ink-800">{w.expires_at ? new Date(w.expires_at).toLocaleDateString() : '—'}</dd>
              <dt className="text-ink-500">Date of birth</dt>
              <dd className="text-ink-800">{w.dob || '—'}</dd>
              <dt className="text-ink-500">Source</dt>
              <dd className="text-ink-800">{w.source || '—'}</dd>
              <dt className="text-ink-500">Emergency contact</dt>
              <dd className="text-ink-800">{w.emergency_name ? `${w.emergency_name} ${w.emergency_phone}`.trim() : '—'}</dd>
              <dt className="text-ink-500">Minors covered</dt>
              <dd className="text-ink-800">{w.minor_name || '—'}</dd>
              <dt className="text-ink-500">Member match</dt>
              <dd className="text-ink-800">{w.matched ? 'Matched to a member' : 'Not matched'}</dd>
            </dl>

            <div className="mt-5 flex flex-wrap gap-2">
              {w.has_pdf && (
                <a href={api.waivers.pdfUrl(w.id)} target="_blank" rel="noopener noreferrer" className="btn-primary text-sm">
                  View signed PDF
                </a>
              )}
              {w.email && (
                <button className="btn-secondary text-sm" onClick={() => onSendLink(w.email)}>
                  Send sign link
                </button>
              )}
              {w.is_current && (
                <button className="btn-secondary text-sm" disabled={busy} onClick={voidWaiver}>
                  {busy ? 'Voiding…' : 'Void waiver'}
                </button>
              )}
            </div>

            {status && (
              <div
                className="mt-3 text-sm rounded-lg px-3 py-2"
                style={{
                  background: status.ok ? 'var(--success-soft)' : 'var(--danger-soft)',
                  color: status.ok ? 'var(--success)' : 'var(--danger)',
                }}
              >
                {status.msg}
              </div>
            )}

            {w.history.length > 0 && (
              <div className="mt-6">
                <h3 className="text-sm font-semibold text-ink-800">Signature history</h3>
                <div className="mt-2 flex flex-col gap-2">
                  {w.history.map(h => (
                    <div key={h.signature_id} className="rounded-lg border px-3 py-2 text-xs" style={{ borderColor: 'var(--border-subtle)' }}>
                      <div className="flex items-center justify-between">
                        <span className="text-ink-800 font-medium">
                          {h.signed_at ? new Date(h.signed_at).toLocaleString() : '—'}
                        </span>
                        <span className="text-ink-500">#{h.signature_id}</span>
                      </div>
                      <div className="text-ink-500 mt-0.5">
                        {h.source}{h.version ? ` · waiver v${h.version}` : ''}{h.expires_at ? ` · valid to ${new Date(h.expires_at).toLocaleDateString()}` : ''}
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}

function SendLinkDialog({ initialEmail, onClose }: { initialEmail: string; onClose: () => void }) {
  const [email, setEmail] = useState(initialEmail)
  const [busy, setBusy] = useState(false)
  const [status, setStatus] = useState<{ ok: boolean; msg: string } | null>(null)
  const dialogRef = useRef<HTMLDivElement>(null)
  useDialogA11y(dialogRef, onClose)

  async function send(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true)
    setStatus(null)
    try {
      const res = await api.waivers.sendLink(email.trim())
      setStatus(res.sent
        ? { ok: true, msg: `Sign link sent to ${email.trim()}.` }
        : { ok: false, msg: 'The email could not be sent — check the address and the site mailer.' })
    } catch (err) {
      setStatus({ ok: false, msg: err instanceof Error ? err.message : String(err) })
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="fixed inset-0 z-40 grid place-items-center" style={{ backgroundColor: 'var(--bg-overlay)' }} onClick={onClose}>
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-label="Send waiver sign link"
        className="w-full max-w-sm rounded-xl p-5 shadow-xl"
        style={{ background: 'var(--bg-surface)' }}
        onClick={e => e.stopPropagation()}
      >
        <h2 className="text-base font-semibold text-ink-900">Send waiver sign link</h2>
        <p className="text-xs text-ink-500 mt-1">
          Members get their personal no-login link; anyone else gets the guest sign page.
        </p>
        <form onSubmit={send} className="mt-3 flex flex-col gap-3">
          <input
            type="email"
            required
            value={email}
            onChange={e => setEmail(e.target.value)}
            placeholder="person@example.com"
            aria-label="Recipient email"
            className="rounded-lg border px-3 py-2 text-sm bg-transparent"
            style={{ borderColor: 'var(--border-subtle)' }}
          />
          <div className="flex items-center justify-end gap-2">
            <button type="button" className="btn-secondary text-sm" onClick={onClose}>Cancel</button>
            <button type="submit" className="btn-primary text-sm" disabled={busy || !email.trim()}>
              {busy ? 'Sending…' : 'Send link'}
            </button>
          </div>
        </form>
        {status && (
          <div
            className="mt-3 text-sm rounded-lg px-3 py-2"
            style={{
              background: status.ok ? 'var(--success-soft)' : 'var(--danger-soft)',
              color: status.ok ? 'var(--success)' : 'var(--danger)',
            }}
          >
            {status.msg}
          </div>
        )}
      </div>
    </div>
  )
}
