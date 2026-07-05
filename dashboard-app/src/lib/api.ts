// Thin API client for the Guns2Ammo Business Control Center.
//
// The WordPress plugin `g2a-business-api` exposes these routes under
// `/wp-json/g2a/v1/*`. When VITE_G2A_USE_MOCKS is set, we short-circuit
// to bundled fixtures so the UI is fully browsable without a live WP.

import { env } from './env'
import * as mock from '@/mocks/data'
import type {
  AIInsight,
  Agent,
  Automation,
  BookingAnalytics,
  BrainIngestResult,
  BrainQueryResult,
  BrainStats,
  BusinessGap,
  ContentListParams,
  ContentPage,
  ContentResourceType,
  EmailOverview,
  InsightisticAnalytics,
  IntegrationsStatus,
  Lead,
  LeadsPage,
  LeadStats,
  LeadStatus,
  MembershipAnalytics,
  ModelConnection,
  NamespacesStatus,
  Range,
  RevenueOverview,
  SeoAnalytics,
  ShooterInsights,
  SiteHealthSummary,
  StoreAnalytics,
  SystemHealthCheck,
  WpContentItem,
} from '@/types/analytics'

const AUTH_KEY = 'g2a.auth.token'

export interface Session {
  // Base64(email:appPassword) for WordPress application-password auth.
  // The g2a-business-api plugin validates this on the /auth/login handshake;
  // subsequent requests re-send it as `Authorization: Basic <token>`.
  //
  // In mock mode this is just a placeholder — nothing consumes it.
  token: string
  displayName: string
  role: 'owner' | 'manager' | 'staff' | 'analyst'
}

export function readSession(): Session | null {
  try {
    const raw = localStorage.getItem(AUTH_KEY)
    return raw ? (JSON.parse(raw) as Session) : null
  } catch {
    return null
  }
}

export function writeSession(s: Session | null) {
  if (s) localStorage.setItem(AUTH_KEY, JSON.stringify(s))
  else localStorage.removeItem(AUTH_KEY)
}

// ---------------------------------------------------------------------------
// HTTP transport
// ---------------------------------------------------------------------------

class ApiError extends Error {
  status: number
  constructor(status: number, message: string) {
    super(message)
    this.status = status
  }
}

async function httpRaw(path: string, init?: RequestInit): Promise<Response> {
  const base = env.apiBase.replace(/\/$/, '')
  const url = `${base}/wp-json/g2a/v1${path}`
  const session = readSession()
  const res = await fetch(url, {
    ...init,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      // WordPress application-password auth. `Basic` because the token
      // *is* base64(email:appPassword) — see the Session comment.
      ...(session ? { Authorization: `Basic ${session.token}` } : {}),
      ...(init?.headers || {}),
    },
    credentials: 'include',
  })
  if (!res.ok) {
    const text = await res.text().catch(() => '')
    throw new ApiError(res.status, text || res.statusText)
  }
  return res
}

async function http<T>(path: string, init?: RequestInit): Promise<T> {
  const res = await httpRaw(path, init)
  return (await res.json()) as T
}

// The g2a-business-api /content/* routes proxy wp/v2 in-process and relay
// its X-WP-Total / X-WP-TotalPages pagination headers — read those off the
// raw Response since the plain `http()` helper only returns the JSON body.
async function httpContentList(path: string): Promise<ContentPage> {
  const res = await httpRaw(path)
  const items = (await res.json()) as WpContentItem[]
  const total = Number(res.headers.get('X-WP-Total'))
  const totalPages = Number(res.headers.get('X-WP-TotalPages'))
  return {
    items,
    total: Number.isFinite(total) && total >= 0 ? total : items.length,
    totalPages: Number.isFinite(totalPages) && totalPages >= 0 ? totalPages : 1,
  }
}

