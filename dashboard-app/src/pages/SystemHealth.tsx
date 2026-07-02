import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'
import { Spinner } from '@/components/ui/Spinner'
import { useAsync } from '@/lib/hooks'
import { api } from '@/lib/api'
import type { SystemHealthCheck } from '@/types/analytics'

const STATUS_STYLE: Record<SystemHealthCheck['status'], string> = {
  ok:    'pill-green',
  warn:  'pill-amber',
  error: 'pill-red',
}

const GROUP_LABEL: Record<SystemHealthCheck['group'], string> = {
  plugins:   'Plugins',
  apis:      'APIs',
  cron:      'Cron & jobs',
  jobs:      'Background jobs',
  webhooks:  'Webhooks',
  messaging: 'Messaging',
  ai:        'AI models',
  security:  'Security',
}

export function SystemHealth() {
  const q = useAsync(() => api.health.checks(), [])
  if (q.loading) return <Spinner />
  const checks = q.data ?? []
  const grouped = new Map<SystemHealthCheck['group'], SystemHealthCheck[]>()
  for (const c of checks) {
    if (!grouped.has(c.group)) grouped.set(c.group, [])
    grouped.get(c.group)!.push(c)
  }

  const ok    = checks.filter(c => c.status === 'ok').length
  const warn  = checks.filter(c => c.status === 'warn').length
  const error = checks.filter(c => c.status === 'error').length

  return (
    <div>
      <PageHeader
        eyebrow="Operational health"
        title="System Health"
        subtitle="Plugins, APIs, cron, webhooks, messaging, AI, and security — everything the dashboard depends on."
        actions={<button className="btn-secondary text-sm">Re-run checks</button>}
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Healthy" value={ok}    intent="success" />
        <StatCard label="Warnings" value={warn}  intent="warn" />
        <StatCard label="Errors"   value={error} intent="danger" />
        <StatCard label="Checks"   value={checks.length} />
      </div>

      <div className="space-y-4 mt-6">
        {Array.from(grouped.entries()).map(([group, list]) => (
          <Card key={group} title={GROUP_LABEL[group]} subtitle={`${list.length} check${list.length === 1 ? '' : 's'}`}>
            <ul className="divide-y divide-ink-100">
              {list.map(c => (
                <li key={c.id} className="py-2.5 flex items-start justify-between gap-4">
                  <div className="min-w-0">
                    <div className="font-medium text-ink-800">{c.label}</div>
                    <div className="text-xs text-ink-500 truncate">{c.detail}</div>
                  </div>
                  <span className={STATUS_STYLE[c.status]}>{c.status}</span>
                </li>
              ))}
            </ul>
          </Card>
        ))}
      </div>
    </div>
  )
}
