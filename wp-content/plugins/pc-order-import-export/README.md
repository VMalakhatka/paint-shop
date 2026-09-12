# Wholesale Price List

## Customer waiting list — stage 1

Prepared in the isolated worktree on 2026-09-12. Disabled by default. Production rollout is limited to one explicitly selected pilot customer.
No mail or Folio calls are part of rollout. Tests used a disposable MariaDB 11.4
and fresh WordPress/WooCommerce with synthetic records and blocked HTTP/mail.
Owner: `Waitlist.php` (account UI/actions), `WaitlistStore.php` (state),
`WaitlistModel.php` (quantity, grouping and reason rules). No new plugin is needed;
the existing plugin is already in the deploy manifest.

### Setup and storage

After an isolated runtime test and release approval, deploy this plugin and the
wholesale help/translations together. In WooCommerce → Waiting list, a manager
with `manage_woocommerce` selects an existing wholesale customer by email and explicitly enables the feature. The POST requires a
nonce and creates `<wp_prefix>pcoe_waitlist` using dbDelta, then registers the
account endpoint and flushes rewrite rules. No schema work occurs on a customer GET.
`pcoe_waitlist_schema=1`, `pcoe_waitlist_enabled=yes`, the wholesale role gate and
`pcoe_waitlist_pilot_user_id` gate all customer features, help, assets and direct POST.
Only the configured customer ID is allowed. A missing/deleted pilot denies everyone.
No email address or customer ID is embedded in code or versioned documentation.

One private row per Woo customer contains a bounded JSON state (200 sources), a
primary customer key and a monotonic revision. The small dedicated table provides
an atomic initial insert and compare-and-swap update, which a non-unique user-meta
key does not guarantee. It is not an analytics projection. No arbitrary customer
ID comes from the browser. Deleting a WordPress user removes their waiting-list row.
Backup this table with WordPress; automatic retention/export tooling is a later gate.

Explicit sources contain product ID, requested/current quantity, created timestamp,
reason, active state, snooze time, and optional draft/item IDs. Related pieces of a
partial transfer share an intent ID and sum; overlapping independent evidence uses
the largest quantity, not a sum. This conservative overlap rule is a pilot default.
Draft subscriptions use live owned draft remainders. Removed/closed draft lines
stop contributing, and the customer can remove those inactive sources.

`_pcoe_track_waitlist` and `_pcoe_track_consent_at` are written through Woo CRUD only
after explicit consent. A manager creating a customer's draft cannot infer consent.
`_pcoe_demand_event` order meta is an append-only pair per processing attempt:
`started` preserves source rows before cart replacement/item mutation;
`cart_prepared` records requested/planned/cart_added/remaining and reason. UUID links
the pair; algorithm version is 1 and timestamps are UTC. An unmatched started event
has unknown outcome. `cart_added` never means purchased or fulfilled. The reason
separates `stock_shortage`, `stock_unknown`, `not_purchasable`, `cart_rejected`,
`cart_adjusted`, `allocation_restricted`, and `none`; do not backfill old residuals as proven shortage.

### Current customer behavior

Shared wholesale role gate, current-user ownership, POST+nonce, server-side input
validation. Product entry is by exact SKU (variation SKU for variable products),
whole quantities 1–100000. Availability is shared across customers and uses the
existing selling-location mapping; unknown stock remains unknown. Account dashboard
and waiting list show current availability when opened, not a fabricated arrival
event. No background scan or email subscription/delivery is implemented.

Cart addition is additive, validates current stock, existing cart quantity, purchase
limits/pack step and the selected allocation mode. Current prices come from Woo.
Draft addition creates a pc-draft without Folio writes; an existing tracked draft
is reused by directing the customer to its source link instead of creating a copy.
Snooze is seven days with an explicit early-resume action; removal deletes this
request, allowing a future explicit request. Missing catalogue products can still
be removed. Updating an existing manual SKU replaces its requested quantity.

A cart/draft transfer claims the revision with a persistent pending receipt before
the Woo mutation. Parallel/stale forms are rejected. A crash leaves the receipt;
the customer must inspect cart/drafts and explicitly acknowledge it. No blind
retry, cron repair or replay is implemented. WordPress state and Woo mutations are
not a distributed transaction. Successful cart additions leave demand tracked;
cart quantity suppresses repeat addition while it remains in the current cart.

### Release limitations and verification

This is a first implementation for an isolated pilot, not the complete recommendation
MVP. Automatic settlement from Folio expense invoices, later Woo purchases, untracked
drafts and open/split orders remains outstanding. Customers must manually remove
fulfilled requests. This limitation is visible in the interface. Tracking notices
can reappear after checkout until settlement is implemented. Customer history,
popular-product suggestions, arrival news and price-change mail are planned in
[the project plan](../../../docs/CUSTOMER_RECOMMENDATIONS_PLAN.md).

