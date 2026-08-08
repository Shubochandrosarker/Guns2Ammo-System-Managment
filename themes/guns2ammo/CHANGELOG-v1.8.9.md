# Guns 2 Ammo theme — v1.8.9 audit (corrections on top of v1.8.8)

## Issues fixed in v1.8.9

After the v1.8.8 build was deployed, four issues remained visible in the
production screenshots:

### 1. "CART" / "MY ACCOUNT" page-title duplicated at the top of the page

**Symptom.** The cart, checkout and my-account pages all rendered the
hardcoded H1 from `page.php` (`<h1 class="hl-display">CART</h1>`,
`MY ACCOUNT`, `CHECKOUT`) at the top of the screen, then immediately
below it the custom WC templates rendered their own hero (`YOUR CART`,
`MY ACCOUNT`, `COMPLETE YOUR ORDER`). Two headings, lots of empty space.

**Root cause.** `page.php` hardcodes that H1 for every page. The v1.8.8
CSS rule tried to hide it via `body.woocommerce-cart .section[data-no-hero] > .container > article > header h1.hl-display`,
but there is **no `<article>` element** in `page.php` — the actual
structure is `section > .container > header`, so the selector never
matched.

**Fix in v1.8.9.** Rewrote `page.php` to skip the `<header>` /
`<h1>` block entirely when `is_cart() || is_checkout() ||
is_account_page() || is_shop()`. Also drops the 140px top padding
(WC hero handles its own padding) and lets the container expand to
full-width (it was capped at 1280px before, which clipped the
sticky checkout columns at the edges).

### 2. Cart product-thumbnail column rendered as a giant empty box

**Symptom.** The red-boxed empty space between the × remove button and
the small ammo image in the cart screenshot. Made it look like product
names were missing.

**Root cause.** No explicit width on `td.product-thumbnail`, so the
72px image floated inside whatever default WC width allocated (often
~200px+), with `padding: 18px 14px` adding more space.

**Fix in v1.8.9.**
- `td.product-thumbnail` → `width: 90px; padding: 14px 8px`
- `td.product-remove` → `width: 44px`
- `td.product-name` → `min-width: 220px` so it absorbs the rest
- `td.product-price / quantity / subtotal` → `width: 1%; white-space: nowrap`
  so they shrink to content and the table balances correctly.
- Mobile breakpoint resets the thumbnail width so the 720px collapse
  layout still works.

### 3. My Account → Account Details: password change inputs were bright white

**Symptom.** "Current password", "New password", "Confirm new password"
rendered as bright white boxes — totally out of place against the dark
theme. Eye-icon (show password) was visible.

**Root cause.** WooCommerce wraps each password field in
`<span class="password-input"><input type="password" /><span class="show-password-input"></span></span>`.
The v1.8.8 selector `.woocommerce-account input[type="password"]` matched
the `<input>` but the surrounding `<span class="password-input">` had a
default white background that bled through the borders of the input
(also Chrome's autofill memory was painting them yellow/white).

**Fix in v1.8.9.**
- Forced dark surface + steel border on `.password-input input` at every
  level of specificity (`.woocommerce-account .password-input input`,
  `.g2a-account-shell fieldset input[type="password"]`,
  `.woocommerce-EditAccountForm fieldset input[type="password"]`).
- Defeated Chrome's `:-webkit-autofill` yellow with `box-shadow inset`
  and `-webkit-text-fill-color`.
- Styled the show-password eye toggle (`.show-password-input::before`)
  with brass open-eye / closed-eye SVG icons.
- Made the wrapper `position: relative` so the eye icon positions
  correctly over the input padding.

### 4. Single product → Related Products STILL rendered as 4 tall narrow strips

**Symptom.** "RELATED PRODUCTS" section showed each product card as a
narrow vertical column with the title broken character-by-character
(`A`/`G`/`U`/`I`/`L`/`A`).

**Root cause.** v1.8.7 / v1.8.8 selectors all targeted
`.single-product div.product .related` (related as a CHILD of div.product).
In production WooCommerce ships `<section class="related products">` as
either a **sibling** of `div.product` or wrapped inside a different
ancestor, depending on the active hooks. None of the previous selectors
matched.

**Fix in v1.8.9.**
- Added a comprehensive selector list covering every variant:
  `.single-product .related`, `.single-product section.related`,
  `.single-product section.up-sells`, `body.single-product .related`,
  `.g2a-single .related`, `.g2a-single + .related.products`,
  `.woocommerce-page .related.products`, etc.
- Forced `display: block !important; grid-column: 1 / -1 !important;
  width: 100% !important; max-width: 100% !important` on the section,
  and `display: grid !important; grid-template-columns: repeat(4, …)`
  on the inner `ul.products`.
- Killed WC's legacy `float: left; width: 23%` on each `li.product`
  with `float: none !important; width: auto !important;
  max-width: none !important`. Also added `[style]` attribute selectors
  so inline `style="width:..."` injected by WC is overridden.

## Files changed in v1.8.9

```
page.php                    skip H1 on WC pages, full-width container
assets/css/tokens.css       appended ~310-line v1.8.9 correction block
style.css                   1.8.8 → 1.8.9
functions.php               G2A_VERSION 1.8.8 → 1.8.9
CHANGELOG-v1.8.9.md         this file
```

`inc/woocommerce.php`, the custom WC templates, and all other PHP files
are unchanged from v1.8.8.

## Deploy

```
1. Upload guns2ammo-v1.8.9.zip via Appearance → Themes → Add New →
   Upload Theme.
2. Click "Replace current with uploaded".
3. Hard-refresh /cart/, /checkout/, /my-account/, /my-account/edit-account/,
   and a product page. Clear any caching plugin / CDN cache too.
```
