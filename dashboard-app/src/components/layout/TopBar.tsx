import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { initials } from '@/lib/format'
import type { Session } from '@/lib/api'
import { useTheme } from '@/lib/theme'

interface Props {
  session: Session
  onSignOut: () => void
  onOpenSidebar: () => void
}

export function TopBar({ session, onSignOut, onOpenSidebar }: Props) {
  const nav = useNavigate()
  const [command, setCommand] = useState('')
  const { theme, toggle } = useTheme()

  return (
    <header
      className="sticky top-0 z-20 backdrop-blur px-3 sm:px-4 lg:px-6 py-3"
      style={{
        backgroundColor: 'color-mix(in srgb, var(--bg-surface) 85%, transparent)',
        borderBottom: '1px solid var(--border-subtle)',
      }}
    >
      <div className="flex items-center gap-2 sm:gap-3">
        <button
          className="lg:hidden btn-ghost !px-2"
          onClick={onOpenSidebar}
          aria-label="Open navigation"
        >
          ☰
        </button>

        <form
          className="flex-1 flex items-center gap-2 min-w-0"
          onSubmit={e => {
            e.preventDefault()
            const q = command.trim()
            if (!q) return
            // Route BridGistic-style natural language into the AI command page,
            // which owns the permission-checked action bridge.
            nav(`/bridgistic?q=${encodeURIComponent(q)}`)
          }}
        >
          <div className="relative flex-1 min-w-0 max-w-xl">
            <span
              className="absolute left-3 top-1/2 -translate-y-1/2 text-sm"
              style={{ color: 'var(--text-subtle)' }}
            >
              ⇌
            </span>
            <input
              value={command}
              onChange={e => setCommand(e.target.value)}
              className="input pl-9"
              placeholder='Ask BridGistic…'
              aria-label="Ask BridGistic"
            />
          </div>
          <button type="submit" className="btn-primary hidden sm:inline-flex">
            Ask
          </button>
        </form>

        <div className="flex items-center gap-2 sm:gap-3">
          <button
            className="btn-ghost !px-2 hidden sm:inline-flex"
            onClick={toggle}
            aria-label={`Switch to ${theme === 'dark' ? 'light' : 'dark'} mode`}
            title={`Switch to ${theme === 'dark' ? 'light' : 'dark'} mode`}
          >
            {theme === 'dark' ? '☀' : '☾'}
          </button>
          <div className="text-right leading-tight hidden md:block">
            <div className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
              {session.displayName}
            </div>
            <div className="text-xs capitalize" style={{ color: 'var(--text-muted)' }}>
              {session.role}
            </div>
          </div>
          <button
            onClick={onSignOut}
            className="h-9 w-9 rounded-full text-white text-sm font-semibold flex items-center justify-center shrink-0"
            style={{ backgroundColor: 'var(--brand)' }}
            title="Sign out"
          >
            {initials(session.displayName)}
          </button>
        </div>
      </div>
    </header>
  )
}
