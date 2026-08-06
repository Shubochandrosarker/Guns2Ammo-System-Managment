/*
 * Pre-paint theme application.
 *
 * src/lib/theme.tsx applies the `dark` class in a useEffect — i.e. after
 * React has mounted, which is after the bundle has been fetched, parsed and
 * executed. Until then <body> carries the light background from index.html,
 * so every single page load flashes white before snapping to dark. On the
 * shop floor that reads as the dashboard "blinking" on each navigation and
 * refresh.
 *
 * This runs synchronously in <head>, before the first paint, and sets exactly
 * the same class and attribute the provider sets, so React's later effect is
 * a no-op rather than a second change.
 *
 * Kept as a separate file rather than an inline <script> on purpose: the CSP
 * in deploy/nginx-security-headers.conf is script-src 'self' with no
 * 'unsafe-inline' and no hashes to keep in sync.
 *
 * Must stay in sync with src/lib/theme.tsx (STORAGE_KEY and the apply()
 * function).
 */
(function () {
  try {
    var stored = window.localStorage.getItem('g2a.theme')
    var theme =
      stored === 'light' || stored === 'dark'
        ? stored
        : window.matchMedia('(prefers-color-scheme: dark)').matches
          ? 'dark'
          : 'light'
    document.documentElement.classList.toggle('dark', theme === 'dark')
    document.documentElement.setAttribute('data-theme', theme)
  } catch (e) {
    /* Storage or matchMedia unavailable — leave the light default in place. */
  }
})()
