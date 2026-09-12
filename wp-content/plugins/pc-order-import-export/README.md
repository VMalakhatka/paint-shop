# Wholesale Price List

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

Local whole-catalogue check on 2026-09-12: 8,576 exported rows, about 7 seconds,
315 MiB PHP peak with a 512 MiB limit (7-12 seconds across runs). Production capacity/timeout must be checked
after deployment; local timing is not a production guarantee. Existing 180-second
PHP export allowance does not override a reverse-proxy timeout. Catalogue rows
are read live, not as an atomic inventory snapshot, and do not reserve stock.

Deploy this plugin, translations and the updated wholesale help together. No new
plugin activation, schema migration or Java deployment is needed. Smoke-test as
partner and opt: download, edit a few quantities, import to a draft, compare current
prices. Use only an approved test account for cart/order writes on production.
Rollback the release files together; existing drafts and orders are not deleted.
