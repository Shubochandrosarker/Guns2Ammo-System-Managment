import { NavLink, useLocation } from 'react-router-dom'

// Dashboard Home is a single ungrouped top link (index page). Everything
// else is grouped below — regrouping labels only, no route paths changed.
const TOP_LINK = { to: '/', label: 'Dashboard Home', icon: '⌂' } as const

// Group -> items. `label` is the *frontend* naming the user asked for.
const NAV = [
  {
    group: 'Website',
    items: [
      { to: '/website-content',      label: 'Website Content',       icon: '⌗' },
    ],
  },
  {
    group: 'Operations',
    items: [
      { to: '/booking-revenue',      label: 'Booking Revenue',       icon: '◉' },
      { to: '/membership-revenue',   label: 'Membership Revenue',    icon: '✦' },
      { to: '/leads',                label: 'Leads',                 icon: '☍' },
      { to: '/waivers',              label: 'Waivers',               icon: '✍' },
      { to: '/email-management',     label: 'Email Management',      icon: '✉' },
      { to: '/ai-insights',          label: 'AI Insights',           icon: '✨' },
      { to: '/ai-agents',            label: 'AI Agents',             icon: '⌬' },
      { to: '/bridgistic',           label: 'BridGistic',            icon: '⇌' },
      { to: '/ops-queue',            label: 'Ops Queue',             icon: '✓' },
      { to: '/tasks',                label: 'Tasks',                 icon: '✎' },
    ],
  },
  {
    group: 'Commerce',
    items: [
      { to: '/woo-store-analytics',  label: 'Woo Store Analytics',   icon: '$' },
      { to: '/business-analysis',    label: 'Business Analysis',     icon: '≈' },
    ],
  },
  {
    group: 'SEO',
    items: [
      { to: '/seo-growth',           label: 'SEO Growth',            icon: '↗' },
      { to: '/insightistic',         label: 'Insightistic Analytics',icon: '◇' },
      { to: '/shooter-insights',     label: 'Shooter Insights',      icon: '◎' },
      { to: '/business-gaps',        label: 'Business Gaps',         icon: '⚠' },
    ],
  },
  {
    group: 'Technical Health',
    items: [
      { to: '/system-health',        label: 'System Health',         icon: '♥' },
      { to: '/reports',              label: 'Reports',               icon: '☰' },
      { to: '/automation-center',    label: 'Automation Center',     icon: '⚙' },
      { to: '/ai-models',            label: 'AI Models & RAGs',      icon: '⛁' },
      { to: '/settings',             label: 'Settings',              icon: '⚙' },
    ],
  },
] as const

interface Props {
  onNavigate?: () => void
}

export function Sidebar({ onNavigate }: Props) {
  const location = useLocation()
  return (
    <aside
      className="flex flex-col h-full w-64 shrink-0"
      style={{
        backgroundColor: 'var(--sidebar-bg)',
        color: 'var(--sidebar-fg)',
      }}
    >
      <div
        className="px-5 py-5"
        style={{ borderBottom: '1px solid rgba(255,255,255,0.06)' }}
      >
        <div className="flex items-center gap-3">
          <div
            className="h-9 w-9 rounded-lg flex items-center justify-center font-bold text-white"
            style={{ backgroundColor: 'var(--brand)' }}
          >
            G2
          </div>
          <div className="leading-tight">
            <div className="text-sm font-semibold text-white">Guns2Ammo</div>
            <div className="text-xs" style={{ color: 'var(--sidebar-fg-muted)' }}>
              Business Control Center
            </div>
          </div>
        </div>
      </div>

      <nav className="flex-1 overflow-y-auto px-3 py-4 space-y-6 text-sm">
        <ul className="space-y-0.5">
          <li>
            <NavLink
              to={TOP_LINK.to}
              end
              onClick={onNavigate}
              className="sidebar-link group flex items-center gap-3 rounded-lg px-2.5 py-2 transition-colors"
              style={{
                backgroundColor: location.pathname === '/' ? 'var(--sidebar-active)' : 'transparent',
                color: location.pathname === '/' ? '#ffffff' : 'var(--sidebar-fg)',
              }}
            >
              <span
                className="w-5 text-center"
                style={{ color: location.pathname === '/' ? 'var(--brand-strong)' : 'var(--sidebar-fg-muted)' }}
              >
                {TOP_LINK.icon}
              </span>
              <span className="truncate">{TOP_LINK.label}</span>
            </NavLink>
          </li>
        </ul>

        {NAV.map(section => (
          <div key={section.group}>
            <div
              className="px-2 pb-2 text-[10px] font-semibold tracking-wider uppercase"
              style={{ color: 'var(--sidebar-fg-muted)' }}
            >
              {section.group}
            </div>
            <ul className="space-y-0.5">
              {section.items.map(item => {
                const isActive = location.pathname.startsWith(item.to)
                return (
                  <li key={item.to}>
                    <NavLink
                      to={item.to}
                      onClick={onNavigate}
                      className="sidebar-link group flex items-center gap-3 rounded-lg px-2.5 py-2 transition-colors"
                      style={{
                        backgroundColor: isActive ? 'var(--sidebar-active)' : 'transparent',
                        color: isActive ? '#ffffff' : 'var(--sidebar-fg)',
                      }}
                    >
                      <span
                        className="w-5 text-center"
                        style={{ color: isActive ? 'var(--brand-strong)' : 'var(--sidebar-fg-muted)' }}
                      >
                        {item.icon}
                      </span>
                      <span className="truncate">{item.label}</span>
                    </NavLink>
                  </li>
                )
              })}
            </ul>
          </div>
        ))}
      </nav>

      <div
        className="px-4 py-3 text-[11px]"
        style={{
          color: 'var(--sidebar-fg-muted)',
          borderTop: '1px solid rgba(255,255,255,0.06)',
        }}
      >
        v0.1 · POS runs separately at
        <br />
        <span style={{ color: 'var(--sidebar-fg)' }}>pos.guns2ammo.com</span>
      </div>
    </aside>
  )
}
