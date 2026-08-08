// On-wire shapes for the Phase 2 cookie-session auth contract.
//
// The session credential itself is an HttpOnly cookie set by the server —
// JavaScript never sees it. The only auth material the client handles is
// the per-session CSRF token, and that lives in module memory only
// (see src/lib/api.ts) — never in localStorage/sessionStorage.

export interface SessionUser {
  id: number
  displayName: string
  email: string
  capabilities: string[]
}

// Returned by POST /auth/session/login and GET /auth/session.
export interface SessionResponse {
  user: SessionUser
  csrfToken: string
}
