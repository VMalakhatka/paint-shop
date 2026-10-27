# Архитектура и границы ответственности

NP manager preparation (2026-10-18, local): PCOE's existing-order hook
`pcoe_manager_order_shipments` delegates to NP `ManagerPreparationPanel` and
`ManagerPreparation`. Reuse `pnpm_shipments/items/events` and PCOE's exact order
lock name. `manager_draft` is NOT a carrier-created TTN or a notification event.
Never turn preparation save into a carrier call or update the customer profile.
Import confirmation explicitly and verify the current source revision. Child
orders, external/locked shipments and multi-warehouse COD remain fail-closed.
Lifecycle 0.9.0 adds explicit preview/create, private PDF and at-most-once order-customer
email. Keep the same PCOE lock; commit the create claim BEFORE either recipient or
document save. Unknown/rejected/creating states never auto-retry. The first attempt
freezes preparation; remaining warehouses require the same order fingerprint.
External TTNs cannot use store-key printing. No COD, courier address, non-cash carrier
payment, polling, Telegram or automated reconciliation yet. The official docs were accessible in Chrome on 2026-10-18;
do not repeat the earlier 403 as a current access blocker. Read
[the contract review](../../../../docs/api/NOVA_POSHTA_SHIPMENT_CONTRACT.md)
before extending the lifecycle: official examples have contradictions, status 1
is not dispatch, and print URLs contain the secret and must never reach browsers.
See NP README and the manager guide `#np-preparation` for release boundaries.
Recipient identity 0.9.1 (2026-10-18): an authorized live recipient-only save
accepted empty MiddleName; never invent patronymics. Prefill structured names only
when saved recipient name and phone match the order/customer candidate; never
overwrite dropship recipients or parse free-text name order. Validate returned
carrier contact before document save; missing/mismatched details lock for manual
review, not retry. This is not global phone-owner identification. See the contract
review for live-vs-mocked evidence; no real TTN was used for this check.
Production gate enablement (2026-10-18, owner-approved): active NP 0.9.1 with both
write gates true, confirmed by a fresh server PHP process. Local defaults remain
off. This enables explicit manager creation, not automated writes or acceptance
of a real shipment pilot. Private backups, re-preview and narrow disable procedure:
manager guide `#np-preparation`. Never copy enabled production gates into fixtures.
Sender mapping repair (production, 2026-10-18): `ShipmentLifecycle::compile`
requires the configured contact Ref AND phone under the current sender. A matching
phone on another contact does not pass ownership. An owner-approved repair changes
only the selected location's `contact_ref`, after unique-match, backup, lock and
concurrency checks; never reset shipment state or write to the carrier. HTTP 200
directory responses can still be unsuccessful. Recovery and evidence boundaries:
manager guide `#np-preparation`.

NP recipient verification 0.9.3 (2026-10-18): save does not promise contact Phones.
Persist returned Refs before a separate exact-contact directory read; test with the
documented minimal save response, not a richer invented mock. Keep safe diagnostic
codes. Admin CLI recovery applies only to proven recipient-only stops, never unknown
document outcomes; `np_recipient_ready` freezes preparation and reuses the existing
contact. No reset to draft or repeated recipient save. Procedure: NP README 0.9.3.

NP pacing 0.9.2 (2026-10-18): JSON transport uses a per-site/key named lock and
shared timestamp; never clear that timestamp merely to bypass a process-local
cache. Only allowlisted rate-limited reads retry once; mutations remain at-most-once.
Live `getWarehouses` returned 20000401501 / To many requests with HTTP 200.
Do not misdiagnose this as a sender mapping error or relax fresh point validation.
Pre-claim busy messages differ from post-claim unknown outcomes; see NP README
and manager guide `#np-preparation` before recovery or deployment.

## Поток данных

