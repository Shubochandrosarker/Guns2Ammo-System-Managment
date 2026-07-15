import { useEffect, useMemo, useState } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout } from '@/components/layout/AppLayout'
import { api, setUnauthorizedHandler } from '@/lib/api'
import type { SessionUser } from '@/types/auth'
import { Spinner } from '@/components/ui/Spinner'
import { Login } from '@/pages/Login'
import { DashboardHome } from '@/pages/DashboardHome'
import { BusinessAnalysis } from '@/pages/BusinessAnalysis'
import { InsightisticAnalytics } from '@/pages/InsightisticAnalytics'
import { BookingRevenue } from '@/pages/BookingRevenue'
import { MembershipRevenue } from '@/pages/MembershipRevenue'
import { WooStoreAnalytics } from '@/pages/WooStoreAnalytics'
import { SEOGrowth } from '@/pages/SEOGrowth'
import { ShooterInsights } from '@/pages/ShooterInsights'
import { BusinessGaps } from '@/pages/BusinessGaps'
import { AIInsights } from '@/pages/AIInsights'
import { AutomationCenter } from '@/pages/AutomationCenter'
import { AIAgents } from '@/pages/AIAgents'
import { EmailManagement } from '@/pages/EmailManagement'
import { Leads } from '@/pages/Leads'
import { BridGistic } from '@/pages/BridGistic'
import { AIModelsRAGs } from '@/pages/AIModels'
import { Reports } from '@/pages/Reports'
import { SystemHealth } from '@/pages/SystemHealth'
import { Settings } from '@/pages/Settings'
import { OpsQueue } from '@/pages/OpsQueue'
import { Tasks } from '@/pages/Tasks'
import { WebsiteContent } from '@/pages/WebsiteContent'

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
            <Route path="woo-store-analytics"  element={<WooStoreAnalytics />} />
            <Route path="seo-growth"           element={<SEOGrowth />} />
            <Route path="shooter-insights"     element={<ShooterInsights />} />
            <Route path="business-gaps"        element={<BusinessGaps />} />
            <Route path="ai-insights"          element={<AIInsights />} />
            <Route path="automation-center"    element={<AutomationCenter />} />
            <Route path="ai-agents"            element={<AIAgents />} />
            <Route path="email-management"     element={<EmailManagement />} />
            <Route path="leads"                element={<Leads />} />
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

  return <BrowserRouter>{routes}</BrowserRouter>
}
