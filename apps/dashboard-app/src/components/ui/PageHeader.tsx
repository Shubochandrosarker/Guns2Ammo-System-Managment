import type { ReactNode } from 'react'

interface Props {
  title: string
  subtitle?: string
  actions?: ReactNode
  eyebrow?: string
}

export function PageHeader({ title, subtitle, actions, eyebrow }: Props) {
  return (
    <div className="flex flex-col md:flex-row md:items-end md:justify-between gap-3 md:gap-4 mb-5 sm:mb-6">
      <div className="min-w-0">
        {eyebrow && (
          <div
            className="text-[11px] font-semibold tracking-widest uppercase mb-1"
            style={{ color: 'var(--brand)' }}
          >
            {eyebrow}
          </div>
        )}
        <h1
          className="text-xl sm:text-2xl font-semibold break-words"
          style={{ color: 'var(--text-primary)' }}
        >
          {title}
        </h1>
        {subtitle && (
          <p
            className="text-sm mt-1 max-w-2xl"
            style={{ color: 'var(--text-muted)' }}
          >
            {subtitle}
          </p>
        )}
      </div>
      {actions && (
        <div className="flex items-center gap-2 flex-wrap">{actions}</div>
      )}
    </div>
  )
}
