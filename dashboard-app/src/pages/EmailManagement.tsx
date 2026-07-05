import { useCallback, useEffect, useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'
import { Spinner } from '@/components/ui/Spinner'
import { api, type EmailDraft } from '@/lib/api'
import { env } from '@/lib/env'
import { useAsync } from '@/lib/hooks'
import { formatNumber } from '@/lib/format'
import type { EmailOverviewSubmission } from '@/types/analytics'

const STATUS_PILL: Record<EmailOverviewSubmission['status'], string> = {
  new:     'pill-amber',
  read:    'pill-gray',
  replied: 'pill-green',
}

function wpAdminUrl(path: string): string {
  return `${env.apiBase.replace(/\/$/, '')}/wp-admin/${path}`
}

export function EmailManagement() {
  const overview = useAsync(() => api.emails.overview(), [])

  const [drafts, setDrafts] = useState<EmailDraft[] | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [inputs, setInputs] = useState<Record<string, string>>({})

  const refresh = useCallback(async () => {
    try {
      const list = await api.emailDrafts.list('pending')
      setDrafts(list)
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    }
  }, [])

  useEffect(() => {
    void refresh()
  }, [refresh])

  async function send(id: string) {
    setBusy(true)
    setError(null)
    try {
      await api.emailDrafts.send(id, { to: inputs[id] || '' })
      await refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  async function discard(id: string) {
    setBusy(true)
    setError(null)
    try {
      await api.emailDrafts.discard(id)
      await refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  const ov = overview.data
  const formistic = ov?.formistic
  const subscribers = ov?.subscribers
  const messageistic = ov?.messageistic

  return (
    <div>
      <PageHeader
        eyebrow="Inbox intelligence"
        title="Email Management"
        subtitle="Live Formistic enquiries, newsletter growth, SMS traffic, and BridGistic-generated drafts. Nothing goes out until you click Send."
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label="New enquiries today"
          value={formistic?.newToday ?? 0}
          intent="warn"
          sublabel={formistic?.active === false ? 'Formistic not active' : undefined}
        />
        <StatCard
          label="Enquiries last 7d"
          value={formistic?.last7d ?? 0}
          sublabel={formistic?.active ? `${formatNumber(formistic.totalSubmissions ?? 0)} all-time` : undefined}
        />
        <StatCard label="AI drafted" value={drafts?.length ?? 0} intent="success" />
        <StatCard
          label="Awaiting reply"
          value={(formistic?.byStatus?.new ?? 0) + (formistic?.byStatus?.read ?? 0)}
          intent="danger"
          sublabel={formistic?.byStatus ? `${formatNumber(formistic.byStatus.replied)} replied` : undefined}
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-6">
        <div className="lg:col-span-1 space-y-4">
          <Card
            title="Formistic inbox"
            subtitle="Contact-form enquiries"
            actions={
              <a
                className="btn-ghost text-xs"
                href={wpAdminUrl('admin.php?page=formistic')}
                target="_blank"
                rel="noreferrer"
              >
                Open in WP admin →
              </a>
            }
          >
            {overview.loading ? (
              <Spinner label="Loading inbox…" />
            ) : !formistic?.active ? (
              <div className="text-sm text-ink-500">
                Formistic is not active on the WordPress site, so there is no
                enquiry inbox to show.
              </div>
            ) : (
              <div className="grid grid-cols-3 gap-2 text-xs">
                <InboxChip label="New"     value={formistic.byStatus?.new ?? 0}     pill="pill-amber" />
                <InboxChip label="Read"    value={formistic.byStatus?.read ?? 0}    pill="pill-gray" />
                <InboxChip label="Replied" value={formistic.byStatus?.replied ?? 0} pill="pill-green" />
              </div>
            )}
          </Card>

          <Card title="Newsletter subscribers" subtitle="Kept separate from the enquiry inbox">
            {overview.loading ? (
              <Spinner label="Loading…" />
            ) : !subscribers?.active ? (
              <div className="text-sm text-ink-500">
                Newsletter capture is not active — the Formistic subscribers
                table was not found.
              </div>
            ) : (
              <div className="flex items-end justify-between gap-4">
                <div>
                  <div className="text-2xl font-semibold text-ink-800">
                    {formatNumber(subscribers.total ?? 0)}
                  </div>
                  <div className="text-xs text-ink-500">active subscribers</div>
                </div>
                <div className="text-right">
                  <div className="text-base font-semibold text-emerald-600">
                    +{formatNumber(subscribers.last30d ?? 0)}
                  </div>
                  <div className="text-xs text-ink-500">last 30 days</div>
                </div>
              </div>
            )}
          </Card>

          <Card title="Messageistic SMS" subtitle="Text-message traffic">
            {overview.loading ? (
              <Spinner label="Loading…" />
            ) : !messageistic?.active ? (
              <div className="text-sm text-ink-500">
                Messageistic is not active on the WordPress site.
              </div>
            ) : !messageistic.stats ? (
              <div className="text-sm text-ink-500">
                Messageistic is active but returned no stats.
              </div>
            ) : (
              <div className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                <SmsStat label="Sent"       value={messageistic.stats.totalSent} />
                <SmsStat label="Delivered"  value={messageistic.stats.delivered} />
                <SmsStat label="Failed"     value={messageistic.stats.failed} />
                <SmsStat label="Replies"    value={messageistic.stats.replies} />
                <SmsStat label="Contacts"   value={messageistic.stats.contacts} />
                <SmsStat label="Opted out"  value={messageistic.stats.optedOut} />
                <SmsStat label="Active campaigns" value={messageistic.stats.activeCampaigns} />
              </div>
            )}
          </Card>
        </div>

        <div className="lg:col-span-2 space-y-4">
          <Card
            title="Pending owner approval"
            subtitle="BridGistic drafts ready to send"
          >
            {error && (
              <div className="mb-3 text-sm text-rose-700 bg-rose-50 border border-rose-100 rounded-lg px-3 py-2">
                {error}
              </div>
            )}

            {drafts === null ? (
              <Spinner label="Loading drafts…" />
            ) : drafts.length === 0 ? (
              <div className="text-sm text-ink-500">
                No pending drafts. When BridGistic classifies a request as
                &quot;action&quot; and you approve it, the draft lands here.
              </div>
            ) : (
              <ul className="divide-y divide-ink-100">
                {drafts.map(d => (
                  <li key={d.id} className="py-3">
                    <div className="flex items-start gap-3">
                      <span className="pill-amber">draft</span>
                      <div className="min-w-0 flex-1">
                        <div className="text-sm font-medium text-ink-800 truncate">{d.subject}</div>
                        <div className="text-xs text-ink-500">from BridGistic · {new Date(d.createdAt).toLocaleString()}</div>
                      </div>
                    </div>
                    <div className="mt-2 rounded-lg bg-ink-50 border border-ink-100 p-3 text-sm text-ink-700 whitespace-pre-wrap">
                      {d.body}
                    </div>
                    <div className="mt-3 flex flex-col md:flex-row md:items-center gap-2">
                      <input
                        className="input md:flex-1"
                        type="email"
                        placeholder="recipient@example.com"
                        value={inputs[d.id] ?? d.to ?? ''}
                        onChange={e => setInputs(prev => ({ ...prev, [d.id]: e.target.value }))}
                      />
                      <div className="flex items-center gap-2 shrink-0">
                        <button className="btn-secondary text-xs" disabled={busy} onClick={() => void discard(d.id)}>
                          Discard
                        </button>
                        <button className="btn-primary text-xs" disabled={busy} onClick={() => void send(d.id)}>
                          Send
                        </button>
                      </div>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card
            title="Recent enquiries"
            subtitle="Latest Formistic submissions — reply from WP admin"
          >
            {overview.loading ? (
              <Spinner label="Loading enquiries…" />
            ) : overview.error ? (
              <div className="text-sm text-rose-700 bg-rose-50 border border-rose-100 rounded-lg px-3 py-2">
                {overview.error}
              </div>
            ) : !formistic?.active ? (
              <div className="text-sm text-ink-500">Formistic is not active — nothing to show.</div>
            ) : formistic.recentSubmissions.length === 0 ? (
              <div className="text-sm text-ink-500">No enquiries yet.</div>
            ) : (
              <ul className="divide-y divide-ink-100">
                {formistic.recentSubmissions.map(s => (
                  <li key={s.id} className="py-3">
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <div className="text-sm font-medium text-ink-800 truncate">
                          {s.subject || '(no subject)'}
                        </div>
                        <div className="text-xs text-ink-500 truncate">
                          {s.name || s.email} · {s.formName} · {new Date(s.createdAt).toLocaleString()}
                        </div>
                      </div>
                      <span className={STATUS_PILL[s.status]}>{s.status}</span>
                    </div>
                    {s.messageExcerpt && (
                      <div className="mt-1.5 text-xs text-ink-600 line-clamp-2">{s.messageExcerpt}</div>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      </div>
    </div>
  )
}

function InboxChip({ label, value, pill }: { label: string; value: number; pill: string }) {
  return (
    <div className="flex items-center gap-2">
      <span className={pill}>{formatNumber(value)}</span>
      <span className="text-ink-500">{label}</span>
    </div>
  )
}

function SmsStat({ label, value }: { label: string; value: number }) {
  return (
    <div className="flex items-center justify-between gap-2">
      <span className="text-ink-500">{label}</span>
      <span className="font-medium text-ink-800">{formatNumber(value)}</span>
    </div>
  )
}
