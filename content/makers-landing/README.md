# Makers landing content bundle

Ukrainian editorial content for the existing KREUL WordPress site. Operator guide,
publication state and rollback: [MAKERS_LANDING.md](../../docs/MAKERS_LANDING.md).
This is not a new theme or plugin and does not need plugin activation.

## Build

```sh
python3 content/makers-landing/build.py --email shop@paint.dn.ua
python3 -m http.server 8766 --bind 127.0.0.1 --directory content/makers-landing/preview
```

Open `http://127.0.0.1:8766/`. `preview/index.html` is noindex/nofollow and uses a
small preview-only header. The WordPress payload is `preview/wordpress-content.html`.
Generated preview/release data are ignored by Git.

To generate a payload using already verified Media Library URLs:

```sh
python3 content/makers-landing/build.py --email shop@paint.dn.ua \
  --asset-map /absolute/path/to/asset-map.json --output content/makers-landing/release
```

The JSON map uses asset stems as keys and HTTPS attachment URLs as values.
`build.py` performs no network, publishing or messaging actions.

## WordPress release

Copy `wordpress.php`, the built `wordpress-content.html`, `page-meta.json` and
`assets/` to a private release directory outside the document root.
Use the site's normal PHP 8.3 WP-CLI runtime and an authorized editor:

```text
wp --path=<production-root> --user=<editor-id> --skip-themes eval-file <bundle>/wordpress.php preview <bundle>
wp --path=<production-root> --user=<editor-id> --skip-themes eval-file <bundle>/wordpress.php prepare <bundle>
wp --path=<production-root> --user=<editor-id> --skip-themes eval-file <bundle>/wordpress.php verify <bundle>
```

Keep plugins loaded during media import so the configured Media Cloud integration
participates. `publish` is a separate explicit mode; inspect the actual preview
first. This helper only manages the owned page, never overwrites other content and
refuses to prepare an already published page. The explicit `update` mode requires
the exact current content SHA256 as a third argument and saves a private backup
of the page and its metadata before writing. Review the operator
guide for unknown outcomes and rollback before writing.

## Images supplied by the owner

All six photos were supplied for this landing in the 2026-11-03 request. Originals
are untouched. Website JPEGs are at most 1400 px on the long side and have no EXIF.
CSS uses object-fit; the files retain their full frame. Total size: about 971 KB.

| Website asset | Original supplied file |
|---|---|
| `polymer-miniatures.jpg` | `IMG_20250717_204937-01.jpeg` |
| `cernit-finishes.jpg` | `IMG_20250729_194613-01.jpeg` |
| `glass-and-contours.jpg` | `IMG-21bef27036fd495b70ff0670a9417de8-V.jpg` |
| `textile-painting.jpg` | `IMG-98981ae4ebbb6514728c853f6896462c-V.jpg` |
| `decorative-painting.jpg` | `IMG-b31da01c2d35b9de1e303638acd26190-V.jpg` |
| `ceramic-painting.jpg` | `IMG-c7692a9f1f2f461b52cd56b3beedb1bd-V.jpg` |

No analytics code, cookies, form storage, customer records, automatic discount
or subscription are introduced by the content. The primary CTA points to existing account registration/login and PCOE messages.
The fallback composes an email to shop@paint.dn.ua in the visitor's own mail
application; it does not send one.
