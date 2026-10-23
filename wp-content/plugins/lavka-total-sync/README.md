# lavka-total-sync

## Background full sync (0.2.0, 2026-10-23)

Manual Java run, force refresh and scheduled full sync now enqueue a durable job
instead of waiting for the whole catalogue. `inc/full-sync-jobs.php` stores the
coordinator in `lts_full_sync_job`; single cron ticks reconcile the same Java ID
with 10-second control requests. The Run and Settings pages poll local progress.
Browser closure does not cancel the task. Current status, confirmed counters,
cursor and technical diagnostics are shown; logs use action `full_sync_job`.

Requires Java jobs protocol 1 / Flyway V19, existing API token, a Woo REST key user
with `manage_woocommerce`, and the updated MU ecosystem lock. The lease callback
is authenticated `POST wc/v3/lavka-full-sync/lease`; it only reads ownership.
Protected leases do not expire and cannot be released before a saved, validated
terminal Java response. HTTP loss never creates a new ID. A checkpoint gap or
uncertain write holds the lease for manual reconciliation, not blind retry.
Ordinary terminal errors leave future daily/weekly runs enabled. Successful full
product REST updates refresh Relevanssi per product, not one large final request.

Deploy plugin, JS/CSS, UK/RU PO/MO and MU guard together, **before Java**, after old
synchronous work is confirmed finished. Rollback only after a confirmed terminal
job and lock release; retain Java history tables. No wholesale/storefront UI or
help changes. Offline test: `php tests/full-sync-jobs.php` from this directory.
Operator steps and rollout: [operations runbook](../../../docs/OPERATIONS_RUNBOOK.md#полная-синхронизация-карточек).
Java contract: `docs/api/FOLIO_FULL_SYNC_JOBS.md` in the backend repository.
UI test: `node tests/full-sync-jobs-ui.cjs` with Playwright available; optionally
set `WP_TEST_ROOT` to a local WordPress core checkout and `PLAYWRIGHT_CHANNEL` to
an installed browser channel. It renders the actual PHP panel and translations
with mocked AJAX at 320/390/1440px; no live synchronization is triggered.

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
