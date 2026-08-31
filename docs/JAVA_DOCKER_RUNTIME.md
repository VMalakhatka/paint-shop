# Java / Docker runtime KREUL

Этот runbook описывает сборку, локальный запуск, диагностику и production deploy
Java API `kreul_com_ua`. Он дополняет общий
[BOOTSTRAP_AND_RECOVERY.md](BOOTSTRAP_AND_RECOVERY.md): здесь находится точная
последовательность runtime-проверок, а бизнес-логика ФОЛИО и WordPress остаётся в
своих документах.

Проверено по Java-проекту и `$build-java-docker-runtime`: 2026-08-29.

## Владелец и границы

| Слой | Ответственный skill |
|---|---|
| Dockerfile, Compose, Java image/container, env contract, health, logs, deploy/rollback | `$build-java-docker-runtime` |
| Spring/ФОЛИО business code, jTDS и MSSQL-запросы | `$work-with-folio-mssql` |
| WordPress proxy, plugins и Woo workflow | `$lavka-woo` |
| Host, VM, Docker daemon, disk, network и firewall | `$server-lavka` |
| OVH service, contract, public network/vRack | `$manage-ovh-infrastructure` |
| Внешняя backup/restore-копия | `$manage-hetzner-backups` |

Запрос на диагностику не разрешает rebuild, restart или deploy. Production deploy
выполняется только после отдельного решения, проверки backup и готового rollback.

## Подтверждённая платформа

- Spring Boot `3.2.3`, Java `17`, Maven;
- multi-stage `Dockerfile`: Maven/Temurin 17 для сборки и Temurin 17 для runtime;
- image и container: `kreul-api`, application port `8080`;
- `docker-compose.yml` — базовая локальная модель, Docker Desktop override —
  `docker-compose.docker.yml`;
- production deploy — `deploy.sh`; `deploy_save.sh` является legacy-копией и не
  считается каноническим;
- liveness endpoints: `/` и `/healthz`;
- production image собирается для `linux/amd64`.

Перед использованием всё равно сверяйте текущий код: runtime-файлы могут измениться
раньше этого документа.

## Четыре разных окружения

| Режим | Env | Как Java видит сервисы host |
|---|---|---|
| Java из IDE/Maven на Mac | IDE/local shell | loopback относится к Mac |
| Container в Docker Desktop | `.env.docker` поверх Compose | `host.docker.internal` |
| Production container на Linux | remote `.env.prod` | loopback допустим только при подтверждённом host networking |
| Внешняя БД/API | отдельный runtime secret/config | private network или remote TLS endpoint |

`127.0.0.1` внутри обычного bridge-container означает сам container. Одинаковое имя
переменной или порт не доказывает одинаковую сетевую доступность.

## Env contract без секретов

Текущие локальные файлы `.env`, `.env.docker`, `.env.prod` и `env-local.sh` не
являются документацией и не должны попадать в Git. Сверяйте только набор имён:

- Spring profile и application port;
- MSSQL/ФОЛИО endpoint и credentials;
- WordPress/MariaDB endpoint и credentials;
- server-side API tokens;
- TLS/truststore settings;
- write-enable, sync и feature flags;
- окно ожидания планового рестарта Folio MSSQL:
  `LAVKA_FOLIO_ACCOUNTING_PRICE_NATIVE_RESTART_WAIT_SECONDS` (по умолчанию 600)
  и интервал probe
  `LAVKA_FOLIO_ACCOUNTING_PRICE_NATIVE_RESTART_PROBE_INTERVAL_SECONDS`
  (по умолчанию 15);
- JVM/resource/log settings.

Не печатайте значения через `cat`, shell tracing, `docker inspect`, full environment
dump или raw application properties. Для каждого endpoint фиксируйте только класс:
`loopback`, `host.docker.internal`, Compose service, private network или remote TLS.
Перед start отдельно подтверждайте write-enable flags: название файла `.env` не
означает безопасный local profile.

Эти два restart-параметра не разрешают слепой retry `JDBC commit failed`.
`native-range` продолжает работу только после fingerprint/postcheck-доказательства:
подтверждённый rollback повторяется один раз, подтверждённый commit не повторяется,
а неоднозначный исход остаётся `OUTCOME_UNKNOWN`. Механизм работает, только если
Java-container не перезапускался вместе с MSSQL.

## Локальная проверка и запуск

В корне Java-проекта:

