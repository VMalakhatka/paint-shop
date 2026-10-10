# Wholesale Price List

## XLSX download compatibility (2026-10-09)

`Exporter::send_xlsx()` uses `setCellValue()` with A1 coordinates, compatible
with the installed PhpSpreadsheet 5.1.0. The removed
`setCellValueByColumnAndRow()` caused a fatal error on production order/cart
XLSX downloads. Header/row order, quantities, prices, allocation modes,
authorization and CSV fallback are unchanged. No dependency update is needed.

Run `php wp-content/plugins/pc-order-import-export/tests/exporter-xlsx.php`.
The test invokes the actual terminating download handler in child processes and
reads its XLSX output back: Unicode, leading-zero barcode, numeric values, empty
rows and columns beyond Z. It also tests CSV fallback without the XLSX library.
Only synthetic data and temporary files are used; no orders or database writes.

Deploy the existing `pc-order-import-export` plugin, then check an authorized
order download and cart download in both allocation modes. No Java changes,
activation or DB migration. Production deployment is left to the owner;
rollback is restoring the previous plugin files (which restores the known XLSX
compatibility bug). Customer-facing instructions/buttons are unchanged.

## Mobile export layout (2026-09-27)

The export warehouse selector in `Ui.php` uses a bounded, wrapping label; at
720 px and below the select occupies a separate full-width row. This is shared
by cart and order export. Options, saved mode and export payloads are unchanged.
No global overflow clipping, vendor changes, settings or migration are needed.

Local acceptance: `tests/mobile-export.spec.cjs`, 320/360/390/768/1280 px, both
warehouse modes and persisted selection after reload. It adds one product to a
fresh anonymous local cart and removes it in `finally`; no order/checkout/Folio
operation occurs. `PCOE_BEFORE=1` records baseline geometry without fixed-layout
assertions. At 390 px the baseline document width was 485 px; after the fix it is
390 px and the select fits the 330 px content area. No page JS errors.

Customer guide and published help include the new mobile export screenshot,
using existing translated captions. Deploy this plugin and the help assets;
production acceptance is still pending. Rollback restores the previous plugin
and help files, without any data operation.

## Conditional frontend assets (2026-09-27)

`Ui::enqueue_page_assets()` loads `pcoe-js`, translations and inline styles on
cart pages, authenticated account orders/view-order and order-received pages.
The cart import/export, account import and order export renderers also ensure
assets for custom placements; late styles print once and the script stays in the
footer. Home, catalog, product cards and account login/dashboard no longer load
the import bundle unless a renderer actually needs it. Manager workspace and
waiting-list assets retain their independent owners and hooks.

This changes asset delivery only, not customer actions, imports, prices or Folio.
No customer-guide/help/i18n change is required. Regression tests live in
`paint-shop-ux/tests/conditional-assets-*`; browser import requests are mocked,
so these tests do not claim an end-to-end import or financial acceptance.

## Background email — single-customer pilot

Enabled for the authorized production pilot on 2026-09-13 (commit `7e02c40`).
One explicit test email was accepted by the configured transport; the next scan
produced no duplicate. Existing customer demand was preserved. Inbox delivery
and an actual stock-arrival event still require acceptance. Owner: `WaitlistMail.php`; no new table or mail provider.
Uses the configured WordPress mail transport. Enable `pcoe_waitlist_mail_enabled=yes`
and the selected customer's `email_enabled=true` in their waiting-list state.
Customer consent is separate from draft tracking and can be withdrawn in the account.
The pilot owner explicitly authorized email for the existing test account; do not
backfill consent for any other customer. The email address always comes from that
account, never the browser or a versioned constant.

`pcoe_waitlist_mail_tick` runs on a 15-minute WP schedule. A targeted server cron
calls only that action as well, so absence of site traffic does not prevent checks.
Do not run all Woo cron jobs to drive this feature. Disable either the waiting list
or the master email switch to stop sending; user opt-out pauses that user's messages.

Read shared selling-location stock only after a `lavka_sync_last_to` cursor no older
than 24 hours and outside an active `lavka-sync` lock. Unknown stock never rearms an
arrival episode. First observed positive stock sends an availability notice (not a
claim of a new shipment), including requested and available quantities. Any positive
quantity qualifies, so partial availability is reported. Continued positive stock,
including increases, does not send again. Observed zero rearms the episode with a
24-hour minimum interval per product. Snoozed/removed/closed draft requests are
excluded. No cart or session is loaded by the scanner, and no order or stock is changed.

