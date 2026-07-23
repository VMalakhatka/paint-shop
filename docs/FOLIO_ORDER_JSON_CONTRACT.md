# Folio Order JSON Contract Draft

This document describes the draft WooCommerce to Folio order payload.

Status: draft, preview-only on the WooCommerce side. - в Фолио это неучитываемый счет , который не меняет остатки товара 

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

The preview is read-only and does not send anything to Folio.

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

Currently fixed to `Интернет заказ сайт`.

`folio_account_header.additionalInfo`

Woo customer checkout note.

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
8. Create non-stock-impacting draft documents for cart/import draft flows when requested later. In Folio this is a non-accounting account.
9. Create real documents for order execution flows when requested later.
10. Return enough data for Woo to persist the Folio link in order meta.

Woo should not:

1. Create the final warehouse split itself.
2. Guess missing Folio accounting fields.
3. Send incomplete fields as if they were confirmed business values.
4. Mark an order completed based only on preview JSON.
5. Choose the final Folio `warehouseId` at order header level.

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
