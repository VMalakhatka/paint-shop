# Folio Order JSON Contract Draft

This document describes the draft WooCommerce to Folio order payload.

Status: implemented in Woo preview, manual real create, and automatic checkout processing.

`preview_only=true` is used only for preview/simulation. Real checkout creation sends
`preview_only=false` to Java. In Folio, a missing-stock or draft document is a
non-accounting account that does not change stock.

WooCommerce must not split one order into multiple Folio documents. WooCommerce sends the structured order, customer mapping, item allocation plan, and available Folio warehouses with priorities. Java/Folio owns the final document split and warehouse selection logic.

Reference Java API document:

`/Users/admin/Documents/Toleran/Proect_Lavka/kreul_com_ua/docs/api/FOLIO_ACCOUNT_JS_API.md`

This contract must cover section `1.1. Extended account header fields`.

## Current Woo Preview Builder

File:

`wp-content/mu-plugins/pc-folio-order-link.php`

Function:

`pc_folio_build_order_preview_payload($order_or_id)`

Admin UI:

WooCommerce order edit screen -> `Folio JSON preview`.

The preview textarea is read-only and does not send anything to Folio by itself.
The same metabox also has actions for sending a preview to Java, creating real
Folio account documents, applying a saved Java response, and creating Woo child
orders from that response.

## Payload Shape

```json
{
  "preview_only": true,
  "schema_version": "folio-order-preview/v1",
  "source": "woo_order",
  "intent": "create_or_update_folio_documents",
  "split_strategy": "java_by_allocations_and_folio_warehouse_priority",
  "folio_account_header": {
    "externalRequestId": "7ec24df3-b03d-4a3d-a104-d459658451b7",
    "documentNumber": "", - если пусто создать номер самому - последний счет +1
    "documentDate": "2026-07-23T00:00:00",
    "controlDate": "2026-07-26",
    "warehouseId": null, - логика складов в товаре 
    "operationType": "СЧЕТ",
    "folioOperationKind": "*ПРЕДОПЛАТ",
    "payerName": "Full Folio partner name",
    "receiverName": "CLASSIC",
    "payerShortName": "FOLIO_SHORT_NAME",
    "folioUser": "buh",
    "sourceInfo": "Интернет заказ сайт",
    "additionalInfo": "Customer checkout note",
    "priceContractType": "ПАРТНЁР",
    "notCash": true,
    "accountingEnabled": true,
    "returnFlag": false,
    "payerCity": "Kyiv",
    "directorName": "",
    "accountantName": "",
    "payerPhone": "+380000000000",
    "deliveryInfo": "Nova Poshta, Kyiv, branch 14, payment method, tel. +380000000000",
    "comment": "Woo order #116873, ordered at 2026-07-23 12:34:56"
  },
  "woo_order": {
    "id": 116873,
    "number": "116873",
    "status": "completed",- это уже расходник не счет , 
     может быть processing - это обычный счет 
     pc-draft - это неучитываемыйсчет 
    "currency": "UAH",
    "total": 3132.6
  },
  "folio_client": {
    "user_id": 30,
    "id": "FOLIO_SHORT_NAME",
    "short_name": "FOLIO_SHORT_NAME",
    "name": "Full Folio partner name",
    "type": "D" - это Дилер 
  },
  "folio_document_link": {
    "document_id": "", уникальный номер 
    "document_number": "", обычный номер 
    "document_type": "", 
    "document_status": "",
    "document_created_at": "",
    "document_payload_hash": "",
    "document_last_error": ""
  },
  "billing": {
    "first_name": "Name",
    "last_name": "Surname",
    "company": "",
    "phone": "+380000000000",
    "email": "client@example.com",
    "city": "Kyiv",
    "address_1": "Street",
    "address_2": ""
  },
  "items": [
    {
      "order_item_id": 2477,
      "product_id": 11770,
      "sku": "P-051000",
      "name": "Product name",
      "quantity": 2,
      "subtotal": 295.2,
      "total": 295.2,
      "unit_price": 147.6,
      "allocations": [
        { - для неучитываемых счетов - создаем счет без распределения на одном складе - самом приоритетном
        
        у каждого товара есть план размещения по складам 
        в Woo храниться что он должен быть помещен на группу складо в Одесса (группа состоит из 5 и 15го склада у 5го приоритет выше - пытаемся 2 сколько можем зарезервировать на 5 если нет достаточного кол-ва то на 15 ! Если не хватило всравно количествва - то создаем счет неучитываемый на складе с высоким приеритетом - в информации счета пишем - нет на складе )
          "woo_location_id": 3943,
          "woo_location_slug": "odesa",
          "woo_location_name": "Odesa",
          "quantity": 2,
          "allocation_source": "_pc_alloc_plan",
          "folio_warehouses": [
            {
              "id": "5",
              "priority": 10
            },
            {
              "id": "15",
              "priority": 20
            }
          ]
        }
      ]
    }
  ]
}
```

