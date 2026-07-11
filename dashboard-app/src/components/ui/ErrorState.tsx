// Shared "a request actually failed" state — as opposed to EmptyState,
// which communicates "the request succeeded and there's genuinely nothing
// to show." Mixing the two up hides real backend failures (auth expiry, WP
// REST outages, network blips) behind a misleadingly calm empty state.
interface Props {
  message: string
  onRetry?: () => void
}

export function ErrorState({ message, onRetry }: Props) {
  return (
    <div
      className="text-sm rounded-lg px-3 py-2 flex items-center justify-between gap-3"
      style={{ background: 'var(--danger-soft)', color: 'var(--danger)' }}
    >
      <span>{message}</span>
      {onRetry && (
        <button onClick={onRetry} className="btn-secondary text-xs shrink-0">
          Retry
        </button>
      )}
    </div>
  )
}
