# lavka-total-sync

## Full-card supplier contract (0.1.2, 2026-10-22)

`inc/supplier-sync.php` extends the existing Woo product REST schema with the
write field `lavka_supplier`. It resolves supplier names to `product_brand`,
sets `brand_ids` through Woo CRUD before save and verifies the persisted relation.
Explicit empty clears the relation; absent preserves legacy requests. Both REST
and the PHP fallback use the same helper. Failures cannot acknowledge a new hash.
The existing display/order option is never written, so new terms remain opt-in.
No new endpoint, capability, activation or schema migration is introduced.
Deploy this plugin before Java's new complete-card hash/supplier implementation.
Operator steps, rollback and local tests:
[full sync release](../../../docs/api/FOLIO_FULL_SYNC_SUPPLIER_RELEASE.md).
No catalogue UI or wholesale help interaction changes.

## Contextual image help (2026-09-15)

`inc/operator-help.php` renders a responsive four-step operator guide with native
keyboard-accessible details and links to the relevant image workflow screens.
English source messages have Ukrainian and Russian PO/MO translations. The human
source of truth is [the media manager guide](../../../docs/MEDIA_MANAGER_GUIDE_UK.md).
Help is inside the existing capability-checked admin page; it does not add endpoints
or trigger uploads or synchronization. Deploy the changed templates and language
catalogs together; no activation or database migration is required for an active
plugin. Rollback restores those files together. Live admin rendering must be checked
after deployment.
