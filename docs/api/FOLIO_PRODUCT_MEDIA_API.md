# Folio Product Media API

## 1. Purpose

This contract defines an administrative Java API for reading and changing product image references in Folio.

The API is needed for two workflows:

1. Repair existing Folio image names when WooCommerce already has the correct attachment and the same object is present in the current OVH/S3 media index.
2. Write newly uploaded product images back to Folio after WordPress has validated and uploaded them to OVH/S3.

The first implementation must never guess an image or write a fuzzy-search result automatically. Search, preview and apply are separate operations.

## 2. Confirmed current data model

### 2.1 Main image

- Folio table: `dbo.ALL_ARTC`
- Product key: `COD_ARTIC`
- Main filename: `S50`
- Gallery grouping key: `PLUS_ARTIC`

Current Java reads the main image through `CardTovExportDaoImpl.findCardImagesBySku()`.

### 2.2 Gallery

- Folio table: `dbo.img_prod`
- Gallery grouping key: `PLUS_ARTIC`
- Filename: `image`
- Position: `sort_order`

Current Java reads gallery rows through `CardTovExportDaoImpl.findGalleryByPlusArtic()`.

### 2.3 OVH/S3 proof

Java can validate the target file against the WordPress MariaDB table `s3_media_index`:

- `filename_lower`
- `full_key`
- `size_bytes`
- `etag`
- `last_modified`

The value written to Folio is the filename only, not `full_key` and not an URL.

## 3. Required schema investigation before gallery writes

Before implementing `update_gallery` or `add_gallery`, inspect the real `dbo.img_prod` schema and record:

- primary key or another stable unique row identifier;
- nullability and maximum length of `image`;
- nullability and type of `sort_order`;
- unique constraints involving `PLUS_ARTIC`, `image` and `sort_order`;
- required columns without defaults;
- whether one `PLUS_ARTIC` can belong to several SKU values.

Do not identify a gallery row only by filename. The search response must return a stable `recordId`. If the table has no stable key, an update may use the composite precondition `PLUS_ARTIC + old filename + old sort_order`, but it must be rejected unless exactly one row would be affected.

Also confirm the real length and nullability of `ALL_ARTC.S50` before enabling writes.

### 3.1 Confirmed schema findings (2026-08-09)

The production Folio schema was inspected before enabling writes:

- `dbo.img_prod.id` is an `int IDENTITY`, non-null primary key;
- `dbo.img_prod.PLUS_ARTIC` is nullable `bigint`;
- `dbo.img_prod.image` is nullable `varchar(100)`;
- `dbo.img_prod.sort_order` is nullable `int`;
- `img_prod` has no additional unique constraints and has real duplicate combinations of `PLUS_ARTIC + image + sort_order`;
- there are no additional required `img_prod` columns without defaults;
- `dbo.ALL_ARTC.S50` is nullable `varchar(50)`;
- `dbo.ALL_ARTC.COD_ARTIC` is the primary key and `PLUS_ARTIC` currently resolves to one SKU.

Therefore gallery responses use `img_prod.id` as `recordId`. Updates use that ID plus optimistic checks for the old filename and sort order. Inserts explicitly provide only `PLUS_ARTIC`, `image` and `sort_order`; SQL Server generates `id`.

## 4. Search endpoint

```http
GET /admin/folio/product-media
```

### 4.1 Query parameters

| Parameter | Required | Description |
| --- | --- | --- |
| `sku` | conditional | Exact Folio SKU. At least `sku` or `filename` is required. |
| `filename` | conditional | Filename or legacy path to search for. |
| `role` | no | `main`, `gallery` or `all`; default `all`. |
| `match` | no | `exact` or `normalized`; default `exact`. |
| `limit` | no | Default 50, maximum 200. |
| `offset` | no | Default 0. |

When both `sku` and `filename` are supplied, both filters must match.

`normalized` is diagnostic only. It may compare basenames after lowercasing, removing a legacy `pic/` prefix and applying known separator normalization. A normalized result is never sufficient for a write without an explicit preview request containing the exact target filename.

### 4.2 Search requirements