function contentQueryString(params: ContentListParams): string {
  const qs = new URLSearchParams()
  if (params.perPage) qs.set('per_page', String(params.perPage))
  if (params.page) qs.set('page', String(params.page))
  if (params.search) qs.set('search', params.search)
  if (params.status) qs.set('status', params.status)
  const suffix = qs.toString()
  return suffix ? `?${suffix}` : ''
}

function mockContentPage(items: WpContentItem[], params: ContentListParams): ContentPage {
  let filtered = items
  if (params.status) filtered = filtered.filter(i => i.status === params.status)
  if (params.search) {
    const q = params.search.toLowerCase()
    filtered = filtered.filter(i =>
      (i.title?.rendered ?? i.name ?? '').toLowerCase().includes(q),
    )
  }
  const total = filtered.length
  const perPage = params.perPage || 10
  const page = params.page || 1
  const start = (page - 1) * perPage
  return {
    items: filtered.slice(start, start + perPage),
    total,
    totalPages: Math.max(1, Math.ceil(total / perPage)),
  }
}

function contentResource(type: ContentResourceType, mockItems: WpContentItem[]) {
  return (params: ContentListParams = {}): Promise<ContentPage> =>
    env.useMocks
      ? Promise.resolve(mockContentPage(mockItems, params))
      : httpContentList(`/content/${type}${contentQueryString(params)}`)
}

// ---------------------------------------------------------------------------
// Public API surface
// ---------------------------------------------------------------------------

const defaultRange = (): Range => {
  // Callers currently rely on the server to interpret an empty range as
  // "last 30 days". Import.meta.env.MODE === 'test' keeps this pure.
  return { from: '', to: '' }
}