## Field Notes

`preview_only`

Always `true` in the Woo admin preview. The future sender endpoint must set this according to the request mode.

`schema_version`

Draft schema identifier. Java should reject or warn on unknown versions.

`intent`

Expected business action. Current value means Java may create new Folio documents or update existing linked documents if `folio_document_link` is already filled.

`split_strategy`

Documents are split on the Java/Folio side using item allocations and Folio warehouse priorities.

`folio_account_header`

Extended Folio account header fields from `FOLIO_ACCOUNT_JS_API.md` section `1.1`.

Woo fills this block for preview and future sending. Java must validate dictionary values and may transform/split it into one or more real Folio account requests.

`folio_account_header.externalRequestId`

Generated for each preview/request. If Java requires idempotency, this rule must be changed before enabling real sending. Current user decision: each generated request gets a new value.

`folio_account_header.documentNumber`

Empty string means Java/Folio should allocate the next available numeric Folio document number. The Java API note says `SCL_NAKL.N_PLAT_POR` is currently `float NOT NULL`, so Woo must not send non-numeric web numbers here.

`folio_account_header.documentDate`

Currently generated by Woo as the current site date at midnight: `YYYY-MM-DDT00:00:00`.

`folio_account_header.controlDate`

Current site date plus 3 days: `YYYY-MM-DD`.

`folio_account_header.warehouseId`

Always `null` in Woo preview for the order-level payload. Woo no longer chooses one final header warehouse. Java must choose the final Folio warehouse per generated document using `items[].allocations[].folio_warehouses`.

`folio_account_header.operationType`

Currently fixed to `СЧЕТ`.

`folio_account_header.folioOperationKind`

Currently fixed to `*ПРЕДОПЛАТ`. Later this may depend on customer/contract settings.

`folio_account_header.payerName`

Uses the mapped Folio partner full name. If missing, Woo falls back to billing first and last name.

`folio_account_header.receiverName`

Currently fixed to `CLASSIC`.

`folio_account_header.payerShortName`

Mapped Folio partner short name. This must be `_PARTNER.N_USER`.

Important: `_PARTNER.N_USER` is the unique short organization name used by Folio for `SCL_NAKL.BRIEFORG` / `SCL_MOVE.ORG_PREDM`. In the current Woo user mapping, `folio_client.id`, `folio_client.short_name`, and `folio_account_header.payerShortName` are intentionally the same value. `_PARTNER.NAMEP_USER` is a payment-document name and must not be used as the short name.

`folio_account_header.folioUser`

Currently fixed to `buh` on the Woo side because the authoritative Folio login/user is owned by Java/Folio configuration. Java should confirm whether it should accept this value from Woo or override it from its own authenticated Folio user.

`folio_account_header.sourceInfo`

