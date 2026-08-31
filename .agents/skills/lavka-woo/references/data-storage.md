# Хранение данных WordPress и WooCommerce

Проверено по локальной схеме: 2026-08-21. Перед прямым чтением production повторно проверь имена таблиц и префикс.

## Стандартные сущности

| Сущность | Основное хранение | Правило записи |
|---|---|---|
| Товары, страницы, attachments | `wp_posts`, `wp_postmeta` | Woo/Product CRUD или WordPress API |
| Категории, бренды, склады, visibility | `wp_terms`, `wp_term_taxonomy`, `wp_term_relationships`, term meta | taxonomy/Woo API |
| Пользователи | `wp_users`, `wp_usermeta` | WordPress user API |
| Настройки | `wp_options` | `get_option`/`update_option` |
| Заказы HPOS | `wp_wc_orders`, `wp_wc_orders_meta`, `wp_wc_order_addresses`, operational tables | только `WC_Order` CRUD |
| Позиции заказов | `wp_woocommerce_order_items`, `wp_woocommerce_order_itemmeta` | order item CRUD |
| Поисковый индекс | таблицы Relevanssi | Relevanssi API/reindex |

Не считай `wp_postmeta` главным хранилищем заказов: HPOS включён. SQL для диагностики HPOS допустим read-only, но изменения выполняй через Woo CRUD.

## Важные meta

### Пользователь и клиент ФОЛИО

- `_folio_partner_id`
- `_folio_partner_short_name`
- `_folio_partner_name`
- `_folio_partner_type`

Связь действительна, когда записано подтверждённое короткое имя ФОЛИО. Не выводи финансовые данные только по Woo-роли без этой связи.

### Товар и цена

- `_ms_hash` — hash полной Java-синхронизации товара.
- `_wpc_price_role_<role>` — цена для Woo-роли.
- стандартные Woo product/stock meta.
- location stock meta принадлежат Stock Locations integration; точный ключ ищи в текущем коде, не угадывай.

Hash не заменяет исправление derived state: при изменении visibility/search/media обновляй соответствующее состояние и индекс, а не только `_ms_hash`.

### Нова пошта

- `pnpm_location_mappings` — соответствие Stock Location зарегистрированному
  отправителю, адресу и точке передачи НП.
- `pnpm_delivery_policy_v1` — версия 1 политики оплаты доставки: mapping Woo roles
  в `retail`/`partner`, пороги и бюджеты магазина, плательщики компонентов и COD.
  Не редактировать сериализованный option вручную; использовать WooCommerce ->
  «Відправлення Нової пошти».

### Заказ и документы ФОЛИО

- `_folio_document_id`, `_folio_document_number`, `_folio_document_type`, `_folio_document_status`.
- `_folio_document_created_at`, `_folio_document_payload_hash`, `_folio_document_last_error`.
- `_folio_documents_result` — стабильный полный результат Java для нескольких документов.
- `_folio_child_order_ids`, `_folio_parent_order_id`.
- `_folio_split_status`, `_folio_split_created_at`, `_folio_split_from_order_id`, `_folio_split_document_kind`.
- `_folio_warehouse_id`, `_folio_customer_notice`, `_folio_auto_*`.
- `_pc_alloc_plan`, `_pc_draft_title`.

Используй helper-функции плагина связи заказов, если они существуют. Не собирай сериализованные значения вручную SQL-строкой.

### Склад Woo -> ФОЛИО

Term meta `lavka_folio_warehouses` хранит упорядоченный список `{id, priority}`. Меньшее значение priority используется раньше. Название склада для пользователя получай из справочника/mapping, а не показывай только цифровой ID.

## Собственные таблицы

