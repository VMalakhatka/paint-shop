# Brief For New Chat: Folio Parent/Child Products To Woo Variable Products

## Goal

Research and design a safe migration from current WooCommerce simple products to WooCommerce variable products using the parent/child product structure that already exists in Folio.

This must be treated as a separate project/branch because it affects product identity, catalog URLs, search, price sync, stock sync, media sync, orders, and Folio document creation.

Recommended branch:

```bash
git checkout -b feature/folio-variable-products
```

Recommended environment:

- Use a separate local WordPress site / copied database.
- Do not experiment directly on production.
- Keep the current production-oriented fixes for payments, Folio orders, total sync, stock sync, and price sync separate from this work.

## Project Context

WordPress/WooCommerce project path:

```text
/Users/admin/Local Sites/paint/app/public
```

Java sync project path:

```text
/Users/admin/Documents/Toleran/Proect_Lavka/kreul_com_ua
```

Main relevant WP plugins and mu-plugins:

```text
wp-content/plugins/lavka-total-sync/
wp-content/plugins/lavka-price-sync/
wp-content/plugins/lavka-sync/
wp-content/mu-plugins/stock-sync-to-woo.php
wp-content/mu-plugins/pc-stock-tap.php
wp-content/mu-plugins/pc-folio-order-link.php
wp-content/mu-plugins/pc-folio-customer-map.php
wp-content/mu-plugins/psu-search-filters.php
```

Current Java total sync endpoint:

```http
POST /sync/run
```

Repair endpoint recently added:

```http
POST /admin/sync/products/force-refresh
```

Current product sync behavior:

- Java/Folio mostly treats each SKU as an independent Woo simple product.
- Full product data is synchronized by `lavka-total-sync` / Java.
- Prices are synchronized separately by `lavka-price-sync`.
- Stock is synchronized separately by Lavka stock plugins.
- Media/images have separate sync logic inside `lavka-total-sync`.
- Relevanssi search is used.

Recent important fix:

- Folio field `DEPARTAM/status = 1` means product should be visible on site.
- Java was updated so status `1` sets:
  - Woo `post_status = publish`
  - Woo catalog visibility = visible
  - removes `exclude-from-search`
  - removes `exclude-from-catalog`
- WP force refresh button was added to re-apply product payload even if `_ms_hash` already matches.
- Relevanssi is reindexed after force refresh.

## Current Woo Product Model

Today, most Folio SKUs are simple products:

```text
wp_posts.post_type = product
wp_posts.post_parent = 0
```

Example current catalog behavior:

- Each paint color is a separate Woo product card.
- Example group: same paint line, many colors, each with separate SKU like `KR-16296`, `KR-16215`, etc.

Desired future behavior:

- One visible Woo parent card for the paint line.
- Color/SKU choices inside that product as variations.
- Customer selects color and quantity.
- Cart/order/Folio JSON must still contain the exact child SKU.

## Woo Variable Product Model

Woo parent:

```text
post_type = product
product_type = variable
post_parent = 0
```

Woo variation child:

```text
post_type = product_variation
post_parent = parent_product_id
```

Each variation can have:

- its own `_sku`
- its own price
- its own stock
- its own image
- its own attributes

Important: variations are database posts, but they are not normal catalog product cards. They belong to the parent product.

## Existing Folio Feature

Folio already has a mechanism for:

- main product article / parent SKU
- child product articles / child SKUs

This is better than guessing groups from product names. The new sync should use Folio-provided product structure, not heuristics based only on `|` in names.

## Required Data From Folio / Java

For every product row, Java should expose enough data to distinguish:

```text
simple product
variable product parent
product variation child
```

Suggested fields:

```json
{
  "sku": "KR-16296",
  "wooProductType": "variation",
  "parentSku": "KR-16200",
  "parentName": "Фарба напівпрозора для скла та порцеляни Kreul 20мл",
  "variationAttribute": {
    "taxonomy": "pa_color",
    "label": "Колір",
    "value": "Бірюзовий",
    "sortOrder": 10
  },
  "defaultChildSku": "KR-16296",
  "showSeparately": false
}
```

