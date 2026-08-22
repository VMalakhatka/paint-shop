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
