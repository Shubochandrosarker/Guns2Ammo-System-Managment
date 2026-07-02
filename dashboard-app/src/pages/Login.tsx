import { FormEvent, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, type Session } from '@/lib/api'

interface Props {
  onSignedIn: (s: Session) => void
}

export function Login({ onSignedIn }: Props) {
  const nav = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function submit(e: FormEvent) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const session = await api.auth.login(email, password)
      onSignedIn(session)
      nav('/', { replace: true })
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign-in failed')
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
            <label className="label" htmlFor="email">Email</label>
            <input
              id="email"
              type="email"
              autoComplete="email"
              className="input"
              value={email}
              onChange={e => setEmail(e.target.value)}
              placeholder="owner@guns2ammo.com"
              required
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
            />
          </div>

          {error && (
            <div className="text-sm text-rose-700 bg-rose-100 rounded-lg px-3 py-2">
              {error}
            </div>
          )}

          <button className="btn-primary w-full" type="submit" disabled={busy}>
            {busy ? 'Signing in…' : 'Sign in'}
          </button>

          <div className="text-xs text-ink-500 text-center pt-2">
            Sign-in is served by the <code className="font-mono">g2a-business-api</code>{' '}
            plugin. In dev, any email/password works (mock mode).
          </div>
        </form>
      </div>
    </div>
  )
}
