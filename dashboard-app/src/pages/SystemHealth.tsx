import type { ReactNode } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'
import { Spinner } from '@/components/ui/Spinner'
import { Skeleton } from '@/components/ui/Skeleton'
import { ErrorState } from '@/components/ui/ErrorState'
import { EmptyState } from '@/components/ui/EmptyState'
import { useAsync } from '@/lib/hooks'
import { api } from '@/lib/api'
import type { IntegrationsStatus } from '@/types/analytics'
import { formatBytes, formatDateTimeInZone, formatNumber } from '@/lib/format'

// Integrations that need explicit configuration link to the Settings page;
// the rest are plugins that are simply active or not. (Legacy
// /system/integrations endpoint — migrates in a later phase.)
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

// Detected-only third-party plugin cards. Each entry reads a `detected.*`
// flag from GET /system/namespaces — a card only renders when the
// corresponding namespace is genuinely registered on the live install.
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
  // NEW canonical endpoint: GET /system/health (envelope with plugins, cron,
  // sessions, audit, integrations). meta.timezone drives all datetimes.
  const health = useAsync(() => api.system.health(), [])
  // Legacy endpoints the Phase-C contract does NOT cover — untouched plumbing.
  const integrations = useAsync(() => api.system.integrations(), [])
  const namespaces = useAsync(() => api.system.namespaces(), [])
  const siteHealth = useAsync(() => api.system.siteHealth(), [])

  function rerunChecks() {
    health.refresh()
    integrations.refresh()
    namespaces.refresh()
    siteHealth.refresh()
  }

  const h = health.data // Enveloped<SystemHealthData> | null
  const tz = h?.meta.timezone ?? 'UTC'
  const activePlugins = h ? h.data.plugins.filter(p => p.active).length : 0
  const inactivePlugins = h ? h.data.plugins.length - activePlugins : 0

  return (
    <div>
      <PageHeader
        eyebrow="Operational health"
        title="System Health"
        subtitle="Plugins, scheduled jobs, dashboard sessions, audit trail, and payment/store integrations — everything the dashboard depends on."
        actions={<button className="btn-secondary text-sm" onClick={rerunChecks}>Re-run checks</button>}
      />

      {/* Freshness banner from the envelope meta */}
      {h && h.meta.freshness.status !== 'fresh' && (
        <div
          role="status"
          className="mb-4 text-sm rounded-lg px-3 py-2 flex flex-wrap items-center gap-2"
          style={{ background: 'var(--warn-soft)', color: 'var(--warn)' }}
        >
          <span className="pill-amber">{h.meta.freshness.status === 'stale' ? 'Stale' : 'Unavailable'}</span>
          <span>
            {h.meta.freshness.status === 'stale'
              ? 'This health report may be out of date'
              : 'The health data source is currently unavailable'}
            {h.meta.freshness.lastUpdatedAt
              ? ` — last updated ${formatDateTimeInZone(h.meta.freshness.lastUpdatedAt, tz)}.`
              : '.'}
          </span>
        </div>
      )}

      {/* Summary tiles */}
      {health.loading ? (
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4" aria-busy="true">
          {[0, 1, 2, 3].map(i => (
            <div key={i} className="card">
              <div className="card-body space-y-3">
                <Skeleton className="h-3 w-24" />
                <Skeleton className="h-7 w-16" />
              </div>
            </div>
          ))}
        </div>
      ) : health.error || !h ? (
        <ErrorState
          message={`Couldn't load the system health report: ${health.error ?? 'Unknown error'}`}
          onRetry={health.refresh}
        />
      ) : (
        <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
          <StatCard label="Active plugins"   value={activePlugins} intent="success" sublabel={`of ${h.data.plugins.length} tracked`} />
          <StatCard label="Inactive plugins" value={inactivePlugins} intent={inactivePlugins > 0 ? 'warn' : 'success'} />
          <StatCard
            label="Active sessions"
            value={h.data.sessions.activeCount}
            sublabel={h.data.sessions.table ? 'sessions table present' : 'sessions table MISSING'}
            intent={h.data.sessions.table ? 'default' : 'danger'}
          />
          <StatCard
            label="Audit failures (24h)"
            value={h.data.audit.recentFailures24h}
            intent={h.data.audit.recentFailures24h > 0 ? 'danger' : 'success'}
          />
        </div>
      )}

      {/* Plugins table + cron schedule — canonical /system/health payload */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">
        <Card title="Plugins" subtitle="Tracked plugins with active state and version">
          {health.loading ? (
            <div className="space-y-2" aria-busy="true">
              <Skeleton className="h-4 w-full" />
              <Skeleton className="h-4 w-full" />
              <Skeleton className="h-4 w-3/4" />
            </div>
          ) : health.error || !h ? (
            <ErrorState message={health.error ?? 'Unknown error'} onRetry={health.refresh} />
          ) : h.data.plugins.length === 0 ? (
            <EmptyState title="No plugins reported" hint="The health endpoint returned an empty plugin list." />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-xs uppercase tracking-wide text-ink-500">
                    <th className="py-2 pr-3 font-medium">Plugin</th>
                    <th className="py-2 pr-3 font-medium">Version</th>
                    <th className="py-2 font-medium">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-ink-100">
                  {h.data.plugins.map(p => (
                    <tr key={p.slug}>
                      <td className="py-2 pr-3">
                        <div className="font-medium text-ink-800">{p.name}</div>
                        <div className="text-xs text-ink-500 font-mono">{p.slug}</div>
                      </td>
                      <td className="py-2 pr-3 text-ink-700 font-mono text-xs">{p.version}</td>
                      <td className="py-2">
                        <span className={p.active ? 'pill-green' : 'pill-amber'}>
                          {p.active ? 'active' : 'inactive'}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>

        <Card title="Scheduled jobs" subtitle={h ? `Cron hooks — next runs shown in ${tz}` : 'Cron hooks'}>
          {health.loading ? (
            <div className="space-y-2" aria-busy="true">
              <Skeleton className="h-4 w-full" />
              <Skeleton className="h-4 w-full" />
              <Skeleton className="h-4 w-2/3" />
            </div>
          ) : health.error || !h ? (
            <ErrorState message={health.error ?? 'Unknown error'} onRetry={health.refresh} />
          ) : h.data.cron.length === 0 ? (
            <EmptyState title="No cron hooks reported" hint="Nothing is scheduled — automations may be disabled." />
          ) : (
            <ul className="divide-y divide-ink-100">
              {h.data.cron.map(job => (
                <li key={job.hook} className="py-2.5 flex items-center justify-between gap-4">
                  <span className="font-mono text-xs text-ink-700 truncate">{job.hook}</span>
                  {job.nextRunAt ? (
                    <span className="text-sm text-ink-800 shrink-0">{formatDateTimeInZone(job.nextRunAt, tz)}</span>
                  ) : (
                    <span className="pill-amber shrink-0">not scheduled</span>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      {/* Sessions / audit / integrations tiles — canonical /system/health payload */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-6">
        <Card title="Dashboard sessions" subtitle="Cookie-session store">
          {health.loading ? (
            <Skeleton className="h-16 w-full" />
          ) : health.error || !h ? (
            <ErrorState message={health.error ?? 'Unknown error'} onRetry={health.refresh} />
          ) : (
            <ul className="text-sm divide-y divide-ink-100">
              <Row
                label="Sessions table"
                value={
                  <span className={h.data.sessions.table ? 'pill-green' : 'pill-red'}>
                    {h.data.sessions.table ? 'present' : 'missing'}
                  </span>
                }
              />
              <Row label="Active sessions" value={formatNumber(h.data.sessions.activeCount)} />
            </ul>
          )}
        </Card>

        <Card title="Audit trail" subtitle="Security-relevant failures">
          {health.loading ? (
            <Skeleton className="h-16 w-full" />
          ) : health.error || !h ? (
            <ErrorState message={health.error ?? 'Unknown error'} onRetry={health.refresh} />
          ) : (
            <ul className="text-sm divide-y divide-ink-100">
              <Row
                label="Failures, last 24h"
                value={
                  <span className={h.data.audit.recentFailures24h > 0 ? 'pill-red' : 'pill-green'}>
                    {formatNumber(h.data.audit.recentFailures24h)}
                  </span>
                }
              />
            </ul>
          )}
        </Card>

        <Card title="Payments &amp; store" subtitle="Core commerce integrations">
          {health.loading ? (
            <Skeleton className="h-16 w-full" />
          ) : health.error || !h ? (
            <ErrorState message={health.error ?? 'Unknown error'} onRetry={health.refresh} />
          ) : (
            <ul className="text-sm divide-y divide-ink-100">
              <Row
                label="Stripe"
                value={
                  <span className={h.data.integrations.stripe.configured ? 'pill-green' : 'pill-amber'}>
                    {h.data.integrations.stripe.configured ? 'configured' : 'not configured'}
                  </span>
                }
              />
              <Row
                label="WooCommerce"
                value={
                  <span className={h.data.integrations.woo.active ? 'pill-green' : 'pill-amber'}>
                    {h.data.integrations.woo.active ? 'active' : 'not active'}
                  </span>
                }
              />
            </ul>
          )}
        </Card>
      </div>

      {/* ------------------------------------------------------------------ */}
      {/* Legacy sections below keep their existing (non-envelope) endpoints  */}
      {/* — they migrate in later phases.                                     */}
      {/* ------------------------------------------------------------------ */}

      <div className="mt-6">
        <Card
          title="Integrations"
          subtitle="What the dashboard can actually read right now"
        >
          {integrations.loading ? (
            <Spinner label="Checking integrations…" />
          ) : integrations.error ? (
            <ErrorState message={integrations.error} onRetry={integrations.refresh} />
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
            <ErrorState message={siteHealth.error} onRetry={siteHealth.refresh} />
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
            <ErrorState message={namespaces.error} onRetry={namespaces.refresh} />
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
    </div>
  )
}

function Row({ label, value }: { label: string; value: ReactNode }) {
  return (
    <li className="py-1.5 flex items-center justify-between gap-3">
      <span className="text-ink-500">{label}</span>
      <span className="text-ink-800 font-medium">{value}</span>
    </li>
  )
}
