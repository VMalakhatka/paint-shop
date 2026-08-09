# Lavka Product Media Upload

Operator-facing WordPress plugin for validating and uploading product image batches
through the standard WordPress Media Library pipeline.

## Location

After activation, open **Media > Product image batches**.

The operator must:

1. Select an XLS/XLSX registry and all referenced image files.
2. Confirm the legacy four-column mode when the registry has no role column.
3. Choose whether canonical filenames should be generated from SKU/barcode.
4. Run the mandatory check.
5. Review every error and warning.
6. Upload only the rows that passed the check.
7. Download the CSV audit report.

## Registry formats

Legacy XLS, without a header:

1. SKU
2. Expected image filename
3. Product name (informational)
4. Barcode

All legacy rows become `main` only after explicit operator confirmation.
The expected filename in column 2 is authoritative; the server lowercases it and uses
the actual validated image extension.

Header-based XLSX:

```text
sku | barcode | source_file | role | position
```

`role` is `main` or `gallery`. Gallery positions are positive integers. A WooCommerce
variation supports `main` only because standard WooCommerce has no variation gallery.

Identifiers are read as strings. Numeric cells that may lose precision, formulas and
scientific notation are rejected.

For header-based registries, automatic naming uses project business rules:

```text
P-296-010 main       -> p-296-010.jpg
P-296-010 gallery 1  -> p-296-010_2.jpg
P-296-010 gallery 2  -> p-296-010_3.jpg
Ж-AS-001 main        -> g-as-001.jpg
РСУ-94100610 main    -> rcy-94100610.jpg
```

Unknown non-ASCII SKU prefixes are rejected instead of guessed.

## Conflict sources

The check looks for canonical filename conflicts in:

- the WordPress Media Library via `_wp_attached_file` and attachment `guid`;
- `s3_media_index.filename_lower`;
- `s3_media_index.full_key`.

Both `s3_media_index` and a prefixed `${wpdb->prefix}s3_media_index` table are
supported. If neither table exists, the UI explicitly reports that S3 conflicts were
not checked.

Before checking a production batch, refresh Total Sync and the S3 media index.

Legacy WordPress filenames with an automatically added numeric suffix, such as
`product-1.jpg`, are reported as warnings when the approved canonical name is
`product.jpg`. They do not block the canonical upload and are never removed or
overwritten automatically.

## Upload phase

The uploader creates a server-side copy with the approved canonical filename and calls
WordPress `media_handle_upload()`. Active Media Cloud handling therefore receives the
original and generated sizes through the same path as a normal Media Library upload.
The plugin does not write directly to S3.

Uploading is available only after a successful dry run tied to the current operator,
registry hash and source file hashes. Define `LPMU_ENABLE_WRITES` as `false`, or return
`false` from `lavka_product_media_upload_enable_writes`, to place the plugin in
verification-only mode.

## Extension points

Barcode lookup supports the Java full-sync `_wc_gtin_code` field, the WooCommerce
`_global_unique_id` field and the legacy/plugin GTIN fields exposed through
`lavka_product_media_upload_barcode_meta_keys`.

- `lavka_product_media_upload_thresholds`
- `lavka_product_media_upload_capability`
- `lavka_product_media_upload_barcode_meta_keys`
- `lavka_product_media_upload_sku_prefix_map`
- `lavka_product_media_upload_s3_name_check`
- `lavka_product_media_upload_s3_check_available`
- `lavka_product_media_upload_malware_scan`
- `lavka_product_media_upload_malware_scan_available`
- `lavka_product_media_upload_visual_analysis`
- `lavka_product_media_upload_phash_available`
- `lavka_product_media_upload_should_verify_remote`
- `lavka_product_media_upload_after_upload`
- `lavka_product_media_upload_enable_writes`

The post-upload hook runs only after the attachment and remote object have passed
verification.

## Version 1 limits

Version 1 validates JPEG, PNG and WebP only and does not silently convert, rotate,
renumber, strip metadata or normalize color profiles. Animated PNG and WebP files are
rejected. EXIF orientation, GPS, ICC, CMYK, low contrast and excessive transparency are
reported as warnings where the local runtime can detect them.

ClamAV, perceptual hashing and expensive blur/crop analysis are not bundled. The plugin
exposes integration points and reports those capabilities only when another component
provides them.
