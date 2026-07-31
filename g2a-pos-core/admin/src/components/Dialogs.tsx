import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';

/**
 * Promise-based replacements for window.confirm() and window.prompt().
 *
 * Those were used in ~20 views for things like "Payout amount?" and "Void this
 * tender line?". Native dialogs block the whole browser, cannot be styled or
 * dismissed with Escape consistently, offer no validation, and — worst for a
 * POS — collect free text where the API expects an enum, so a typo like
 * "Cash" instead of "cash" reached the server as a valid-looking value.
 *
 * These render in-app, validate before resolving, and support select fields so
 * an enum is picked rather than typed.
 *
 *   const { confirm, prompt } = useDialogs()
 *
 *   if (!(await confirm({ title: 'Void this tender line?', danger: true }))) return
 *
 *   const values = await prompt({
 *     title: 'Record payout',
 *     fields: [
 *       { name: 'amount', label: 'Amount', type: 'number', min: 0.01, required: true },
 *       { name: 'method', label: 'Method', type: 'select', options: [
 *         { value: 'cash', label: 'Cash' }, { value: 'check', label: 'Check' },
 *       ] },
 *     ],
 *   })
 *   if (!values) return // cancelled — distinct from a zero/empty answer
 */

export type FieldType = 'text' | 'number' | 'select' | 'textarea' | 'date';

export interface DialogField {
  name: string;
  label: string;
  type?: FieldType;
  /** Pre-filled value. */
  value?: string | number;
  placeholder?: string;
  required?: boolean;
  /** Numeric bounds, applied only when type is 'number'. */
  min?: number;
  max?: number;
  step?: number;
  options?: Array<{ value: string; label: string }>;
  help?: string;
}

export interface ConfirmOptions {
  title: string;
  body?: ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  /** Styles the primary action as destructive. */
  danger?: boolean;
}

export interface PromptOptions {
  title: string;
  body?: ReactNode;
  fields: DialogField[];
  confirmLabel?: string;
  cancelLabel?: string;
  danger?: boolean;
}

/** Values keyed by field name. Numbers come back as numbers, not strings. */
export type PromptResult = Record<string, string | number>;

interface DialogsApi {
  confirm: (options: ConfirmOptions) => Promise<boolean>;
  prompt: (options: PromptOptions) => Promise<PromptResult | null>;
}

const DialogsContext = createContext<DialogsApi | null>(null);

export function useDialogs(): DialogsApi {
  const ctx = useContext(DialogsContext);
  if (!ctx) throw new Error('useDialogs must be used inside <DialogsProvider>');
  return ctx;
}

interface OpenState {
  kind: 'confirm' | 'prompt';
  options: ConfirmOptions & Partial<PromptOptions>;
  resolve: (value: never) => void;
}