- SKU search returns main and gallery rows separately.
- Filename search returns every Folio product/gallery row that references that filename.
- `role=main` searches only `ALL_ARTC.S50`.
- `role=gallery` searches only `img_prod.image`.
- The response must distinguish an exact match from a normalized match.
- For every result, Java checks whether the exact basename exists in `s3_media_index`.
- If the same basename has several S3 keys, return all keys and their ETag/size.
- Identical S3 duplicates are a warning. Same-name objects with different ETags are a blocking conflict.

### 4.3 Response

```json
{
  "ok": true,
  "query": {
    "sku": "РСУ-94100842R",
    "filename": "pcy-941008--r.jpg",
    "role": "all",
    "match": "normalized",
    "limit": 50,
    "offset": 0
  },
  "total": 1,
  "items": [
    {
      "role": "main",
      "sku": "РСУ-94100842R",
      "productName": "RR Пастель ...",
      "filename": "pcy-941008--r.jpg",
      "matchType": "normalized",
      "plusArtic": "...",
      "sortOrder": null,
      "recordId": {
        "table": "ALL_ARTC",
        "key": "РСУ-94100842R"
      },
      "s3": {
        "indexed": false,
        "matches": []
      }
    }
  ],
  "warnings": [],
  "errors": []
}
```

For a gallery row, `recordId` must contain the discovered stable `img_prod` row key.

## 5. Preview and apply endpoint

Use one request shape for preview and apply:

```http
POST /admin/folio/product-media/changes
Content-Type: application/json
```

`previewOnly=true` calculates the result without writing Folio. `previewOnly=false` applies accepted changes.

### 5.1 Request

```json
{
  "externalRequestId": "9c61545e-52c4-4c85-aa77-059bd45dcc8a",
  "previewOnly": true,
  "source": "woo_media_repair",
  "changes": [
    {
      "operation": "set_main",
      "sku": "РСУ-94100842R",
      "expectedOldFilename": "pcy-941008--r.jpg",
      "filename": "pcy-941008-r.jpg",
      "s3Proof": {
        "fullKey": "wp-content/uploads/2025/06/pcy-941008-r.jpg",
        "sizeBytes": 27962,
        "etag": "b47040deaae24962d3b8f7ec3e991e43"
      }
    },
    {
      "operation": "update_gallery",
      "sku": "ABC-001",
      "recordId": "12345",
      "expectedOldFilename": "old-name.jpg",
      "expectedOldSortOrder": 2,
      "filename": "abc-001_3.jpg",
      "sortOrder": 2,
      "s3Proof": {
        "fullKey": "wp-content/uploads/2026/08/abc-001_3.jpg",
        "sizeBytes": 45678,
        "etag": "..."
      }
    },
    {
      "operation": "add_gallery",
      "sku": "ABC-001",
      "filename": "abc-001_4.jpg",
      "sortOrder": null,
      "s3Proof": {
        "fullKey": "wp-content/uploads/2026/08/abc-001_4.jpg",
        "sizeBytes": 56789,
        "etag": "..."
      }
    }
  ]
}
```

### 5.2 Supported operations

| Operation | Behaviour |
| --- | --- |
| `set_main` | Update `ALL_ARTC.S50` for the exact SKU. This is an update of the singleton main image reference. |
| `update_gallery` | Update one existing `img_prod` row using its stable record ID and optimistic old-value checks. |
| `add_gallery` | Insert one `img_prod` row for the SKU's resolved `PLUS_ARTIC`. If `sortOrder` is null, Java chooses `max(sort_order) + 1`. |

Removal and complete gallery replacement are outside the first version.

### 5.3 Mandatory validation

For every change Java must:

1. Require a basename only. Reject URLs and paths after returning a clear normalized suggestion.
2. Resolve the exact SKU in Folio.
3. Verify the current value equals `expectedOldFilename` for updates.
4. Verify the exact `filename` exists in `s3_media_index`.
5. Verify `fullKey`, `sizeBytes` and normalized ETag against `s3Proof` when proof is provided.
6. Reject same-name S3 rows with different ETags.
7. Treat same-name rows with the same ETag and size as identical duplicates and return a warning.
8. Validate Folio column lengths before writing.
9. For gallery operations, resolve `PLUS_ARTIC`; reject the change if it is empty or ambiguous.
10. Reject `update_gallery` unless exactly one row matches the record ID and expected old values.
11. Return `noop` if Folio already contains the requested value.