```text
Браузер
  -> WordPress / WooCommerce / GeneratePress Child
  -> собственные plugins и MU-plugins
  -> WordPress proxy/AJAX/REST
  -> Java API
  -> ФОЛИО на legacy MSSQL

Медиа:
  WordPress Media Library
  -> Media Cloud
  -> OVH S3-compatible storage
  -> s3_media_index
  -> ФОЛИО media API и Woo attachment IDs
```

MariaDB хранит состояние сайта и проекции ФОЛИО. MSSQL остаётся источником учётных документов, партнёров, остатков и учётных цен. S3 хранит бинарные медиа, но Woo связывает товар с WordPress `attachment_id`, а не с произвольным URL.

## Владельцы поведения

Относительный период аналитики (2026-10-14): WordPress `analytics-period.php`
хранит `ROLLING_DAYS` с длительностью в сценарии и вычисляет конкретные `from/to`
при запуске по календарной дате сайта. Все страницы/потомки и Excel одного расчёта
используют зафиксированные даты; Java получает существующий контракт дат.
Режим `FIXED` сохраняет старые исторические сценарии. Регламент:
[период сценария](../../../../docs/OPERATIONS_RUNBOOK.md#относительный-период-анализа-сценария-2026-10-14).

Клиентские предзаказы в закупочном preview (подтверждение владельца 2026-10-14):
Java `FolioPreorderRule` отбирает неучитываемые ПРД-счета (`STND_UCHET=0`).
Они не влияют на свободный остаток ФОЛИО; PHP добавляет полное оставшееся
количество к потребности и не приписывает им другие текущие резервы. Канон:
[Operations runbook](../../../../docs/OPERATIONS_RUNBOOK.md#клиентские-предзаказы-в-supplier-preview-2026-10-14).

Закупочный прогноз сборки: Java владеет чтением ALL_RAZBORKA(_SLOJ), BALL4 и
метриками ФОЛИО; PHP — графом потребности в preview. Сложный рецепт приоритетен
для всего ребёнка. Потомки находятся вне фильтра поставщика, но закупочная выгрузка
содержит закупаемые SKU выбранного поставщика и аналитические строки детей/внуков.
У детей заказ поставщику всегда 0; строки сохраняют статистику и потребность
изготовления. Списки родителей и детей берутся из рецепта, включая нулевой дефицит.
Не повторять прогноз общего ребёнка и не вычитать его остаток на каждом пути.
Контракт и статус выпуска: [прогноз компонентов](../../../../docs/api/FOLIO_ASSEMBLY_PURCHASE.md).

- Тема `generatepress-child`: компоновка и стили, которые действительно принадлежат теме.
- `paint-core`: общие stock/allocation/cart helpers.
- `lavka-customer-analytics`: consent-gated GA4 витрины и checkout snapshots, не аналитика ФОЛИО. Production 11.10: 0.2 active/enabled, debug OFF после guest/network/Realtime приёмки; полный платёжный и wholesale browser путь не принят. Не считать processing/date_paid оплатой и не складывать parent/children. Канон выпуска и read-only отчёта: [CUSTOMER_ANALYTICS.md](../../../../docs/CUSTOMER_ANALYTICS.md).
- `paint-shop-ux`: каталог, карточка товара, поиск и UX витрины.
- `lavka-total-sync`: полная синхронизация карточек/категорий/медиа без цен и остатков.
- `lavka-sync`: обмен остатками и mapping складов.
- `lavka-price-sync` + `role-price`: получение и применение цен по ролям, учётные цены и аналитика.
- `pc-folio-*`: customer mapping, баланс, документы, связь и split заказов.
- `pc-order-import-export`: импорт/экспорт и черновики.
- `lavka-product-media-upload`: контролируемая пакетная загрузка изображений.
- Java: тяжёлые расчёты и работа с ФОЛИО; PHP не должен повторять бизнес-алгоритм MSSQL.

## Правило выбора слоя

Условная загрузка frontend-ресурсов (проверено локально 2026-09-27): импортом
владеет `pc-order-import-export/inc/Ui.php`, адаптером WP Social для каталога —
`paint-shop-ux/inc/social-assets.php`. Не менять vendor и не отключать социальный
плагин целиком. Render-time fallback сохраняет нестандартные места вывода;
прямые theme-вызовы социальных функций вне content/shortcodes требуют
`psu_social_assets_required=true`. Границы и проверка:
`docs/SITE_PERFORMANCE_AUDIT_2026-09-14.md`, раздел от 27.09.2026.

1. Найди текущий hook/route/meta владельца через `rg`.
2. Исправляй источник, а не визуальный симптом в другом плагине.
3. Общий бизнес-инвариант размещай в одном собственном модуле и переиспользуй.
4. Не создавай параллельную копию Java-логики в PHP.
5. Не изменяй vendor/third-party plugin ради локального поведения.

## Локальное и production

Catalogue draft planning (2026-10-16, local): `pc-order-import-export/CatalogDraft`
owns the authenticated direct-to-draft path. Never implement this by relaxing Woo
cart stock validation. Reuse `pc-draft`, ownership/editability and the manager's
per-order named lock. Catalogue filtering adapters ignore stock only in planning
mode; explicit draft processing remains a separate preview/apply. Configuration,
metadata, tests and rollback: the PCOE README, Catalogue draft mode.

Draft guidance is owned by PCOE `DraftHelp`: inline native disclosure menus for
all signed-in roles, reused inside the wholesale guide without widening its role
gate. The shipped PDF and synthetic screenshots live in PCOE assets/draft-guide;
regenerate them when changing the draft/purpose/preview flow. No Folio write is
needed to capture documentation examples (README: Illustrated draft help).

Mobile cart navigation (2026-10-16): Paint Shop UX `inc/mobile-cart.php` owns
the cache-neutral footer shell and own-session Woo AJAX summary. Reuse successful
add/remove fragments; do not re-enable the globally disabled wc-cart-fragments
script or cache personalized totals in public page HTML/localStorage. No cart,
stock, checkout or consent-policy mutation belongs in this UI. See PSU README.

NP checkout 0.8.1 (2026-10-18, local): `paint-nova-poshta-multishipping` owns
carrier-paid shipping and single-location pickup. Zero Woo shipping cost is not
free carrier service; retain payment-collection and base-coverage shipping meta.
Unknown weight/API quote failure must not invent a charge, while allocation and
destination errors still block submission. Default base subsidy is strictly over
1200; persisted policies take precedence. When its NP rate is available, the
customer selector permits NP and allocation-backed pickup only, including for
wholesale roles. Legacy rate IDs are not reliable; use the positive method list.
Existing external TTNs/manager operations remain unchanged. Display thresholds
from the same policy calculator; never promise full coverage for capped subsidies.
Do not silently overwrite production
shipping options or transfer stock for pickup. Deployment prerequisites and tests:
plugin README, section «Класичне оформлення замовлення».

Classic-cart AJAX metadata (2026-10-11): `pc-cart-guard.php` returns
`item_data_html` from the updated cart item through Woo's standard formatter.
The allocation hook in `stock-locations-ui.php` needs cart context during AJAX;
use the scoped `woocommerce_is_cart` filter, not a duplicate client allocator.
Tests and deploy scope: `docs/CART_LAYOUT_QA.md`, Allocation Metadata Refresh.

Google analytics (production 2026-10-11): OAuth grant и Search Console site ownership
различаются. При 403 на site query сверять кабинет Google, а не повторять OAuth
вслепую. Reconnect Rank Math может очистить selections/options; совпадающие имена
ресурсов не позволяют выбрать поток — сверять measurement ID/URI. Сохранять прежнюю
политику исключения пользователей; новый analytics plugin не активировать ради
исправления авторизации. Источник: UI/API Google, Rank Math `class-ajax.php` и
`google/class-request.php`; подтверждённое состояние и регламент восстановления:
[CUSTOMER_ANALYTICS.md](../../../../docs/CUSTOMER_ANALYTICS.md).
Верификация Search Console через GA4 зависит от сохранения gtag.js: перед подавлением
Rank Math snippet при consent rollout согласовать и проверить независимый способ
владения. Production 11.10: дополнительный HTML tag подтверждён для того же owner
через штатный `google_verify` в option `rank-math-options-general` (дефисы).
Consent rollout сохраняет это поле и Rank Math head; verification value/email не
экспортировать. Полный privacy проект — локальный DOCX на утверждение, не опубликован;
analytics-specific notice опубликован отдельно, module 0.2 active/enabled.
Точный scoped rollback и источник проекта — в каноне.
Подтверждение доступа не означает готовность поисковых данных/cache import.
Read-only preflight `scripts/audit-analytics-release.php` читает выбранный поток
Rank Math через `stream_id` либо legacy `view_id`, не экспортирует OAuth/customer rows.
GA4 site search parameters и `catalog_search` витрины сверять отдельно; готовность
счётчика не доказывает событий товаров/корзины. Published privacy и network consent
test — обязательные ворота; фактический результат 11.10.2026 в каноне выше.
Google config operator `scripts/configure-analytics-google.php` по умолчанию preview;
apply требует явного разрешения, сверки measurement ID/resource name, closed snapshot
и конечного updateMask. При timeout перечитать, не повторять PATCH автоматически;
не очищать чужой ecosystem lock. Одобренный срок не означает внедрение Woo удаления.
Разрешённые retention/enhanced изменения не включают analytics plugin и не заменяют
network consent приёмку. Результаты/rollback — в том же каноне.
Analytics-specific notice может быть отдельно опубликован и назначен через
`lca_settings.notice_page_id`; он не заменяет полную Woo privacy policy и не обещает
непроверенные финансовые/log/backup сроки. Scoped launch сохраняет persisted
Rank Math install flag OFF, чтобы деактивация не возобновляла неконтролируемый сбор.

XML sitemap категорий (2026-10-08): `paint-shop-ux/inc/category-sitemap.php`
ограничивает `rank_math/sitemap/index/entry` плоской нативной выборкой Rank Math.
Не хардкодить последнюю страницу и не исправлять vendor. Сохранять параметры
выборки, включая `orderby=term_order`, иначе Woo menu-order meta join меняет
число терминов. После разрешённого деплоя нужен сброс sitemap-кэша; регламент
и read-only интеграционный тест: `docs/SYSTEM_OVERVIEW.md`, раздел XML sitemap.

Product sitemap cache (production 2026-10-16): отсутствие URL в XML не означает
noindex или явное исключение. Сначала сверять нативный провайдер Rank Math,
robots/canonical и текущую страницу выборки с кэшем. Для двух разрешённых карточек
хватило штатной очистки только `product`; Woo catalog visibility `hidden` сохранена.
Не менять видимость, остатки, даты карточек или vendor ради включения в sitemap.
Preview, backup/lock, публичная проверка и границы результата:
`docs/SYSTEM_OVERVIEW.md`, раздел «XML sitemap товаров: обновление кэша».

Production SEO (2026-10-08): служебные страницы закрыты через штатный
`rank_math_robots`, taxonomy `location` через custom robots Rank Math. Это
production options/meta, не code-defaults; не переносить локальную БД ради их
сохранения. Список, проверка и точечный rollback: `docs/SYSTEM_OVERVIEW.md`,
раздел «Служебные страницы: индексация production». Не путать складские архивы
с полезными страницами контактов/магазинов и не считать noindex защитой данных.

- Локальный сайт используется для разработки и безопасной визуальной проверки.
- ID страниц, пользователей, заказов, media attachment и location terms между окружениями различаются.
- Конфигурация API, payment gateway и storage может различаться; проверяй settings, а не переносимые ID.
- Производственный mutate-запуск требует отдельного подтверждения даже после успешного локального теста.

Проверено по коду и настройкам локального сайта: 2026-08-21.

## Мастер-классы (закрытый production 2026-09-26)

`lavka-workshops` владеет CPT статей/закрытых заявок, расписанием в post meta и
ролью `lavka_instructor`. Не использовать Woo orders или ФОЛИО для заявок без
отдельного требования оплаты. Вместимость подтверждает мастер вручную; повтор
телефона на одну дату идемпотентен. Woo admin guard допускает роль по capability
`edit_lavka_workshops`, без выдачи `edit_posts`/`manage_woocommerce`.
С 2.0 (локально проверено 2026-09-26) статья редактируется Gutenberg; три стартовых
макета и семь композиций состоят из core blocks в едином `post_content`.
REST включён только для публичного CPT статей с обычными capability checks;
заявки и `_lw_sessions` через REST не публиковать. Даты сохраняет совместимый
метабокс. Legacy HTML не мигрировать на чтении: преобразование инициирует мастер.
Источники: `inc/block-editor.php`, `inc/patterns.php`, `tests/block-editor.php`.
Источник: `wp-content/plugins/lavka-workshops/`; lifecycle и инструкция мастера —
`docs/WORKSHOPS_GUIDE_RU.md`. С 2.1 закрытый режим по умолчанию определяется
`lw_section_visibility`: только `public` разрешает гостевой доступ. Gate в
`inc/visibility.php` защищает страницы, запросы, REST, oEmbed и sitemap; AJAX и
admin-post проверяют те же capabilities. Опубликованный статус не открывает раздел.
Медиа/S3 остаются публичным хранилищем. Production 2.1 активирован
в закрытом режиме 2026-09-26; HTTP-проверки гостей и браузерный сценарий владельца
пройдены. Точные ссылки, backup и результаты — в основной инструкции.

Проверено кодом и локальными тестами 2026-10-09: в заказе по сборкам Java graph v2
исключает МУЛЬТИСБОРКА из demand/lost demand, сохраняя фактический расход.
PHP preview v9 показывает childCoverageShortfall после округления/ручной правки,
не меняя выбранное количество. Формулы и проверка: docs/api/FOLIO_ASSEMBLY_PURCHASE.md.

Product-card reads (2026-10-18): `lavka-price-sync/inc/folio-product-cards.php`
owns the separate Lavka Folio manager menu; Java `FolioProductCardDao/Controller`
owns the two Access-compatible SELECTs and all-field multiset comparison. Preserve
54 template positions, literal SKU/barcode strings, legacy warehouse/markup filters
and optional ALL_ARTC/ALL_CENA rows. Do not substitute card_tov_export or treat the
missing assembly columns as fetched data. Full contract and operator boundaries:
[product-card read/export](../../../../docs/api/FOLIO_PRODUCT_CARDS.md).

Folio card file input (2026-10-18): `folio-product-cards-import.php` uses the shared
PhpSpreadsheet autoloader for XLS/XLSX SKU lists; it never imports card values.
Preserve explicit selected/all scope, capability/nonce and text identifiers. Export
has 57 template headings with the final three assembly fields empty; SQL remains
54 fields. Operator contract: `docs/api/FOLIO_PRODUCT_CARDS.md`.

Product-card contextual help (2026-10-22): `folio-product-cards-help.php` owns the
capability-gated guide submenu and the disclosure topic menu above the card form.
`lps-card-help` is a dedicated UK/RU domain. Keep topic anchors and button names
aligned with the form; help rendering makes no Java request. Canonical operator
steps: `docs/api/FOLIO_PRODUCT_CARDS.md`, linked from `docs/OPERATIONS_RUNBOOK.md`.

Manager card disclosures (2026-10-27): PCOE `assets/manager.js` hides sibling
branches around each card heading without reparenting forms. Preserve this boundary:
NP and Folio modules bind to their existing nodes. Visibility belongs to session
storage, not order metadata, and must never change draft/preorder purpose. The
manager guide documents controls, anchors, validation and no-JS fallback.