export function DialogsProvider({ children }: { children: ReactNode }) {
  const [open, setOpen] = useState<OpenState | null>(null);
  const [values, setValues] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const firstFieldRef = useRef<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement | null>(null);
  const confirmButtonRef = useRef<HTMLButtonElement | null>(null);

  const confirm = useCallback(
    (options: ConfirmOptions) =>
      new Promise<boolean>((resolve) => {
        setError('');
        setValues({});
        setOpen({ kind: 'confirm', options, resolve: resolve as (v: never) => void });
      }),
    [],
  );

  const prompt = useCallback(
    (options: PromptOptions) =>
      new Promise<PromptResult | null>((resolve) => {
        setError('');
        setValues(
          Object.fromEntries(
            options.fields.map((f) => [
              f.name,
              f.value !== undefined ? String(f.value) : f.type === 'select' ? (f.options?.[0]?.value ?? '') : '',
            ]),
          ),
        );
        setOpen({ kind: 'prompt', options, resolve: resolve as (v: never) => void });
      }),
    [],
  );

  const close = useCallback(
    (result: boolean | PromptResult | null) => {
      open?.resolve(result as never);
      setOpen(null);
      setError('');
    },
    [open],
  );

  // Cancelling must be unambiguous: `false` for confirm, `null` for prompt, so
  // callers can tell "dismissed" from "answered with an empty/zero value".
  const cancel = useCallback(() => close(open?.kind === 'prompt' ? null : false), [close, open]);

  const submit = useCallback(() => {
    if (!open) return;
    if (open.kind === 'confirm') {
      close(true);
      return;
    }

    const out: PromptResult = {};
    for (const field of open.options.fields ?? []) {
      const raw = (values[field.name] ?? '').trim();

      if (field.required && raw === '') {
        setError(`${field.label} is required.`);
        return;
      }

      if (field.type === 'number') {
        if (raw === '') {
          out[field.name] = '';
          continue;
        }
        const n = Number(raw);
        // Native prompt() gave back a string that callers pushed through
        // parseFloat(x || '0'), so bad input silently became 0. Reject it here.
        if (!Number.isFinite(n)) {
          setError(`${field.label} must be a number.`);
          return;
        }
        if (field.min !== undefined && n < field.min) {
          setError(`${field.label} must be at least ${field.min}.`);
          return;
        }
        if (field.max !== undefined && n > field.max) {
          setError(`${field.label} must be at most ${field.max}.`);
          return;
        }
        out[field.name] = n;
        continue;
      }

      out[field.name] = raw;
    }

    close(out);
  }, [close, open, values]);

  // Escape cancels, Enter submits (except inside a textarea, where Enter is
  // a newline). Bound at the document so it works wherever focus sits.
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.preventDefault();
        cancel();
        return;
      }
      if (e.key === 'Enter' && !(e.target instanceof HTMLTextAreaElement)) {
        e.preventDefault();
        submit();
      }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, cancel, submit]);

  // Move focus into the dialog so keyboard and screen-reader users land in the
  // right place instead of being left behind on the trigger button.
  useEffect(() => {
    if (!open) return;
    queueMicrotask(() => {
      if (firstFieldRef.current) firstFieldRef.current.focus();
      else confirmButtonRef.current?.focus();
    });
  }, [open]);

  const api = useMemo<DialogsApi>(() => ({ confirm, prompt }), [confirm, prompt]);

  return (
    <DialogsContext.Provider value={api}>
      {children}
      {open && (
        <div
          className="fixed inset-0 z-[100] flex items-center justify-center bg-black/40 p-4"
          role="presentation"
          onMouseDown={(e) => {
            // Backdrop click cancels, but only when the press started on the
            // backdrop — otherwise a drag that ends outside would discard input.
            if (e.target === e.currentTarget) cancel();
          }}
        >
          <div
            className="card w-full max-w-md p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="g2a-dialog-title"
          >
            <h3 id="g2a-dialog-title" className="text-base font-semibold">
              {open.options.title}
            </h3>

            {open.options.body && <div className="mt-2 text-sm text-zinc-500">{open.options.body}</div>}

            {open.kind === 'prompt' && (
              <div className="mt-3 grid gap-3">
                {(open.options.fields ?? []).map((field, i) => {
                  const id = `g2a-dialog-field-${field.name}`;
                  const common = {
                    id,
                    className: 'input mt-1 w-full',
                    value: values[field.name] ?? '',
                    onChange: (
                      e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>,
                    ) => setValues((v) => ({ ...v, [field.name]: e.target.value })),
                  };
                  return (
                    <div key={field.name}>
                      <label className="text-sm font-medium" htmlFor={id}>
                        {field.label}
                        {field.required && <span aria-hidden="true"> *</span>}
                      </label>
                      {field.type === 'select' ? (
                        <select
                          {...common}
                          ref={i === 0 ? (el) => { firstFieldRef.current = el; } : undefined}
                        >
                          {(field.options ?? []).map((o) => (
                            <option key={o.value} value={o.value}>
                              {o.label}
                            </option>
                          ))}
                        </select>
                      ) : field.type === 'textarea' ? (
                        <textarea
                          {...common}
                          rows={3}
                          placeholder={field.placeholder}
                          ref={i === 0 ? (el) => { firstFieldRef.current = el; } : undefined}
                        />
                      ) : (
                        <input
                          {...common}
                          type={field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'}
                          placeholder={field.placeholder}
                          min={field.min}
                          max={field.max}
                          step={field.step ?? (field.type === 'number' ? 'any' : undefined)}
                          ref={i === 0 ? (el) => { firstFieldRef.current = el; } : undefined}
                        />
                      )}
                      {field.help && <p className="mt-1 text-xs text-zinc-500">{field.help}</p>}
                    </div>
                  );
                })}
              </div>
            )}

            {error && (
              <p className="mt-3 rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800" role="alert">
                {error}
              </p>
            )}

            <div className="mt-4 flex justify-end gap-2">
              <button className="btn" onClick={cancel}>
                {open.options.cancelLabel ?? 'Cancel'}
              </button>
              <button
                ref={confirmButtonRef}
                className={open.options.danger ? 'btn-danger' : 'btn-primary'}
                onClick={submit}
              >
                {open.options.confirmLabel ?? (open.kind === 'confirm' ? 'Confirm' : 'Save')}
              </button>
            </div>
          </div>
        </div>
      )}
    </DialogsContext.Provider>
  );
}
