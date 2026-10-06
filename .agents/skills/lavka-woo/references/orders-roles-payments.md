# Пользователи, роли, заказы и оплаты

## Пользователь и ФОЛИО

Регистрация оптовика: инструкция `docs/MANAGER_CUSTOMER_GUIDE_UK.md`,
`#register-wholesale` (2026-10-02). До deploy CustomerPermissions production
editable_roles ограничены customer, promote_users отсутствует, но Woo runtime
уже даёт edit_users: проверять current_user_can с target, не только role storage.
Новая политика PCOE (опубликована 2026-10-02, 88abdda) разрешает edit/promote клиентских ролей
customer/opt/partner/opt_osn/schule только без elevated caps; mixed staff,
самоповышение и delete/remove заблокированы, admin не меняется. Права runtime,
без role migration; WordPress сохраняет nonce/target/editable_roles checks.
Quick-order allow-list шире customer balance/documents:
последние требуют opt/partner и подтверждённую привязку. Mapping появляется
на user-edit/profile, не user-new; выбрать результат недостаточно, нужен Update
User. Согласованный путь: менеджер регистрирует клиента через My Account в
отдельном приватном окне; письмо пароля отправляется до настройки роли/ФОЛИО.
Production 2026-10-02: регистрация, генерация username/password включены;
стандартная форма содержит email, поля имени/фамилии требуют доработки.
После deploy менеджер использует Edit User в карточке; mapping сохраняется
штатным профилем. Production read-only role/target checks и UK/RU help render
пройдены; реальные сохранения профиля и отправка писем не тестировались.
Категория цен — opt/partner, не персональная скидка.
Источник: read-only options/capabilities на
production, Woo wc-user-functions, pc-folio-customer-map/balance и WordPress core.

- Новая guest purchase может создать WordPress user с ролью `customer` и связать его с default Internet Client ФОЛИО.
- При существующем email заказ связывается с найденным пользователем по правилам guest-register plugin; не создавай дубликат без проверки.
- Связь с реальным оптовиком/дилером задаётся на user edit через partners API и user meta.
- `_folio_partner_id` и `_folio_partner_short_name` в текущем контракте содержат короткое имя/ID ФОЛИО; сохраняй их согласованно.
- Финансовые tabs разрешай только при подтверждённом short name и допустимой роли/политике.

## Роли и договоры

### Выбор склада на витрине (2026-09-21)

`paint-core/inc/header-allocation-switcher.php` ограничивает гостей и розницу
режимом `single` через `pc_normalize_alloc_pref`, включая старые cookies/session
и AJAX. На витрине остаётся только выбор локации (Киев/Одеса в текущем справочнике).
Авто/приоритет доступны ролям общего `pc_wholesale_customer_can_access`, не просто
всем авторизованным пользователям; над контролом показывается предупреждение о
разных отправлениях. Явный аргумент preference менеджерского `pc_build_alloc_plan`
сохраняет прежнюю семантику и не зависит от роли оператора. Старые сохранённые
заказы не мигрируются. Проверки: `paint-core/tests/allocation-policy.php`, локальный
браузер 1440/390; оптовая разметка проверена с временным контекстом роли без записи
пользователя. Production-публикация справки требует деплоя MU-файла и переводов.

Розничная корзина хранит `pc_retail_location_id` в identity каждой строки;
`pc_cart_item_alloc_plan` и лимиты используют этот склад, а не текущий свичер.
Смена магазина влияет только на новые добавления. `stock-locations-ui` показывает
другие остатки, но не использует их как fallback для лимита; `pc-cart-guard`
считает доступность по строке/складу. Старый single-план сохраняет склад, старый
multi-план требует повторного добавления (checkout блокируется с сообщением).
Оптовые роли игнорируют retail pin и сохраняют прежнее распределение. Проверять
один SKU в двух магазинах, восстановление сессии, изменение количества и нулевой
остаток выбранного склада. Пользовательское правило: раздел 2
`docs/WHOLESALE_CUSTOMER_GUIDE_UK.md`.

