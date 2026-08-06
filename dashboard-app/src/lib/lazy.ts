// Lazy-route loading that survives a deploy.
//
// deploy/deploy.sh switches `current` to a new release and prunes all but the
// last five, so the asset filenames change on every deploy. A tab that was
// already open is still running the PREVIOUS bundle: the moment someone
// navigates to a route whose chunk has not loaded yet, the browser requests a
// hashed filename that no longer exists on the server. nginx answers the
// `/assets/` location with `try_files $uri =404`, the dynamic import rejects,
// and — with no error boundary — React unmounts the tree and leaves a white
// screen. The owner sees the dashboard "break" some hours after a deploy they
// were never told about, and the fix (hard refresh) is not discoverable.
//
// This is not a hypothetical: it is the normal outcome of shipping a
// route-split SPA with fingerprinted assets to people who leave the tab open
// all day, which is exactly how a shop back-office gets used.
//
// So: detect the failure, reload the page once to pick up the new bundle, and
// remember in sessionStorage that we already tried. If the reload does not fix
// it (genuinely broken deploy, or the user is offline), the second failure
// propagates to the error boundary and shows a real message instead of
// reloading forever.

const RELOAD_FLAG = 'g2a.chunk-reloaded'

/**
 * Does this look like "the JS file I asked for is not there any more"?
 *
 * Browsers disagree on the wording, and none of them expose a machine code, so
 * matching on the message is the only option available:
 *   Chrome/Edge  "Failed to fetch dynamically imported module: https://…"
 *   Firefox      "error loading dynamically imported module"
 *   Safari       "Importing a module script failed."
 *   Vite preload "Unable to preload CSS for …"
 */
export function isChunkLoadError(err: unknown): boolean {
  const message =
    err instanceof Error
      ? `${err.name}: ${err.message}`
      : typeof err === 'string'
        ? err
        : ''
  if (!message) return false
  return (
    /dynamically imported module/i.test(message) ||
    /Importing a module script failed/i.test(message) ||
    /Unable to preload/i.test(message) ||
    /ChunkLoadError/i.test(message) ||
    /Loading (?:CSS )?chunk \d+ failed/i.test(message)
  )
}

/** True when this page has already been reloaded once to recover a chunk. */
export function hasAttemptedChunkReload(): boolean {
  try {
    return sessionStorage.getItem(RELOAD_FLAG) === '1'
  } catch {
    // Storage blocked (private mode / cookies off). Treat as "already tried"
    // so a missing flag can never turn into an infinite reload loop.
    return true
  }
}

function markChunkReloadAttempted(): void {
  try {
    sessionStorage.setItem(RELOAD_FLAG, '1')
  } catch {
    // Nothing to do — hasAttemptedChunkReload() already fails safe.
  }
}

/**
 * Clear the one-shot guard. Called once the app has successfully rendered, so
 * a later deploy in the same tab gets its own recovery attempt.
 */
export function clearChunkReloadFlag(): void {
  try {
    sessionStorage.removeItem(RELOAD_FLAG)
  } catch {
    // Ignore.
  }
}

/**
 * Decide what to do about a failed lazy import. Returns true when the caller
 * should stop (a reload is under way), false when the error must be shown.
 *
 * Split out from the reload itself so it is testable without a DOM.
 */
export function shouldReloadForChunkError(err: unknown, alreadyTried: boolean): boolean {
  return isChunkLoadError(err) && !alreadyTried
}

/**
 * Wrap a `() => import('…')` so a stale-chunk failure reloads the page once
 * instead of blanking the app. Anything else — and any second failure — is
 * rethrown for the error boundary.
 */
export function retryImport<T>(load: () => Promise<T>): () => Promise<T> {
  return () =>
    load().catch((err: unknown) => {
      if (shouldReloadForChunkError(err, hasAttemptedChunkReload())) {
        markChunkReloadAttempted()
        window.location.reload()
        // Never settles — the page is going away. Resolving with a dummy
        // module would flash a broken screen during the reload.
        return new Promise<T>(() => {})
      }
      throw err
    })
}