| Таблица | Назначение |
|---|---|
| `wp_lavka_catmap` | соответствие дерева категорий ФОЛИО и Woo |
| `wp_lavka_ecosystem_events` | общий журнал длительных операций |
| `wp_lavka_sync_logs` | журналы синхронизации |
| `wp_stock_import` | staging остатков |
| `s3_media_index` | точный индекс basename/key/size/etag объектов S3 |
| `s3_media_links` | связи изображения с SKU/товаром и состояние обработки |
| `sync_product_state` | cursor/state полной синхронизации |
| `folio_product_media_requests` | идемпотентность media-команд ФОЛИО |
| `folio_product_snapshot_generation`, `folio_product_snapshot_item` | поколения и состояния проверки товаров ФОЛИО; Java создаёт эти таблицы без WordPress-префикса |
| `folio_product_movement_fact` | активные строки движения за горизонт: класс движения, регулярный/разовый спрос, условия оплаты, сегмент клиента, контрагент и текущий поставщик |
| `folio_product_*metrics*`, alerts | аналитика товара и предупреждения |
| `folio_customer_balance_snapshot_*` | подготовленный снимок должников |
| `wp_lps_analytics_scenarios` | общие и личные сценарии аналитики ФОЛІО; фактический префикс берётся из `$wpdb->prefix` |
| `wp_lps_analytics_scenario_revisions` | неизменяемые ревизии сценариев для истории и контроля версий |

Точный набор колонок узнавай через `SHOW CREATE TABLE`/`DESCRIBE` перед изменением. Таблица с названием `snapshot` является проекцией, а не источником ФОЛИО.

`folio_product_movement_fact` заменяется Java атомарно для одного склада при
успешной публикации поколения. WordPress читает готовые `movement_class`,
`demand_mode`, `payment_terms`, `customer_segment` и флаги влияния; не
пересчитывает их из `VID_DOC`. `current_supplier` — назначение карточки на дату
snapshot, а `counterparty_*` — исторический контрагент документа.

Рабочий сценарий аналитики имеет `schemaVersion=4` и хранит `context`, период,
условия `products` и `movements`, параметры расчёта, сортировку, пагинацию и
`presentation`. `context.warehouseIds` содержит один или несколько складов.
`visibility=shared` доступна пользователям с `LPS_CAP`, `visibility=personal` —
владельцу; `status=archived` скрывает сценарий из рабочего выбора без физического
удаления. Поле `version` используется для optimistic locking, а каждое успешное
сохранение создаёт строку ревизии.

Старые фильтры из user meta `lps_product_analytics_filter_presets` импортируются
один раз в личные сценарии с ограниченным преобразованием в v4. Исходный meta не
удаляется, а `legacy_key` защищает от повторного импорта. Не редактируй JSON или
ревизии напрямую SQL: используй административный интерфейс и AJAX владельца.

## Состояния проверки учётных цен

`folio_product_snapshot_item.verification_state`:

- `UNVERIFIED` — ещё не прошёл первичную кампанию;
- `NEW` — новый товар;
- `DIRTY` — изменился после проверки;
- `VERIFIED` — проверен и не должен повторно идти в регулярный cron;
- `FAILED` — требует исправления оператором перед новым запуском;
- `REMOVED` — больше не присутствует в ФОЛИО.

До завершения baseline WordPress выбирает `UNVERIFIED`, `NEW`, `DIRTY`; после него — только `NEW`, `DIRTY`. Java не принимает `onlyDirty=true`, поэтому выбор SKU является обязанностью WordPress server-side.

Состояние текущей оркестрации хранится в option `lps_accounting_price_sku_campaign`. Оно содержит campaign/job IDs, склад, фазу, текущий пакет, диагностические результаты и запрос безопасной остановки. Настройки еженедельного запуска и размеров пакетов находятся в `lps_accounting_prices_native_cron`. Не редактируй эти сериализованные options вручную; используй административный UI и функции владельца.

## Настройки, которые нельзя хардкодить

Ищи текущие значения в options и коде владельца:

- `lavka_sync_options`, `lts_options`, `lps_options`;
- `lps_role_contract_map`;
- accounting cron/job/batch options;
- ecosystem lock option;
- WayForPay settings и test-access options;
- Woo page IDs и permalinks.

Не выводи значения секретных options в отчёт или документацию.
