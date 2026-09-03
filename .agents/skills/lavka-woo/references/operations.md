# Операции и регламенты

## Для оператора

### Полная синхронизация товаров

- Владелец: `lavka-total-sync` + Java `/sync/run`.
- Обычный run сравнивает `_ms_hash`; force-refresh принудительно обновляет существующие товары после изменения алгоритма.
- Full sync не владеет role prices и stock.
- После изменения индексируемых полей должен обновляться Relevanssi.
- Массовые запуски дроби на поддерживаемые UI batch/limit и не запускай параллельно соседнюю синхронизацию.

### Остатки

- Java/staging -> `wp_stock_import` -> stock sync -> location stock Woo.
- Не устанавливай индивидуальный primary location товара: общий пользовательский выбор определяет приоритет Киев/Одесса.
- `lavka_folio_warehouses` задаёт внутренние Folio warehouses и priority внутри location group.
- Перед очисткой staging проверь ошибки и количество применённых строк.

### Цены по ролям

- Mapping Woo role -> Folio contract хранится в `lps_role_contract_map`.
- Если роли нет в mapping, цена договора пустая; не передавай slug роли как contract.
- Runtime-цена читается из `_wpc_price_role_<role>`.

### Учётные цены ФОЛИО

Основной регламент: Java `docs/api/FOLIO_ACCOUNTING_PRICE_OPERATIONS_FRONTEND.md`. UI обязан следовать текущим статусам, а не старому условию `warningCount === 0`.

- Point — один SKU.
- Native-range/skus — выбранный диапазон/список.
- Native-full — штатный полный алгоритм ФОЛИО и только maintenance window.
- Даже preview native-full вызывает safe procedure внутри транзакции и rollback; это не обычное read-only действие.
- `PREVIEW_READY` и `PREVIEW_READY_WITH_WARNINGS` разрешают apply.
- `COMPLETED` и `COMPLETED_WITH_WARNINGS` — успешный итог; skipped SKU остаются в отчёте.
- `QUARANTINE_PREPARATION` показывай как «Подготовка безопасного пропуска проблемных товаров» и продолжай polling.
- При `FAILED` показывай `failedChunk`: `inputArt`, `outputArt`, `nextArt`, `returnCode`, `currentUnits`, `totalUnits`, `problemDate`, `validationError`.
- Не предлагай auto-retry. При `committedChunks=0` сообщи, что изменения откатились.
- Склады обрабатываются последовательно. Следующий стартует только после `running=false` предыдущего.
- Cron после baseline выбирает server-side только `NEW/DIRTY`; `FAILED` — после ручного исправления, `VERIFIED` — не повторять.

Ошибки отдельных SKU должны сохраняться подробно и не блокировать безопасные товары, если Java вернула supported warning/skipped contract. Системный неизвестный итог и ошибку rejected chunk не маскируй как предупреждение.

#### SKU campaign и расписание

Владелец WordPress-оркестрации: `lavka-price-sync/inc/accounting-price-campaign.php`; UI находится на странице «Облікові ціни ФОЛІО» во вкладке «Кампанія SKU та розклад». Кампания выполняет для каждого выбранного склада строгую последовательность:

1. `POST /snapshot/refresh` и polling `/snapshot/status` до нового `ACTIVE`.
2. Server-side выбор SKU из `folio_product_snapshot_item`: baseline — `UNVERIFIED`, `NEW`, `DIRTY`; регулярный запуск — только `NEW`, `DIRTY`.
3. Последовательные apply-пакеты `POST /recalculate/native-range` с `skus[]`, не более 500 SKU, с polling status до terminal state.
4. Обязательный новый snapshot после последнего пакета или безопасной остановки.
5. Только затем переход к следующему складу.

Операции защищены общим `lavka_ecosystem_lock`; snapshot и native-range не должны пересекаться с другой массовой операцией Lavka. Размер первого пакета по умолчанию 100, максимальный 500; дальнейший размер ограничивается измеренным временем на SKU и остатком окна обслуживания.

Каждая автоматическая порция native-range выполняется только в однопроходном режиме:

```json
{
  "warehouseId": 5,
  "skus": ["KR-84127", "СТИ-741449R"],
  "previewOnly": false,
  "confirmApply": true,
  "applyMode": "SAFE_APPLY_ONLY"
}
```

