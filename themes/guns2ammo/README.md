# Guns 2 Ammo  WordPress Theme

A 1:1 port of the Guns 2 Ammo "Tactical Luxury" design package as a **standalone WordPress theme** (no parent  every template is self-contained). Pure PHP templates, no page builder, SEO-optimized, brand preloader, Schema.org JSON-LD.

## Install

1. Copy the `guns2ammo/` folder into `wp-content/themes/`.
2. Install **WooCommerce** (required for `/shop/` and `/product/` templates).
3. In **Appearance  Themes**, activate **Guns 2 Ammo**.
4. Create one WordPress Page per design page, then assign each a Template:
   - `About`  Template: *About*
   - `Book A Lane`  Template: *Book A Lane*
   - etc. (every page in `/page-templates/` is selectable)
5. In **Settings  Reading**, set the homepage to a static page that uses the **Home Page** template (`front-page.php` is loaded automatically when the front page is set).

## Collections & Shop landing pages

Three slug-driven templates power the shopping landing pages. Create the
WordPress Pages below and assign the listed template  the template reads the
page **slug** to know which variant to render.

| Page (slug) | Template | URL |
|---|---|---|
| `collections` | Collections Index | `/collections/` |
| `handguns` | Collection Landing | `/collections/handguns/` |
| `rifles` | Collection Landing | `/collections/rifles/` |
| `ammunition` | Collection Landing | `/collections/ammunition/` |
| `magazines` | Collection Landing | `/collections/magazines/` |
| `ffl-transfers` | Shop Info Landing | `/transfers/` |
| `local-pickup` | Shop Info Landing | `/shop/` |
| `expert-fitment` | Shop Info Landing | `/contact/` |
| `federal-compliance` | Shop Info Landing | `/ffl-services/` |

Make the four collection pages children of `collections` so the URL nests as
`/collections/handguns/`. **Collection Landing** auto-pulls live WooCommerce
products from the matching product category (`handguns`, `rifles`,
`ammunition`, `magazines`); if none exist yet it shows a placeholder grid.

## Training, Private Instruction & Arsenal landing pages

Slug-driven templates power the training and Signature Experience landing
pages. Create the WordPress Pages below and assign the listed template.

| Page (slug) | Template | URL |
|---|---|---|
| `basic-handgun` | Course Landing | `/training/basic-handgun/` |
| `california-ccw` | Course Landing | `/training/california-ccw/` |
| `church-security` | Course Landing | `/training/church-security/` |
| `womens-intro` | Course Landing | `/training/womens-intro/` |
| `defensive-pistol` | Course Landing | `/training/defensive-pistol/` |
| `rifle-fundamentals` | Course Landing | `/training/rifle-fundamentals/` |
| `refuse-to-be-a-victim` | Course Landing | `/training/refuse-to-be-a-victim/` |
| `youth-firearm-safety` | Course Landing | `/training/youth-firearm-safety/` |
| `private-instruction` | Private Instruction | `/private-instruction/` |
| `mp5` | Arsenal Weapon | `/machine-gun/mp5/` |
| `m16` | Arsenal Weapon | `/machine-gun/m16/` |
| `ak-47` | Arsenal Weapon | `/machine-gun/ak-47/` |
| `free-ccw-class` | Free CCW Class | `/free-ccw-class/` |

Make the eight course pages children of `training`, and the three weapon
pages children of `machine-gun`, so the URLs nest correctly. Arizona CCW keeps
its dedicated page at `/ccw/`.

## Content, syllabus & transfer-request pages

| Page (slug) | Template | URL |
|---|---|---|
| `arizona-ccw-syllabus` | CCW Syllabus | `/arizona-ccw-syllabus/` |
| `transfer-request` | Transfer Request | `/transfer-request/` |

The Book A Lane, Arizona CCW, Transfers, Ladies Tuesday and Machine Gun
templates were expanded with deeper content and internal linking. The CCW and
Ladies Tuesday pages now embed a reservation form; the Transfers page links its
"Start A Transfer" buttons to the new Transfer Request landing page.

## Sell Your Gun & Get Support pages

| Page (slug) | Template | URL |
|---|---|---|
| `sell-your-gun` | Sell Your Gun | `/sell-your-gun/` |
| `get-support` | Get Support | `/get-support/` |

**Sell Your Gun** is an SEO/AEO-focused landing page for buying used firearms
from the public, with a detailed sell-request form. **Get Support** is the
central help page for membership, selling, transfer, order and general
requests. Both forms post to the generic `admin-post.php` request handler and
email the business inbox. They are linked from the footer Quick Access menu.

## Knowledge Hub & legal pages

The blog index, category, tag and search archives (`index.php`, used by
`archive.php`) and single posts (`single.php`) were rebuilt as a two-column
Knowledge Hub with a shared sidebar (`template-parts/blog-sidebar.php`)  search,
category list, recent posts, and a "Sell Your Used Guns" promotion. To make
`/blog/` show real posts, set it as the Posts page under **Settings  Reading**.

