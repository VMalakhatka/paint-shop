# Запуск с нуля, перенос и восстановление

Этот документ задаёт безопасную последовательность. Он ещё не является полностью
автоматизированной «одной кнопкой»: часть production topology, backup и внешних
договоров требует подтверждения. Такие места перечислены в
[KNOWN_GAPS.md](KNOWN_GAPS.md).

Проверено по WordPress и Java-репозиториям: 2026-08-29.

## Сначала выбрать сценарий

| Сценарий | Цель | Что нельзя предполагать |
|---|---|---|
| Локальная разработка | поднять безопасную копию сайта и Java | что локальные ID/config равны production |
| Новая production-платформа | перенести работающий магазин без потери данных | что deploy кода переносит БД, uploads и секреты |
| Disaster recovery | восстановить согласованное состояние после отказа | что наличие архива доказывает восстановимость |

Для каждого сценария заранее назначаются владелец, окно работ, RPO/RTO, источник
данных, точка возврата и критерии прекращения.

## Что нужно для воспроизводимого восстановления

Git недостаточно. Нужны отдельные проверенные объекты:

- WordPress-код и список версий сторонних plugins/theme;
- согласованный dump MariaDB и файлы, которые не восстанавливаются из S3;
- environment-specific WordPress config без передачи секретов в Git;
- Java-код или образ, schema/migration version и отдельный env contract;
- ФОЛИО/MSSQL backup, конфигурация клиента и runtime-зависимости;
- Media Library metadata, OVH/S3 objects и индекс их соответствия;
- inventory OVH services: contracts/renewals, domain/DNS/MX, compute/vRack и Object
  Storage project/bucket policy;
- DNS/TLS, cron/Action Scheduler, SMTP, cache и внешний proxy;
- конфигурация WayForPay, Checkbox и Nova Poshta с безопасно выключенной записью;
- доказательство test restore и контрольные данные для приёмки.

## Локальное окружение WordPress

1. Установить поддерживаемую локальную среду WordPress/MariaDB и создать сайт
   `paint.local` либо согласованный новый alias.
2. Разместить checkout этого репозитория в document root.
3. Создать ignored `wp-config.local.php`; отслеживаемые `wp-config.php` и
   `wp-config.common.php` должны содержать только несекретную логику/defaults.
4. Восстановить согласованный локальный dump и uploads либо документированную
   обезличенную dev-копию. Не считать пустую установку эквивалентом магазина.
5. Проверить PHP, MariaDB, WordPress/Woo versions и требуемые extensions.
6. Проверить активные обычные plugins, автоматическую загрузку MU-плагинов, child
   theme, Woo pages, HPOS, permalinks и cron.
7. Проверить frontend, admin, контрольный товар, корзину и один read-only отчёт.
8. Реальные payment/fiscalization/TTN writes и production endpoint должны оставаться
   выключенными.

Полную замену production-базы локальной копией не использовать как обычный deploy.
Это отдельная разрушающая операция с backup, точным target, URL rewrite, проверкой
пользователей/заказов и отдельным разрешением владельца.

## OVH preflight для новой платформы

До provisioning или переключения `$manage-ovh-infrastructure` должен сопоставить:

`OVH service ID → DNS/network → SSH alias/hostname → application → data/backups`.

1. Проверить текущие contracts, renewal и ongoing provider operations.
2. Сохранить DNS zone и TTL; установить зависимые website/API/mail/certificate records.
3. Подтвердить Dedicated/VPS/vRack mapping, region, routes, monitoring и rescue access.
4. Для Object Storage подтвердить project/service, bucket, region, policy, CORS,
   lifecycle, versioning/retention, quotas, billing alerts и credential consumers.
5. Проверить off-provider backup и test restore; OVH resource или snapshot не является
   единственной достаточной копией.

Не выводить принадлежность проекта из DNS name или SSH alias. Provider changes,
DNS/MX, network/firewall, resize/reinstall и bucket policy являются отдельными
операциями с точным target, zone/config backup и rollback.

## Java API: локальный запуск

Подробные команды, network/env model, диагностика и stopping conditions находятся в
[JAVA_DOCKER_RUNTIME.md](JAVA_DOCKER_RUNTIME.md). Здесь остаётся место Java в общем
bootstrap и переносе всей платформы.

Подтверждённая платформа текущего проекта: Spring Boot 3.2.3, Java 17, Maven,
jTDS 1.3.1 для SQL Server 2000 и MariaDB driver. Доступен Dockerfile.

1. Получить checkout `kreul_com_ua` и убедиться, что используется Java 17.
2. Создать ignored локальную environment-конфигурацию. Не использовать tracked shell
   script с literal credentials как источник для нового окружения.
3. Настроить отдельные группы параметров без вывода значений:
   - MS SQL/Folio DataSource;
   - WordPress/MariaDB DataSource;
   - Woo base URL и application credentials;
   - WordPress proxy/Lavka API;
   - OVH/S3;
   - timeouts, batch limits и logging;
   - feature flags учётных цен, snapshots и отчётов.
4. Оставить apply/full-apply/live-write flags выключенными.
5. Для self-signed `paint.local` использовать отдельный dev truststore. Изменение
   системного Java truststore не должно быть единственным воспроизводимым вариантом.
6. Выполнить узкие тесты и сборку под Java 17.
7. Запустить приложение, проверить health и один безопасный endpoint каждой реально
   настроенной зависимости.
8. Только после этого связать WordPress proxy с локальным Java.

Не заменять jTDS или legacy SQL-диалект как побочную часть bootstrap: для этого нужна
отдельная миграция и стенд с проверкой CP1251, generated keys и транзакций.

## Новая production-платформа WordPress

### Подготовка