export const api = {
  auth: {
    async login(email: string, password: string): Promise<Session> {
      if (env.useMocks) {
        if (!email || !password) throw new ApiError(400, 'Email and password required')
        const session: Session = {
          token: 'mock-token',
          displayName: email.split('@')[0] || 'Owner',
          role: 'owner',
        }
        writeSession(session)
        return session
      }

      // Mint the Basic-auth token BEFORE calling /auth/login so the
      // handshake itself is authenticated — the plugin needs it to check
      // `current_user_can(g2a_dashboard)` against a real WP user.
      const basic = btoa(`${email}:${password}`)
      writeSession({ token: basic, displayName: email, role: 'analyst' })

      try {
        const server = await http<Omit<Session, 'token'>>('/auth/login', {
          method: 'POST',
          body: JSON.stringify({ email, password }),
        })
        const session: Session = { token: basic, ...server }
        writeSession(session)
        return session
      } catch (err) {
        writeSession(null)
        throw err
      }
    },
    logout() {
      writeSession(null)
    },
    async me(): Promise<Omit<Session, 'token'>> {
      if (env.useMocks) {
        const s = readSession()
        return { displayName: s?.displayName ?? 'Owner', role: s?.role ?? 'owner' }
      }
      return http<Omit<Session, 'token'>>('/auth/me')
    },
  },

  analytics: {
    revenueOverview(range: Range = defaultRange()): Promise<RevenueOverview> {
      return env.useMocks
        ? Promise.resolve(mock.revenueOverview)
        : http<RevenueOverview>(`/analytics/overview?from=${range.from}&to=${range.to}`)
    },
    bookings(range: Range = defaultRange()): Promise<BookingAnalytics> {
      return env.useMocks
        ? Promise.resolve(mock.bookings)
        : http<BookingAnalytics>(`/analytics/bookings?from=${range.from}&to=${range.to}`)
    },
    memberships(range: Range = defaultRange()): Promise<MembershipAnalytics> {
      return env.useMocks
        ? Promise.resolve(mock.memberships)
        : http<MembershipAnalytics>(`/analytics/memberships?from=${range.from}&to=${range.to}`)
    },
    store(range: Range = defaultRange()): Promise<StoreAnalytics> {
      return env.useMocks
        ? Promise.resolve(mock.store)
        : http<StoreAnalytics>(`/analytics/store?from=${range.from}&to=${range.to}`)
    },
    seo(range: Range = defaultRange()): Promise<SeoAnalytics> {
      return env.useMocks
        ? Promise.resolve(mock.seo)
        : http<SeoAnalytics>(`/analytics/seo?from=${range.from}&to=${range.to}`)
    },
    insightistic(range: Range = defaultRange()): Promise<InsightisticAnalytics> {
      return env.useMocks
        ? Promise.resolve(mock.insightistic)
        : http<InsightisticAnalytics>(`/analytics/insightistic?from=${range.from}&to=${range.to}`)
    },
    shooterInsights(): Promise<ShooterInsights> {
      return env.useMocks
        ? Promise.resolve(mock.shooterInsights)
        : http<ShooterInsights>('/analytics/shooter-insights')
    },
  },

  gaps: {
    list(): Promise<BusinessGap[]> {
      return env.useMocks ? Promise.resolve(mock.gaps) : http<BusinessGap[]>('/insights/business-gaps')
    },
  },

  insights: {
    list(): Promise<AIInsight[]> {
      return env.useMocks ? Promise.resolve(mock.insights) : http<AIInsight[]>('/ai/insights')
    },
    async approve(id: string): Promise<Task> {
      if (env.useMocks) throw new Error('approve is unavailable in mock mode')
      const res = await http<{ ok: true; task: Task }>(`/ai/insights/${id}/approve`, { method: 'POST' })
      return res.task
    },
    async dismiss(id: string): Promise<void> {
      if (env.useMocks) return
      await http(`/ai/insights/${id}/dismiss`, { method: 'POST' })
    },
  },

  gapActions: {
    async createTask(id: string): Promise<Task> {
      if (env.useMocks) throw new Error('createTask is unavailable in mock mode')
      const res = await http<{ ok: true; task: Task }>(`/insights/business-gaps/${id}/create-task`, { method: 'POST' })
      return res.task
    },
  },

  tasks: {
    async list(status: 'open' | 'all' = 'open'): Promise<Task[]> {
      if (env.useMocks) return mock.tasks.filter(t => status === 'all' || t.status === 'open')
      return http<Task[]>(status === 'open' ? '/tasks?status=open' : '/tasks')
    },
    async create(title: string, body = '', owner = ''): Promise<Task> {
      if (env.useMocks) throw new Error('create is unavailable in mock mode')
      return http<Task>('/tasks', {
        method: 'POST',
        body: JSON.stringify({ title, body, owner }),
      })
    },
    async resolve(id: string): Promise<Task> {
      if (env.useMocks) throw new Error('resolve is unavailable in mock mode')
      return http<Task>(`/tasks/${id}/resolve`, { method: 'POST' })
    },
    async dismiss(id: string): Promise<Task> {
      if (env.useMocks) throw new Error('dismiss is unavailable in mock mode')
      return http<Task>(`/tasks/${id}/dismiss`, { method: 'POST' })
    },
  },

  automations: {
    list(): Promise<Automation[]> {
      return env.useMocks ? Promise.resolve(mock.automations) : http<Automation[]>('/automations')
    },
    async toggle(id: string, enabled: boolean): Promise<void> {
      if (env.useMocks) return
      await http(`/automations/${id}/toggle`, {
        method: 'POST',
        body: JSON.stringify({ enabled }),
      })
    },
    async updateSchedule(id: string, patch: { interval?: AutomationInterval; status?: 'active' | 'paused' }): Promise<Automation> {
      if (env.useMocks) throw new Error('updateSchedule is unavailable in mock mode')
      return http<Automation>(`/automations/${id}`, {
        method: 'PATCH',
        body: JSON.stringify(patch),
      })
    },
  },

  routing: {
    get(): Promise<ModelRoutingResponse> {
      if (env.useMocks) {
        return Promise.resolve({
          purposes: MOCK_PURPOSES,
          labels: MOCK_ROUTING_LABELS,
          routing: MOCK_ROUTING,
        })
      }
      return http<ModelRoutingResponse>('/model-routing')
    },
    async update(patch: Record<string, string | null>): Promise<Record<string, string | null>> {
      if (env.useMocks) throw new Error('routing.update is unavailable in mock mode')
      const res = await http<{ ok: true; routing: Record<string, string | null> }>('/model-routing', {
        method: 'PUT',
        body: JSON.stringify(patch),
      })
      return res.routing
    },
  },

  content: {
    posts: contentResource('posts', mock.contentPosts),
    pages: contentResource('pages', mock.contentPages),
    media: contentResource('media', mock.contentMedia),
    categories: contentResource('categories', mock.contentCategories),
    tags: contentResource('tags', mock.contentTags),
  },

  system: {
    integrations(): Promise<IntegrationsStatus> {
      return env.useMocks
        ? Promise.resolve(mock.integrations)
        : http<IntegrationsStatus>('/system/integrations')
    },
    namespaces(): Promise<NamespacesStatus> {
      return env.useMocks
        ? Promise.resolve(mock.namespacesStatus)
        : http<NamespacesStatus>('/system/namespaces')
    },
    siteHealth(): Promise<SiteHealthSummary> {
      return env.useMocks
        ? Promise.resolve(mock.siteHealthSummary)
        : http<SiteHealthSummary>('/system/site-health')
    },
    async rotateKeys(): Promise<SystemActionResult> {
      if (env.useMocks) throw new Error('rotateKeys is unavailable in mock mode')
      return http<SystemActionResult>('/system/rotate-keys', {
        method: 'POST',
        body: JSON.stringify({ confirm: true }),
      })
    },
    async revokeSessions(): Promise<SystemActionResult> {
      if (env.useMocks) throw new Error('revokeSessions is unavailable in mock mode')
      return http<SystemActionResult>('/system/revoke-sessions', {
        method: 'POST',
        body: JSON.stringify({ confirm: true }),
      })
    },
    async rebuildRag(): Promise<SystemActionResult> {
      if (env.useMocks) throw new Error('rebuildRag is unavailable in mock mode')
      return http<SystemActionResult>('/system/rag/rebuild', {
        method: 'POST',
        body: JSON.stringify({ confirm: true }),
      })
    },
  },

  exports: {
    // Build absolute URLs so the browser handles Content-Disposition properly.
    tasksCsvUrl(): string {
      return `${env.apiBase.replace(/\/$/, '')}/wp-json/g2a/v1/export/tasks.csv`
    },
    auditCsvUrl(): string {
      return `${env.apiBase.replace(/\/$/, '')}/wp-json/g2a/v1/export/audit-log.csv`
    },
    reportTxtUrl(id: string): string {
      return `${env.apiBase.replace(/\/$/, '')}/wp-json/g2a/v1/export/reports/${id}.txt`
    },
  },

  agents: {
    list(): Promise<Agent[]> {
      return env.useMocks ? Promise.resolve(mock.agents) : http<Agent[]>('/agents')
    },
    async run(id: string): Promise<void> {
      if (env.useMocks) return
      await http(`/agents/${id}/run`, { method: 'POST' })
    },
  },

  leads: {
    list(params: LeadsListParams = {}): Promise<LeadsPage> {
      if (env.useMocks) {
        let items = mock.leads
        if (params.category) items = items.filter(l => l.category === params.category)
        if (params.status)   items = items.filter(l => l.status === params.status)
        if (params.source)   items = items.filter(l => l.source === params.source)
        if (params.search) {
          const q = params.search.toLowerCase()
          items = items.filter(l =>
            (l.contactName ?? '').toLowerCase().includes(q) ||
            (l.contactEmail ?? '').toLowerCase().includes(q) ||
            (l.subject ?? '').toLowerCase().includes(q),
          )
        }
        // Same string comparison the real repository uses (created_at >= / <= date_from/date_to).
        if (params.dateFrom) items = items.filter(l => l.createdAt >= params.dateFrom!)
        if (params.dateTo)   items = items.filter(l => l.createdAt <= params.dateTo!)
        items = [...items].sort((a, b) => b.createdAt.localeCompare(a.createdAt))
        const total = items.length
        const perPage = params.perPage || 20
        const page = params.page || 1
        const start = (page - 1) * perPage
        return Promise.resolve({ items: items.slice(start, start + perPage), total })
      }
      const qs = new URLSearchParams()
      if (params.category)  qs.set('category', params.category)
      if (params.status)    qs.set('status', params.status)
      if (params.source)    qs.set('source', params.source)
      if (params.search)    qs.set('search', params.search)
      if (params.dateFrom)  qs.set('date_from', params.dateFrom)
      if (params.dateTo)    qs.set('date_to', params.dateTo)
      if (params.page)      qs.set('page', String(params.page))
      if (params.perPage)   qs.set('per_page', String(params.perPage))
      const suffix = qs.toString()
      return http<LeadsPage>(`/leads${suffix ? `?${suffix}` : ''}`)
    },
    stats(): Promise<LeadStats> {
      return env.useMocks ? Promise.resolve(mock.leadStats) : http<LeadStats>('/leads/stats')
    },
    get(id: number): Promise<Lead> {
      if (env.useMocks) {
        const found = mock.leads.find(l => l.id === id)
        if (!found) throw new ApiError(404, 'Lead not found')
        return Promise.resolve(found)
      }
      return http<Lead>(`/leads/${id}`)
    },
    async updateStatus(id: number, patch: { status?: LeadStatus; assignedAgent?: string | null }): Promise<Lead> {
      if (env.useMocks) throw new Error('updateStatus is unavailable in mock mode')
      const body: Record<string, unknown> = {}
      if (patch.status !== undefined) body.status = patch.status
      if (patch.assignedAgent !== undefined) body.assigned_agent = patch.assignedAgent
      return http<Lead>(`/leads/${id}`, { method: 'PATCH', body: JSON.stringify(body) })
    },
  },

  brain: {
    query(q: string, k?: number, scope?: string): Promise<BrainQueryResult> {
      if (env.useMocks) return Promise.resolve(mock.brainQueryResult)
      const qs = new URLSearchParams({ q })
      if (k) qs.set('k', String(k))
      if (scope) qs.set('scope', scope)
      return http<BrainQueryResult>(`/brain/query?${qs.toString()}`)
    },
    stats(scope?: string): Promise<BrainStats> {
      if (env.useMocks) return Promise.resolve(mock.brainStats)
      const qs = scope ? `?scope=${encodeURIComponent(scope)}` : ''
      return http<BrainStats>(`/brain/stats${qs}`)
    },
    async ingest(label: string, body: string, opts: { tags?: string; scope?: string } = {}): Promise<BrainIngestResult> {
      if (env.useMocks) throw new Error('ingest is unavailable in mock mode')
      return http<BrainIngestResult>('/brain/ingest', {
        method: 'POST',
        body: JSON.stringify({ label, body, ...opts }),
      })
    },
  },

  models: {
    list(): Promise<ModelConnection[]> {
      return env.useMocks ? Promise.resolve(mock.models) : http<ModelConnection[]>('/model-connections')
    },
    async test(id: string): Promise<ModelTestResult> {
      if (env.useMocks) return { ok: true, latencyMs: 240, provider: 'mock', probe: 'mock' }
      return http<ModelTestResult>(`/model-connections/${id}/test`, { method: 'POST' })
    },
    async create(patch: ModelPatch): Promise<ModelConnection> {
      if (env.useMocks) throw new Error('create is unavailable in mock mode')
      return http<ModelConnection>('/model-connections', {
        method: 'POST',
        body: JSON.stringify(patch),
      })
    },
    async update(id: string, patch: ModelPatch): Promise<ModelConnection> {
      if (env.useMocks) throw new Error('update is unavailable in mock mode')
      return http<ModelConnection>(`/model-connections/${id}`, {
        method: 'PATCH',
        body: JSON.stringify(patch),
      })
    },
    async remove(id: string): Promise<void> {
      if (env.useMocks) throw new Error('delete is unavailable in mock mode')
      await http(`/model-connections/${id}`, { method: 'DELETE' })
    },
    async setKey(id: string, apiKey: string): Promise<{ ok: true; keyMasked: string }> {
      if (env.useMocks) throw new Error('setKey is unavailable in mock mode')
      return http(`/model-connections/${id}/key`, {
        method: 'POST',
        body: JSON.stringify({ apiKey }),
      })
    },
    async catalog(id: string): Promise<ModelCatalogResult> {
      if (env.useMocks) return { ok: true, provider: 'mock', models: mock.modelCatalog }
      return http<ModelCatalogResult>(`/model-connections/${id}/catalog`)
    },
  },

  reports: {
    list(): Promise<ReportDefinition[]> {
      if (env.useMocks) return Promise.resolve(mock.reports ?? [])
      return http<ReportDefinition[]>('/reports')
    },
    async runNow(id: string): Promise<ReportDelivery> {
      if (env.useMocks) throw new Error('run-now is unavailable in mock mode')
      return http<ReportDelivery>(`/reports/${id}/run-now`, { method: 'POST' })
    },
    async latest(id: string): Promise<ReportDelivery> {
      if (env.useMocks) throw new Error('latest is unavailable in mock mode')
      return http<ReportDelivery>(`/reports/${id}/latest`)
    },
  },

  settings: {
    get(): Promise<DashboardSettings> {
      if (env.useMocks) return Promise.resolve(mock.dashboardSettings)
      return http<DashboardSettings>('/settings')
    },
    async update(patch: Partial<DashboardSettings>): Promise<DashboardSettings> {
      if (env.useMocks) throw new Error('settings update unavailable in mock mode')
      const res = await http<{ ok: true; settings: DashboardSettings }>('/settings', {
        method: 'PUT',
        body: JSON.stringify(patch),
      })
      return res.settings
    },
  },

  health: {
    checks(): Promise<SystemHealthCheck[]> {
      return env.useMocks ? Promise.resolve(mock.health) : http<SystemHealthCheck[]>('/system/health')
    },
  },

  bridgistic: {
    async ask(query: string): Promise<BridGisticAskResult> {
      if (env.useMocks) {
        // Deterministic client-side classifier so the mock UX matches the
        // server's decision rules — same verb list as the PHP classifier.
        const lower = query.toLowerCase()
        const isDraft  = /\b(draft|prepare|write|compose|propose|suggest)\b/.test(lower)
        const isAction = /\b(send|email|sms|create|add|schedule|book|update|change|edit|modify|delete|remove|cancel|refund|charge|issue|approve|reject|ban|suspend)\b/.test(lower)
        const category = isDraft ? 'draft' : isAction ? 'action' : 'read'
        return {
          query,
          category,
          requiresApproval: category !== 'read',
          answer:
            category === 'action'
              ? 'Action detected. Queued for owner approval.'
              : category === 'draft'
              ? 'Draft prepared. Review + approve before sending.'
              : 'Read-only query — served without any state change.',
          actionId: category === 'action' ? 'mock-action' : undefined,
        }
      }
      return http<BridGisticAskResult>('/bridgistic/ask', {
        method: 'POST',
        body: JSON.stringify({ query }),
      })
    },
    async pending(): Promise<BridGisticAction[]> {
      if (env.useMocks) return []
      return http<BridGisticAction[]>('/bridgistic/actions?status=pending')
    },
    async approve(id: string): Promise<BridGisticAction> {
      if (env.useMocks) throw new Error('approve is unavailable in mock mode')
      return http<BridGisticAction>(`/bridgistic/actions/${id}/approve`, { method: 'POST' })
    },
    async reject(id: string): Promise<BridGisticAction> {
      if (env.useMocks) throw new Error('reject is unavailable in mock mode')
      return http<BridGisticAction>(`/bridgistic/actions/${id}/reject`, { method: 'POST' })
    },
  },

  emails: {
    overview(): Promise<EmailOverview> {
      return env.useMocks
        ? Promise.resolve(mock.emailOverview)
        : http<EmailOverview>('/emails/overview')
    },
  },

  emailDrafts: {
    async list(status: 'pending' | 'all' = 'pending'): Promise<EmailDraft[]> {
      if (env.useMocks) return mock.emailDrafts.filter(d => status === 'all' || d.status === 'pending_send')
      const path = status === 'pending' ? '/email-drafts?status=pending' : '/email-drafts'
      return http<EmailDraft[]>(path)
    },
    async send(id: string, overrides: { to?: string; subject?: string; body?: string }): Promise<EmailDraft> {
      if (env.useMocks) throw new Error('send is unavailable in mock mode')
      return http<EmailDraft>(`/email-drafts/${id}/send`, {
        method: 'POST',
        body: JSON.stringify(overrides),
      })
    },
    async discard(id: string): Promise<EmailDraft> {
      if (env.useMocks) throw new Error('discard is unavailable in mock mode')
      return http<EmailDraft>(`/email-drafts/${id}/discard`, { method: 'POST' })
    },
  },

  cancellations: {
    async list(status: 'awaiting' | 'all' = 'awaiting'): Promise<Cancellation[]> {
      if (env.useMocks) return mock.cancellations.filter(c => status === 'all' || c.status === 'awaiting_manual_action')
      const path = status === 'awaiting' ? '/cancellations?status=awaiting' : '/cancellations'
      return http<Cancellation[]>(path)
    },
    async markCompleted(id: string, notes: string): Promise<Cancellation> {
      if (env.useMocks) throw new Error('markCompleted is unavailable in mock mode')
      return http<Cancellation>(`/cancellations/${id}/mark-completed`, {
        method: 'POST',
        body: JSON.stringify({ notes }),
      })
    },
    async drop(id: string): Promise<Cancellation> {
      if (env.useMocks) throw new Error('drop is unavailable in mock mode')
      return http<Cancellation>(`/cancellations/${id}/drop`, { method: 'POST' })
    },
  },

  agentHistory: {
    async list(agentId: string): Promise<AgentHistoryEntry[]> {
      if (env.useMocks) return mock.agentHistory[agentId] ?? []
      return http<AgentHistoryEntry[]>(`/agents/${agentId}/history`)
    },
  },

  agentPrompt: {
    async set(agentId: string, template: string): Promise<{ record: Agent; placeholder: boolean }> {
      if (env.useMocks) throw new Error('prompt editing is unavailable in mock mode')
      const res = await http<{ ok: true; record: Agent; placeholder: boolean }>(
        `/agents/${agentId}/prompt`,
        { method: 'POST', body: JSON.stringify({ template }) },
      )
      return { record: res.record, placeholder: res.placeholder }
    },
    async get(agentId: string): Promise<{ template: string; history: PromptVersion[] }> {
      if (env.useMocks) return { template: '(edit disabled in mock mode)', history: [] }
      return http<{ template: string; history: PromptVersion[] }>(`/agents/${agentId}/prompt`)
    },
  },

  auditLog: {
    async list(limit = 100): Promise<AuditLogEntry[]> {
      if (env.useMocks) return mock.auditLog
      return http<AuditLogEntry[]>(`/audit-log?limit=${limit}`)
    },
  },
}