Built from site/customer information and trimmed to 30 UTF-8 characters because
Folio stores it in `L_CP1_PLAT varchar(30)`.

`folio_account_header.additionalInfo`

Compact Woo order reference, also trimmed to 30 UTF-8 characters because Folio
stores it in `L_CP2_PLAT varchar(30)`.

Current format:

```text
Int 116906 2026-07-25 16:20
```

`folio_account_header.priceContractType`

Resolved from the Woo user role -> Folio contract mapping used by `lavka-price-sync`. If no mapped contract exists, Woo falls back to the first Woo role slug for visibility in preview.

`folio_account_header.notCash`

Currently fixed to `true`.

`folio_account_header.accountingEnabled`

Currently fixed to `true`.

`folio_account_header.returnFlag`

Currently fixed to `false`.

`folio_account_header.payerCity`

Woo billing city.

`folio_account_header.directorName`

Currently empty.

`folio_account_header.accountantName`

Currently empty.

`folio_account_header.payerPhone`

Woo billing phone.

`folio_account_header.deliveryInfo`

Built from Woo shipping method, shipping/billing city and address, payment method, and billing phone.

`folio_account_header.comment`

Currently contains the Woo order number and order creation time.

`folio_client.id`

The Folio client identifier selected in the WordPress user profile. This must be `_PARTNER.N_USER`.

In the current Woo mapping, `id` and `short_name` are intentionally the same value. The full client name is `_PARTNER.NAME_USER`.

`folio_document_link`

Existing Woo order to Folio document meta. Empty values mean no Folio document is linked yet.

`items[].allocations`

Allocation plan from Woo order item meta `_pc_alloc_plan`.

`items[].allocations[].folio_warehouses`

Ordered list of Folio warehouse candidates for the Woo location. Lower `priority` number should be used first.

## Java/Folio Responsibilities

Java should:

1. Validate that `folio_client.id` is present and exists in Folio.
2. Validate all `folio_account_header` dictionary values against Folio dictionaries.
3. Decide how many Folio documents must be created.
4. Split lines by available Folio warehouses and priorities.
5. Select the final Folio warehouse per line or per document.
6. Put the selected final warehouse into the actual Java `/admin/folio/accounts` request `warehouseId`.
7. If `documentNumber` is empty, allocate the next valid numeric Folio document number.
8. Create non-stock-impacting documents for cart/import draft flows. In Folio this is a non-accounting account.
9. Create real documents for order execution flows when requested later.
10. Return enough data for Woo to persist the Folio link in order meta.

Woo should not:

1. Create the final warehouse split itself.
2. Guess missing Folio accounting fields.
3. Send incomplete fields as if they were confirmed business values.
4. Mark an order completed based only on preview JSON.
5. Choose the final Folio `warehouseId` at order header level, except for the
   explicit single-warehouse non-accounting draft workflow described below.

## Implemented Draft-to-Cart / Non-accounting Workflow

Owner: `pc-order-import-export/inc/DraftFolioWorkflow.php`.

The workflow is available only for a Woo order with status `pc-draft` and only
to its owner or a WooCommerce manager. Both operations require a Java preview
and a separate explicit apply:

1. `partial_to_cart` recalculates current Woo allocation, replaces the cart with
   the currently loadable quantities, keeps the unavailable remainder in the
   same Woo draft, and creates one non-accounting Folio document for that
   remainder.
2. `whole_draft` leaves the cart unchanged and creates one non-accounting Folio
   document for every valid line of the draft. This is intended for prepaid
   out-of-stock orders.

The target Folio warehouse is an explicit administrator setting stored in
`pcoe_folio_non_accounting_warehouse_id`; it is not derived from an editable
warehouse name. For the current business process the production setting must be
Folio warehouse `7`. The payload deliberately overrides:

```text
woo_order.status = pc-draft
folio_account_header.warehouseId = configured warehouse ID
folio_account_header.accountingEnabled = false
folio_account_header.sourceInfo = нет на складе
split_strategy = single_non_accounting_warehouse
```

