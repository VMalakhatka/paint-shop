# ФОЛІО: контракт безопасного повтора после временной SQL-блокировки

Проверено по WordPress-оркестратору и локальной Java-реализации: 2026-09-08.

Статус: WordPress-поддержка находится в `paint-shop` начиная с `5ee4d28`.
Java-контракт и реализация используют тот же код и прошли целевые тесты, но на
момент проверки изменения Java ещё не были закоммичены, а production-деплой не
подтверждён. Каноническое описание ответа находится в Java-репозитории:
`docs/api/FOLIO_ACCOUNTING_PRICES_API.md`, раздел «Безопасная пауза после
SQL-блокировки native-range».

## Назначение

WordPress умеет отложить продолжение SKU campaign на 10 минут, но только когда
Java доказала, что временная конкуренция за блокировку не оставила неопределённой
записи текущего SKU. Распознавание по тексту исключения или фрагменту SQL запрещено.

## Требуемый ответ Java

Для `GET /admin/folio/accounting-prices/recalculate/native-range/status` Java
возвращает точный верхнеуровневый код:

```json
{
  "running": false,
  "status": "FAILED_PARTIAL",
  "errorCode": "FOLIO_LOCK_BUSY_RETRYABLE_AFTER_SNAPSHOT",
  "jobId": "uuid",
  "processedSku": 123,
  "committedChunks": 123,
  "request": {
    "warehouseId": 10,
    "previewOnly": false,
    "confirmApply": true,
    "applyMode": "SAFE_APPLY_ONLY",
    "skus": ["..."]
  },
  "error": "...",
  "recommendation": "Wait and continue through a fresh product snapshot."
}
```

`FAILED` допустим вместо `FAILED_PARTIAL`, если в задании не было ни одного
подтверждённого commit. Счётчики должны описывать только доказанный результат:
`processedSku` не меньше `committedChunks`; неизвестное значение нельзя заменять
нулём. `failedChunk` для этого ответа отсутствует: временная блокировка описывается
верхнеуровневыми `errorCode`, `error` и `recommendation`.

## Когда код разрешён

Код `FOLIO_LOCK_BUSY_RETRYABLE_AFTER_SNAPSHOT` разрешён только если одновременно:

1. Получен именно временный lock/deadlock timeout при работе с ФОЛІО.
2. Транзакция текущего SKU подтверждённо откатилась до commit.
3. Результат текущего SKU не является неизвестным.
4. Все более ранние commit этого job подтверждены обязательной проверкой и их
   состояния snapshot сохранены.
5. Status содержит исходный request и стабильный `jobId`.

Если проверочный snapshot или запись его результатов не завершились, этот код
возвращать нельзя.

## Когда автоматический повтор запрещён

Сохранять `OUTCOME_UNKNOWN`, обычный `FAILED_PARTIAL` или другой точный код для:

- потери связи во время commit;
- неизвестного результата transaction commit/rollback;
- рестарта Java;
- ошибки проверки или сохранения snapshot;
- arithmetic/data validation failure;
- нарушения защищённого инварианта;
- неподтверждённого lock exception;
- ошибки rejected chunk, для которой текущий SKU мог быть записан.

## Поведение WordPress

После указанного кода WordPress:

1. Учитывает подтверждённые `processedSku` и `committedChunks` текущего job.
2. Освобождает ecosystem lock и не приостанавливает недельное расписание.
3. Назначает повтор через 10 минут, только если до конца окна осталось минимум
   25 минут.
4. После ожидания ещё раз требует минимум 15 минут до deadline.
5. Строит свежий product snapshot и выбирает оставшиеся SKU из его состояний.
   Старый `skus[]` повторно не отправляется.
6. Если времени недостаточно, строит обязательный финальный snapshot и завершает
   очередь через `PAUSED_TIME_LIMIT`.

Любой другой `FAILED_PARTIAL` и каждый `OUTCOME_UNKNOWN` остаются ручной проверкой
с остановкой кампании и расписания.

## Приёмочные тесты Java

- lock timeout до commit, ноль ранних commit: `FAILED` + точный retryable code;
- lock timeout после N ранних commit: `FAILED_PARTIAL`, `committedChunks=N` и тот
  же code только после успешной проверки;
- rollback текущего SKU не подтверждён: `OUTCOME_UNKNOWN`, без retryable code;
- финальная проверка ранних commit не сохранилась: без retryable code;
- обычная бизнес-ошибка и отрицательный остаток: без retryable code;
- status после завершения остаётся доступен с теми же `jobId`, request и counters.

До деплоя Java с этим контрактом frontend-функция существует, но не активируется:
старое сообщение `Another Folio accounting-price recalculation is already running`
без структурированного `errorCode` корректно требует ручной проверки.
