# Lavka Reports — monthly Folio profit

Frontend version 0.3.1. Verified against local code and the synthetic Java DTO
fixture on 2026-09-08; this does not assert a production deployment.

## Ownership and contract

Java owns profit, currency conversion, classifications, period resolution and
expense allocation. PHP is an authenticated read-only proxy; JS displays the
returned values without reconstructing expense totals from payments.

The admin page requires `manage_woocommerce` and the existing report nonce.
Summary and audit call `/admin/folio/profit-report` and `/admin/folio/profit-report/audit`
with the same report parameters. Optional `kyivAdditionalSalary` joins the existing
Odesa salary, tax share and RUB rate parameters. Blank salary omits the override;
explicit `0` is sent. Month changes reset both salary overrides. Unsupported Kyiv
salary is disabled when an old API omits the corresponding input.

The current Java contract is maintained in `kreul_com_ua/docs/api/FOLIO_PROFIT_REPORT_API.md`.
The cross-project request is [the backend reconciliation task](../../../docs/api/FOLIO_PROFIT_REPORT_RECONCILIATION_BACKEND_TASK.md).

- `expenseLines` drives individual expense rows, including zero/manual rows.
  `filters` supplies short codes, operation types, whether the operation is required,
  purpose codes, separate cash/bank warehouse restrictions and notes. With an old
  API, `expenses` is displayed with a missing-detail notice; JS never guesses splits.
- All ordinary `documents` fields are displayed, including stable payment ID,
  original currency/rate, resolved period, safe period markers, per-city allocation,
  expense row IDs and classification reasons. Future extra fields remain visible.
- `periodDiagnostics[].document` is flattened into a separate register alongside
  its status, inclusion and amount treatment. Ordinary documents are not duplicated.
  An outside-month candidate is an expected exclusion; problematic included amounts
  remain explicitly provisional. Diagnostic controls cover the full selection even
  if the register is truncated.
- Inventory is informational, with city totals and warehouse detail; the UI does
  not add it to profit. Global controls are not filtered by the city switch.
- Result month is explicit. Changing parameters marks the result stale and disables
  audit/export until recalculation. Late responses cannot replace a different form
  revision. Audit success updates summary and audit together from one response.
- `calculatedAt` supports ISO dates and legacy Unix seconds/milliseconds, displayed
  in `Europe/Kyiv` time. An absent monetary field displays `—`, not zero.

## Excel and CSV

**Export report to Excel** downloads a real `.xlsx` in the browser. It first loads
missing audit data. The workbook uses exactly that audit response for all sheets:
Kyiv, Odesa, city profit, applied parameters, controls, warnings, inventory,
ordinary payments, period checks, master class totals/invoices and all expense rows.
City filters and audit filters do not limit XLSX. The separate CSV button exports
only the filtered ordinary payment register, with all displayed audit fields.

Every XLSX sheet identifies the report month and calculation time. Incomplete and
truncated data stays clearly marked; an exported audit is not a promise that all
Folio documents were returned. The API's NOLOCK/concurrent-change caveat still applies.
The generated export is independent from the historical three-source comparison
book; it does not populate old-Excel/new-manual-export columns with invented values.

`profit-xlsx.js` writes OOXML/ZIP without external libraries, requests or uploaded
files. Amounts/counts are numeric cells; identifiers and text are literal strings.
Values exceeding Excel's 15-digit numeric precision remain exact text. No formulas,
macros, external links or executable document content are created. CSV neutralizes
formula prefixes. No downloaded file or raw API response is saved to WordPress.

## Verification

Run PHP syntax checks, JS syntax checks, gettext format checks, and:

```sh
php wp-content/plugins/lavka-reports/tests/profit-proxy.php
node --test wp-content/plugins/lavka-reports/tests/profit-report.spec.cjs
python3 wp-content/plugins/lavka-reports/tests/check-profit-xlsx.py /path/to/test/output
```