For a parent product:

```json
{
  "sku": "KR-16200",
  "wooProductType": "variable",
  "parentSku": null,
  "parentName": "Фарба напівпрозора для скла та порцеляни Kreul 20мл",
  "defaultChildSku": "KR-16296"
}
```

For a normal product:

```json
{
  "sku": "ABC-001",
  "wooProductType": "simple",
  "parentSku": null
}
```

Minimum required fields:

- `wooProductType`: `simple`, `variable`, or `variation`
- `parentSku`: required for variations
- variation attribute name/label, for example `Колір`
- variation attribute value, for example `Бірюзовий`
- sort order for variations
- default child SKU for parent product
- image role or clear image assignment rule
- whether variation can be shown separately

## Parent Image / Main Variant Rules

Need explicit decision:

- Parent product image:
  - use parent image from Folio if available
  - otherwise use default child variation image
- Variation image:
  - use child SKU image
- Parent gallery:
  - optional shared images
  - possibly include variation images if useful

Need explicit default selection:

- `defaultChildSku` or `isDefaultVariation = true`
- Without this, parent product can open without price/image/stock selected.

## Migration Problem

The hard part is not creating a new variable product. The hard part is migrating existing simple products safely.

Possible transitions:

```text
simple -> variable parent
simple -> product_variation
variable -> simple
variation -> simple
variation moves to another parent
parent default child changes
```

Risks:

- Existing Woo order items reference old product IDs.
- Existing carts/favorites may reference old product IDs.
- Existing product URLs are indexed by search engines.
- Relevanssi indexes old products/SKUs.
- Price sync and stock sync currently expect SKU -> product/variation ID.
- Media sync may have links/images attached to old simple products.
- Folio order JSON must continue sending exact child SKU.
- Duplicate `_sku` values are dangerous.

## Key Rule To Research

For a SKU that becomes a variation:

- Should the old simple product be converted into `product_variation`?
- Or should a new variation be created and old simple product hidden?
- Should old simple product URL redirect to parent?
- How do we avoid duplicate `_sku`?

This must be designed before implementation.

## Compatibility Audit Required

Search all WP code for assumptions like:

```sql
post_type = 'product'
```

or:

```php
$product->is_type('simple')
```

or direct SQL looking only at product posts.

Any code that syncs by SKU must support:

```text
post_type IN ('product', 'product_variation')
```

Relevant areas:

- full product sync
- force refresh
- price sync
- stock sync
- media sync
- search/Relevanssi sync
- order allocation
- Folio order JSON builder
- account/order displays
- import from cart/list

## Expected Sync Rules

Simple product:

- update as current normal Woo product.

Variable parent:

- update common fields:
  - title
  - description
  - category
  - brand
  - parent image/gallery
  - visibility
  - status
  - variable attributes
  - default variation

Variation child:

- update child-specific fields:
  - SKU
  - price
  - stock
  - variation image
  - variation attributes
  - status/enabled state

Do not accidentally update parent price/stock with one child SKU unless Woo requires computed sync metadata.

## Search Requirements

Customer should be able to search by:

- parent product name
- child SKU
- child color/variant name

Expected behavior:

- Search for a variation SKU should find the parent product.
- Relevanssi may need custom indexing of variation SKUs into parent document.
- Variation itself should not necessarily appear as a standalone catalog result.

Current existing search hook:

```text
wp-content/mu-plugins/psu-search-filters.php
```

It already queues Relevanssi reindex on `_sku`, `_gtin`, `_ms_hash`.

## Price And Stock Sync Requirements

`lavka-price-sync` and stock sync must update variation SKU directly.

Important:

- `wc_get_product_id_by_sku()` can find variations.
- Direct SQL restricted to `post_type = 'product'` will not find variations.
- Need audit and tests.

