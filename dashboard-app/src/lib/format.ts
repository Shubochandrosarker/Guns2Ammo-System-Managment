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
