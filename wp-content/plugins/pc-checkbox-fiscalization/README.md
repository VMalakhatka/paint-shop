# PC Checkbox Fiscalization

Universal, caller-agnostic Checkbox.ua fiscalization executor for WordPress.

The plugin does not inspect WooCommerce, WayForPay, Folio, or any other source to decide what must be fiscalized. A trusted caller supplies either:

1. a complete canonical fiscalization command; or
2. an explicit `source_type` and `source_id`, which the plugin uses to fetch that same command from a configured Java endpoint.

## Safety defaults

- `preview` performs local schema, totals, and idempotency validation only.
- `validate` calls the non-fiscal Checkbox validation endpoint.
- `fiscalize` is locked unless `PC_CHECKBOX_ALLOW_FISCALIZATION` is exactly `true`.
- A register that cannot be proven to be a test register is locked unless `PC_CHECKBOX_ALLOW_LIVE` is exactly `true`.
- The default shift policy requires an already-opened shift. The plugin never closes shifts.
- Ambiguous network/5xx results become `UNCERTAIN` and must be reconciled; they are never blindly resent.
- Secrets and complete fiscal payloads are not stored in WordPress options or in the operation journal.

## Secret configuration

Define secrets in an ignored environment-specific configuration file or as environment variables:

```php
define('PC_CHECKBOX_LICENSE_KEY', '...');
define('PC_CHECKBOX_CASHIER_PIN', '...');
define('PC_CHECKBOX_ACCESS_KEY', '...'); // optional
define('PC_CHECKBOX_INBOUND_TOKEN', 'long-random-token');
define('PC_CHECKBOX_JAVA_TOKEN', 'long-random-token'); // optional

define('PC_CHECKBOX_ALLOW_FISCALIZATION', false);
define('PC_CHECKBOX_ALLOW_LIVE', false);
```

The Checkbox license key selects the cash register; the cashier PIN authenticates the cashier. The settings page only reports whether each secret exists and never displays its value.

## REST API

External callers send `X-PC-Checkbox-Token`. Authenticated WordPress users with `manage_pc_checkbox_fiscalization` may call the API without that header.

```text
POST /wp-json/pc-checkbox/v1/fiscalize
POST /wp-json/pc-checkbox/v1/fiscalize-source
POST /wp-json/pc-checkbox/v1/reconcile
GET  /wp-json/pc-checkbox/v1/operation?operation_key=...
```

Full command request:

```json
{
  "mode": "preview",
  "command": { "schema_version": "1.0", "...": "see docs/JAVA_ENDPOINT_CONTRACT.md" }
}
```

Explicit Java-source request:

```json
{
  "mode": "preview",
  "source_type": "expense",
  "source_id": "123456"
}
```

## PHP API

```php
$result = pc_checkbox_fiscalize($command, 'preview');
```

Callers should first use `preview`, then `validate`, and only then request `fiscalize` after the test register and shift are confirmed.

See [docs/JAVA_ENDPOINT_CONTRACT.md](docs/JAVA_ENDPOINT_CONTRACT.md) for the complete v1 contract.