- Инвентаризировать текущие PHP/webserver/MariaDB/cache/cron/TLS и сторонние plugins.
- Снять согласованные backup БД и файлов и доказать чтение архивов.
- Зафиксировать DNS TTL, maintenance page, окно переключения и rollback target.
- Создать production-like staging, если перенос меняет платформу или версии runtime.
- Проверить совместимость Woo/HPOS, Media Cloud/S3, checkout и внешних callbacks.

### Развёртывание без трафика

1. Подготовить webserver, PHP runtime, MariaDB, TLS и закрытую конфигурацию.
2. Восстановить БД и обязательные файлы; проверить владельца файлов и права.
3. Разместить repository checkout отдельно от web-root и использовать контролируемый
   deploy собственных plugins/MU/theme.
4. Создать ignored `wp-config.production.php`; проверить только наличие constants и
   безопасные write-флаги, не печатать значения.
5. Проверить список `wp-content/deploy_plugins.list`. Политика `manual` означает, что
   наличие файлов не равно активации.
6. Активировать только принятые plugins после миграций/config precheck.
7. Проверить Woo pages из options, HPOS, admin, frontend, media attachment и cache.
8. Настроить system cron/Action Scheduler и доказать, что задания не дублируются.

### Переключение

- Запретить изменения на старой площадке либо выполнить согласованный delta-перенос.
- Повторить согласованный финальный dump/files sync.
- Выполнить DNS/proxy switch и проверить TLS/callback URLs.
- Пройти smoke tests без реальных внешних записей.
- Включать Java proxy, snapshots, синхронизации и внешние интеграции по одному.
- Сохранять возможность возврата трафика до окончания согласованного наблюдения.

## Java production deploy

Точный runtime-регламент и rollback: [JAVA_DOCKER_RUNTIME.md](JAVA_DOCKER_RUNTIME.md).

Текущий проект собирает Linux/amd64 Docker image, передаёт image и отдельный env на
сервер, запускает контейнер с host network и проверяет internal health. Основной
`deploy.sh` сохраняет предыдущий образ/env и пытается автоматический rollback, если
новый контейнер не проходит health-check.

Перед переносом на новую платформу нужно подтвердить:

- target host/architecture, Docker runtime и место для образов/логов;
- безопасный канал передачи env и минимальные права файла;
- volume/log retention и disk capacity;
- internal/external health endpoints;
- auth/authz и сетевой периметр `/admin` и `/sync`;
- совместимость сети с legacy MSSQL и MariaDB;
- rollback image, предыдущий env и stopping condition.

External health, завершившийся предупреждением, не должен превращать неуспешный
deploy в «готово». После deploy проверить container status, logs без секретов, health,
контракт WordPress proxy и одну read-only бизнес-операцию.

Runtime workflow этого раздела принадлежит `$build-java-docker-runtime`; OVH compute
и сеть — `$manage-ovh-infrastructure`/`$server-lavka`, а Java/Folio business code —
`$work-with-folio-mssql`.

## Медиа при переносе или восстановлении

Использовать `$image-in-woo` совместно с `$manage-ovh-infrastructure`:

1. Подтвердить bucket/policy/provider state, не меняя objects.
2. Восстановить WordPress DB с attachment records и относительными
   `_wp_attached_file`; один S3 bucket без metadata недостаточен.
3. Проверить Media Cloud config и безопасный доступ к original/generated sizes.
4. Сверить выборку attachments, exact S3 key/size/ETag через `HEAD`, Woo main/gallery
   IDs и storefront.
5. Перестроить индекс как projection; не считать reindex доказательством физического
   object и не очищать stale rows автоматически.
6. Только затем запускать Folio↔Woo reconcile, сначала dry-run/partial-safe режимом.

Для переноса legacy URLs проверить минимум пять attachments, `guid`,
`_wp_attached_file`, thumbnails, `_thumbnail_id` и gallery. Не overwrite canonical
filename другим содержимым и не присваивать Woo bare S3 URL.

## ФОЛИО и legacy server

Перенос сайта не означает автоматический перенос Windows/Folio. Сначала подтвердить
цепочку OVH service → host → VM → volume → MSSQL → Paint_Ua/Paint_Rus → Java clients.

- Windows Server 2003 R2 и SQL Server 2000 считаются legacy-зависимостью.
- До изменения VM hardware, сети, MSSQL или storage нужны console access, backup и
  проверяемое восстановление.
- Crash-consistent VM snapshot не является единственной достаточной копией MSSQL.
- CP1251, compatibility 80, типы данных и jTDS должны быть проверены на копии.
- Миграцию сайта/Java и миграцию ФОЛИО лучше разделить на разные контролируемые окна.

## Порядок ввода после восстановления

1. Инфраструктура, DNS/TLS, storage и базы.
2. MSSQL/Folio read-only connectivity.
3. Java в safe/read-only режиме.
4. WordPress/Woo и server-side proxy.
5. Один контрольный snapshot склада.
6. Карточки, остатки и цены — последовательно, с контрольной выборкой.
7. Отчёты и кабинет клиента.
8. Автоматические расписания.
9. WayForPay, Checkbox и Nova Poshta writes — отдельно, после тестовых сценариев и
   явного production-разрешения.

## Критерии завершения

- есть подписанный inventory версий и конфигурационных ключей без значений;
- backup восстановлен на изолированной площадке и пройдена контрольная сверка;
- все health-checks и критические пользовательские сценарии прошли;
- snapshots/sync не пересекаются, lock освобождается, terminal statuses сохранены;
- сверены контрольные товары, цены, остатки, роли, заказ и отчёт;
- callbacks и live writes либо подтверждены тестом, либо явно остаются выключенными;
- мониторинг, уведомления, owner, RPO/RTO и rollback задокументированы.