## Media Sync Requirements

Variation images should be assigned to variation posts.

Parent product should have:

- main/default image
- optional shared gallery

Need decide:

- Should all child images appear in parent gallery?
- Should missing child images be logged?
- Existing media sync tables must be audited.

## Folio Order Requirements

Folio order JSON must continue to receive the exact child SKU selected by customer.

If Woo order item is a variation:

- order item should include variation ID
- product ID may be parent or child depending on Woo APIs
- SKU must resolve to variation SKU

Audit:

```text
wp-content/mu-plugins/pc-folio-order-link.php
```

The builder must not accidentally send parent SKU instead of selected variation SKU.

## Safe Implementation Plan

### Phase 1: Data discovery only

No writes.

Produce report from Java/Folio:

- parent SKU
- child SKUs
- parent names
- variation labels
- current Woo IDs for every SKU
- whether current Woo product is simple/product/variation
- conflicts

### Phase 2: WP compatibility audit

Find all code that assumes only `post_type = product`.

Classify:

- must support variations now
- safe to stay parent-only
- must be redesigned

### Phase 3: Dry-run migration report

For selected groups, report:

- create parent
- convert/hide old simple
- create variations
- redirect old URLs
- update search
- expected order behavior

No writes.

### Phase 4: One test group on separate local site

Use one product family from paint colors.

Verify:

- parent appears in catalog
- colors selectable
- variation image changes
- price/stock correct
- cart has exact child SKU
- order has exact child SKU
- Folio JSON sends exact child SKU
- Relevanssi finds parent by child SKU
- old simple URL redirect or clear behavior

### Phase 5: Extend price/stock/media sync

Make sure all existing sync jobs update variations safely.

### Phase 6: Production migration plan

Only after local/staging success:

- full backup
- maintenance window
- migrate small group
- monitor logs
- rollback plan

## Initial Questions For Java/Folio Chat

1. Which Folio fields currently store parent article and child articles?
2. Can Java expose `wooProductType` directly?
3. Can Java expose `parentSku` for every child SKU?
4. Can Java expose the default child SKU?
5. Can Java expose variation attribute label and value?
6. Can Java expose variation order?
7. Does Folio have a parent image, or only child images?
8. Can a child SKU move between parents?
9. Can a product switch back from variation to simple?
10. Are there grouped products that should still be shown separately?

## Initial SQL Checks

Find current product by SKU:

```sql
SELECT
    p.ID,
    p.post_type,
    p.post_parent,
    p.post_status,
    sku.meta_value AS sku
FROM wp_posts AS p
JOIN wp_postmeta AS sku
    ON sku.post_id = p.ID
   AND sku.meta_key = '_sku'
WHERE sku.meta_value = 'KR-16296';
```

Find duplicate SKUs:

```sql
SELECT
    sku.meta_value AS sku,
    COUNT(*) AS cnt,
    GROUP_CONCAT(CONCAT(p.ID, ':', p.post_type, ':', p.post_status) ORDER BY p.ID SEPARATOR ', ') AS posts
FROM wp_postmeta AS sku
JOIN wp_posts AS p
    ON p.ID = sku.post_id
WHERE sku.meta_key = '_sku'
  AND sku.meta_value <> ''
GROUP BY sku.meta_value
HAVING COUNT(*) > 1
ORDER BY cnt DESC, sku.meta_value;
```

Find code assumptions:

```bash
rg -n "post_type\\s*=\\s*'product'|post_type\\s+IN|product_variation|wc_get_product_id_by_sku|is_type\\('simple'\\)" wp-content/plugins wp-content/mu-plugins
```

## Definition Of Done For Research Stage

Research stage is complete only when we have:

- confirmed Folio fields for parent/child
- proposed Java API contract
- audited WP sync code for variation compatibility
- produced dry-run report shape
- selected one test group
- written migration rules for existing simple products
- written rollback strategy

