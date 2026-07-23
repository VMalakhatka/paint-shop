# Folio Order JSON Contract Draft

This document describes the draft WooCommerce to Folio order payload.

Status: draft, preview-only on the WooCommerce side.

WooCommerce must not split one order into multiple Folio documents. WooCommerce sends the structured order, customer mapping, item allocation plan, and available Folio warehouses with priorities. Java/Folio owns the final document split and warehouse selection logic.

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
  "woo_order": {
    "id": 116873,
    "number": "116873",
    "status": "completed",
    "currency": "UAH",
    "total": 3132.6
  },
  "folio_client": {
    "user_id": 30,
    "id": "FOLIO_SHORT_NAME",
    "short_name": "FOLIO_SHORT_NAME",
    "name": "Full Folio partner name",
    "type": "D"
  },
  "folio_document_link": {
    "document_id": "",
    "document_number": "",
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
        {
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

`folio_client.id`

The Folio client identifier selected in the WordPress user profile. In the current Woo mapping, `id` and `short_name` are intentionally the same value.

`folio_document_link`

Existing Woo order to Folio document meta. Empty values mean no Folio document is linked yet.

`items[].allocations`

Allocation plan from Woo order item meta `_pc_alloc_plan`.

`items[].allocations[].folio_warehouses`

Ordered list of Folio warehouse candidates for the Woo location. Lower `priority` number should be used first.

## Java/Folio Responsibilities

Java should:

1. Validate that `folio_client.id` is present and exists in Folio.
2. Decide how many Folio documents must be created.
3. Split lines by available Folio warehouses and priorities.
4. Select the final Folio warehouse per line or per document.
5. Create non-stock-impacting draft documents for cart/import draft flows when requested later.
6. Create real documents for order execution flows when requested later.
7. Return enough data for Woo to persist the Folio link in order meta.

Woo should not:

1. Create the final warehouse split itself.
2. Guess missing Folio accounting fields.
3. Send incomplete fields as if they were confirmed business values.
4. Mark an order completed based only on preview JSON.

## Expected Java Response Draft

```json
{
  "ok": true,
  "woo_order_id": 116873,
  "documents": [
    {
      "document_id": "FOLIO_INTERNAL_ID",
      "document_number": "FOLIO_VISIBLE_NUMBER",
      "document_type": "account",
      "document_status": "draft",
      "folio_warehouse_id": "7",
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

If Java creates multiple Folio documents for one Woo order, the current single-link meta model must be extended before enabling sending.

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