### 5.4 Response

```json
{
  "ok": true,
  "previewOnly": true,
  "externalRequestId": "9c61545e-52c4-4c85-aa77-059bd45dcc8a",
  "summary": {
    "requested": 1,
    "ready": 1,
    "noop": 0,
    "blocked": 0,
    "applied": 0
  },
  "results": [
    {
      "index": 0,
      "operation": "set_main",
      "status": "ready",
      "role": "main",
      "sku": "РСУ-94100842R",
      "recordId": {
        "table": "ALL_ARTC",
        "key": "РСУ-94100842R"
      },
      "before": {
        "filename": "pcy-941008--r.jpg",
        "sortOrder": null
      },
      "after": {
        "filename": "pcy-941008-r.jpg",
        "sortOrder": null
      },
      "s3Matches": [
        {
          "fullKey": "wp-content/uploads/2025/06/pcy-941008-r.jpg",
          "sizeBytes": 27962,
          "etag": "b47040deaae24962d3b8f7ec3e991e43"
        },
        {
          "fullKey": "wp-content/uploads/2026/07/pcy-941008-r.jpg",
          "sizeBytes": 27962,
          "etag": "b47040deaae24962d3b8f7ec3e991e43"
        }
      ],
      "warnings": [
        {
          "code": "IDENTICAL_S3_DUPLICATES",
          "message": "Several OVH/S3 objects have the same basename and identical content."
        }
      ],
      "errors": []
    }
  ],
  "warnings": [],
  "errors": []
}
```

For a successful apply, item status is `applied`; for an unchanged value it is `noop`; unsafe changes are `blocked` with structured errors.

## 6. Error codes

| Code | Meaning |
| --- | --- |
| `SKU_NOT_FOUND` | SKU does not exist in the Folio product source. |
| `MAIN_RECORD_NOT_FOUND` | `ALL_ARTC` row cannot be resolved safely. |
| `GALLERY_RECORD_NOT_FOUND` | Requested `img_prod` row does not exist. |
| `GALLERY_RECORD_AMBIGUOUS` | More than one gallery row matches the supplied identity/preconditions. |
| `PLUS_ARTIC_REQUIRED` | Gallery grouping key is empty or cannot be resolved. |
| `OLD_VALUE_CHANGED` | Folio changed after preview; caller must preview again. |
| `S3_FILE_NOT_INDEXED` | Exact target filename is not present in `s3_media_index`. |
| `S3_PROOF_CHANGED` | S3 key, size or ETag differs from the supplied proof. |
| `S3_FILENAME_CONFLICT` | Same basename points to different content. |
| `FILENAME_TOO_LONG` | Filename does not fit the confirmed Folio column. |
| `INVALID_FILENAME` | Value is a path/URL or otherwise invalid for the Folio filename field. |
| `DUPLICATE_GALLERY_ITEM` | The same filename is already present in the gallery. |

## 7. Transactions and idempotency

- `externalRequestId` is required for apply.
- Repeating the same successful apply request must return the stored result and must not insert a second gallery row.
- Apply should use one MSSQL transaction per SKU. One failing SKU must not leave a partially changed gallery.
- Preview never writes Folio, WordPress or S3.
- Java must log only the request ID, operation, SKU, role, before/after and result. Do not emit large duplicate payloads in normal logs.

## 8. WordPress repair workflow

The WordPress report builds a proposed correction only when all of the following are true:

1. Java logged a missing Folio filename for a known SKU and role.
2. WooCommerce already links a live attachment for that role.
3. The attachment's `_wp_attached_file` basename exists in `s3_media_index`.
4. If several S3 objects share the basename, their ETag and size are identical.
5. For gallery, the attachment can be matched to one Folio gallery row without ambiguity.

The operator reviews the report, sends a preview request, and only then applies selected corrections.

Do not delete duplicate WordPress attachments or S3 objects as part of this workflow.

## 9. New upload workflow

