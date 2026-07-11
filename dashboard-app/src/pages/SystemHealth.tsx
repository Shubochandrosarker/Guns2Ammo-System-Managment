import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'
import { Spinner } from '@/components/ui/Spinner'
import { useAsync } from '@/lib/hooks'
import { api } from '@/lib/api'
import type { IntegrationsStatus, SystemHealthCheck } from '@/types/analytics'
import { formatBytes } from '@/lib/format'

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

// Integrations that need explicit configuration link to the Settings page;
// the rest are plugins that are simply active or not.
const INTEGRATION_ITEMS: Array<{ key: keyof IntegrationsStatus; label: string; kind: 'configured' | 'active' }> = [
  { key: 'ga4Configured',          label: 'Google Analytics 4',      kind: 'configured' },
  { key: 'gscConfigured',          label: 'Google Search Console',   kind: 'configured' },
  { key: 'gbpConfigured',          label: 'Google Business Profile', kind: 'configured' },
  { key: 'aiConnectionConfigured', label: 'AI model connection',     kind: 'configured' },
  { key: 'wooActive',              label: 'WooCommerce',             kind: 'active' },
  { key: 'bookingEngineActive',    label: 'G2A Booking Engine',      kind: 'active' },
  { key: 'memberisticActive',      label: 'Memberistic',             kind: 'active' },
  { key: 'messageisticActive',     label: 'Messageistic',            kind: 'active' },
  { key: 'formisticActive',        label: 'Formistic',               kind: 'active' },
]

// Detected-only third-party plugin cards. Each entry's `when` reads a
// `detected.*` flag from GET /system/namespaces — a card only renders when
// the corresponding namespace is genuinely registered on the live install.
// We never show a "not detected" placeholder for plugins we aren't sure
// exist (Rank Math / Redirection / Elementor are not part of this repo).
const THIRD_PARTY_CARDS: Array<{
  key: 'rankMath' | 'redirection' | 'elementor'
  label: string
  namespaceHint: string
}> = [
  { key: 'rankMath',    label: 'Rank Math',   namespaceHint: 'rankmath/v1' },
  { key: 'redirection', label: 'Redirection', namespaceHint: 'redirection/v1' },
  { key: 'elementor',   label: 'Elementor',   namespaceHint: 'elementor*' },
]

export function SystemHealth() {
  const q = useAsync(() => api.health.checks(), [])
  const integrations = useAsync(() => api.system.integrations(), [])
  const namespaces = useAsync(() => api.system.namespaces(), [])
  const siteHealth = useAsync(() => api.system.siteHealth(), [])
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

      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        <StatCard label="Healthy" value={ok}    intent="success" />
        <StatCard label="Warnings" value={warn}  intent="warn" />
        <StatCard label="Errors"   value={error} intent="danger" />
        <StatCard label="Checks"   value={checks.length} />
      </div>

      <div className="mt-6">
        <Card
          title="Integrations"
          subtitle="What the dashboard can actually read right now"
        >
          {integrations.loading ? (
            <Spinner label="Checking integrations…" />
          ) : integrations.error ? (
            <div className="text-sm text-rose-700 bg-rose-50 border border-rose-100 rounded-lg px-3 py-2">
              {integrations.error}
            </div>
          ) : (
            <ul className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 divide-y sm:divide-y-0 divide-ink-100">
              {INTEGRATION_ITEMS.map(item => {
                const on = Boolean(integrations.data?.[item.key])
                return (
                  <li key={item.key} className="py-2.5 flex items-center justify-between gap-3">
                    <span className="text-sm text-ink-700">{item.label}</span>
                    <span className={on ? 'pill-green' : 'pill-amber'}>
                      {on
                        ? item.kind === 'configured' ? 'configured' : 'active'
                        : item.kind === 'configured' ? 'not configured' : 'not active'}
                    </span>
                  </li>
                )
              })}
            </ul>
          )}
          {integrations.data && !(integrations.data.ga4Configured && integrations.data.gscConfigured) && (
            <div className="mt-3 text-xs text-ink-500">
              Google integrations that show &quot;not configured&quot; report zeros on the
              analytics pages until a service-account key and property are set in
              the WordPress admin (G2A Business API → Settings).
            </div>
          )}
        </Card>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">
        <Card title="Site Health" subtitle="WordPress core's own Site Health tests, summarized">
          {siteHealth.loading ? (
            <Spinner label="Running site health checks…" />
          ) : siteHealth.error ? (
            <div className="text-sm rounded-lg px-3 py-2" style={{ background: 'var(--danger-soft)', color: 'var(--danger)' }}>
              {siteHealth.error}
            </div>
          ) : siteHealth.data ? (
            <div className="space-y-3">
              <div className="grid grid-cols-2 gap-3">
                <StatCard label="Critical issues" value={siteHealth.data.criticalIssues} intent={siteHealth.data.criticalIssues > 0 ? 'danger' : 'success'} />
                <StatCard label="Recommended" value={siteHealth.data.recommendedImprovements} intent={siteHealth.data.recommendedImprovements > 0 ? 'warn' : 'success'} />
              </div>
              <ul className="text-sm divide-y divide-ink-100">
                <Row label="WordPress" value={siteHealth.data.wpVersion} />
                <Row label="PHP" value={siteHealth.data.phpVersion} />
                <Row label="Active plugins" value={String(siteHealth.data.activePluginCount)} />
                <Row label="WP_DEBUG" value={siteHealth.data.debugMode ? 'on' : 'off'} />
                <Row label="Display errors" value={siteHealth.data.displayErrors ? 'on' : 'off'} />
                {siteHealth.data.diskFreeBytes != null && (
                  <Row label="Disk free" value={formatBytes(siteHealth.data.diskFreeBytes)} />
                )}
              </ul>
              {siteHealth.data.degraded && (
                <div className="text-xs text-ink-500">
                  Core Site Health tests weren&apos;t reachable in-process — this is a
                  constants-only summary rather than the full test suite.
                </div>
              )}
            </div>
          ) : null}
        </Card>

        <Card title="Active REST Namespaces" subtitle="Every REST namespace actually registered on this install">
          {namespaces.loading ? (
            <Spinner label="Discovering namespaces…" />
          ) : namespaces.error ? (
            <div className="text-sm rounded-lg px-3 py-2" style={{ background: 'var(--danger-soft)', color: 'var(--danger)' }}>
              {namespaces.error}
            </div>
          ) : (
            <ul className="flex flex-wrap gap-1.5">
              {(namespaces.data?.namespaces ?? []).map(ns => (
                <li key={ns} className="pill-gray font-mono text-xs">{ns}</li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      {namespaces.data && THIRD_PARTY_CARDS.some(c => namespaces.data!.detected[c.key]) && (
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 mt-6">
          {THIRD_PARTY_CARDS.filter(c => namespaces.data!.detected[c.key]).map(c => (
            <Card key={c.key} title={c.label} subtitle={c.namespaceHint}>
              <span className="pill-green">detected</span>
            </Card>
          ))}
        </div>
      )}

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

function Row({ label, value }: { label: string; value: string }) {
  return (
    <li className="py-1.5 flex items-center justify-between gap-3">
      <span className="text-ink-500">{label}</span>
      <span className="text-ink-800 font-medium">{value}</span>
    </li>
  )
}
