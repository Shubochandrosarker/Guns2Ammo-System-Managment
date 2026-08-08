// Small display helpers. Everything the UI renders passes through here,
// so a locale/currency change lives in one place.

export function formatCurrency(cents: number): string {
  // The backend stores money in cents to keep integer math clean.
  return (cents / 100).toLocaleString('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 0,
  })
}

// Canonical money renderer for the envelope endpoints (Phase C). The wire
// carries integer USD cents; split into dollars + remainder with integer
// math ONLY — no floating-point division on money values.
export function formatCents(cents: number): string {
  const whole = Math.trunc(cents) // guard against a non-integer sneaking in
  const sign = whole < 0 ? '-' : ''
  const abs = Math.abs(whole)
  const dollars = (abs - (abs % 100)) / 100 // exact: abs-(abs%100) is a multiple of 100
  const rem = abs % 100
  const dollarsStr = dollars.toLocaleString('en-US')
  return rem === 0
    ? `${sign}$${dollarsStr}`
    : `${sign}$${dollarsStr}.${String(rem).padStart(2, '0')}`
}

// Render an ISO-8601 UTC datetime in the business timezone from meta.timezone.
// Falls back gracefully on an unparseable date or unknown IANA zone.
export function formatDateTimeInZone(iso: string, timeZone: string): string {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return iso
  const opts: Intl.DateTimeFormatOptions = {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    timeZoneName: 'short',
  }
  try {
    return new Intl.DateTimeFormat('en-US', { ...opts, timeZone }).format(date)
  } catch {
    // Unknown/invalid timezone id — render in the viewer's local zone
    // rather than crashing.
    return new Intl.DateTimeFormat('en-US', opts).format(date)
  }
}

export function formatNumber(n: number): string {
  return n.toLocaleString('en-US')
}

export function formatPercent(pct: number, digits = 1): string {
  return `${pct.toFixed(digits)}%`
}

export function formatDelta(pct: number): string {
  const sign = pct > 0 ? '+' : ''
  return `${sign}${pct.toFixed(1)}%`
}

export function formatBytes(bytes: number): string {
  if (bytes <= 0) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)))
  return `${(bytes / 1024 ** i).toFixed(i === 0 ? 0 : 1)} ${units[i]}`
}

export function initials(name: string): string {
  return name
    .split(/\s+/)
    .map(part => part[0]?.toUpperCase() ?? '')
    .slice(0, 2)
    .join('')
}
