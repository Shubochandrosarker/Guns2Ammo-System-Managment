import { PageHeader } from '@/components/ui/PageHeader'
import { StatCard } from '@/components/ui/StatCard'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { ErrorState } from '@/components/ui/ErrorState'
import { useAsync } from '@/lib/hooks'
import { api } from '@/lib/api'
import { formatNumber } from '@/lib/format'
import { LineChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis, CartesianGrid } from 'recharts'

export function SEOGrowth() {
  const s = useAsync(() => api.analytics.seo(), [])
  if (s.loading) return <Spinner />
  if (s.error) return <Card title="SEO Growth"><ErrorState message={s.error} onRetry={s.refresh} /></Card>
  if (!s.data) return <Card title="No data" />
  const d = s.data

  return (
    <div>
      <PageHeader
        eyebrow="Google Search Console"
        title="SEO Growth"
        subtitle="Where organic search sends traffic, which pages are climbing, and which need attention."
      />

      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
        <StatCard label="Clicks"      value={d.clicks}      format="number" intent="success" />
        <StatCard label="Impressions" value={d.impressions} format="number" />
        <StatCard label="CTR"         value={d.ctr}         format="percent" />
        <StatCard label="Avg position" value={d.position}   format="number" intent="warn" />
      </div>

      <Card title="Clicks trend" className="mt-6">
        <div className="h-64">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={d.clicksSeries}>
              <CartesianGrid strokeDasharray="3 3" stroke="var(--chart-grid)" />
              <XAxis dataKey="date" tickFormatter={x => x.slice(5)} tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} />
              <YAxis tick={{ fontSize: 11, fill: 'var(--chart-axis)' }} width={40} />
              <Tooltip />
              <Line type="monotone" dataKey="value" stroke="var(--info)" strokeWidth={2.5} dot={false} />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </Card>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-6">
        <Card title="Top pages (rising)">
          <ul className="text-sm space-y-2">
            {d.topPages.map(p => (
              <li key={p.url} className="flex justify-between items-center">
                <span className="text-ink-700 truncate mr-3">{p.url}</span>
                <span className="pill-green shrink-0">+{p.deltaPct.toFixed(1)}%</span>
              </li>
            ))}
          </ul>
        </Card>

        <Card title="Dropping pages">
          <ul className="text-sm space-y-2">
            {d.droppingPages.map(p => (
              <li key={p.url} className="flex justify-between items-center">
                <span className="text-ink-700 truncate mr-3">{p.url}</span>
                <span className="pill-red shrink-0">{p.deltaPct.toFixed(1)}%</span>
              </li>
            ))}
          </ul>
        </Card>
      </div>

      <Card title="Top queries" className="mt-6">
        <div className="overflow-x-auto -mx-1 px-1"><table className="w-full text-sm">
          <thead>
            <tr className="text-left text-xs uppercase tracking-wide text-ink-500 border-b border-ink-100">
              <th className="py-2">Query</th>
              <th className="py-2 text-right">Clicks</th>
              <th className="py-2 text-right">Avg pos</th>
            </tr>
          </thead>
          <tbody>
            {d.topQueries.map(q => (
              <tr key={q.query} className="border-b border-ink-100 last:border-0">
                <td className="py-2 text-ink-800">{q.query}</td>
                <td className="py-2 text-right">{formatNumber(q.clicks)}</td>
                <td className="py-2 text-right text-ink-600">{q.position.toFixed(1)}</td>
              </tr>
            ))}
          </tbody>
        </table></div>
      </Card>
    </div>
  )
}
