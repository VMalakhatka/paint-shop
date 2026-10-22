# Lavka Storefront

Independent classic WordPress theme for the Lavka/KREUL storefront. It has no
parent theme and does not load GeneratePress PHP, scripts, or styles.

Status: prepared and verified locally on 2026-10-21. Adding these files does not activate
the theme, alter the database, or deploy it to production. Theme activation is a
separate rollout step after the local checks and review.

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

The saved active theme and its options stayed unchanged. These checks do not
exercise WordPress `switch_theme`/`after_switch_theme` or widget remapping during
real activation. The existing deploy script deliberately excludes this new theme;
delivery, activation, and production acceptance belong to the separate next stage.
See the stage-two section of Paint Shop UX README for the test command and scope.

Source messages are English; bundled `languages/uk.po` and `ru_RU.po` catalogs
provide Ukrainian and Russian translations. Rebuild the matching `.mo` files
with `msgfmt --check` after changing translations.
