// Runtime configuration surfaced through Vite's import.meta.env.
// Fields default in a way that keeps `npm run dev` useful without
// any .env — the app falls back to bundled mock data.

interface Env {
  apiBase: string
  useMocks: boolean
}

const raw = import.meta.env

export const env: Env = {
  apiBase: (raw.VITE_G2A_API_BASE as string | undefined)?.trim() || '',
  useMocks: (raw.VITE_G2A_USE_MOCKS as string | undefined) !== '0',
}
