// Trend-delta chip for the Phase-D analytics payloads' `trends.deltas.*Pct`
// values (percent vs the previous comparable window, or null when there is
// no baseline to compare against).
//
// Accessibility: the direction is never color-only — the chip always renders
// the word "up"/"down"/"flat" alongside the arrow, and the arrow itself is
// aria-hidden so screen readers hear the words, not the glyph.

import { formatPercent } from '@/lib/format'

interface Props {
  /** What moved, e.g. "Bookings" or "Revenue". */
  label: string
  /** Percent delta vs the previous window; null → no baseline ("n/a"). */
  pct: number | null
}

export function TrendDelta({ label, pct }: Props) {
  if (pct === null) {
    return (
      <span className="pill-gray">
        {label}: n/a
        <span className="sr-only"> — no previous-period data to compare against</span>
      </span>
    )
  }
  const word = pct > 0 ? 'up' : pct < 0 ? 'down' : 'flat'
  const arrow = pct > 0 ? '▲' : pct < 0 ? '▼' : '–'
  const pill = pct > 0 ? 'pill-green' : pct < 0 ? 'pill-red' : 'pill-gray'
  return (
    <span className={pill}>
      <span aria-hidden="true">{arrow} </span>
      {label} {word} {formatPercent(Math.abs(pct))}
      <span className="sr-only"> versus the previous period</span>
    </span>
  )
}
