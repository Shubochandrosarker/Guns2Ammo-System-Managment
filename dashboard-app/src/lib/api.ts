// Thin API client for the Guns2Ammo Business Control Center.
//
// The WordPress plugin `g2a-business-api` exposes these routes under
// `{VITE_G2A_API_BASE}` (the full REST base, e.g.
// https://guns2ammo.com/wp-json/g2a/v1 — see src/lib/env.ts).
//
// Auth (Phase 2, fixed contract):
//   * The session credential is an HttpOnly cookie the server sets on
//     POST /auth/session/login. JavaScript NEVER reads or stores it —
//     every fetch simply carries it via `credentials: 'include'`.
//   * A per-session CSRF token is returned in the login/session JSON and
//     kept in module memory ONLY (never localStorage/sessionStorage).
//     Every non-GET request sends it as `X-G2A-CSRF`.
//   * 401 anywhere → clear client auth state and let the app route to
//     /login. 403 with code `g2aba_csrf_failed` → re-hydrate the CSRF
//     token once via GET /auth/session and retry the request once.
//
// In dev, VITE_G2A_USE_MOCKS short-circuits to bundled fixtures so the UI
// is browsable without a live WP. The fixtures are loaded via a dynamic
// import gated on a literal `import.meta.env.DEV`, so production builds
// tree-shake the entire mock module out of the bundle (env.ts additionally
// forces useMocks=false in prod).

import { env } from './env'
import type { SessionResponse, SessionUser } from '@/types/auth'
import type {
  ApiEnvelope,
  ApiErrorPayload,
  ApiSuccess,
  DashboardOverviewData,
  Enveloped,
  SystemHealthData,
} from '@/types/api'
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
  WpContentItem,
} from '@/types/analytics'

// ---------------------------------------------------------------------------
// Legacy credential cleanup
// ---------------------------------------------------------------------------

// Phase 1 builds persisted base64(email:appPassword) under this key. Sessions
// are HttpOnly-cookie-managed now; scrub any stale credential on app boot so
// it can't linger in existing users' browsers.
try {
  localStorage.removeItem('g2a.auth.token')
} catch {
  // Storage unavailable (private mode / disabled) — nothing to clean.
}

// ---------------------------------------------------------------------------
// Auth state (module memory ONLY — nothing is persisted)
// ---------------------------------------------------------------------------

// Per-session CSRF token from /auth/session(/login). A page reload loses it
// by design; App boot re-obtains it via GET /auth/session.
let csrfToken: string | null = null

// Mock-mode stand-in for the cookie session (dev only, memory only).
let mockUser: SessionUser | null = null

// App-level hook: invoked whenever the API sees a 401 so the router can
// clear auth state and land on /login (App.tsx registers it on mount).
let onUnauthorized: (() => void) | null = null
export function setUnauthorizedHandler(handler: (() => void) | null) {
  onUnauthorized = handler
}

// ---------------------------------------------------------------------------
// Dev-only mock fixtures (dynamically imported, tree-shaken from prod)
// ---------------------------------------------------------------------------

type MockModule = typeof import('@/mocks/data')
let mockModule: MockModule | null = null

async function mocks(): Promise<MockModule> {
  if (import.meta.env.DEV) {
    if (!mockModule) mockModule = await import('@/mocks/data')
    return mockModule
  }
  // Unreachable in practice: env.ts forces useMocks=false in prod builds.
  // The literal DEV guard above is what lets Rollup drop the dynamic import
  // (and the whole fixtures chunk) from production bundles.
  throw new Error('g2a: mock data is not available in production builds')
}

// ---------------------------------------------------------------------------
// HTTP transport
// ---------------------------------------------------------------------------

export class ApiError extends Error {
  status: number
  /** WP_Error-style machine code from the JSON body, e.g. 'g2aba_csrf_failed'. */
  code?: string
  constructor(status: number, message: string, code?: string) {
    super(message)
    this.status = status
    this.code = code
  }
}