1. WordPress validates the register and image files.
2. WordPress creates an unattached Media Library record through `media_handle_upload()`;
   Media Cloud uploads the original and generated sizes through its standard hooks.
   Large-image scaling is disabled for this call so the approved original basename is
   preserved; registered WordPress thumbnail sizes are still generated.
3. WordPress verifies the attachment metadata and public remote object.
4. Java refreshes the OVH/S3 index once for the approved batch. If an uploaded object is
   not visible yet, WordPress waits briefly and requests one bounded second refresh only
   for the rows still awaiting exact proof.
5. WordPress reads the exact basename, full key, size and ETag from the refreshed index.
6. WordPress searches the current Folio main/gallery references for the exact SKU.
7. WordPress sends `set_main`, `update_gallery` or `add_gallery` with
   `previewOnly=true`.
8. If every result is `ready` or `noop`, WordPress sends the identical changes with
   `previewOnly=false` and the same `externalRequestId`.
9. Only after apply returns `applied` or `noop` does WordPress assign the attachment to
   the WooCommerce product and set the attachment parent.
10. Existing media synchronization may be rerun as a separate reconciliation check.

This automatic path is enabled only by the uploader's explicit second confirmation
after its mandatory dry run. It runs under the Lavka ecosystem global lock. Failures
remain resumable and never permit WordPress to invent a suffixed filename.
If the shared lock API is unavailable, WordPress keeps verification available but
blocks the upload before creating an attachment.

## 10. Acceptance tests

1. Search by SKU returns main and gallery separately.
2. Search by filename returns all referencing SKUs and roles.
3. Role filters never mix `ALL_ARTC.S50` with `img_prod.image`.
4. Preview of `pcy-941008--r.jpg` to `pcy-941008-r.jpg` reports the two identical S3 objects without blocking.
5. Apply changes only the Folio filename and is idempotent.
6. Changed old value after preview produces `OLD_VALUE_CHANGED` and no write.
7. Two same-name S3 objects with different ETags produce `S3_FILENAME_CONFLICT`.
8. Adding a gallery image assigns the requested position or `max + 1` exactly once.
9. Gallery update cannot affect more than one row.
10. Dry preview produces no MSSQL, MariaDB, WooCommerce or S3 changes.

## 11. WordPress frontend integration rules

The WordPress admin must call Java through a server-side authenticated proxy. The browser must never receive the Java API token and must not construct a trusted `s3Proof` itself.

For the mismatch repair report, WordPress follows these rules:

1. One report row and one selected S3 object produce one Java change. Repairs are not combined across SKU values.
2. WordPress re-reads the exact `filename_lower + full_key` row from `s3_media_index` and builds `s3Proof` from its current `full_key`, `size_bytes` and `etag`.
3. WordPress searches the current Folio row before preview. `match=normalized` is used only to locate the historical reference; the target `filename` is the exact basename from `s3_media_index`.
4. A main-image repair uses `set_main` and the current search result as `expectedOldFilename`.
5. A gallery repair uses `update_gallery`, `recordId.key`, `expectedOldFilename`, `expectedOldSortOrder` and the unchanged current `sortOrder` from the search result.
6. Zero or multiple matching Folio rows block the repair. WordPress never guesses a gallery row.
7. Preview and apply are separate admin actions. Apply is offered only after a one-change preview returns top-level `ok=true` and item status `ready`.
8. WordPress stores the exact apply payload server-side for 30 minutes and gives the browser only an opaque token. Apply reads that stored payload, so browser data cannot change after preview.
9. The same stored `externalRequestId` and request content are reused when an apply is retried.
10. Apply runs under the Lavka ecosystem global lock and writes a compact `folio_media_repair` entry to the Total Sync log.
11. HTTP 200 is not treated as success by itself. WordPress checks top-level `ok` and the item status. `blocked` is shown as a refusal, `noop` as already correct, and only `applied` or `noop` complete an apply successfully.

The package uploader uses the same authenticated server-side proxy and response rules.
For each exact SKU it always performs preview before apply, reuses one deterministic
request ID, and treats `blocked` or top-level `ok=false` as a failed partial workflow.
