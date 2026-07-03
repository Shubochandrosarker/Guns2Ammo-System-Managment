import { NavLink } from 'react-router-dom'
import { cn } from '@/lib/cn'

// Group -> items. `label` is the *frontend* naming the user asked for.
const NAV = [
  {
    group: 'Overview',
    items: [
      { to: '/',                     label: 'Dashboard Home',        icon: '⌂' },
      { to: '/business-analysis',    label: 'Business Analysis',     icon: '≈' },
      { to: '/insightistic',         label: 'Insightistic Analytics',icon: '◇' },
    ],
  },
  {
    group: 'Revenue',
    items: [
      { to: '/booking-revenue',      label: 'Booking Revenue',       icon: '◉' },
      { to: '/membership-revenue',   label: 'Membership Revenue',    icon: '✦' },
      { to: '/woo-store-analytics',  label: 'Woo Store Analytics',   icon: '$' },
    ],
  },
  {
    group: 'Growth',
    items: [
      { to: '/seo-growth',           label: 'SEO Growth',            icon: '↗' },
      { to: '/shooter-insights',     label: 'Shooter Insights',      icon: '◎' },
      { to: '/business-gaps',        label: 'Business Gaps',         icon: '⚠' },
    ],
  },
  {
    group: 'AI Operations',
    items: [
      { to: '/ai-insights',          label: 'AI Insights',           icon: '✨' },
      { to: '/automation-center',    label: 'Automation Center',     icon: '⚙' },
      { to: '/ai-agents',            label: 'AI Agents',             icon: '⌬' },
      { to: '/email-management',     label: 'Email Management',      icon: '✉' },
      { to: '/bridgistic',           label: 'BridGistic',            icon: '⇌' },
      { to: '/ai-models',            label: 'AI Models & RAGs',      icon: '⛁' },
    ],
  },
  {
    group: 'System',
    items: [
      { to: '/reports',              label: 'Reports',               icon: '☰' },
      { to: '/ops-queue',            label: 'Ops Queue',             icon: '✓' },
      { to: '/system-health',        label: 'System Health',         icon: '♥' },
      { to: '/settings',             label: 'Settings',              icon: '⚙' },
    ],
  },
] as const

interface Props {
  onNavigate?: () => void
}

export function Sidebar({ onNavigate }: Props) {
  return (
    <aside className="flex flex-col h-full bg-ink-900 text-ink-100 w-64 shrink-0">
      <div className="px-5 py-5 border-b border-ink-800/60">
        <div className="flex items-center gap-3">
          <div className="h-9 w-9 rounded-lg bg-brand-500 flex items-center justify-center font-bold">
            G2
          </div>
          <div className="leading-tight">
            <div className="text-sm font-semibold text-white">Guns2Ammo</div>
            <div className="text-xs text-ink-400">Business Control Center</div>
          </div>
        </div>
      </div>

      <nav className="flex-1 overflow-y-auto px-3 py-4 space-y-6 text-sm">
        {NAV.map(section => (
          <div key={section.group}>
            <div className="px-2 pb-2 text-[10px] font-semibold tracking-wider uppercase text-ink-500">
              {section.group}
            </div>
            <ul className="space-y-0.5">
              {section.items.map(item => (
                <li key={item.to}>
                  <NavLink
                    to={item.to}
                    end={item.to === '/'}
                    onClick={onNavigate}
                    className={({ isActive }) =>
                      cn(
                        'group flex items-center gap-3 rounded-lg px-2.5 py-2 transition-colors',
                        isActive
                          ? 'bg-brand-500/15 text-white'
                          : 'text-ink-300 hover:bg-ink-800/60 hover:text-white',
                      )
                    }
                  >
                    <span className="w-5 text-center text-ink-400 group-hover:text-brand-300">
                      {item.icon}
                    </span>
                    <span className="truncate">{item.label}</span>
                  </NavLink>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </nav>

      <div className="px-4 py-3 text-[11px] text-ink-500 border-t border-ink-800/60">
        v0.1 · POS runs separately at
        <br />
        <span className="text-ink-300">pos.guns2ammo.com</span>
      </div>
    </aside>
  )
}