Woo role не является названием договора ФОЛИО. Используй mapping:

```text
Woo role -> lps_role_contract_map -> Folio contract
```

Примеры подтверждённых mapping могут измениться; всегда читай option. Если mapping отсутствует, передавай пустое contract value, а не slug `customer`/`administrator`.

## Заказ -> ФОЛИО

Checkout incident, production 2026-09-22: ошибка
`ExternalShipmentStore::submit()` в NP-плагине может прервать
`woocommerce_checkout_order_created` уже после сохранения Woo-заказа, но до
`woocommerce_checkout_order_processed` и отправки в ФОЛИО. При `pending` без
`_folio_auto_status` сначала проверять PHP fatal log и сторонние checkout hooks,
а не повторять Java create. Отсутствующий `_pnpm_external_plan` возвращает `''`;
каст `(array)` создаёт непустой массив и небезопасен. Исправление и регрессионные
тесты принадлежат NP-плагину. Восстановление требует отдельного разрешения,
проверки дублей и сохранённого stable preview/apply payload; отменённые попытки
не восстанавливать вместе с выбранным заказом.
После восстановления `bacs` сверять исходный `date_paid`: Woo автоматически
заполняет его при переходе в `processing`, даже без вызова `payment_complete()`.
Не выдавать статус принятого Folio-счёта за подтверждение поступления денег.
Источник: Woo `WC_Order::maybe_set_date_paid()`, production recovery 2026-09-22,
`docs/OPERATIONS_RUNBOOK.md` («Обычный заказ остановился до отправки в ФОЛИО»).

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

## Manager workspace (2026-09-07)

Подтверждено локальным кодом и тестом с HTTP-заглушкой: `pc-order-import-export`
владеет `pcoe-customers` / `pcoe_manager`. Для менеджерских операций передавай
явный `customer_id`, проверяй `manage_woocommerce`, nonce и владельца Woo-заказа;
не переноси клиентский `get_current_user_id()` в owner нового черновика. Короткий
контекст расчёта цены обязан восстановить пользователя и `WC()->customer` до
авторизации/записи; корзина и её preference не переключаются. Явный preference
поддерживается `pc_build_alloc_plan` без изменения поведения вызовов без аргумента.

`_pcoe_manager_command` — durable marker до внешнего POST. Любое незавершённое
состояние блокирует повторную отправку; не очищай marker для обхода неизвестного
результата. Старые UI mutate-маршруты используют общий per-order MariaDB lock,
а manager command учитывается в `pc_folio_order_has_saved_documents`. Учётные
результаты и stock-plan дочерних заказов сверяются с полученным складом ФОЛИО.
Создание расходной/реальной отгрузки этим модулем не реализовано. Каноническая
инструкция: `docs/OPERATIONS_RUNBOOK.md`, раздел «Работа менеджера с клиентскими
документами»; backend ownership — `docs/BACKEND_GUIDE.md`. Production не проверен.

### Аргументы frontend hooks клиентских документов

Проверено локально 2026-09-07 по `customer-documents.php` и regression test
`pc-order-import-export/tests/frontend-document-assets.php`: `wp_enqueue_scripts`
вызывает `pc_folio_documents_enqueue_assets` с `accepted_args=0`. Пустой аргумент
WordPress не является числовым ID клиента; менеджер передаёт ID прямым вызовом.
При расширении сигнатур проверять реальные hooks витрины, не только admin/CLI.
Диагностика: `docs/BOOTSTRAP_AND_RECOVERY.md`, раздел о падении витрины.

### Каталог клиентов менеджера

Проверено локально 2026-09-07 по `ManagerWorkspace::directory` и браузеру:
список строится из Woo-пользователей customer и общей оптовой allow-list
(`pc_wholesale_customer_roles`, актуализировано 2026-09-27) с pagination,
совместными фильтрами роли и `billing_city`. Названия ФОЛИО читаются из сохранённых
`_folio_partner_name` / `_folio_partner_short_name`, без live Java/MSSQL запроса.
Город не выводится из названия или адреса ФОЛИО. Пользовательская инструкция —
`docs/OPERATIONS_RUNBOOK.md`, раздел «Работа менеджера с клиентскими документами».

