# Lavka Workshops

Photo schedule at `/master-klasy/`, visual workshop articles at `/master-klas/<slug>/`,
repeatable date rows and private booking requests. No Woo orders, payments, Folio
calls, email/SMS sending or automatic seat reservation. Manual confirmation is explicit
in both public and instructor UI.

Operator guide: [Мастер-классы: расписание, статьи и заявки](../../../docs/WORKSHOPS_GUIDE_RU.md).

## Ownership and storage

- `lavka_workshop`: public CPT with core Gutenberg, featured image, excerpt and
  content revisions. `inc/block-editor.php` configures only this editor;
  `inc/patterns.php` provides three starters and seven independent compositions.
  All layouts use core blocks in canonical `post_content`; no article metadata,
  external builder, license key, account or paid dependency is needed.
  Public article REST is enabled for Gutenberg with the existing CPT capabilities;
  private booking requests remain out of REST, and `_lw_sessions` is not exposed.
  Legacy HTML is preserved on open. Explicit client-side conversion uses WordPress
  rawHandler and normal undo/save; no bulk migration or automatic rewrite.
  Shared `publication.css` styles editor/public content. Block articles use a wide
  canvas with booking below; legacy HTML retains the previous article/sidebar layout.
  `_lw_sessions` remains validated metadata with stable date UUIDs, Kyiv timestamps,
  city (`kyiv`/`odesa`), address, UAH price, duration and state. Dates remain a standard
  compatible meta box; Gutenberg saves it through the core meta-box form.
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
node --check wp-content/plugins/lavka-workshops/assets/block-editor.js
wp eval-file wp-content/plugins/lavka-workshops/tests/wordpress.php --skip-plugins --skip-themes
wp eval-file wp-content/plugins/lavka-workshops/tests/block-editor.php --skip-plugins --skip-themes
python3 .agents/skills/lavka-project-documentation/scripts/check-documentation.py --working-tree
```

The integration test is limited to `paint.local`, creates its own synthetic posts
and user, and cleans them in `finally`. It checks malformed dates, Kyiv timezone,
consent/nonce, dedupe, request snapshots/privacy, full/past/cancelled/draft rejection,
role boundaries and unauthorized save. Run after local activation so role exists.
The block-editor test covers pattern parser round trips, Gutenberg activation,
REST saving/revisions, preservation of schedule metadata and guest/private-data
boundaries. Browser acceptance also checks core client block validation, text editing,
layout insertion and legacy conversion, media, meta-box save and public rendering.

Version 2.0 local acceptance (2026-09-26): 46 Gutenberg and 35 booking checks pass.
Chrome verified all ten patterns without invalid-block warnings, direct text edits,
article/date save together, and legacy conversion with undo/redo and preserved bold
text/headings. Public demo `/master-klas/demo-gutenberg/` verified at desktop width
and 390 px mobile; the editor shows the same content styles. The Codex in-app browser
left the Gutenberg iframe canvas blank; use Chrome for the authoring workflow.
The local demo is not deployed or seeded automatically.
Production deployment/activation remains pending. Gutenberg uses the WordPress core
already installed; do not install the separate experimental Gutenberg plugin.

Templately's cloud-import editor assets are dequeued only on workshop screens;
the plugin remains available elsewhere. The existing non-admin Rank Math classic
mount exception stays in effect. Unrelated admin/SEO tools retain their permissions.
