# Lavka Price Sync

## Accounting-price manager documentation

Canonical manager guide: [FOLIO_ACCOUNTING_PRICES_MANAGER_UK.md](../../../docs/FOLIO_ACCOUNTING_PRICES_MANAGER_UK.md).
Read-only help lives at `admin.php?page=lps-accounting-prices&view=help` and uses
the same `manage_lavka_prices` gate as the accounting-price screen. Its renderer
returns before job/snapshot loading. Dedicated English msgids and UK/RU catalogs
use `lps-accounting-help`, keeping documentation separate from business strings.

`assets/accounting-price-help.js` maps stable selectors to section anchors, including
dynamically replaced diagnostics and exports. It does not click buttons, submit
forms or call APIs. Navigation groups keep each tab with its own help link.

Checks: `tests/accounting-price-help.php` (translations, anchors, canonical parity,
capability gate), `tests/accounting-price-help.cjs` (offline desktop/mobile and
dynamic replacement), existing diagnostic/export and campaign tests.

Deployment: existing plugin; no activation, configuration or data migration.
Publish `inc/accounting-prices.php`, new `inc/accounting-price-help.php`, two help
assets and two `lps-accounting-help` MO files together. Back up and compare current
files first. Rollback restores the old accounting-prices.php; help dependencies
are then unused. Never start preview/apply or enable schedules to verify help.
Production publication is tracked in the canonical guide.

## Product cards: Lavka Folio (2026-10-18)

The separate **Lavka Folio → Product cards** menu uses the existing
`manage_lavka_prices` capability and Java connection/token settings. Accounting
prices and profit remain in their existing menus. This feature reads Paint_Ua;
it never imports cards, executes Access macros, changes prices or creates jobs.
Enter up to 500 exact SKUs, one per line, or leave empty for every eligible card.
Select `1212 все поля`, `_dbo_Все поля по Artic`, or comparison. **Read and check**
shows the first 50 rows, total returned rows, equality and requested missing SKUs.
**Export product cards XLSX** reads the selection again and downloads all returned
rows. A later export can reflect intervening edits; it is not the saved preview.
Comparison exports require equality. On a mismatch, download each mode separately
for review. Neither a missing SKU nor an empty result proves the card is absent:
legacy warehouse and markup requirements may exclude it.

The first worksheet retains the first 54 column labels/order from the supplied
НК template, with frozen first row/three columns and filters. Product identifiers,
barcodes and formula-like strings remain literal text. SQL null stays blank;
numeric fields are Excel numbers (over 15 significant digits are preserved as text).
Template assembly columns 55–57 are outside both queries and are not manufactured.
The Report worksheet includes source, mode, generation time, row count, comparison
status and missing requested SKUs. Files are streamed through private system temp
files and deleted after download; no export is stored in public uploads. ZIP is
required. Over 50,000 rows or overlong Excel cell text fails without a partial file.

Deploy the matching Java endpoint first; missing/unavailable backend fails visibly.
Token is the existing `lavka.token`/LAVKA_TOKEN matching price-sync API settings.
No activation, migration or new secret is required in an already active plugin.
Shared read locks can briefly delay concurrent Folio edits. Source definitions,
response contract and restrictions: Java `docs/api/FOLIO_PRODUCT_CARDS_API.md`;
WordPress integration: [product-card contract](../../../docs/api/FOLIO_PRODUCT_CARDS.md).
Production deployment/verification is a separate operation. Rollback code only;
there are no Folio data changes to undo.

Regression: `php wp-content/plugins/lavka-price-sync/tests/folio-product-cards.php`;
Java `FolioProductCardDaoTest` covers parameter binding, source guard and multiset
comparison. Browser coverage uses synthetic responses; it does not establish live
equivalence of the current view and direct query.

Live read check (2026-10-18): direct SELECT returned 36,798 rows and 54 columns, but SQL Server denied the
card_tov view query (229/42000). Direct mode is the default. View/compare modes
show a safe permission message until the DBA grants SELECT on that view; this
change does not grant permissions. Live equality remains unverified.

### Product-card Excel input (2026-10-18)

The Folio screen accepts pasted SKUs or XLS/XLSX (5 MB, first sheet, selectable
column and first data row) through the existing shared PhpSpreadsheet autoloader.
Loading replaces the editable list; no Folio calls or media copies occur. Explicit
selection prevents an empty list from requesting all cards. XLSX retains all 57
template headings; the three unsupported assembly columns stay empty. Operator
steps and limits: `docs/api/FOLIO_PRODUCT_CARDS.md`. Tests: product-cards,
product-cards-import and product-cards-auth under tests/. No migration required.
