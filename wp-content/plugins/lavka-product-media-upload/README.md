# Lavka Product Media Upload

Operator-facing WordPress plugin for validating product image batches and completing
the controlled Media Library, OVH/S3, Folio and WooCommerce workflow.

## Location

After activation, open **Media > Product image batches**.

The operator must:

1. Select an XLS/XLSX registry and all referenced image files, or choose their folder.
2. Confirm the legacy four-column mode when the registry has no role column.
3. Choose whether canonical filenames should be generated from SKU/barcode.
4. Run the mandatory check.
5. Review every error and warning.
6. Confirm the full upload and synchronization operation for rows that passed.
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

The check looks for canonical filenames in:

- the WordPress Media Library via `_wp_attached_file` and attachment `guid`;
- `s3_media_index.filename_lower`;
- `s3_media_index.full_key`.

An existing exact image is reused only when WordPress has a valid attachment and
the selected source content is proven identical. A shared source image referenced by
several registry rows is validated and uploaded once, then the same attachment is
assigned to each listed product and synchronized to every exact Folio SKU.

An S3 object without a matching WordPress attachment is blocked. WooCommerce stores an
attachment ID rather than an arbitrary image URL, so reconstructing missing attachment
metadata is intentionally outside the automatic reuse path.

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
WordPress `media_handle_sideload()`. The original file on the operator's computer is not
renamed or modified. Active Media Cloud handling therefore receives the original and
generated sizes through the same path as a normal Media Library upload. The plugin does
not write directly to S3. WordPress large-image scaling is disabled only for this upload
call so the original keeps the approved basename; normal registered thumbnail sizes are
still generated.

After all approved files have passed WordPress metadata and remote-object verification,
the plugin performs this sequence under the shared Lavka ecosystem lock:

1. Refresh the Java OVH/S3 media index once for the batch. If an uploaded object has not
   appeared yet, wait briefly and perform one bounded second refresh for the pending rows.
2. Read the exact `filename_lower + full_key` object proof from `s3_media_index`.
3. Read the current main and gallery references for each exact Folio SKU.
4. Build `set_main`, `update_gallery` or `add_gallery` changes.
5. Send a mandatory `previewOnly=true` request.
6. Apply the identical request with `previewOnly=false` and the same deterministic
   `externalRequestId`.
7. Assign the accepted attachment as the WooCommerce main/gallery image and synchronize
   its Media Library parent.
8. Save a CSV audit report and a compact shared Lavka event.

The Folio step is atomic per SKU. A failure for one SKU does not guess or overwrite a
different row. An attachment from an interrupted operation remains marked as partial;
running the same files through the mandatory check again resumes that attachment instead
of creating a `-1` duplicate. If Folio was applied but the response was lost, the next
exact search resolves the operation as already correct before Woo assignment.

For a shared attachment, the first registry row determines both the canonical filename
and the deterministic Media Library parent. The attachment records that parent in
`_lpmu_primary_product_id`, all Woo assignments in `_lpmu_assignments`, and the
`_lpmu_first_used_at` / `_lpmu_last_used_at` timestamps for a future orphan/retention
report. A future full-media audit should refresh `_lpmu_last_used_at` for every observed
relationship and treat only old, unobserved attachments as cleanup candidates. TODO:
replace the first-row fallback parent with the Folio variable-product parent when that
relationship is exposed by the product media API.

Replacing different image content under an existing canonical filename is never done
silently. A future explicit replacement mode must create a controlled versioned name,
preview the Folio change and retain the old object until the new assignment is verified.

Folder selection is a browser convenience and remains subject to PHP's
`max_file_uploads` and request-size limits. Split a large folder into smaller batches.
If the shared Lavka lock API is unavailable, verification remains available but the
upload cycle is blocked before any file is written.

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
verification, Folio has accepted the exact reference, and WooCommerce assignment has
succeeded.

## Version 1 limits

Version 1 validates JPEG, PNG and WebP only and does not silently convert, rotate,
renumber, strip metadata or normalize color profiles. Animated PNG and WebP files are
rejected. EXIF orientation, GPS, ICC, CMYK, low contrast and excessive transparency are
reported as warnings where the local runtime can detect them.

ClamAV, perceptual hashing and expensive blur/crop analysis are not bundled. The plugin
exposes integration points and reports those capabilities only when another component
provides them.

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

## Supplier catalogues (0.4.0)

