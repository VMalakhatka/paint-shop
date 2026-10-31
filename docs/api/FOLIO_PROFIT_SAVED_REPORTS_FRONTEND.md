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
Решение владельца от 31.10.2026: по умолчанию показывать последний сохранённый
результат месяца, включая PROVISIONAL. Java выбирает последнюю доступную ревизию
COMPLETED/PROVISIONAL с отчётом для GET месяца без revisionId и для каждого месяца
диапазона. PROVISIONAL сохраняет предупреждения и complete=false; выбор для
просмотра не означает публикацию или подтверждение правильности.

FAILED/RUNNING остаются последней попыткой (latestRevisionId/latestStatus), но не
заменяют последний доступный результат. Если результата нет, суммы недоступны.
RUNNING после рестарта не доказывает, что Java ещё работает. Явный revisionId
открывает указанную историческую версию. publishedRevisionId/published остаются
отдельными метаданными; WordPress больше не называет этот ID «текущим результатом».
Ручной публикации предварительной версии в v1 нет. GET не изменяет указатели БД.

WordPress 0.12.2 использует revisionId и totals из ответа Java без подмены ID и
суммирования на фронте. Открытие, добавление диапазона в сравнение и Excel читают
именно эти ID. Для нового выбора необходима совместимая Java; обновление только
WordPress не исправляет выбор старого результата. После обоих деплоев достаточно
нажать «Показать сохранённые отчёты»; пересчёт ФОЛИО и миграция данных не нужны.
Существующие вручную выбранные месяцы сравнения сохраняют свои явные версии;
чтобы заменить старую, добавьте последнюю версию из списка месяцев.

При сохранении Java использует один calculate(includeDocuments=true), а не
раздельные пересчёты summary/audit. Сохранены все полученные значения; усечённый
аудит не становится полным от записи в БД. Исходное NOLOCK-чтение не является
транзакционным снимком ФОЛИО, хотя сохранённый ответ фиксирован.

Ошибка сохранения не означает отсутствие commit. requestId идемпотентен:
повтор тех же параметров возвращает существующую попытку, другой payload —409.
Конкурирующая старая попытка не откатывает указатель на старую опубликованную версию.

## Диапазон и Excel

### Настройки складов и исключений (0.12.0, 24.10.2026)

`Lavka_Reports_Profit_Scope_Settings` проксирует GET/PUT
`/admin/folio/profit-report/scope-settings` и GET `scope-catalog`. Доступ —
`manage_woocommerce` + отдельный nonce, токен Java только на сервере. Java хранит
CAS-версию в application DB (миграция V20); WordPress не пишет настройки в ФОЛИО.
Точный контракт Java: `docs/api/FOLIO_PROFIT_SCOPE_SETTINGS_API.md`.

Четыре списка: `kyivWarehouseIds`, `odesaWarehouseIds`, `kyivStockWarehouseIds`,
`odesaStockWarehouseIds` — непустые, до 50 integer ID, без пересечений городов
отдельно для sales и stock. Два списка исключений: `excludedInventoryGroupIds`,
`excludedInventorySubgroupIds` — суммарно до 100 opaque string ID из каталога.
ID до 13000 ASCII-символов, фронт их не конструирует и не декодирует.
Каталог групп — реальные пути `SCL_ARTC.NGROUP_TVR…NGROUP_TV6`, не Woo categories.
Подгруппы имеют `path`, `level`, корневой `groupId` и непосредственный `parentId`.
Выбор предка покрывает потомков, явный выбор дочерней ветки сохраняется при
снятии предка. Неизвестный выбранный ID виден и требует исправления, не удаляется
тихо. Ошибка каталога не означает пустые списки.

Перед новым расчётом UI читает settings и фиксирует `expectedScopeSettingsVersion`
один раз на всю серию. Dirty/busy/unknown-save блокируют расчёт. При 409 нужно
перечитать и проверить настройки; автоматического повторного PUT/расчёта нет.
Страница истории, просмотр и Excel используют только сохранённые данные.
`report.scopeSettings` содержит эффективные значения, `report.scopeCatalog` —
имена выбранных складов и пути выбранных исключений/предков. Присутствие предка
в каталоге не означает его исключение. Старые null остаются историческими null.
Общий scope-catalog не загружается при открытии/выгрузке сохранённого отчёта.
Изменение scopes не блокирует итоги периода по правилу ниже.