### Долг и исторические ПРД — 2026-09-24

Общий Java `FolioCustomerBalanceCalculator` сохраняет сальдо после всех оплат,
включая ПРД; `prepaymentAmount` является справочным оборотом отмеченных платежей
за выбранный период, не остатком аванса. PHP не вычитает это поле повторно.
После deploy Java нужен отдельный refresh снимка должников; старые строки сами
не пересчитываются. Источник: unit-регрессия калькулятора и
`docs/api/FOLIO_CUSTOMER_BALANCE_API.md` Java-проекта. Инструкция выпуска —
`docs/OPERATIONS_RUNBOOK.md`, «Исправление долга при исторических ПРД».

### Дата документа в уведомлении менеджеру

Проверено по коду 2026-09-26: `pc-order-import-export/inc/ManagerNotifications.php`
читает номера и `document_date` из сохранённых связей ФОЛИО, без Java-запросов
при отправке письма. `pc-folio-order-link.php` сохраняет `_folio_document_date`
для прямой/дочерней связи. `document_created_at` и дата Woo не заменяют дату
документа. Для старого дочернего заказа допустим только документ с совпадающим
ID из сохранённого результата его родителя. Операторский путь и ограничения
раннего уведомления описаны в `docs/OPERATIONS_RUNBOOK.md`, раздел
«Подробности заказа для менеджера и документы ФОЛИО в письме».


### Чат клиента и менеджерская очередь — 2026-09-27

Владелец: `pc-order-import-export/inc/ConversationStore.php` и `Conversations.php`.
Текстовая переписка — приватные CPT с проверкой владельца на каждом входе;
внутренние заметки доступны только `manage_woocommerce`. Назначение менеджера
и очередь не меняют заказы, согласия, платежи или документы ФОЛИО. Повтор формы
идемпотентен; запись сообщения и состояния требует InnoDB transaction.
Default off, включение администратором отдельно от deploy. Query-маршрут кабинета
зависит от исключения редиректа в `pc-account-tweaks.php`. Обязательно проверять
настоящий HTTP-путь, а не только renderer в CLI.
Регламент и границы этапа 1: `docs/OPERATIONS_RUNBOOK.md`, раздел «Чат клиента
и очередь обращений — этап 1». Email и standalone ФОЛИО не реализованы.
Telegram добавлен отдельным этапом ниже.


### Telegram как канал того же чата — 2026-09-27

`TelegramSettings/TelegramStore/TelegramBridge/TelegramManagers` в `pc-order-import-export` —
единственные владельцы bot config, identity binding, webhook и outbox. Код проверен
локально с подменой HTTP; клиентский и одиночный менеджерский production-обмен
подтверждены владельцем 2026-09-27, расширение до пары ожидает deploy. Канонические сообщения
остаются в ConversationStore. Привязка требует одноразового кода и отдельного
подтверждения владельцем авторизованного кабинета; username/email не авторизация.
Webhook проверяет секрет и private peer, reply mapping включает поколение связи.
Внутренние заметки не отправляются. Дедупликация update и входящее сообщение,
исходящий ответ и outbox записываются транзакционно; сеть вызывается после claim.
Timeout/5xx/stale sending = unknown, без автоматической повторной отправки.
Шифрованный токен и owning home URL хранятся в `pcoe_telegram_config`; endpoint,
планировщик, новая таблица и восстановление — `docs/OPERATIONS_RUNBOOK.md`, раздел
«Telegram в клиентской переписке — этап 2». Setup создаёт schema и регистрирует
webhook только по явной кнопке администратора. Не менять чужой webhook и bot ID.
Менеджер с `manage_woocommerce` привязывает собственный peer с audience=manager;
старые связи без audience остаются клиентскими и не получают новые права при
смене роли. Менеджерский Reply требует доставленной карты текущего поколения и
актуального назначения; claim сериализован с assign под thread lock. Общая очередь
рассылается только подключённым менеджерам; карточки очереди/назначения заново
проверяют получателя перед HTTP. Приватные заметки не экспортируются никому.

