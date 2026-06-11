import PageHeader from '../components/PageHeader';

export default function Settings() {
  return (
    <div>
      <PageHeader title="Settings" subtitle="Stripe Terminal, CA DROS, 4473 template, locations." />
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card title="Stripe Terminal" status="Configure in wp_options g2a_pos_stripe">
          Set <code>secret_key</code>, <code>publishable_key</code>, <code>account_id</code> (optional), <code>mode</code>. The cashier lane will start accepting card-present payments once credentials are saved.
        </Card>
        <Card title="CA DROS" status="Configure in wp_options g2a_pos_dros_credentials">
          DES API <code>endpoint</code>, <code>api_key</code>, <code>dealer_id</code>. Without credentials, CA transactions queue as pending for manual DES portal entry.
        </Card>
        <Card title="ATF 4473 PDF template" status="Drop the official PDF at assets/templates/4473.pdf">
          Copy <code>4473-fields.example.json</code> to <code>4473-fields.json</code> and tune coordinates against the official 5300.9. Fallback (transcript) mode runs automatically when the template is missing.
        </Card>
        <Card title="2FA / TOTP" status="Enroll per user">
          POST <code>/security/totp/enroll</code> to scan a QR; <code>/confirm</code> to activate. Send the 6-digit code via the <code>X-G2A-TOTP</code> header on high-risk endpoints.
        </Card>
      </div>
    </div>
  );
}

function Card({ title, status, children }: { title: string; status: string; children: React.ReactNode }) {
  return (
    <div className="card p-5">
      <h3 className="text-base font-semibold">{title}</h3>
      <div className="mt-1 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{status}</div>
      <p className="mt-3 text-sm text-zinc-700 dark:text-zinc-300">{children}</p>
    </div>
  );
}
