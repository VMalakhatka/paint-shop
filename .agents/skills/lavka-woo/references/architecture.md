# Архитектура и границы ответственности

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

Закупочный прогноз сборки: Java владеет чтением ALL_RAZBORKA(_SLOJ), BALL4 и
метриками ФОЛИО; PHP — графом потребности в preview. Сложный рецепт приоритетен
для всего ребёнка. Потомки находятся вне фильтра поставщика, но закупочная выгрузка
содержит только выбранного поставщика и не содержит изготовляемых детей.
Не повторять прогноз общего ребёнка и не вычитать его остаток на каждом пути.
Контракт и статус выпуска: [прогноз компонентов](../../../../docs/api/FOLIO_ASSEMBLY_PURCHASE.md).

- Тема `generatepress-child`: компоновка и стили, которые действительно принадлежат теме.
- `paint-core`: общие stock/allocation/cart helpers.
- `lavka-customer-analytics`: consent-gated GA4 витрины и checkout snapshots, не аналитика ФОЛИО. Подготовлено локально 2026-10-10; production 11.10: файлы есть, но inactive/enabled=false. Не считать processing/date_paid оплатой и не складывать parent/children. Канон выпуска и read-only отчёта: [CUSTOMER_ANALYTICS.md](../../../../docs/CUSTOMER_ANALYTICS.md).
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
экспортировать. Проект privacy — локальный DOCX на утверждение, не опубликован;
analytics module остаётся off. Точный scoped rollback и источник проекта — в каноне.
Подтверждение доступа не означает готовность поисковых данных/cache import.
Read-only preflight `scripts/audit-analytics-release.php` читает выбранный поток
Rank Math через `stream_id` либо legacy `view_id`, не экспортирует OAuth/customer rows.
GA4 site search parameters и `catalog_search` витрины сверять отдельно; готовность
счётчика не доказывает событий товаров/корзины. Published privacy и network consent
test — обязательные ворота; фактический результат 11.10.2026 в каноне выше.

XML sitemap категорий (2026-10-08): `paint-shop-ux/inc/category-sitemap.php`
ограничивает `rank_math/sitemap/index/entry` плоской нативной выборкой Rank Math.
Не хардкодить последнюю страницу и не исправлять vendor. Сохранять параметры
выборки, включая `orderby=term_order`, иначе Woo menu-order meta join меняет
число терминов. После разрешённого деплоя нужен сброс sitemap-кэша; регламент
и read-only интеграционный тест: `docs/SYSTEM_OVERVIEW.md`, раздел XML sitemap.

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