`CustomerManagers` владеет парой клиента (`_pcoe_chat_team` user meta: primary,
secondary, revision). Новые обращения наследуют пару; применение ко всем открытым
только по явной галочке, до 200, с атомарным rollback defaults/назначений/audit/outbox.
Закрытые не менять. На обращении `_chat_assignee` остаётся ответственным,
`_chat_secondary` — дополнительный. Оба получают сообщения клиента и публичные
ответы коллеги (без эха автору) и видят обращение в «Мої»/`/threads`. Telegram Reply
проверяет текущее вхождение в пару; исключение/потеря capability закрывает доступ
через старые карты и отменяет pending delivery. Customer-team lock синхронизирует
создание обращения с defaults; thread lock — ответ/переназначение. При rollback
очищать user_meta и затронутые post caches. Не подменять основного ответом помощника.


### Оформление менеджером заказа из черновика — 2026-09-29

`ManagerWorkspace` / `ManagerOrderFlow` в `pc-order-import-export`: основной
маршрут preview/apply имеет `mode=accounts` и передаёт Woo `on-hold`, чтобы Java
создал учитываемые счета по остаткам. Группа склада сайта сохраняет все связанные
склады ФОЛИО с приоритетами; «Киев» не означает один внутренний склад.
Неучитываемая отправка всего списка — отдельная свёрнутая форма без выбора группы,
с настроенным складом; она не проверяет наличие и оставляет Woo `pc-draft`.
Выбранную группу в этом режиме теперь отклонять, а не игнорировать.
Apply проверяет совпадение режима с preview и наличие учитываемого документа для
оформления заказа. Новый preview очищает прежнее подтверждение даже при ошибке.
Java владеет окончательным распределением/резервом: preview не блокирует остатки.
Список результата берёт только реальные связи документов и Woo-заказов того же
клиента/родителя. Shortcut подтверждения доступен для зарезервированных заказов;
нехватка не готова к сборке. Сам shortcut ничего не отправляет клиенту.
Завершённый non-accounting можно копировать в новую рабочую чернетку; не сбрасывать
command/meta старой операции, не обходить unknown/needs_review копированием.
Канон оператора: `docs/MANAGER_CUSTOMER_GUIDE_UK.md`, prepare/apply; публикация
`ManagerHelp.php`, UK/RU. Детали выпуска — `docs/OPERATIONS_RUNBOOK.md`.
Проверено локально: 53 manager integration assertions с подменой HTTP/email;
для изменения нужен WordPress deploy, Java и production-данные не меняются.


### Контакты и уведомления подтверждения — 2026-09-29

Владелец: `ApprovalContacts` / `ApprovalNotifications` в `pc-order-import-export`.
Форма читает только профиль и собственные заказы вошедшего клиента; выбранные
телефон/адрес редактируются и сохраняются в подтверждении, без checkout, изменения
профиля, заказа, оплаты или отгрузки. Сохранённый пункт НП читается из метаданных
заказа, без вызова перевозчика. Кнопка менеджера отправляет ссылку только на email
профиля владельца после проверки nonce и актуальной версии документа.
Согласие сначала сохраняется; затем уведомляются текущая пара клиента и создатель
запроса с действующим `manage_woocommerce`, без дублей. Email имеет журнал
accepted/failed/unknown; accepted не означает доставлено, неизвестный исход
запрещает повтор. Telegram использует существующую очередь со scope `approval`:
перед доставкой проверить версию, время подтверждения, назначение и привязку.
Просмотр страницы и deploy не рассылают старые подтверждения. Проверено локально
с подменой mail/HTTP; канон оператора — `docs/OPERATIONS_RUNBOOK.md`, опубликованные
инструкции — manager/customer guides. Выпуск: WordPress, без Java и DDL.


### Доставка НП при подтверждении — 2026-09-29

