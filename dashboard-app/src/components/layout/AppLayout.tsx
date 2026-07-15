import { useRef, useState } from 'react'
import { Outlet, useNavigate } from 'react-router-dom'
import { Sidebar } from './Sidebar'
import { TopBar } from './TopBar'
import type { SessionUser } from '@/types/auth'
import { api } from '@/lib/api'
import { useBodyScrollLock, useDialogA11y } from '@/lib/hooks'

interface Props {
  session: SessionUser
  onSessionChange: (s: SessionUser | null) => void
}

export function AppLayout({ session, onSessionChange }: Props) {
  const nav = useNavigate()
  const [mobileOpen, setMobileOpen] = useState(false)

  // Lock body scroll while the mobile nav drawer is open so the page behind
  // it doesn't scroll along with it (reliably on iOS Safari too).
  useBodyScrollLock(mobileOpen)

  async function signOut() {
    try {
      // Server-side revocation: POST /auth/session/logout clears the
      // HttpOnly cookie and kills the session record.
      await api.auth.logout()
    } finally {
      // Local state is dropped even if the network call failed — the
      // worst case is a still-valid cookie that the next boot re-hydrates.
      onSessionChange(null)
      nav('/login', { replace: true })
    }
  }

  return (
    <div className="flex h-full">
      <div className="hidden lg:block">
        <Sidebar />
      </div>

      {mobileOpen && (
        <MobileNavDrawer onClose={() => setMobileOpen(false)} />
      )}

      <div className="flex-1 flex flex-col min-w-0">
        <TopBar
          session={session}
          onSignOut={signOut}
          onOpenSidebar={() => setMobileOpen(true)}
        />
        <main className="flex-1 overflow-y-auto">
          <div className="px-3 sm:px-4 lg:px-8 py-4 sm:py-6 max-w-[1400px] mx-auto w-full">
            <Outlet />
          </div>
        </main>
      </div>
    </div>
  )
}

function MobileNavDrawer({ onClose }: { onClose: () => void }) {
  const dialogRef = useRef<HTMLDivElement>(null)
  useDialogA11y(dialogRef, onClose)

  return (
    <div
      className="fixed inset-0 z-30 lg:hidden"
      style={{ backgroundColor: 'var(--bg-overlay)' }}
      onClick={onClose}
    >
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-label="Navigation"
        tabIndex={-1}
        className="absolute inset-y-0 left-0"
        onClick={e => e.stopPropagation()}
      >
        <Sidebar onNavigate={onClose} />
      </div>
    </div>
  )
}
