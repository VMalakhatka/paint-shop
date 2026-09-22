# Сохранённые месячные отчёты прибыли и диапазон

Разработка 2026-09-09, WordPress lavka-reports0.5.0. Реализация локальная;
production-миграция и деплой в рамках разработки не выполнялись.

## Решение пользователя и владельцы

Сначала аудит правильности заполнения документов, затем отдельно согласованное
ужесточение отборов. Сохранение результатов не меняет действующие фильтры/формулы.
Java хранит месячные результаты в application MariaDB через wpDataSource.
В legacy ФОЛИО новые таблицы и записи не создаются. WordPress хранение не дублирует.

Логический ключ: база источника × YYYY-MM. Например, Paint_Ua × 2025-07.
`folio_profit_report_month` содержит текущую и последнюю ревизии;
`folio_profit_report_revision` содержит версию, UUID запроса, исходные параметры,
полный ответ с inputs, городами, расходами, МК, остатками, предупреждениями,
аудитом/диагностикой, ограничениями полноты и датами. Миграция Java V15.

Канонический Java-контракт: `docs/api/FOLIO_PROFIT_SAVED_REPORTS_API.md` в
kreul_com_ua. WordPress consumer: `inc/class-profit-history.php`, `profit-history.js`,
bridge `LavkaProfitViewer` в `profit-report.js`. Права manage_woocommerce и отдельный
nonce для всех операций. Токен Java остаётся на сервере; финансовые ответы не логируются.

## Действия API и UI

База `/admin/folio/profit-report/saved`:

- GET fromMonth/toMonth — сохранённый диапазон включительно,1..24месяца.
- GET /{month}?revisionId — точная сохранённая версия, без ФОЛИО.
- GET /{month}/revisions — постраничная история, включая ошибки/предварительные версии.
- POST /{month}/calculate — явное формирование одного месяца и сохранение одной версии.
  UUID requestId и десятичные параметры передаются без преобразования в float.

Экран прибыли по умолчанию загружает только сохранённую историю. Старые live
summary/audit endpoints остаются доступными Java для совместимости, но этот
экран не вызывает их в сохранённом режиме. Нет авторасчёта отсутствующего месяца.
Старые результаты до внедрения хранения не появляются в истории задним числом.

Кнопка диапазона фиксирует выбранные параметры и последовательно рассчитывает
месяцы. Пустая доплата означает серверное значение **каждый месяц**, явный0
остаётся override. «Остановить после текущего месяца» предотвращает следующую
команду, не прерывает текущий POST. При неизвестном исходе stop+requestId,
GET истории; нет автоматического нового UUID/повтора. Номер незавершённой попытки
сохраняется в sessionStorage вкладки, полный отчёт там не хранится.

## Версии и точность просмотра

COMPLETED — расчёт завершён с полным ответом/аудитом по действующему контракту.
PROVISIONAL — предварительный результат; не заменяет ранее опубликованный.
Если месячных результатов ещё нет, первый предварительный доступен как текущий.
FAILED/RUNNING остаются в истории. RUNNING после рестарта не доказывает, что Java
ещё работает. Открытие версии всегда показывает её ID и ID текущей версии месяца.
Ручной публикации предварительной версии в v1 нет.

При сохранении Java использует один calculate(includeDocuments=true), а не
раздельные пересчёты summary/audit. Сохранены все полученные значения; усечённый
аудит не становится полным от записи в БД. Исходное NOLOCK-чтение не является
транзакционным снимком ФОЛИО, хотя сохранённый ответ фиксирован.

Ошибка сохранения не означает отсутствие commit. requestId идемпотентен:
повтор тех же параметров возвращает существующую попытку, другой payload —409.
Конкурирующая старая попытка не откатывает указатель на старую опубликованную версию.

## Диапазон и Excel

Месячные grossProfit, operatingExpenses, profit и прочие потоки суммирует Java.
Выручка продаж не добавляется: её нет в исходном DTO, валовая прибыль не является
выручкой. Missing/null не заменяются нулём: общий показатель null. Предварительные
суммы отмечены complete=false. Курс и налоговая доля не усредняются.

