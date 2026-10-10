# Lavka Customer Analytics

Version 0.1.0. Prepared and locally tested 2026-10-10. **Not deployed or enabled on production.**

Owns consent-gated storefront GA4 events, checkout attribution snapshots and anonymous
Woo order analysis. Does not modify prices, stock, allocation, checkout validation,
payments, Folio documents or advertising accounts. Uses HPOS-compatible Woo CRUD.

Canonical operator procedure and release gates:
[Customer analytics](../../../docs/CUSTOMER_ANALYTICS.md).

## Configuration and lifecycle

`lca_settings` option: `enabled` (strict true/1, default false), `measurement_id`
(valid GA4 stream ID, default empty), `debug` (default false). No API secret needed
for browser events. No database migration or scheduled job. Activation alone is inert.
Deploy manifest policy `manual`: uploading files does not activate or enable collection.

With explicit enablement, a runtime option filter disables **only Rank Math's GA
snippet**, without changing its persisted settings. This plugin loads the Google tag
only after `lca_consent=granted`. No consent means no Google request from this module.
Other advertising plugins/tags are outside this control and must be audited before release.

Consent UI: English msgids, UK/RU catalogs. Refusal does not block shopping.
Choice expires after 180 days. Preferences can change it; withdrawal stops events,
clears source/seen session storage and the module's first-party GA cookies.
The six-month cookie contains only the choice, not an identity. GA4 identifiers remain
pseudonymous, not proof of full anonymization. Owner review of the privacy policy and
Google stream enhanced measurement is a mandatory production gate.

## Event contract

- Standard: `page_view`, `view_item_list`, `select_item`, `view_item`, `add_to_cart`,
  `remove_from_cart`, `view_cart`, `begin_checkout`, `add_shipping_info`,
  `add_payment_info`, `purchase`, `view_search_results`.
- Custom: `catalog_filter`, `checkout_submit`, `checkout_error`, `payment_confirmed`.
- Event scope: `customer_segment` retail/wholesale, currency, item SKU/Woo fallback,
  public name, quantity and current ex-tax price. Receipt prices use saved line totals.
- `purchase` = placed order on an authorized receipt, **not money received**.
  `payment_confirmed` requires the explicit Woo `payment_complete` hook marker;
  `processing`/`date_paid` do not count as confirmation.
- No customer names, contact details, raw user IDs, full addresses, full referrer URLs,
  URL queries, order keys, raw search queries or error messages exported. Search sends
  `catalog_query_redacted`, result count and empty-result flag. Filters send only the
  finite set of filter names, not arbitrary input values.
- Standard catalog loop includes hidden public product data. Quick-order adapter reads
  only displayed public SKU/name, no locale-dependent price parsing.
- GA page location strips queries; receipt uses `/analytics/order-receipt/` and neutral
  title. Account, balances, customer documents, admin and payment pages excluded.
- Staff with management/product/pricing/workshop capabilities excluded.
- Exact purchase transaction ID `woo-<root id>` enables GA4 purchase deduplication.
  Browser session/in-memory markers prevent common repeated receipt/consent emissions.

Successful Woo add/remove/quantity hooks enqueue events in the current Woo session,
not button-click listeners. Supported custom requests: cart guard, quick bulk order,
PCOE file import/draft-to-cart, customer document repeat, customer draft apply/waitlist.
Manager operations and CLI are excluded. Failed validation has no successful add event.
Storefront AJAX/next public page flushes this queue through same-session, nonce-protected
`lca_events`; no public order-data endpoint. Queue TTL 30 minutes, cap 100 events.
At-most-once server hand-off can lose events on connection failure or parallel requests;
not an accounting ledger. GA4 events may also be lost to blockers or rejected consent.

## Storage and limits

- `_lca_segment`, `_lca_consent`, `_lca_source`, `_lca_medium`, `_lca_campaign`,
  `_lca_device`: consented checkout snapshot. Campaign tokens limited and phone/email-like
  values rejected; campaign naming must never include personal data.
- `_lca_payment_confirmed_at`: first explicit payment hook timestamp, saved as order meta.
  This is Woo evidence, not independent bank/Folio settlement reconciliation.
- Split children inherit attribution at their normal Woo save, but **never** payment
  evidence. Receipt uses child totals OR unsplit order totals, not both. Foreign customer,
  currency, child relation, failed/invalid split or unauthorized key blocks receipt events.
- Browser-only purchases/payments require a return to the receipt; late callbacks without
  a return are visible in future Woo reports but not guaranteed to reach GA4. Server-side
  Measurement Protocol, refund events, offline payment reconciliation and historical
  backfill are **not implemented**. They require a separate delivery/idempotency design.
- GA4 maximum 200 items is exposed by `items_truncated`; full aggregate value retained.
  Large wholesale baskets cannot be treated as complete item-level GA4 accounting.
- No destructive uninstall. Disabling/deactivating stops hooks/collection but retains
  audit metadata in orders. Restore previous tracking deliberately; deactivation makes
  Rank Math's persisted snippet setting effective again.

## Verification

```bash
php wp-content/plugins/lavka-customer-analytics/tests/model.php
php wp-content/plugins/lavka-customer-analytics/tests/sales-report.php
node wp-content/plugins/lavka-customer-analytics/tests/browser.cjs
wp eval-file wp-content/plugins/lavka-customer-analytics/tests/runtime.php
```

Browser tests use synthetic pages and intercept Google requests. `PLAYWRIGHT_MODULE`
can point to the installed supported runtime. Runtime test refuses non-local environments
and uses only ephemeral filters and an unsaved WC_Order. No real emails, orders,
payments, customer mutations or Folio writes in tests.

Local verification 2026-10-10: 32 model assertions, 12 report fixtures, 15 WordPress/Woo
runtime assertions and 22 synthetic desktop/mobile assertions; PHP/JS syntax and
documentation structure/impact checks passed. The generic skill-frontmatter validator
could not run because PyYAML is absent in the available Python runtimes; SKILL.md
frontmatter itself was not changed. Production release gates remain open.
