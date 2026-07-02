import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'
import { EmptyState } from '@/components/ui/EmptyState'

export function InsightisticAnalytics() {
  return (
    <div>
      <PageHeader
        eyebrow="Insightistic layer"
        title="Insightistic Analytics"
        subtitle="Combined analytics from Insightistic + WooCommerce + Booking + Membership + SEO + support signals."
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Sessions / 30d"     value={18412}  format="number" />
        <StatCard label="Engaged sessions"   value={11208}  format="number" intent="success" />
        <StatCard label="Revenue attributed" value={4_812_400} format="currency" />
        <StatCard label="Bounce rate"        value={38.2}   format="percent" intent="warn" />
      </div>

      <Card title="Insightistic connector" className="mt-6">
        <EmptyState
          icon="◇"
          title="Insightistic feed will populate here"
          hint="Point the dashboard at the Insightistic plugin's /wp-json/insightistic/v1 endpoints. Until it is wired, this page shows sample totals from GA4."
        />
      </Card>
    </div>
  )
}
