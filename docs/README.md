# Документация Lavka / KREUL

Это единая карта поддерживаемых инструкций. Она отделяет пользовательскую работу,
операции магазина, разработку, API-контракты и знания для Codex.

Проверено по текущему репозиторию и проектным skills: 2026-08-29.

## Быстрый маршрут

| Задача | Основной документ |
|---|---|
| Понять устройство системы и владельца поведения | [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md) |
| Разрабатывать и сопровождать backend PHP/Java/Folio | [BACKEND_GUIDE.md](BACKEND_GUIDE.md) |
| Запустить синхронизацию, увидеть результат или разобрать ошибку | [OPERATIONS_RUNBOOK.md](OPERATIONS_RUNBOOK.md) |
| Развернуть локально, перенести на новую платформу или восстановить | [BOOTSTRAP_AND_RECOVERY.md](BOOTSTRAP_AND_RECOVERY.md) |
| Собрать, запустить, диагностировать или развернуть Java container | [JAVA_DOCKER_RUNTIME.md](JAVA_DOCKER_RUNTIME.md) |
| Инвентаризировать OVH, DNS, compute/vRack или Object Storage | [SYSTEM_OVERVIEW.md](SYSTEM_OVERVIEW.md) + [BOOTSTRAP_AND_RECOVERY.md](BOOTSTRAP_AND_RECOVERY.md) |
| Выбрать skill Codex | [SKILLS_CATALOG.md](SKILLS_CATALOG.md) |
| Понять, что обязано обновляться вместе с изменением | [DOCUMENTATION_POLICY.md](DOCUMENTATION_POLICY.md) |
| Увидеть неподтверждённые места и риски | [KNOWN_GAPS.md](KNOWN_GAPS.md) |
| Пользоваться сайтом и кабинетом | [SITE_USER_GUIDE_UK.md](SITE_USER_GUIDE_UK.md) |
| Загружать и исправлять изображения | [MEDIA_MANAGER_GUIDE_UK.md](MEDIA_MANAGER_GUIDE_UK.md) |
| Проверить Woo → Java/Folio заказ | [FOLIO_ORDER_JSON_CONTRACT.md](FOLIO_ORDER_JSON_CONTRACT.md) |
| Развивать фильтры и многоскладскую товарную аналитику | [FOLIO_PRODUCT_ANALYTICS_FILTERS_PLAN.md](FOLIO_PRODUCT_ANALYTICS_FILTERS_PLAN.md) и [задание Java на полный контракт сценариев](api/FOLIO_PRODUCT_ANALYTICS_SCENARIOS_BACKEND_TASK.md) |
| Ограничить тестовый WayForPay | [WAYFORPAY_TEST_ACCESS.md](WAYFORPAY_TEST_ACCESS.md) |

## Аудитории

### Владелец бизнеса

Использует карту системы, операционный ритм, отчёты, список рисков, RPO/RTO и
критерии готовности новой платформы. Решает, когда разрешена production-запись,
активация платежей, фискализации, ТТН и автоматических расписаний.

### Менеджер и оператор

Использует пользовательскую инструкцию, media guide и операционный runbook. Для
каждой операции сохраняет параметры запуска, время, склад/период, итоговый статус,
количество обработанных/ошибочных строк и request/job ID, если он показан.

### Разработчик

Начинает с [BACKEND_GUIDE.md](BACKEND_GUIDE.md), архитектуры и владельца компонента,
затем читает plugin-specific README, API-контракт Java и проектный skill. Изменение
считается завершённым только вместе с
проверкой, операционной инструкцией, конфигурацией, deploy/rollback и обновлением
документации по правилам [DOCUMENTATION_POLICY.md](DOCUMENTATION_POLICY.md).
Структурой, ревизией и documentation-impact checks владеет
`$lavka-project-documentation`; фактическое поведение подтверждает профильный skill.

### Администратор инфраструктуры

Использует bootstrap/recovery совместно с `server-lavka`,
`manage-ovh-infrastructure` и `manage-hetzner-backups`. WordPress runbook не заменяет
карту host → VM → MSSQL → приложение и доказательство тестового восстановления.

## Виды документов

- `docs/*.md` — инструкции для людей и сквозные проектные решения.
- `docs/api/*.md` — локальные копии или review отдельных API; полный набор Java
  контрактов находится в репозитории `kreul_com_ua/docs/api`.
- `wp-content/plugins/<slug>/README.md` — точная конфигурация и ограничения одного
  плагина.
- `.agents/skills/*` — инструкции Codex. Это не замена runbook для человека.
- `ROADMAP_*.md`, `*_BRIEF.md`, `TODO_*` — планы и рабочие материалы; они не
  доказывают, что функция реализована или включена.

## Источник истины

При конфликте не объединять значения:

1. фактическое read-only состояние нужного окружения и живая схема;
2. текущий исполняемый код и тесты владельца компонента;
3. актуальный API-контракт;
4. поддерживаемый runbook;
5. roadmap, старый README, скриншот или предположение.

Изменчивый факт должен иметь строку `Проверено: YYYY-MM-DD`. Неподтверждённое
утверждение помечается `Нужно проверить`, а не записывается как готовая инструкция.

## Документы, которые ещё не являются регламентом

- `ROADMAP_RETAIL_WHOLESALE_2026.md` и `ROADMAP_WHOLESALE_FIRST_2026.md` — продуктовые
  планы.
- `VARIABLE_PRODUCTS_NEW_CHAT_BRIEF.md` — задание на исследование вариативных
  товаров.
- `FOLIO_CUSTOMER_BALANCE_REPORT_REVIEW.md` — review конкретного отчёта, а не общий
  операторский сценарий.
- `TODO_verifyIMG.md` — исторический рабочий список; рабочий media workflow уже
  вынесен в `MEDIA_MANAGER_GUIDE_UK.md`.

Текущая очередь превращения черновиков в проверяемые инструкции находится в
[KNOWN_GAPS.md](KNOWN_GAPS.md).