The existing per-user CAS row stores `mail_observed`, `mail_log`, `email_enabled`
and `email_consent_at`. A digest contains up to 20 products. Its durable `claimed`
record is saved before calling `wp_mail`. Concurrent scans cannot claim the same
revision. Recheck consent, pilot identity, active demand and stock readiness before
transport. Completion merges only journal status into fresh state, preserving
concurrent customer edits. A crash leaves `claimed` (unknown); false means `failed`;
true means `accepted`, not delivered. Failed/unknown attempts pause sending pending
operator review and are never automatically retried. Do not erase claims to retry.
Cancellation after claim is logged and does not send. This is not an atomic transaction
with the remote mail provider; do not claim exactly-once delivery.

Manager journal: WooCommerce → Waiting list. Shows time, status and SKUs; underlying
records contain quantities and a recipient hash, not message bodies or raw addresses.
`pcoe_waitlist_mail_health` records the last scan and reason for a pause. The bounded
pilot journal stops sending at 100 records; archival/review UI is a later expansion
requirement. `WaitlistMail::send_test()` is CLI-only, uses a fixed claim key, and sends
an explicitly labelled test email once. It does not assert any stock arrival.

Validation: offline episode transitions plus isolated real WordPress/MariaDB tests
in `tests/waitlist-mail-isolated.php` with intercepted mail: consent, background guest
context, partial stock, nested concurrent scan, deduplication, snooze, stale cursor,
removal, transport false/exception, durable unknown claim, test replay and schedule.
Production recipient delivery requires inbox verification; acceptance alone is insufficient.

## Customer waiting list — stage 1

Prepared in the isolated worktree on 2026-09-12. Disabled by default. Production rollout is limited to one explicitly selected pilot customer.
The initial rollout made no mail or Folio calls; background email is described above. Tests used a disposable MariaDB 11.4
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
event. Background email is available through the separate pilot switches described above.

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
The single-customer production pilot was enabled on 2026-09-12. Scoped code/help
release, schema setup and all-user access enumeration passed. Production runtime
checks covered add/snooze/resume/remove, read-only rendering, pilot help and denied
nonpilot/guest POST; the test list was left empty. No cart/order/stock changes or
mail/Folio operations were performed in production. Full authenticated browser
acceptance remains with the pilot customer. See the operations runbook.

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
  parent is published and visible in the catalogue, within visible categories.
  Zero-stock products in those categories remain;
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
assigned visible Yoast primary category wins, otherwise the deepest assigned
visible category, with alphabetical category order as a stable tie-breaker.
Variations use their parent's categories. Within each group,
configured supplier priority wins, then product name and ID.

Category visibility comes from `PSU_Category_Menu::index()` in `paint-shop-ux`:
excluded branches and descendants, and categories without catalogue-visible products,
are omitted. Hidden-only/unassigned products are not moved into Other products;
multi-category products may use a visible branch instead. Resolve the shared index
once per download; do not maintain a second exclusion setting or cache customer data.
Missing/unavailable visibility provider fails the download rather than exporting
an unrestricted catalogue. Main headers use dark burgundy `800000`, subgroups
use `D9B3B3`/`F2E6E6`; the order column stays yellow. No schema/import change.
Offline check: `tests/price-list-visibility.php` (set `PCOE_AUTOLOAD` to the shared
Composer autoloader when testing an isolated worktree). It exercises the actual
storefront category index, visibility changes, variations and XLSX save/reload.
Checked locally 2026-10-05; deployment needs no new plugin, activation or migration.

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


## Customer conversations — stage 1 (2026-09-27)

