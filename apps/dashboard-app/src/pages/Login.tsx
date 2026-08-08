import { FormEvent, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { api, ApiError } from '@/lib/api'
import { safeRedirect } from '@/lib/redirect'
import type { SessionUser } from '@/types/auth'

interface Props {
  onSignedIn: (u: SessionUser) => void
}

function loginErrorMessage(err: unknown): string {
  if (err instanceof ApiError) {
    if (err.status === 429) {
      return 'Too many sign-in attempts — please wait a few minutes before trying again.'
    }
    if (err.status === 401) {
      return 'Invalid username or password.'
    }
  }
  return err instanceof Error && err.message ? err.message : 'Sign-in failed'
}

export function Login({ onSignedIn }: Props) {
  const nav = useNavigate()
  const location = useLocation()
  const [username, setUsername] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      // POST /auth/session/login sets the HttpOnly session cookie; nothing
      // credential-shaped is ever stored client-side.
      const user = await api.auth.login(username, password)
      onSignedIn(user)
      nav(safeRedirect(location.state), { replace: true })
    } catch (err) {
      setError(loginErrorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="min-h-full flex items-center justify-center bg-ink-900 p-4">
      <div className="w-full max-w-md card overflow-hidden">
        <div className="bg-ink-900 text-white p-6">
          <div className="flex items-center gap-3">
            <div className="h-10 w-10 rounded-lg bg-brand-500 flex items-center justify-center font-bold">
              G2
            </div>
            <div>
              <div className="text-lg font-semibold">Guns2Ammo</div>
              <div className="text-xs text-ink-400">Business Control Center</div>
            </div>
          </div>
          <p className="mt-4 text-sm text-ink-300 leading-relaxed">
            Owner &amp; manager brain of the business — analytics, AI insights,
            automations, and BridGistic-controlled actions. POS is separate.
          </p>
        </div>

        <form onSubmit={submit} className="p-6 space-y-4">
          <div>
            <label className="label" htmlFor="username">Username</label>
            <input
              id="username"
              type="text"
              autoComplete="username"
              className="input"
              value={username}
              onChange={e => setUsername(e.target.value)}
              placeholder="Your WordPress username or email"
              required
              disabled={busy}
            />
          </div>
          <div>
            <label className="label" htmlFor="password">Password</label>
            <input
              id="password"
              type="password"
              autoComplete="current-password"
              className="input"
              value={password}
              onChange={e => setPassword(e.target.value)}
              required
              disabled={busy}
            />
            <p className="mt-1 text-xs text-ink-500">
              Your WordPress password. A WordPress <em>application password</em>{' '}
              pasted here works too.
            </p>
          </div>

          {error && (
            <div className="text-sm text-rose-700 bg-rose-100 rounded-lg px-3 py-2" role="alert">
              {error}
            </div>
          )}

          <button className="btn-primary w-full" type="submit" disabled={busy}>
            {busy ? 'Signing in…' : 'Sign in'}
          </button>

          <div className="text-xs text-ink-500 text-center pt-2">
            Sign-in is served by the <code className="font-mono">g2a-business-api</code>{' '}
            plugin. In dev, any username/password works (mock mode).
          </div>
        </form>
      </div>
    </div>
  )
}
