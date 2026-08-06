import { lazy, Suspense, useEffect, useMemo, useState } from 'react'
import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { AppLayout } from '@/components/layout/AppLayout'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { api, setUnauthorizedHandler } from '@/lib/api'
import { clearChunkReloadFlag, retryImport } from '@/lib/lazy'
import type { SessionUser } from '@/types/auth'
import { Spinner } from '@/components/ui/Spinner'
import { Login } from '@/pages/Login'
import { DashboardHome } from '@/pages/DashboardHome'

// Route-level code splitting: only Login + the home screen ship in the main
// bundle; every other page loads on first visit. Pages use named exports,
// hence the `.then(m => ({ default: m.X }))` shims.
//
// Every import goes through retryImport (src/lib/lazy.ts): after a deploy the
// hashed chunk filenames change, so an already-open tab asks for a file that
// no longer exists and the import rejects. retryImport reloads the page once
// to pick up the new bundle instead of letting the app go blank.
const BusinessAnalysis      = lazy(retryImport(() => import('@/pages/BusinessAnalysis').then(m => ({ default: m.BusinessAnalysis }))))
const InsightisticAnalytics = lazy(retryImport(() => import('@/pages/InsightisticAnalytics').then(m => ({ default: m.InsightisticAnalytics }))))
const BookingRevenue        = lazy(retryImport(() => import('@/pages/BookingRevenue').then(m => ({ default: m.BookingRevenue }))))
const MembershipRevenue     = lazy(retryImport(() => import('@/pages/MembershipRevenue').then(m => ({ default: m.MembershipRevenue }))))
const WaiverAnalytics       = lazy(retryImport(() => import('@/pages/WaiverAnalytics').then(m => ({ default: m.WaiverAnalytics }))))
const WooStoreAnalytics     = lazy(retryImport(() => import('@/pages/WooStoreAnalytics').then(m => ({ default: m.WooStoreAnalytics }))))
const SEOGrowth             = lazy(retryImport(() => import('@/pages/SEOGrowth').then(m => ({ default: m.SEOGrowth }))))
const ShooterInsights       = lazy(retryImport(() => import('@/pages/ShooterInsights').then(m => ({ default: m.ShooterInsights }))))
const BusinessGaps          = lazy(retryImport(() => import('@/pages/BusinessGaps').then(m => ({ default: m.BusinessGaps }))))
const AIInsights            = lazy(retryImport(() => import('@/pages/AIInsights').then(m => ({ default: m.AIInsights }))))
const AutomationCenter      = lazy(retryImport(() => import('@/pages/AutomationCenter').then(m => ({ default: m.AutomationCenter }))))
const AIAgents              = lazy(retryImport(() => import('@/pages/AIAgents').then(m => ({ default: m.AIAgents }))))
const EmailManagement       = lazy(retryImport(() => import('@/pages/EmailManagement').then(m => ({ default: m.EmailManagement }))))
const Leads                 = lazy(retryImport(() => import('@/pages/Leads').then(m => ({ default: m.Leads }))))
const Waivers               = lazy(retryImport(() => import('@/pages/Waivers').then(m => ({ default: m.Waivers }))))
const BridGistic            = lazy(retryImport(() => import('@/pages/BridGistic').then(m => ({ default: m.BridGistic }))))
const AIModelsRAGs          = lazy(retryImport(() => import('@/pages/AIModels').then(m => ({ default: m.AIModelsRAGs }))))
const Reports               = lazy(retryImport(() => import('@/pages/Reports').then(m => ({ default: m.Reports }))))
const SystemHealth          = lazy(retryImport(() => import('@/pages/SystemHealth').then(m => ({ default: m.SystemHealth }))))
const Settings              = lazy(retryImport(() => import('@/pages/Settings').then(m => ({ default: m.Settings }))))
const OpsQueue              = lazy(retryImport(() => import('@/pages/OpsQueue').then(m => ({ default: m.OpsQueue }))))
const Tasks                 = lazy(retryImport(() => import('@/pages/Tasks').then(m => ({ default: m.Tasks }))))
const WebsiteContent        = lazy(retryImport(() => import('@/pages/WebsiteContent').then(m => ({ default: m.WebsiteContent }))))

/**
 * Signed-out catch-all. Sends the visitor to /login while remembering where
 * they were actually going, so an emailed or bookmarked deep link such as
 * /waivers survives the sign-in instead of silently dumping them on the home
 * screen with no clue the link went anywhere.
 */
