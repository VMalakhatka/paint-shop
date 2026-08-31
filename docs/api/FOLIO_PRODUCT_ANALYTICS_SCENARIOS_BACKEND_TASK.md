# Задание Java: полный контракт сценариев товарной аналитики ФОЛІО

Проверено: 2026-08-31 по Java snapshot schema v2, текущему WordPress consumer,
коду выгрузки карточек товара и документации ФОЛІО.

Статус: историческое задание backend. Schema v4 реализована в Java-коммите
`f417ecc`, а актуальная WordPress-интеграция описана в
[FOLIO_PRODUCT_ANALYTICS_FRONTEND_V4.md](FOLIO_PRODUCT_ANALYTICS_FRONTEND_V4.md).
Матрица ниже сохраняется как обоснование источников и известных ограничений;
наличие поля в ней само по себе не означает, что фильтр доступен на сайте.

## 1. Цель

Страница сценариев WordPress должна позволять повторно использовать один набор
условий в реестре товаров, реестре движений и будущем формировании заказа
поставщику. WordPress не должен повторять классификацию ФОЛІО, обращаться к MSSQL
при каждом изменении фильтра или угадывать группу, бренд и поставщика по названию
товара.

Целевой поток:

```text
ФОЛІО / MSSQL
  -> Java: атомарный product snapshot schema v3
  -> MariaDB: готовые товарные и документные измерения
  -> Java: capabilities + server-side analytics query
  -> WordPress proxy и сохранённый сценарий
  -> Товары / Рух товару / будущий заказ поставщику
```

Тяжёлый аналитический запрос к legacy MSSQL на каждое действие браузера
запрещён. MSSQL читается при построении снимка. Отчёты фильтруют готовое активное
поколение в MariaDB. Отдельное live-чтение ФОЛІО допустимо только для небольшого
справочника или диагностики, если это явно обосновано и ограничено.

## 2. Четыре независимых блока сценария

Backend и frontend не должны смешивать эти блоки:

1. **Совокупность товаров**: склады, SKU, группы, тип, отдел, бренд, текущий
   поставщик и признаки карточки товара.
2. **Область движений**: период, документы, операции, направление, контрагент,
   условия оплаты и признаки влияния движения.
3. **Параметры расчёта**: горизонт спроса, база ABC, правила возвратов,
   регулярный/разовый спрос, service level и правила покрытия.
4. **Представление**: вкладка, колонки, сортировка, группировка и размер страницы.

У каждого справочного фильтра один режим:

```text
ANY | INCLUDE | EXCLUDE
```

`INCLUDE` и `EXCLUDE` принимают массив стабильных кодов. Пустой массив означает
`ANY`. Неподдерживаемое поле или значение должно вернуть явную ошибку, а не
молча построить отчёт без этого ограничения.

## 3. Матрица возможных отборов

Обозначения:

- `READY_V2` — уже хранится в активном snapshot schema v2;
- `CONFIRMED_NOT_PROJECTED` — источник подтверждён кодом/схемой ФОЛІО, но поля
  нет в аналитической проекции;
- `RESEARCH_REQUIRED` — источник вероятен или документирован, но семантика и
  заполненность Paint_Ua требуют read-only исследования;
- `DERIVED_AFTER_EXTENSION` — рассчитывается после добавления исходных данных;
- `NOT_AVAILABLE` — нужен новый слой истории или бизнес-данных.

