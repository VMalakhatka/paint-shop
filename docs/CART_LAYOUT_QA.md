# Cart Layout Regression

Checked locally: 2026-10-07. Production not deployed by this task.

## Evidence And Scope

The customer video shows a desktop browser: narrow price columns initially,
then very tall blank-looking rows and horizontal scrolling. The exact customer
cart/session was not accessed, so its complete failure is not yet reproduced.

Smush's `.lazyload, .lazyloading` rule overrides image width with an intrinsic
placeholder width using `!important` (`class-lazy-load-controller.php`). In the
local 20-row fixture with a 3000px placeholder, the old layout grows a row to
571px and its thumbnail to 545px. With the fix the row is 117px and the image
stays 60px. This proves a layout conflict, not every possible cause of the
customer's white-screen symptom.

## Owner And Behavior

`paint-shop-ux/inc/cart-layout.php` loads `assets/cart-layout.css` only on the
cart and uses GeneratePress's sidebar filter to give the cart full width.
The stylesheet fixes image geometry during and after lazy loading, bounds table
columns and wraps full product names. At 768px and below, each row becomes an
unframed grid with name/photo, removal and labelled price/quantity/subtotal.
No vendor code, Java, stock allocation, prices or customer data are changed.
No new plugin or activation is required. Rollback is the scoped Git revert.

## Verification

- `php wp-content/plugins/paint-shop-ux/tests/cart-layout.php`: scope/cache hooks.
- Open `wp-content/plugins/paint-shop-ux/tests/cart-layout-fixture.html` on the
  local site. Toggle the fix to compare large placeholders. Finish lazy loading
  to confirm stable geometry. This is synthetic test data, not a customer cart.
- Fixture: 20 rows, long unbroken SKU, oversized placeholders, desktop and
  320/390px widths. Fixed photos remain 60px before/after loading; no horizontal
  overflow at those mobile widths.
- Real local guest cart: two products, desktop 1366px and mobile 390px. Quantity
  1 to 2 updated subtotal correctly; removing the second item updated the total;
  reloading retained quantity 2 and one item. No checkout/order was submitted.
- The mobile screenshot in the wholesale guide uses this local guest fixture;
  it contains no customer account information. The warehouse-split example is
  retained separately because allocation behavior has not changed.

## Production Follow-Up

Deploy the existing `paint-shop-ux` plugin and the changed wholesale-help MU
files/assets/translations using the normal approved deployment. Check the actual
affected account in the customer's browser after reloading assets. If blank rows
persist, capture the affected row's computed sizes and browser errors; do not
clear the customer's cart or assume the account/order data is lost.

## Allocation Metadata Refresh (2026-10-11)

The classic-cart fast update in `pc-cart-guard.php` previously returned only
subtotal/totals; the existing `dl.variation` retained the old allocation quantity.
The response now includes `item_data_html`, rendered from the refreshed cart item
after quantity validation and totals calculation. Rendering uses Woo's standard
`wc_get_formatted_cart_item_data()` under a temporary `woocommerce_is_cart`
filter, removed in `finally`: the allocation hook otherwise skips admin-AJAX.
The browser replaces only the direct `dl.variation` under `.product-name`.
Product links, stock panels and quantity controls remain intact; variation
attributes are regenerated with the allocation row. Empty metadata removes the
old block, and a legacy response without this field leaves the block untouched.

Checks: `wp eval-file wp-content/plugins/paint-core/tests/retail-cart-locations.php
--skip-themes` on paint.local uses in-memory stock/cart fixtures and checks retail
quantity changes, split wholesale allocation, variation metadata and context
cleanup. `node scripts/test-cart-allocation-update.cjs` uses Playwright, the actual
inline JS and mocked AJAX at 390/1280px; checks 1->3->1, server-capped quantity,
no duplicate metadata and empty-block removal. Set `CHROME_PATH` to an installed
Chrome executable if Playwright's bundled browser is unavailable. No real orders,
payments or inventory movements are created. Checkout deductions were not tested.

Deploy the changed `pc-cart-guard.php` MU file and wholesale-help PHP/UK/RU
translations. No new plugin, Java change or DB migration. Production not modified
in this task. After deployment, test changing quantities in an authorized cart
without checkout. Rollback restores the prior MU file, which also restores the
stale-display defect. The customer guide and published-help source were updated;
production help publication awaits deployment.
