import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { useAsync } from '@/lib/hooks'
import { api } from '@/lib/api'

export function BusinessGaps() {
  const g = useAsync(() => api.gaps.list(), [])
  if (g.loading) return <Spinner />
  const gaps = g.data ?? []

  const priorityStyle: Record<string, string> = {
    high:   'pill-red',
    medium: 'pill-amber',
    low:    'pill-gray',
  }

  return (
    <div>
      <PageHeader
        eyebrow="Where money leaks"
        title="Business Gaps"
        subtitle="Concrete, evidence-backed problems the business should fix — each with a suggested owner and impact."
      />

      <div className="space-y-4">
        {gaps.map(gap => (
          <Card key={gap.id}>
            <div className="flex items-start justify-between gap-4">
              <div className="min-w-0">
                <div className="flex items-center gap-2 mb-1">
                  <span className={priorityStyle[gap.priority] ?? 'pill-gray'}>{gap.priority}</span>
                  <span className="text-xs text-ink-500 uppercase tracking-wide">{gap.owner}</span>
                </div>
                <h3 className="font-semibold text-ink-800">{gap.problem}</h3>
                <p className="text-sm text-ink-600 mt-1">{gap.evidence}</p>
              </div>
              <button className="btn-secondary shrink-0 text-xs">Create task</button>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
              <div>
                <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-500 mb-1">
                  Business impact
                </div>
                <div className="text-sm text-ink-700">{gap.impact}</div>
              </div>
              <div>
                <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-500 mb-1">
                  Recommended fix
                </div>
                <div className="text-sm text-ink-700">{gap.fix}</div>
              </div>
            </div>
          </Card>
        ))}
      </div>
    </div>
  )
}
