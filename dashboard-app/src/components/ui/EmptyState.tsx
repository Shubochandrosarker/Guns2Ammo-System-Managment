import type { ReactNode } from 'react'

interface Props {
  title: string
  hint?: string
  icon?: string
  action?: ReactNode
}

export function EmptyState({ title, hint, icon = '·', action }: Props) {
  return (
    <div className="text-center py-12">
      <div className="mx-auto h-12 w-12 rounded-full bg-ink-100 text-ink-500 flex items-center justify-center text-xl">
        {icon}
      </div>
      <div className="mt-3 font-medium text-ink-800">{title}</div>
      {hint && <div className="mt-1 text-sm text-ink-500 max-w-md mx-auto">{hint}</div>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  )
}
