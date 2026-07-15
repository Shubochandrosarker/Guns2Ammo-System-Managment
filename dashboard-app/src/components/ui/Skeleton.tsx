import { cn } from '@/lib/cn'

// Loading placeholder block. Purely decorative — hidden from assistive tech;
// the surrounding container should carry the aria-busy / status semantics.
export function Skeleton({ className }: { className?: string }) {
  return <div className={cn('animate-pulse rounded-md bg-ink-100', className)} aria-hidden="true" />
}
