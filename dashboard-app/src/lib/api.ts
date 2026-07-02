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
      ...(session ? { Authorization: `Bearer ${session.token}` } : {}),
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
      const session = await http<Session>('/auth/login', {
        method: 'POST',
        body: JSON.stringify({ email, password }),
      })
      writeSession(session)
      return session
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
}
