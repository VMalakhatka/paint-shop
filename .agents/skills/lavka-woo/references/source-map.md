# Карта источников

## Приоритет истины

1. Текущие Woo settings, схема MariaDB и наблюдаемое поведение локального/целевого окружения.
2. Текущий PHP/JS-код и тесты этого репозитория.
3. Текущий Java-код, тесты и подтверждённые API-документы проекта ФОЛИО.
4. Поддерживаемые инструкции в `docs/` этого WordPress-проекта.
5. Старые заметки, скриншоты и предположения.

При конфликте не усредняй источники. Зафиксируй расхождение и следуй более сильному источнику.

## Репозитории

- WordPress/WooCommerce: корень, содержащий этот skill.
- Java/Folio: `/Users/admin/Documents/Toleran/Proect_Lavka/kreul_com_ua`.
- Skill по ФОЛИО: `/Users/admin/Documents/Toleran/Proect_Lavka/kreul_com_ua/.agents/skills/work-with-folio-mssql/`.

Пути относятся к рабочей машине и могут измениться. Перед изменением проверь существование пути через `rg --files`/`find`.

## Документация WordPress

- `docs/SITE_USER_GUIDE_UK.md` — пользовательские сценарии сайта.
- `docs/MEDIA_MANAGER_GUIDE_UK.md` — работа менеджера с медиабанком, S3 и ФОЛИО.
- `docs/FOLIO_ORDER_JSON_CONTRACT.md` — контракт заказов.
- `docs/WAYFORPAY_TEST_ACCESS.md` — тестовый доступ к WayForPay.
- `docs/ROADMAP_*.md` — планы, не доказательство реализованного поведения.
- `docs/VARIABLE_PRODUCTS_NEW_CHAT_BRIEF.md` — отдельное проектирование вариативных товаров.

## Документация Java/Folio

В каталоге Java `docs/api/` сначала ищи документ конкретного endpoint. Наиболее важные:

- `FOLIO_ACCOUNT_JS_API.md` — создание счетов и распределение по складам.
- `FOLIO_ACCOUNTING_PRICES_API.md` — контракт учётных цен.
- `FOLIO_ACCOUNTING_PRICES_FRONTEND.md` — UI и статусы перерасчёта.
- `FOLIO_ACCOUNTING_PRICE_OPERATIONS_FRONTEND.md` — VERIFIED/DIRTY и операционная кампания.
- `FOLIO_CUSTOMER_BALANCE_API.md` и `FOLIO_CUSTOMER_BALANCE_FRONTEND.md`.
- `FOLIO_CUSTOMER_DEBTORS_FRONTEND.md` — снимок и отчёт должников.
- `FOLIO_CUSTOMER_DOCUMENTS_API.md` — документы клиента.
- `FOLIO_PRODUCT_MEDIA_API.md` и `FOLIO_WOO_MEDIA_RECONCILE.md`.
- `FOLIO_PRODUCT_SNAPSHOT_API.md` и `FOLIO_PRODUCT_STATISTICS_FRONTEND.md`.
- `FOLIO_PROFIT_REPORT_API.md` и `FOLIO_PROFIT_REPORT_FRONTEND_TASK.md`.

## Быстрая проверка текущего состояния

Используй read-only команды и не полагайся на сохранённые ID:

```bash
git status --short
rg --files wp-content/plugins wp-content/mu-plugins docs
wp option get active_plugins --format=json
wp option get woocommerce_shop_page_id
wp option get woocommerce_cart_page_id
wp option get woocommerce_checkout_page_id
wp option get woocommerce_myaccount_page_id
wp theme mod list
wp db tables
```

Если обычный `wp` загружает проблемный сторонний код, для чтения options используй `--skip-plugins --skip-themes`. Не применяй этот режим к проверке runtime-hooks.

## Поддержание карты

Обновляй справочники только после проверки. Для изменчивых значений используй строку `Проверено: YYYY-MM-DD`. Не переносить сюда секреты из `.env`, `wp-config.php`, options или Java configuration.