A slug-driven Legal Document template covers all three policy pages:

| Page (slug) | Template | URL |
|---|---|---|
| `privacy-policy` | Legal Document | `/privacy-policy/` |
| `terms-and-conditions` | Legal Document | `/terms-and-conditions/` |
| `refund-and-returns-policy` | Legal Document | `/refund-and-returns-policy/` |

The legal copy is firearms-retail-aware but should be reviewed by counsel
before launch (a note on each page says so).

### Reservation forms

Course, Private Instruction, Arsenal, Ladies Tuesday and Machine Gun pages
render a reservation form (`template-parts/reservation-form.php`; pass a
`packages` array to offer a tier picker, as the Machine Gun page does). On
submit it posts to the front end (`g2a_form_action_url()`, handled on
`template_redirect` — see `g2a_route_frontend_form()` in `functions.php`, not
`admin-post.php`, since WAFs/security plugins commonly block `/wp-admin/`
requests for logged-out visitors) and emails the address in **Customizer 
Business Info  Email** (falling back to the WordPress admin email). A
honeypot field blocks basic spam. Every submission is also mirrored into the
Formistic plugin's inbox when it's active (`g2a_capture_to_formistic()`), so
neither plugin is required for the form itself to work.

## Plugin integration (Memberistic + G2A Booking Engine)

`inc/plugins.php` wires the theme to the two companion plugins:

- **Memberistic** powers memberships. The Checkout, Account, Login, Renewal,
  Thank You, Payment Failed and Staff templates host the matching
  `[memberistic_*]` shortcode; the Memberships page swaps its plan cards for
  `[memberistic_plans]` when the plugin is active (and falls back to the static
  Defender/Patriot/Guardian cards when it is not).
- **G2A Booking Engine** powers bookings. Book A Lane renders
  `[g2a_lane_booking]`, and the Arizona CCW and Ladies Tuesday pages render
  `[g2a_booking_form]` when the plugin is active (falling back to the email
  reservation form otherwise).
- The theme exposes `g2a_has_memberistic()` and `g2a_has_booking()` helpers,
  feeds the plugins a page map so they reuse the branded theme pages instead of
  creating duplicates, and adds a small CSS bridge so plugin output inherits the
  theme palette and fonts.

If a plugin is inactive, affected sections show a branded "coming online"
notice instead of a broken shortcode  the site never looks broken.

## Customizer

`Appearance  Customize  Guns 2 Ammo  Business Info` controls:
- Phone, email, address, lat/lng (drives Schema.org LocalBusiness)
- Rating + review count
- Social URLs (Facebook, Instagram, X, YouTube)
- Default OG image + home meta description

## SEO

- Auto-detects **Rank Math / Yoast** and yields title/meta/canonical/og to them.
- Always emits LocalBusiness JSON-LD (uses the customizer values above).
- Adds BreadcrumbList on singular/archive pages.
- Adds Article schema on single posts (uses featured image + author).
- Adds Product schema on WooCommerce single-product pages.
- `g2a_faq_schema()` helper available inside any template for FAQPage markup.

## AEO / GEO / local SEO

- `inc/aeo.php` emits **Organization** and **WebSite** (with a sitelinks
  SearchAction) JSON-LD on every page.
- The **LocalBusiness** schema now includes `areaServed` (Mesa, Phoenix,
  Gilbert, Tempe, Chandler, Scottsdale, Apache Junction, Queen Creek, Maricopa
  County), `hasOfferCatalog` with the three membership plans, `knowsAbout`, and
  a map link  tuned for local search and AI answer engines.
- **`/llms.txt`** and **`/llms-full.txt`** are served dynamically (no file
  upload needed) to help AI chatbots understand and cite Guns 2 Ammo. The
  `robots.txt` filter points crawlers at them.
- A branded **HTML sitemap** ships as the `HTML Sitemap` template  create a
  Page with slug `sitemap`.

## URL slugs

The Arizona CCW page now uses the descriptive slug `arizona-ccw-certification`
(`/arizona-ccw-certification/`). When creating WordPress Pages, match these
slugs so internal links resolve. The full slug  template  URL mapping for
every custom page is listed in the sections above.

## Single product page

Rebuilt in 1.28.0. The screen is owned by three files — `inc/single-product.php`
(behaviour), `assets/css/single-product.css` (presentation, enqueued only on
product requests) and `assets/js/single-product.js` — plus the template
overrides in `woocommerce/single-product/`. **Do not add `.single-product`
rules to `tokens.css`, `app.css` or `wc-fixes.css`**; that is exactly the drift
this rebuild removed.

Layout: gallery with a vertical thumbnail rail and click-to-zoom lightbox on
the left; title, brand, SKU, price, stock/FFL badges, quantity stepper,
add-to-cart, wishlist and a trust row on the right; a vertical tab card below;
then the related-products grid.

**Gallery.** Theme-owned, so the stock `wc-product-gallery-*` theme supports
are deliberately not registered — no FlexSlider, jQuery-zoom or PhotoSwipe is
loaded. Thumbnails come from the product's gallery images in order.

