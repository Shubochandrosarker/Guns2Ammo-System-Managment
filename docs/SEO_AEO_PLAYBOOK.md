# SEO + AEO playbook — Guns 2 Ammo

## 1. Who owns what

| Surface | Owner | Notes |
|---|---|---|
| `<title>` tag | RankMath | per-page title rules in RM dashboard |
| `<meta description>` | RankMath | per-page |
| `<meta keywords>` (if on) | RankMath | optional |
| `<meta robots>` per-page | RankMath | noindex/nofollow toggles |
| Canonical | **Theme** | `inc/seo.php` (RM canonical disabled) |
| Open Graph + Twitter | **Theme** | `inc/seo.php` (RM OG disabled) |
| LocalBusiness JSON-LD | **Theme** | every page |
| BreadcrumbList JSON-LD | **Theme** | singulars + archives |
| Article JSON-LD | **Theme** | blog posts |
| FAQPage JSON-LD | **Theme** | only on `/faqs/` |
| Product JSON-LD | **Theme** | single-product (when WC active) |
| `/sitemap.xml` | **Theme** | `inc/sitemap.php` (WP core + RM sitemap off) |
| `/robots.txt` | **Theme** | `inc/robots.php` |
| `/llms.txt`, `/llms-full.txt` | **Theme** | `inc/llms.php` |
| 404 monitor | RankMath | RM module |
| Redirections | **Theme** + RankMath | theme map for legacy URLs; RM for ad-hoc |

The conflict guard runs at `init` priority 20: when RankMath is
active it filters off every overlap (`rank_math/json_ld`,
`rank_math/opengraph/*`, `rank_math/frontend/canonical`,
`rank_math/sitemap/enable`).

## 2. AI-bot allowlist

`/robots.txt` explicitly lists per-bot blocks:

```
User-agent: GPTBot
Allow: /
Disallow: /wp-admin/
Disallow: /account/
Disallow: /checkout/
Disallow: /cart/
Disallow: /login/
```

And does the same for: OAI-SearchBot, ChatGPT-User, Claude-Web,
ClaudeBot, anthropic-ai, PerplexityBot, Google-Extended,
Applebot-Extended, CCBot, Bingbot, YouBot.

Default `User-agent: *` blocks the same private paths but allows
everything else.

## 3. llms.txt convention

```
GET /llms.txt        → short summary (brand, hours, pricing, links)
GET /llms-full.txt   → full text of every public page + FAQs + recent posts
```

Both served as `text/plain` with `Cache-Control: public, max-age=900`.

Update path: edit `inc/llms.php` (short version) — the full version
auto-pulls from page content + FAQ data so it stays in sync.

## 4. Sitemap

```
/sitemap.xml                  → master index
/sitemap-pages.xml            → published pages (excludes account/cart/etc.)
/sitemap-posts.xml            → blog posts
/sitemap-products.xml         → WooCommerce products
/sitemap-product-cats.xml     → product categories
/sitemap-faqs.xml             → /faqs/
```

WP core's auto-sitemap is OFF (`wp_sitemaps_enabled` false).

## 5. Redirects + canonical host

- All `www.guns2ammo.com/*` → 301 → `https://guns2ammo.com/*`
- Legacy URLs from the May 2026 audit are 301'd in
  `guns2ammo/inc/redirects.php`. To add more: extend
  `g2a_redirect_map()` (literal paths) or
  `g2a_redirect_patterns()` (regex).

## 6. Local SEO

Single LocalBusiness JSON-LD emits with:

- types: `LocalBusiness`, `SportsActivityLocation`, `Store`
- address (Mesa, AZ), geo (lat/lng from Customizer)
- opening hours (Mon–Thu 10–18, Fri 10–19, Sat 10–19, Sun 12–18)
- `areaServed`: Mesa, Phoenix, Gilbert, Tempe, Chandler, Scottsdale,
  Apache Junction, Queen Creek, Maricopa County
- `aggregateRating` from `g2a_rating` + `g2a_review_count` mods
- `knowsAbout` list (indoor range, CCW, FFL transfers, training,
  machine-gun shooting, etc.)
- `hasOfferCatalog` with all three plans + URLs

## 7. Schema testing

After each release, run these in Google's Rich Results Test:

- Home: should detect LocalBusiness
- A blog post: should detect Article + BreadcrumbList
- `/faqs/`: should detect FAQPage
- Any product: should detect Product (if WC active)

## 8. Workflow when adding a new page

1. Add the page in WP admin.
2. Theme automatically picks it up in `/sitemap.xml` next request.
3. If it's a customer-facing page, add a row in
   `g2a_faqs_data()` or update the page's `post_content` so
   `/llms-full.txt` picks it up.
4. If it replaces a previously-public URL that's now dead, add a
   row to `g2a_redirect_map()`.

## 9. Workflow when changing business hours

Edit two places (and they MUST stay in sync until we move hours
to a Customizer field — see ROADMAP):

- `guns2ammo/inc/seo.php` — `openingHoursSpecification` block in
  the LocalBusiness JSON-LD.
- `guns2ammo/assets/js/chrome.js` — the `ranges` object in the
  live Open/Closed pill code.
- `guns2ammo/inc/faqs.php` — the "What are your hours?" answer.
- `guns2ammo/inc/llms.php` — the "Hours" line in `g2a_llms_short()`.

Yes, four places — refactor planned in ROADMAP.md item D.2.
