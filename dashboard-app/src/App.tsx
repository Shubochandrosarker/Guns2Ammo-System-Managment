import { useMemo, useState } from 'react'
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { AppLayout } from '@/components/layout/AppLayout'
import { readSession, type Session } from '@/lib/api'
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
import { BridGistic } from '@/pages/BridGistic'
import { AIModelsRAGs } from '@/pages/AIModels'
import { Reports } from '@/pages/Reports'
import { SystemHealth } from '@/pages/SystemHealth'
import { Settings } from '@/pages/Settings'

export function App() {
  const [session, setSession] = useState<Session | null>(() => readSession())

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
            <Route path="bridgistic"           element={<BridGistic />} />
            <Route path="ai-models"            element={<AIModelsRAGs />} />
            <Route path="reports"              element={<Reports />} />
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

  return <BrowserRouter>{routes}</BrowserRouter>
}
