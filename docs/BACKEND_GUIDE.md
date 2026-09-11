# Backend Lavka / KREUL

Это единая точка входа для разработчика и администратора бэкенда. Документ связывает
WordPress/PHP, Java API, MariaDB, ФОЛИО/MS SQL, Docker и внешние сервисы, но не
дублирует точные API-контракты и длинные операционные процедуры.

Проверено по WordPress- и Java-репозиториям, проектным skills и поддерживаемым
runbook: 2026-08-29.

## Что в проекте считается бэкендом

```text
Browser / Woo admin
        |
        v
WordPress + WooCommerce
  |-- собственные PHP plugins и MU-plugins
  |-- MariaDB: сайт, HPOS, options, logs, idempotency, projections
  |-- Media Library -> Media Cloud -> OVH/S3
  |
  +-- server-side proxy/AJAX/REST
              |
              v
       Spring Boot Java API
          |             |
          |             +-> WordPress MariaDB / Flyway projections
          +----------------> ФОЛИО / legacy MS SQL через jTDS
```

Отдельный инфраструктурный путь ФОЛИО:

```text
OVH service -> physical host -> hypervisor -> Windows VM
            -> MSSQL runtime -> Paint_Ua/Paint_Rus -> Java clients
```

Frontend не обращается напрямую к MSSQL или MariaDB. PHP не должен повторять
финансовые расчёты и классификацию документов, уже выполненные Java/ФОЛИО.

## Репозитории и владельцы

| Область | Репозиторий/компонент | Владелец знания |
|---|---|---|
| WordPress, WooCommerce, PHP plugins, theme, proxy и deploy сайта | WordPress repository | `$lavka-woo` |
| Java controllers, DTO, services, DAO, Flyway и API contracts | `kreul_com_ua` | `$build-java-docker-runtime` для runtime; `$work-with-folio-mssql` для Folio-кода |
| ФОЛИО tables, procedures, documents и accounting semantics | Paint_Ua/Paint_Rus | live schema + `$work-with-folio-mssql` |
| Java image, Compose, env, health, deploy/rollback | `kreul_com_ua` | `$build-java-docker-runtime` |
| Host, VM, services, disks, MSSQL runtime | server layer | `$server-lavka` |
| OVH contracts, DNS, network/vRack и S3 policy | OVH provider layer | `$manage-ovh-infrastructure` |
| Media attachment/object/reconcile | WordPress + Java + OVH/S3 | `$image-in-woo`, provider changes отдельно |

MariaDB и ФОЛИО/MS SQL не имеют общей транзакции. Сквозной процесс обязан иметь
idempotency key, наблюдаемый terminal status и план восстановления после частичного
успеха.

## Карта исходного кода

### WordPress/PHP

- `wp-content/plugins/` — обычные собственные плагины с activation lifecycle.
- `wp-content/mu-plugins/` — автоматически загружаемые integration/guard modules.
- `wp-content/deploy_plugins.list` — allow-list обычных собственных plugins для deploy.
- `wp-content/deploy_safe.sh` — application deploy WordPress-кода.
- `wp-config.php`, `wp-config.common.php` — отслеживаемая несекретная логика.
- `wp-config.local.php`, `wp-config.production.php` — ignored environment config.

Точный владелец каждой функции перечислен в [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md)
и `.agents/skills/lavka-woo/references/plugins.md`.

### Java

- `controller/` — HTTP routes и transport-level validation.
- `dto/` — request/response contract.
- `service/` и `service/folio/` — orchestration и бизнес-инварианты.
- `dao/folio/` — legacy MSSQL/Folio access.
- `dao/wp/` — WordPress MariaDB projections и idempotency.
- `config/` и `property/` — data sources, binding, feature/write flags.
- `src/main/resources/db/wp/migration/` — Flyway migrations только для MariaDB.
- `docs/api/` — точные Java contracts; `docs/business/` — подтверждённые правила.
- `.agents/skills/work-with-folio-mssql/` — legacy compatibility и безопасная работа
  с Paint_Ua/Paint_Rus.

## Товарная аналитика: карта реализации

Проверено: 2026-09-10, локальные исходники WordPress и Java; production не проверялся.
Доменный владелец — `$folio-inventory-profit-planning`; WordPress сопровождает
`$lavka-woo`, Java/ФОЛІО — `$work-with-folio-mssql`, документы —
`$lavka-project-documentation`.

