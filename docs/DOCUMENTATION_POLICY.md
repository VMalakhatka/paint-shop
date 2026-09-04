# Правила ведения документации

Цель — чтобы изменение кода, конфигурации или бизнес-процесса оставляло после себя
проверяемую инструкцию, а не только историю чата.

## Один факт — одно основное место

| Вид знания | Где хранить |
|---|---|
| Общий пользовательский сценарий | `SITE_USER_GUIDE_UK.md` |
| Оптовая справка на сайте, заказ, импорт/экспорт, черновики, повтор заказа, склады, checkout, баланс и документы клиента | `WHOLESALE_CUSTOMER_GUIDE_UK.md` |
| Операторские кнопки, статусы, ошибки, stopping condition | `OPERATIONS_RUNBOOK.md` |
| Полный media workflow | `MEDIA_MANAGER_GUIDE_UK.md` |
| Архитектура и ownership | `SYSTEM_OVERVIEW.md` |
| Backend code map, API families, environment и development workflow | `BACKEND_GUIDE.md` |
| Развёртывание/восстановление | `BOOTSTRAP_AND_RECOVERY.md` |
| Java image/container/env/health/deploy | `JAVA_DOCKER_RUNTIME.md` |
| Точный payload/response endpoint | API-документ владельца Java/PHP |
| Конфигурация одного plugin | `wp-content/plugins/<slug>/README.md` |
| Правила работы Codex | профильный `SKILL.md`/`references` |
| Будущая функция | roadmap/brief с явной пометкой «не реализовано» |

Не копировать один длинный алгоритм в несколько файлов. В соседнем документе давать
ссылку и кратко фиксировать только границу, которая нужна его аудитории.

## Статусы утверждений

Использовать явные формулировки:

- `Подтверждено` — наблюдается в текущем коде, схеме или воспроизводимой проверке;
- `Расчёт` — получено из подтверждённых данных по указанной формуле;
- `Вывод` — логически следует из фактов, но не проверен напрямую;
- `Нужно проверить` — необходим источник или тест;
- `План` — функция ещё не является рабочим поведением.

Изменчивое значение сопровождается `Проверено: YYYY-MM-DD`, окружением и видом
источника. Не писать конкретный локальный/production ID как переносимую константу.

## Что обновлять вместе с изменением

| Изменение | Обязательная документация |
|---|---|
| Новый/изменённый admin screen | operator runbook, UI strings/translations |
| Изменение оптовой справки/контекстных ссылок, каталога, импорта/экспорта, черновиков, повторного заказа, выбора склада, checkout split, баланса или документов клиента | `WHOLESALE_CUSTOMER_GUIDE_UK.md` и при необходимости общий user guide |
| Новый sync/job/cron/status | owner, lock, preview/apply, terminal states, retry/recovery |
| Новый Java endpoint/field/status | API-контракт и WordPress consumer |
| Изменение backend component/ownership/data flow | `BACKEND_GUIDE.md` или `SYSTEM_OVERVIEW.md` |
| Новая table/meta/option/migration | владелец данных, lifecycle, backup/rollback |
| Новый plugin | plugin README, deploy manifest, config, activation policy, rollback |
| Новый secret/feature flag | только имя, источник, safe default и restart requirement |
| Изменение deploy | bootstrap/recovery, precheck, postcheck, rollback |
| Изменение Java Dockerfile/Compose/env/health/deploy | `JAVA_DOCKER_RUNTIME.md`, а при переносе платформы также bootstrap/recovery |
| Изменение отчёта/формулы | формула, источник, границы, контрольная сверка |
| Новый внешний сервис | ownership, test/live separation, idempotency, unknown outcome |
| OVH contract/DNS/compute/S3 policy | service ID/owner, сильный источник, backup/export, impact и rollback |
| Изменение media flow | Folio/attachment/S3 authority, dry-run, exact object proof и storefront postcheck |
| Новое устойчивое правило проекта | профильный skill reference |

## Оптовая инструкция: канон и публикация

Изменение, которое видно оптовому клиенту или меняет его действия, имеет два
обязательных слоя документации:

1. Каноническая подробная инструкция:
   `docs/WHOLESALE_CUSTOMER_GUIDE_UK.md`.
2. Опубликованная на сайте интерактивная справка **«Як замовляти»**:
   `wp-content/mu-plugins/pc-wholesale-help.php`, её изображения, стили и переводы
   `pc-wholesale-help`.

Чат **«Создать документацию Woo Lavka»** после изменения каталога, быстрого
заказа, корзины, импорта/экспорта, черновиков, checkout, доставки, оплаты,
разделения заказов, баланса или документов клиента должен:

1. сверить новое поведение с исполняемым кодом и доступными ролями;
2. дополнить каноническую инструкцию для оптового клиента;
3. синхронизировать сокращённый текст, тематические ссылки и при необходимости
   скриншоты опубликованной справки;
4. обновить английские `msgid`, украинский и русский переводы без строк интерфейса,
   встроенных напрямую на украинском;
