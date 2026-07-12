import { useEffect, useRef, useState } from 'react'
import type { RefObject } from 'react'

// Minimal async-data hook. Deliberately simple — we do not need a full
// react-query dependency for Phase 1, and every page owns a single fetch.

interface AsyncState<T> {
  data: T | null
  loading: boolean
  error: string | null
  refresh: () => void
}

export function useAsync<T>(fn: () => Promise<T>, deps: unknown[] = []): AsyncState<T> {
  const [data, setData] = useState<T | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [nonce, setNonce] = useState(0)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setError(null)
    fn()
      .then(res => {
        if (!cancelled) setData(res)
      })
      .catch(err => {
        if (!cancelled) setError(err instanceof Error ? err.message : String(err))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })
    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, nonce])

  return { data, loading, error, refresh: () => setNonce(n => n + 1) }
}

const FOCUSABLE_SELECTOR = [
  'a[href]',
  'button:not([disabled])',
  'textarea:not([disabled])',
  'input:not([disabled])',
  'select:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(', ')

// Shared modal/drawer accessibility behavior: focuses the first focusable
// element (or the container itself) on open, traps Tab/Shift+Tab within the
// container, closes on Escape, and returns focus to whatever triggered the
// dialog once it unmounts. Intended to be attached to the outer element that
// carries role="dialog" — mount/unmount of that element is treated as
// open/close, so every one of this app's modals (which are only rendered
// while open) gets this behavior for free just by calling the hook.
export function useDialogA11y<T extends HTMLElement>(containerRef: RefObject<T>, onClose: () => void): void {
  const previouslyFocused = useRef<HTMLElement | null>(null)

  useEffect(() => {
    previouslyFocused.current = document.activeElement instanceof HTMLElement ? document.activeElement : null

    const container = containerRef.current
    const focusables = container ? Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR)) : []
    ;(focusables[0] ?? container)?.focus()

    function onKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        e.preventDefault()
        onClose()
        return
      }
      if (e.key !== 'Tab') return
      const el = containerRef.current
      if (!el) return
      const items = Array.from(el.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR))
      if (items.length === 0) return
      const first = items[0]
      const last = items[items.length - 1]
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault()
        last.focus()
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('keydown', onKeyDown)
      previouslyFocused.current?.focus()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
}

// Locks page scroll behind an open modal/drawer. A plain `overflow: hidden`
// on <body> does NOT reliably stop background scroll on iOS Safari — the
// page can still rubber-band/scroll underneath because Safari's compositor
// doesn't fully respect overflow on the layout viewport during touch
// scrolling. Pinning the body with `position: fixed` (and restoring the
// scroll position on close) is the standard cross-browser-reliable fix.
export function useBodyScrollLock(locked: boolean): void {
  useEffect(() => {
    if (!locked) return
    const scrollY = window.scrollY
    const { body } = document
    const prev = {
      position: body.style.position,
      top: body.style.top,
      left: body.style.left,
      right: body.style.right,
      width: body.style.width,
    }
    body.style.position = 'fixed'
    body.style.top = `-${scrollY}px`
    body.style.left = '0'
    body.style.right = '0'
    body.style.width = '100%'
    return () => {
      body.style.position = prev.position
      body.style.top = prev.top
      body.style.left = prev.left
      body.style.right = prev.right
      body.style.width = prev.width
      window.scrollTo(0, scrollY)
    }
  }, [locked])
}
