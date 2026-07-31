import { useCallback, useState } from 'react';
import { errorMessage } from '../api';

/**
 * Feedback wrapper for mutations.
 *
 * 18 views called `await post(...)` with no try/catch, so a rejected request
 * did nothing visible at all — the row simply didn't change and the operator
 * had no way to know whether it had worked. A few others surfaced failures
 * through window.alert(). This gives every view the same handling: an inline
 * error, a success notice, and a busy flag to disable controls mid-flight.
 *
 *   const { run, busy, error, notice, setError } = useAction()
 *
 *   const pay = () => run(async () => {
 *     await post(`/layaways/${id}/pay`, body)
 *     await refresh()
 *   }, 'Payment recorded.')
 *
 * `run` resolves to true on success and false on failure, so callers can
 * branch (e.g. only close a panel when the write actually landed).
 */
export function useAction() {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const run = useCallback(async (fn: () => Promise<unknown>, success = ''): Promise<boolean> => {
    setBusy(true);
    setError('');
    setNotice('');
    try {
      await fn();
      if (success) setNotice(success);
      return true;
    } catch (e) {
      setError(errorMessage(e));
      return false;
    } finally {
      setBusy(false);
    }
  }, []);

  const clear = useCallback(() => {
    setError('');
    setNotice('');
  }, []);

  return { run, busy, error, notice, setError, setNotice, clear };
}

/**
 * Inline error/success banners. Rendered by every migrated view directly under
 * its PageHeader so feedback always appears in the same place.
 */
export function ActionFeedback({ error, notice }: { error?: string; notice?: string }) {
  if (!error && !notice) return null;
  return (
    <>
      {error && (
        <div
          className="mb-3 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800"
          role="alert"
        >
          {error}
        </div>
      )}
      {notice && (
        <div
          className="mb-3 rounded border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-800"
          role="status"
        >
          {notice}
        </div>
      )}
    </>
  );
}