`Conversations` owns account/admin UI, nonce-protected text forms and polling;
`ConversationStore` owns private records, access checks, assignment and queue states.
It is an isolated module of this already-deployed plugin. Enable explicitly in
Customer workspace → Conversations → Chat settings (manage_options), default off.
No schema migration or Java changes. Requires InnoDB posts/postmeta and MySQL/MariaDB
advisory locks; source-of-truth operations stay with existing Woo/Folio owners.
See [operator setup, storage, checks and rollback](../../../docs/OPERATIONS_RUNBOOK.md#чат-клиента-и-очередь-обращений--этап-1-2026-09-27).

Text only; one conversation per customer/Woo order or independent general topics.
The first manager public reply claims an unassigned thread. Customer reply reopens
closed conversations; internal notes never reach customers. Sends use actor/request
idempotency, owner checks and a transaction; stale assignment revisions are rejected.
The browser polls only the latest message page while visible, without replacing the
composer. Email, uploads and standalone Folio binding are not implemented.
Telegram is a separately enabled adapter described below.
Local regression: `tests/conversations.php` (intercepted HTTP/mail, disposable records).


## Telegram adapter — stage 2 (2026-09-27)

`TelegramSettings`, `TelegramStore`, and `TelegramBridge` attach a private Telegram
bot to the existing canonical conversations. Default off, explicitly configure from
the manager Conversations tab as administrator. No new plugin or Java changes.
An isolated bot, public HTTPS webhook and running WordPress cron are required.
`pcoe_telegram_config` holds the encrypted token (AES-GCM with WP auth salt),
webhook secret, bot identity, enabled flag and owning site URL. No secrets in Git.
An explicit setup creates the InnoDB `{prefix}pcoe_telegram` table; deploy does not.

A one-use 15-minute deep link only proposes the binding; the customer confirms the
Telegram identity from the authenticated website account. Reply mappings include
bot, peer and binding generation; unknown replies never guess a Woo order.
Public manager replies enqueue atomically with the canonical message. Internal
notes never leave the website. Telegram acceptance is not a read/approval receipt.
Network uncertainty stays unknown and is not retried blindly. Token rotation is
supported for the same bot; changing bots requires a separate migration.

[Setup, privacy boundaries, queue, recovery and rollback](../../../docs/OPERATIONS_RUNBOOK.md#telegram-в-клиентской-переписке--этап-2-2026-09-27).
Local mock integration: `wp eval-file wp-content/plugins/pc-order-import-export/tests/telegram.php`.
All HTTP/mail are intercepted. A real bot round trip remains a release acceptance
step after deploy and operator configuration. See the customer `messages` help anchor.

### Manager Telegram (2026-09-27)

`TelegramManagers` adds personal manager bindings in the same Conversations UI and
bot. Staff need `manage_woocommerce`; only administrators change global bot config.
The stored audience and live capabilities are checked during binding, receipt and
delivery. Existing bindings without an audience remain customer-only.
Customer messages go to both assigned managers or, when waiting and unassigned,
all linked managers. Public manager replies also notify their colleague, with the
author's name, without echoing back to the author. Both can reply in their own name;
the primary manager remains responsible. `CustomerManagers` owns the primary and
optional additional manager defaults in customer user meta `_pcoe_chat_team`.
New threads inherit the pair; an explicit checkbox replaces both managers in all
open threads (maximum 200, atomic defaults/assignments/audit/outbox). Closed threads
keep their assignment. Individual thread overrides never change customer defaults.
The per-thread `_chat_secondary` defaults to zero for existing conversations.
Take request uses the canonical thread lock and assignment audit. Telegram
replies require a delivered message mapping and current assignment; no implicit
customer selection, private notes, or closed-thread replies. Reassignment enqueues
public context transactionally and invalidates previous recipients' pending cards.
`/threads` and `/queue` show ten recent relevant cards; each card rechecks its scope
before delivery. Disconnect/reconnect invalidates earlier reply and callback maps.
No DDL, new token, or Java change is required. Saving customer defaults requires
InnoDB posts/postmeta/usermeta. The existing client and single-manager channels are
confirmed on production by the owner; paired managers await deploy and live checks.
`tests/telegram.php` includes `tests/telegram-managers.php` and
`tests/customer-managers.php` (149 total checks, including removal, role loss,
colleague replies, optimistic revisions and bulk rollback).
The runbook above covers setup, operator acceptance and rollback.
# Manager contextual documentation

Wholesale onboarding (2026-10-02): `#register-wholesale` documents the existing
administrator-assisted workflow, separate role/Folio mapping, invitation after
verification and password-reset email. It does not grant new capabilities or
send email. Production Shop manager lacks create_users/edit_users/promote_users;
customer balance/documents currently require opt or partner, not every quick-order role.

Tab help is grouped in `.pcoe-help-tab` and the navigation uses scoped flex
layout: never insert ungrouped inline help among WordPress floated `.nav-tab`
elements. Regression tests include core `wp-admin/css/common.css` and verify
tab/help pairing and wrap at 320–1100 px (2026-09-29).

The manager guide lives at `admin.php?page=pcoe-customers&view=help` and requires
`manage_woocommerce`. Canonical Ukrainian instructions:
`docs/MANAGER_CUSTOMER_GUIDE_UK.md` in the project root. `ManagerHelp.php` renders
English gettext sources, with dedicated `pcoe-manager-help` UK/RU catalogs.
`assets/manager-help.js` maps stable selectors and `data-pcoe-help` to exact
anchors, preserves forms by opening a new tab, and handles AJAX without duplicate
links. Related approval, balance and debtors admin screens are explicitly gated.
No customer data, bot configuration or financial mutation is part of this help.
Update guide, translations, anchors and tests together. Tests:
`php wp-content/plugins/pc-order-import-export/tests/manager-help.php` and
`node scripts/test-manager-help.cjs` (PLAYWRIGHT_MODULE may select the runtime).


## Manager draft checkout and reserved orders — 2026-09-29

The primary draft action prepares customer Woo orders with accounting Folio
accounts and reservation. A website location is a group of Folio warehouses;
selected-only keeps every warehouse in that group and Java applies its priorities.
Whole-list non-accounting export is a separate collapsed action with its fixed
warehouse displayed. It does not inspect stock or create a reserved Woo order.
Previously the shared warehouse selector was silently ignored for this action.

`ManagerOrderFlow` supplies action-specific preview/confirmation labels and actual
linked Woo results. Apply requires the preview's mode, and an accounts preview
must contain a reserved document. Starting another preview clears the previous
confirmation, including on failure. Java still checks final stock during apply;
the preview does not guarantee or reserve quantities. Completed non-accounting
drafts offer a new working copy without resetting the original operation.
Only actual reserved child orders offer the customer-confirmation shortcut;
shortages are identified separately. No approval request is sent automatically.
No Java change, schema migration or production data correction is included.

Operator instructions: `docs/MANAGER_CUSTOMER_GUIDE_UK.md` (prepare/apply),
published by `ManagerHelp.php`; customer confirmation note in
`docs/WHOLESALE_CUSTOMER_GUIDE_UK.md` and `pc-wholesale-help.php`.
Validation: `tests/manager-workspace.php` includes `tests/manager-order-flow.php`
(53 integration assertions, all external HTTP/email mocked).

Browser regression with real synthetic form markup (network blocked):

```sh
PCOE_ORDER_FLOW_UI_FIXTURE=/tmp/pcoe-flow.json wp eval-file wp-content/plugins/pc-order-import-export/tests/manager-workspace.php --skip-themes
node wp-content/plugins/pc-order-import-export/tests/manager-order-flow-ui.cjs /tmp/pcoe-flow.json
```

`PLAYWRIGHT_MODULE` can select the bundled Playwright installation. The integration
harness requires `paint.local`, blocks HTTP/email and deletes its temporary data.
Screenshots are written beside the fixture; none is a production order.


## Customer confirmation link routing — 2026-09-29

The MU `pc-account-tweaks.php` root-account redirect exempts `pcoe_approval`
and `approval_page` while `CustomerApproval` is available. Approval ownership,
role checks and consent remain in the plugin. Existing approval URLs keep their
IDs; opening a link does not confirm it. Deploy the MU file together with help
translations; no Java change, data migration or rewrite flush is required.

Run `wp eval-file wp-content/plugins/pc-order-import-export/tests/customer-approval-http.php --skip-themes`
on `paint.local` for thirteen real HTTP checks, including customer isolation,
the ordinary root redirect and idempotent confirmation with mocked email. The
loopback PHP server permits reads and confirmation POSTs for its sole test fixture,
outbound HTTP/mail and cron disabled, creates temporary records and cleans up.
`PCOE_APPROVAL_HTTP_HTML=/tmp/approval.html` optionally exports the owner's HTML
for offline visual review. Operator details: `docs/OPERATIONS_RUNBOOK.md`,
customer instructions: `docs/WHOLESALE_CUSTOMER_GUIDE_UK.md` and published help.


## Approval contacts and notifications — 2026-09-29

`ApprovalContacts` reads only the signed-in owner's Woo profile/current/recent
order contacts, including saved Nova Poshta destinations. Native Woo form fields
support browser autocomplete; selection is editable and never runs checkout or
updates profile/order/shipment data. Only final preferences enter the approval.

`ApprovalNotifications` provides a manager-only nonce/revision-checked email
button addressed to the owning WP profile email. Mail event claims are durable;
same-request replay is idempotent and unknown transport outcomes block resending.
Customer confirmation emails the current manager pair plus the requesting manager
(current capability checked, deduplicated). Email acceptance is distinct from
delivery. A notification error cannot discard the saved consent.
Connected managers also get a Telegram job with scope `approval`; delivery checks
the approval revision, confirmed timestamp, current membership and binding. It
uses the existing queue and sends no chat reply on the customer's behalf.

Checks: `tests/approval-contacts-notifications.php`, `tests/approval-telegram.php`
(included by `tests/telegram.php`), `tests/customer-approval-http.php` and existing
approval tests. `tests/approval-contacts-ui.cjs` checks the exported HTTP owner HTML
offline (saved contact switching, manual entry, disabled methods, consent and
desktop/mobile layout). Run WP integration tests only on paint.local; HTTP/mail are mocked.
Canonical instructions: manager/customer guides and OPERATIONS_RUNBOOK.md.
Deploy the plugin, help MU file and translation catalogs; no Java or DDL change.


## Nova Poshta on customer confirmation — 2026-09-29

PCOE owns authorization, saved consent and ten-minute delivery estimate receipts.
PNPM reuses its recipient directory/point card, tariff service and delivery policy.
`DocumentShipmentBuilder` reads saved order allocation or uniquely mapped Folio
warehouses; it never allocates current stock. The customer sees carrier/store/customer
amounts, parcels and fallback-weight warnings. A receipt is invalidated by changes to
destination, document, parcel inputs, policy or owner; non-permitted COD is rejected.
Estimates remain separate from Woo totals and do not create shipments. Existing
packaging/volumetric-weight limitations remain; a manager checks final charges.
Deploy both plugins, MU help and translations together. No migration or new secret.
Operator/rollback: `docs/OPERATIONS_RUNBOOK.md`, customer confirmation section.
Local tests: PCOE `tests/approval-delivery.php`, `tests/approval-delivery-ui.cjs`,
existing confirmation HTTP and PNPM point-directory/card regressions. HTTP/mail
are intercepted; production verification remains pending deployment.
# Customer profile permissions (2026-10-02)

`CustomerPermissions` extends the existing manager workspace through runtime
capability filters; no persistent role migration. Single-site shop managers with
`manage_woocommerce` can edit/promote customer-only accounts and use core profile,
password-reset and Folio mapping controls. Customer roles are restricted to
customer/opt/partner/opt_osn/schule and read-only role capabilities. Mixed staff
roles and individually elevated customers fail closed. Self-promotion, account
deletion/removal and admin account creation are not granted. Core role allow-list,
nonce and target checks remain responsible for writes; no direct user-meta API.
Registration remains the My Account flow. Existing manager order/document actions
retain their own authorization and confirmation requirements.

Test: `php wp-content/plugins/pc-order-import-export/tests/customer-permissions.php`.
Operator guide, verification and deployment/rollback scope:
`docs/MANAGER_CUSTOMER_GUIDE_UK.md#register-wholesale`. Published 2026-10-02 from
88abdda with owner approval; six files byte-verified and runtime permissions/help
checked read-only. No customer writes or email-delivery tests were performed.

## Excel attachments for customer confirmation — 2026-10-04

On a fresh pending approval, managers can choose **Email Excel file for
confirmation** beside the existing link-only button. Both use the existing
nonce, ownership/revision checks and durable mail-event journal. One request key
cannot send twice, even if the posted format changes. The recipient is the owning
WP profile email; Reply-To is the sending manager. The email retains the account
confirmation link and explains that an email reply does not confirm the order.

`ApprovalWorkbook` exports the saved approval snapshot using the site's existing
PhpSpreadsheet dependency. The first sheet contains the order/document, with each
linked Folio document on a separate sheet. Stored line amounts and document totals
are preserved, with no repricing or double addition of linked document totals.
Text is explicit string data (including formula-like product names and leading
zero SKUs); quantities/prices/amounts are numeric. Known linked Folio numbers and
dates come from the existing saved Woo document metadata. Missing dates are not
inferred. This is a review copy, not a new payment invoice.

The private system-temporary attachment is named for the approval and revision,
passed synchronously to wp_mail, and deleted on success/failure/exception; shutdown
cleanup covers interrupted execution. Generation failure sends nothing. Missing
PhpSpreadsheet leaves link-only sending available. Unknown mail outcome still
blocks resending until investigated. Rollback: restore these plugin/help files;
old requests and mail journal entries remain readable. No schema/Java changes.

Local check: `wp eval-file wp-content/plugins/pc-order-import-export/tests/approval-excel.php --skip-themes`
with HTTP and email intercepted; optional `PCOE_APPROVAL_EXCEL_PREVIEW` points to
an existing private directory for synthetic workbook/panel HTML previews.
Regression: `tests/approval-contacts-notifications.php`, `tests/customer-approval.php`
and `tests/manager-help.php`. Canonical manager/customer guides and their on-site
UK/RU help were updated; deployment and production verification remain separate.

## Manager email mailings (2026-10-05)

`BroadcastUi` / `broadcast-view.php` add the Mailings workspace tab for
`manage_woocommerce`. The explicit preview/start flow supports plain text, a
full site price list, or a mini price list limited to an accounted Folio receipt.
`BroadcastSources` uses the server-side Java receipt catalogue proxy; no purchase
prices, supplier identities or financial totals are read for the attachment.
`PriceList::from_rows()` shares the existing taxonomy tree, safe cell types,
customer display prices and Kyiv/Odesa stock layout with the self-service export.

`BroadcastPricing` temporarily scopes the current user/customer/locale and clears
the manager session from price calculation, restoring everything in `finally`.
Group keys include ordered roles, locale, currency, tax settings/address/VAT.
Reviewed role-price/WPC/core filters share a file; unknown price/tax filters add
customer ID to the key. Never weaken this fallback merely to reduce file counts.
Before delivery the recipient email, eligibility, opt-out and pricing group are
checked again. Product prices/stock are the prepared snapshot, not recalculated
per recipient. Additional price providers require review before sharing files.

`BroadcastStore` owns private `pcoe-mailing` posts, meta and a MariaDB named lock.
`Broadcasts` owns bounded WP-Cron batches, a global five-attempts/minute quota,
durable sending claims, an explicitly reviewed start and failed-only retries.
Request UUID prevents repeated preparation. Recovery is scheduled before work;
a fatal sending interruption becomes unknown, an interrupted workbook becomes
preparation error. No automatic retry of uncertain sends. Pause/cancel only affect
remaining recipients. START/RESUME/RETRY require preparation age <=24 hours.
CPT is non-public/non-REST; files are checksum-verified non-autoloaded private options (one per campaign/group), downloads
require capability and nonce, email copies live outside webroot and are cleaned.
Limits: 2000 recipients, 2000 receipt SKUs, 8 MB attachment, first 100 directory
rows on screen. One byte-identical XLSX is reused per group. No automatic retention
purge yet; deleting a campaign also deletes its file options. Files are stored
separately so reading campaign state never loads every XLSX into memory. Customer opt-out is editable through Woo Account details; order emails
and Telegram are unaffected. Each message uses one recipient and creator Reply-To.

Operator steps, prerequisites, status semantics, deployment and rollback:
[operations runbook](../../../docs/OPERATIONS_RUNBOOK.md#email-рассылки-менеджера-2026-10-05).
[Manager guide](../../../docs/MANAGER_CUSTOMER_GUIDE_UK.md#mailings) and published
UK/RU help are updated. Java contract lives in its repository at
`docs/api/FOLIO_RECEIPT_CATALOGUE_API.md`; deploy Java as well for arrival selection.
Production checks and real email delivery remain pending after deployment.

Local verification (mail/HTTP/cron intercepted; disposable fixtures cleaned):

```sh
wp eval-file wp-content/plugins/pc-order-import-export/tests/broadcasts.php --skip-themes
wp eval-file wp-content/plugins/pc-order-import-export/tests/price-list.php --skip-themes
php wp-content/plugins/pc-order-import-export/tests/manager-help.php
```

For UI checks, set `PCOE_BROADCAST_PREVIEW` to a private temporary directory when
running the mailing test, then run `tests/broadcast-ui.spec.cjs` with that variable
and Playwright available. It uses rendered fixtures and mocked receipt responses,
never a production login or a send action. These checks do not establish SMTP
inbox delivery or live Folio query latency.

Full local catalogue preparation was also measured read-only with a disposable
wholesale account: 8421 product rows, 789222-byte XLSX, 22.8 seconds, 370.7 MiB
peak PHP memory (2026-10-05). Plan at least 512 MiB available to this PHP task;
actual production size/limits may differ. Cache reads are batched at 250 products.

## Customer directory: Folio organization type (2026-10-06)

The Customers tab filters by saved `_folio_partner_type` independently of site
price roles. Art salons use Latin `H`; the filter also requires a non-empty saved
`_folio_partner_short_name`. The list remains limited to WordPress customer
accounts. No live Folio request, role change, price update or mapping write occurs.
`customer_folio_type` persists in pagination and clears with Reset filters.
Unknown filter values fall back to all types. Existing directory callers,
including mailing recipient selection, keep their previous behavior.

Operator steps and published help: [manager guide](../../../docs/MANAGER_CUSTOMER_GUIDE_UK.md#customers),
`ManagerHelp.php` customers section and UK/RU catalogs. Requires WordPress plugin
deployment; no Java update or database migration. Rollback restores the plugin
files and catalogs; customer data is unchanged. Local verification is covered by
`tests/customer-directory.php` with disposable users and blocked mail/HTTP.

## Manager import of Folio customers (2026-10-06)

Owner: `FolioCustomerImport` / `FolioCustomerImportUi`; Customers → Import customers
from Folio. Shop managers with customer promotion permissions and administrators
can select up to 25 П/Д/К/H organizations per batch. Ordinary customers and other
staff without these permissions cannot import or access job contacts.

Java must first expose authenticated `GET /admin/folio/partners/registration`.
Set Java property `folio.customer-import.token` (environment
`FOLIO_CUSTOMER_IMPORT_TOKEN`) to a random secret of at least 32 characters matching
WordPress Lavka price sync's existing API token. Configure values only through
protected runtime settings; never include them in Git, browser JS or URLs. Missing
configuration disables contact reads. Keep transport private or HTTPS. This task
adds no credentials and performs no production deployment/import/email sending.

The source key is the exact `_PARTNER.N_USER`. Email, phones, billing address and postcode come from confirmed source columns.
By owner request (2026-10-06), both countries default to UA, both cities to
`TOWNB_USER`; shipping address/postcode initially copy billing. All are editable.
`CP_2` is displayed above the role selector as a contact/price reference, not an
automatic price-contract mapping. `PRIMECH` and `INFORM_PAR` seed an editable
multiline internal note (max 20000 characters). `CustomerInternalNotes` owns private
user meta `_pcoe_customer_internal_note`, visible in the manager customer card and
editable through the customer user profile with edit-user permission and nonce.
It is not public biography, REST-registered metadata or email content. Existing
prepared imports retain saved defaults; create a fresh import for new fields.
First/last name and the agreed Woo role are reviewed explicitly. `SKIDKAPRCNT` is informational, not a role-price override.
Addresses are plain text, not Nova Poshta branch IDs. See the Java partners API
contract for exact field mapping and evidence. Existing customer profiles are
never overwritten: matching email OR either Folio key is skipped. Duplicate
emails inside a batch skip both rows. Unknown/elevated roles and missing role
contracts are rejected; source and price mapping are rechecked before creation.

Preview lasts 30 minutes and changes no customer. Private owner-scoped CPT
`pcoe-client-import`, meta `_pcoe_client_import`, records staged contacts and row
outcomes; admin may inspect any job. Reports expire via a single scheduled cleanup
after seven days (actual cleanup requires WP cron); accounts are retained.
`_pcoe_import_job` links created users to the audit batch. No schema migration.
Preview/apply state changes use the shared ecosystem lock. Apply processes one
customer per AJAX request. Browser retries are never automatic after errors;
reload reveals durable progress. `creating` and `sending` markers become review
outcomes, never blind repeat commands. Closing the page pauses remaining rows.
Accounts start as retail, receive verified Folio/address metadata, then the chosen
safe role. Partial results require profile review; rollback of code does not
remove created accounts. Do not delete customers with orders as a rollback.

Invitation checkbox defaults off. When selected, `retrieve_password` runs only
for a newly created, verified profile, after a durable sending marker. Stored
passwords/tokens are never exposed. Mail failure or uncertain send requires manual
mail-log/profile review, without automatic repeat. Operator guide and published
help: `docs/MANAGER_CUSTOMER_GUIDE_UK.md#customer-import` / `ManagerHelp`.
Verification: local `tests/folio-customer-import.php` mocks Folio, blocks external
HTTP/mail and removes fixtures; Java registration tests never connect to Folio.

Manager import instructions reorganized 2026-10-06: `ManagerHelp` now has seven
dedicated `#customer-import*` sections: overview, selection, fields/prices,
preview, creation/invitations, results and recovery. Import controls link directly
to the corresponding explanation; manual registration retains `#register-wholesale`.
Canonical text: `docs/MANAGER_CUSTOMER_GUIDE_UK.md#customer-import`; UK/RU
help catalogs remain synchronized. Published 2026-10-06 from `f1d6170`; production
browser navigation and read-only manager UK/RU rendering passed. No import API
changes, customer creation or emails. Deployment/backup record is in the canonical guide.

Imported account logins (2026-10-06): sanitized lowercase email local part,
truncated to 55 characters, with a numeric suffix for occupied/blocked names.
WordPress username validation and blocked-login filters apply. Existing accounts
are not renamed. The chosen login is persisted with the creating marker and
used for the optional password-setting email; interrupted creation remains review-only.

Registration directory checks (2026-10-06): `FolioCustomerDirectory` calls the
authenticated read-only POST `/admin/folio/partners/registration-emails` once for
the 25-row page. Two batched WordPress reads compare email and both Folio meta
keys separately. Conflicting account matches are shown separately, duplicate meta
links are deduplicated, profile links respect edit-user permissions. Missing or
failed backend checks stay unknown, not unregistered. These are read-only hints;
preview and apply still enforce existing duplicate checks. Deploy Java first;
older Java leaves email hints unavailable while Folio links still work.

### Commercial offers in mailings (2026-10-10)

`CommercialOffer` renders the optional flat XLSX; `PriceList::catalogue_rows` still
owns product visibility, customer prices and selling-warehouse stock. A mailing
persists `format=price|offer`. Legacy `quantity=one|stock` remains accepted but never pre-fills new offers. Old campaigns default to
standard price lists. Both catalogue and document selections support either format.
No category headings/outlines are emitted for offers; SKU/barcode/text stay strings,
prices and quantities are numeric. Header row 1 retains the existing import contract.
Short description falls back to full/parent description, with HTML/shortcodes removed.
Unknown stock stays blank; zero stays zero. Quantities never come from Folio documents.

Photos use the assigned Woo thumbnail (variation falls back to parent), with safe
HTTP redirects, 3-second request timeout, 512 KiB input and 4-million-pixel limits.
JPEG/PNG/GIF/WebP are resized to at most 160×100 and embedded. GD absence, failures,
25-second image budget or 3 MiB total compressed image budget leave a product link.
The review reports embedded photo count. No media writes, public price files, additional automatic email are introduced; the existing 8 MiB final
attachment limit and explicit review/start remain. Pricing groups reuse the same
private file; the email attachment is named `commercial-offer-YYYY-MM-DD.xlsx`.

Manager steps: `docs/MANAGER_CUSTOMER_GUIDE_UK.md#mailings`. Customer quantities must
be reviewed before re-import; customer guide and published help source explain this.
Verification: local `tests/broadcasts.php` with intercepted mail/HTTP and disposable
fixtures; browser composer checks and XLSX inspection. Deployment: Java packaging endpoint, then WordPress.

Offer refinement (2026-10-10): headers use burgundy `800000`. Retail price uses the raw Woo regular price (synced retail), with display tax handling; customer price retains its role context. Unit uses `_edin_izmer`, then `pa_edin_izmer`, with parent fallback. Pack size means units per package: protected Java `/admin/folio/product-packaging` reads `SCL_ARTC.EDN_V_UPAK` at catalogue source warehouse 7 in batches of 500. Zero/unknown stays blank, never inferred from volume, dimensions or stock. API failure blocks preparation with an actionable message. Reuses the configured import token. Deploy Java before WordPress; no catalogue sync or migration needed. Stock is column K; yellow order column F is always blank. Existing prepared attachments remain snapshots; prepare a new mailing for the new layout.
