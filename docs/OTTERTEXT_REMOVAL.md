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

## What's actually installed (scanned 2026-05-29)

The site uses the **"Otter Text - Chat Widget"** plugin
(`otter-text-chat-widget`, v1.0.0, by Otter Text). It is a thin WordPress
Plugin Boilerplate wrapper that does only two things when a Chat Widget ID is
set under **Settings → Otter Text**:

1. Enqueues the remote script `https://app.ottertext.com/js/chatwidget.js`.
2. Prints `<div id="otterWebsiteChatWidget" data-client="…"></div>` in the
   footer.

**Everything else — the chatbot UI, the age-verification popup, the complaint
layer, SMS, and the webhook data link — lives in that remote script on the
Otter Text platform, keyed to your widget ID. None of it is in the plugin.**

That means removal is clean: **deactivating or deleting the plugin stops the
chatbot, the age popup, and the on-site data flow immediately.** There is no
local data-handling code to unwind.

> Note: the plugin's `uninstall.php` is an empty stub — deleting the plugin
> leaves the `otter_text_settings` option (your widget ID) orphaned in
> `wp_options`. The theme now **auto-purges** this option once the plugin files
> are gone (admin load, admins only). A temporary *deactivation* is left alone
> so reactivating keeps your widget ID. To remove it manually / immediately:
>
> ```bash
> wp g2a ottertext-cleanup          # removes it when the plugin is deleted
> wp g2a ottertext-cleanup --force  # removes it even if still installed
> ```
> …or in PHP: `delete_option('otter_text_settings');`

## 1. Remove the embed at the source

For this site the source is the plugin itself:

- [ ] **Plugins → Installed Plugins → "Otter Text - Chat Widget" → Deactivate**
      (then **Delete**). This is the real fix.
- [ ] Optionally delete the orphaned `otter_text_settings` option (above).

Also rule out a *second* copy of the embed added elsewhere (only if the widget
still shows after deleting the plugin):

- [ ] **Header/Footer scripts plugin** (WPCode, "Insert Headers & Footers",
      Header Footer Code Manager) → delete any Ottertext snippet.
- [ ] **Google Tag Manager / GA4** → pause + delete any "Custom HTML" tag that
      loads `app.ottertext.com` / `chatwidget.js`.
- [ ] **Theme / page-builder custom-scripts box** (Customizer → Additional
      Scripts, or builder global settings).
- [ ] **Hard-coded snippet** pasted into a child theme's `footer.php` (the
      parent `guns2ammo` theme contains none).


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