function apiUrl(path: string): string {
  return `${env.apiBase.replace(/\/$/, '')}${path}`
}

// Re-fetch the CSRF token from GET /auth/session (e.g. after the session
// was rotated in another tab). Returns true when a fresh token was stored.
async function rehydrateCsrf(): Promise<boolean> {
  try {
    const res = await fetch(apiUrl('/auth/session'), {
      headers: { Accept: 'application/json' },
      credentials: 'include',
    })
    if (res.status === 401) {
      csrfToken = null
      onUnauthorized?.()
      return false
    }
    if (!res.ok) return false
    const data = (await res.json()) as SessionResponse
    csrfToken = data.csrfToken ?? null
    return csrfToken !== null
  } catch {
    return false
  }
}

async function httpRaw(path: string, init?: RequestInit, isRetry = false): Promise<Response> {
  const method = (init?.method ?? 'GET').toUpperCase()
  const res = await fetch(apiUrl(path), {
    ...init,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      // CSRF protection: every unsafe (non-GET/HEAD) request must carry the
      // per-session token. The HttpOnly cookie alone must never authorize a
      // state change.
      ...(method !== 'GET' && method !== 'HEAD' && csrfToken
        ? { 'X-G2A-CSRF': csrfToken }
        : {}),
      ...(init?.headers || {}),
    },
    // The HttpOnly session cookie rides on every request.
    credentials: 'include',
  })
  if (res.ok) return res

  const text = await res.text().catch(() => '')
  let code: string | undefined
  try {
    code = (JSON.parse(text) as { code?: string }).code
  } catch {
    // Non-JSON error body — no machine code available.
  }

  if (res.status === 401) {
    // Session expired or revoked: drop client auth state; the app-level
    // handler clears the user and the router lands on /login.
    csrfToken = null
    onUnauthorized?.()
    throw new ApiError(401, text || res.statusText, code)
  }

  if (res.status === 403 && code === 'g2aba_csrf_failed' && !isRetry) {
    // Stale CSRF token — re-hydrate once via GET /auth/session, then retry
    // the request exactly once.
    if (await rehydrateCsrf()) return httpRaw(path, init, true)
  }

  throw new ApiError(res.status, text || res.statusText, code)
}

async function http<T>(path: string, init?: RequestInit): Promise<T> {
  const res = await httpRaw(path, init)
  return (await res.json()) as T
}

// ---------------------------------------------------------------------------
// Canonical envelope transport (Phase C endpoints)
// ---------------------------------------------------------------------------
//
// /dashboard/overview and /system/health speak the canonical envelope:
//   {"success":true,"data":...,"meta":{requestId,generatedAt,timezone,...}}
//   {"success":false,"error":{"code","message","requestId"}}
// This wrapper rides on the untouched Phase-2 http client (cookie session +
// CSRF + 401/403 handling all still apply) and unwraps the envelope, keeping
// `meta` visible to callers.

// httpRaw throws ApiError(message = raw body text) for non-2xx responses and
// only knows how to pull a WP_Error-style top-level `code`. Recover the
// envelope's structured error ({success:false,error:{...}}) from that body
// text so callers see the contract's code/message/requestId.
function toEnvelopeError(err: ApiError): ApiError {
  try {
    const parsed = JSON.parse(err.message) as { success?: boolean; error?: ApiErrorPayload }
    if (parsed && parsed.success === false && parsed.error?.message) {
      const { code, message, requestId } = parsed.error
      return new ApiError(err.status, requestId ? `${message} (request ${requestId})` : message, code)
    }
  } catch {
    // Body was not a JSON envelope — keep the original error.
  }
  return err
}

