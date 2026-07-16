import { useEffect, useState } from 'react';
import { errorMessage, get, post } from '../api';
import PageHeader from '../components/PageHeader';

export default function Settings() {
  return (
    <div>
      <PageHeader title="Settings" subtitle="Stripe Terminal, CA DROS, 4473 template, 2FA." />
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <StripeCard />
        <DrosCard />
        <FormTemplateCard />
        <TotpCard />
      </div>
    </div>
  );
}

function Card({ title, subtitle, children }: { title: string; subtitle: string; children: React.ReactNode }) {
  return (
    <div className="card p-5">
      <h3 className="text-base font-semibold">{title}</h3>
      <p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{subtitle}</p>
      <div className="mt-4 space-y-3">{children}</div>
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Stripe Terminal                                                     */
/* ------------------------------------------------------------------ */

interface StripeConfig {
  mode: 'test' | 'live';
  publishable_key: string;
  account_id: string;
  secret_key: string;
  secret_key_configured?: boolean;
}

function StripeCard() {
  const [cfg, setCfg] = useState<StripeConfig | null>(null);
  const [saving, setSaving] = useState(false);
  const [status, setStatus] = useState('');

  useEffect(() => {
    (async () => {
      try {
        setCfg(await get<StripeConfig>('/settings/stripe'));
      } catch (e) {
        setStatus('✗ ' + errorMessage(e, 'Could not load Stripe settings.'));
      }
    })();
  }, []);

  const save = async () => {
    if (!cfg) return;
    setSaving(true);
    setStatus('');
    try {
      const r = await post<StripeConfig>('/settings/stripe', cfg);
      setCfg(r);
      setStatus('✓ Saved. The cashier lane will accept card-present payments once a secret key is set.');
    } catch (e) {
      setStatus('✗ ' + errorMessage(e, 'Could not save Stripe settings.'));
    } finally {
      setSaving(false);
    }
  };

  if (!cfg) return <Card title="Stripe Terminal" subtitle="Loading…">{null}</Card>;

  return (
    <Card title="Stripe Terminal" subtitle="Card-present payments for the cashier lane.">
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="block text-xs mb-1">Mode</label>
          <select className="input w-full" value={cfg.mode} onChange={(e) => setCfg({ ...cfg, mode: e.target.value as StripeConfig['mode'] })}>
            <option value="test">Test</option>
            <option value="live">Live</option>
          </select>
        </div>
        <div>
          <label className="block text-xs mb-1">Account ID (optional)</label>
          <input className="input w-full" placeholder="acct_..." value={cfg.account_id} onChange={(e) => setCfg({ ...cfg, account_id: e.target.value })} />
        </div>
        <div className="col-span-2">
          <label className="block text-xs mb-1">Publishable key</label>
          <input className="input w-full" placeholder="pk_live_…" value={cfg.publishable_key} onChange={(e) => setCfg({ ...cfg, publishable_key: e.target.value })} />
        </div>
        <div className="col-span-2">
          <label className="block text-xs mb-1">Secret key {cfg.secret_key_configured ? '(configured — leave blank to keep)' : ''}</label>
          <input className="input w-full" type="password" value={cfg.secret_key} onChange={(e) => setCfg({ ...cfg, secret_key: e.target.value })} />
        </div>
      </div>
      <div className="flex items-center justify-end gap-2">
        {status && <span className="text-sm mr-auto">{status}</span>}
        <button className="btn-primary" onClick={save} disabled={saving}>{saving ? 'Saving…' : 'Save'}</button>
      </div>
    </Card>
  );
}

/* ------------------------------------------------------------------ */
/* CA DROS                                                              */
/* ------------------------------------------------------------------ */

interface DrosConfig {
  endpoint: string;
  dealer_id: string;
  api_key: string;
  api_key_configured?: boolean;
}

function DrosCard() {
  const [cfg, setCfg] = useState<DrosConfig | null>(null);
  const [saving, setSaving] = useState(false);
  const [status, setStatus] = useState('');

  useEffect(() => {
    (async () => {
      try {
        setCfg(await get<DrosConfig>('/settings/dros'));
      } catch (e) {
        setStatus('✗ ' + errorMessage(e, 'Could not load CA DROS settings.'));
      }
    })();
  }, []);

  const save = async () => {
    if (!cfg) return;
    setSaving(true);
    setStatus('');
    try {
      const r = await post<DrosConfig>('/settings/dros', cfg);
      setCfg(r);
      setStatus('✓ Saved. Without credentials, CA transactions still queue as pending for manual DES portal entry.');
    } catch (e) {
      setStatus('✗ ' + errorMessage(e, 'Could not save CA DROS settings.'));
    } finally {
      setSaving(false);
    }
  };

  if (!cfg) return <Card title="CA DROS" subtitle="Loading…">{null}</Card>;

  return (
    <Card title="CA DROS" subtitle="California Dealer Record of Sale — DES API credentials.">
      <div>
        <label className="block text-xs mb-1">DES API endpoint</label>
        <input className="input w-full" placeholder="https://des.doj.ca.gov/…" value={cfg.endpoint} onChange={(e) => setCfg({ ...cfg, endpoint: e.target.value })} />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="block text-xs mb-1">Dealer ID</label>
          <input className="input w-full" value={cfg.dealer_id} onChange={(e) => setCfg({ ...cfg, dealer_id: e.target.value })} />
        </div>
        <div>
          <label className="block text-xs mb-1">API key {cfg.api_key_configured ? '(configured — leave blank to keep)' : ''}</label>
          <input className="input w-full" type="password" value={cfg.api_key} onChange={(e) => setCfg({ ...cfg, api_key: e.target.value })} />
        </div>
      </div>
      <div className="flex items-center justify-end gap-2">
        {status && <span className="text-sm mr-auto">{status}</span>}
        <button className="btn-primary" onClick={save} disabled={saving}>{saving ? 'Saving…' : 'Save'}</button>
      </div>
    </Card>
  );
}

/* ------------------------------------------------------------------ */
/* ATF 4473 PDF template                                               */
/* ------------------------------------------------------------------ */

interface TemplateStatus { template_installed: boolean; overlay_mode: boolean }
interface FieldsResponse { fields: Record<string, unknown>; source: 'live' | 'example' | 'empty' }

function FormTemplateCard() {
  const [status, setStatus] = useState<TemplateStatus | null>(null);
  const [fieldsJson, setFieldsJson] = useState('');
  const [fieldsSource, setFieldsSource] = useState('');
  const [uploading, setUploading] = useState(false);
  const [savingFields, setSavingFields] = useState(false);
  const [message, setMessage] = useState('');

  const refresh = async () => {
    const [s, f] = await Promise.all([
      get<TemplateStatus>('/atf/4473/template/status'),
      get<FieldsResponse>('/atf/4473/template/fields'),
    ]);
    setStatus(s);
    setFieldsJson(JSON.stringify(f.fields, null, 2));
    setFieldsSource(f.source);
  };
  useEffect(() => { queueMicrotask(refresh); }, []);

  const uploadFile = async (file: File) => {
    setUploading(true);
    setMessage('');
    try {
      const b64 = await new Promise<string>((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result).split(',')[1] || '');
        reader.onerror = () => reject(reader.error);
        reader.readAsDataURL(file);
      });
      await post('/atf/4473/template/upload', { pdf_b64: b64 });
      setMessage('✓ Template uploaded.');
      await refresh();
    } catch (e) {
      setMessage('✗ ' + errorMessage(e, 'Upload failed.'));
    } finally {
      setUploading(false);
    }
  };

  const saveFields = async () => {
    setSavingFields(true);
    setMessage('');
    try {
      const parsed = JSON.parse(fieldsJson);
      await post('/atf/4473/template/fields', { fields: parsed });
      setMessage('✓ Field coordinates saved.');
      await refresh();
    } catch (e) {
      setMessage(e instanceof SyntaxError ? '✗ Fields must be valid JSON.' : '✗ ' + errorMessage(e, 'Save failed.'));
    } finally {
      setSavingFields(false);
    }
  };

  if (!status) return <Card title="ATF 4473 PDF template" subtitle="Loading…">{null}</Card>;

  return (
    <Card title="ATF 4473 PDF template" subtitle="Overlay the official 5300.9 PDF, or leave unset to use transcript (fallback) mode.">
      <div className="flex items-center gap-2 text-sm">
        <span className={status.template_installed ? 'badge-green' : 'badge-zinc'}>
          {status.template_installed ? 'Template installed' : 'No template — using transcript fallback'}
        </span>
        <span className="badge-zinc">field map: {fieldsSource}</span>
      </div>
      <div>
        <label className="block text-xs mb-1">Upload official ATF 5300.9 PDF</label>
        <input
          className="input w-full"
          type="file"
          accept="application/pdf"
          disabled={uploading}
          onChange={(e) => e.target.files?.[0] && uploadFile(e.target.files[0])}
        />
      </div>
      <details>
        <summary className="cursor-pointer text-xs text-zinc-500">Field coordinate map (advanced)</summary>
        <textarea
          className="input w-full mt-2 font-mono text-xs"
          rows={8}
          value={fieldsJson}
          onChange={(e) => setFieldsJson(e.target.value)}
        />
        <div className="mt-2 flex justify-end">
          <button className="btn-sm" onClick={saveFields} disabled={savingFields}>{savingFields ? 'Saving…' : 'Save field map'}</button>
        </div>
      </details>
      {message && <div className="text-sm">{message}</div>}
    </Card>
  );
}

