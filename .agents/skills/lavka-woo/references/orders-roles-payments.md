# Пользователи, роли, заказы и оплаты

## Пользователь и ФОЛИО

- Новая guest purchase может создать WordPress user с ролью `customer` и связать его с default Internet Client ФОЛИО.
- При существующем email заказ связывается с найденным пользователем по правилам guest-register plugin; не создавай дубликат без проверки.
- Связь с реальным оптовиком/дилером задаётся на user edit через partners API и user meta.
- `_folio_partner_id` и `_folio_partner_short_name` в текущем контракте содержат короткое имя/ID ФОЛИО; сохраняй их согласованно.
- Финансовые tabs разрешай только при подтверждённом short name и допустимой роли/политике.

## Роли и договоры

Woo role не является названием договора ФОЛИО. Используй mapping:

```text
Woo role -> lps_role_contract_map -> Folio contract
```

Примеры подтверждённых mapping могут измениться; всегда читай option. Если mapping отсутствует, передавай пустое contract value, а не slug `customer`/`administrator`.

## Заказ -> ФОЛИО

1. Woo order собирает client/header/items/allocation plan.
2. Preview всегда отправляет `preview_only=true` в Java `/admin/folio/order-accounts`.
3. Java распределяет по Folio warehouses и возвращает `documents[]`, warnings/errors.
4. Create использует тот же stable contract с `preview_only=false` и idempotent `externalRequestId`.
5. Ответ сохраняется через helper multiple-documents meta.

Для обычного учитываемого счёта `folio_account_header.sourceInfo` берётся из
`WC_Order::get_customer_note()`, сокращается до 30 UTF-8 символов и записывается
Java в `SCL_NAKL.L_CP1_PLAT` (поле «Откуда узнал»). Полный комментарий остаётся в
Woo order. Если комментарий пуст, PHP использует fallback с названием сайта и
покупателем. Для `missing_stock_account` Java по-прежнему заменяет значение на
`нет на складе`.

Java отвечает за складское распределение внутри ФОЛИО; PHP строит Woo parent/children по сохранённому ответу и не повторяет stock algorithm.

## Черновик -> корзина / необліковий документ

- Владелец процесса: `pc-order-import-export/inc/DraftFolioWorkflow.php`.
- Действие доступно только владельцу `pc-draft` или Woo manager.
- Режим `partial_to_cart`: актуально доступное количество заменяет корзину,
  недоступный остаток остаётся в том же черновике и после отдельного apply
  записывается одним необліковим документом ФОЛІО.
- Режим `whole_draft`: корзина не меняется, весь черновик записывается как
  необліковий документ, например для предоплаченного отсутствующего товара.
- Склад берётся из option `pcoe_folio_non_accounting_warehouse_id`; для текущего
  production-процесса ожидается ID `7`. Не привязывай логику к имени склада.
- Payload принудительно задаёт `accountingEnabled=false`, выбранный `warehouseId`
  и `sourceInfo=нет на складе`; synthetic allocation остаётся непустым.
- Preview и apply разделены. Apply использует тот же `externalRequestId`; после
  timeout/unknown outcome нет автоматического retry.
- Обработчик apply работает через `admin-post.php`, где WooCommerce не загружает
  клиентскую корзину автоматически. Перед чтением или заменой корзины он обязан
  вызвать `wc_load_cart()`, проверить `WC()->session`/`WC()->cart`, установить
  session cookie и загрузить текущее содержимое. Пустая корзина является валидной.
- Остаток черновика fingerprint-проверяется, связь с уже созданным документом
  блокирует дубликат, статус `pc-draft` сохраняется.
- Старый AJAX `pcoe_draft_to_cart` не должен напрямую переносить `pc-draft`: он
  только направляет пользователя в новый preview-процесс.

## Split lifecycle

- Один реальный document: reuse исходного Woo order, status `processing`, сохранить связь.
- Несколько documents: исходный order становится справочным `pc-draft`; на каждый real account создаётся child `processing`.
- `missing_stock_account`: child `on-hold` с крупным понятным уведомлением клиенту.
- Parent хранит `_folio_child_order_ids`; child хранит `_folio_parent_order_id`/`_folio_split_from_order_id`.
- Повторный create children должен быть идемпотентным и не создавать дубликаты.
- Названия складов для клиента получай из mapping; цифровой warehouse ID показывай только в техническом блоке.
- Колонка `ФОЛІО` в списке заказов показывает номер и склад только при прямой
  связи этого Woo order с одним документом ФОЛІО. Для справочного parent после
  split выводи `—`; не подставляй склад из line items или сохранённого плана.
- Родитель и children должны показывать взаимные ссылки в admin; клиенту объясняй split и товары ожидания.

## Документы клиента

- `ACCOUNT` -> «Рахунок».
- `EXPENSE` -> «Видаткова накладна».
- `PAYMENT` -> «Платіж».
- Не показывай клиенту внутренний document ID, source DTO name, нерасшифрованный currency code или служебное поле без бизнес-смысла.
- Number suffix показывай отдельно и не используй display number для detail lookup: route должен получать устойчивые type/id из API.
- Warehouse ID преобразуй через справочник.
- `additionalInfo` полезно и в реестре, и в detail header.
- Repeat order использует SKU/quantity из документа, но цену и доступность берёт текущие из Woo.

## Импорт заказа из файла

- `pc-order-import-export` принимает CSV, XLSX и XLS; для Excel читает активный лист.
- Заголовки сопоставляются независимо от порядка колонок и поддерживают несколько украинских, русских и английских синонимов.
- Минимально нужна колонка идентификатора товара (`sku`/артикул или `gtin`/штрихкод) и количества (`qty`, включая `q-ty`).
- Если в строке заполнены и GTIN, и SKU, `Helpers::resolve_product_id()` сначала ищет по GTIN и только при отсутствии результата — по SKU. Не документируй обратный порядок.
- Связка заголовков `gtin;q-ty` подтверждена текущими `header_synonyms()` и `build_colmap()`.

## WayForPay

- Текущий gateway поддерживает classic checkout, а не Checkout Blocks.
- Основные Woo cart/checkout должны быть shortcode-страницами для текущей интеграции.
- Пока магазин WayForPay в test mode, gateway можно показывать только пользователям из собственного test-access списка.
- Service/return URLs задаются настройками gateway; не хардкодь ID страницы.
- Успешная платёжная страница ещё не означает production activation merchant.
- Перед общим включением должны существовать доступные страницы: условия, возврат, оплата/доставка и контакты продавца.
- Кабинет, API, кассы, кассиры, смены и операторские действия Checkbox веди через `$checkbox-ua`. Для автоматической фискализации платежей WayForPay используй его совместно с `$checkbox-wayforpay-woo`.

Не копируй merchant login, secret key и реквизиты в документацию, код или диагностику.

## Статусы и понятные сообщения

| Сценарий | Woo status | Сообщение клиенту |
|---|---|---|
| Реальный счёт | `processing` | заказ принят, указан склад/отправление |
| Нехватка | `on-hold` | товара нет; менеджер свяжется для согласования |
| Parent после split | `pc-draft` | заказ разделён на отдельные счета/склады |
| Ошибка Java до создания | исходный order сохраняется | обработку проверит менеджер |

Не обещай клиенту создание документа ФОЛИО, если Java вернула ошибку или outcome неизвестен.
