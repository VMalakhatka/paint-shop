# Java/Folio read-only contract required by the console

Status: proposed for experimentation; not yet implemented in the Java repository.

The Java application remains the only component allowed to query Folio/MSSQL.
The console expects two authenticated, read-only endpoints. They must not reserve
numbers, lock rows for update, write audit markers into Folio, or call stateful
stored procedures.

## List expense documents

```http
GET /admin/folio/fiscalization/expenses?dateFrom=2026-08-25&dateTo=2026-08-25
```

The initial candidate filter must be confirmed against the live Folio UI before
implementation. The working hypothesis is active `SCL_NAKL` rows with Cyrillic
`TYPE_DOC='Р'`; `STND_UCHET` is exposed but must not be treated as proof of
payment or fiscalization.

Example response:

```json
{
  "documents": [
    {
      "source_id": "751193",
      "document_number": "64471/0626",
      "document_date": "2026-08-25T09:35:00+03:00",
      "customer_display": "Обезличенный клиент",
      "warehouse_display": "Склад 7",
      "total_cents": 129050,
      "accounted": true,
      "return_document": false,
      "active": true,
      "operation_kind": "*РОЗНИЦА",
      "suggested_payment_type": "CASHLESS",
      "line_count": 2
    }
  ]
}
```

The list is a candidate queue only. `suggested_payment_type` is a suggestion from
a confirmed mapping and never replaces the operator's payment confirmation.

## Expense detail

```http
GET /admin/folio/fiscalization/expenses/751193
```

Example response:

```json
{
  "document": {
    "source_id": "751193",
    "document_number": "64471/0626",
    "document_date": "2026-08-25T09:35:00+03:00",
    "customer_display": "Обезличенный клиент",
    "warehouse_display": "Склад 7",
    "total_cents": 129050,
    "accounted": true,
    "return_document": false,
    "active": true,
    "operation_kind": "*РОЗНИЦА",
    "suggested_payment_type": "CASHLESS",
    "items": [
      {
        "sku": "TEST-1001",
        "name": "Товар",
        "price_cents": 41250,
        "quantity_thousandths": 2000,
        "line_total_cents": 82500,
        "tax_codes": [8]
      }
    ]
  }
}
```

## Required invariants

- `source_id` is the technical `SCL_NAKL.UNICUM_NUM`, serialized as a string;
  it is not the visible document number.
- Expense type uses Cyrillic `Р`, never Latin `P`.
- Monetary values are integer kopiykas; Java calculates them with `BigDecimal`.
- Quantity is an integer number of thousandths.
- Item totals equal `total_cents` exactly.
- Checkbox tax codes are supplied explicitly from an approved mapping; the
  console does not guess a default.
- Return documents are excluded from the sale flow.
- Payment confirmation and fiscal timing remain separate business decisions.
- Both endpoints require real HTTP authentication and authorization; the word
  `admin` in the path is not a security boundary.
- Responses and logs must not expose database credentials, Checkbox secrets, or
  unnecessary buyer contact data.
