import { Component, type ErrorInfo, type ReactNode } from 'react'

// The app had no error boundary at all. In React 18 that is not a cosmetic
// gap: an uncaught render error unmounts the ENTIRE tree and leaves an empty
// <div id="root">. On app.guns2ammo.com that reads as "the dashboard is down"
// — no message, no retry, nothing in the UI to act on — for what may be one
// bad field in one API response on one card.
//
// Two boundaries are mounted (see App.tsx):
//   * one around the whole app, so the worst case is a readable page;
//   * one inside the layout around the routed page, so a single screen
//     failing keeps the sidebar and the other 20 screens usable.

interface Props {
  children: ReactNode
  /** Shown above the message, e.g. 'Dashboard Home'. */
  title?: string
  /** Reset key — when it changes, drop the error and try rendering again. */
  resetKey?: string
}

interface State {
  error: Error | null
}

export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null }

  static getDerivedStateFromError(error: Error): State {
    return { error }
  }

  componentDidUpdate(prev: Props) {
    // Navigating away from a screen that threw should not keep the error on
    // screen — App.tsx passes the pathname so a route change clears it.
    if (this.state.error && prev.resetKey !== this.props.resetKey) {
      this.setState({ error: null })
    }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    // No error-reporting service is wired up, and inventing one here would be
    // shipping an unreviewed third-party call from an authenticated admin
    // origin. The console is what the owner can actually be walked through
    // over the phone.
    console.error('g2a: render error', error, info.componentStack)
  }

  render() {
    const { error } = this.state
    if (!error) return this.props.children

    return (
      <div className="min-h-full flex items-center justify-center p-6">
        <div className="w-full max-w-lg card p-6">
          <div className="text-xs uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>
            {this.props.title ?? 'Guns2Ammo Business Control Center'}
          </div>
          <h1 className="mt-1 text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>
            This screen failed to load
          </h1>
          <p className="mt-2 text-sm" style={{ color: 'var(--text-secondary)' }}>
            Something in the page threw an error, so it was stopped rather than
            left half-rendered showing numbers that may be wrong.
          </p>
          <pre
            className="mt-3 text-xs rounded-lg px-3 py-2 overflow-x-auto whitespace-pre-wrap"
            style={{ background: 'var(--danger-soft)', color: 'var(--danger)' }}
          >
            {error.message || String(error)}
          </pre>
          <div className="mt-4 flex gap-2">
            <button className="btn-primary" onClick={() => this.setState({ error: null })}>
              Try again
            </button>
            <button className="btn-secondary" onClick={() => window.location.reload()}>
              Reload the app
            </button>
          </div>
        </div>
      </div>
    )
  }
}
