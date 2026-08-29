# Skills проекта Lavka / KREUL

Skill — это инструкция Codex о границах, источниках истины и безопасном workflow.
Human runbook остаётся обязательным: skill не заменяет обучение менеджера,
production approval или документацию внешнего сервиса.

Сквозная human-карта backend находится в [BACKEND_GUIDE.md](BACKEND_GUIDE.md).

Проверено по каталогу skills текущей рабочей среды: 2026-08-29.

## Основные skills

| Skill | Когда использовать | Не использовать вместо |
|---|---|---|
| `$lavka-woo` | Любой код, настройка, deploy, диагностика или операция `paint.local`/KREUL | чистой логики ФОЛИО, provider/server operations |
| `$lavka-project-documentation` | структура, создание, ревизия, актуализация и автоматический контроль документации Lavka/KREUL | владельца фактического поведения кода или разрешения production-операции |
| `$work-with-folio-mssql` | Paint_Ua/Paint_Rus, SQL Server 2000, таблицы/процедуры ФОЛИО, Java DAO | обычного WordPress/MariaDB SQL |
| `$server-lavka` | host, hypervisor, Windows VM, services, disks, RDP, MSSQL runtime, incident | Woo deploy и семантики бизнес-таблиц |
| `$build-java-docker-runtime` | Java/Spring Boot, Dockerfile/Compose, env contract, image, container health и deploy/rollback; human runbook — `JAVA_DOCKER_RUNTIME.md` | физического host, WordPress и бизнес-данных ФОЛИО |
| `$folio-inventory-profit-planning` | остатки, прибыль, капитал, ABC/XYZ, прогноз, закупки | низкоуровневого доступа к ФОЛИО |
| `$image-in-woo` | Media Library, Media Cloud, S3 object proof, attachments, gallery и Folio↔Woo reconcile | bucket policy, billing и общей синхронизации без media |
| `$integrate-nova-poshta-woo` | отделения, почтоматы, многоскладские ТТН, COD, tracking | доставок без Новой почты |
| `$checkbox-ua` | кабинет/API Checkbox, кассы, смены, чеки, возвраты, отчёты | определения, какую оплату WayForPay фискализировать |
| `$checkbox-wayforpay-woo` | WayForPay payment/refund → Woo → Checkbox | общего администрирования Checkbox |
| `$manage-ovh-infrastructure` | OVH Manager, contract/renewal, DNS/MX, compute/vRack и Object Storage policy | WordPress/Java deploy и Woo media assignment |
| `$manage-hetzner-backups` | внешний backup-контур, retention и test restore | application-specific deploy |
| `$personal-skill-router` | запрос одновременно затрагивает две и более подсистемы выше | простого запроса с одним владельцем |
| `$skill-creator` | создать или существенно обновить сам skill | обычной проектной документации |

Проектные `lavka-woo` и `lavka-project-documentation` хранятся рядом с WordPress-кодом
и версионируются вместе с ним. `work-with-folio-mssql` хранится рядом с
Java/Folio-кодом. Остальные skills — персональные и подключаются по предметной
области.

## Порядок сочетания

Для составной задачи применять минимальный набор в таком порядке:

1. `$personal-skill-router` выбирает минимальный набор, когда владельцев несколько.
2. `$lavka-woo` определяет application ownership, локальные соглашения и WordPress deploy.
3. `$lavka-project-documentation` выбирает канонический документ и проверяет impact,
   если задача меняет устойчивое поведение, конфигурацию или эксплуатацию.
4. `$manage-ovh-infrastructure` определяет provider resource, DNS/network/S3 policy;
   `$server-lavka` — host/VM/service topology.
5. `$build-java-docker-runtime` владеет Java image/container/env/health и его rollback.
6. `$work-with-folio-mssql` определяет схему, legacy-совместимость и границу записи.
7. `$image-in-woo` или другой профильный skill задаёт workflow объекта/внешнего сервиса.
8. `$folio-inventory-profit-planning` задаёт бизнес-метрики после подтверждения данных.

Используются только реально затронутые строки этой последовательности. Например,
проверка одного Woo attachment не требует server или inventory skill.

Явное упоминание skill не разрешает запись в production, ФОЛИО, Checkbox или Новую
почту. Разрешение запрашивается непосредственно перед точной мутирующей операцией.

## Типовые запросы

```text
$lavka-woo Проверь, почему полная синхронизация завершилась с ошибками,
ничего не перезапускай и не меняй production.
```

```text
$lavka-project-documentation Проверь актуальность документации после изменения
синхронизации, исправь основной runbook и запусти documentation-impact check.
```

```text
$lavka-woo $work-with-folio-mssql Проследи путь одного Woo-заказа до preview
счёта ФОЛИО и раздели подтверждённые факты, выводы и неизвестное.
```

```text
$server-lavka $work-with-folio-mssql Диагностируй недоступность ФОЛИО:
сначала topology и services, затем MSSQL; работай только read-only.
```

```text
$lavka-woo $folio-inventory-profit-planning $work-with-folio-mssql
Подготовь отчёт по дефициту и неликвидам за согласованный период без создания заказа.
```

```text
$lavka-woo $integrate-nova-poshta-woo Проверь расчёт нескольких посылок и COD,
не создавай реальную ТТН.
```

```text
$lavka-woo $checkbox-wayforpay-woo $checkbox-ua
Проверь идемпотентность тестовой оплаты и чека без live-фискализации.
```

```text
$personal-skill-router $lavka-woo $manage-ovh-infrastructure
$server-lavka $build-java-docker-runtime
Подготовь read-only карту новой production-платформы: OVH service, DNS,
host/runtime и Java health. Ничего не переключай и не деплой.
```

```text
$personal-skill-router $lavka-woo $image-in-woo $manage-ovh-infrastructure
Разбери отсутствующее изображение: attachment и Woo-связь, exact S3 HEAD proof,
а также bucket/provider layer. Не исправляй ФОЛИО и не удаляй object.
```

## Когда обновлять skill

Обновлять skill нужно только для устойчивого знания, которое меняет будущие решения:
ownership, invariant, source of truth, safety boundary, обязательную проверку или
маршрут к поддерживаемому reference. Пошаговые кнопки для менеджера обновляются в
human runbook; точные endpoint/payload — в API-контракте; временный план — в roadmap.

После нового или существенно изменённого skill:

- проверить уникальность имени и точность description;
- связать каждый reference из `SKILL.md`;
- не дублировать соседний domain skill;
- выполнить validator и проверку ссылок;
- обновить карту `personal-skill-router`, если изменился персональный каталог.

`personal-skill-router` — это и есть забытый «skill со сведениями обо всех skills».
Его `references/personal-skill-map.md` хранит короткую карту триггеров, границ и
типовых сочетаний, но актуальный каталог текущей сессии остаётся сильнее этой карты.
