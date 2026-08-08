// Runtime configuration surfaced through Vite's import.meta.env.
// Fields default in a way that keeps `npm run dev` useful without
// any .env — the app falls back to bundled mock data.
//
// In a production build (import.meta.env.PROD), we refuse to boot in
// mock mode: shipping mocks to app.guns2ammo.com would silently mask
// missing configuration. Missing VITE_G2A_API_BASE at build time throws
// so the failure is loud and visible.

interface Env {
  /**
   * Full REST base for the g2a-business-api plugin, INCLUDING the
   * /wp-json/g2a/v1 suffix (fixed auth contract: endpoints live at
   * `{API_BASE}/auth/session/...`). Example:
   *   https://guns2ammo.com/wp-json/g2a/v1
   * In dev it defaults to the relative '/wp-json/g2a/v1' so requests ride
   * through the Vite dev-server proxy (vite.config.ts).
   */
  apiBase: string
  useMocks: boolean
}

const raw = import.meta.env
const isProdBuild = Boolean(raw.PROD)

const DEV_DEFAULT_API_BASE = '/wp-json/g2a/v1'
const apiBaseFromEnv =
  (raw.VITE_G2A_API_BASE as string | undefined)?.trim() ||
  (isProdBuild ? '' : DEV_DEFAULT_API_BASE)
const useMocksRaw = raw.VITE_G2A_USE_MOCKS as string | undefined

// Mock mode is DEV-ONLY.
//   dev  → useMocks = true unless VITE_G2A_USE_MOCKS='0'
//   prod → useMocks = false, ALWAYS. The flag is deliberately not honored:
//          a production bundle must never serve fixture data, and api.ts
//          only loads the fixtures behind a literal `import.meta.env.DEV`
//          guard so they are tree-shaken out of production builds entirely.
const useMocks = import.meta.env.DEV ? useMocksRaw !== '0' : false

if (isProdBuild && useMocksRaw === '1') {
  // Refuse the old "CI demo" escape hatch loudly instead of silently.
  console.error(
    'g2a: VITE_G2A_USE_MOCKS=1 is ignored in production builds — mock mode is dev-only. ' +
    'The app will talk to VITE_G2A_API_BASE instead.',
  )
}

if (isProdBuild && apiBaseFromEnv === '') {
  // Fail hard at module load — better than lazy-failing on the first fetch.
  throw new Error(
    'g2a: VITE_G2A_API_BASE must be set for a production build. ' +
    "Copy .env.production.example to .env.production and set it to the full REST base " +
    "(e.g. 'https://guns2ammo.com/wp-json/g2a/v1').",
  )
}

export const env: Env = {
  apiBase: apiBaseFromEnv,
  useMocks,
}
