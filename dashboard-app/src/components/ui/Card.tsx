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
        <div className="card-header">
          <div>
            {title && <div className="font-semibold text-ink-800">{title}</div>}
            {subtitle && <div className="text-xs text-ink-500 mt-0.5">{subtitle}</div>}
          </div>
          {actions && <div className="flex items-center gap-2">{actions}</div>}
        </div>
      )}
      {children != null && <div className={cn('card-body', bodyClassName)}>{children}</div>}
    </div>
  )
}
