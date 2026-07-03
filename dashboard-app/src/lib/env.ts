// Runtime configuration surfaced through Vite's import.meta.env.
// Fields default in a way that keeps `npm run dev` useful without
// any .env — the app falls back to bundled mock data.
//
// In a production build (import.meta.env.PROD), we refuse to boot in
// mock mode: shipping mocks to app.guns2ammo.com would silently mask
// missing configuration. Missing VITE_G2A_API_BASE at build time throws
// so the failure is loud and visible.

interface Env {
  apiBase: string
  useMocks: boolean
}

const raw = import.meta.env
const isProdBuild = Boolean(raw.PROD)

const apiBaseFromEnv = (raw.VITE_G2A_API_BASE as string | undefined)?.trim() || ''
const useMocksRaw    = raw.VITE_G2A_USE_MOCKS as string | undefined

// Default:
//   dev  → useMocks = true unless VITE_G2A_USE_MOCKS='0'
//   prod → useMocks = false; explicit '1' still allows mocks (rare — CI demo)
const useMocks = isProdBuild
  ? useMocksRaw === '1'
  : useMocksRaw !== '0'

if (isProdBuild && !useMocks && apiBaseFromEnv === '') {
  // Fail hard at module load — better than lazy-failing on the first fetch.
  throw new Error(
    'g2a: VITE_G2A_API_BASE must be set for a production build. ' +
    "Copy .env.production.example to .env.production and set it to the WordPress origin (e.g. 'https://guns2ammo.com').",
  )
}

export const env: Env = {
  apiBase: apiBaseFromEnv,
  useMocks,
}