Each line still contains a complete synthetic allocation to the configured
warehouse so Java validation does not receive an empty allocation. The Java
endpoint remains `/admin/folio/order-accounts`.

Safety and idempotency rules:

- preview does not modify Woo or Folio;
- apply reuses the exact preview payload and its stable `externalRequestId`;
- draft lines and allocation are fingerprinted; a change requires a new preview;
- after the cart is prepared, the remaining draft lines have a separate
  fingerprint so a manual edit cannot be sent using stale Folio data;
- an unknown or failed apply is never retried automatically;
- the preview transient is retained for a deliberate manual retry;
- preview and apply must return exactly one document with the configured
  warehouse and an explicit `accounting_enabled=false`; any other response is
  blocked;
- an existing linked Folio document blocks repeated creation;
- the draft keeps status `pc-draft`; the non-accounting document never proves a
  real stock write-off.

Woo stores the full response in `_folio_documents_result`, the single document
link in the standard `_folio_document_*` meta, and workflow diagnostics in
`_pcoe_folio_non_accounting_*` meta. All order access and persistence use
WooCommerce CRUD and are HPOS compatible.

## Expected Java Response Draft

```json
{
  "ok": true,
  "woo_order_id": 116873,
  "documents": [
    {
      "document_id": "FOLIO_INTERNAL_ID", - уникальный номер 
      "document_number": "FOLIO_VISIBLE_NUMBER",
      "document_type": "account",
      "document_status": "draft",
      "folio_warehouse_id": "7",
      "source_external_request_id": "7ec24df3-b03d-4a3d-a104-d459658451b7",
      "items": [
        {
          "order_item_id": 2477,
          "sku": "P-051000",
          "quantity": 2
        }
      ]
    }
  ],
  "warnings": [],
  "errors": []
}
```

Woo will use this response to save:

- `_folio_document_id`
- `_folio_document_number`
- `_folio_document_type`
- `_folio_document_status`
- `_folio_document_created_at`
- `_folio_document_payload_hash`
- `_folio_document_last_error`

## Woo Meta/API Layer

Implemented helper functions:

```php
pc_folio_set_order_documents_result($order_or_id, array $result): bool
pc_folio_get_order_documents_result($order_or_id): array
pc_folio_set_parent_child_links($parent_order_or_id, array $child_order_ids): bool
```

Multiple-document meta keys:

- `_folio_documents_result` stores the full Java/Folio response.
- `_folio_child_order_ids` stores child Woo order IDs on the parent order.
- `_folio_parent_order_id` stores parent Woo order ID on a child order.
- `_folio_split_status` stores a compact state: `ready`, `partial`, `error`, `empty`, or `split`.
- `_folio_split_created_at` stores when the Java/Folio split response was saved.

This layer only stores metadata. It does not create child orders, send anything to Folio, or change Woo order statuses.

The old single-document meta keys remain available for the simple case where Java returns exactly one real account document.

## Woo Checkout Automation

Automatic Folio processing runs from:

```php
woocommerce_checkout_order_processed
woocommerce_store_api_checkout_order_processed
```

File:

`wp-content/mu-plugins/pc-folio-order-link.php`

Main functions:

```php
pc_folio_auto_process_checkout_order($order_id): void
pc_folio_create_documents_for_order(\WC_Order $order, string $source = 'manual'): array
pc_folio_apply_saved_response_to_order(\WC_Order $order): array
pc_folio_create_child_orders_from_saved_response(\WC_Order $parent_order): array
```

The automatic pipeline intentionally reuses the same helpers as the manual admin
buttons:

1. Build the Woo -> Folio payload.
2. Validate critical fields before Java:
   - Folio client mapping exists.
   - Order has line items.
   - Each line item has `_pc_alloc_plan` allocations.
   - Each allocation has Folio warehouse mappings.
