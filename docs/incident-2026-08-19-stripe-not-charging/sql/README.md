# Read-only forensic queries — 2026-08-19 Stripe incident

Every file here is `SELECT`-only. Nothing writes, updates, deletes or drops.
Run them against a **read replica or a restored backup** if one exists;
otherwise run them on production as a read-only DB user.

Before running anything, confirm the table prefix:

```bash
grep table_prefix wp-config.php
```

These files assume `wp_`. If production differs, run:

```bash
sed -i 's/\bwp_g2ab_/<prefix>g2ab_/g; s/\bwp_memberistic_/<prefix>memberistic_/g; s/\bwp_options\b/<prefix>options/g' *.sql
```

Suggested runner:

```bash
for f in 0*.sql; do echo "===== $f ====="; wp db query < "$f"; done | tee forensics-$(date +%F).txt
```

No file prints a Stripe key, a webhook secret or a full card identifier.
`08-reconciliation-export.sql` masks email addresses.
