import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'

export function Settings() {
  return (
    <div>
      <PageHeader
        eyebrow="Configuration"
        title="Settings"
        subtitle="Roles, connections, and defaults for the dashboard. Sensitive values live on the WordPress side, never in the browser."
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

        <Card title="Defaults">
          <div className="space-y-3 text-sm">
            <Row label="Timezone"        value="America/Phoenix (AZ does not observe DST)" />
            <Row label="Default range"   value="Last 30 days" />
            <Row label="Currency"        value="USD" />
            <Row label="Weekly report day" value="Monday 07:00" />
          </div>
        </Card>

        <Card title="Danger zone">
          <div className="text-sm text-ink-600 space-y-3">
            <p>
              Actions here affect production integrations. BridGistic will not
              perform any of these — the owner must click through in this UI.
            </p>
            <div className="flex flex-wrap gap-2">
              <button className="btn-secondary text-xs">Rebuild RAG stores</button>
              <button className="btn-secondary text-xs">Rotate API keys</button>
              <button className="btn-secondary text-xs">Force re-auth all sessions</button>
            </div>
          </div>
        </Card>
      </div>
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
