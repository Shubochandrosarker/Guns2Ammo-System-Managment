import { useState } from 'react'
import { Outlet, useNavigate } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { TopBar } from './TopBar'
import type { Session } from '@/lib/api'
import { api } from '@/lib/api'

interface Props {
  session: Session
  onSessionChange: (s: Session | null) => void
}

export function AppLayout({ session, onSessionChange }: Props) {
  const nav = useNavigate()
  const [mobileOpen, setMobileOpen] = useState(false)

  function signOut() {
    api.auth.logout()
    onSessionChange(null)
    nav('/login', { replace: true })
  }

  return (
    <div className="flex h-full">
      <div className="hidden lg:block">
        <Sidebar />
      </div>

      {mobileOpen && (
        <div
          className="fixed inset-0 z-30 lg:hidden bg-ink-900/60"
          onClick={() => setMobileOpen(false)}
        >
          <div
            className="absolute inset-y-0 left-0"
            onClick={e => e.stopPropagation()}
          >
            <Sidebar onNavigate={() => setMobileOpen(false)} />
          </div>
        </div>
      )}

      <div className="flex-1 flex flex-col min-w-0">
        <TopBar
          session={session}
          onSignOut={signOut}
          onOpenSidebar={() => setMobileOpen(true)}
        />
        <main className="flex-1 overflow-y-auto">
          <div className="px-4 lg:px-8 py-6 max-w-[1400px] mx-auto w-full">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}
