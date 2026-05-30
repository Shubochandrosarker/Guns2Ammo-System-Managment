# Waivers on File — Ottertext import + lookup

_Memberistic 1.43.0 (DB schema 1.5.0) · 2026-05-29_

Stores every signed range waiver in a searchable archive (members **and**
walk-ins), mirrors the PDFs into your own protected storage, matches members,
and lets staff look up "do we have a waiver for this person?" by **email or
name** at booking / check-in.

## What it does

- **New table** `wp_memberistic_waivers_archive` — indexed by email, last name,
  DOB. One **current** waiver per person (repeat signings collapse; the latest
  wins).
- **PDF mirroring** — each signed PDF is downloaded from `media.otterwaiver.com`
  into `wp-content/uploads/memberistic-waivers/`, which is **hardened against
  public access** (`.htaccess` deny). They survive Ottertext cancellation.
- **Member matching** — rows whose email matches a WP user stamp that member's
  person record `waiver_status = signed` (+ signed date).
- **Staff lookup** — Memberistic → **Waivers on File**: search by email/name →
  shows status, signed date, contact, and a **protected "View signed PDF"** link
  (streamed only to logged-in staff, capability-checked).
- **Public API** — `memberistic_waiver_on_file( $email, $name = '', $dob = '' )`
  returns the current waiver row (or null) for use anywhere (booking form,
  check-in, account).

## How to import (run on the LIVE site)

The importer needs the live member DB + network access to fetch the PDFs.

**Option A — WP-CLI (best for the full 1,900+ file run):**
```bash
wp memberistic import-waivers /path/to/Range_Waiver_20260529.csv
#   --no-pdf   skip PDF mirroring (just names/dates/links)
#   --fresh    clear the archive first (clean re-import)
#   --limit=N  only the first N people (test run)
```

**Option B — Admin upload:** Memberistic → **Waivers on File** → *Import from
Ottertext CSV* → choose the file → Import. (For ~1,900 rows + PDF downloads,
CLI is more reliable than a single web request.)

> ⏰ **Do this before cancelling Ottertext** — once the account closes, the
> `media.otterwaiver.com` PDF links stop working and only the mirrored copies
> remain. Import (with PDF mirroring on) first, verify, then cancel.

## CSV mapping

| Archive field | CSV column |
|---|---|
| first/last name | Adult First/Last Name |
| email, phone | Email, Phone |
| dob | Adult Birthday (MM/DD/YYYY → Y-m-d) |
| signed_at | Date (ISO → site time) |
| external_url | Document URL |
| minor name/age | Minor Name / Minor Age |
| emergency contact | Emergency Contact Name / Phone |
| source, type | Source, Participant Type |

Validated against the real export: **1,922 rows → 1,793 unique people.**

## Not yet wired (next step, on request)

**Auto-satisfying the booking waiver step** (so an on-file customer never
re-signs at booking) needs a small hook in the **G2A Booking Engine**. I held
off because we just learned the live plugins can be ahead of the repo — confirm
the live Booking Engine version first (same downgrade trap we hit with
Memberistic), then I'll add it. The lookup API + `memberistic_waiver_on_file()`
are ready for it.
