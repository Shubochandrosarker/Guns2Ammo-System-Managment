import { useRef, useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { EmptyState } from '@/components/ui/EmptyState'
import { useAsync, useDialogA11y } from '@/lib/hooks'
import { api, type ReportDefinition, type ReportDelivery } from '@/lib/api'
import { cn } from '@/lib/cn'

type DrawerState = {
  report: ReportDefinition
  delivery: ReportDelivery | null
  loading: boolean
  error: string | null
}

export function Reports() {
  const q = useAsync(() => api.reports.list(), [])
  const [reports, setReports] = useState<ReportDefinition[] | null>(null)
  const [drawer, setDrawer] = useState<DrawerState | null>(null)
  const [running, setRunning] = useState<string | null>(null)

  const rows = reports ?? q.data ?? []

  if (q.loading && rows.length === 0) return <Spinner />

  async function runNow(r: ReportDefinition) {
    setRunning(r.id)
    try {
      const delivery = await api.reports.runNow(r.id)
      setReports(rows.map(x => (x.id === r.id ? { ...x, lastDeliveredAt: delivery.generatedAt, hasLatest: true } : x)))
      setDrawer({ report: r, delivery, loading: false, error: null })
    } catch (err) {
      setDrawer({ report: r, delivery: null, loading: false, error: err instanceof Error ? err.message : String(err) })
    } finally {
      setRunning(null)
    }
  }

  async function openLatest(r: ReportDefinition) {
    setDrawer({ report: r, delivery: null, loading: true, error: null })
    try {
      const delivery = await api.reports.latest(r.id)
      setDrawer({ report: r, delivery, loading: false, error: null })
    } catch (err) {
      setDrawer({ report: r, delivery: null, loading: false, error: err instanceof Error ? err.message : String(err) })
    }
  }

  return (
    <div>
      <PageHeader
        eyebrow="Business reporting"
        title="Reports"
        subtitle="Scheduled and on-demand reports. Run now regenerates + persists the latest; Open latest reads the last delivery."
      />

      {rows.length === 0 ? (
        <EmptyState
          title="No reports registered yet"
          hint="The Reports_Store seeds a built-in catalog on first read. If you're seeing this, the plugin needs an activation refresh."
        />
      ) : (
        <Card bodyClassName="p-0">
          <div className="overflow-x-auto -mx-1 px-1">
            <table className="w-full text-sm">
              <thead className="bg-ink-50 text-xs uppercase tracking-wide text-ink-500">
                <tr>
                  <th className="text-left px-4 py-2">Report</th>
                  <th className="text-left px-4 py-2">Schedule</th>
                  <th className="text-left px-4 py-2">Last delivered</th>
                  <th className="text-right px-4 py-2"></th>
                </tr>
              </thead>
              <tbody>
                {rows.map(r => (
                  <tr key={r.id} className="border-t border-ink-100 align-top">
                    <td className="px-4 py-3">
                      <div className="font-medium text-ink-800">{r.label}</div>
                      <div className="text-xs text-ink-500 mt-0.5 max-w-xl">{r.description}</div>
                    </td>
                    <td className="px-4 py-3 text-ink-600 whitespace-nowrap">{r.schedule}</td>
                    <td className="px-4 py-3 text-ink-600 whitespace-nowrap">
                      {r.lastDeliveredAt
                        ? new Date(r.lastDeliveredAt).toLocaleString()
                        : <span className="text-ink-400">Never</span>}
                    </td>
                    <td className="px-4 py-3 text-right space-x-2 whitespace-nowrap">
                      <button
                        className="btn-ghost text-xs"
                        onClick={() => void runNow(r)}
                        disabled={running === r.id}
                      >
                        {running === r.id ? 'Running…' : 'Run now'}
                      </button>
                      <button
                        className="btn-secondary text-xs"
                        onClick={() => void openLatest(r)}
                        disabled={!r.hasLatest}
                      >
                        Open latest
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {drawer && <DeliveryDrawer state={drawer} onClose={() => setDrawer(null)} />}
    </div>
  )
}

function DeliveryDrawer({ state, onClose }: { state: DrawerState; onClose: () => void }) {
  const { report, delivery, loading, error } = state
  const dialogRef = useRef<HTMLDivElement>(null)
  useDialogA11y(dialogRef, onClose)

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
        aria-label={`Latest delivery — ${report.label}`}
        tabIndex={-1}
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
            <div className="text-[11px] uppercase tracking-wide text-ink-500">Latest delivery</div>
            <div className="font-semibold text-ink-800">{report.label}</div>
          </div>
          <button onClick={onClose} className="btn-ghost !px-2">✕</button>
        </div>

        <div className="p-4 sm:p-5">
          {loading && <Spinner label="Loading delivery…" />}

          {error && (
            <div className="text-sm rounded-lg px-3 py-2 bg-rose-50 text-rose-800 border border-rose-100">
              {error}
            </div>
          )}

          {delivery && (
            <>
              <div className="flex items-start justify-between gap-3 mb-3">
                <div className="text-xs text-ink-500">
                  Generated {new Date(delivery.generatedAt).toLocaleString()}
                  {delivery.range?.from && (
                    <> · Range {delivery.range.from} → {delivery.range.to}</>
                  )}
                </div>
                <a
                  href={api.exports.reportTxtUrl(report.id)}
                  className="btn-ghost text-xs shrink-0"
                  download
                >
                  Download .txt
                </a>
              </div>
              <pre className="font-mono text-xs text-ink-800 whitespace-pre-wrap rounded-lg border border-ink-100 p-3 max-h-[70vh] overflow-y-auto">
{delivery.body}
              </pre>
            </>
          )}
        </div>
      </div>
    </div>
  )
}
