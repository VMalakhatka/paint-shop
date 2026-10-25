# Paint Nova Poshta Multishipping

## Classification handoff fix 0.10.4 — 2026-10-25

In 0.10.3 the partner picker showed a new selection while the link form retained
its server-rendered empty partner. This made a deliberate save prerequisite look
like a missing-field or document-loading failure. 0.10.4 mirrors the selected code,
shows the unsaved state and provides **Save classification and continue** beside
the document picker. Both save buttons share the existing revision-checked classify
command; selection and Load documents never implicitly write classification.

Successful classification returns the accepted metadata/revision and updates all
forms for that record without reloading or clearing warehouse/date/type filters.
Changes invalidate document choices/previews, including late preview responses.
Documents still read the persisted partner server-side. Save failure is displayed
beside the document list and leaves loading blocked; no mutation retries. The
readonly partner display no longer has misleading required-field validation.
81 isolated registry checks passed; browser checks reproduced the initially empty
partner, successful save/load without losing dates and a rejected save. No production
write or deploy. Deploy 0.10.4 PHP and JS together; no schema or Java change.
Manager steps: [registry guide](../../../docs/MANAGER_CUSTOMER_GUIDE_UK.md#np-registry).

## Folio selectors 0.10.3 — 2026-10-25

The classification form now searches partners by code/name using the same
`/admin/folio/partners` directory as customer editing (two characters, 20 results,
all types including suppliers). Selection shows short code plus full name; typing
without selecting cannot accidentally save the previous code. Save classification
before linking a document. The linking form is visible above carrier refresh.

`Registry/Catalog.php` reuses the existing `lps_java_get` gateway and routes:
`/ref/warehouses` from the mailing workflow; `/admin/folio/customer-documents`
for ACCOUNT/EXPENSE; `/admin/folio/receipt-catalogue` for RECEIPT. No Java change
or migration. Admin capability pair and registry nonce protect all commands;
lookups skip even registry cleanup and never write Folio, NP, links or profiles.
Document reads use the persisted shipment partner and current revision, not a
browser-supplied partner. Customer API pages (50 rows) are filtered by warehouse
after pagination; a page with no matching warehouse is not end-of-list. Receipts
use one page/day per explicit click across the selected range (up to 366 days).
Their API lacks supplier identity: retain explicit supplier evidence, never
present the warehouse list as supplier-filtered. Returns remain manual.

Picking a document fills the existing preview fields; it does not link anything.
Exact header verification, explicit preview/confirm, revision checks and account
before expense remain unchanged. Manual entry remains available. Stale search
responses are ignored; changed filters/selection invalidate the preview. Source
failure has an explicit retry for reads; mutations are never retried automatically.
UK/RU UI and [manager instructions](../../../docs/MANAGER_CUSTOMER_GUIDE_UK.md#np-registry)
are updated. Local registry checks: 80 with external HTTP mocked. Requires the
normal scoped WordPress deployment; no production writes were performed.

## Registry list details 0.10.2 — 2026-10-25

The list now shows recipient/company and contact, recipient city, shipment/document
date, delivery cost, declared value, delivery payer and the saved Folio partner code.
Known direction/purpose appear beneath the TTN. Amounts are in UAH; missing fields
use a dash, while an explicit zero remains zero. Payer is not proof of payment;
the document date is not proof of physical handover. CSV fallback is labelled unverified.

`Registry/Summary.php` projects the latest saved observation per source. Newer NP
observations take precedence; missing fields fall back to the other NP source,
then CSV. UUIDs are not shown as city/person names; CSV destination text is not
guessed to be a city. Folio code is the manager's saved classification, never an
automatic name/phone match. The list performs four bounded read queries, no carrier
requests or writes. Existing imports work immediately without reimport or migration.
The table scrolls horizontally on narrow screens; UK/RU translations and the
[manager guide](../../../docs/MANAGER_CUSTOMER_GUIDE_UK.md#np-registry) are updated.
Validation: 13 standalone summary checks and 65 isolated registry checks with all
external requests mocked. 0.10.2 requires the normal production deployment.

## Monthly history fix 0.10.1 — 2026-10-25

Production read-only diagnosis confirmed that 0.10.0, schema 1 and an API key are
present. A single 01.06–25.10.2026 request failed with the provider's exact error:
`The difference between DateTimeFrom and DateTimeTo cannot be more than three months.`
The original advice to send that range in one request was incorrect. First-page
reads for June and 1–25 October succeeded (1 and 8 rows respectively); no import
confirmation, TTN, notification or deployment was performed by this diagnosis.

0.10.1 keeps the overall period (up to 366 days) but sends only one calendar-month
window at a time. Pages stay in that window until an explicitly saved empty page;
the next action then advances to the next month at page 1. The first/last months
are clipped to the selected dates, with no gaps or overlap. Only an empty final
window finishes the period. Preview and coverage show overall dates, actual window
and next cursor; confirmation replay cannot skip a month. A 0.10.0 continuation
without the new month cursor requires restarting the period, preserving saved facts.
The carrier boundary refuses requests over 31 inclusive days before HTTP.
Known period/key/busy/rate-limit/network failures have safe actionable messages;
arbitrary provider error text is not exposed. No schema migration is needed.

Until 0.10.1 is deployed, select June, July and subsequent months separately.
After deployment, select the full period and continue reading/saving pages across
months until the next-page button disappears. 0.10.1 was subsequently observed on
production on 25.10.2026; the 0.10.2 list enhancement above awaits deployment.
All 59 original local registry checks passed, covering
partial months, 31-day months, boundary replay, last-window completion, stale
legacy cursors and the observed provider error. Full incoming coverage remains unproven.

## Shipment registry 0.10.0 — 2026-10-25

Stage 1 is implemented locally: WooCommerce → **Nova Poshta registry**, outgoing,
incoming, customer returns, supplier purchases/returns and internal transfers.
Registry TTNs do not require Woo orders. 0.10.0 was subsequently observed on
production on 25.10.2026, followed by the monthly fix 0.10.1.
The supported history start is **2026-06-01**.
Manager steps: [registry guide](../../../docs/MANAGER_CUSTOMER_GUIDE_UK.md#np-registry).

### Ownership, installation and access

`src/Registry/{Schema,Record,Store,Carrier,Folio,Service}.php` owns the read model;
`Admin/RegistryPage.php` and `assets/registry.{js,css}` own the private manager UI.
Both `manage_woocommerce` and `manage_pnpm_shipments` are mandatory for the page
and every AJAX command; nonce is required. No public REST route or customer view.
English msgids have UK/RU PO/MO translations, including inline expandable help.

After an independently authorized deployment and private database backup, an
administrator with `manage_options` initializes the five InnoDB tables using the
page button. Merely loading updated code does **not** migrate production:
`{prefix}pnpm_registry`, `_observations`, `_links`, `_audit`, `_batches`.
Option `pnpm_registry_schema=1` records the installed schema. The existing
`pnpm_shipments` lifecycle and Woo/Folio documents remain unchanged. No new API
secret is needed: reads reuse the server-only NP key and the existing Java proxy.

### Import, data and coverage

- `InternetDocument.getDocumentList`: explicit overall range, maximum 366 days,
  split into calendar-month requests in 0.10.1 (0.10.0 requires manual splitting),
  `GetFullList=0`, sequential `Page`, up to 100 records. Each page is read,
  previewed and explicitly saved. Only then is **Read next page** available.
  Continue through a confirmed empty page of every month, not merely a short page. Adjacent-page
  duplicates stop the import rather than silently claiming completion.
- The period filter is NP's shipment date, with document creation as fallback
  according to the official portal re-read on 25.10.2026. It is not a filter for
  every tracking event. The API does not promise retention back to June.
- A known incoming/outgoing TTN can be read with `TrackingDocument.getStatusDocuments`;
  an optional 380-format sender/recipient phone is used only in that request.
  Wrong/missing TTN, missing status, HTTP/business failure do not change saved facts.
- CSV uses the site's explicit UTF-8 semicolon template, up to 100 rows / 256 KiB:
  `ttn;date;sender;recipient;origin;destination;payer;delivery_cost`.
  It is **not** advertised as a universal NP export parser. Blank cost is null,
  supplied cost is unverified. Old inaccessible TTNs can have a manual reference
  with manager evidence; this is not carrier verification.
- Full safe row responses are retained as source-labelled observations, including
  available payer, addresses, weights, fees, COD and status fields. Keys, tokens,
  passwords, URLs and URL-bearing values are excluded. Unchanged responses are
  deduplicated per source; changes, including a return to an older state, remain.
  Carrier dates remain raw source fields; observed/recorded times are UTC.
  Listing latest 100 observations and 30 import confirmations does not delete older
  database evidence. Unknown values never become zero or proof of payment.
- Current observations are collected on explicit reads, **not** scheduled polling.
  No all-incoming discovery endpoint or full historical event feed was verified.
  Account pages, known-TTN tracking, CSV and manual references are distinct scopes.
  An empty page does not certify complete incoming coverage. Match June onward
  against the business cabinet/export after deployment; record missing periods
  and account roles before calling the backfill complete.

### Confirmed Folio associations

Classification and links are manager-controlled, never inferred from a name,
phone or tracking status. A sale links an ACCOUNT first; a subsequent EXPENSE
must reference its active account link. Both remain. Incoming purchases use
RECEIPT; returns have explicit RETURN references and an optional original TTN.
Multiple document links per TTN are supported; this is not a cost allocation.

API verification reads the existing `/admin/folio/customer-documents/{type}/{id}`
through `pc_folio_documents_java_get`: exact type, internal ID, printed number,
date, partner and non-return state must match for ACCOUNT/EXPENSE. Only header
evidence is retained. It does not prove an account-to-expense business relationship;
the manager confirms that relationship in the preview. RECEIPT reads the existing
`/admin/folio/receipt-catalogue` by exact date/warehouse/ID. It verifies the accounted,
non-return receipt header; this API does not return the supplier, so manager
evidence is still required (`api_header_manual_party`). No new Java deployment or
MSSQL write is part of this change; unavailable endpoints fail visibly.

Unsupported return documents and offline records use explicitly selected `manual`
verification, printed number/date/partner/warehouse, optional internal ID and a
reason of at least 10 characters. This label must never be promoted to API verified.
Identity uses Paint_Ua + document type + internal ID, or the full manual composite.
Links record actor/time/evidence. Revoking retains audit; revoke related expenses
before their account. Existing active links block a partner/direction change.

### Concurrency, safety, release and rollback

Previews live in private DB staging for 10 minutes, bound to actor and (for NP)
an opaque server-side key fingerprint. Expired staged payloads are erased on the
next registry command; final observations and audit remain. Preview/edit/confirm
are separate. Edits invalidate browser preview; record revisions reject stale
classifications and Folio links. Confirmation, observations and its durable result
commit atomically under row locks and `lavka_ecosystem_lock`. Retrying the same
confirmation returns that result. A storage/audit error rolls back all writes.
Unknown network outcomes are never auto-retried by the UI: reload and inspect;
deliberate confirmation of the same token is idempotent.

Reopening the page loses the browser's continuation token: review saved page
evidence and restart the period; existing TTNs/unchanged observations deduplicate.
Carrier observations never change confirmed links, Woo orders, stock, lifecycle
claims, receipts or customer addresses, and never send email/Telegram or create TTNs.
Fees are not yet included in margin; purchases need a later verified allocation
and exclusion of shipping already included in Folio cost. Automatic linkage on
TTN creation, reusable checkout addresses and scheduled updates are next stages.

Rollback restores the previous plugin files; retain registry tables for recovery,
do not drop/restore over newer manager evidence. Private snapshots contain personal
data and belong in normal protected database backups, not uploads or Git.

Local acceptance: 48 synthetic integration checks with a fake API key, all carrier
and Java HTTP mocked, mail blocked, disposable table prefix removed in `finally`:

```sh
php /usr/local/bin/wp --require=wp-content/plugins/paint-nova-poshta-multishipping/tests/registry-bootstrap.php eval-file wp-content/plugins/paint-nova-poshta-multishipping/tests/registry-local.php
```

Coverage includes repeat confirmations, source deduplication, changed responses,
incoming/returns, account + expense retention, receipt supplier limitation, stale
revisions, actor/account/expiry guards, malformed dates/CSV, carrier failures,
wrong TTNs/partners, rollback on observation/audit failure and private rendering.
This is local acceptance, not proof of real historical API completeness.

## Recipient directory verification 0.9.3 — 2026-10-18

Official `CounterpartyGeneral.save` examples omit contact Phones. 0.9.1/0.9.2
incorrectly required it in that response; their mocks invented it. 0.9.3 persists
minimal recipient/contact Refs before a separate `Counterparty.getCounterpartyContactPersons`
read. Only the exact returned contact Ref can match. First/last names and phone,
plus supplied patronymic, must match; this is not a global phone-owner lookup.
Read up to five documented pages (100 contacts/page); missing/ambiguous contact,
page limit, provider failure or mismatched fields fail closed before document save.
Audit/response store safe issue codes, not received personal data or raw errors.
The manager panel translates the reason; legacy records disclose missing diagnostics.

### Administrator recovery, never a create retry

`ShipmentLifecycle::recoverRecipient(orderId, shipmentId, refs, rowHash, apply=false)`
is CLI-only and requires `manage_options` plus both shipment capabilities. There is
no public/AJAX recovery endpoint. Scope is ONLY `np_recipient_mismatch`, no TTN/Ref,
and a recipient-only claim/audit without any document-phase event or payload refs.
Unknown/creating/rejected outcomes cannot use it. Persisted contact Refs cannot be
replaced. For legacy missing refs, an operator must supply the uniquely reconciled
existing pair from the same account; never call save to discover them.

1. Obtain separate owner authorization; back up the exact row and events privately,
   outside web-root. Verify ecosystem/order locks, current actor capabilities and
   row hash. Read-only reconcile the intended contact against the saved person.
2. Run with `apply=false`: unchanged order fingerprint, current sender/points and
   fresh exact-contact identity must pass. No carrier writes or local state change.
3. With the same hash, run `apply=true`; checks run again under the order lock.
   One atomic transition/audit sets `np_recipient_ready` and stores the verified pair.
   Never reset to `manager_draft`, delete audit, overwrite preparation or change Woo.
4. Reload the manager screen. Names are frozen. Fresh preview and explicit consent
   are still required; the later create command reads the contact again and issues
   only the document save, never a second recipient save. It records a durable
   document-phase event before HTTP. Unknown document outcomes remain non-retryable.

Rollback code using the scoped deployment archive; do not restore shipment rows
after any later carrier action. Older code will lock `np_recipient_ready`, which is
safer than a draft reset. A database rollback needs its own current-state review.
Fixtures use a save response WITHOUT Phones and cover missing/mismatched directory
data, paging, reference persistence, read failures, admin/stale/audit gates, legacy
reconciliation, frozen UI and document-only continuation. All fixture HTTP/mail is
intercepted. Real TTN/PDF/email acceptance is not implied by these tests.
Local acceptance: 195 lifecycle checks, 40 preparation checks, 22 pacing checks,
30 point-directory checks and 41 checkout checks passed. Recovered-state UI uses
read-only names and does not show the draft-only instruction to enter another person.

Production, 2026-10-18: owner-approved plugin-only deploy (code `f50f3ee`, UI
clarification `617f02c`) from a clean server checkout, after scoped dry-run and a
verified private plugin archive. Fresh runtime reports 0.9.3. One exact intended
contact matched by name and phone in the account directory. After a private row/
audit backup, recovery preview passed six read-only requests without changing the
row. Separate authorized apply repeated the checks, recorded one audit event and
set `np_recipient_ready`; settings and sender mappings stayed unchanged. Carrier
writes and mail were blocked in both operator commands. No real TTN was created.
Comparison with the private backup confirmed only status, response snapshot and
updated timestamp changed, with exactly one audit event added. Browser reload
showed the recovered notice and frozen names; live preview succeeded and enabled
the unchecked consent control. Desktop and synthetic 390px layout were inspected;
the synthetic checkbox enabled creation. Production consent/create was not clicked.

## Request pacing 0.9.2 — 2026-10-18

Confirmed production failure: HTTP 200 / success=false / errorCodes 20000401501,
`To many requests` during the second exact point lookup. A valid preview and unchanged
row can still fail this fresh pre-create read. This is not a contact mismatch.
`ApiRequestThrottle` serializes JSON transport per site/API-key with a MySQL named
lock (2-second acquisition limit) and a shared 10-second transient reservation.
Requests start at least 1.1 seconds apart; rate limits impose a 2-second cooldown.
These are conservative application choices, not a documented carrier quota.
An explicit read-method allowlist permits one retry ONLY for this rate error or
HTTP 429. Other errors and unknown methods are single-attempt. All mutations,
including recipient save and document save, remain single-attempt; PDF transport
is unchanged. No new automatic notification or durable create retry exists.

Fresh sender/point validation is retained; the limiter does not reuse stale limits
or weaken the row/plan fingerprint. Busy/rate errors before the claim explicitly
say creation was not started. After a claim, existing unknown/locked recovery
rules remain unchanged. Failure to lock or persist the reservation prevents HTTP.
Only a hash of the site/key and a timestamp are stored, never request/recipient data.
Other installations using the same carrier key are outside this site's limiter.

Tests: standalone `tests/api-rate-limit.php` (HTTP 429 + business code, retry bound,
no save retry, spacing/shared timestamp, lock/persistence failure and release),
plus local intercepted lifecycle tests for pre-claim rejection and rate recovery.
Operator instructions: [manager guide](../../../docs/MANAGER_CUSTOMER_GUIDE_UK.md#np-preparation).
Rollback: restore the prior plugin files from the deployment backup; do not reset
shipment rows, change mappings or enable/disable write gates as part of rollback.

Production acceptance, 2026-10-18: plugin-only deployment of code commit `6f8b6a0`
after a verified private archive outside web-root. Runtime reports 0.9.2. A guarded
read-only preview followed immediately by the same fresh compilation used before
creation completed 11 carrier reads, with minimum measured start spacing 1.099 s
(measurement/rounding tolerance) and zero rate errors. Preview row hash, plan hash
and strict payload comparison all matched. Shipment row, event count, settings
and sender mappings remained unchanged. The browser preview also succeeded and
enabled the unchecked confirmation box; the new inline help was visible. No
recipient/document save, real TTN, PDF printing or email was executed in this check.
This verifies the pre-create path, not end-to-end carrier creation acceptance.

## Manager lifecycle 0.9.1 — production gates enabled 2026-10-18

Production runtime checked on 2026-10-18: active 0.9.1, API key configured, both
write gates enabled by explicit owner approval. Fresh PHP process confirmed
`writesEnabled() === true`; unrelated option values stayed unchanged. Configuration
and options were privately backed up outside web-root. This was a configuration
operation, not a code deploy, real TTN, PDF or email pilot. Local gates remain off.
Reload the order and run a fresh preflight before confirming creation. Operator
instructions and narrow disable/rollback: manager guide `#np-preparation`.

The existing-order PCOE workspace supports saved preparation → carrier preflight →
explicit per-warehouse TTN creation → private PDF (document, A4 or Zebra marking) →
explicit service email to the **order billing email**, not the dropship recipient.
No automatic email on save/create, no stock/payment/profile updates and no automatic
Woo status transitions. Created means prepared, not physically handed over.

Scope: private-person recipient with confirmed Ukrainian first/last names and
optional patronymic, branch drop-off to branch/locker, Cash carrier payment, no COD.
Courier addresses, origin lockers, NonCash and COD fail closed.
`RecipientIdentity` suggests structured order shipping/billing names, then customer
card names, only when the saved recipient name and normalized phone match. Never
split a free-text full name, overwrite a dropship recipient, or update a profile.
The saved recipient phone is visible beside the editable suggested names.
Before document save, separately read contact FirstName/LastName/Phones and any supplied
MiddleName must match. Missing/different fields lock as `np_recipient_mismatch`:
the recipient may exist, but no TTN is requested. No retry/reset UI; 0.9.3 adds the
admin-only reconciliation above. This compares
the specific response, not ownership in a global phone registry.
One TTN per saved physical warehouse; multiple boxes at branches, one at lockers.
Preview refreshes sender ownership/contact/phone and exact point directories, checks
known bounds plus stricter locker limits, computes declared goods value after
discounts with proportional item tax (ceil UAH, minimum 1), and requests a boxed
carrier estimate. Missing bounds are unknown, never unlimited; the manager must
verify them, payer and subsidy. The estimate is not the final carrier invoice.

`ShipmentLifecycle` uses the same PCOE named order lock as preparation. A 5-minute,
manager-bound preview token binds the row and whole-order fingerprint. Both write
gates remain required: `PNPM_ALLOW_REAL_TTN === true` and settings writes_enabled=yes.
Code defaults do not enable them; the production enablement is recorded above.
A committed `np_creating` claim and audit precede
CounterpartyGeneral.save and InternetDocumentGeneral.save. Exact final payload is
stored without the API key before the second write. Validate success plus Ref/TTN;
warnings with a valid success do not trigger another save. `np_created` repeats
return the existing TTN. Unknown transport/result/storage failure and rejected
documents stay locked (`np_creating`, `np_unknown`, `np_rejected`): no automatic
retry or status reset. An administrator must reconcile the carrier account manually;
there is no recovery UI yet. The first attempt freezes preparation, including after
failure; subsequent warehouses require the unchanged plan.

PDF uses a POST nonce, both manager capabilities and exact order/owned shipment,
fixed carrier host/path, no redirects, timeout, 10 MiB cap, PDF MIME/signature and
private no-store response. External customer TTNs never use this print transport.
Secret-bearing carrier URLs never appear in HTML, JSON, emails or our logs.
Email persists a claim before transport under the order lock; all accepted/failed/
unknown outcomes prevent automatic resend. Accepted is **not delivered**. Service
mail is independent of advertising opt-outs. Telegram, new tracking polling,
customer shipment dashboard, cancellation and automatic reconciliation remain open.

Tests: `tests/shipment-lifecycle-local.php`, run using its narrowly scoped
`--require=.../tests/shipment-lifecycle-bootstrap.php` CLI bootstrap. Only that local
fixture process has fake credentials/write flags; every HTTP/mail is intercepted,
synthetic rows cleaned in finally. Preparation and existing checkout tests remain
separate. See the final verification below and the [contract review](../../../docs/api/NOVA_POSHTA_SHIPMENT_CONTRACT.md).
Help canonical/operator recovery: [manager guide](../../../docs/MANAGER_CUSTOMER_GUIDE_UK.md#np-preparation).
Code, UK/RU UI/help and skill invariants are updated together. No migration/options,
customer notice or deployment was performed. One separately authorized live
recipient-only save on 2026-10-18 accepted empty MiddleName and returned both Refs;
no real TTN was created. Roll back code
only; never remove shipment/audit records to permit another carrier save.

Verified locally on 2026-10-18: 64 lifecycle fixture checks, 40 preparation checks,
30 point-directory checks, 41 delivery checks and TTN normalizer passed. All NP
PHP files and changed JavaScript pass syntax checks; scoped manager-help UK/RU
canonical parity and documentation-impact checks pass. Standalone skill validation
could not run for 0.9.1 because the local Python runtimes lack PyYAML; reference
links were checked by the documentation validator. Browser fixture inspection
(desktop and 390 px iframe) confirmed translated autofilled fields and optional
patronymic; lifecycle checks cover separate confirmation before
creation, invalidation after name edits, three print formats and per-warehouse
email state. This is mocked/local verification, **not carrier or production acceptance**.

## Historical preparation-only stage 0.8.2 (superseded by 0.9.0 above)

The existing-order PCOE workspace now has an order-only recipient/packaging panel,
explicit fresh confirmation import, save and preview. This is **not the complete
create/print/track/notify release**. `Create TTN` is disabled, regardless of write
flags; there is no new carrier mutation route, scheduler or notification hook.

`ManagerPreparation` persists `source=store_api,status=manager_draft` in the existing
shipment tables with the stable order/location idempotency key. The request snapshot
is a typed `manager-preparation-v1` draft, **not an InternetDocument payload**.
All warehouse rows, item allocations and private audit events commit in one InnoDB
transaction, under the same per-order named lock as PCOE. Failure rolls back; stale
revisions reject repeat writes. No profile/address/stock/payment changes. Existing
external or non-draft shipments block preparation, including mixed-source orders.
Child orders must use the original; missing allocations and refunds fail closed.
The manager's profile never determines the customer's COD policy. Multi-origin COD
remains blocked; no duplicated full-order COD is stored. A single-origin COD amount
is a preview from the saved order, not permission to collect it.

The preview validates local typed fields and mapping completeness, but does not
approve packed-point compatibility, final tariff, sender ownership or subsidy/payer
settlement. These remain mandatory preflight checks before real creation. No draft
is exposed as a created TTN in the customer account; existing external TTN display
is unchanged. Audit records actor/time, reviewed fields and snapshot hashes, not
recipient values in logs. Access requires manage_woocommerce and
manage_pnpm_shipments. No migration, option changes or deployment performed.

**Contract review, 2026-10-18:** the [official developer portal](https://developers.novaposhta.ua/)
is accessible in the user's Chrome tab; the earlier HTTP 403 is no longer an access
blocker. Save, string-recipient, locker, marking, tracking and document-list pages
were read without executing API requests. Verified fields, conflicting official
examples and remaining checks are recorded in the
[shipment contract review](../../../docs/api/NOVA_POSHTA_SHIPMENT_CONTRACT.md).
This does not enable creation or replace implementation tests. Remaining work:
verified payload/compiler and sender/recipient preflight, durable create claim plus
unknown-outcome reconciliation, authenticated PDF proxy, shipment tracking/customer
view and idempotent email/linked-Telegram outbox. Creation means prepared; only
carrier-confirmed physical acceptance means dispatched. Service-channel settings
apply, advertising opt-outs must not. None of these notifications are implemented
by saving a manager draft.

Local check: `tests/manager-preparation-local.php` via WP CLI, HTTP/mail intercepted,
synthetic order/customer/approval rows deleted in finally. Covers two origins/split
line, multiple boxes, stale revision, atomic rollback, external/locked protections,
capabilities, unchanged profile, COD and fresh/stale confirmation. Workflow and
safe recovery: `docs/MANAGER_CUSTOMER_GUIDE_UK.md#np-preparation`. UK/RU help updated;
publication awaits deployment. Roll back code only; preserve saved drafts/audit.

Verification: 40 local DB checks, 40 desktop/mobile manager fixture checks, 54
shared checkout UI checks, 30 directory checks, 41 delivery checks, TTN normalizer,
20 approval checks and the scoped manager-help UK/RU canonical-parity check passed.
PHP/JS syntax, skill validator and documentation-impact checks passed. Full manager
help test still finds two pre-existing `mailings` canonical-text mismatches,
confirmed unchanged from HEAD; the new `--np-preparation` mode checks this section
without weakening the default full check. No production acceptance claimed.

Окремий WooCommerce-плагін прямої інтеграції з офіційним API Нової пошти для багатоскладських замовлень Paint/Lavka.

## Попередній checkout-етап 0.8.1 (історія)

Версія `0.8.1` залишає в налаштованому checkout НП та безкоштовний самовивіз для одного складу; доставку перевізнику не додає до оплати товарів. Показує поріг покриття базового тарифу з політики ролі клієнта. Збережені клієнтські ТТН, менеджерська обробка та доставка в листах залишаються доступними. Створення реальних ТТН нашим API залишається вимкненим.

Уже реалізовано:

- HPOS-сумісне завантаження плагіна для WooCommerce 11;
- власні таблиці відправлень, товарних рядків, аудиту та приватних документів;
- ключ API лише з `PNPM_NOVA_POSHTA_API_KEY`, без збереження у WordPress та без показу в HTML;
- окрема сторінка Лавка → Лавка: налаштування → Лавка: Нова пошта;
- read-only перевірка офіційного API;
- read-only завантаження бізнес-відправника, адрес і контактних осіб з Нової пошти;
- зіставлення кожної Stock Location із зареєстрованою адресою відправника;
- окремий пошук і вибір точного відділення або поштомата, куди склад самостійно здає посилки;
- діагностика фактичного розподілу товарів за `_slw_data`, включно з поділом кількості одного рядка між складами;
- fallback `_stock_location` лише для старих замовлень без `_slw_data`;
- окрема сутність shipment, таблиця її товарного складу та глобальний захист від дубля номера ТТН;
- нормалізація текстового номера й офіційного tracking-посилання до канонічного 14-значного номера;
- подвійне блокування будь-яких API-операцій запису.
- окремі профілі оплати доставки для роздрібних покупців і партнерів/оптовиків;
- зіставлення Woo-ролей із профілем доставки;
- поріг суми замовлення та окремі правила нижче/вище нього: без компенсації, повна оплата магазином, фіксований бюджет, відсоток від товарів або від доставки;
- окремий платник для основного тарифу, оголошеної вартості, кур'єрського забору, адресної доставки, поштомата, пакування та додаткових складських посилок;
- окремі дозволи COD для однієї та кількох посилок і правило сплати комісії.
- окремий спосіб доставки WooCommerce для класичного checkout;
- пошук населеного пункту, відділення або поштомата через офіційні read-only довідники Нової пошти;
- адресну доставку кур'єром із використанням стандартних адресних полів checkout;
- розрахунок окремої посилки для кожного фактичного складу, включно з поділом кількості одного товару;
- показ покупцю кількості посилок, повної вартості перевізника та внеску магазину;
- застосування роздрібної або партнерської політики оплати доставки й обмеження післяплати;
- збереження у замовленні знімка одержувача, тарифів, розподілу посилок і політики.

## Подвійний запобіжник реальних ТТН

Запис у Нову пошту можливий лише коли одночасно:

1. внутрішня настройка `writes_enabled` дорівнює `yes`;
2. у `wp-config.php` явно визначено `PNPM_ALLOW_REAL_TTN` як `true`;
3. конкретна дія отримала окреме підтвердження оператора.

У версії `0.6.0` немає UI для ввімкнення першого пункту і немає реалізації створення ТТН. Збереження клієнтської ТТН змінює тільки локальний запис.

## Налаштування відправників складів

1. Відкрити Лавка → Лавка: налаштування → Лавка: Нова пошта.
2. Переконатися, що read-only перевірка API успішна.
3. Натиснути «Завантажити відправників з Нової пошти».
4. Для кожної Stock Location вибрати бізнес-відправника, зареєстровану адресу, контактну особу та перевірити телефон.
5. Якщо склад сам здає посилки, знайти за номером або адресою і вибрати точне відділення чи поштомат Нової пошти. Якщо потрібен забір кур'єром, вибрати відповідний тип відправлення; окрема точка передавання тоді не потрібна.
6. Заповнити зрозумілу покупцю назву складу, увімкнути рядок і зберегти налаштування.

Статус рядка має стати «Готово». Для самостійного передавання він неможливий без конкретної точки Нової пошти. Вимкнені склади не використовуються для побудови відправлень. Технічні Ref залишені у згорнутих блоках лише для діагностики.

## Політика оплати доставки

На цій самій сторінці налаштовуються два профілі: роздрібний і партнерський. Ролі `partner`, `opt`, `opt_osn` та `schule` початково віднесені до партнерського профілю; `guest`, `customer`, `subscriber` та `internet_client` — до роздрібного. Призначення можна змінити в адмінці.

Для кожного профілю задається поріг суми товарів після знижок і правило бюджету магазину нижче та вище порога:

- покупець сплачує все;
- магазин сплачує вибрані складові повністю;
- фіксований бюджет у гривнях;
- бюджет як відсоток суми товарів;
- бюджет як відсоток вибраних складових доставки;
- необов'язковий максимальний внесок магазину.

Кожна складова має власного платника: покупець, магазин або спільна оплата в межах бюджету магазину. Це дозволяє, наприклад, залишити базовий тариф покупцю, але оплатити магазином другу посилку, яка виникла через внутрішнє розділення складів; або спрямувати на доставку до 3% суми товарів.

Початкові значення 0.8.0 за погодженими умовами: до 1200 грн включно все сплачує
одержувач; строго понад 1200 грн базовий тариф кожної посилки покриває магазин,
страхування та додаткові послуги — одержувач. Поріг рахують із суми товарів після
знижок у shipping package. COD для однієї посилки дозволено, для кількох складів —
заборонено. Збережена політика `pnpm_delivery_policy_v1` має пріоритет над defaults
і автоматично не перезаписується. На deploy обов'язково звірити обидва профілі:
поріг 1200, нижній рівень customer, верхній store без cap, base_transport та
extra_shipments — budget, решта — customer. Збережені індивідуальні правила
потребують окремого погодження, а не автоматичної міграції.

## Класичне оформлення замовлення

Плагін реєструє зональний спосіб доставки `pnpm_nova_poshta` та один раз додає його до наявної зони доставки України. На сторінці WooCommerce -> Налаштування -> Доставка треба переконатися, що спосіб «Доставка Новою поштою» увімкнений у потрібній зоні.

НП доступна до вибору міста. Покупець обирає населений пункт і спосіб отримання:
відділення, поштомат або кур'єр. Тариф показується як орієнтир окремої оплати НП:
Woo shipping cost завжди 0, не додається до оплати товарів. Ознака
`pnpm_payment_collection=carrier` і покриття базового тарифу зберігаються у shipping
item, деталі — `_pnpm_checkout_quote`. При безкоштовному базовому тарифі текст
явно залишає страхування і допослуги за одержувачем. Грошове відшкодування або
створення ТТН цим кодом не виконується; менеджер застосовує збережену політику
під час оформлення перевезення.

Вага каталогу Lavka історично часто записана у грамах у полі ваги WooCommerce.
Початковий режим — грами. Якщо хоча б один товар не має ваги, checkout не запитує
тариф за резервною вагою і не показує вигадану суму. Помилка тарифного API також
не блокує окрему оплату перевізнику. Помилки розподілу/налаштування складів і
незаповнена адреса НП залишаються блокуючими. Оцінки на сторінці погодження
документів зберігають свій попередній контракт та попередження про резервну вагу.

`Shipping/CheckoutRates.php` працює лише для українського package з доступним
`pnpm_nova_poshta`. Залишає тільки цей спосіб та власний самовивіз: старі тарифи
можуть мати довільний ID, зокрема `flat_rate`, тому deny-list не достатній.
Окремий варіант клієнтської ТТН у цьому checkout також не показується; збережені
ТТН та менеджерські операції не змінюються. Налаштування зон не видаляються.
Самовивіз додається лише за повного розподілу товарів на одну location;
ID складу зберігається у shipping meta. НП є початковим вибором, явний вибір
клієнта зберігається. Версія/ролі/політика входять у hash кешу shipping package.

Підпис НП показує поріг поточного профілю (retail/partner) строго «понад», якщо
політика справді покриває весь базовий тариф без ліміту. Часткову/лімітовану
компенсацію не рекламує як безкоштовну. Після досягнення порогу підпис повідомляє,
що базовий тариф покриває магазин. Самовивіз називає склад розподілу, а не місто
одержувача; для вибраного самовивозу приховано Woo destination/address calculator.
За адресної доставки показано попередження про дорожчу доставку та можливість
вибрати відділення вище. Без фіксованої суми доплати:
[офіційні тарифи НП](https://novaposhta.ua/shipping-cost/?language=uk).
Версія 0.8.1 інвалідує старі session rates; старий вибір замінюється НП.

Локальна перевірка 18.10.2026: 41 standalone перевірка shipping/policy, 12 перевірок
реальних Woo-класів, 80 браузерних перевірок 390/1280 px на реальних PHP-шаблонах
і перекладах з mocked directory API. Окремо перевірено живий `paint.local`:
guest cart, два методи, перемикання самовивозу, попередження address/branch та
ширину checkout. Тестову корзину очищено, замовлення/оплати/ТТН не створювалися.
Новий код, канон та MU-справка підготовлені локально; production очікує deploy.

Deploy: наявний плагін + MU-справка і переклади, без нових плагінів та схем.
Перевірити на цільовому сайті наявність увімкненого `pnpm_nova_poshta` у зоні,
зіставлення складів та політику. Якщо власний метод не налаштовано, legacy не
вимикається мовчки. Rollback: повернути попередній код і оновити shipping cache;
збережені замовлення не перераховувати. Production цим завданням не змінюється.

Перевірки 0.8.0: `php tests/checkout-delivery.php`, `php tests/point-directory.php`,
local `tests/point-card-fixture.php` + `tests/point-card-ui.cjs` (desktop/mobile).
Тести не створюють реальні замовлення, оплати чи ТТН.
Перевірено локально 16.10.2026: 28 standalone перевірок доставки, 8 перевірок
реальних Woo-класів (`wp eval-file tests/checkout-delivery-local.php`),
30 перевірок довідника та 48 браузерних перевірок при 390/1280 px.
`PNPM_POINT_FIXTURE_DIR` додає до локального тесту HTML реальних Woo-labels для
4,00 / 1200,00 / 1200,01 грн і самовивозу. API у тестах перехоплено; повне
production-оформлення та живий тариф НП не перевірено. Канон оптової довідки
і код «Як замовляти» оновлені, публікація очікує deploy.

Checkout виконує лише read-only виклики `searchSettlements`, `getWarehouses` і `getDocumentPrice`. Він не створює, не змінює і не видаляє ТТН.

## Пошук і картка точки — 0.7.0, локально 2026-09-26

`WarehouseDirectory::searchPage` виконує один bounded `Address/getWarehouses` запит на сторінку (50 сирих рядків). `nextPage` розраховується до фільтрації branch/postomat, за `info.totalCount` або повною сирою сторінкою, якщо total відсутній. Порожня відфільтрована сторінка не завершує пошук. Кнопка продовження дає доступ до наступних сторінок; порожній query дозволений. Межа page 1–10000 захищає від некоректного вводу. Дублікати Ref усуваються в межах сторінки й у браузері між сторінками. Старий `search()` зберігає контракт першої сторінки для sender settings; цей етап змінює саме клієнтський checkout.

Whitelist картки: `PlaceMaxWeightAllowed` → одна коробка, `TotalMaxWeightAllowed` → усе відправлення, `MaxDeclaredCost` → оголошена вартість; `ReceivingLimitationsOnDimensions` та `SendingLimitationsOnDimensions` не змішуються, порядок Length/Width/Height. Schedule/Reception/Delivery показуються окремо. Нуль, відсутнє, від'ємне або нечислове значення — unknown, не unlimited. Немає універсальних hardcoded 20/30 кг. `Working` і відсутність `DenyToSelect=1` дозволяють вибір; інші/невідомі статуси показуються без збереження вибору. Це довідникова доступність, не перевірка поточних годин.

Кеш сирих сторінок v2 — 15 хвилин, помилки не кешуються. Перезавантаження відновлює картку окремим `pnpm_recipient_point` AJAX із exact Ref та перевіркою CityRef/типу; клієнт не передає власні ліміти. Обидва endpoint перевіряють nonce, ключ залишається на сервері. JS скасовує застарілі відповіді, очищує картку при зміні міста/типу/тексту, показує retry при помилці. Значення API вставляються тільки як текст. Оптова контекстна справка — `#np-point-limits`, лише дозволеним ролям.

Прямі read-only відповіді офіційного API 2026-09-26 підтвердили exact Ref, totalCount, поля маси/габаритів/графіка й MaxDeclaredCost поштомата. Портал developers недоступний через інструмент перевірки; не трактувати довідник як повний перелік юридичних/товарних правил. Джерела: [офіційна інтеграція](https://novaposhta.ua/for-business/cooperation/integration/), [актуальні умови](https://novaposhta.ua/help/). При rate-limit показується помилка й явний повтор, без нескінченних автоматичних запитів.

Перевірка реального пакування/об'ємної ваги/придатності замовлення, авторитетна повторна перевірка точки при submit, зміна тарифу і старого способу `novaposhta` — поза цим етапом. Картка прямо попереджає про це. Дані замовлень, shipping zones і write-флаги не змінюються; міграції немає. Deploy окремо; rollback — попередній код, кеш v2 сам спливає. Production 0.7.0 не розгорнуто.

Тести: `php tests/point-directory.php` (без БД/мережі); `wp eval-file tests/point-card-fixture.php` з `PNPM_POINT_FIXTURE_DIR` у local, потім `PLAYWRIGHT_MODULE=/path/to/playwright node tests/point-card-ui.cjs /path/to/fixture-dir`. Фікстура використовує реальний PHP/JS/CSS, віртуальну оптову роль і mocked API; це не E2E оформлення production-замовлення.

Перевірено: 30 standalone перевірок довідника і 40 браузерних перевірок при 1280/390 px; HTML-значення, застарілі відповіді, помилки/retry, зміна міста/типу, відновлення картки та оптове контекстне посилання. Горизонтального переповнення немає. Регресія: 42 external-TTN перевірки, 21 перевірка листів і normalizer — успішно; HTTP/mail перехоплені, тестові замовлення видалені.

## Наступні етапи

1. Незмінний shipment-plan після створення замовлення на основі фактичного `_slw_data`.
2. QR, штрихкод, PDF та зображення у приватному сховищі; текст/офіційне посилання вже приймаються на checkout.
3. Автоматичний polling та заміна/відкликання поданої ТТН з аудитом; ручна tracking-перевірка та підтвердження вже реалізовані.
4. Змішані замовлення: частина зовнішніх ТТН, частина створених нашим бізнес-акаунтом.
5. Окремо підтверджуване створення ТТН, друк та автоматичні повідомлення про зміну tracking-статусу. Перегляд клієнтських ТТН у кабінеті та звичайних листах замовлення вже реалізовано.
6. Action Scheduler для оновлення статусів та повторів лише read-only операцій.

## Бізнес-правила, які треба погодити

- чи може менеджер змінювати розподіл до першої ТТН;
- коли саме дозволяти кнопку створення реальної ТТН;
- які внутрішні назви складів можна показувати покупцю;
- дедлайн і правила заміни зовнішньої ТТН клієнта;
- хто друкує зовнішню маркировку;
- чи приймаємо реєстр ТТН у першій робочій версії.

## Зовнішня ТТН B2B-клієнта

### Доставка у листах (0.6.1)

`Email/DeliverySummary.php` додає блок після заголовка замовлення перед товарами у стандартних HTML/plain Woo-листах клієнту й менеджеру. Показує збережену назву доставки (також самовивіз/кур'єр), зареєстровані клієнтські ТТН, склади та стан перевірки. Читає локальні дані без API-запитів. План, який не вдалося зареєструвати, показує попередження менеджеру без удавано збережених номерів.

Дочірній Folio-заказ без власної доставки використовує `_folio_parent_order_id` або ранній `_folio_split_from_order_id`, лише після перевірки власника. Успадковані дані позначаються як усі склади початкового замовлення; посилання для клієнта веде до кабінету, для менеджера — до адміністративного замовлення.

Буферизація обмежена `emails/email-order-details.php` та `emails/plain/email-order-details.php` через before/after-template hooks; Woo-шаблони не копіюються. Для стороннього конструктора, який обходить ці шаблони, потрібен окремий адаптер. Вже надіслані листи не змінюються та автоматично не пересилаються. Локальний regression: `tests/email-delivery.php`, HTTP/mail заблоковані, синтетичні замовлення видаляються. Версія 0.6.1 ще не розгорнута в production.

Перевірено локально 2026-09-20: 21 email-перевірка (new order / processing / on-hold, HTML/plain, split, самовивіз, кур'єр, escaping, незбережений план), 32 перевірки external-TTN та normalizer. HTML-лист перевірено у браузері при ширині 390/900 px без горизонтального переповнення. Реальні листи не надсилались.

На checkout оптовик вибирає «Відправка за моєю ТТН Нової пошти». Доступ визначається спільним allow-list `pc_wholesale_customer_roles` (типово partner/opt/opt_osn/schule), а не тільки видимістю поля. Спосіб доступний для України та одного логічного Woo shipping package, який може включати кілька фізичних складів. Для кожного складу потрібен окремий номер; можна вставити повідомлення або офіційне tracking-посилання. Кілька різних номерів в одному полі відхиляються. Клієнт підтверджує розпізнані номери та показаний склад товарів.

Доставка у Woo — 0 грн. Це не безплатна послуга НП: платник і тариф визначені клієнтською ТТН. Наш товар оплачується окремо; Woo COD прибирається на checkout і сторінці повторної оплати. Відправлення має `source=customer_external`, `status=submitted`, без прав API edit/delete. Зовнішній COD не потрапляє в наші платежі/фіскалізацію.

`ExternalTtnCheckout` валідовує актуальний серверний `pc_alloc_plan` і зберігає `_pnpm_external_plan`. `ExternalShipmentStore` на `woocommerce_checkout_order_created` до запуску ФОЛІО записує весь batch в наявні InnoDB `pnpm_shipments/items/events`: per-order lock, транзакція, глобальний unique TTN. Помилка не пропускає оплату/обробку ФОЛІО. Повтор неоплаченого checkout допускає тільки ті самі номери/товари, переприв'язує нові Woo item IDs, не дублює відправлення. Зміна вже поданого набору блокується до втручання менеджера.

Кабінет показує номери та статуси тільки власнику/менеджеру. Після split записи залишаються в оригінальному замовленні: дочірні замовлення явно посилаються на весь список його відправлень. Немає автоматичного дублювання чи перепризначення ТТН дочірнім рахункам.

Кнопка менеджера викликає read-only `TrackingDocument/getStatusDocuments` без телефону клієнта. Зберігається тільки номерний код, публічний текст статусу і час. Підтвердження потребує checklist; статус НП має бути `1` (очікує передачі). Якщо API недоступне, потрібна ручна перевірка і примітка 10–500 символів. Інший доступний статус блокує підтвердження. Поточні кількості та `_slw_data`/`_pc_alloc_plan` звіряються з незмінним складом. Саме підтвердження не означає фактичну передачу НП. Інструкція менеджера: [операційний runbook](../../../docs/OPERATIONS_RUNBOOK.md#клиентские-ттн-новой-почты).

Перевірено локально 2026-09-20: normalizer та інтеграційні тести з блокуванням HTTP/mail. Read-only запит офіційному API з тестовим нульовим номером підтвердив маршрутизацію методу та помилку валідації номера; реальна клієнтська ТТН не запитувалась. Портал developers повернув 403. Production не розгорнуто й не перевірено.

Перед підтвердженням склад перевіряє фізичне місце відправлення, склад товарів, одержувача, платника, COD, оголошену вартість, вагу, габарити, кількість місць і маркировку.

Активація вже наявного плагіна/нова схема не потрібні. `pnpm_settings.external_ttn_enabled=no` приховує прийом нових ТТН, записи й перегляд залишаються. Production activation policy у manifest — `manual`. Відкат: вимкнути прийом і повернути попередній код; існуючі записи не видаляти. Для виправлення помилкової/скасованої ТТН звернутися до менеджера; UI заміни/видалення та автоматичне звільнення номера ще не реалізовані. Не використовувати старий номер у новому замовленні.

Тести (тільки local, синтетичні об'єкти видаляються):

```bash
php wp-content/plugins/paint-nova-poshta-multishipping/tests/manual-ttn-normalizer.php
wp eval-file wp-content/plugins/paint-nova-poshta-multishipping/tests/external-ttn.php
```

## Локальна перевірка

```bash
wp plugin activate paint-nova-poshta-multishipping
wp plugin status paint-nova-poshta-multishipping
```

Після активації відкрити Лавка → Лавка: налаштування → Лавка: Нова пошта. Без API-ключа read-only перевірка повинна показати зрозумілу помилку; створення ТТН відсутнє.

## Checkout recovery: absent external TTN plan

Verified 2026-09-22: `ExternalShipmentStore::submit()` treats missing/empty
`_pnpm_external_plan` as no external shipment and returns before acquiring a
database lock. Do not cast missing Woo meta to an array: `(array) ''` is `['']`,
not an empty array. Non-empty malformed plans are rejected before transaction
work using the existing checkout validation message. Ordinary delivery does not
require a customer TTN. The local `tests/external-ttn.php` suite covers empty
meta, malformed records, the ordinary checkout-created hook, real synthetic
multi-parcel persistence and idempotent retries; HTTP and email are intercepted.


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

Combined confirmation integration (2026-10-11): `DocumentShipmentBuilder` reads
`bundle.documents` as independent saved Folio account snapshots when no Woo order is
supplied. It maps each document warehouse to a sender, gives rows distinct composite
keys, and counts merchandise once. Accounts at the same physical location share the
existing shipment grouping. No live-stock allocation, carrier API contract or waybill
creation changes. Covered by PCOE `tests/approval-delivery.php`.