/* ------------------------------------------------------------------ */
/* 2FA / TOTP                                                          */
/* ------------------------------------------------------------------ */

function TotpCard() {
  const [enrolling, setEnrolling] = useState(false);
  const [secret, setSecret] = useState('');
  const [otpauthUri, setOtpauthUri] = useState('');
  const [code, setCode] = useState('');
  const [confirming, setConfirming] = useState(false);
  const [confirmed, setConfirmed] = useState(false);
  const [message, setMessage] = useState('');

  const enroll = async () => {
    setEnrolling(true);
    setMessage('');
    try {
      const r = await post<{ secret: string; otpauth_uri: string }>('/security/totp/enroll');
      setSecret(r.secret);
      setOtpauthUri(r.otpauth_uri);
      setConfirmed(false);
    } catch (e) {
      setMessage('✗ ' + errorMessage(e, 'Could not start enrollment.'));
    } finally {
      setEnrolling(false);
    }
  };

  const confirm = async () => {
    if (code.trim().length !== 6) return;
    setConfirming(true);
    setMessage('');
    try {
      await post('/security/totp/confirm', { code: code.trim() });
      setConfirmed(true);
      setMessage('✓ Two-factor authentication is now active on your account.');
    } catch (e) {
      setMessage('✗ ' + errorMessage(e, 'Code did not match — try again.'));
    } finally {
      setConfirming(false);
    }
  };

  return (
    <Card title="2FA / TOTP" subtitle="Per-user enrollment. Confirmed staff must send a 6-digit code via the X-G2A-TOTP header on high-risk endpoints.">
      {!secret && (
        <button className="btn-primary" onClick={enroll} disabled={enrolling}>{enrolling ? 'Starting…' : 'Start enrollment'}</button>
      )}
      {secret && !confirmed && (
        <>
          <div className="text-xs text-zinc-500">Scan this into your authenticator app, or enter the secret manually:</div>
          <div className="rounded bg-zinc-100 p-2 font-mono text-xs break-all dark:bg-zinc-800">{secret}</div>
          <div className="text-xs text-zinc-500 break-all">{otpauthUri}</div>
          <div className="flex items-center gap-2">
            <input className="input" placeholder="6-digit code" maxLength={6} value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} />
            <button className="btn-primary" onClick={confirm} disabled={confirming || code.length !== 6}>{confirming ? 'Confirming…' : 'Confirm'}</button>
          </div>
        </>
      )}
      {confirmed && <div className="badge-green w-fit">Enrolled and active</div>}
      {message && <div className="text-sm">{message}</div>}
    </Card>
  );
}