Browser tests require Playwright resolvable in `NODE_PATH`, PHP in PATH (or `PHP_BIN`),
and an installed Playwright Chromium or `CHROME_BIN` pointing to an installed Chrome.
Use `PROFIT_TEST_OUTPUT` for output. Tests render PHP with isolated stubs and intercept
all report requests. `tests/fixtures/profit-report.json` is synthetic output from
Java's service test, not a live report. The Python check requires openpyxl.

Coverage: 32-row Java contract, legacy fallback, explicit zero, all-city Excel after
filtering, audit/summary consistency, long identifiers, formula-like text, diagnostic
separation, truncation notices, changed-month race and narrow-screen overflow.

## Deployment / rollback

Deploy the matching Java API before or with plugin assets, PHP, translations and
version constant. Deploying only JS or omitting `profit-xlsx.js` breaks the asset
contract. Smoke-check a known month and its audit with accepted inputs before
using results. Neither SQL migrations nor Folio writes are part of this change.
Roll back the plugin files together to the prior release if required; never rewrite
Folio or rerun mutating campaigns as a frontend rollback. The old API remains
readable through the explicit legacy fallback.

Manager workflow: [operations runbook](../../../docs/OPERATIONS_RUNBOOK.md#прибыль).


## Partial availability (0.3.1)

A successful response can include `sections`, a map from section code to
`{status: AVAILABLE|UNAVAILABLE, message, errorCode, errorId}`. The page and a
separate Excel sheet display this map. A failed source leaves dependent city
amounts null (displayed as `—`, without positive-profit styling); it does not
remove unrelated sections or disable export. The final result is explicitly partial.

`MASTER_CLASS_LINES_IGNORED` is advisory: those movement rows are already excluded,
and the warning alone neither rejects the response nor stops other calculations.
Each independent display block also catches its own rendering error, adds
`CLIENT_SECTION_UNAVAILABLE`, and continues. This guard does not repair or
recalculate backend amounts. Fatal transport/request errors remain errors, with
safe HTTP/diagnostic IDs where provided; old values are explicitly identified as
a previous snapshot.

Incident check 2026-09-08: the open production tab still ran 0.2.2 while server
files were 0.3.0. After reload, July returned successfully with 35 expense rows.
A direct Java request and a normal WordPress read also succeeded (about 20–23s).
The earlier failure could not be reproduced; neither the warning nor a particular
timeout was established as its cause. Local partial-availability changes require
a matching Java API release; the successful live check used the earlier contract.

Optional numeric parameters retain decimal syntax validation in PHP (up to100
characters), but semantic ranges are evaluated by Java. Negative amounts and
out-of-range shares reach Java instead of causing PHP to abort the whole report.
`EXPENSE_INPUTS`/`EXPENSES` can become unavailable; their controls and dependent
amounts stay unknown. A malformed decimal such as `abc`, an invalid month or a
disabled feature remains a request error requiring correction. This is not a
promise to silently accept every invalid input or replace it with a default.

Expired WordPress nonce responses now return `REPORT_SESSION_EXPIRED`/HTTP403
with a reload instruction. Legacy `-1`/403 is recognized by JS too. Authorization
and nonce checks are retained and never bypassed. Nonce expiry is a plausible,
but unproven, explanation for the old-tab incident; this change makes future
failures diagnosable instead of presenting a generic Folio loading error.

## Saved profit history (0.5.0)

Profit opens saved application-DB results by default. Explicit monthly calculation
creates a revision; a range contains1..24months, missing values are never zero.
Full month/audit/range exports read fixed saved revisions, not live Folio.
Java V15 migration is required; no production migration was performed during
development. Failed/uncertain saves preserve older history and never auto-retry.
See [consumer contract](../../../docs/api/FOLIO_PROFIT_SAVED_REPORTS_FRONTEND.md)
and [operator runbook](../../../docs/OPERATIONS_RUNBOOK.md#прибыль).
