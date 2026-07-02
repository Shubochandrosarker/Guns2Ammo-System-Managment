import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { initials } from '@/lib/format'
import type { Session } from '@/lib/api'

interface Props {
  session: Session
  onSignOut: () => void
  onOpenSidebar: () => void
}

export function TopBar({ session, onSignOut, onOpenSidebar }: Props) {
  const nav = useNavigate()
  const [command, setCommand] = useState('')

  return (
    <header className="sticky top-0 z-20 bg-white/90 backdrop-blur border-b border-ink-100 px-4 lg:px-6 py-3">
      <div className="flex items-center gap-3">
        <button
          className="lg:hidden btn-ghost !px-2"
          onClick={onOpenSidebar}
          aria-label="Open navigation"
        >
          ☰
        </button>

        <form
          className="flex-1 flex items-center gap-2"
          onSubmit={e => {
            e.preventDefault()
            const q = command.trim()
            if (!q) return
            // Route BridGistic-style natural language into the AI command page,
            // which owns the permission-checked action bridge.
            nav(`/bridgistic?q=${encodeURIComponent(q)}`)
          }}
        >
          <div className="relative flex-1 max-w-xl">
            <span className="absolute left-3 top-1/2 -translate-y-1/2 text-ink-400 text-sm">
              ⇌
            </span>
            <input
              value={command}
              onChange={e => setCommand(e.target.value)}
              className="input pl-9"
              placeholder='Ask BridGistic — e.g. "show this week&apos;s booking revenue"'
              aria-label="Ask BridGistic"
            />
          </div>
          <button type="submit" className="btn-primary hidden sm:inline-flex">
            Ask
          </button>
        </form>

        <div className="flex items-center gap-3">
          <div className="text-right leading-tight hidden sm:block">
            <div className="text-sm font-medium text-ink-800">{session.displayName}</div>
            <div className="text-xs text-ink-500 capitalize">{session.role}</div>
          </div>
          <button
            onClick={onSignOut}
            className="h-9 w-9 rounded-full bg-brand-500 text-white text-sm font-semibold flex items-center justify-center"
            title="Sign out"
          >
            {initials(session.displayName)}
          </button>
        </div>
      </div>
    </header>
  )
}
