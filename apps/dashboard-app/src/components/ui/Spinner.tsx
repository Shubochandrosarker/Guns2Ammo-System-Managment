export function Spinner({ label = 'Loading…' }: { label?: string }) {
  return (
    <div className="flex items-center justify-center py-10 text-ink-500 text-sm gap-2">
      <span className="inline-block h-4 w-4 rounded-full border-2 border-ink-200 border-t-brand-500 animate-spin" />
      {label}
    </div>
  )
}