async function httpEnvelope<T>(path: string): Promise<Enveloped<T>> {
  let body: unknown
  try {
    body = await http<unknown>(path)
  } catch (err) {
    throw err instanceof ApiError ? toEnvelopeError(err) : err
  }
  const envelope = body as ApiEnvelope<T> | null
  if (envelope && typeof envelope === 'object' && envelope.success === true) {
    return { data: envelope.data, meta: envelope.meta }
  }
  // 200 body that is either {success:false,...} or not the envelope at all —
  // both are contract violations the UI must surface as an ERROR state, not
  // render as zeros.
  const errPayload = (envelope as ApiFailureLike | null)?.error
  throw new ApiError(
    502,
    errPayload?.message ?? `Malformed API envelope from ${path}`,
    errPayload?.code ?? 'g2a_bad_envelope',
  )
}

interface ApiFailureLike {
  error?: ApiErrorPayload
}

function unwrapMockEnvelope<T>(envelope: ApiSuccess<T>): Enveloped<T> {
  return { data: envelope.data, meta: envelope.meta }
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

function contentResource(type: ContentResourceType, pickMock: (m: MockModule) => WpContentItem[]) {
  return (params: ContentListParams = {}): Promise<ContentPage> =>
    env.useMocks
      ? mocks().then(m => mockContentPage(pickMock(m), params))
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
    // POST {API_BASE}/auth/session/login — the server validates the
    // credentials (a WordPress application password typed as the password
    // works too), sets the HttpOnly session cookie, and returns the user +
    // CSRF token. 401 on bad credentials, 429 when rate-limited.
    async login(username: string, password: string): Promise<SessionUser> {
      if (env.useMocks) {
        if (!username || !password) throw new ApiError(400, 'Username and password required')
        mockUser = {
          id: 1,
          displayName: username.split('@')[0] || 'Owner',
          email: username.includes('@') ? username : `${username}@guns2ammo.test`,
          capabilities: ['g2a_dashboard', 'g2a_dashboard_admin'],
        }
        csrfToken = 'mock-csrf-token'
        return mockUser
      }
      const res = await http<SessionResponse>('/auth/session/login', {
        method: 'POST',
        body: JSON.stringify({ username, password }),
      })
      csrfToken = res.csrfToken
      return res.user
    },

    // GET {API_BASE}/auth/session — hydrate an existing cookie session (and
    // the in-memory CSRF token) on app boot / reload. Resolves null when no
    // session exists (401).
    async session(): Promise<SessionUser | null> {
      if (env.useMocks) return mockUser
      try {
        const res = await http<SessionResponse>('/auth/session')
        csrfToken = res.csrfToken
        return res.user
      } catch (err) {
        if (err instanceof ApiError && err.status === 401) return null
        throw err
      }
    },

    // POST {API_BASE}/auth/session/logout — revokes the server session and
    // clears the cookie (CSRF header attached by httpRaw). Local auth state
    // is dropped even if the network call fails.
    async logout(): Promise<void> {
      if (env.useMocks) {
        mockUser = null
        csrfToken = null
        return
      }
      try {
        await http('/auth/session/logout', { method: 'POST' })
      } finally {
        csrfToken = null
      }
    },

    // POST {API_BASE}/auth/session/revoke-all — revokes every dashboard
    // session (including the caller's own; the next API call will 401 and
    // bounce to /login).
    async revokeAll(): Promise<SystemActionResult> {
      if (env.useMocks) throw new Error('revokeAll is unavailable in mock mode')
      const result = await http<Record<string, unknown>>('/auth/session/revoke-all', {
        method: 'POST',
      })
      csrfToken = null
      return { ok: true, result }
    },
  },

  dashboard: {
    // GET {API_BASE}/dashboard/overview?from=YYYY-MM-DD&to=YYYY-MM-DD
    // (canonical envelope). Omitting the range lets the server apply its
    // default window (last 30 days); meta is surfaced to the caller.
    overview(range?: Range): Promise<Enveloped<DashboardOverviewData>> {
      if (env.useMocks) return mocks().then(m => unwrapMockEnvelope(m.dashboardOverview))
      const qs =
        range && range.from && range.to
          ? `?from=${encodeURIComponent(range.from)}&to=${encodeURIComponent(range.to)}`
          : ''
      return httpEnvelope<DashboardOverviewData>(`/dashboard/overview${qs}`)
    },
  },

  analytics: {
    revenueOverview(range: Range = defaultRange()): Promise<RevenueOverview> {
      return env.useMocks
        ? mocks().then(m => m.revenueOverview)
        : http<RevenueOverview>(`/analytics/overview?from=${range.from}&to=${range.to}`)
    },
    bookings(range: Range = defaultRange()): Promise<BookingAnalytics> {
      return env.useMocks
        ? mocks().then(m => m.bookings)
        : http<BookingAnalytics>(`/analytics/bookings?from=${range.from}&to=${range.to}`)
    },
    memberships(range: Range = defaultRange()): Promise<MembershipAnalytics> {
      return env.useMocks
        ? mocks().then(m => m.memberships)
        : http<MembershipAnalytics>(`/analytics/memberships?from=${range.from}&to=${range.to}`)
    },
    store(range: Range = defaultRange()): Promise<StoreAnalytics> {
      return env.useMocks
        ? mocks().then(m => m.store)
        : http<StoreAnalytics>(`/analytics/store?from=${range.from}&to=${range.to}`)
    },
    seo(range: Range = defaultRange()): Promise<SeoAnalytics> {
      return env.useMocks
        ? mocks().then(m => m.seo)
        : http<SeoAnalytics>(`/analytics/seo?from=${range.from}&to=${range.to}`)
    },
    insightistic(range: Range = defaultRange()): Promise<InsightisticAnalytics> {
      return env.useMocks
        ? mocks().then(m => m.insightistic)
        : http<InsightisticAnalytics>(`/analytics/insightistic?from=${range.from}&to=${range.to}`)
    },
    shooterInsights(): Promise<ShooterInsights> {
      return env.useMocks
        ? mocks().then(m => m.shooterInsights)
        : http<ShooterInsights>('/analytics/shooter-insights')
    },
  },

  gaps: {
    list(): Promise<BusinessGap[]> {
      return env.useMocks ? mocks().then(m => m.gaps) : http<BusinessGap[]>('/insights/business-gaps')
    },
  },

  insights: {
    list(): Promise<AIInsight[]> {
      return env.useMocks ? mocks().then(m => m.insights) : http<AIInsight[]>('/ai/insights')
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
      if (env.useMocks) return (await mocks()).tasks.filter(t => status === 'all' || t.status === 'open')
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
      return env.useMocks ? mocks().then(m => m.automations) : http<Automation[]>('/automations')
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
        return mocks().then(m => ({
          purposes: m.mockPurposes,
          labels: m.mockRoutingLabels,
          routing: m.mockRouting,
        }))
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
    posts: contentResource('posts', m => m.contentPosts),
    pages: contentResource('pages', m => m.contentPages),
    media: contentResource('media', m => m.contentMedia),
    categories: contentResource('categories', m => m.contentCategories),
    tags: contentResource('tags', m => m.contentTags),
  },

  system: {
    // GET {API_BASE}/system/health — canonical-envelope health report
    // (plugins, cron, sessions, audit, integrations). Replaces the legacy
    // SystemHealthCheck[] payload this route served pre-Phase-C.
    health(): Promise<Enveloped<SystemHealthData>> {
      return env.useMocks
        ? mocks().then(m => unwrapMockEnvelope(m.systemHealth))
        : httpEnvelope<SystemHealthData>('/system/health')
    },
    integrations(): Promise<IntegrationsStatus> {
      return env.useMocks
        ? mocks().then(m => m.integrations)
        : http<IntegrationsStatus>('/system/integrations')
    },
    namespaces(): Promise<NamespacesStatus> {
      return env.useMocks
        ? mocks().then(m => m.namespacesStatus)
        : http<NamespacesStatus>('/system/namespaces')
    },
    siteHealth(): Promise<SiteHealthSummary> {
      return env.useMocks
        ? mocks().then(m => m.siteHealthSummary)
        : http<SiteHealthSummary>('/system/site-health')
    },
    async rotateKeys(): Promise<SystemActionResult> {
      if (env.useMocks) throw new Error('rotateKeys is unavailable in mock mode')
      return http<SystemActionResult>('/system/rotate-keys', {
        method: 'POST',
        body: JSON.stringify({ confirm: true }),
      })
    },
    // Legacy nuclear option: deletes every WP application password for
    // dashboard users. Kept for the Basic-auth migration window; the
    // Settings UI now uses api.auth.revokeAll() (cookie sessions) instead.
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
    // Plain <a href> download links, kept as top-level GET navigations on
    // purpose: the HttpOnly session cookie flows on same-site top-level GETs
    // (SameSite=Lax), so no Authorization header or JS fetch is needed and
    // the browser handles Content-Disposition natively. These URLs point at
    // API_BASE and RELY ON THE SESSION COOKIE — they 401 if signed out.
    tasksCsvUrl(): string {
      return apiUrl('/export/tasks.csv')
    },
    auditCsvUrl(): string {
      return apiUrl('/export/audit-log.csv')
    },
    reportTxtUrl(id: string): string {
      return apiUrl(`/export/reports/${id}.txt`)
    },
  },

  agents: {
    list(): Promise<Agent[]> {
      return env.useMocks ? mocks().then(m => m.agents) : http<Agent[]>('/agents')
    },
    async run(id: string): Promise<void> {
      if (env.useMocks) return
      await http(`/agents/${id}/run`, { method: 'POST' })
    },
  },

  leads: {
    async list(params: LeadsListParams = {}): Promise<LeadsPage> {
      if (env.useMocks) {
        let items = (await mocks()).leads
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
        return { items: items.slice(start, start + perPage), total }
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
      return env.useMocks ? mocks().then(m => m.leadStats) : http<LeadStats>('/leads/stats')
    },
    async get(id: number): Promise<Lead> {
      if (env.useMocks) {
        const found = (await mocks()).leads.find(l => l.id === id)
        if (!found) throw new ApiError(404, 'Lead not found')
        return found
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
      if (env.useMocks) return mocks().then(m => m.brainQueryResult)
      const qs = new URLSearchParams({ q })
      if (k) qs.set('k', String(k))
      if (scope) qs.set('scope', scope)
      return http<BrainQueryResult>(`/brain/query?${qs.toString()}`)
    },
    stats(scope?: string): Promise<BrainStats> {
      if (env.useMocks) return mocks().then(m => m.brainStats)
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
      return env.useMocks ? mocks().then(m => m.models) : http<ModelConnection[]>('/model-connections')
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
      if (env.useMocks) return { ok: true, provider: 'mock', models: (await mocks()).modelCatalog }
      return http<ModelCatalogResult>(`/model-connections/${id}/catalog`)
    },
  },

  reports: {
    list(): Promise<ReportDefinition[]> {
      if (env.useMocks) return mocks().then(m => m.reports ?? [])
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
      if (env.useMocks) return mocks().then(m => m.dashboardSettings)
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
        ? mocks().then(m => m.emailOverview)
        : http<EmailOverview>('/emails/overview')
    },
  },

  emailDrafts: {
    async list(status: 'pending' | 'all' = 'pending'): Promise<EmailDraft[]> {
      if (env.useMocks) return (await mocks()).emailDrafts.filter(d => status === 'all' || d.status === 'pending_send')
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
      if (env.useMocks) return (await mocks()).cancellations.filter(c => status === 'all' || c.status === 'awaiting_manual_action')
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
      if (env.useMocks) return (await mocks()).agentHistory[agentId] ?? []
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
      if (env.useMocks) return (await mocks()).auditLog
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