export interface BridGisticAskResult {
  query: string
  category: 'read' | 'draft' | 'action'
  answer: string
  requiresApproval: boolean
  actionId?: string
}

export interface BridGisticAction {
  id: string
  query: string
  intent: 'read' | 'draft' | 'action'
  status: 'pending' | 'approved' | 'rejected'
  requesterId: number
  createdAt: string
  resolvedAt: string | null
  result: string | null
}

export interface EmailDraft {
  id: string
  query: string
  to: string
  subject: string
  body: string
  status: 'pending_send' | 'sent' | 'failed' | 'discarded'
  createdAt: string
  sentAt: string | null
  error: string | null
}

export interface Cancellation {
  id: string
  bookingId: string | null
  query: string
  status: 'awaiting_manual_action' | 'completed' | 'dropped'
  createdAt: string
  resolvedAt: string | null
  notes: string | null
}

export interface LeadsListParams {
  category?: string
  status?: string
  source?: string
  search?: string
  dateFrom?: string
  dateTo?: string
  page?: number
  perPage?: number
}

export interface AgentHistoryEntry {
  ts: string
  output: string
  confidence: number
}

export interface PromptVersion {
  ts: string
  template: string
}

export interface Task {
  id: string
  title: string
  body: string
  source: 'ai_insight' | 'business_gap' | 'bridgistic' | 'manual'
  sourceId: string
  owner: string
  status: 'open' | 'done' | 'dismissed'
  createdAt: string
  resolvedAt: string | null
}

