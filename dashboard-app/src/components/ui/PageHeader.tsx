import type { ReactNode } from 'react'

interface Props {
  title: string
  subtitle?: string
  actions?: ReactNode
  eyebrow?: string
}

export function PageHeader({ title, subtitle, actions, eyebrow }: Props) {
  return (
    <div className="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-6">
      <div>
        {eyebrow && (
          <div className="text-[11px] font-semibold tracking-widest uppercase text-brand-500 mb-1">
            {eyebrow}
          </div>
        )}
        <h1 className="text-2xl font-semibold text-ink-800">{title}</h1>
        {subtitle && <p className="text-sm text-ink-500 mt-1 max-w-2xl">{subtitle}</p>}
      </div>
      {actions && <div className="flex items-center gap-2">{actions}</div>}
    </div>
  )
}
