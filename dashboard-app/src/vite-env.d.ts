/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_G2A_API_BASE?: string
  readonly VITE_G2A_USE_MOCKS?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
