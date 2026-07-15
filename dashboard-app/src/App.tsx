import { lazy, Suspense, useEffect, useMemo, useState } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout } from '@/components/layout/AppLayout'
import { api, setUnauthorizedHandler } from '@/lib/api'
import type { SessionUser } from '@/types/auth'
import { Spinner } from '@/components/ui/Spinner'
import { Login } from '@/pages/Login'
import { DashboardHome } from '@/pages/DashboardHome'

// Route-level code splitting: only Login + the home screen ship in the main
// bundle; every other page loads on first visit. Pages use named exports,
// hence the `.then(m => ({ default: m.X }))` shims.
const BusinessAnalysis      = lazy(() => import('@/pages/BusinessAnalysis').then(m => ({ default: m.BusinessAnalysis })))
const InsightisticAnalytics = lazy(() => import('@/pages/InsightisticAnalytics').then(m => ({ default: m.InsightisticAnalytics })))
const BookingRevenue        = lazy(() => import('@/pages/BookingRevenue').then(m => ({ default: m.BookingRevenue })))
const MembershipRevenue     = lazy(() => import('@/pages/MembershipRevenue').then(m => ({ default: m.MembershipRevenue })))
const WaiverAnalytics       = lazy(() => import('@/pages/WaiverAnalytics').then(m => ({ default: m.WaiverAnalytics })))
const WooStoreAnalytics     = lazy(() => import('@/pages/WooStoreAnalytics').then(m => ({ default: m.WooStoreAnalytics })))
const SEOGrowth             = lazy(() => import('@/pages/SEOGrowth').then(m => ({ default: m.SEOGrowth })))
const ShooterInsights       = lazy(() => import('@/pages/ShooterInsights').then(m => ({ default: m.ShooterInsights })))
const BusinessGaps          = lazy(() => import('@/pages/BusinessGaps').then(m => ({ default: m.BusinessGaps })))
const AIInsights            = lazy(() => import('@/pages/AIInsights').then(m => ({ default: m.AIInsights })))
const AutomationCenter      = lazy(() => import('@/pages/AutomationCenter').then(m => ({ default: m.AutomationCenter })))
const AIAgents              = lazy(() => import('@/pages/AIAgents').then(m => ({ default: m.AIAgents })))
const EmailManagement       = lazy(() => import('@/pages/EmailManagement').then(m => ({ default: m.EmailManagement })))
const Leads                 = lazy(() => import('@/pages/Leads').then(m => ({ default: m.Leads })))
const Waivers               = lazy(() => import('@/pages/Waivers').then(m => ({ default: m.Waivers })))
const BridGistic            = lazy(() => import('@/pages/BridGistic').then(m => ({ default: m.BridGistic })))
const AIModelsRAGs          = lazy(() => import('@/pages/AIModels').then(m => ({ default: m.AIModelsRAGs })))
const Reports               = lazy(() => import('@/pages/Reports').then(m => ({ default: m.Reports })))
const SystemHealth          = lazy(() => import('@/pages/SystemHealth').then(m => ({ default: m.SystemHealth })))
const Settings              = lazy(() => import('@/pages/Settings').then(m => ({ default: m.Settings })))
const OpsQueue              = lazy(() => import('@/pages/OpsQueue').then(m => ({ default: m.OpsQueue })))
const Tasks                 = lazy(() => import('@/pages/Tasks').then(m => ({ default: m.Tasks })))
const WebsiteContent        = lazy(() => import('@/pages/WebsiteContent').then(m => ({ default: m.WebsiteContent })))

export function App() {
  const [session, setSession] = useState<SessionUser | null>(null)
  const [booting, setBooting] = useState(true)

  // Global 401 handling: any API call that comes back unauthorized clears
  // the auth state, and the route guards below land on /login.
  useEffect(() => {
    setUnauthorizedHandler(() => setSession(null))
    return () => setUnauthorizedHandler(null)
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
          <Route path="*" element={<Navigate to="/login" replace />} />
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
    <BrowserRouter>
      {/* Suspense boundary for the lazy route chunks above. */}
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
  )
}
