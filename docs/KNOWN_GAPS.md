# Пробелы, риски и очередь документации

Это не список всех будущих функций. Здесь только то, без чего эксплуатация, перенос
или восстановление остаются непроверяемыми.

Проверено по доступным репозиториям и skills: 2026-08-29.

## P0 — безопасность и восстановимость

### Ротация секретов, попавших в отслеживаемые файлы

Подтверждено: в Java-репозитории отслеживается локальный startup script с literal
credentials; старые проектные инструкции также содержали конкретные инфраструктурные
реквизиты. Новые документы их не повторяют.

Нужно:

1. составить список затронутых систем без вывода значений;
2. перенести локальные параметры в ignored env/template contract;
3. ротировать каждую затронутую credential;
4. проверить consumers и только затем отключить старые значения;
5. отдельно решить, требуется ли очистка Git history.

Удаление строки из текущей ветки не отменяет компрометацию значения в истории.

### Auth/authz Java `/admin` и `/sync`

Статический аудит Java-ветки ранее не подтвердил входящую Spring Security protection.
Нужно доказать фактический reverse proxy/network boundary и authorization каждого
mutating endpoint. До этого нельзя расширять внешнюю доступность или считать слово
`admin` в URL защитой.

### Backup и test restore

Нужно утвердить RPO/RTO, retention, off-provider copy, encryption/key owner и дату
последнего test restore отдельно для:

- host/hypervisor config;
- Windows VM;
- MSSQL system/user databases и ФОЛИО-конфигурации;
- WordPress MariaDB и обязательных файлов;
- Java env/artifact/logs;
- Media Library metadata и OVH/S3 objects.

Наличие архива или VM snapshot без тестового восстановления недостаточно.

### Production topology и аварийный доступ

Нужно подтвердить host OS, hypervisor/version, VM definition/autostart, volume mapping,
console path, network path, MSSQL instances, базы и зависимых клиентов. Без этого нельзя
безопасно обещать перенос или восстановление «с нуля».

### OVH account, DNS и Object Storage

Нужно подтвердить связь каждого используемого домена/API/SSH alias с точным OVH
service, compute и vRack. Для media bucket отдельно проверить project/service owner,
billing, policy/CORS, lifecycle, versioning/retention, quota/alerts, CDN/DNS и раздельные
credentials WordPress/Java/CLI. S3 index и публичный URL этого не доказывают.

## P1 — воспроизводимый запуск

### Конфигурационный контракт

Создать versioned templates без значений для WordPress и Java:

- имя переменной/constant/option;
- назначение и владелец;
- required/optional;
- safe default;
- secret/non-secret;
- окружения;
- необходимость restart/migration;
- проверка факта настройки.

### Матрица версий и зависимостей

Зафиксировать принятые версии WordPress, WooCommerce, PHP, MariaDB, Java, Maven,
Spring Boot, Docker, jTDS, Media Cloud, темы и критических сторонних plugins. Для каждой
указать owner обновления, compatibility test и rollback.

### Production-like staging

Локальный `paint.local` не заменяет staging с production-like PHP/webserver/cache,
callbacks и данными. Нужна площадка, где можно проверить deploy, migrations, cron и
read-only integrations до включения production writes.

### Полностью исполняемый bootstrap

Текущий [BOOTSTRAP_AND_RECOVERY.md](BOOTSTRAP_AND_RECOVERY.md) задаёт gates, но не все
значения подтверждены. После закрытия topology/config/backup gaps подготовить
версионированный checklist с входами, автоматическими prechecks и актом приёмки.

### Monitoring и уведомления

Назначить owner и пороги для availability, TLS/DNS, disk, MariaDB, Java health,
MSSQL/Folio connectivity, failed cron/jobs, stale ecosystem lock, snapshot freshness,
payment/fiscalization/TTN failures и backup age.

## P1 — операционная управляемость

### Единый реестр процессов

Для каждого cron/sync/report нужны: owner, schedule, lock name, read/write scope,
status location, terminal states, typical duration, timeout, retry rule, alert и
recovery. Сейчас сведения есть по частям в коде и skills.

### Единая корреляция

Унифицировать отображение `requestId/jobId/runId/orderId` между UI, WordPress event
log и Java log, сохраняя privacy. Это ускорит разбор сквозной ошибки без копирования
полных payloads.

### Роли и обучение

Нужно зафиксировать владельца каждого процесса, capability matrix, кто имеет право на
preview/apply/live-write, кому приходит incident notification и кто принимает решение
о rollback. Подготовить короткие учебные сценарии для нового менеджера.

## P2 — качество и изменение системы

- WordPress и Java-репозитории уже имеют versioned changed-path documentation gates.
  Осталось добавить secret-pattern scanner, сделать оба checks обязательными для
  protected branches и автоматизировать cross-repository проверку, когда Java diff
  требует обновления WordPress runtime/backend runbook.
- Smoke tests после deploy каждого критического собственного plugin.
- Проверяемый rollback schema migrations.
- Architecture Decision Records для изменений границ WordPress/Java/ФОЛИО.
- Data retention и privacy policy для logs, events, audit CSV и customer data.
- Dependency/license/renewal inventory для OVH, S3, доменов, SMTP, payments и plugins.
- Регулярная disaster-recovery тренировка с измеренными RTO/RPO.
- Отдельный change calendar для массовых учётных операций и сезонных периодов.

## Аналитика и закупки

До готового плана заказа остаётся подтвердить каналы, возвраты/net metrics,
поставщика, lead time, MOQ, кратность, in-transit, service level и историю stockout.
Текущий snapshot уже полезен для рисков и приоритетов, но не для автоматического
заказа или динамического ценообразования.

## Как закрывать пункт

1. Получить сильный источник или воспроизводимую проверку.
2. Обновить основной документ/skill, а не только этот список.
3. Добавить дату, owner, проверку и rollback.
4. Удалить закрытый gap или заменить его точным следующим ограничением.
