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
- Guest register 1.1.0: email-only association, never phone lookup/auth cookies/profile or role overwrite. New users alone receive default Folio mapping and billing/shipping data. Persist association before sending account mail; `_pc_guest_customer_account_email_sent` records the Woo mail transport result, not delivery to inbox. Classic checkout requires email; pickup exemption must validate every selected rate against actual packages, never trust its label or prefix. Canonical customer steps: `docs/WHOLESALE_CUSTOMER_GUIDE_UK.md`, section 10; implementation/verification/deploy: PSU README, Checkout comfort (2026-10-23).
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

## Manager allocation with auxiliary stock (2026-10-27)

`ManagerQuickOrder` extends the existing manager form: auto/priority/single,
optional preview, explicit creation, optional confirmation email. Pool target is
always the first warehouse in that mode; auxiliaries cannot also be destinations.
Manager pool transport must use `lps_java_post` / `lps_options` like its warehouse
catalogue; `pc_folio_order_link_java_post` uses independent Total Sync credentials.
Production read-only verification 2026-10-27: legacy transport 401 versus Lavka
preview 200. Do not disable authentication or fall back to unprotected routes.
A review job may return to settings only if its same-customer work copy has no
persisted create command, Folio links, write-attempt marker or background progress.
Archive the old job before removing its active marker, under source/work locks;
never automatically retry a write or send email during this recovery. Keep both
source and work drafts for audit. Operator steps: manager guide, one-action section.
Java `/admin/folio/manager-order-accounts/v2` separates ready quantities from
`waiting_transfer_account` (accounting enabled, target reserve, `ОЖП` label),
including two documents on the same warehouse. Waiting Woo orders stay on-hold;
their approval snapshot/email explicitly explains that picking must wait. Only
waiting items use the pooled reserve writer; ready accounts use ordinary reserve.
Do not mark waiting as missing stock or PRD, or expose this manager mode to clients.
No automatic transfer/readiness clearing or auxiliary reservation. Signed target
free stock carries previous commitments. Preserve operation journal/unknown-result
stop. Deploy Java and PHP together; old pool route is closed before old PHP can
misclassify the new document type. See manager guide, one-action draft section.

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

Owner decision 2026-10-16: all WayForPay receipts of funds for FOP Malakhatka
belong to payment warehouse 5, even when the invoice uses another warehouse.
Link the payment to the source Folio invoice and retain that invoice's payer;
later user-profile remapping must not move historical money. This is a business
configuration, not a shipping-location rule. Bank-income creation is NOT yet
implemented. Native payment allocation requires a guarded Paint_Rus golden-master
before implementation/apply; do not substitute a bare SCL_PLAT insert or the
invoice number allocator. Agreed task and gates:
`docs/api/FOLIO_WFP_PAYMENT_BACKEND_TASK.md`; operator entry:
`docs/WAYFORPAY_TEST_ACCESS.md`.

2026-10-16: `pc-folio-order-link.php` replaces the `Int` prefix in
`additionalInfo` with `WFP` only for server-verified WayForPay evidence matching
the paid Woo transaction. This is informational, not a Folio payment write.
Keep the 30-character limit, preserve other gateways and do not backfill old
documents. Contract and offline regression: `docs/FOLIO_ORDER_JSON_CONTRACT.md`,
`scripts/tests/folio-wayforpay-info.php`; production verification is separate.

2026-10-15: локально реализован `pc-wayforpay-checkbox`, manual deploy, default off.
Канон подключения/приёмки: `wp-content/plugins/pc-wayforpay-checkbox/README.md`;
точка входа оператора — `docs/WAYFORPAY_TEST_ACCESS.md`. Не считать это production rollout.
Адаптер перехватывает legacy callback до vendor, проверяет signature/merchant/сумму/
валюту/Approved; фиксирует snapshot до payment_complete и Folio split. Не строить
чек по processing или текущим изменённым parent lines. Queue retries не вызывают
Folio create. Таймаут чека → reconcile UUID, не повторный sell. Refund → review,
автовозвраты не реализованы. Allowlist пользователей не доказывает sandbox merchant.
Аварийно использовать PC_WFP_CHECKBOX_PAUSED: off возвращает legacy callback.

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
  per-customer initial contexts for unknown price/tax filters. Equal effective file
  signatures may merge during preparation before XLSX/photo/packaging work (2026-10-10).
  Retain each recipient pricing_context for pre-send checks; do not equate role alone
  with equal prices. Existing files without signatures remain unchanged.
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

