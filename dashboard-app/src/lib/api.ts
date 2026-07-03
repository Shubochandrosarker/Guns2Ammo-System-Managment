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
  BusinessGap,
  InsightisticAnalytics,
  MembershipAnalytics,
  ModelConnection,
  Range,
  RevenueOverview,
  SeoAnalytics,
  StoreAnalytics,
  SystemHealthCheck,
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

async function http<T>(path: string, init?: RequestInit): Promise<T> {
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
  return (await res.json()) as T
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

  models: {
    list(): Promise<ModelConnection[]> {
      return env.useMocks ? Promise.resolve(mock.models) : http<ModelConnection[]>('/model-connections')
    },
    async test(id: string): Promise<{ ok: boolean; latencyMs?: number; error?: string }> {
      if (env.useMocks) return { ok: true, latencyMs: 240 }
      return http(`/model-connections/${id}/test`, { method: 'POST' })
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
