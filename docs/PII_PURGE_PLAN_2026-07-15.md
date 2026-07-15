# PII Purge Plan — Real Otter Waiver Export in Git History

**Status: prepared, NOT executed. The history rewrite below requires explicit
owner sign-off and a coordinated maintenance window.**

## What leaked and where

`g2a-pos-core/tests/fixtures/range_waivers_otterwaiver.csv` — the real Otter
Waiver production export: 1,922 rows of customer names, dates of birth,
emails, phones, addresses, emergency contacts, ~76 minors' names/ages, and
signed-document URLs.

| Location | State |
|---|---|
| `Shubochandrosarker/Guns2Ammo-System-Managment` working tree | ✅ replaced with synthetic fixture (PR #79) |
| `Shubochandrosarker/G2A-POS-Solutions` working tree | ✅ replaced with synthetic fixture (PR) |
| `releases/g2a-pos-core-3.2.0.zip` | ✅ rebuilt clean |
| `releases/g2a-pos-core-3.1.8.zip`, `3.1.9.zip` | ✅ CSV entry deleted from both archives |
| **Git history of both repos** (since commit `fb5194e`, 2026-06-14) | ❌ still present — this plan |
| **Deployed installs** that received 3.1.8/3.1.9 zips | ❌ CSV may be web-readable at `wp-content/plugins/g2a-pos-core/tests/fixtures/…` — check production |
| Old zip copies anywhere outside git (backups, downloads, staging) | ❌ owner to sweep |

## History rewrite procedure (needs sign-off)

For **each** of the two repos:

1. Freeze pushes; merge or close all open PRs first (a rewrite orphans them).
2. Fresh clone with `git clone --mirror`.
3. `git filter-repo --invert-paths --path g2a-pos-core/tests/fixtures/range_waivers_otterwaiver.csv --path releases/g2a-pos-core-3.1.8.zip --path releases/g2a-pos-core-3.1.9.zip --path releases/g2a-pos-core-3.2.0.zip`
   (zips are included because historical zip blobs contain the CSV; the
   current clean zips get re-committed after the rewrite).
4. Force-push all refs; every collaborator re-clones.
5. Contact GitHub Support to expunge cached/dangling blobs and any fork
   network copies (a force-push alone does not delete unreachable blobs on
   GitHub's side).
6. Re-commit the clean release zips.
7. Verify: `git rev-list --all | xargs -n1 git ls-tree -r | grep otterwaiver`
   returns only the 4-line synthetic fixture blob.

## Production checks (do alongside)

- On guns2ammo.com: confirm whether
  `wp-content/plugins/g2a-pos-core/tests/fixtures/range_waivers_otterwaiver.csv`
  exists and is fetchable; delete it and check access logs for retrievals.
- Deploy the clean 3.2.0 build.

## Breach assessment (owner + attorney)

- Repos are private (exposure limited to repo collaborators + any deployed
  web-readable copies), but the data includes minors. Arizona's data-breach
  statute (A.R.S. § 18-552) and the business's own policies should be
  reviewed with counsel to decide whether notification duties apply.
- Otter Waiver document URLs in the CSV point at `media.otterwaiver.com` —
  confirm whether those links are tokenized/expiring or publicly fetchable.
