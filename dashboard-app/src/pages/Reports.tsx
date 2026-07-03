import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'

const REPORTS = [
  { id: 'daily-summary',    label: 'Daily summary',            schedule: 'Every day 07:00', last: '2026-07-02 07:00' },
  { id: 'weekly-business',  label: 'Weekly business report',   schedule: 'Monday 07:00',    last: '2026-06-30 07:00' },
  { id: 'monthly-growth',   label: 'Monthly growth report',    schedule: '1st of month',    last: '2026-06-01 08:00' },
  { id: 'seo-report',       label: 'SEO report',               schedule: 'Monday 08:00',    last: '2026-06-30 08:00' },
  { id: 'booking-report',   label: 'Booking report',           schedule: 'Monday 08:15',    last: '2026-06-30 08:15' },
  { id: 'member-report',    label: 'Membership report',        schedule: 'Monday 08:30',    last: '2026-06-30 08:30' },
  { id: 'woo-report',       label: 'WooCommerce report',       schedule: 'Monday 08:45',    last: '2026-06-30 08:45' },
  { id: 'ai-recs',          label: 'AI recommendations report', schedule: 'Wednesday 09:00', last: '2026-06-24 09:00' },
]

export function Reports() {
  return (
    <div>
      <PageHeader
        eyebrow="Business reporting"
        title="Reports"
        subtitle="Scheduled and on-demand reports. Owner + manager get email + Slack; agents also read them."
        actions={<button className="btn-primary text-sm">+ New report</button>}
      />

      <Card bodyClassName="p-0">
        <div className="overflow-x-auto -mx-1 px-1"><table className="w-full text-sm">
          <thead className="bg-ink-50 text-xs uppercase tracking-wide text-ink-500">
            <tr>
              <th className="text-left px-4 py-2">Report</th>
              <th className="text-left px-4 py-2">Schedule</th>
              <th className="text-left px-4 py-2">Last delivered</th>
              <th className="text-right px-4 py-2"></th>
            </tr>
          </thead>
          <tbody>
            {REPORTS.map(r => (
              <tr key={r.id} className="border-t border-ink-100">
                <td className="px-4 py-3 font-medium text-ink-800">{r.label}</td>
                <td className="px-4 py-3 text-ink-600">{r.schedule}</td>
                <td className="px-4 py-3 text-ink-600">{r.last}</td>
                <td className="px-4 py-3 text-right space-x-2">
                  <button className="btn-ghost text-xs">Run now</button>
                  <button className="btn-secondary text-xs">Open latest</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table></div>
      </Card>
    </div>
  )
}
