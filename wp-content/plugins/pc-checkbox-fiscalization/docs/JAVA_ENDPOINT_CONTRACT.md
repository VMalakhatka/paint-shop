# Java fiscalization command contract v1

## Responsibility boundary

The Java/Folio side decides **whether** a source document needs a receipt and prepares the final fiscal meaning:

- source document and stable operation identity;
- sale versus return;
- final goods names, SKU/codes, prices, quantities, discounts, and totals;
- Checkbox tax codes for every line;
- payment split and payment metadata;
- customer delivery address for the e-receipt, when required;
- original Checkbox receipt UUID for a return.

The WordPress plugin validates that command, applies idempotency and safety rules, and sends it to Checkbox. It does not query Folio tables, infer payment type, choose taxes, or discover documents by polling.

## Endpoint called by WordPress

Configured template:

```text
GET {java_base_url}{java_command_path}?source_type={source_type}
```

Default path:

```text
/admin/folio/fiscalization/commands/{source_id}
```

If `PC_CHECKBOX_JAVA_TOKEN` is configured, WordPress sends it as `X-Auth-Token`. The endpoint may return either the command object itself or `{ "command": { ... } }`.

The Java endpoint is called only after another system explicitly supplies `source_type` and `source_id` to `/fiscalize-source`. It must not implement a “next receipt” queue whose selection is hidden from the caller.

## Canonical command

Example:

```json
{
  "schema_version": "1.0",
  "operation_key": "folio:expense:123456:SELL:v1",
  "receipt_id": "39ea3580-6045-4dd6-9d5e-cf11d85f76aa",
  "operation_type": "SELL",
  "source": {
    "system": "folio",
    "entity_type": "EXPENSE",
    "entity_id": "123456",
    "document_number": "РН-000123",
    "occurred_at": "2026-08-24T14:25:10+03:00"
  },
  "currency": "UAH",
  "expected_total_cents": 12500,
  "goods": [
    {
      "code": "SKU-1001",
      "name": "Назва товару",
      "price_cents": 12500,
      "quantity_thousandths": 1000,
      "line_total_cents": 12500,
      "tax_codes": [8],
      "discounts": [],
      "barcode": "4820000000000"
    }
  ],
  "payments": [
    {
      "type": "CASH",
      "value_cents": 12500,
      "label": "Готівка"
    }
  ],
  "discounts": [],
  "cashier_name": "Тестовий касир",
  "department": "Основний склад",
  "delivery": {
    "email": "customer@example.test"
  }
}
```

## Required fields

| Field | Type | Rule |
|---|---:|---|
| `schema_version` | string | Exactly `1.0`. |
| `operation_key` | string | Stable business idempotency key, max 191 chars; never reuse it for changed fiscal data. Recommended: `system:document-type:id:operation:revision`. |
| `receipt_id` | UUID | Generated once by the caller and persisted with the source operation. |
| `operation_type` | enum | `SELL` or `RETURN`. |
| `source.system` | string | For example `folio`, `woocommerce`, or another caller. |
| `source.entity_type` | string | Source document/entity type. |
| `source.entity_id` | string | Source identifier. |
| `currency` | enum | `UAH` only. |
| `expected_total_cents` | integer | Positive final receipt total in kopiykas. |
| `goods` | array | 1–1000 final fiscal lines. |
| `payments` | array | 1–10 entries; their sum must equal `expected_total_cents`. |
| `discounts` | array | Receipt-level Checkbox discounts; use `[]` if absent. |

## Goods fields

Required for every item:

| Field | Type | Meaning |
|---|---:|---|
| `code` | string | Stable caller product/SKU code. |
| `name` | string | Final name printed in the receipt. |
| `price_cents` | integer | Unit price in kopiykas, non-negative. |
| `quantity_thousandths` | integer | Quantity multiplied by 1000 (`1 = 0.001`, `1000 = 1`, `2500 = 2.5`). |
| `line_total_cents` | integer | Final line contribution used for local total reconciliation. Sum of all lines must equal `expected_total_cents`. |
| `tax_codes` | array | One or more Checkbox tax codes. The Java side must supply them explicitly; the plugin does not assume a default. |
| `discounts` | array | Line-level Checkbox discounts; use `[]` if absent. |