### Customer directory organization filter (2026-10-06)

Owner: PCOE `ManagerWorkspace::directory()` and `manager-view.php`.
`customer_folio_type` reads saved `_folio_partner_type` and requires a non-empty
Folio short name when a type is selected; Latin `H` means art salon. It intersects
site price role, search and billing city without querying Folio or changing roles.
Keep the WordPress customer-account scope and pagination/reset behavior. Existing
mailing calls omit the new optional argument and retain their original scope.
Source: local `tests/customer-directory.php`; operator/help canon is
`docs/MANAGER_CUSTOMER_GUIDE_UK.md#customers`. Deployment is WordPress-only.

### Folio customer registration import (2026-10-06, local)

PCOE `FolioCustomerImport` owns selected new-account creation, owner-scoped private
seven-day jobs, a 30-minute preview, per-row durable outcomes and optional password
emails. No existing profiles are overwritten. Shared ecosystem lock serializes
preview/apply; interrupted creating/sending rows require review, never automatic
retry. Roles must remain customer-only and match current configured price contracts.
Source identity is refetched before creation. Java registration contacts require a
configured server token; ordinary partner search remains unchanged. Do not infer
price role from organization type, personal discount, or first/last names from the
company name. Operator steps: `docs/MANAGER_CUSTOMER_GUIDE_UK.md#register-wholesale`;
configuration/lifecycle: plugin README. Tests: `tests/folio-customer-import.php`
with mocked Folio and blocked external mail/HTTP. Production deployment remains separate.

Owner defaults added 2026-10-06: both countries UA; bank city seeds both cities;
shipping address/postcode initially copy billing and remain editable. CP_2 is a
reference above the role picker, never an automatic role mapping. CustomerInternalNotes
owns private `_pcoe_customer_internal_note`; Folio note/additional information must
not go into public biography or customer email. Profile writes require manager,
edit-user, customer-target and nonce checks. Source: import integration tests and
Java partners API contract; manager guide contains the operational steps.

Imported login policy (2026-10-06): derive new-account logins from sanitized email
local parts, with numeric collision suffixes and WordPress blocked-name checks.
Persist the chosen login with the creating state; do not rename existing users.
Source: `FolioCustomerImport::username()` and import integration tests.

Import selection registration hints (2026-10-06): `FolioCustomerDirectory` checks
email and Folio short-name links independently, using one protected Java
`POST /admin/folio/partners/registration-emails` batch (up to 25) and two local
account queries. Unknown email checks must not be presented as unregistered;
missing email and unavailable service are distinct. Profile links require edit-user
permission; preview/apply still recheck duplicates. Deploy Java and WordPress.
Source: import integration tests and `customer-import-select` in the manager guide.

Commercial offer mailing format (2026-10-10): `CommercialOffer` consumes the same
`PriceList::catalogue_rows` eligibility/prices as ordinary mailings, and preserves its visible group/subgroup hierarchy in
XLSX with descriptions and best-effort embedded Woo thumbnails. Persist format; new offers always separate stock from blank order quantities.
Retail uses raw regular price; packaging reads EDN_V_UPAK through the protected
Java product-packaging endpoint, never volume/dimensions.
Never reuse source document prices
or quantities. Shared price groups stay private and generation never sends email.
Keep row-1 SKU/Order quantity headers for re-import and ask clients to fill the blank order
quantities. Photo limits/fallback and verification: PCOE README; operator steps:
`docs/MANAGER_CUSTOMER_GUIDE_UK.md#mailings`. Deploy Java packaging endpoint before WordPress; no media writes.