Не смешивай `skus[]` с `fromSku/toSku` и не отправляй больше 500 SKU. После HTTP 202 сохраняй `jobId`; GET status принадлежит кампании только при совпадении `jobId`, склада, полного набора SKU, `previewOnly=false`, `confirmApply=true` и `applyMode=SAFE_APPLY_ONLY`. После timeout/409 разрешено присоединиться к уже завершившейся задаче, если всё это совпало; повторный POST запрещён. `accepted=false` в GET является нормальным.

Прогресс однопроходной порции считай только как `progressUnits/totalUnits` либо `processedSku/totalUnits`. Показывай `currentArt`, `committedChunks`, `warningCount` и процент. Не используй `procedureCurrentUnits/procedureTotalUnits`: это внутренние legacy-счётчики, и нулевой `procedureTotalUnits` для одного SKU допустим. В `SAFE_APPLY_ONLY` значение `preflightChunks=0` ожидаемо и подтверждает отсутствие отдельного rollback-preflight прохода.

- `COMPLETED` и `COMPLETED_WITH_WARNINGS` продолжают очередь. Проблемные SKU с `details.skipped=true` остаются в диагностике.
- Обычный `FAILED` завершает текущий склад, строит финальный snapshot и позволяет перейти к следующему складу.
- `FAILED_PARTIAL` и `OUTCOME_UNKNOWN` останавливают всю кампанию и расписание до ручной проверки.
- После timeout/обрыва apply не повторяется. WordPress сначала проверяет status и принимает только доказанно совпадающий job.
- Для snapshot единственный признак реально выполняющейся задачи — `running=true` из `GET /admin/folio/accounting-prices/snapshot/status`. Значения `status=BUILDING` или `QUEUED` сами по себе не подтверждают активный процесс.
- Если начальный snapshot до запуска `native-range` завершился точным кодом `PRODUCT_SNAPSHOT_ACCOUNTING_MODE_UNSUPPORTED`, пропусти только этот склад со статусом `WAREHOUSE_SKIPPED_UNSUPPORTED_MODE`, сохрани `accountingRawCode`, `accountingMode`, `recommendation`, увеличь warning/skipped counter и продолжи следующий склад. Не менять `SCLAD_R.N_2` автоматически. Не применять это исключение к финальному snapshot после apply и не распознавать ошибку по тексту: все остальные snapshot failures остаются строгими и останавливают кампанию.
- Если `running=false` и Java вернула `status=INTERRUPTED`, `phase=RECOVERY_REQUIRED` либо оставила старый `BUILDING/QUEUED`, считай snapshot прерванным рестартом Java: освободи ecosystem lock, останови campaign ticks/polling, приостанови автоматическое расписание и переведи кампанию в `MANUAL_REVIEW / SNAPSHOT_INTERRUPTED`. Новый apply автоматически не запускай; оператор может начать новую кампанию вручную.
- Успешно завершённый snapshot принимается при `running=false`, `status=ACTIVE`; `phase=COMPLETED` является явным подтверждением новой Java. Предыдущий активный snapshot при прерывании остаётся доступным, но прерванную кампанию автоматически по нему не продолжай.
- Безопасная остановка запрещает новый пакет, дожидается текущей операции и строит финальный snapshot.
- По окончании окна новый пакет не начинается; оставшиеся склады обрабатываются следующей кампанией.
- Детальный CSV-отчёт выгружается из вкладки кампании. Он содержит результаты пакетов, skipped warnings, ошибки и `failedChunk`; формульные значения Excel экранируются.
- После финального snapshot блок «Звіт за станами знімка» раскрывает реальные товары `UNVERIFIED`, `NEW`, `DIRTY`, `FAILED` и `REMOVED` с пагинацией и отдельным CSV для выбранного состояния. Для каждого товара показываются причина состояния, ошибка, период движений, последнее наблюдение/применение и последняя зафиксированная смена состояния. `UNVERIFIED` означает присутствующий в текущем снимке SKU без подтверждённого успешного перерасчёта; `REMOVED` означает отсутствие SKU в текущем снимке после присутствия в предыдущем и само по себе не является ошибкой.
- `NEGATIVE_CHRONOLOGICAL_STOCK` показывается не только как код: интерфейс и CSV должны содержать склад, документ и дату, запись/позицию движения, начальное количество, остаток до операции, вид и количество операции, остаток после неё, дефицит и текущий физический/учётный остаток. Это позволяет найти конкретный документ ФОЛІО, который нарушил хронологию.
- Постоянный блок «Стан обробки всіх складів ФОЛІО» не зависит от выбранного текущего склада. Он объединяет Java warehouse directory, сохранённое расписание и snapshot tables, поэтому показывает также склад, который ещё ни разу не обрабатывался. Для каждого склада доступны последний snapshot, дата последнего apply, все snapshot states и переход в товары `UNVERIFIED`, `NEW`, `DIRTY`, `FAILED`, `REMOVED`.
- Последний итог и подробные issues сохраняются отдельно для каждого склада в non-autoload options `lps_accounting_price_sku_campaign_warehouse_<id>`. Новая обработка заменяет историю только своего склада. Общие действия «від'ємні залишки», «попередження» и «помилки» собирают последнюю диагностику всех складов; `COMPLETED_WITH_WARNINGS` не приравнивается к ошибке склада. Для старых кампаний, завершённых до появления постоянной истории, гарантированы snapshot states и `last_error`, но не полная структура movement/document.