Остатки — начало первого запрошенного месяца и конец последнего; состав складов
должен быть сопоставим. При несовместимости складов/неполноте соответствующий итог
недоступен. Frontend не вычисляет альтернативные финансовые итоги.

Месячный Excel выгружает открытую версию и её сохранённый аудит; имя файла включает
revisionId. Excel диапазона сохраняет таблицу месяцев, итоги, предупреждения и все
разделы каждого доступного месячного отчёта. Полные данные читаются по revisionId
из уже показанного диапазона; новая публикация не подменяет версию во время экспорта.
Missing месяцы остаются в паспорте. При несовпадении версии/месяца экспорт прекращается.

## Проверки и запуск

PHP proxy tests: `tests/profit-history-proxy.php`.
Browser scenarios: `tests/profit-history.spec.cjs`; прежний renderer:
`tests/profit-report.spec.cjs`. Проверяются отсутствие live calls при чтении/экспорте,
пустые месяцы,0/default, последовательность и stop, потеря HTTP без автоповтора,
точный revisionId, desktop/mobile, XLSX и прежние частичные ответы.

После явного решения о деплое: резервная копия application DB, штатная миграция
V15, Java и WordPress0.5.0, проверка доступа, GET пустого/сохранённого месяца без
ФОЛИО, явный расчёт одного месяца, повторный GET и Excel. Не объявлять миграцию
применённой по одному наличию SQL-файла. Откат интерфейса не должен удалять историю.


## Обновление 2026-09-15: работники и менеджерский Excel

Локально реализовано в lavka-reports 0.6.0; production этой задачей не обновляется.
Требуется Java rules 2026-09-15.1. Новые `kyivEmployeeCount`/`odesaEmployeeCount`
передаются целыми числами, включая 0; одновременно `odesaTaxShare` не передаётся.
Режим старой сохранённой доли доступен отдельно. Отсутствующие в старой ревизии
работники не восстанавливаются из нынешних defaults. Входы сохраняются Java.
Месячная книга и каждый месяц диапазона используют `profit-manager.js`:
первые городские листы повторяют колонки пользовательского шаблона, далее идут
исходные детальные таблицы. Формулы замкнуты внутри листа, поэтому переименование
листов в экспорте диапазона не меняет ссылки. Новые проверки:
`tests/profit-manager.test.cjs`, headcount browser scenario и proxy zero/invalid cases.
[Инструкция менеджеру](../PROFIT_REPORT_MANAGER_RU.md).

## Tax firm lists (2026-09-22, lavka-reports 0.7.0)

Java owns persistent retail/wholesale firm lists in application MariaDB. WordPress
only edits them through nonce-protected, `manage_woocommerce`-restricted AJAX:
GET/PUT `/admin/folio/profit-report/tax-settings`. Body and response:
`{version, retailFirmCodes, wholesaleFirmCodes}`. No effective dates. Defaults:
retail `МИХНФОП, МАЛАФОП`, wholesale `КУЗНФОП, КОНДФОП`. Each list may be empty;
maximum 100 codes, each 1–64 letters/digits/underscore/hyphen, normalized to uppercase.
Duplicates and overlap are invalid. PUT carries the last loaded version. A 409
conflict or unconfirmed save preserves edits and requires an explicit reload;
there is no automatic overwrite or retry.

The editor appears above the report period. Saving settings does not recalculate
reports. Unsaved edits block a new calculation; active report operations lock the
editor. A new month/range calculation reads a settings version once and passes
`taxSettingsVersion` to each saved calculate request. Java checks the version,
preventing a campaign from silently mixing rules after another editor saves.
Old callers omitting the field continue to use current settings.

Each report carries `taxDetails.settings`, `retailAmount`, `wholesaleAmount`,
`unallocatedAmount`, and `unallocatedDocuments`. The UI and export use only this
snapshot, never today's lists when opening an old report. `taxDetails=null` means
historical settings are unavailable, not default lists. Existing tax line IDs
ending in `_TAX_MALAFOP` / `_TAX_KONDFOP` remain compatibility identifiers; actual
selection columns use `filters.purposeCodes`. Unknown firms remain visible and
make the report incomplete, while other sections remain available. Review the
unallocated registry before accepting city profit totals.

Deployment requires the matching Java API/migration. No production data changes
or deployment are performed by this frontend implementation.