export interface AuditLogEntry {
  ts: string
  kind: string
  summary: string
  actor: number
  meta: Record<string, unknown>
}

export interface ModelTestResult {
  ok: boolean
  provider: string
  probe?: string
  latencyMs?: number
  httpCode?: number
  error?: string
}

export interface ModelCatalogEntry {
  id: string
  name: string
}

export interface ModelCatalogResult {
  ok: boolean
  provider: string
  models: ModelCatalogEntry[]
  error?: string
}

export type ModelPatch = Partial<{
  provider: 'anthropic' | 'openai' | 'gemini' | 'openrouter' | 'ollama' | 'custom'
  displayName: string
  modelName: string
  apiBaseUrl: string
  contextLimit: number
  costLevel: 'free' | 'low' | 'medium' | 'high'
  useCase: string
  fallbackId: string | null
  apiKey: string
}>

export interface ReportDefinition {
  id: string
  label: string
  description: string
  schedule: string
  cronHook: string
  handlerSlug: string
  lastDeliveredAt: string | null
  hasLatest: boolean
}

export interface ReportDelivery {
  id: string
  generatedAt: string
  body: string
  format: string
  range: { from: string; to: string }
}

export type AutomationInterval = 'hourly' | 'twicedaily' | 'daily' | 'weekly'