function RedirectToLogin() {
  const location = useLocation()
  const from = `${location.pathname}${location.search}${location.hash}`
  return (
    <Navigate
      to="/login"
      replace
      state={from === '/' || from.startsWith('/login') ? undefined : { from }}
    />
  )
}

export function App() {
  const [session, setSession] = useState<SessionUser | null>(null)
  const [booting, setBooting] = useState(true)

  // Global 401 handling: any API call that comes back unauthorized clears
  // the auth state, and the route guards below land on /login.
  useEffect(() => {
    setUnauthorizedHandler(() => setSession(null))
    return () => setUnauthorizedHandler(null)
  }, [])

  // The app got far enough to run an effect, so whatever chunk failure caused
  // a recovery reload is behind us. Release the one-shot guard so the NEXT
  // deploy in this same long-lived tab gets its own recovery attempt.
  useEffect(() => {
    clearChunkReloadFlag()
  }, [])

  // Hydrate the HttpOnly cookie session on boot: GET /auth/session returns
  // the user (and refreshes the in-memory CSRF token inside the api module)
  // or resolves null on 401 → login screen. Nothing auth-related is read
  // from localStorage anymore.
  useEffect(() => {
    let cancelled = false
    void api.auth
      .session()
      .then(user => {
        if (!cancelled) setSession(user)
      })
      .catch(() => {
        if (!cancelled) setSession(null)
      })
      .finally(() => {
        if (!cancelled) setBooting(false)
      })
    return () => {
      cancelled = true
    }
  }, [])

  const routes = useMemo(
    () => (
      <Routes>
        <Route
          path="/login"
          element={
            session ? <Navigate to="/" replace /> : <Login onSignedIn={setSession} />
          }
        />
        {session ? (
          // AppLayout wraps <Outlet/> in its own Suspense + ErrorBoundary, so
          // a slow chunk or a throwing screen only affects the content area —
          // the sidebar and top bar stay put and every other screen is still
          // one click away.
          <Route element={<AppLayout session={session} onSessionChange={setSession} />}>
            <Route index element={<DashboardHome />} />
            <Route path="website-content"      element={<WebsiteContent />} />
            <Route path="business-analysis"    element={<BusinessAnalysis />} />
            <Route path="insightistic"         element={<InsightisticAnalytics />} />
            <Route path="booking-revenue"      element={<BookingRevenue />} />
            <Route path="membership-revenue"   element={<MembershipRevenue />} />
            <Route path="waiver-analytics"     element={<WaiverAnalytics />} />
            <Route path="woo-store-analytics"  element={<WooStoreAnalytics />} />
            <Route path="seo-growth"           element={<SEOGrowth />} />
            <Route path="shooter-insights"     element={<ShooterInsights />} />
            <Route path="business-gaps"        element={<BusinessGaps />} />
            <Route path="ai-insights"          element={<AIInsights />} />
            <Route path="automation-center"    element={<AutomationCenter />} />
            <Route path="ai-agents"            element={<AIAgents />} />
            <Route path="email-management"     element={<EmailManagement />} />
            <Route path="leads"                element={<Leads />} />
            <Route path="waivers"              element={<Waivers />} />
            <Route path="bridgistic"           element={<BridGistic />} />
            <Route path="ai-models"            element={<AIModelsRAGs />} />
            <Route path="reports"              element={<Reports />} />
            <Route path="ops-queue"            element={<OpsQueue />} />
            <Route path="tasks"                element={<Tasks />} />
            <Route path="system-health"        element={<SystemHealth />} />
            <Route path="settings"             element={<Settings />} />
            <Route path="*"                    element={<Navigate to="/" replace />} />
          </Route>
        ) : (
          <Route path="*" element={<RedirectToLogin />} />
        )}
      </Routes>
    ),
    [session],
  )

  if (booting) {
    // Session probe in flight — avoid flashing the login screen.
    return (
      <div className="min-h-full flex items-center justify-center">
        <Spinner label="Signing in…" />
      </div>
    )
  }

  return (
    // Outermost boundary: the last line of defence between a render error and
    // an empty <div id="root">.
    <ErrorBoundary>
      <BrowserRouter>
        {/*
          Safety-net Suspense. In practice the boundary inside AppLayout
          catches the lazy routes first; this one exists so a lazy component
          added outside the layout can never suspend with no boundary above it.
        */}
        <Suspense
          fallback={
            <div className="min-h-full flex items-center justify-center">
              <Spinner label="Loading…" />
            </div>
          }
        >
          {routes}
        </Suspense>
      </BrowserRouter>
    </ErrorBoundary>
  )
}