`ApprovalDelivery` (PCOE) владеет owner/nonce/revision-проверками и десятиминутным
receipt в transient; `DocumentShipmentBuilder` / `ApprovalQuoteService` (PNPM) —
посылками по сохранённым данным, существующим тарифом и политикой доставки.
Форма переиспользует справочники/карточку точки обычного checkout без cart session.
Для Woo приоритет у `_slw_data`/`_stock_location`; дочерний счёт использует
`_folio_warehouse_id` через однозначный mapping location. `lavka_folio_warehouses`
содержит строки `{id, priority}`, а не список строковых ID. Не планировать
доставку документа по текущему остатку и не выдавать нулевой тариф при ошибке.
Перед согласием заново сверить владельца, версию, назначение и fingerprint
посылок/политики; подтверждённый расчёт хранится только в приватном запросе.
Корзина/Woo totals/профиль/ТТН/ФОЛИО не меняются. Оценка веса и упаковки сохраняет
ограничения существующего PNPM; финальные условия проверяет менеджер.
Источник — локальные интеграционные/браузерные тесты с mock HTTP; детали выпуска
двух плагинов и восстановления — `docs/OPERATIONS_RUNBOOK.md`, подтверждение клиента.

### Excel-вложение подтверждения — 2026-10-04

`ApprovalNotifications` отправляет по отдельной кнопке ссылку или XLSX только на
email профиля владельца, после прежних nonce/capability/revision-проверок.
`ApprovalWorkbook` использует сохранённый snapshot, не пересчитывает каталог или
доставку, не создаёт платёжный счёт. Linked Folio sheets — детализация, их суммы
не складывать с Woo total. Текстовые ячейки задаются явно как строки; приватное
вложение удаляется после синхронной отправки и при ошибке. Один mail request key
идемпотентен для обоих форматов, unknown блокирует повтор. Email-ответ менеджеру
не является consent на сайте. Источник: классы PCOE и локальный `approval-excel.php`
с перехватом mail/HTTP. Канон: manager/customer guides и OPERATIONS_RUNBOOK.md.

## Manager email mailings (2026-10-05)

- Owner: `pc-order-import-export/inc/Broadcast*.php`, manager Mailings tab.
  Follow WordPress `docs/OPERATIONS_RUNBOOK.md` email-mailings section and
  `docs/MANAGER_CUSTOMER_GUIDE_UK.md#mailings`; API contract is Java
  `docs/api/FOLIO_RECEIPT_CATALOGUE_API.md`.
- Implementing or previewing a campaign does not authorize sending it. Real mail
  requires the manager's explicit start after reviewing recipients/text/files.
- Same pricing context/locale shares one private XLSX. Keep conservative
  per-customer grouping for unknown price/tax filters; do not bypass it for speed.
  Document SKU selection never imports source prices, quantities or counterparties
  into a customer price file. Accounted receipts and accounting/non-accounting
  invoices supply products; document type/warehouse/day are revalidated on preview.
- Recheck recipient subscription/email/group before sending. Persist a sending
  claim before SMTP; unknown outcome is never automatically retried. Do not clear
  state to restart an uncertain campaign. Snapshot prices are not a live quote.
- Java picker is internal read-only; WordPress owns mail, no Folio writes. Deploy
  both components for document selection. Warehouses use the existing
  `/ref/warehouses` directory, independently of the newer catalogue route. Invoice
  selection requires the Java `documentTypes` capability; never silently fall back
  to receipts or the full product catalogue. Do not expose admin endpoints publicly.
- Customer opt-out only affects marketing mailings, not order/approval emails.
  No production sending or live Folio acceptance was performed during local tests.

### Folio profile partner search

Confirmed in source 2026-10-06: art salons use Latin `H` in `_PARTNER.MY_ORGANIZ`
(`FolioProfitGrossLines`), not Cyrillic `Н`. The profile MU-plugin
`pc-folio-customer-map.php` defaults to `П,Д,К,H`; `types=all` removes the filter.
Java partner search accepts supplied codes without an allow-list. Keep the visible
default, JS fallback and AJAX fallback aligned. Operator steps and deployment:
`docs/OPERATIONS_RUNBOOK.md`, «Типы организаций в поиске клиента».