```bash
docker compose -f docker-compose.yml -f docker-compose.docker.yml config --quiet
docker compose -f docker-compose.yml -f docker-compose.docker.yml up --build -d
docker compose -f docker-compose.yml -f docker-compose.docker.yml ps -a
docker logs --tail 200 kreul-api
curl -fsS http://127.0.0.1:8080/healthz
```

До image build отдельно запустите узкие тесты и Maven package под Java 17. Наличие
`-DskipTests` в Dockerfile означает, что image build сам по себе тесты не доказывает.
После start проверяйте не только HTTP 2xx, но и один безопасный read-only сценарий
через WordPress proxy. Мутирующие sync/admin endpoints на этом этапе не запускаются.

## Production deploy

Каноническая последовательность текущего `deploy.sh`:

1. Подтвердить чистую revision, Java 17 tests/package и target `linux/amd64`.
2. Проверить SSH-доступ, архитектуру host, Docker daemon, disk, remote runtime/log
   directories и права на них.
3. Проверить полный набор env keys без вывода значений и безопасное состояние
   write-flags.
4. Зафиксировать текущие image/container, env backup и способ возврата.
5. Собрать image, передать его и env отдельными runtime-артефактами.
6. Загрузить image, заменить container и проверить internal `/healthz`.
7. Подтвердить внешний 2xx вручную: текущий script не делает external health failure
   блокирующим.
8. Проверить WordPress proxy и один read-only бизнес-запрос.
9. Только после этого считать deploy успешным и отдельно разрешать расписания/write.

Если SSH/terminal оборвался, результат считается неизвестным. Сначала read-only
проверяются remote files, image, container, logs и health; повторный deploy вслепую
запрещён.

## Диагностика по слоям

Проверяйте последовательно и не меняйте несколько слоёв одновременно:

1. Maven tests/package;
2. image build и правильная platform;
3. container process и restart count;
4. bind port и `/healthz`;
5. сеть из namespace container;
6. доступность MariaDB/MSSQL/внешнего API;
7. Spring binding, Flyway и application error;
8. WordPress proxy и бизнес-контракт.

Сохраняйте время, revision/image ID, последнюю завершённую стадию, HTTP status и
короткий очищенный фрагмент ошибки. Не сохраняйте raw env, connection strings или
customer payloads.

## Rollback и stopping conditions

Остановитесь и возвращайтесь к предыдущему image/env, если:

- internal health не стал успешным в заданное окно;
- container циклически перезапускается;
- migration или dependency outcome неизвестен;
- внешний health или WordPress proxy не подтверждён;
- выяснилось, что используется неправильное окружение или write-profile.

Автоматический rollback `deploy.sh` ориентируется на internal health. После rollback
вручную подтвердите работающий container, внешний endpoint и WordPress proxy; один
факт запуска процесса недостаточен.

## Подтверждённые технические долги

- `.dockerignore` отсутствует: build context включает ненужные generated/ignored
  файлы; до регулярных builds нужен безопасный allow/ignore contract.
- Dockerfile пропускает tests; они обязательны отдельным шагом.
- Runtime image использует полный JDK и по умолчанию запускается от root.
- В tracked `application.properties` обнаружены непустые sensitive defaults. Их
  нужно удалить и затронутые credentials ротировать отдельной задачей.
- Legacy `env-local.sh` хранит и печатает sensitive values; его нельзя запускать в
  shared terminal/log до исправления.
- Создание remote directory в `deploy.sh` не гарантировано; это обязательный
  preflight.
- `seccomp=unconfined` — широкое исключение, требующее отдельного аудита.
- External health сейчас неблокирующий; успех подтверждается вручную.

Эти пункты не являются разрешением исправлять production автоматически. Каждый долг
закрывается отдельным изменением с тестом, deploy plan и rollback.

## Когда обновлять этот документ

Обновляйте runbook в той же задаче при изменении Dockerfile/Compose, env key contract,
network mode, port/health endpoint, build platform, deploy stages, remote paths,
rollback или runtime prerequisites. Точный Spring API-контракт обновляется в
Java `docs/api`, а изменение WordPress consumer — в документации его plugin.

После обновления запустите проверки из
[DOCUMENTATION_POLICY.md](DOCUMENTATION_POLICY.md). Зелёная автоматическая проверка
подтверждает наличие связанного документа и исправность ссылок, но не заменяет
ручную сверку команд с текущим Java-кодом.