Manager draft shortage routing (2026-10-10, code + local regression): always pass
`DraftFolioWorkflow::default_warehouse_id()` as `folio_account_header.warehouseId`,
also for accounting/auto or single-group workflows. Java `selectMissingWarehouse`
already prioritizes this field; reservations remain governed by item allocations.
Reject unset configuration and non-accounting responses on another warehouse.
Do not route shortages via the last allocation or change existing documents.
Operator instructions: `docs/MANAGER_CUSTOMER_GUIDE_UK.md`; regression: PCOE
`tests/manager-workspace.php`. WordPress-only fix.

Offer footer (2026-10-10): localized invitation to request additional data, XML/other
formats or high-resolution photo links. Keep category headings/footer SKU-empty and
footer outside filters. Workbook layout version participates in deduplication signatures.

Offer category columns (2026-10-10): each SKU row repeats L=visible root Group and
M=remaining Subgroup path. Build from heading/depth entries, not string splitting;
clear lower levels on a new branch. Preserve text typing and blank root-only subgroup.

Combined confirmations (2026-10-11; `ApprovalBundle`, `CustomerApproval`, `ApprovalWorkbook`):
use sorted unique Folio ACCOUNT IDs (1–20), same validated customer, accounted positive
invoices only. Aggregate Folio snapshots once, not their linked Woo totals. Optional
confirmed invoice settings are snapshot/revision data. One XLSX sheet retains each
source number/date/warehouse; no accounting merge, payment or stock writes. New sends,
downloads and confirmation revalidate all documents/settings. NP planning uses each
bundle document warehouse; no top-level single-warehouse assumption. Manager workflow:
`docs/MANAGER_CUSTOMER_GUIDE_UK.md#approval`.

Import access (2026-10-14, FolioCustomerImport::allowed): custom manager accounts
can receive explicit `pcoe_import_customers` together with existing
`manage_woocommerce`; do not grant create_users or change staff roles just to expose
Folio import. Grant/revoke per user only with owner authorization. Existing customer
role allowlist, actor-owned jobs and preview/apply are unchanged.

WayForPay/Checkbox recovery (2026-10-16, 0.1.1 code and offline tests): browser
return with valid order/key goes to Woo order-received, never confirms payment.
ShiftManager shares a per-register persisted UUID/lock across manual and automatic
opening; unknown outcomes are GET-only. Receipt preflight errors retain sanitized
stage/message/HTTP and shift ID. Do not diagnose opening rejection from the generic
basebad_request code alone. Folio-only receipts still need an authoritative paid
command producer and cross-source duplicate checks; never create a second operation
for a Woo-linked invoice. Human runbook: `docs/WAYFORPAY_TEST_ACCESS.md` and linked
plugin READMEs. Live opening/replay remains separately authorized.

Receipt admin projection (2026-10-18, adapter 0.2.1 code/offline tests):
`ReceiptPanel` and `assets/receipt-status.js` read saved state only, behind
manage_woocommerce + order-edit capability + order-bound nonce. Polling must never
call reconciliation, enqueue work or submit the order form. During pending states,
retain old rejection only as labelled history; show confirmed executor result even
before adapter metadata catches up. A stale open page is not evidence of a repeated
Checkbox failure. Workflow/limits/deploy: `docs/WAYFORPAY_TEST_ACCESS.md` and the
adapter README, «Обновление статуса в карточке».

Manual draft purpose (2026-10-16; `PCOE DraftPurpose`, `DraftFolioWorkflow`,
`ManagerWorkspace`, Java `FolioOrderAccountService`): `_pcoe_draft_purpose`
selects ordinary draft vs preorder. Manual non-accounting does not imply shortage.
Only preorder sends `ПРД` at the start of `additionalInfo` / `L_CP2_PLAT` and
`folioOperationKind=*РАЗОВАЯ`. Actual shortage remains a separate document intent;
Java must not append its marker to every non-accounting document. Purpose belongs
in preview/revision guards. Save remains local; financial creation still requires
preview + apply. Human instructions: `docs/MANAGER_CUSTOMER_GUIDE_UK.md` and
`docs/WHOLESALE_CUSTOMER_GUIDE_UK.md`. Deploy Java before WP; no retroactive changes.

