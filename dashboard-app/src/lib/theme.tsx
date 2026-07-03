import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'

// Theme system.
//
//  - Reads persisted `theme` from localStorage on boot.
//  - Falls back to `prefers-color-scheme` for first-run users.
//  - Applies the `dark` class to <html> so the CSS variables in
//    styles/index.css switch.
//  - Also stamps <html data-theme="light|dark"> so anything that consumed
//    the raw class before can still key off an attribute.

export type Theme = 'light' | 'dark'
export type ThemeChoice = Theme | 'system'

const STORAGE_KEY = 'g2a.theme'

interface Ctx {
  theme: Theme            // Currently rendered theme.
  choice: ThemeChoice     // What the user picked (or 'system').
  setChoice: (c: ThemeChoice) => void
  toggle: () => void
}

const ThemeContext = createContext<Ctx | undefined>(undefined)

function systemTheme(): Theme {
  if (typeof window === 'undefined') return 'light'
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

function readChoice(): ThemeChoice {
  if (typeof window === 'undefined') return 'system'
  const raw = window.localStorage.getItem(STORAGE_KEY)
  return raw === 'light' || raw === 'dark' ? raw : 'system'
}

function apply(theme: Theme) {
  const root = document.documentElement
  root.classList.toggle('dark', theme === 'dark')
  root.setAttribute('data-theme', theme)
}

export function ThemeProvider({ children }: { children: ReactNode }) {
  const [choice, setChoiceState] = useState<ThemeChoice>(() => readChoice())
  const [theme, setTheme] = useState<Theme>(() => {
    const c = readChoice()
    return c === 'system' ? systemTheme() : c
  })

  useEffect(() => {
    apply(theme)
  }, [theme])

  // When the user picks 'system', keep in sync with OS changes.
  useEffect(() => {
    if (choice !== 'system' || typeof window === 'undefined') return
    const mq = window.matchMedia('(prefers-color-scheme: dark)')
    const handler = () => setTheme(mq.matches ? 'dark' : 'light')
    mq.addEventListener('change', handler)
    return () => mq.removeEventListener('change', handler)
  }, [choice])

  const setChoice = useCallback((c: ThemeChoice) => {
    setChoiceState(c)
    if (typeof window !== 'undefined') {
      if (c === 'system') window.localStorage.removeItem(STORAGE_KEY)
      else window.localStorage.setItem(STORAGE_KEY, c)
    }
    setTheme(c === 'system' ? systemTheme() : c)
  }, [])

  const toggle = useCallback(() => {
    const next: Theme = theme === 'dark' ? 'light' : 'dark'
    setChoice(next)
  }, [theme, setChoice])

  const value = useMemo(() => ({ theme, choice, setChoice, toggle }), [theme, choice, setChoice, toggle])
  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>
}

export function useTheme(): Ctx {
  const ctx = useContext(ThemeContext)
  if (!ctx) throw new Error('useTheme must be used inside <ThemeProvider>')
  return ctx
}
