/**
 * Where to land after a successful sign-in.
 *
 * The signed-out catch-all in App.tsx stashes the requested path in the
 * router's navigation state, so a bookmarked or emailed link like /waivers
 * survives the sign-in instead of dumping the user on the home screen.
 *
 * The value is validated rather than trusted. It arrives via history state,
 * which is writable by anything running on this origin, and handing an
 * arbitrary string to navigate() is how a login form turns into an open
 * redirect — the classic phishing shape being a link to the real
 * app.guns2ammo.com/login that bounces to a lookalike host the moment the
 * owner's credentials go in.
 */
export function safeRedirect(state: unknown): string {
  const from = (state as { from?: unknown } | null)?.from
  if (typeof from !== 'string' || from === '') return '/'

  // Must be a path on this origin. Rejected:
  //   '//evil.com/x'   protocol-relative URL — the browser treats it as a host
  //   '/\\evil.com/x'  same thing; browsers normalise the backslash
  //   'https://…'      absolute URL
  //   'evil.com'       relative, would resolve against the current directory
  if (!from.startsWith('/')) return '/'
  if (from.startsWith('//') || from.startsWith('/\\')) return '/'

  // Bouncing back to /login would loop.
  if (from === '/login' || from.startsWith('/login?') || from.startsWith('/login#')) return '/'

  return from
}