| Область | Владелец кода | Подтверждённая реализация и граница |
|---|---|---|
| Лавка: сценарії аналітики | `lavka-price-sync/inc/analytics-scenarios.php`, `assets/analytics-scenarios-v4.js` | Имена, версии, таблица ревизий, архивирование, проверка конфликта версии; профиль v4 допускает наличие v5 и параметры purchase preview |
| Лавка: товарна аналітика | `lavka-price-sync/inc/product-analytics.php`, `assets/product-analytics-v4.js` | Несколько складов, товар/движения, поддержанные include/exclude, серверные итоги, cursor; неподтверждённые измерения определяются capabilities |
| Наличие и группы | `lavka-price-sync/inc/product-availability.php`; Java `FolioAvailabilityOptions`, `FolioProductAvailabilityHistory`, analytics service/DAO | Дни и проценты отсутствия, MIN=0, статусы качества; объединение по дням рассчитывает Java |
| Глобальные группы и транспорт | `lavka-sync/inc/warehouse-map.php` | Состав групп, ревизия, список транспортных складов; сценарий хранит коды групп |
| Учётные факты и метрики | Java `FolioProductSnapshotService`, source/snapshot DAO, `FolioProductAnalyticsService` и `FolioProductAnalyticsDao` | Движения, продажи, возвраты, остатки, себестоимость, валовая прибыль, GMROI, coverage; query читает MariaDB, снимок извлекается из ФОЛІО |
| Транспортный остаток | Java `FolioTransitAnalytics`; WordPress transit consumer и purchase model | calculationVersion=3 разделяет физический и поставщицкий остаток; неподтверждённая согласованность сети блокирует закупку |
| Формування замовлення постачальнику — попередній розрахунок | `lavka-price-sync/inc/purchase-planning.php`, `purchase-planning-model.php`, `assets/purchase-planning.js` | Групповая потребность, lead time/целевые/страховые дни, MOQ/упаковка, перемещения между группами, корректировка с причиной; только временный preview |
| Экспорт | `lavka-price-sync/inc/product-analytics-export.php`, purchase export | CSV/XLSX, контроль полноты и поколений; параметры и объяснение расчёта сохраняются в выгрузке |
| Обновление снимков | `lavka-price-sync/inc/analytics-snapshot-queue.php` | Последовательная очередь под общим lock, без перерасчёта цен; потерянный POST не повторяется автоматически |