Optional: `barcode`, `uktzed`, `excise_barcodes`, `header`, `footer`.

`is_return` is not accepted from Java as a decision flag. The plugin derives it from `operation_type`, keeping the whole command internally consistent.

## Payments

Cash:

```json
{"type":"CASH","value_cents":12500,"label":"Готівка"}
```

Cashless:

```json
{
  "type": "CASHLESS",
  "value_cents": 12500,
  "label": "Картка",
  "code": 1,
  "card_mask": "444455******1111",
  "bank_name": "Bank",
  "auth_code": "123456",
  "rrn": "123456789012",
  "payment_system": "VISA",
  "terminal": "Terminal 1",
  "receipt_no": "42",
  "transaction_id": "provider-transaction-id"
}
```

Only `type`, `value_cents`, and `label` are required. Cashless `code` defaults to `1` if omitted and must be between `1` and `9`. Payment values must sum exactly to `expected_total_cents`.

## Discounts

Line and receipt discounts use Checkbox-native values:

```json
{
  "type": "DISCOUNT",
  "mode": "VALUE",
  "value": 500,
  "name": "Знижка",
  "tax_codes": [8]
}
```

- `type`: `DISCOUNT` or `EXTRA_CHARGE`;
- `mode`: `VALUE` or `PERCENT`;
- `value`: for `VALUE`, a positive integer in kopiykas; for `PERCENT`, a positive integer or decimal percentage;
- `name` and `tax_codes`: optional at the discount level.

The Java producer must calculate `line_total_cents` and `expected_total_cents` after all discounts and rounding. Checkbox remains the final semantic validator.

## Returns

A return uses the same contract with:

```json
{
  "operation_type": "RETURN",
  "related_receipt_id": "UUID-of-original-Checkbox-receipt"
}
```

`related_receipt_id` is mandatory. Goods are automatically sent with `is_return: true`. The caller must supply the correct refund payment type and value.

## Optional receipt fields

- `cashier_name`, `department`, `control_number`, `header`, `footer`, `barcode`, `stock_code`;
- `rounding_mode`: `ROUND_10` or `ROUND_50`;
- `delivery.email`, `delivery.emails`, and/or a Ukrainian `delivery.phone` in `+380XXXXXXXXX` format;
- `order_id`, `previous_receipt_id`, `technical_return`, and scalar `context` fields for advanced caller-controlled flows.

Do not send customer contact data unless an electronic receipt actually needs delivery.

## Response and status rules

The WordPress endpoints return safe operation metadata, not the original payload or credentials. Important states:

- `PREVIEW`: local validation passed;
- `VALIDATED`: Checkbox validation endpoint accepted the command without creating a receipt;
- `PROCESSING`: Checkbox accepted a request but fiscal completion is not yet proven;
- `UNCERTAIN`: transport/5xx outcome; do not retry;
- `FAILED` / `VALIDATION_FAILED`: definite failure that may be corrected using a new revision key if fiscal data changes;
- `SUCCEEDED`: fiscal completion confirmed.

For `PROCESSING` or `UNCERTAIN`, call `/reconcile` with the same `operation_key`. The plugin reads the receipt by its UUID and never resends it during reconciliation.

## Java persistence requirements

The producer should persist at least:

- source entity type/id;
- operation type and revision;
- immutable `operation_key`;
- immutable `receipt_id` UUID;
- canonical command hash or command snapshot;
- WordPress operation status and Checkbox receipt UUID/fiscal code returned by the executor.

If any fiscal field changes after a definite failure, create a deliberate new revision and new UUID. Never mutate a command behind an existing `operation_key`.