Owner: this plugin. `SupplierFeed` streams bounded XML with XMLReader/DOM; fields
are plain element paths, not XPath. Entities/custom DTDs are rejected. The exact
inert YML `shops.dtd` declaration is accepted with DTD loading/substitution disabled.
`SupplierCatalog` owns the Media → Supplier catalogues admin page and staging only.
Human workflow: [media manager guide](../../../docs/MEDIA_MANAGER_GUIDE_UK.md#каталоги-постачальників-xml-і-папки-google-drive).

Capability: existing `lavka_product_media_upload_capability` + `upload_files`.
Configuring sources additionally requires `manage_options`. AJAX and image proxy
requests require nonces. Source URLs are not returned to non-admins, except Drive
folder links. No anonymous routes. Browser previews/downloads use an authenticated
bounded server proxy. HTTP(S) destinations and each redirect must resolve to public
addresses; credential headers are never forwarded on redirects. Photos are limited
to JPEG/PNG/WebP and 10 MiB (or a lower validator limit). Temporary downloads are
removed after each request; there is no permanent mirror of the supplier media bank.

State/lifecycle:

- `lpmu_supplier_sources`: non-autoloaded private source configuration, XML mapping,
  SKU-match opt-in and daily flag. Do not commit personal feed URLs or credentials.
- `{prefix}lpmu_supplier_items`: separate catalogue snapshots with generation,
  external ID hash, reference metadata, match projection and manual SKU. Created
  lazily on an authenticated catalogue AJAX call with `dbDelta`; schema version
  `lpmu_supplier_schema=1`. No product tables are altered.
- Per-source `lpmu_supplier_active_*`, `lpmu_supplier_status_*`,
  `lpmu_supplier_import_*`: non-autoloaded snapshot, progress and 15-minute import
  lease. A failed import (including a failed active-pointer write) retains the old active snapshot; successful import removes
  superseded staging rows. Match filters are projections from the last import;
  registry creation rechecks the live exact match. Manual corrections are retained
  across consecutive snapshots with the same supplier ID.
- Optional `lpmu_supplier_daily` WP-Cron event refreshes staging only. Disabled by
  default; saved configuration controls scheduling. Deactivation unschedules events,
  retains configuration/table/media. Re-save a source to restore its schedule after
  reactivation. Status is visible on the catalogue page; no automatic notifications.
- `LPMU_GOOGLE_DRIVE_API_KEY`: optional production/local config constant outside Git,
  server only; restrict to Drive API and server usage. Drive reader paginates public
  folder metadata recursively, bounded to 200 folders/10,000 image/video files.
  Access/resource-key restrictions can require supplier assistance. No OAuth flow,
  private Drive account access, video ingestion or video product assignment.

Selecting up to min(20, `max_file_uploads` - 1) photos downloads validated MIME blobs
into the browser, generates a text-typed XLSX with **our** exact SKUs, then populates
the existing uploader inputs. The original multipart upload checks, fingerprint,
lock, S3 proof, Folio preview/apply and Woo workflow are unchanged. There is no
trusted-local-file bypass and no direct remote-URL product assignment. A manager
must run the existing dry run and confirm the full upload. Reference prices,
descriptions/attributes are displayed as text and never written to products.

Deploy with plugin assets/translations together (already in the deploy manifest).
No reactivation is required. First admin use creates the staging table; configure
sources in the UI or an administrator-only provisioning step. Back up plugin files
and source options/table before upgrade. Rollback restores plugin files together and
clears `lpmu_supplier_daily` scheduled hooks; keep staged data for investigation.
Do not uninstall/delete media or revert product/Folio data as part of this rollback.

Validation:

```sh
php wp-content/plugins/lavka-product-media-upload/tests/supplier-feed-test.php
# Optionally pass a local XML path outside Git for a real-feed parse.
# Local/development WordPress only, with Woo + uploader dependencies loaded:
wp eval-file wp-content/plugins/lavka-product-media-upload/tests/supplier-wordpress-test.php
```

The WP integration test only creates temporary catalogue rows/options, removes them
in `finally`, checks registry generation and snapshot recovery, and never calls the
media upload/apply endpoint. The schema table remains installed locally.
Artizo actual schema and Drive real API reading are unverified until access is
provided; a failed HTTP response is not an imported catalogue. See the dated human
guide for acceptance evidence and source-specific limitations.
