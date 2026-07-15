// Human names for the plugin slugs the canonical envelope carries in
// `meta.source` / `modules[].source`, so UNAVAILABLE states can say which
// plugin is missing/inactive instead of showing a raw slug.

export const SOURCE_LABEL: Record<string, string> = {
  'g2a-booking-engine': 'G2A Booking Engine',
  memberistic: 'Memberistic',
  woocommerce: 'WooCommerce',
  'g2a-waivers': 'G2A Waivers',
  formistic: 'Formistic',
  messageistic: 'Messageistic',
}

export const sourceNames = (source: string[]): string =>
  source.length > 0 ? source.map(s => SOURCE_LABEL[s] ?? s).join(', ') : 'its source plugin'
