import { cn } from '@/lib/cn'
import { formatCurrency, formatDelta, formatNumber, formatPercent } from '@/lib/format'

interface Props {
  label: string
  value: number
  format?: 'currency' | 'number' | 'percent'
  deltaPct?: number
  sublabel?: string
  intent?: 'default' | 'success' | 'warn' | 'danger'
}

const intentBar: Record<NonNullable<Props['intent']>, string> = {
  default: 'bg-brand-500',
  success: 'bg-emerald-500',
  warn:    'bg-amber-500',
  danger:  'bg-rose-500',
}

export function StatCard({
  label,
  value,
  format = 'number',
  deltaPct,
  sublabel,
  intent = 'default',
}: Props) {
  const display =
    format === 'currency' ? formatCurrency(value)
    : format === 'percent' ? formatPercent(value)
    : formatNumber(value)

  return (
    <div className="card overflow-hidden">
      <div className={cn('h-1', intentBar[intent])} />
      <div className="card-body">
        <div className="text-xs font-medium uppercase tracking-wide text-ink-500">
          {label}
        </div>
        <div className="mt-2 flex items-baseline gap-2">
          <div className="text-2xl font-semibold text-ink-800">{display}</div>
          {typeof deltaPct === 'number' && (
            <div
              className={cn(
                'text-xs font-medium',
                deltaPct > 0 ? 'text-emerald-600' : deltaPct < 0 ? 'text-rose-600' : 'text-ink-500',
              )}
            >
              {formatDelta(deltaPct)}
            </div>
          )}
        </div>
        {sublabel && <div className="mt-1 text-xs text-ink-500">{sublabel}</div>}
      </div>
    </div>
  )
}
