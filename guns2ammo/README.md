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

Course, Private Instruction and Arsenal pages render a reservation form
(`template-parts/reservation-form.php`). On submit it posts to
`admin-post.php` and emails the address in **Customizer  Business Info 
Email** (falling back to the WordPress admin email). A honeypot field blocks
basic spam; no plugin required.

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

## Performance

- Dequeues Storefront parent CSS, WP block-library, classic-theme styles, jQuery Migrate.
- Removes `wp_generator`, RSD/WLW links, oEmbed discovery, REST head link.
- `g2a-chrome.js` deferred via `script_loader_tag` filter.
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
    customizer.php         Business info + social fields
 assets/
    css/tokens.css         Full design tokens + components
    css/app.css            Preloader, lazy-fade, reveal
    js/chrome.js           Behaviors only (nav, profile, live status, modal, countdown)
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
