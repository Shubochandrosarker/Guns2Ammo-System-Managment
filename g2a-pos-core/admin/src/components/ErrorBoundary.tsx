import { Component, type ErrorInfo, type ReactNode } from 'react';

interface Props {
  children: ReactNode;
  /** Changing this (e.g. the active view key) clears a previous crash. */
  resetKey?: string;
  label?: string;
}
interface State {
  error: Error | null;
}

/**
 * Catches render-time errors in a subtree so one broken screen can't unmount
 * the entire dashboard (which is what made clicking "Import CSV" white-screen
 * the whole POS). The sidebar + topbar stay usable; the user sees the error
 * and can switch screens or retry.
 */
export default class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    // Keep a console trace for debugging without taking the app down.
    // eslint-disable-next-line no-console
    console.error('[G2A POS] view crashed:', error, info.componentStack);
  }

  componentDidUpdate(prev: Props) {
    // Navigating to a different view clears the boundary so a crash on one
    // screen doesn't wedge every other screen.
    if (prev.resetKey !== this.props.resetKey && this.state.error) {
      this.setState({ error: null });
    }
  }

  render() {
    if (this.state.error) {
      return (
        <div className="card m-2 max-w-2xl p-6">
          <h2 className="text-lg font-semibold text-rose-600">
            {this.props.label ? `${this.props.label} hit an error` : 'This screen hit an error'}
          </h2>
          <p className="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
            The rest of the dashboard is still working — pick another item from the sidebar, or
            reload the page. If it keeps happening, send support the details below.
          </p>
          <pre className="mt-3 overflow-auto rounded-lg bg-zinc-100 p-3 text-xs text-rose-700 dark:bg-zinc-900 dark:text-rose-300">
            {this.state.error.message}
          </pre>
          <button className="btn-primary mt-4" onClick={() => this.setState({ error: null })}>
            Try again
          </button>
        </div>
      );
    }
    return this.props.children;
  }
}
