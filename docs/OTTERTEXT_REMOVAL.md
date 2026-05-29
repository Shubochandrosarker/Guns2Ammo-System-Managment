# Ottertext Removal Checklist

_Last updated: 2026-05-29_

Guns 2 Ammo has **retired Ottertext**. Age verification + visitor data capture
now run on the in-house **Verifyistic** plugin (with multi-webhook delivery),
and the liability waiver is handled by the booking/waiver flow. Ottertext is no
longer used for the on-site chatbot or the age-gate popup.

Ottertext loads as an **external embed** — it is *not* part of the theme or any
bundled plugin, so removing it is a configuration task, not a code change. The
theme ships a safety-net scrubber (`guns2ammo/inc/ottertext-cleanup.php`) that
strips any leftover Ottertext script/iframe while you complete the steps below.

## 1. Remove the embed at the source

Check each of these (the snippet usually lives in exactly one):

- [ ] **Header/Footer scripts plugin** (WPCode, "Insert Headers & Footers",
      Header Footer Code Manager) → delete the Ottertext snippet.
- [ ] **Google Tag Manager / GA4** → pause + delete any "Custom HTML" tag that
      loads `ottertext`.
- [ ] **Theme / page-builder custom-scripts box** (Customizer → Additional
      Scripts, or builder global settings).
- [ ] **Companion plugin** → Plugins → search "Ottertext" → deactivate +
      delete if present.
- [ ] **Hard-coded snippet** pasted into `header.php` / `footer.php` of a child
      theme (the parent `guns2ammo` theme contains none).

## 2. Cut the data link

- [ ] In the **Ottertext dashboard**, disable/delete the **webhook** that
      forwarded verification/age data so no data continues to flow.
- [ ] Revoke any API key Ottertext was given for the site.
- [ ] Cancel/downgrade the Ottertext SMS + chatbot plan if it's no longer used
      for anything else.

## 3. Verify it's gone

- [ ] Open the live site in a private window — the Ottertext chatbot bubble and
      its age popup should not appear.
- [ ] View source / DevTools → Network, filter `ottertext` → zero requests.
- [ ] Confirm the **Verifyistic** age popup appears instead (see
      `VERIFYISTIC_SETUP_G2A.md`).

## 4. Retire the safety net

Once steps 1–3 are confirmed, the scrubber is no longer needed. Either:

- Delete `guns2ammo/inc/ottertext-cleanup.php` and its `require_once` line in
  `functions.php`, **or**
- Leave it (harmless — it only matches the literal token `ottertext`), or
  disable it with:

```php
add_filter( 'g2a_ottertext_cleanup', '__return_false' );
```

> The scrubber runs a late output-buffer pass. Leaving it on indefinitely is
> safe but adds a tiny amount of work per request — removing it after cleanup
> is the tidy option.
