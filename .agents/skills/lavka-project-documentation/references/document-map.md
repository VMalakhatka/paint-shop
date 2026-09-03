# Карта документов и ответственности

Используй эту карту, когда нужно выбрать канонический документ, провести ревизию или
расширить changed-path validation.

## Поддерживаемые входные документы

| Знание | Каноническое место |
|---|---|
| Безопасная точка входа | `README.md`, затем `docs/README.md` |
| Архитектура, ownership и data flow | `docs/SYSTEM_OVERVIEW.md` |
| Backend code map, API families, environments и development workflow | `docs/BACKEND_GUIDE.md` |
| Sync/report/status/error workflow | `docs/OPERATIONS_RUNBOOK.md` |
| Локальный старт, перенос и recovery платформы | `docs/BOOTSTRAP_AND_RECOVERY.md` |
| Java image/container/env/health/deploy | `docs/JAVA_DOCKER_RUNTIME.md` |
| Каталог и композиция skills | `docs/SKILLS_CATALOG.md` |
| Правила актуализации и checks | `docs/DOCUMENTATION_POLICY.md` |
| Неподтверждённые риски и очередь | `docs/KNOWN_GAPS.md` |
| Пользовательские сценарии | `docs/SITE_USER_GUIDE_UK.md` |
| Оптовая справка на сайте, заказ, импорт/экспорт, черновики, повтор старого заказа, склады, checkout split, баланс и документы ФОЛІО | `docs/WHOLESALE_CUSTOMER_GUIDE_UK.md` |
| Media workflow оператора | `docs/MEDIA_MANAGER_GUIDE_UK.md` |
| Точный request/response/status | Java `docs/api` или contract владельца plugin |
| Конфигурация/lifecycle одного plugin | `wp-content/plugins/<slug>/README.md` |

Roadmap, brief и TODO являются планом или рабочим материалом, пока их содержание не
подтверждено кодом и не перенесено в поддерживаемый документ.

## Маршрут по типу изменения

| Изменение | Основной документ | Фактический владелец |
|---|---|---|
| Admin UI, sync, report, cron, statuses | Operations runbook | `$lavka-woo` + профильный domain skill |
| Component/data flow/owner | System overview | `$lavka-woo` |
| Backend code map/API family/environment/development workflow | Backend guide | `$lavka-woo` + владелец затронутого слоя |
| WordPress deploy/config/restore | Bootstrap/recovery | `$lavka-woo` |
| Java Docker/env/health/deploy | Java runtime runbook | `$build-java-docker-runtime` |
| Media Library/S3/Folio media | Media guide | `$image-in-woo`; provider layer — `$manage-ovh-infrastructure` |
| Оптовая справка/контекстные ссылки, каталог/список, импорт/экспорт, черновики, повтор заказа, выбор склада, checkout split, баланс и документы клиента | Wholesale customer guide | `$lavka-woo`; при затрагивании ФОЛІО — `$work-with-folio-mssql` |
| MSSQL schema/business rule | Java API/reference владельца | `$work-with-folio-mssql` |
| Skill boundary/composition | Skills catalog | `$skill-creator` + `$personal-skill-router` при составном каталоге |
| Новый неизвестный риск | Known gaps | владелец соответствующего слоя |

## Definition of Done

Для применимых пунктов документ фиксирует prerequisites, роль/capability, источник
конфигурации без secret values, read/write effect, preview/apply, progress и terminal
states, доказательство результата, retry/idempotency, unknown outcome, logs/IDs,
deploy/activation, rollback, остаточный риск и дату проверки.

Code-only refactor без изменения внешнего контракта всё равно обновляет README
владельца: указывает сохранённый invariant и новую проверку. Не создавай фиктивную
правку общего runbook только для прохождения gate.

## Контроль

- `scripts/check-documentation.py` — обязательные документы, относительные ссылки и
  changed-path impact.
- `.githooks/pre-commit` — staged gate после включения `core.hooksPath`.
- `.github/workflows/documentation-impact.yml` — pull-request gate.
- Protected branch должен требовать CI check и запрещать прямой push.
- Java-репозиторий имеет собственный `scripts/check-documentation.py`, hook и PR
  workflow. Оба локальных gate не проверяют cross-repository зависимость автоматически.

При добавлении нового component path одновременно добавь его owner, основной
документ и pattern в `IMPACT_RULES`. При закрытии gap замени его точным остаточным
ограничением или удали, если ограничений больше нет.
