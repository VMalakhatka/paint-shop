# Lavka Workshops

Photo schedule at `/master-klasy/`, visual workshop articles at `/master-klas/<slug>/`,
repeatable date rows and private booking requests. No Woo orders, payments, Folio
calls, email/SMS sending or automatic seat reservation. Manual confirmation is explicit
in both public and instructor UI.

Operator guide: [Мастер-классы: расписание, статьи и заявки](../../../docs/WORKSHOPS_GUIDE_RU.md).

## Ownership and storage

- `lavka_workshop`: public CPT, five standard visual article modules, featured image,
  excerpt and content revisions. `inc/article-editor.php` uses core `wp_editor` with
  visual mode, media and example buttons; no additional editor plugin is needed.
  The modules compose a single canonical `post_content` with section comments;
  there is no parallel article metadata. Normal WP revisions restore the whole article.
  Known v1 headings are split read-only; unknown/wrapped layouts remain intact in
  the first field. Saving needs capability, nonce and complete-form checks. Examples
  fill empty sections only, and empty sections are omitted from the public article.
  A hidden core content field is synchronized for WP autosave/preview.
  `_lw_sessions` is a validated array with stable date UUIDs,
  Kyiv timestamps, city (`kyiv`/`odesa`), address, UAH price, duration and state.
  Article revisions do not restore schedule metadata.
- `lavka_mk_request`: non-public CPT, forced private status, no REST exposure or
  public search. `_lw_workshop`, `_lw_session` (immutable submitted-date snapshot),
  `_lw_name`, `_lw_phone`, `_lw_status`, `_lw_consent`, `_lw_hash`.
- `lavka_instructor`: custom capability family for workshops and requests, plus
  `read` and `upload_files`. Can manage all MK and requests, no store/user settings.
  Administrators receive these capabilities on activation; existing roles are not
  assigned to users automatically. Deactivation preserves data and role/caps.
  Woo admin guard permits the custom editorial capability; regular guests/customers
  remain restricted. The Rank Math classic React mount is dequeued only on MK admin
  screens for non-administrators, whose SEO metabox is unavailable.
- Public request AJAX/admin-post validates publish state, nonce, consent, session,
  phone/name and honeypot. JS fetches a fresh nonce on submission for cached pages.
  No-JS form posts normally; exclude articles from long-lived HTML caching for it.
- Dedupe is HMAC(workshop, session UUID, normalized phone), including requests in
  trash. Atomic non-autoload option claim serializes concurrent submissions; claims
  older than 60 seconds are reclaimed. Failed network response may be retried.
  Same phone for multiple people requires manual coordination with the instructor.
- Best-effort per-IP throttle: 10 new requests / 10 minutes. No proxy-header trust.
  At very high traffic use perimeter rate limiting. PII is never in URLs or logs.
- Archive flattens future session metadata and paginates 12 dates chronologically,
  with city/month filters. Intended for a small studio schedule; if thousands of
  workshop articles accumulate, migrate session queries to indexed storage.
- Standard Media Library attachment rendering; no storage overrides in plugin code.
  English msgids plus complete Ukrainian/Russian PO/MO catalogs.

## Lifecycle

Git allow-list registered; deploy manifest policy `manual`. Local activation verified
2026-09-25. No secrets/options required. Production deployment, dry-run, activation,
role assignment, real content/photos and menu link are pending. Activation refreshes
rewrite rules and capabilities only. No automatic demo seeding or database export.
Deployment source and rollback details are in the operator guide; deactivate to
remove routes without deleting data. No uninstall data deletion handler.

## Verification

```sh
find wp-content/plugins/lavka-workshops -name '*.php' -print0 | xargs -0 -n1 php -l
node --check wp-content/plugins/lavka-workshops/assets/admin.js
node --check wp-content/plugins/lavka-workshops/assets/public.js
node --check wp-content/plugins/lavka-workshops/assets/article-editor.js
wp eval-file wp-content/plugins/lavka-workshops/tests/wordpress.php --skip-plugins --skip-themes
wp eval-file wp-content/plugins/lavka-workshops/tests/article-editor.php --skip-plugins --skip-themes
python3 .agents/skills/lavka-project-documentation/scripts/check-documentation.py --working-tree
```

The integration test is limited to `paint.local`, creates its own synthetic posts
and user, and cleans them in `finally`. It checks malformed dates, Kyiv timezone,
consent/nonce, dedupe, request snapshots/privacy, full/past/cancelled/draft rejection,
role boundaries and unauthorized save. Run after local activation so role exists.
The article test covers legacy preservation, section round trips, sanitization,
photos/shortcodes, authenticated saving, incomplete forms and revision restoration.
Browser acceptance must additionally check real full-plugin runtime, classic editor,
copy-date/save workflow, 1365px desktop and 390px mobile, filtering, actual AJAX
success and error feedback. Test data never represents real enrollment.

Verified locally on 2026-09-25: 35 integration checks passed; browser checks at
1365×950 and 390×844 passed for schedule filters, cover rendering, article, successful
AJAX request, invalid-phone feedback with retry, instructor copy-date/save and
request status save. Synthetic QA account and request were removed after acceptance.

Version 1.1 acceptance (local, 2026-09-25): 24 article checks and 35 booking checks
passed. Instructor UI verified visual formatting despite `rich_editing=false`,
example insertion and non-overwrite, save/reopen and public rendering, existing
demo decomposition without rewriting source, and narrow admin layout (553px CSS
viewport). Both draft and published preview paths are covered by the integration
test. Browser popup navigation and local-backup restore still need a manual check
in the operator's normal browser. Administrator UI was not separately exercised;
elevation of the disposable QA account was denied by automatic approval review.
No production deployment; temporary QA account and article removed.
