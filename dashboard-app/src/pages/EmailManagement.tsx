import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { StatCard } from '@/components/ui/StatCard'

const CATEGORIES = [
  { key: 'customer',  label: 'Customer inquiries', count: 26 },
  { key: 'booking',   label: 'Booking emails',     count: 44 },
  { key: 'member',    label: 'Membership emails',  count: 31 },
  { key: 'waiver',    label: 'Waiver emails',      count: 12 },
  { key: 'sales',     label: 'Sales follow-up',    count: 18 },
  { key: 'support',   label: 'Support',            count: 22 },
  { key: 'reviews',   label: 'Review requests',    count:  9 },
  { key: 'internal',  label: 'Internal alerts',    count:  6 },
  { key: 'reports',   label: 'Reports',            count:  4 },
  { key: 'marketing', label: 'Marketing campaigns',count:  3 },
]

const PENDING = [
  { subject: 'Re: CCW class rescheduling — need before 07/09',   from: 'Aisha K.',    intent: 'booking',   priority: 'high' },
  { subject: 'Question about corporate group day rates',         from: 'Corp: Nexa',  intent: 'sales',     priority: 'medium' },
  { subject: 'Waiver signed on wrong name',                       from: 'Marcus D.',   intent: 'waiver',    priority: 'medium' },
  { subject: 'Membership renewal card declined',                  from: 'Priya S.',    intent: 'member',    priority: 'high' },
  { subject: 'Request: private range block for bachelor party',   from: 'Trevor R.',   intent: 'booking',   priority: 'medium' },
]

const priorityStyle: Record<string, string> = {
  high:   'pill-red',
  medium: 'pill-amber',
  low:    'pill-gray',
}

export function EmailManagement() {
  return (
    <div>
      <PageHeader
        eyebrow="Inbox intelligence"
        title="Email Management"
        subtitle="Auto-classified inbound email with drafted replies from the Email Manager Agent. Owner approves sends."
      />

      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label="Unread"          value={38} intent="warn" />
        <StatCard label="AI drafted"      value={12} intent="success" />
        <StatCard label="Urgent"          value={ 4} intent="danger" />
        <StatCard label="Sent last 24h"   value={97} />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-6">
        <Card title="Categories" className="lg:col-span-1">
          <ul className="space-y-1.5 text-sm">
            {CATEGORIES.map(c => (
              <li key={c.key} className="flex justify-between hover:bg-ink-50 rounded px-2 py-1 cursor-pointer">
                <span className="text-ink-700">{c.label}</span>
                <span className="pill-gray">{c.count}</span>
              </li>
            ))}
          </ul>
        </Card>

        <Card title="Pending owner approval" subtitle="Drafts ready to send" className="lg:col-span-2">
          <ul className="divide-y divide-ink-100">
            {PENDING.map(p => (
              <li key={p.subject} className="py-3 flex items-center gap-3">
                <span className={priorityStyle[p.priority]}>{p.priority}</span>
                <div className="min-w-0 flex-1">
                  <div className="text-sm font-medium text-ink-800 truncate">{p.subject}</div>
                  <div className="text-xs text-ink-500">from {p.from} · {p.intent}</div>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                  <button className="btn-secondary text-xs">Review</button>
                  <button className="btn-primary text-xs">Send</button>
                </div>
              </li>
            ))}
          </ul>
        </Card>
      </div>
    </div>
  )
}