| Блок | Отбор или параметр | Источник | Статус | Требуемое действие |
|---|---|---|---|---|
| Контекст | один или несколько складов | snapshot generation | `READY_V2` для одного склада | добавить согласование поколений и многоскладскую агрегацию |
| Контекст | период движения | `folio_product_movement_fact.document_date` | `READY_V2` | сохранить как отдельный calculation context |
| Товар | SKU и название | `SCL_ARTC` / snapshot current | `READY_V2` | оставить |
| Товар | текущий назначенный поставщик | `SCL_ARTC.DOP2_ARTIC` | `READY_V2` | не смешивать с контрагентом документа |
| Товар | состояние назначения поставщика | snapshot current | `READY_V2` | оставить `CURRENT/MISSING/...` |
| Товар | иерархия групп 1–6 | `SCL_ARTC.NGROUP_TVR`, `NGROUP_TV2..NGROUP_TV6` | `CONFIRMED_NOT_PROJECTED` | добавить код и отображаемое имя каждого уровня |
| Товар | отдел/публикационный статус | `SCL_ARTC.DEPARTAM` | `CONFIRMED_NOT_PROJECTED` | подтвердить значения Paint_Ua и спроецировать как код |
| Товар | тип товара | `SCL_ARTC.TIP_TOVR` + `TIP_TOVR` | `CONFIRMED_NOT_PROJECTED` | передать код, название и подтверждённые свойства типа |
| Товар | единица измерения | `SCL_ARTC.EDIN_IZMER` | `CONFIRMED_NOT_PROJECTED` | добавить справочник |
| Товар | фасовка/кратность | `SCL_ARTC.EDN_V_UPAK` | `CONFIRMED_NOT_PROJECTED` | проверить нули и бизнес-смысл |
| Товар | минимальная партия | `SCL_ARTC.MIN_PARTIA` | `CONFIRMED_NOT_PROJECTED` | проверить заполненность и единицы |
| Товар | минимальный/максимальный запас | `SCL_ARTC.MIN_TVRZAP`, `MAX_TVRZAP` | `CONFIRMED_NOT_PROJECTED` | подтвердить использование по складам |
| Товар | вес и габариты | карточка `SCL_ARTC` | `CONFIRMED_NOT_PROJECTED` | добавить после проверки единиц измерения |
| Товар | штрихкоды | `SCL_CODE` | `RESEARCH_REQUIRED` | определить связь, основной и множественные коды |
| Товар | бренд/производитель | подтверждённый справочник не выбран | `RESEARCH_REQUIRED` | найти источник; не выводить из названия или группы |
| Товар | родитель, вариация, аналоги | источник ФОЛІО не подтверждён | `RESEARCH_REQUIRED` | отдельное исследование |
| Товар | партия и срок годности | `SCL_MOVE`/`SCL_SROK` | `RESEARCH_REQUIRED` | не обещать фильтр без проверки заполненности |
| Цена | текущая продажная цена и ценовая группа | карточка/ценовые таблицы ФОЛІО | `RESEARCH_REQUIRED` | выбрать канонический источник и валюту |
| Остаток | физический, резерв, доступно | snapshot current | `READY_V2` | многоскладские значения хранить с расшифровкой |
| Остаток | учётная цена и вложенный капитал | snapshot current | `READY_V2` | оставить статус качества цены |
| Движение | тип документа | movement fact | `READY_V2` | множественный `INCLUDE/EXCLUDE` |
| Движение | исходный вид операции ФОЛІО | movement fact | `READY_V2` | не смешивать с типом документа |
| Движение | класс движения | movement fact | `READY_V2` | продажа, возврат, приход, transfer, сборка и прочее |
| Движение | направление остатка | movement fact | `READY_V2` | `IN/OUT/TRANSFER_IN/TRANSFER_OUT/...` |
| Движение | регулярный/разовый спрос | movement fact | `READY_V2` | `*РАЗОВАЯ` остаётся в фактических финансах |
| Движение | влияет на stock/финансы/плановый спрос | movement fact | `READY_V2` | три независимых признака |
| Документ | номер и дата | movement fact | `READY_V2` | оставить |
| Документ | учётный/возвратный признак | movement fact | `READY_V2` | использовать классификацию Java |
| Документ | контракт, основание, контрольная дата | `SCL_NAKL.CONTR_POR`, `OSNOVANIE`, `CONTRLDATE` | `CONFIRMED_NOT_PROJECTED` | не выводить отсрочку только из даты |
| Документ | источник/дополнительная информация | `L_CP1_PLAT`, `L_CP2_PLAT` | `CONFIRMED_NOT_PROJECTED` | только поиск/диагностика |
| Контрагент | клиент документа | movement fact | `READY_V2` | хранить отдельно от текущего поставщика |
| Контрагент | тип организации и сегмент | movement fact | `READY_V2` | справочник и `UNKNOWN` обязательны |
| Контрагент | продавец/менеджер/пользователь | источник не подтверждён | `RESEARCH_REQUIRED` | определить поле и стабильный ID |
| Перемещение | склад-источник и склад-получатель | документы/движения ФОЛІО | `RESEARCH_REQUIRED` | добавить оба ID |
| Закупка | поставщик прихода | контрагент исторического документа | `READY_V2` как общий counterparty | явно классифицировать для закупок |
| Закупка | lead time, MOQ, pack, supplier price | `scm_artcmap`, `scm_partner` | `RESEARCH_REQUIRED` | проверить живую схему и заполненность |
| Закупка | открытый заказ и товар в пути | SCM/заказы поставщику | `RESEARCH_REQUIRED` | определить статусы и защиту от двойного учёта |
| История | активные/архивные документы | `SCL_*` и `SCL_ARCN/SCL_ARCM/...` | `RESEARCH_REQUIRED` | задать период и дедупликацию |
| Финансы | продажи, выручка, COGS, валовая прибыль | snapshot v2 | `READY_V2` | COGS периода отдавать явно или считать server-side |
| Финансы | net revenue/net COGS/net gross profit | нужна себестоимость возврата | `DERIVED_AFTER_EXTENSION` | не называть текущую прибыль чистой |
| Спрос | ABC по выручке/прибыли | готовые движения и агрегаты | `DERIVED_AFTER_EXTENSION` | считать отдельно по выбранной базе |
| Спрос | XYZ по недельному регулярному спросу | нужны недельные корзины | `DERIVED_AFTER_EXTENSION` | помесячных агрегатов недостаточно |
| Спрос | coverage/reorder point/план заказа | demand + lead time + MOQ + in-transit | `DERIVED_AFTER_EXTENSION` | не формировать заказ до полноты данных |
| Спрос | дни отсутствия и потерянный спрос | подневная история доступности | `NOT_AVAILABLE` | создать daily stock/stockout слой |

