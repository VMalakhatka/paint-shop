# Lavka Storefront

Independent classic WordPress theme for the Lavka/KREUL storefront. It has no
parent theme and does not load GeneratePress PHP, scripts, or styles.

Status: active on `paint.local` and production as of 2026-10-22. The owner first
installed the 0.1.0 ZIP, then explicitly authorized production activation.
Installing files alone does not activate the theme.

## Ownership

- This theme owns the HTML document, WordPress lifecycle hooks, generic page
  fallbacks, the WooCommerce archive wrapper, menu locations, and widget areas.
- Paint Shop UX owns the storefront design, header, footer, catalogue and
  product presentation, ordinary public page templates, and customer flows.
- Store rules, access control, stock, prices, orders, exports, and integrations
  stay in their existing plugins. This theme does not duplicate those rules.

The shell retains `#page`, `#content`, `#primary`, `#main`, `.inside-article`, and
`.entry-content`, which existing presentation modules use. Header and footer
modules render through `wp_body_open` and `wp_footer`. When those modules are
disabled or unavailable, the theme provides an accessible text menu, widget
areas, footer, search, content, and comments. Plugin and page-builder template
selection remains under WordPress' normal template filters.

The `psu-public-pages` theme support opts into the plugin's public page templates.
The theme uses native WooCommerce templates and gallery support. It leaves
product wrappers to Paint Shop UX when its product module is enabled; otherwise
its own WooCommerce wrappers apply. Only catalogue archives and product searches
can display a sidebar. Product, checkout, cart, account, and ordinary pages use
the full content width.

## Existing settings

Read-only compatibility preserves existing site choices without migrating them:

- `primary` uses the valid saved menu from `theme_mods_generatepress-child` only
  while this theme has no `nav_menu_locations` key. An explicitly empty mapping
  in this theme is respected. Existing WordPress/plugin menu filters still run.
- `catalog_sidebar` can be `left-sidebar`, `right-sidebar`, or `no-sidebar`; its
  initial fallback is `generate_settings.blog_layout_setting`. A sidebar is
  rendered only when its widget area is active.
- The registered widget IDs remain `sidebar-1`, `sidebar-2`, `header`,
  `footer-1` through `footer-5`, `footer-bar`, and `top-bar`.
- Generic singular pages respect the saved `_generate-disable-headline` choice.

These compatibility reads do not require GeneratePress to be installed. The
theme provides the `lavka_storefront_before_main_content` action and the
`lavka_storefront_show_title` and `lavka_storefront_sidebar_layout` filters for
presentation adapters. Its CSS supplies scoped baseline and fallback styling;
Paint Shop UX retains ownership of store component styling.

## Verification and translations

The integration checks live in
`wp-content/plugins/paint-shop-ux/tests/lavka-theme-local.php` and
`lavka-theme-bootstrap-local.php`. The latter is used in a separate local PHP
process with a temporary option override, so the saved active theme remains
unchanged. Local acceptance: 104 integration checks plus 166 existing GeneratePress
public-page regression checks. Browser checks covered 320/390/768/1280 px,
catalogue and its sticky shared name on page two, product gallery/lightbox,
all four legal pages, cart/checkout with a memory-only guest cart, wholesale
account/quick order, mobile menu, 404, workshops, and fallback header/footer.
No horizontal overflow was observed in these scenarios. Generic table styling
keeps native table layout, and product-search fallback renders its sidebar.

The preview checks above left the saved active theme unchanged. On 2026-10-22,
real local `switch_theme` and the next request’s `after_switch_theme` completed:
59 activation checks and 103 integration checks passed without theme-option
overrides. Primary menu and all active widget assignments/order were retained;
widget data and Woo page assignments were unchanged. Core removed one inactive
legacy widget reference, preserving its widget data. Image sizes/hash stayed the
same and no image-regeneration job was queued.

Browser acceptance after activation covered the actual `paint.local` catalogue,
SKU search, page-two sticky context, product gallery/lightbox, ordinary page,
404 and login, plus memory-only cart/checkout and role previews on loopback.
Those previews used the saved active theme, without template/stylesheet overrides.
Widths 320/390/768/1280 px passed; mobile navigation and wholesale/retail menu
visibility were checked. No order, payment or external integration was submitted.

The existing deploy script still deliberately excludes this new theme and does
not activate themes. Future theme updates need separate delivery or an agreed
deploy-script change. Local backup/rollback: [Bootstrap and recovery](../../../docs/BOOTSTRAP_AND_RECOVERY.md#локальная-активация-lavka-storefront-2026-10-22).

### Production preflight, 2026-10-22

Read-only server checks confirmed all 20 uploaded theme files and 45 Paint Shop UX
runtime files match the locally verified copy. Paint Shop UX 1.9.0 is active;
WordPress 7.0 and PHP 8.3.8 meet the requirements. At preflight, the saved theme was
GeneratePress Child with GeneratePress as its parent. Primary menu, two sidebar
widgets and both rollback themes are present.

The Customizer iframe was blank for both themes. Authenticated native
`?wp_theme_preview=lavka-storefront` worked without activation; keep the parameter
on each checked URL. Browser checks covered the catalogue, oil-category image,
sticky Number One context, Clairefontaine brand and eight-photo product gallery,
mobile menu, empty cart, account and legal page at desktop, 390 and 768 px widths.
Production `after_switch_theme`, real orders and payments were not exercised in
this preflight.

A private local snapshot contains 86 option rows and `.htaccess`; it is not a
full database backup. At preflight, a fresh full DB backup was not confirmed in
the checked server locations; that gap was resolved before activation below.
Evidence, limitations
and rollback scope: [production preflight](../../../docs/BOOTSTRAP_AND_RECOVERY.md#production-preflight-lavka-storefront-2026-10-22).

### Production activation, 2026-10-22

With explicit owner approval, native `switch_theme` activated 0.1.0 at 21:16:21 UTC;
a fresh WordPress bootstrap completed `after_switch_theme` at 21:16:40 UTC.
All 10 read-only postchecks passed at 21:16:50 UTC: the switch marker cleared,
the primary menu was saved in this theme's own mods, widget assignments/hash and
Woo page/image options were unchanged. No pending or running image-regeneration
actions or Woo regeneration batches were present. The operation lock was released.

Before activation, database and WordPress-file backup checksums were verified.
These were sequential backups of a live site; no restore rehearsal was performed.
Both GeneratePress themes remain available for rollback.

Browser acceptance passed on normal production URLs without preview parameters:
admin theme/CSS/sidebar/account navigation, guest catalogue and images, the
Clairefontaine brand and eight-image gallery (open, next, close), mobile menu,
sticky compact-name context, oil-category description, empty cart, login/register
and legal page. Desktop 1280/1680 px and 390/768 px checks showed no horizontal
overflow, including the guest gallery. Viewport overrides were reset. No cart
items, orders, payments, imports, sync, manual regeneration or S3 writes were
submitted. Populated-cart checkout, payment, every business role and physical
phones remain outside this acceptance. Procedure and backup scope:
[production activation](../../../docs/BOOTSTRAP_AND_RECOVERY.md#production-activation-lavka-storefront-2026-10-22).

Source messages are English; bundled `languages/uk.po` and `ru_RU.po` catalogs
provide Ukrainian and Russian translations. Rebuild the matching `.mo` files
with `msgfmt --check` after changing translations.