export interface ModelRoutingResponse {
  purposes: string[]
  labels: Record<string, string>
  routing: Record<string, string | null>
}

export interface SystemActionResult {
  ok: true
  result: Record<string, unknown>
}

const MOCK_PURPOSES = [
  'business_analysis', 'seo_analysis', 'booking_suggest', 'support_classify',
  'email_drafts', 'daily_summaries', 'private_inventory',
]

const MOCK_ROUTING_LABELS: Record<string, string> = {
  business_analysis: 'Deep business analysis',
  seo_analysis:      'SEO analysis',
  booking_suggest:   'Booking suggestions',
  support_classify:  'Customer support classify',
  email_drafts:      'Email drafts',
  daily_summaries:   'Cheap daily summaries',
  private_inventory: 'Private inventory',
}

const MOCK_ROUTING: Record<string, string | null> = {
  business_analysis: 'm1',
  seo_analysis:      'm1',
  booking_suggest:   'm2',
  support_classify:  'm3',
  email_drafts:      'm4',
  daily_summaries:   'm4',
  private_inventory: 'm5',
}

export interface DashboardSettings {
  defaultRange: 'last-7' | 'last-30' | 'last-90' | 'month-to-date' | 'quarter-to-date' | 'year-to-date'
  currency: 'USD'
  weeklyReportDay: 'monday' | 'tuesday' | 'wednesday' | 'thursday' | 'friday' | 'saturday' | 'sunday'
  weeklyReportHour: number
  dailySummaryHour: number
  ownerEmail: string
  timezone: string
  // CORS allow-list for the hosted dashboard, e.g. ['https://app.guns2ammo.com'].
  allowedOrigins: string[]
}
