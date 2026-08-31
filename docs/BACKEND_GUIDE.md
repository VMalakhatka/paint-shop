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

## Основные потоки

| Поток | Последовательность | Где точный контракт |
|---|---|---|
| Карточки/категории | WordPress `lavka-total-sync` -> Java `/sync/run` -> Woo | [OPERATIONS_RUNBOOK.md](OPERATIONS_RUNBOOK.md) |
| Остатки | Java/Folio -> `wp_stock_import` -> Woo location stock | [OPERATIONS_RUNBOOK.md](OPERATIONS_RUNBOOK.md) |
| Цены по ролям | Folio contract -> Java -> role-price meta -> runtime Woo price | plugin README + runbook |
| Woo-заказ -> ФОЛИО | Woo order -> PHP preview/proxy -> Java allocation/idempotency -> Folio documents | [FOLIO_ORDER_JSON_CONTRACT.md](FOLIO_ORDER_JSON_CONTRACT.md) + Java API |
| Учётные цены | WordPress campaign -> Java job/status -> Folio procedure -> postcheck/snapshot | Java `FOLIO_ACCOUNTING_PRICE_*` docs |
| Товарная аналитика | Folio read-only snapshot -> Java calculation -> MariaDB `folio_product_*` -> PHP screen | Java `FOLIO_PRODUCT_SNAPSHOT_API.md` + [план полного контракта сценариев](api/FOLIO_PRODUCT_ANALYTICS_SCENARIOS_BACKEND_TASK.md) |
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
- options владельцев plugins: warehouses, role contracts, schedules, batch limits;
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