**Tabs are driven by how the description is written.** The description is split
on its `<h2>` headings and each section is routed to a tab:

| Heading matches | Goes to |
| --- | --- |
| `…Specifications` | **Specifications** tab (above the WooCommerce attributes table) |
| `Common Questions` / `FAQs` | **FAQs** tab, rendered as an accordion |
| anything else | **Description** tab |

Inside a questions section, each `<h3>` (or a paragraph that is entirely bold)
starts a new question and everything after it is its answer. A description with
no `<h2>`s is left completely alone — it all stays in Description. A questions
section with no recognisable Q&A pairs stays in Description rather than being
dropped.

**Shipping & Returns** is generated, not authored: firearms (products flagged
`_wpistic_ffl_required` by Advanced FFL Checkout) get the FFL-transfer and
final-sale copy, everything else gets the 14-day accessories window. The trust
row under the buy box switches on the same flag, so a firearm never advertises
direct shipping or easy returns.

**Wishlist** is a browser-local save-for-later (`localStorage`). It hides itself
automatically if a real wishlist plugin (YITH, TI, WPC) is activated, and can be
switched off with the `g2a_single_product_show_wishlist` filter.

Other filters: `g2a_new_product_days` (default 30) controls the "New" badge on
the gallery and shop cards; `g2a_brand_taxonomies` controls where the brand line
looks for a value.

## Performance

- Dequeues Storefront parent CSS, WP block-library, classic-theme styles, jQuery Migrate.
- Removes `wp_generator`, RSD/WLW links, oEmbed discovery, REST head link.
- `g2a-chrome.js` and `g2a-single-product.js` deferred via `script_loader_tag` filter.
- Product pages no longer load FlexSlider, jQuery-zoom or PhotoSwipe (~60KB of
  script) — the theme ships its own gallery instead.
- Fonts loaded once with `display=swap`, `preconnect` + `dns-prefetch` for `fonts.gstatic.com`.
- Native `loading="lazy"` + IntersectionObserver reveal-on-scroll.
- Brand preloader (logo + brass sweep) before first paint; auto-dismisses on `load` with a 4s safety net.

## File layout

```
guns2ammo/
 style.css                  Theme header (standalone, no parent)
 functions.php              Asset enqueue, perf strips, theme support
 header.php / footer.php
 front-page.php             Home (porting source: homepage.html)
 page.php / single.php / index.php / archive.php / search.php / 404.php
 inc/
    seo.php                Meta + JSON-LD (Rank Math / Yoast aware)
    woocommerce.php        Shop loop overrides, Product schema
    single-product.php     Product page: summary stack, tabs, brand/FFL facts
    customizer.php         Business info + social fields
 woocommerce/
    single-product.php     Page wrapper + sticky buy bar
    single-product/        Gallery + vertical tabs template overrides
    archive-product.php    Shop / category archive
    cart|checkout|myaccount
 assets/
    css/tokens.css         Full design tokens + components
    css/app.css            Preloader, lazy-fade, reveal
    css/single-product.css Product page (product requests only)
    js/chrome.js           Behaviors only (nav, profile, live status, modal, countdown)
    js/single-product.js   Gallery rail, lightbox, qty stepper, wishlist
 template-parts/
    nav.php
    mobile-drawer.php
 page-templates/
     template-about.php
     template-book-a-lane.php
     template-ccw.php
     template-ladies-tuesday.php
     template-machine-gun.php
     template-memberships.php
     template-pricing.php
     template-checkout.php
     template-thank-you.php
     template-account.php
     template-login.php
     template-renewal.php
     template-payment-failed.php
     template-blog.php
     template-post.php
     template-shop.php
     template-single-product.php
     template-training.php
     template-ffl-services.php
     template-transfers.php
     template-range-safety.php
     template-contact.php
     template-staff.php
     template-thank-you.php
```

## Hooking the booking + membership engines

Booking and membership forms are currently hard-coded HTML. When you have the
plugins live, replace the marked sections:

- **`template-book-a-lane.php`**  swap the inline form with the **G2A Booking
  Engine** shortcode (e.g. `[g2a_booking_engine]`).
- **`template-memberships.php`, `template-pricing.php`, `template-checkout.php`,
  `template-account.php`, `template-renewal.php`, `template-login.php`,
  `template-payment-failed.php`**  swap with **Memberistic** shortcodes
  (`[memberistic_plans]`, `[memberistic_account]`, `[memberistic_login]`, etc.).

## Design tokens

Edit `assets/css/tokens.css` to change palette/typography. Live theme variants:

```html
<html data-vibe="operator|heritage|rangeday" data-density="compact|spacious">
```

## Image hosting

All hero/background image references currently point at
`https://staging10.guns2ammo.com/wp-content/uploads/...`. Once you re-upload
to the production site, find/replace those URLs theme-wide, or move the URLs
into theme-mods using the `g2a_image()` helper in `functions.php`.