Расписание выключено по умолчанию и запускает склады последовательно. Для первого массового прохода используй ручной запуск одного склада и проверь snapshots, отчёт и lock; только после этого включай подтверждённое расписание.

Параметры `campaign_initial_batch_size`, `campaign_max_batch_size`, `campaign_window_minutes` и `campaign_horizon_months` общие для ручной SKU-кампании и запуска по расписанию. Переключатель расписания, склады очереди, день и время относятся только к автоматическому запуску. Источник: `wp-content/plugins/lavka-price-sync/inc/accounting-price-campaign.php` и `inc/accounting-prices-cron.php`, проверено 2026-08-27.

После `FAILED_PARTIAL`, `OUTCOME_UNKNOWN` или отказа Java в apply WordPress сохраняет параметры расписания, но переводит его в состояние «приостановлено после ошибки», устанавливает `enabled=false` и снимает ближайшие cron-события. Постоянный блок «Збережений розклад» должен показывать сохранённые склады, день/время, окно, размеры пакетов и причину паузы даже при выключенном расписании. После устранения причины оператор заново включает расписание и сохраняет настройки; скрыто восстанавливать cron автоматически нельзя.

### Медиа

Подробный workflow: `docs/MEDIA_MANAGER_GUIDE_UK.md`.

1. Сохрани XLSX текущего mismatch report.
2. Проверь suggested S3 match и attachment WordPress.
3. Выполни Folio preview; затем отдельный apply.
4. Добавь исправленные SKU в накопительную очередь.
5. Запусти targeted `/admin/media/sync`, не жди недельной полной синхронизации.
6. Для новых файлов: validation -> upload через Media Library/Media Cloud -> refresh S3 index -> Folio preview/apply -> Woo assignment.

Если объект есть только в S3, но WordPress attachment отсутствует, строку блокируй до безопасного восстановления attachment: Woo хранит attachment ID для main/gallery/thumbnails. Совпадение basename в S3 не всегда ошибка; разрешай reuse существующего attachment и не загружай дубликат.

### Импорт заказа

- `pc-order-import-export` принимает CSV/XLSX/XLS, создаёт cart или `pc-draft`.
- После import список drafts должен включать только что созданный order и кнопку загрузки в корзину.
- Export/import labels и инструкции поддерживаются через i18n.

### Баланс, должники и документы

- Баланс одного клиента доступен по short Folio name из user meta.
- Общий список должников читает готовый snapshot; status endpoint не запускает расчёт.
- При `BUILDING` показывай предыдущий `activeSnapshot`, если он есть, и polling 30–60 секунд.
- Refresh снимка является отдельной admin-командой и не должен стартовать второй параллельный расчёт.
- Документы клиента по умолчанию запрашивай за последний месяц; активный ledger и архивы не смешивай без поддержки Java.

### Отчёт прибыли

- Владелец: `lavka-reports`; контракт — Java `FOLIO_PROFIT_REPORT_*`.
- После клика сразу показывай процесс, disable кнопки и ошибку с raw response/reqId.
- Ручные параметры не превращай в ноль, если значение не подтверждено: пустое означает «не задано».

### Статистика товара и движения

- Владелец WordPress consumer: `lavka-price-sync/inc/product-analytics.php` и
  `assets/product-analytics.js`. Все таблицы одного ответа связывай по
  `source_database + warehouse_id + generation_id`; используй только завершённое
  поколение `ACTIVE` и schema v2 для поставщиков/regular/one-off/движений.
- Экран одного склада поддерживает server-side фильтры supplier include/exclude,
  supplier quality, остатков, regular/one-off спроса, финансов, дат и alerts, а
  также именованные наборы фильтров в user meta WordPress.
