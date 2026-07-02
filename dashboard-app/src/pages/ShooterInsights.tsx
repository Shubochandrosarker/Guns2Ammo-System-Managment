import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'

// Guns2Ammo's frontend uses the more evocative "Shooter Insights" label,
// but the underlying data is the customer analytics feed.
export function ShooterInsights() {
  return (
    <div>
      <PageHeader
        eyebrow="Customer intelligence"
        title="Shooter Insights"
        subtitle="Who is walking through the door, who is coming back, and who is at risk of drifting away."
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Known shooters"        value={2168} />
        <StatCard label="Repeat rate"           value={34.1} format="percent" intent="warn" />
        <StatCard label="First-time this month" value={148}  intent="success" />
        <StatCard label="Lapsed (90d silent)"   value={318}  intent="danger" />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">
        <Card title="Segments" subtitle="Auto-detected from booking + purchase behavior">
          <ul className="space-y-3 text-sm">
            <Segment label="Range regulars"        value="612" hint="≥ 2 lane bookings / 30d" />
            <Segment label="CCW graduates"         value="204" hint="Completed CCW class, no gun purchase yet" />
            <Segment label="Ammo bulk buyers"      value="118" hint="≥ 1000rd order last 60d" />
            <Segment label="Ladies Tuesday attend" value="97"  hint="Attended in last 90d" />
            <Segment label="Members expired 30-90d" value="46" hint="High-value win-back list" />
          </ul>
        </Card>

        <Card title="Top follow-up opportunities">
          <ul className="text-sm space-y-2">
            <li>Send CCW alumni a rifle-class invite (204 shooters)</li>
            <li>Ammo bulk buyers → member conversion offer (118)</li>
            <li>Expired members within 90d → renewal + return credit (46)</li>
            <li>New first-time visitors last 14d → "come back for lane #2" (72)</li>
          </ul>
          <div className="mt-4 text-xs text-ink-500">
            These segments feed the Automation Center and the Email Manager Agent.
          </div>
        </Card>
      </div>
    </div>
  )
}

function Segment({ label, value, hint }: { label: string; value: string; hint: string }) {
  return (
    <li className="flex items-start justify-between gap-3">
      <div className="min-w-0">
        <div className="text-ink-800 font-medium">{label}</div>
        <div className="text-xs text-ink-500 truncate">{hint}</div>
      </div>
      <span className="pill-blue shrink-0">{value}</span>
    </li>
  )
}
