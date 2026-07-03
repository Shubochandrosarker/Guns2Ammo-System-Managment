import { useCallback, useEffect, useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { api, type Task } from '@/lib/api'
import { cn } from '@/lib/cn'

type StatusFilter = 'open' | 'all'

const SOURCE_STYLE: Record<Task['source'], string> = {
  ai_insight:  'pill-blue',
  business_gap:'pill-amber',
  bridgistic:  'pill-green',
  manual:      'pill-gray',
}

const STATUS_STYLE: Record<Task['status'], string> = {
  open:      'pill-amber',
  done:      'pill-green',
  dismissed: 'pill-gray',
}

export function Tasks() {
  const [status, setStatus] = useState<StatusFilter>('open')
  const [tasks, setTasks] = useState<Task[] | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const refresh = useCallback(async () => {
    try {
      setTasks(await api.tasks.list(status))
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    }
  }, [status])

  useEffect(() => {
    void refresh()
  }, [refresh])

  async function act(taskId: string, kind: 'resolve' | 'dismiss') {
    setBusy(true)
    setError(null)
    try {
      if (kind === 'resolve') await api.tasks.resolve(taskId)
      else                    await api.tasks.dismiss(taskId)
      await refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : String(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      <PageHeader
        eyebrow="Work queue"
        title="Tasks"
        subtitle="Every AI-insight approval, business-gap task, and BridGistic-created work item lands here."
        actions={
          <a
            href={api.exports.tasksCsvUrl()}
            className="btn-secondary text-sm"
            download
          >
            Download CSV
          </a>
        }
      />

      <div className="mb-4 flex gap-2 border-b" style={{ borderColor: 'var(--border-subtle)' }}>
        {(['open', 'all'] as StatusFilter[]).map(s => (
          <button
            key={s}
            onClick={() => setStatus(s)}
            className={cn(
              'px-3 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
              status === s ? 'border-brand-500' : 'border-transparent',
            )}
            style={{
              color: status === s ? 'var(--brand)' : 'var(--text-muted)',
              borderBottomColor: status === s ? 'var(--brand)' : 'transparent',
            }}
          >
            {s === 'open' ? 'Open' : 'All'}
          </button>
        ))}
      </div>

      <Card>
        {error && (
          <div className="mb-3 text-sm rounded-lg px-3 py-2"
               style={{ background: 'var(--danger-soft)', color: 'var(--danger)' }}>
            {error}
          </div>
        )}
        {tasks === null ? (
          <Spinner label="Loading tasks…" />
        ) : tasks.length === 0 ? (
          <div className="text-sm" style={{ color: 'var(--text-muted)' }}>
            {status === 'open'
              ? 'No open tasks. Approve an AI insight or click "Create task" on a business gap to seed the queue.'
              : 'No tasks yet.'}
          </div>
        ) : (
          <ul className="divide-y" style={{ borderColor: 'var(--border-subtle)' }}>
            {tasks.map(t => (
              <li key={t.id} className="py-3">
                <div className="flex items-start gap-2 flex-wrap">
                  <span className={SOURCE_STYLE[t.source]}>{t.source.replace('_', ' ')}</span>
                  <span className={STATUS_STYLE[t.status]}>{t.status}</span>
                  <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                    created {new Date(t.createdAt).toLocaleString()}
                  </span>
                </div>
                <div className="mt-2 font-medium" style={{ color: 'var(--text-primary)' }}>
                  {t.title}
                </div>
                {t.body && (
                  <pre
                    className="mt-1 text-xs font-mono whitespace-pre-wrap"
                    style={{ color: 'var(--text-secondary)' }}
                  >
                    {t.body}
                  </pre>
                )}
                {t.status === 'open' && (
                  <div className="mt-3 flex items-center gap-2">
                    <button
                      className="btn-primary text-xs"
                      disabled={busy}
                      onClick={() => void act(t.id, 'resolve')}
                    >
                      Mark done
                    </button>
                    <button
                      className="btn-secondary text-xs"
                      disabled={busy}
                      onClick={() => void act(t.id, 'dismiss')}
                    >
                      Dismiss
                    </button>
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
