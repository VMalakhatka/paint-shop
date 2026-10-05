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