## 4. Snapshot schema v3

Минимальное расширение товарной проекции:

```text
group_level_1_code, group_level_1_name,
group_level_2_code, group_level_2_name,
group_level_3_code, group_level_3_name,
group_level_4_code, group_level_4_name,
group_level_5_code, group_level_5_name,
group_level_6_code, group_level_6_name,
department_code, department_name,
product_type_code, product_type_name,
unit_code, unit_name,
package_quantity, minimum_order_quantity,
minimum_stock, maximum_stock,
brand_code, brand_name
```

`brand_*` можно оставить `null` до подтверждения источника, но нельзя заполнять
эвристикой из названия. Для каждого нового поля Java определяет:

- каноническую MSSQL-таблицу и join;
- кодировку, trimming и `UNKNOWN`/`null`;
- влияние на fingerprint аналитики;
- индексы MariaDB;
- миграцию staging и active таблиц;
- поведение активного поколения schema v2.

Movement projection должна дополнительно рассмотреть:

```text
source_warehouse_id, destination_warehouse_id,
contract_code, document_basis, control_date,
seller_code, seller_name
```

Эти поля не добавляются, пока источник и семантика не подтверждены на Paint_Ua.

## 5. Endpoint возможностей

Нужен read-only endpoint, чтобы WordPress не показывал неработающие фильтры:

```http
POST /admin/folio/product-analytics/capabilities
Content-Type: application/json
```

```json
{
  "sourceDatabase": "Paint_Ua",
  "warehouseIds": [1, 5, 7]
}
```

Пример ответа:

```json
{
  "ok": true,
  "analyticsSchemaVersion": 3,
  "compatibleGeneration": true,
  "warehouses": [
    {"id": 1, "name": "...", "generationId": 101, "asOf": "2026-08-31T01:00:00"}
  ],
  "filters": {
    "productGroups": {"supported": true, "multi": true, "modes": ["ANY", "INCLUDE", "EXCLUDE"]},
    "brands": {"supported": false, "reason": "SOURCE_NOT_CONFIRMED"},
    "movementClasses": {"supported": true, "multi": true, "modes": ["ANY", "INCLUDE", "EXCLUDE"]}
  },
  "dictionaries": {
    "productGroups": [{"code": "...", "name": "...", "count": 123}],
    "movementClasses": [{"code": "SALE", "name": "...", "count": 456}]
  },
  "warnings": []
}
```

Справочники строятся по совместимому активному поколению и учитывают выбранные
склады. Для больших справочников допустим отдельный endpoint поиска с server-side
пагинацией. Системные enum локализует WordPress; названия групп, поставщиков и
контрагентов приходят из ФОЛІО.

## 6. Endpoint многоскладского отчёта

```http
POST /admin/folio/product-analytics/query
Content-Type: application/json
```

Минимальный запрос:

```json
{
  "sourceDatabase": "Paint_Ua",
  "warehouseIds": [1, 5, 7],
  "period": {"from": "2025-09-01", "to": "2026-08-31"},
  "productFilters": {
    "groups": {"mode": "EXCLUDE", "values": ["SERVICE"]},
    "currentSuppliers": {"mode": "INCLUDE", "values": ["KREUL"]}
  },
  "movementFilters": {
    "operationKinds": {"mode": "INCLUDE", "values": ["..."]},
    "movementClasses": {"mode": "EXCLUDE", "values": ["TRANSFER_IN", "TRANSFER_OUT"]},
    "demandModes": {"mode": "EXCLUDE", "values": ["ONE_OFF_ORDER"]}
  },
  "calculation": {
    "abcBasis": "GROSS_PROFIT",
    "includeReturns": true
  },
  "page": {"size": 50, "cursor": null},
  "sort": [{"field": "grossProfit", "direction": "DESC"}]
}
```