- `filter_options` возвращает поставщиков объектами `value/products/state` и
  диагностику `assignedProducts/missingProducts/distinctSuppliers`. Ошибку AJAX
  никогда не маскируй сообщением об отсутствии поставщиков. Значение поставщика
  `1` помечай как служебный код для проверки.
- Справочник поставщиков берётся из активного поколения
  `folio_product_metric_current` и не зависит от доступности таблицы фактов
  движений; ошибку движения показывай только во вкладке движений.
- Вкладка движений читает `folio_product_movement_fact` того же поколения и
  фильтруется server-side. Не повторяй классификацию Java по тексту `VID_DOC` и не
  выдавай фильтр фактов за перерасчёт агрегированных товарных метрик.
- Несколько складов в одном отчёте и группы/подгруппы/бренд не рассчитывай в PHP
  из готовых метрик. Java должна опубликовать стабильные
  справочники и агрегированный результат по `warehouseIds[]`; суммы складываются,
  а coverage, turns, GMROI, health и alerts пересчитываются сервером.
- Товарную группу/подгруппу нельзя определять по названию Woo. Отбор движения
  обязан пересчитать период из `folio_product_movement_fact` либо готовой Java
  агрегации. Точный план и минимальный контракт:
  `docs/FOLIO_PRODUCT_ANALYTICS_FILTERS_PLAN.md`.
- После миграции movement snapshot запусти новый полный refresh каждого
  участвующего склада последовательно; удалять старые поколения не нужно.
  Новые поля разрешено показывать как рассчитанные только при
  `analyticsSchemaVersion=2`.
- Общие продажи показывают фактические деньги, включая разовые заказы.
- Coverage, ликвидность, ABC/XYZ для заказа и прогноз используют только
  `REGULAR`; `ONE_OFF_ORDER` показывается отдельной колонкой/фильтром.
- Предоплата и отсрочки разрешены только как фильтр условий оплаты и не меняют
  количество или класс продажи.
- Для закупки группируй по `current_supplier`, но показывай `MISSING` и не
  подменяй им контрагента исторического прихода.
- Для schema v4 браузер передаёт ссылку на WordPress-сценарий как `id + version`
  только в proxy `v4_query`. WordPress проверяет доступ, активность и текущую
  версию сценария, не передаёт эту ссылку в Java и добавляет к успешному ответу
  `scenarioContext` с UUID, именем, версией и режимом `SAVED`, `MODIFIED` либо
  `LEGACY_CONVERTED`. При сравнении с сохранённым сценарием cursor и порядок
  значений фильтра игнорируются; устаревшая версия даёт HTTP 409
  `ANALYTICS_SCENARIO_VERSION_CONFLICT`. Полный контракт и приёмка:
  `docs/api/FOLIO_PRODUCT_ANALYTICS_FRONTEND_V4.md` и
  `docs/OPERATIONS_RUNBOOK.md` (проверено 2026-09-02).
- Capabilities для многоскладского сценария могут загружаться десятки секунд.
  Запросы должны иметь sequence guard: устаревший ответ не обновляет DOM и не
  снимает busy-state актуального запроса. На это время блокируй сценарий, склады и
  запуск отчёта, показывай spinner и понятный статус.
- Неизменённый сценарий формирует query из сохранённого профиля текущей версии, а
  не из потенциально перестроенного DOM. Построение отчёта является read-only для
  таблиц сценариев; сохранять профиль разрешено только явным действием редактора.
  Отсутствующее в новом справочнике сохранённое значение показывай явно и сохраняй,
  но никогда молча не превращай его в `ANY` (проверено 2026-09-02).

## Для менеджера

- Проверяй бизнес-результат, а не только зелёный HTTP: суммы, склад, роль/контракт, количество документов, missing stock и видимость товара.
- Перед массовой коррекцией экспортируй отчёт до изменений.
- Для ошибки сохраняй SKU, понятную причину, документ/дату/склад и reqId; не пересылай секретную конфигурацию.
- Если процесс занят, не запускать дубликат. Проверить lock/status и дождаться результата.

## Для разработчика

- Начинай с `git status` и ownership search.
- Для PHP: `php -l` изменённых файлов и существующие tests.
- Для JS: syntax/lint, AJAX success/error/timeout и loading state.
- Для i18n: POT/PO/MO и runtime locale.
- Для WP: проверка HPOS, capability, nonce, idempotency и повторного запроса.
- Для UI: desktop/mobile screenshot и отсутствие overflow/наложения.
- Для длительной операции: общий lock, poll interval, terminal statuses, unknown outcome и recovery path.