WayForPay bank income (owner revision 2026-10-16; adapter 0.2.0): a verified
Approved payment creates an independent Folio bank income on warehouse 5, not an
invoice-dependent allocation. Missing/split/changed/archived invoices do not block
income. Snapshot the customer's Folio payer key at first verified Approved;
later user reassignment must not move the payment. Initial NOTOVAROST equals the
gross payment, NACLAD_POR is 0 (not NULL), no SCL_PMOV allocations. Confirmed by a
Paint_Rus native rollback probe and independent no-remains check; production
API acceptance is still pending. Java `folio.payment` owns native insert + durable
ledger in one transaction; WP FolioPayments owns a separate outbox and GET-only
reconciliation after attempted apply. Refund retains income and flags a separate
expense review. Never promote preview queues or backfill earlier manual payments.
Runbook: `docs/FOLIO_WFP_PAYMENTS.md`; Java API contract of the same domain.
Defaults off; schema migration/deployment/live enablement require separate approval.

Customer marketing subscriptions (2026-10-17, source PCOE CustomerSubscriptions +
Broadcasts): one user profile `_pcoe_subscriptions_v1`; AND brand/category inside
rules, OR across rules, topic-specific scopes. Reuse public catalogue visibility,
never expose hidden suppliers. Filter attachment products before grouping; retain
pricing context independently of subset/file grouping. Recheck profile fingerprint
before delivery. Waitlist consent/pilot is independent and must not be inferred
from marketing arrival subscription. No personal schedule yet. Contract, rollback
and acceptance limits: PCOE README, Customer subscriptions first stage; user and
manager instructions in their canonical guides.


Commercial-offer photos (2026-10-17, code/offline verification): PCOE
`BroadcastPhotos` prepares campaign thumbnails before price-group XLSX assembly,
under the existing mailing lock. Preserve per-product persisted progress and
private non-autoloaded blobs; do not restore a single global photo time budget.
A yielded batch is incomplete, not a missing image. Final attachment size limits
and explicit send approval remain. Recovery/cleanup and regression details:
`wp-content/plugins/pc-order-import-export/README.md`, Commercial offers in mailings.

Manager delivery display (2026-10-18): PCOE `ManagerOrderDetails` shows saved
`_pnpm_recipient` independently of the Woo address. Reuse its ownership-checked
split source resolver; never present checkout choice as the current issued TTN
destination. NP preparation owns later corrections. Details: PCOE README,
Saved Nova Poshta destination in manager orders.


Folio checkout recovery (2026-10-22, offline verified): the MU order-link companion
`pc-folio-order-link/recovery.php` owns the shared per-order create lock and
pre-HTTP attempt marker. Never clear `_folio_create_attempted_at` or infer no
Folio document from an absent Woo link after timeout. Customer continuation is
restricted to new, proven-unsubmitted waits; legacy waits need reconciliation.
BACS on-hold rechecks that gate without creating a paid event. Saved links are
not live reserve checks. Contract, rollback and tests: `docs/FOLIO_ORDER_JSON_CONTRACT.md`,
Checkout payment recovery. This is PHP flow protection, not Java idempotency.

Customer progress (2026-10-22): `pc-folio-order-link/progress.php` is read-only;
bounded GET refresh must never invoke checkout/create/payment callbacks. Payment
success requires the verified gateway reference, matching transaction and paid
date; saved Folio documents do not imply payment or a live reserve. See the
customer progress section of `docs/FOLIO_ORDER_JSON_CONTRACT.md`.

Prepared OpenCart migration (2026-10-23): PCOE operator CLI
`pcoe customers-import` owns the exact Customers XLSX schema and durable option
claims. Existing users never change; creation/mail after unknown outcomes never
replay. Source roles are owner-approved per-file values, not Folio identity.
Retail receives INET KL; wholesale identities cannot be inferred from old XLSX.
See PCOE README and manager guide. Personal exports remain private, outside Git.

