import PageHeader from '../components/PageHeader';
export default function NicsQueue() {
  return (
    <div>
      <PageHeader
        title="NICS Queue"
        subtitle="National Instant Criminal Background Check System (FBI) — initiated as part of the 4473 transaction. State POC states route through their own portal. Status flow: pending → proceed / delayed / denied. Federal default-proceed at 3 business days per 18 USC § 922(t)(1)(B). When a qualifying state CCW is presented, NICS is bypassed under 18 USC § 922(t)(3) — the 4473 form is still required. See Compliance → CCW Bypass." />
      <div className="card p-6 text-sm text-zinc-600 dark:text-zinc-400">
        NICS queue list wires to <code className="rounded bg-zinc-100 px-1 dark:bg-zinc-800">GET /atf/nics</code>. Records appear automatically when a 4473 hits the NICS step; default-proceed promotions run on the hourly cron.
      </div>
    </div>
  );
}