5. проверить страницу под разрешённой оптовой ролью, тематический anchor и ссылку
   с изменённого экрана; после deploy повторить проверку на production.

Чаты не передают сообщения друг другу напрямую. Общий Git diff, эта политика и
канонический документ являются handoff между разработкой и документационным чатом.
В итоговом сообщении нужно отдельно назвать три состояния:

- каноническая инструкция обновлена;
- справка на сайте обновлена в коде;
- production-страница проверена после deploy.

Если выполнены не все три пункта, нельзя писать, что пользовательская инструкция
полностью опубликована. Нужно явно указать **«публикация ожидает deploy»** или
**«production-проверка не выполнена»**.

## Definition of Done документации

Изменение не считается полностью переданным в эксплуатацию, пока не указаны:

- назначение и владелец компонента;
- prerequisites и capability/роль;
- источник и место конфигурации без значений секретов;
- что операция читает и что изменяет;
- preview/apply и явное подтверждение, если применимо;
- ожидаемый progress и terminal statuses;
- доказательство бизнес-результата, а не только HTTP 200;
- timeout/retry/idempotency и unknown outcome;
- логи, request/job/run ID и безопасный диагностический пакет;
- deploy, activation/migration, rollback и остаточный риск;
- дата и источник последней проверки.

## Автоматический контроль влияния изменений

Проект использует три последовательных барьера:

1. Project skill требует выбрать основной human-документ в той же задаче.
2. Versioned pre-commit hook проверяет staged paths по карте «код → документ».
3. GitHub Actions повторяет проверку для всей разницы pull request с base branch.

Базовая проверка структуры и относительных ссылок:

```bash
python3 .agents/skills/lavka-project-documentation/scripts/check-documentation.py
```

Проверка текущих staged, unstaged и untracked изменений:

```bash
python3 .agents/skills/lavka-project-documentation/scripts/check-documentation.py --working-tree
```

Включение versioned hook выполняется один раз в локальном checkout:

```bash
git config core.hooksPath .githooks
```

Hook запускает проверку с `--staged`. CI workflow
`.github/workflows/documentation-impact.yml` запускает её с `--base` для pull
request. Чтобы CI нельзя было обойти, в настройках protected branch нужно запретить
прямой push и сделать check **Documentation impact / documentation-impact**
обязательным. Эта настройка находится вне Git и должна быть подтверждена владельцем
репозитория.

Карта путей хранится в
`.agents/skills/lavka-project-documentation/scripts/check-documentation.py`. Она покрывает собственные
plugins, MU-plugins, child theme, deploy/config, sync/report, media, checkout,
платежи, доставку и project skill. Для нового компонента его path pattern и основной
документ добавляются в карту одновременно с самим компонентом.

Проверка доказывает, что связанный документ затронут и ссылки разрешаются. Она не
доказывает правильность смысла инструкции. Автор вручную сверяет команды, статусы,
safe defaults, rollback и дату с исполняемым кодом. Code-only изменение без
пользовательского эффекта всё равно должно обновить README владельца: зафиксировать
неизменившийся контракт, новую проверку или техническое ограничение, а не создавать
фиктивную правку общего runbook.

Java находится в отдельном репозитории, поэтому WordPress CI не видит его diff.
В `kreul_com_ua` добавлен собственный `scripts/check-documentation.py`, versioned
pre-commit hook и pull-request workflow: они требуют локальный API/business/runtime
документ по changed paths. Этот gate не может доказать обновление соседнего
WordPress-runbook. Поэтому изменение runtime считается незавершённым, пока при
необходимости не обновлён [JAVA_DOCKER_RUNTIME.md](JAVA_DOCKER_RUNTIME.md) или
[BACKEND_GUIDE.md](BACKEND_GUIDE.md); это cross-repository правило дополнительно
закреплено в профильных skills.

## Секреты и чувствительные данные

Запрещено добавлять в Git, docs, skills, examples и logs:

- пароли, API keys, tokens, private keys и authentication salts;
- connection strings и полные внутренние адреса;
- банковские реквизиты, customer payloads и персональные данные;
- production dumps, raw registry exports и неочищенные SQL definitions.

В документации используются placeholders и имена переменных. Проверяется только факт
наличия и безопасное значение write-флага. Если секрет когда-либо был committed, его
удаление из текущего файла недостаточно: требуется ротация и отдельное решение о
history cleanup.

## Ревизия

- После каждого изменения — проверка затронутых ссылок и инструкций.
- Ежемесячно — operator runbook, расписания, owners и список известных ошибок.
- Ежеквартально — bootstrap/recovery, dependency/version matrix, RPO/RTO и test restore.
- После инцидента — только воспроизводимый invariant, причина и stopping condition;
  сырые логи остаются вне docs.

Старый факт заменять, а не добавлять рядом противоречащую заметку. Если поведение ещё
не подтверждено, обновлять [KNOWN_GAPS.md](KNOWN_GAPS.md), а не маскировать пробел
уверенной инструкцией.
