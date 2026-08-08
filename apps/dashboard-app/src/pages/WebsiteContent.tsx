import { useState } from 'react'
import { PageHeader } from '@/components/ui/PageHeader'
import { Card } from '@/components/ui/Card'
import { Spinner } from '@/components/ui/Spinner'
import { useAsync } from '@/lib/hooks'
import { api } from '@/lib/api'
import type { ContentResourceType, WpContentItem } from '@/types/analytics'
import { cn } from '@/lib/cn'

const PER_PAGE = 10

const TABS: Array<{ key: ContentResourceType; label: string }> = [
  { key: 'posts', label: 'Posts' },
  { key: 'pages', label: 'Pages' },
  { key: 'media', label: 'Media' },
  { key: 'categories', label: 'Categories' },
  { key: 'tags', label: 'Tags' },
]

const STATUS_STYLE: Record<string, string> = {
  publish: 'pill-green',
  draft: 'pill-amber',
  pending: 'pill-amber',
  future: 'pill-blue',
  private: 'pill-gray',
}

export function WebsiteContent() {
  const [tab, setTab] = useState<ContentResourceType>('posts')
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')

  function selectTab(key: ContentResourceType) {
    setTab(key)
    setPage(1)
    setSearch('')
  }

  const list = useAsync(
    () => api.content[tab]({ page, perPage: PER_PAGE, search: search || undefined }),
    [tab, page, search],
  )

  const items = list.data?.items ?? []
  const total = list.data?.total ?? 0
  const hasMore = list.data ? page < list.data.totalPages : false

  return (
    <div>
      <PageHeader
        eyebrow="Site content"
        title="Website Content"
        subtitle="Posts, pages, media, categories, and tags — read straight from WordPress core through the same g2a/v1 gateway as every other page."
      />

      <div className="flex flex-wrap gap-2 border-b pb-3" style={{ borderColor: 'var(--border-subtle)' }}>
        {TABS.map(t => (
          <button
            key={t.key}
            onClick={() => selectTab(t.key)}
            className={cn(
              'px-3 py-1.5 text-xs font-medium border-b-2 -mb-px transition-colors',
              tab === t.key ? '' : 'border-transparent text-ink-500 hover:text-ink-800',
            )}
            style={{
              color: tab === t.key ? 'var(--brand)' : undefined,
              borderBottomColor: tab === t.key ? 'var(--brand)' : 'transparent',
            }}
          >
            {t.label}
          </button>
        ))}
      </div>

      <div className="mt-4 flex items-center gap-2">
        <input
          className="input max-w-xs"
          placeholder={`Search ${TABS.find(t => t.key === tab)?.label.toLowerCase()}…`}
          value={search}
          onChange={e => {
            setSearch(e.target.value)
            setPage(1)
          }}
        />
      </div>

      <Card className="mt-4">
        {list.loading ? (
          <Spinner label={`Loading ${tab}…`} />
        ) : list.error ? (
          <div className="text-sm rounded-lg px-3 py-2" style={{ background: 'var(--danger-soft)', color: 'var(--danger)' }}>
            {list.error}
          </div>
        ) : items.length === 0 ? (
          <div className="text-sm text-ink-500">No {tab} found.</div>
        ) : tab === 'media' ? (
          <MediaGrid items={items} />
        ) : (
          <ContentTable items={items} tab={tab} />
        )}

        {!list.loading && !list.error && items.length > 0 && (
          <div className="mt-4 flex items-center justify-between text-xs text-ink-500">
            <span>{total} total</span>
            <div className="flex items-center gap-2">
              <button
                className="btn-secondary text-xs"
                disabled={page <= 1}
                onClick={() => setPage(p => Math.max(1, p - 1))}
              >
                Prev
              </button>
              <span>Page {page}{list.data ? ` of ${list.data.totalPages}` : ''}</span>
              <button
                className="btn-secondary text-xs"
                disabled={!hasMore}
                onClick={() => setPage(p => p + 1)}
              >
                Next
              </button>
            </div>
          </div>
        )}
      </Card>
    </div>
  )
}

function ContentTable({ items, tab }: { items: WpContentItem[]; tab: ContentResourceType }) {
  const isTaxonomy = tab === 'categories' || tab === 'tags'
  return (
    <div className="overflow-x-auto -mx-1 px-1">
      <table className="w-full text-sm">
        <thead>
          <tr className="text-left text-xs uppercase tracking-wide text-ink-500 border-b border-ink-100">
            <th className="py-2">Title</th>
            {!isTaxonomy && <th className="py-2">Status</th>}
            {!isTaxonomy && <th className="py-2">Date</th>}
            {isTaxonomy && <th className="py-2">Count</th>}
            <th className="py-2">Link</th>
          </tr>
        </thead>
        <tbody>
          {items.map(item => (
            <tr key={item.id} className="border-b border-ink-100 last:border-0">
              <td className="py-2 max-w-md">
                <div className="font-medium text-ink-800 truncate">
                  {item.title?.rendered || item.name || `#${item.id}`}
                </div>
              </td>
              {!isTaxonomy && (
                <td className="py-2">
                  {item.status && (
                    <span className={STATUS_STYLE[item.status] ?? 'pill-gray'}>{item.status}</span>
                  )}
                </td>
              )}
              {!isTaxonomy && (
                <td className="py-2 text-ink-600 whitespace-nowrap">
                  {item.date ? new Date(item.date).toLocaleDateString() : '—'}
                </td>
              )}
              {isTaxonomy && <td className="py-2 text-ink-600">{item.count ?? 0}</td>}
              <td className="py-2">
                <a
                  href={item.link}
                  target="_blank"
                  rel="noreferrer"
                  className="text-xs"
                  style={{ color: 'var(--brand)' }}
                >
                  View ↗
                </a>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function MediaGrid({ items }: { items: WpContentItem[] }) {
  return (
    <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
      {items.map(item => {
        const thumb = item.media_details?.sizes?.thumbnail?.source_url || item.source_url
        return (
          <a
            key={item.id}
            href={item.link}
            target="_blank"
            rel="noreferrer"
            className="block rounded-lg border overflow-hidden hover:opacity-90 transition-opacity"
            style={{ borderColor: 'var(--border-subtle)' }}
          >
            <div className="aspect-square bg-ink-50 flex items-center justify-center overflow-hidden">
              {thumb ? (
                <img src={thumb} alt="" className="w-full h-full object-cover" loading="lazy" />
              ) : (
                <span className="text-xs text-ink-400">{item.media_type || 'file'}</span>
              )}
            </div>
            <div className="p-2 text-xs text-ink-600 truncate">
              {item.date ? new Date(item.date).toLocaleDateString() : '—'}
            </div>
          </a>
        )
      })}
    </div>
  )
}