Ответ содержит:

- `context`: schema version, generation каждого склада, snapshot date, период;
- `appliedFilters`: нормализованный фактически применённый сценарий;
- `totals`: суммы по всей отфильтрованной совокупности;
- `rows`: строки SKU с общим результатом;
- `warehouseBreakdown`: показатели SKU по каждому складу;
- `facets`: доступные значения и counts;
- `warnings`, `errors` и cursor следующей страницы.

## 7. Правила многоскладского расчёта

- Склады используют совместимые завершённые поколения одной schema version.
  Молчаливое смешивание запрещено.
- Количества, выручка, себестоимость, прибыль и стоимость запаса суммируются.
- Маржа, оборачиваемость, GMROI, coverage и проценты пересчитываются из сумм.
- Текущий остаток берётся из snapshot current. Фильтры движений пересчитывают
  flow-метрики за период, но не изменяют текущий остаток.
- `TRANSFER` не является продажей. `ONE_OFF_ORDER` входит в фактическую выручку и
  прибыль, но не входит в регулярный спрос, XYZ, coverage и прогноз закупки.
- Текущий поставщик карточки и контрагент документа остаются разными полями.
- Исключение группы меняет строки и все итоги, а не только отображение браузера.

## 8. Ошибки контракта

Минимальные машинные коды:

```text
UNSUPPORTED_FILTER
UNSUPPORTED_FILTER_VALUE
INCOMPATIBLE_GENERATIONS
SNAPSHOT_NOT_READY
ANALYTICS_SCHEMA_TOO_OLD
INVALID_PERIOD
INVALID_FILTER_MODE
SOURCE_FIELD_NOT_CONFIRMED
```

HTTP 200 допустим только для корректного отчёта. Частичное игнорирование фильтра
не является успешным ответом.

## 9. Порядок реализации Java

1. Подтвердить read-only SQL и заполненность шести групп, отдела, типа, единицы,
   упаковки, MOQ и min/max stock на Paint_Rus, затем на Paint_Ua.
2. Исследовать бренд, штрихкоды, transfer warehouses, seller, SCM supplier terms
   и открытые поставки.
3. Зафиксировать результаты в каталоге ФОЛІО и API-документации.
4. Добавить MariaDB migration schema v3 для active/stage проекций и индексов.
5. Расширить атомарный snapshot без изменения verification-семантики цен.
6. Реализовать `capabilities`; unsupported filters не выдавать как доступные.
7. Реализовать `query`, server-side пагинацию, facets и формулы нескольких складов.
8. Добавить golden-master: один и три склада, исключённая группа, `*РАЗОВАЯ`,
   transfer, return и несовместимые поколения.
9. Только после этого WordPress повышает scenario `schemaVersion` и включает
   новые элементы управления.

## 10. Критерии приёмки

- Каждый активный фильтр имеет подтверждённый источник и отражён в
  `appliedFilters`.
- `capabilities` соответствует schema активных поколений.
- Неподдерживаемый фильтр завершается ошибкой, а не режимом `ANY`.
- Сценарий воспроизводим в товарах, движениях и CSV/XLSX при одном поколении.
- Итоги равны отфильтрованным строкам независимо от страницы результатов.
- Многоскладские производные показатели пересчитаны из сумм.
- Разовый спрос, transfer, возвраты и текущий поставщик соблюдают бизнес-правила.
- Нет browser-to-MSSQL и тяжёлого MSSQL-запроса на каждое изменение фильтра.
- Snapshot refresh read-only для ФОЛІО и атомарно сохраняет прошлое поколение при
  ошибке.

## 11. Граница WordPress до готовности backend

Текущий scenario schema v1 сохраняет только поддержанные поля snapshot v2 и один
склад. WordPress может хранить формат `warehouseIds`, но не должен:

- показывать активный выбор нескольких складов без серверной агрегации;
- добавлять группу, бренд, SCM или lead time как работающий фильтр;
- вычислять классификацию документов и спроса по строкам в PHP;
- обращаться напрямую к ФОЛІО ради каждого dropdown;
- молча отбрасывать неизвестные условия сценария.

После реализации Java WordPress проверяет `capabilities`, включает только
поддержанные фильтры и сохраняет версию backend contract в ревизии сценария.
