// Chart colour tokens.
//
// Recharts accepts CSS variables in its stroke/fill props (via
// `var(--foo)`), so this is a small helper that centralises the two we
// care about across pages. A hook version wouldn't buy us anything since
// these values only change when the theme flips — and when it flips,
// Recharts re-reads the DOM styles.

export const chartTokens = {
  grid:  'var(--chart-grid)',
  axis:  'var(--chart-axis)',
  brand: 'var(--brand)',
  info:  'var(--info)',
  success: 'var(--success)',
  warn:  'var(--warn)',
  danger: 'var(--danger)',
} as const