3. Send `preview_only=false` to Java endpoint:
   - `POST /admin/folio/order-accounts`
4. Save the Java response to Woo meta.
5. Apply the saved response.
6. If Java returned one real document:
   - keep the original Woo order;
   - save the single Folio document link on that order;
   - keep/set Woo status `processing`.
7. If Java returned multiple documents or a `missing_stock_account`:
   - create child Woo orders;
   - real account children get status `processing`;
   - missing-stock children get status `on-hold`;
   - parent gets status `pc-draft` (`wc-pc-draft`, visible as `Draft (import)`);
   - parent stores `_folio_child_order_ids`;
   - children store `_folio_parent_order_id` and `_folio_split_from_order_id`.

Checkout must not fail if Folio/Java fails. On error, Woo stores:

- `_folio_auto_status = error`
- `_folio_auto_error`
- `_folio_auto_started_at`
- `_folio_auto_finished_at`

and adds a private order note. The manual buttons remain available for retry or
diagnostics.

Successful automatic processing stores:

- `_folio_auto_status = success`
- `_folio_auto_started_at`
- `_folio_auto_finished_at`

After a successful automatic checkout run Woo also:

- clears the customer's active cart so processed items do not remain in the
  basket after Folio documents are created;
- redirects the order-received URL to **My Account -> Orders** for the current
  logged-in customer, so the customer immediately sees the real child orders or
  the linked original order.

This redirect is only used for completed Folio automation. Orders waiting for an
online payment confirmation or orders with `_folio_auto_status = error` keep the
standard WooCommerce checkout/order-received flow.

## Runtime Flag

Automatic checkout processing can be disabled in `wp-config.php` or environment
specific config:

```php
define('PC_FOLIO_AUTO_CHECKOUT', false);
```

Default behavior when the constant is not defined:

```php
PC_FOLIO_AUTO_CHECKOUT = true
```

Use this flag before risky deploys, Java maintenance, Folio maintenance, or any
period where Woo orders should be accepted but Folio documents should not be
created automatically.

## Current Manual Recovery / Debug Checks

Check Folio automation meta on an HPOS order:

```sql
SELECT order_id, meta_key, meta_value
FROM wp_wc_orders_meta
WHERE order_id = 116906
  AND meta_key LIKE '_folio_%'
ORDER BY meta_key;
```

Check a split parent and children:

```sql
SELECT id, parent_order_id, status, total_amount, date_created_gmt
FROM wp_wc_orders
WHERE id IN (116906, 116907, 116908)
ORDER BY id;
```

Expected statuses after split:

```text
parent        wc-pc-draft
real child    wc-processing
missing child wc-on-hold
```

## Future: Existing Folio Documents / History Import

Planned safe sequence:

1. Manual link existing Folio document to a Woo order.
2. Folio history preview by mapped customer without creating Woo orders.
3. Import selected Folio document as Woo draft.
4. Bulk import/sync Folio history only after duplicate rules are proven.

## Open Questions For Java

1. What endpoint should accept this payload?
2. Should Java accept `preview_only=true` and return a calculated split without writing to Folio?
3. What exact Folio document type values should Woo send for:
   - cart/import draft
   - real order execution
   - partially fulfilled remainder
4. Which Folio fields are mandatory and must be provided by Woo?
5. Should Java return one document link or multiple document links for one Woo order?
6. What stable Folio document ID should Woo store for idempotent updates?
7. Should Java calculate `payload_hash`, or should Woo calculate it before sending?
8. Should Java trust Woo-provided `folioUser=buh`, or should Java override it from its Folio auth/config?
9. Should empty `documentNumber` mean "allocate last + 1" in Java?
10. Are `receiverName=CLASSIC`, `sourceInfo=Интернет заказ сайт`, and `folioOperationKind=*ПРЕДОПЛАТ` valid dictionary values on production?