Перед публикацией фронта требуется совместимая Java: отсутствие нового API
блокирует новые расчёты, но не просмотр сохранённых отчётов. Production-деплой
и применение миграции в разработке не выполнялись.

### Итоги

Месячные grossProfit, operatingExpenses, profit и прочие потоки суммирует Java.
Выручка продаж не добавляется: её нет в исходном DTO, валовая прибыль не является
выручкой. Missing/null не заменяются нулём: общий показатель null. Предварительные
суммы отмечены complete=false. Курс и налоговая доля не усредняются.

Решение владельца от 24.10.2026: различия наборов складов сохранённых месяцев
не блокируют итоги диапазона. Java складывает именно сохранённые месячные суммы,
даже при разных или отсутствующих исторических списках складов в inputs.
Остатки — начало первого запрошенного месяца и конец последнего; изменение —
разница этих сохранённых значений, без проверки совпадения складов.
Реальная неполнота данных по-прежнему делает соответствующий итог недоступным.
Frontend показывает totals API и не вычисляет альтернативные финансовые итоги.
Изменение применяется после обновления Java; повторный расчёт месяцев не нужен.

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

Legacy tax rows with empty selection metadata retain the historical MALAFOP /
KONDFOP labels. Only a new report's settings snapshot can establish that a firm
list was intentionally empty; this is shown as “No firms configured”, never “Any”.

`taxDetails.unallocatedDocuments` is a full registry independent of the ordinary
audit limit. When `sections.EXPENSES` is unavailable, tax amounts are null and an
empty registry does not prove that no tax documents exist. Audit review includes
both `UNCLASSIFIED` and `UNALLOCATED` treatments. Compatibility checked against
`tests/fixtures/profit-tax-settings-java.json`, generated by Java with synthetic
old/new firm and unknown/conflicting tax documents; browser export and XLSX readback
preserve the snapshot, all unallocated records and numeric amounts.

## 2026-09-30: manager template compatibility

Confirmed frontend contract for Java ruleVersion `2026-09-30.1`, plugin 0.8.0:
- `inputs.taxAllocationMethod=ALL_TAXES_KYIV` selects the new website/XLSX manager layout. Old snapshots retain legacy layout and tax allocation.
- `masterClassesByCity` / `masterClassDocumentsByCity` keyed KYIV/ODESA take precedence over the old Odesa-only aliases. No duplicate Odesa rows.
- `grossProfitLines[]`: lineId, sortOrder, city, label, organizationTypes, warehouseIds, amount, lineCount. Amounts sum to each city baseGrossProfit; OTHER preserves remaining types. No client-side financial reclassification.
- `KYIV_TAXES` and `ODESA_TAXES` replace separate tax-pool lines for new calculations. Odesa zero/NOT_APPLICABLE rows are visible. Tax settings/unallocated audit remain available.
- New requests omit employee counts, legacy tax share and Kyiv manual work. Odesa manual override and RUB rate remain supported. Historical inputs remain visible in stored result parameters.
- Expense sortOrder is backend-owned: salary UAH/RUB, then additional work, then bank. Wholesale rent/phone are grouped below Kyiv retail expenses. Capitalized transport is displayed below operating totals, with zero profit impact.

Reference workbook: user attachment `Лавка_прибыль_july_2026_шаблон.xls`, revised 2026-09-30. It defines layout/rules, not production amounts. Production validation requires a coordinated Java + WordPress deployment and a new saved revision; this change does not rewrite old snapshots.

Validation: 27 isolated browser scenarios (history, report, tax settings, new Java fixture), 9 manager/XLSX unit checks and all three PHP proxy suites passed. The synthetic fixture from Java `FolioProfitSeptemberRulesTest` is stored as `tests/fixtures/profit-template-java.json`. Both city sheets were reopened with openpyxl: cell borders and cached formula values verified. No production financial data is included in the fixture.