Формат WordPress-сценария v4 и версия Java-снимка — разные версии.
Аудит 2026-09-10 относился к Java schema 5. Подготовленное 2026-09-11
исправление свободного остатка использует `ANALYTICS_SCHEMA_VERSION=6` и требует
обновлённых снимков; одной
Flyway V13 недостаточно. Точные запросы и границы потребителя:
[контракт WordPress](api/FOLIO_PRODUCT_ANALYTICS_FRONTEND_V4.md).
Настройка и формулы preview остаются в
[операторском регламенте](OPERATIONS_RUNBOOK.md#формирование-заказа-поставщику-preview).

Проверено исполнением шести изолированных PHP suites в
`wp-content/plugins/lavka-price-sync/tests/`: `purchase-planning.php`,
`purchase-planning-session.php`, `product-availability.php`,
`transport-warehouses.php`, `configurable-transit.php`, `analytics-snapshot-queue.php`.
Все завершились PASS. Они не загружают рабочую ФОЛІО. Java-тесты analytics,
availability и transit изучены по исходникам; новый запуск Maven не выполнялся.
Браузерная приёмка desktop/mobile, реальная выдача файлов и сверка Paint_Ua
в этом аудите не выполнены. Изменена только документация: i18n и deploy приложения
не требуются. Перед будущим выпуском нужны совместимая Java, снимки v5 и UI-приёмка
всех трёх разделов по регламенту.

Оставшиеся ограничения и порядок развития:
[аналитика и закупки](KNOWN_GAPS.md#аналитика-и-закупки).

## Основные потоки

| Поток | Последовательность | Где точный контракт |
|---|---|---|
| Карточки/категории | WordPress `lavka-total-sync` -> Java `/sync/run` -> Woo | [OPERATIONS_RUNBOOK.md](OPERATIONS_RUNBOOK.md) |
| Остатки | Java/Folio -> `wp_stock_import` -> Woo location stock | [OPERATIONS_RUNBOOK.md](OPERATIONS_RUNBOOK.md) |
| Цены по ролям | Folio contract -> Java -> role-price meta -> runtime Woo price | plugin README + runbook |
| Woo-заказ -> ФОЛИО | Woo order -> PHP preview/proxy -> Java allocation/idempotency -> Folio documents | [FOLIO_ORDER_JSON_CONTRACT.md](FOLIO_ORDER_JSON_CONTRACT.md) + Java API |
| Учётные цены | WordPress campaign -> Java job/status -> Folio procedure -> postcheck/snapshot | Java `FOLIO_ACCOUNTING_PRICE_*` docs |
| Товарная аналитика | Folio read-only snapshot -> Java calculation -> MariaDB `folio_product_*` -> WordPress nonce/capability proxy -> PHP screen | Java `FOLIO_PRODUCT_ANALYTICS_API.md` + [WordPress frontend schema v4](api/FOLIO_PRODUCT_ANALYTICS_FRONTEND_V4.md) |
| Баланс/должники/документы | Woo user mapping -> PHP proxy -> Java read/snapshot -> Folio | Java `FOLIO_CUSTOMER_*` docs |
| Изображения | Media Library -> Media Cloud/S3 -> Java/Folio preview/apply -> Woo attachment assignment | [MEDIA_MANAGER_GUIDE_UK.md](MEDIA_MANAGER_GUIDE_UK.md) |

Для точного поля, статуса или JSON всегда открывать текущий controller/DTO и
соответствующий файл Java `docs/api`; эта таблица только маршрутизирует.

## Окружения

| Режим | WordPress | Java | Важная граница |
|---|---|---|---|
| Локальная разработка | `paint.local`, local MariaDB, ignored config | IDE/Maven или Docker Desktop | local IDs и endpoints не равны production |
| Docker Desktop | WordPress остаётся на Mac | Compose + `.env.docker` | host services доступны через `host.docker.internal` |
| Production | webserver/PHP/MariaDB + ignored production config | Linux container + отдельный runtime env | текущий Java deploy использует host networking |
| Recovery/staging | изолированная восстановленная копия | safe/read-only profile | live writes и callbacks включаются последними |

`127.0.0.1` в bridge-container — сам container. Имя env-файла не доказывает
безопасность значений: перед start отдельно классифицируются endpoints и write flags.

## Конфигурационный контракт

Документация хранит только имена, назначение и safe default, без значений.

### WordPress

- environment-specific database/cache/log configuration;
- server-side Java proxy URL, authentication и timeout;
- options владельцев plugins: warehouse labels, location-term warehouse groups,
  role contracts, schedules, batch limits;
- external services: WayForPay, Checkbox, Nova Poshta, Media Cloud/S3;
- аварийные live-write flags с выключенным default.

WordPress не загружает `.env` автоматически. Фактическая модель конфигурации и
безопасный перенос описаны в [BOOTSTRAP_AND_RECOVERY.md](BOOTSTRAP_AND_RECOVERY.md)
и project skill `deployment-and-configuration.md`.

### Java

- runtime: profile, port, language и logging;
- Folio/MS SQL DataSource;
- WordPress MariaDB DataSource и Flyway;
- Woo API и server-side Lavka API;
- OVH/S3;
- timeouts, page/batch/parameter limits;
- accounting-price, snapshot, report, scheduler и apply feature flags.

Сейчас нужен отдельный versioned template с полями `required`, `secret`, safe
default, environments и restart/migration requirement. До его завершения актуальный
набор key names проверяется без вывода values; пробел отслеживается в
[KNOWN_GAPS.md](KNOWN_GAPS.md).

## Владение данными

| Данные | Источник истины | Проекция/consumer |
|---|---|---|
| Товары, partners, Folio documents, stock, accounting prices | ФОЛИО/MS SQL | Java и Woo projections |
| Woo products, users, HPOS orders, options | WordPress/MariaDB | PHP/Woo CRUD |
| Folio snapshot, metrics, idempotency и operation state | MariaDB tables владельца | WordPress admin и Java jobs |
| Media binary objects | OVH/S3 | Media Cloud/WordPress attachment metadata |
| Woo main/gallery relation | WordPress attachment IDs | storefront |

HPOS-заказы изменяются только через Woo CRUD. Snapshot является проекцией, а не
заменой записи ФОЛИО. Ключ товарного snapshot:
`source_database + warehouse_id + sku`; join только по SKU неверен.

## Запуск с нуля

Полный checklist и порядок восстановления находится в
[BOOTSTRAP_AND_RECOVERY.md](BOOTSTRAP_AND_RECOVERY.md). Backend-часть проходит такие
gates:

1. Инвентаризация версий, сервисов, DNS/TLS, storage, databases и backup evidence.
2. Восстановление WordPress MariaDB/files и проверка PHP/Woo без внешних writes.
3. Проверка host/VM/MSSQL/Folio read-only и legacy compatibility.
4. Подготовка Java 17, env key contract, network routes и safe flags.
5. Tests/package, image/container start и `/healthz`.
6. Один безопасный запрос к каждой реально настроенной dependency.
7. Подключение WordPress proxy к Java и проверка read-only business path.
8. Последовательные snapshots/sync с общим ecosystem lock.
9. Schedulers, payments, fiscalization, реальные ТТН и другие live writes — только
   после отдельной приёмки и решения владельца.

Java commands и rollback: [JAVA_DOCKER_RUNTIME.md](JAVA_DOCKER_RUNTIME.md). Простого
восстановления Git недостаточно: нужны согласованные базы, ignored config, media
metadata/objects, внешние contracts и доказанный test restore.

## Разработка изменения

1. Найти владельца route/hook/table и сильный источник текущего поведения.
2. Определить read/write scope, transaction manager и partial-failure boundary.
3. Проверить capability/auth, nonce/request ID, idempotency, lock и unknown outcome.
4. Реализовать минимальное изменение в owning component.
5. Добавить focused tests и проверить legacy SQL/CP1251/HPOS при необходимости.
6. Обновить точный API/plugin contract и один основной human document.
7. Выполнить local smoke test, затем deploy dry-run/preview.
8. Production apply, activation или live-write выполнять отдельным решением.
9. Проверить бизнес-результат, rollback readiness и остаточный риск.

## Проверки по слоям

| Слой | Минимальная проверка |
|---|---|
| PHP | `php -l`, существующие tests, capability/nonce, HPOS, повтор запроса |
| Java | focused unit tests, package под Java 17, controller/service/DAO boundaries |
| MSSQL/Folio | SQL Server 2000 syntax, jTDS, CP1251, parameter limits, preview/read first |
| MariaDB/Flyway | migration ownership, forward/backward compatibility и backup plan |
| Docker | build context, image architecture, container state, `/healthz` и logs без secrets |
| Сквозной поток | WordPress proxy, request/job/run ID и контрольный business object |

HTTP 200, запущенный process или зелёный health не заменяют проверку суммы, склада,
цены, остатка, документа, attachment или terminal job status.

## Deploy, rollback и неизвестный результат

- WordPress и Java имеют разные deploy-скрипты и rollback-модели.
- Code rollback не откатывает Flyway, plugin migration, Folio write или внешний API.
- После timeout сначала определяется фактическое состояние; мутирующий запрос или
  deploy не повторяется вслепую.
- Перед production нужны backup, maintenance impact, postcheck, rollback target и
  stopping condition.
- Подробности: [BOOTSTRAP_AND_RECOVERY.md](BOOTSTRAP_AND_RECOVERY.md) и
  [JAVA_DOCKER_RUNTIME.md](JAVA_DOCKER_RUNTIME.md).

## Наблюдаемость

Для сквозной ошибки сохраняются окружение, время/часовой пояс, безопасные параметры,
HTTP status, `requestId/jobId/runId`, terminal phase, counts, lock и последний
успешный запуск. Не сохраняются raw payload клиента, env, connection string или
полный `docker inspect`.

Минимальный production monitoring ещё должен охватить availability, DNS/TLS, disk,
MariaDB, Java health, MSSQL connectivity, failed cron/jobs, stale lock, snapshot
freshness, external integration failures и backup age.

## Безопасность

- Наличие `/admin` или `/sync` в URL не является защитой; auth/authz и сетевой
  периметр должны быть доказаны до расширения доступа.
- Secrets не хранятся в Git, docs, skills, examples, image layers или logs.
- Финансовая, документная, media, фискальная и логистическая запись использует
  preview/apply, idempotency и отдельное подтверждение.
- Legacy Windows/MSSQL не обновляются или не перезапускаются как побочный шаг
  application-диагностики.

## Как поддерживать документ

Основной принцип: один факт — одно место. При изменении:

| Изменение | Основной документ |
|---|---|
| Backend component, ownership или data flow | этот `BACKEND_GUIDE.md` или [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md) |
| Endpoint, payload, status | точный Java/PHP API contract |
| Operator button, sync, report, error | [OPERATIONS_RUNBOOK.md](OPERATIONS_RUNBOOK.md) |
| Java image/env/health/deploy | [JAVA_DOCKER_RUNTIME.md](JAVA_DOCKER_RUNTIME.md) |
| Platform bootstrap, migration, backup/recovery | [BOOTSTRAP_AND_RECOVERY.md](BOOTSTRAP_AND_RECOVERY.md) |
| Plugin config/lifecycle | README owning plugin |
| Folio schema/business invariant | Java business/catalog doc и Folio skill reference |

Полные правила, Definition of Done и автоматическая проверка влияния находятся в
[DOCUMENTATION_POLICY.md](DOCUMENTATION_POLICY.md). Неподтверждённые topology,
version matrix, configuration template, staging, monitoring, RPO/RTO и restore
evidence остаются в [KNOWN_GAPS.md](KNOWN_GAPS.md), а не маскируются как готовая
инструкция.

WordPress и Java имеют отдельные versioned documentation-impact checks. Каждый
проверяет только свой репозиторий; связь Java change -> WordPress backend/runtime
runbook пока обеспечивается skills и review, а не одним общим CI.

### Менеджерский контекст клиента

Проверено по локальному коду 2026-09-07. `pc-order-import-export/inc/ManagerWorkspace.php`
владеет страницей `pcoe-customers` и AJAX `pcoe_manager`; инструкция —
[Работа менеджера с клиентскими документами](OPERATIONS_RUNBOOK.md#работа-менеджера-с-клиентскими-документами).
Авторизация проверяет менеджера (`manage_woocommerce` + nonce), принадлежность
заказа проверяется по выбранному `customer_id`. Цены читаются в коротком контексте
клиента с обязательным восстановлением текущего пользователя и `WC()->customer`;
авторизация и запись не выполняются внутри этого контекста. Аллокатор `paint-core`
принимает необязательный явный preference; отсутствие аргумента сохраняет прежнее
поведение клиентской корзины. ФОЛИО payload и создание/связь дочерних заказов
переиспользуют `pc-folio-order-link`; Java остаётся владельцем окончательного
складского распределения и учётных записей. Hook `pc_folio_child_order_item_prepared`
фиксирует складской план менеджерского дочернего заказа до смены статуса.

Manager audit meta: `_pcoe_manager_created_by`, `_pcoe_manager_updated_by`,
`_pcoe_source_order_id`; durable command: `_pcoe_manager_command`. Preview и результаты
обычного повтора нового черновика хранятся в ограниченных по времени transients.
Новая таблица и миграция не нужны. Фильтр `pc_folio_documents_request_context`
устанавливается только после проверки manager endpoint; публичный клиентский
endpoint по умолчанию сохраняет контекст вошедшего клиента.

### Аудит заполнения документов ФОЛИО

Самостоятельный read-only Java `/admin/folio/document-audit` и WordPress
`lavka-reports/inc/class-document-audit.php` + `document-audit.js`.
Категория/проверки/версия принадлежат Java; WordPress отображает и экспортирует
реестр, контролирует права, nonce и пагинацию. Profit classifier не меняется.
Подробности, закрытый доступ и запуск:
[контракт потребителя](api/FOLIO_DOCUMENT_AUDIT_FRONTEND.md),
[runbook](OPERATIONS_RUNBOOK.md#аудит-документов-фолио).

### Сохранённая прибыль

Java владеет V15 `folio_profit_report_month/revision` в application MariaDB,
идемпотентным расчётом и итогами диапазона. WordPress0.5.0 читает историю через
`class-profit-history.php`; `profit-history.js` открывает неизменённый DTO
в существующем viewer. В ФОЛИО нет новых таблиц/записей.
[Контракт и запуск](api/FOLIO_PROFIT_SAVED_REPORTS_FRONTEND.md).


### Изменение purchase preview от 2026-09-11

Подготовлены выбор упаковки в сценарии/на SKU, сохранение режима в экспорте,
допуск отрицательного свободного остатка и обязательная schema 6. Java-исправление
source mapping находится в отдельной ветке `codex/purchase-preview-stock`.
Проверки этого изменения: 50 Java unit-тестов в семи наборах, Maven package,
шесть PHP suites, desktop/mobile UI fixture, UK/RU catalogs и documentation gates
обоих репозиториев. Production и настоящая интеграционная UI-приёмка не выполнялись.
Требования к выпуску и ограничения — в
[операторском регламенте](OPERATIONS_RUNBOOK.md#обновление-preview-свободный-остаток-и-упаковка).


### Дополнительные прайсы поставщиков

Импорт XLSX принадлежит WordPress `lavka-price-sync`: `supplier-price-model.php`
разбирает данные, `supplier-prices.php` хранит версии в двух prefixed InnoDB
таблицах `lps_supplier_prices` / `lps_supplier_price_heads`,
`supplier-prices-admin.php` предоставляет защищённый admin-post workflow.
Источник сопоставлений — read-only активный `folio_product_metric_current`
(поставщик, source_database, sku, primary_barcode, generation_id). Записи цен,
товаров и складских метрик не меняются. Purchase preview закрепляет версии
и добавляет `supplierPrices` в строки и экспорт.
[Эксплуатация, ограничения, приёмка и откат](OPERATIONS_RUNBOOK.md#импорт-прайса-поставщика).