Promotion coupons (2026-10-25, local code): `pc-order-import-export/inc/PromotionCoupons.php`
extends native Woo coupon editing with start date and gift instructions. Native Woo
owns code generation, restrictions and usage accounting; do not maintain a second
usage counter. Gift-only coupons normalize to fixed_cart/zero/no free shipping.
Checkout snapshots instructions in order meta `_pcoe_coupon_gifts` via CRUD; no
Folio document or gift stock movement occurs. Coupons are not enabled automatically.
Operator procedure: `docs/MANAGER_CUSTOMER_GUIDE_UK.md`, section coupons.

Order execution pilot (2026-10-26): `OrderExecution` / `OrderExecutionUi` in PCOE
own internal supervision on a validated Woo/Folio order family. One CRUD meta
`_pcoe_execution_v1` contains state and history; shared order GET_LOCK + revision
protect updates. Explicit per-order start schedules one Folio GET every five
minutes in Action Scheduler group `pcoe-execution`; NP data is saved-source only.
Manual links validate exact type/ID/customer; missing/failed reads preserve last
verified values. Responsibility transfer requires manage_options; source/customer
mapping changes stop updates. No payment, completion, shipping command or bonus
side effects. Packing and box passports currently exist only on paper (owner
26.10); do not confuse production-component assembly with order picking.
Launch/limits/rollback: docs/MANAGER_CUSTOMER_GUIDE_UK.md#execution-control.


HPOS capacity (2026-10-27, reproduced locally and production schema read): native
TEXT order meta rejects serialized values above 65,535 bytes; WC save success alone
is not persistence proof. Large manager commands/results and execution history need
LONGTEXT. `scripts/expand-order-meta.php` is preview-only unless explicitly enabled
with a verified private backup; no on-request DDL and no narrowing rollback. Recheck
after Woo schema upgrades. ManagerWorkspace must read back each command checkpoint
before external POST and the saved result afterward. Normalize creation account variants
to ACCOUNT for document reads; do not infer accounting status from that normalization.
Recovery verifies partner, source Woo marker and all SKU quantities, never resends
creation. Operator details: docs/MANAGER_CUSTOMER_GUIDE_UK.md, large-order recovery.

Manager one-action preparation (2026-10-27): `ManagerQuickOrder` is manager-only,
uses a persisted job on the source, a separate work copy, and native reserved Woo
children. Email checkbox defaults on; unchecked suppresses initial customer emails.
Approved work may run in Action Scheduler; do not infer new authorization from a
job with changed customer/Folio mapping or revoked actor. Recheck before execution.
Never replay an uncertain Folio POST or mail; request/command checkpoints precede
side effects. Mail failure is separate from successful document creation. Original
PRD accounts must be reconciled separately (automatic conversion refuses them).
Execution source links are reciprocal and customer-scoped; do not use native Folio
parent metadata for historical drafts. New Java manager route is required only for
auxiliary stock; do not silently fall back to ordinary checkout. Pool reserves the
target only, including signed negative target free stock in future calculations;
no physical transfer and no exclusive donor reserve. Preserve these distinctions
in manager help. Canonical steps: `docs/MANAGER_CUSTOMER_GUIDE_UK.md`.

Verified production recovery, 2026-10-27: a saved non-accounting response can coexist
with a legacy manager command stuck at `sending`; `ManagerQuickOrder::eligible`
correctly refuses it until reconciled. Do not clear the command or resend creation.
A Folio visible number may survive an internal-ID change. Resolve only within the
same customer and require source `Int` marker, warehouse, accounting mode and all
SKU quantities/prices/amounts to match the saved request/result and current draft.
Scoped operator recovery may then preserve the original command, record recovery
provenance, update the existing result ID and mark it complete under the shared
order lock with a verified private backup and readback. This restores the manager
button without creating accounts/reserves/email; it is not automatic page-load
reconciliation. Steps: manager guide, missing preparation button section.

Preorder-purpose mismatch recovery (production, 2026-10-27): distinguish the stored
Woo draft intent from the live Folio header. A prior save may be complete while the
Folio account was subsequently replaced/edited. If rows match but purpose differs,
ask which business purpose is intended before changing classification. An explicit
owner choice of ordinary non-accounting draft permits correcting only Woo purpose
while retaining the live Folio header; do not add PRD or change VID_DOC implicitly.
