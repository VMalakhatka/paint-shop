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
