import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'

interface Props {
  title?: string
  subtitle?: string
  actions?: ReactNode
  children?: ReactNode
  className?: string
  bodyClassName?: string
}

export function Card({ title, subtitle, actions, children, className, bodyClassName }: Props) {
  return (
    <div className={cn('card', className)}>
      {(title || actions) && (
        <div className="card-header flex-wrap gap-2">
          <div className="min-w-0">
            {title && (
              <div className="font-semibold" style={{ color: 'var(--text-primary)' }}>
                {title}
              </div>
            )}
            {subtitle && (
              <div className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                {subtitle}
              </div>
            )}
          </div>
          {actions && <div className="flex items-center gap-2 flex-wrap">{actions}</div>}
        </div>
      )}
      {children != null && <div className={cn('card-body', bodyClassName)}>{children}</div>}
    </div>
  )
}
