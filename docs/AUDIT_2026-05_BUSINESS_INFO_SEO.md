# Audit fix log — Business info, hours, schema, SEO (theme 1.20.0)

Scope: technical SEO, NAP consistency, structured data, hours
logic, conversion-blocking placeholders. No firearm sales copy
changes, no fabricated review data, no misleading local SEO.

## Files changed

| File | What changed |
|---|---|
| `guns2ammo/inc/business-info.php` | **NEW.** Centralized `g2a_biz()` source of truth for NAP, founding year, review count + rating, hours (per-weekday open/close in minutes), maps URL, geo, social. Helpers: `g2a_biz_is_open_now()`, `g2a_biz_hours_human()`, `g2a_biz_category_counts()` (live WC counts, cached 1hr). Localizes `window.g2aBiz` for the JS pill. |
| `guns2ammo/functions.php` | Require `business-info.php` before `seo.php`. |
| `guns2ammo/footer.php` | NAP + "Since {year}" + review count now from `g2a_biz()`. Dropped the "+" suffix so the number always matches GBP exactly. |
| `guns2ammo/front-page.php` | Hero trust strip, machine-gun reviews block, reviews section, and the entire Visit/hours block now read from `g2a_biz()`. Hours render dynamically Mon→Sun with TODAY highlighted by real America/Phoenix weekday. Category cards use live WC SKU counts. |
| `guns2ammo/template-parts/nav.php` | Shop submenu meta counts now from live WC category counts (`$g2a_shop_meta()`), not hard-coded 24/12/38/21. |
| `guns2ammo/assets/js/chrome.js` | Live pill reads `window.g2aBiz.hours` + tz. Real weekday name, "Opens {day} {time}" when closed, next-open lookup. No hard-coded "Today Tue". |
| `guns2ammo/inc/seo.php` | LocalBusiness JSON-LD reads everything from `g2a_biz()`. `openingHoursSpecification` generated from the hours table (closed days omitted). `aggregateRating` emitted ONLY when verified rating+count exist. `foundingDate` added. noindex,nofollow added for account/cart/checkout/login/etc. via `wp_robots`. |
| `guns2ammo/footer.php` (quick-view) | Removed placeholder "BRAND / Product / $0 / Description." Empty nodes now, populated by JS on open. |

## Before / after — business info consistency

| Item | Before | After |
|---|---|---|
| Founding year | Homepage "Est. 2015"; footer "Since 2014"; About "Since 2014" | **Single value** `g2a_founded_year` (default 2014) everywhere. Homepage + footer now both read it. |
| Review count | Homepage "449+"; footer "500+ Google"; pricing "449+" | **Single value** `g2a_review_count` (default 449), no "+" suffix. Footer + homepage + schema all match. |
| Review rating | Hard-coded 4.7 in ~5 places | `g2a_review_rating` (default 4.7) from one source. |
| Hours | Homepage hard-coded "Today Tue", Sat "9am-8pm", Fri "Closed"; schema Sat "10:00-19:00" | **One hours table.** Homepage, pill, and schema all render from it. Sat now consistent (10am-7pm default). Friday shows real status from settings. |
| Category counts | Nav "24/12/38/21"; homepage cards "38/24/52/18" | **Live WC counts** from `product_cat` term counts, cached 1hr, busted on edits. Nav + homepage identical. |
| Phone / address / email | Hard-coded in ~15 templates | Footer + homepage + schema from `g2a_biz()`. (Remaining static templates listed below for follow-up.) |

## Hours logic

- Timezone: `America/Phoenix` (no DST) everywhere — PHP
  `DateTime` server-side, `Intl`/`toLocaleString` client-side.
- Per-weekday open/close stored in minutes-from-midnight in the
  Customizer (`g2a_hours_{day}_open/close`). A day with
  `open >= close` is treated as **closed** and omitted from
  schema + shown as "Closed" on the homepage.
- The live pill shows: "Open Now · Until 6pm MST" / "Closed ·
  Opens 10am MST" / "Closed · Opens Mon 10am MST" (next-open
  lookup when closed all day).
- Fallback: if `window.g2aBiz` is missing (localizer didn't run),
  the pill falls back to the historical hardcoded schedule so it
  never blanks out.

## Robots + sitemap (validated earlier, unchanged here)

- `/robots.txt` — theme-owned (`inc/robots.php`), `parse_request`
  intercept. Includes `Sitemap: https://guns2ammo.com/sitemap.xml`.
  AI-bot allowlist. Blocks account/cart/checkout/login.
- `/sitemap.xml` — theme-owned (`inc/sitemap.php`), branded XSL,
  master index + per-type sub-sitemaps. Valid XML.
- noindex,nofollow meta added for private pages (this release) —
  belt-and-suspenders with the robots.txt Disallow.

## NAP for GBP sync (verify these match Google Business Profile)

```
Name:    Guns 2 Ammo
Address: 6030 E Main St, Suite 103, Mesa, AZ 85205
Phone:   (602) 715-2677
Hours:   Mon-Thu 10am-6pm, Fri 10am-7pm, Sat 10am-7pm, Sun 12pm-6pm
Founded: 2014
```
If any of these differ from the live GBP listing, update them in
**Appearance → Customize → G2A Business Info** and they propagate
everywhere automatically. (Customizer field registration is the
next task — see Follow-ups.)

## Follow-ups (not in this commit)

1. **Register the new Customizer controls** for all the
   `g2a_*` theme mods this module reads (founded_year,
   review_count, review_rating, per-day hours, business_name,
   etc.) so the client can edit them without code. Defaults are
   correct so the site renders fine until then.
2. **Sweep remaining static templates** (about, contact,
   transfers, get-support, legal, collections) to use
   `g2a_biz()` instead of hard-coded NAP. ~12 files.
3. Move membership prices to the Memberistic plan rows (single
   source) so pricing templates don't hardcode 29.99/39.99/59.99.
4. Image optimization pass (width/height attrs, WebP, alt text).

## Testing checklist

- [ ] **Homepage** — hero trust strip shows one rating + count + Est. year; Visit block shows correct day highlighted (Mesa time), Sat 10am-7pm, no "Today Tue"; category cards show live SKU counts.
- [ ] **Footer** — "Since 2014", review number matches homepage, no "+".
- [ ] **Live pill** — open/closed correct for current Mesa time; shows next-open when closed.
- [ ] **Nav → Shop** — submenu counts match homepage category cards.
- [ ] **Quick-view** — no "BRAND/Product/$0" flash; opens populated.
- [ ] **Schema validator** (search.google.com/test/rich-results) — LocalBusiness valid; openingHoursSpecification matches visible hours; aggregateRating present + matches on-page number; no errors.
- [ ] **/robots.txt** — first line `# Guns 2 Ammo`; Sitemap line present.
- [ ] **/sitemap.xml** — renders branded table; valid XML.
- [ ] **GSC URL inspection** — /account/, /cart/, /checkout/ show "Excluded by noindex"; homepage + key pages indexable.
- [ ] **Mobile menu** — opens, traps focus, ESC closes, profile icon visible.