Offline checks: `php tests/waitlist-offline.php` exercises grouping, quantities,
reason classification, role/guest gates, draft ownership/remainders, consent and
revision conflicts using a SQLite adapter. It does not prove MariaDB/dbDelta or
real Woo cart/order behavior. `tests/waitlist-isolated.php` additionally passed
on a fresh WordPress and disposable MariaDB: dbDelta, CAS, real cart/draft transfer,
partial quantity filters, rejected additions, durable source evidence, consent,
GET/nonce rejection and read-only rendering. Full-theme/import acceptance remains.
The actual rendered PHP was visually inspected at desktop and 390px mobile widths
in a standalone fixture; this is not a full production-theme/browser test.
Before activation, verify on an isolated WordPress
database: setup/disable, UK/RU, roles/nonces, opt-in imports, simultaneous forms,
partial add/rejection, timeout recovery, variable products, allocation modes,
cart-to-draft clearing, draft reuse, desktop/mobile and user deletion. External
HTTP/mail must be blocked in the test environment.

Rollback: disable the feature first, then restore plugin/help/translations together.
The table and order meta remain for recovery; do not drop them in uninstall/deploy.
For the selected pilot only, the processing workflow calls the normal add-to-cart validation filter and
uses the quantity actually present in the cart after quantity filters, distributing
it across duplicate product rows before changing draft remainders.
No Java deployment, price recalculation, real document creation or mail is required.
Canonical help and help code are updated; publication awaits deployment and runtime
verification. Activation and migration were exercised only in the disposable test database.

## Price list

Owner: `inc/PriceList.php`; authenticated AJAX action `pcoe_price_list`, nonce
`pcoe_price_list`. Uses the shared `pc_wholesale_customer_can_access()` allow-list.
No caller-selected user, role or contract. The download is private/no-store and
the temporary XLSX is deleted, including on shutdown. No Folio request is made.

Buttons appear beside import in My Account / Orders and both empty and full carts.
The customer workflow is in
[the wholesale guide](../../../docs/WHOLESALE_CUSTOMER_GUIDE_UK.md#повний-прайс-для-замовлення).

## Data Contract

- Whole published catalogue: simple products and variations with SKUs whose
  parent is published and visible in the catalogue. Zero-stock products remain;
  hidden/search-only products and variable parent rows are excluded.
- One price uses the current Woo customer price pipeline (`get_price()` and
  `wc_get_price_to_display()`), the same role mapping as the storefront. No Java
  contract change is required. Unknown prices are blank, not zero.
- Selling locations come from `lavka_get_locations_mapping_for_java()`, matched
  by Folio codes 1 and 5 (Kyiv and Odesa), not editable names or local term IDs.
  Their mapped groups are already reflected in `_stock_at_<term_id>`. Transit
  locations are not added separately. Both selling mappings must be present.
  Stock sums nonnegative values; a missing/non-numeric source leaves the total
  blank. Variation stock falls back only when it uses parent-managed stock.
- GTIN uses `psu_product_display_barcode()`; SKU/GTIN/text cells are explicit
  strings, preserving leading zeros and preventing spreadsheet formula injection.
- Keyset batches of 250 avoid front-end pagination and search limits; only the
  per-request object cache is flushed between batches, never persistent cache.

## Import Safety

The sheet follows the `product_cat` tree with coloured category headings and Excel
row outlines (summary above, maximum seven nested outline levels). Categories use
the same alphabetical ordering as storefront tiles. Each product is written once:
assigned Yoast primary category wins, otherwise the deepest assigned category,
with alphabetical category order as a stable tie-breaker. Unassigned products go
under Other products. Variations use their parent's categories. Within each group,
configured supplier priority wins, then product name and ID.

Header row/column keys stay unchanged. Category rows have no SKU or quantity, so
existing import skips them. Collapsed rows are still imported when filled. There
is no sheet-wide AutoFilter/sort, which would detach headings from their products.

`Helpers` recognizes `Order quantity` / `Замовити` / `Заказать` and the combined
stock header. It requires the explicit SKU and order columns. It does not fall
back to stock/price columns, ignores unchosen rows, rejects invalid quantities,
and matches SKUs before barcodes for this format. Formulas are not evaluated in
the downloaded price-list format. Do not rename/delete its headers.

Customer draft imports always use live account prices, even if price-list
headers were renamed. Custom file prices are accepted only for managers in the
generic import format, never in a recognized price list. Existing generic
CSV/XLSX matching retains GTIN-first semantics.

Cart import is additive and respects Woo availability/allocation; draft import
keeps requested quantities without reserving stock. Neither operation creates a
Folio document. The cart report stays visible until manual refresh. Pending
buttons block repeated clicks; mutation requests are never retried automatically.
After a network failure, inspect cart/orders before importing again.

## Verification And Deployment

Run `tests/price-list.php` via WP-CLI only on paint.local. It creates and deletes
isolated users/products/drafts, blocks external HTTP/mail, and checks role and
nonce gates, workbook roundtrip, barcode formatting, stock and draft repricing.

Local grouped-catalogue check on 2026-09-12: 8,576 unique products plus 1,752
category headings, about 10 seconds, 331 MiB PHP peak with a 512 MiB limit.
Production capacity/timeout must be checked
after deployment; local timing is not a production guarantee. Existing 180-second
PHP export allowance does not override a reverse-proxy timeout. Catalogue rows
are read live, not as an atomic inventory snapshot, and do not reserve stock.

Deploy this plugin, translations and the updated wholesale help together. No new
plugin activation, schema migration or Java deployment is needed. Smoke-test as
partner and opt: download, edit a few quantities, import to a draft, compare current
prices. Use only an approved test account for cart/order writes on production.
Rollback the release files together; existing drafts and orders are not deleted.
