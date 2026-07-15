import { formatCurrency, formatDelta, formatNumber, formatPercent } from '@/lib/format'

interface Props {
  label: string
  /**
   * number → formatted per `format`. string → rendered verbatim (used by the
   * envelope pages so money flows through the single formatCents helper
   * instead of this component's float-division currency path).
   */
  value: number | string
  format?: 'currency' | 'number' | 'percent'
  deltaPct?: number
  sublabel?: string
  intent?: 'default' | 'success' | 'warn' | 'danger'
}

const intentColor: Record<NonNullable<Props['intent']>, string> = {
  default: 'var(--brand)',
  success: 'var(--success)',
  warn:    'var(--warn)',
  danger:  'var(--danger)',
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
    typeof value === 'string' ? value
    : format === 'currency' ? formatCurrency(value)
    : format === 'percent' ? formatPercent(value)
    : formatNumber(value)

  const deltaColor =
    typeof deltaPct !== 'number' ? 'var(--text-muted)'
    : deltaPct > 0 ? 'var(--success)'
    : deltaPct < 0 ? 'var(--danger)'
    : 'var(--text-muted)'

  return (
    <div className="card overflow-hidden">
      <div className="h-1" style={{ backgroundColor: intentColor[intent] }} />
      <div className="card-body">
        <div
          className="text-xs font-medium uppercase tracking-wide"
          style={{ color: 'var(--text-muted)' }}
        >
          {label}
        </div>
        <div className="mt-2 flex items-baseline gap-2 flex-wrap">
          <div
            className="text-xl sm:text-2xl font-semibold break-words"
            style={{ color: 'var(--text-primary)' }}
          >
            {display}
          </div>
          {typeof deltaPct === 'number' && (
            <div className="text-xs font-medium" style={{ color: deltaColor }}>
              {formatDelta(deltaPct)}
            </div>
          )}
        </div>
        {sublabel && (
          <div className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>
            {sublabel}
          </div>
        )}
      </div>
    </div>
  )
}
