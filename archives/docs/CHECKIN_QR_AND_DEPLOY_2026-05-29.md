# Check-in QR fix + deployment note (2026-05-29)

Two issues were reported from the **live** site. Here's the root cause of each
and exactly what makes them go away.

## 1. Check-in / member-card QR "not working" — FIXED

**Root cause:** the in-house QR generator (`Memberistic\Utilities\QR`) had a
broken Reed–Solomon error-correction routine. Verified empirically against a
reference encoder: the matrix it produced for `https://guns2ammo.com/check-in/`
differed in **146 of 841 modules** — i.e. it was not a valid, scannable QR.

**Fix:** rewrote the encoder's RS stage (and hardened the full pipeline:
multi-block ECC interleaving, function-pattern placement, mask 0, format +
version info, and a required 4-module quiet zone). It now matches a reference
QR **byte-for-byte across versions 1–10** (validated for payloads from 2 to 200
chars). Member-card QR (`templates/account.php`) is fixed; Memberistic →
**1.36.1**.

**About the check-in poster page** (`?memberistic_waiver_poster=1`, the QR that
links to `/check-in/`): that page's code is **not in this repository** — the
live site runs a newer Memberistic build than what's committed here. But the
poster uses this same `QR` class, so deploying the updated plugin fixes its QR
too. After deploying, also confirm:

- The **`/check-in/` page exists** and resolves (if the QR scans but lands on a
  404, that also looks like "not working").
- If the poster renders the external `api.qrserver.com` image as primary (as the
  member card does), that path was already working; the fix matters for the
  in-process SVG path / privacy-first rendering.

> Privacy note: the member card still calls `api.qrserver.com` as the *primary*
> image with the in-process SVG as an `onerror` fallback. Now that the SVG
> encoder is correct, you can make the SVG primary (and drop the external call)
> to fully honor the "no member data to third parties" intent. Left as-is here
> since it's outside the reported issue — say the word and I'll flip it.

## 2. Ladies Tuesday still shows every date — NOT YET DEPLOYED

The screenshot shows the **old** open calendar (all of June selectable, generic
10am–6pm slots) **and** the Ottertext chat bubble in the corner. Both are
tell-tale signs that **the live site is still running the pre-fix build** — the
event-driven calendar (Booking Engine 1.10.0) and the Ottertext removal are in
this branch/PR but have **not been uploaded to the live site yet**.

To activate the fix on live:

1. **Deploy `g2a-booking-engine.zip` (v1.10.0)** — Plugins → Add New → Upload →
   "Replace current with uploaded".
2. **Publish the Ladies Tuesday dates** — G2A Booking → Events → Add Event, set
   **Event type = `ladies-day`**, a date, start/end time, and seats. Repeat for
   each Tuesday you want open. (Full steps: `LADIES_TUESDAY_BOOKING.md`.)
3. Re-open the Ladies Tuesday page: only the published event dates will be
   selectable (highlighted), with the event's time slots.

> If you deploy and the calendar shows **no selectable dates**, that's expected
> until at least one future `ladies-day` Event is published — it's the gate
> working, not a bug.

Likewise, the Ottertext bubble disappears once you deploy the theme update +
delete the "Otter Text - Chat Widget" plugin (`OTTERTEXT_REMOVAL.md`).
