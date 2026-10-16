# PC Checkbox Fiscalization

## Manual shift opening and diagnostics (0.1.1)

In settings, **Preview shift opening** reads the configured cashier, organization,
register, test/live mode, current shift and last attempt. **Confirm opening / check
saved attempt** requires a fresh five-minute preview, the same logged-in manager,
nonce and `manage_pc_checkbox_fiscalization`. Recheck the displayed identity before
confirming: live opening is a real fiscal action. Both fiscalization/live safety
constants apply. Preview and connection test never open shifts. No sale or closing
is performed by this button.

Manual and automatic (`open_if_missing`) opening share a per-register DB lock and
durable `pccf_shift_<license-hash>` option journal (no secret/payload). UUID is saved
before POST. Timeout, crash or pending response is resolved only by GET of that UUID,
including from another order. HTTP 404 does not authorize another opening. OPENED
and register identity must be confirmed. A shift on another register blocks execution.
Definite rejection may be retried after correcting the cause; a pending attempt
ending CLOSED requires support review, not journal deletion or blind reopening.

Diagnostics show safe stage, HTTP, code, message and shift ID. For support collect
these values and UTC time, never keys/raw responses. A CLOSED attempt includes the
opening transaction's `response_error_message` when provided. Pre-receipt errors
are also saved in recent operations and the WayForPay order panel. Old errors
cannot be expanded retroactively if the original message was not stored.

Deploy both 0.1.1 plugins and translations together, then preview only. Live opening
and resuming a paid sale require separate operator confirmation. Do not replay
historical orders or issue a new sale to test diagnostics. After OPENED, resume the
existing Woo operation; verify unchanged UUID, fiscal status and email separately.
For rollback pause the adapter, reconcile pending openings/receipts and preserve
journals; do not downgrade with unknown opening outcomes.

Offline checks: `tests/executor-safety.php` covers shared/manual opening, persistence,
wrong register, locks and unknown outcomes; `tests/api-diagnostics.php` covers safe
error parsing. Real opening, browser UI and email delivery remain acceptance checks.

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
- Execute/reconcile hold a MySQL connection-scoped lock per operation. A competing request receives `operation_busy`; failure to persist PROCESSING prevents sending. Do not remove operation records to retry.
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

The separate [WayForPay adapter](../pc-wayforpay-checkbox/README.md) supplies explicit
commands after a verified payment; it does not move payment policy into this executor.
Local regression checks: `php tests/executor-safety.php` and
`php -d zend.assertions=1 -d assert.exception=1 tests/command-validator-smoke.php`
from this plugin directory. The first uses offline API/SQL boundaries.

## Admin navigation

With paint-core active, open **Лавка → Лавка: налаштування → Лавка: фіскалізація Checkbox**. The page slug and `manage_pc_checkbox_fiscalization` capability are unchanged. Without paint-core the page remains under WooCommerce, or Tools if WooCommerce is unavailable. Navigation updated in local code on 2026-09-08; production requires deployment.
