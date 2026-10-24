# Страницы и интерфейсы

Проверено по локальным Woo options: 2026-08-21. ID приведены для ориентира и не должны использоваться в коде.

## Основные страницы

| Назначение | Локальная страница | Содержимое/владелец |
|---|---|---|
| Главная и магазин | `Лавка Художника`, `/shop/` | Woo shop archive + theme/custom catalog |
| Корзина | `Кошик(класик)`, `/cart_lh_klassick/` | `[woocommerce_cart]` |
| Checkout | `Checkout`, `/checkout/` | `[woocommerce_checkout]` |
| Кабинет | `Кабінет`, `/my-account/` | `[woocommerce_my_account]` |
| Быстрый заказ | `Список товару`, `/shvydke-zamovlennia/` | `[pc_quick_order]` |

Локальные IDs на момент проверки: shop 7, cart 102277, checkout 9, account 10, quick order 102209. Получай рабочие Woo pages из options, а ссылку — через Woo/WordPress API.

## Витрина

- Виджет «Категорії Лавки»: `paint-shop-ux/inc/category-menu.php`.
  Пустая текущая категория не должна обходить hide_empty через active-path pin.
  Глобальные исключения `psu_category_menu_excluded` скрывают выбранные ветки
  вместе с потомками независимо от hide_empty, включая REST и пагинацию.
  Админка: Вигляд → Категорії Лавки; сохранение требует nonce/edit_theme_options.
  С версии меню 1.3.3 это lazy tree с галочками/поиском, включая пустые ветки.
  Исключения также действуют на Woo tiles через scoped subcategories args и
  product_categories shortcode filter; не расширять их на общий get_terms.
  Woo hierarchy cache не включает args: при исключениях обходится только этот
  слой, WordPress term-query cache остаётся активным.
  Не использовать raw term_taxonomy.count для hide_empty: опубликованный товар
  может быть exclude-from-catalog. Индекс агрегирует прямые visible counts;
  Woo product_count_product_cat включает потомков и не заменяет прямой счётчик
  при исключении веток. Учитывать Woo hide-out-of-stock, инвалидировать при visibility.
  Правила и откат описаны в README владельца; не удалять категории или товары.

- Header содержит выбор сценария списания/города; это не индивидуальный primary warehouse товара.
- Каталог: `paint-shop-ux/inc/catalog-cards.php` и `assets/catalog-cards.css`
  владеют заголовком на три строки с ellipsis, видимым SKU и мобильной сеткой
  в три колонки. Короткое имя (`_psu_compact_title`, затем сегмент `|`) применяется
  только к конечной подгруппе без видимых дочерних категорий; её родитель
  (например, «56гр») показывает полное имя с обрезкой до трёх строк.
  Контекст берётся из индекса `PSU_Category_Menu`, а поиск всегда выводит полное имя.
  С 1.8.1 общая текстовая часть из описания конечной подгруппы закрепляется
  `assets/catalog-context.js`/CSS: переносится существующий узел, H1 не закрепляется.
  Не возвращать sticky всему archive header: он может содержать иллюстрации.
  JS принимает один простой текстовый блок, учитывает wpadminbar и высоту экрана;
  без JS исходное описание доступно. На paged>=1 узкий archive-description callback
  сохраняет этот контекст (Woo сам его скрывает); не распространять на rich/media
  descriptions, поиск, родителя или shortcode loop. Парсер `|`/custom meta не менять.
  Источник: catalog-cards.php, catalog-context.js и tests/catalog-cards-local.php;
  приёмка/rollback — README Paint Shop UX (локально 2026-10-21, ожидает deploy).
  Отступ SKU → цена уменьшен вдвое; `.loop-buy-row` — flex без переноса
  и без растягивания: корзина сразу после «+» (3 px mobile, 4 px desktop).
  На мобильном кнопки имеют высоту минимум 32 px, значки ± — 18 px;
  длинный счётчик может перенестись под значок внутри самой корзины.
  Сбрасывать theme padding поля количества, иначе сжатое поле скрывает цифры.
  Не возвращать конкурирующий clamp в child theme. `stock-locations-ui.php`
  отдельно строит mini-панель: имена складов и количества без видимых подписей
  и итога; приоритет выделяется CSS, выбранный нулевой склад сохраняется.
  PDP/распределение не используют этот renderer. Канон и локальная проверка
  2026-10-07: `docs/WHOLESALE_CUSTOMER_GUIDE_UK.md`, тест `catalog-cards-local.php`.
- Фото категории без собственного `thumbnail_id`: `paint-shop-ux/inc/category-thumbnails.php`
  выбирает товар с фото из всей ветки по общему порядку `psu_catalog_supplier_terms()`.
  Secondary query `_psu_category_thumbnail` использует тот же SQL ranking,
  независимо от поиска/сортировки покупателя. Приоритет вручную назначенного фото
  сохраняется; hidden/password/draft исключены, Woo hide-out-of-stock учитывается.
  Суточный кэш содержит fingerprint порядка поставщиков и hide-out-of-stock.
  Правила/откат: «Поставщики в каталоге» в `docs/OPERATIONS_RUNBOOK.md`;
  локальная проверка: `paint-shop-ux/tests/category-thumbnails-local.php`.
- Верхний уровень `product_cat` остаётся обзором разделов. Начиная со второго
  уровня `paint-shop-ux` выводит дочерние плитки и товары всей выбранной ветки на
  одной странице; при активной фильтрации плитки скрываются.
  Для этого expanded archive `display_type` переопределяется только при чтении
  текущего термина на `both`: иначе Woo скрывает счётчик и пагинацию.
  `psu-search-filters` выводит текущую/общую страницу и штатные Woo-ссылки сверху
  и снизу товаров. Настройки отображения категории в БД не меняются.
- Видимая панель `psu-search-filters` объединяет поиск, поставщика, склады, наличие и
  цену. Категория сохраняется через `product_cat`; пустой поиск не отправляется
  как `s`, а `post_type` не должен переключать категорию на Woo shop template.
  С 2026-09-24 presentation aliases единиц (`50мл`/`50 мл`, `шт`/`шт.`) объединяются
  только на чтении, prepared SQL IN одинаков для основного поиска и подсказок;
  `_edin_izmer` по-прежнему приоритетнее `pa_edin_izmer`. Значения справочника не
  переписывать и объёмы не пересчитывать. Списки фильтров ограничены веткой.
  Applied chips строятся из URL, сохраняют контекст категории и сбрасывают страницу
  при удалении условия. Проверки/контракт: README `paint-shop-ux`, `clean-filters-*`.
  `psu-catalog-suppliers.php` хранит видимость и порядок taxonomy `product_brand`
  в option `psu_catalog_suppliers`. Видимость скрывает пункт фильтра и бренд в
  product metadata: `paint-shop-ux/inc/single-product.php` через Woo
  `woocommerce_product_brands_output` использует тот же `psu_catalog_supplier_terms(true)`.
  Разрешены только явно включённые `visible`: отсутствие настройки/флага означает
  запрет, включая checkbox админки. Если разрешённых брендов нет, строка отсутствует;
  назначения, schema и товары
  остаются прежними. Источник: `tests/single-product-brands-local.php` (2026-10-15).
  Порядок группирует товары без текстового поиска до пагинации; keyword search
  сохраняет релевантность Relevanssi. См. раздел
  «Поставщики в каталоге» в `docs/OPERATIONS_RUNBOOK.md`.
  Woo хранит артикул в `_sku`; подтверждённые исторические штрихкоды Lavka
  находятся в `_wc_gtin_code`. Поле `_gtin` оставлено только для совместимости и
  на проверенной базе не заполнено. `_global_unique_id` предназначено Woo для
  GTIN/UPC/EAN/ISBN, но текущая синхронизация записывает туда Folio
  `globalUniqueId`; не называй это значение подтверждённым штрихкодом без
  исправленного контракта. Точный идентификатор ищется непосредственно по этим
  meta keys, а `psu-search-filters` добавляет их в custom fields Relevanssi через
  `relevanssi_index_custom_fields` независимо от выбранного режима полей. После
  первого деплоя этой настройки нужен полный rebuild индекса.
- Собственная страница товара (локальный код 2026-10-14) принадлежит
  `paint-shop-ux/inc/single-product.php`, `templates/content-single-product.php` и
  `assets/single-product.*`. Сохранять пять Woo single-product hooks, порядок,
  password gate, native gallery/type forms/notices/schema и существующие price/stock
  owners. Scoped adapter заменяет Woo/GeneratePress wrappers только на single product;
  footer с 1.5.0 и шапка/меню с 1.7.0 — у собственных модулей ниже;
  общая PHP-оболочка остаётся у активной темы.
  Simple AJAX выполняет один Woo POST и при
  неизвестном результате направляет к корзине без auto-retry; остальные типы — native.
  `pcux_catalog_assets_required` подключает общие controls для related/upsell loops;
  не связывать Paint Core напрямую с GeneratePress. Откат до `wp` —
  `psu_single_product_enabled=false`. Проверки и deployment boundaries — README
  `paint-shop-ux`; пользовательский канон — `docs/WHOLESALE_CUSTOMER_GUIDE_UK.md`.
  Проверять не только SKU с одним фото, но и многофотографическую галерею:
  ширину native gallery и всех миниатюр, каждый слайд/lightbox, main/gallery IDs
  и порядок. Не путать разный состав media на local/production с потерей фото
  шаблоном; CSS и PDP template не меняют привязки. Регрессия 1.4.1 от 2026-10-15
  сохраняет normal/400 title и ограниченное фото, перекрывает inherited Woo 48%.
  Production-публикация и проверка в этой задаче не выполнялись.
- Карточка товара показывает SKU, категории/бренд, stock/allocation и
  desktop-поиск под артикулом. На single product после meta выводится
  подтверждённый GTIN из `_wc_gtin_code` или совместимых barcode keys; значения
  проходят проверку длины и контрольной цифры. В catalog loop штрихкод не
  выводится.
- Поиск обслуживает Relevanssi. При изменении SKU/visibility/content выполняй reindex через API Relevanssi.
  Проверено локально 2026-09-24: `paint-shop-ux/inc/catalog-search.php` добавляет
  подсказки в основное поле фильтров, скрывает дублирующий sidebar search только
  в каталоге и не удаляет widget options. Публичный read-only admin-AJAX endpoint
  учитывает категорию/фильтры, выдаёт цену текущего пользователя с private/no-store;
  shared cache для payload недопустим. Unit SQL разрешён для secondary query
  только с `_psu_suggest`. Сводка и проверки в README `paint-shop-ux`.
- Пользователю показывай понятное название склада из mapping, а цифровой Folio warehouse ID оставляй техническим.

## Корзина и checkout

- С 2026-10-21 `pc-order-import-export/Ui::inline_css()` скрывает только на
  `body.woocommerce-cart` блоки export/import/cart-to-draft при ширине <=767px;
  от 768px они доступны. Это CSS presentation rule, не role/API gate: кабинет,
  экспорт заказа, отдельный прайс-лист и checkout не скрывать. Проверять filled/empty
  cart и границу 767/768, не вводить серверное device detection. Источник: `Ui.php`;
  приёмка/deploy — PCOE README, пользовательский канон — wholesale guide.

- С 2026-10-15 стандартный XLSX-прайс удаляет колонку поставщика перед выдачей; внутренний row schema сохранён для CommercialOffer. Новые рассылки используют новую версию fingerprint; уже готовые вложения нужно пересоздать.
- Полный клиентский XLSX-прайс принадлежит `pc-order-import-export/PriceList` и
  доступен оптовым ролям в Orders и корзине. Цена одна, из текущего customer price
  pipeline; остаток только по mapped selling locations Киева и Одессы. Импорт
  заполняет только `Замовити`, идентифицирует SKU и заново получает цену клиента.
  XLSX повторяет дерево `product_cat` с outline-группами: один товар в одной
  ветке (назначенная primary либо самая глубокая категория), заголовки без SKU
  не импортируются, заполненные строки свёрнутых разделов импортируются.
  С 2026-10-05 отбор веток использует общий `PSU_Category_Menu::index()`:
  только visible и не blocked, включая исключение всех потомков скрытой группы.
  Primary выбирается среди видимых назначений; hidden-only и unassigned не
  переносятся в «Інші товари». При недоступном провайдере видимости экспорт
  завершается ошибкой, не открывает полный каталог. Проверка: offline
  `pc-order-import-export/tests/price-list-visibility.php`.
  Подробности и границы: `wp-content/plugins/pc-order-import-export/README.md`.

- Classic cart/checkout нужны для совместимости текущего WayForPay plugin.
- Изменение количества и удаление строки classic cart выполняет
  `pc-cart-guard` через защищённый AJAX одной строки; ответ обновляет subtotal и
  cart totals, а при сбое используется штатное обновление WooCommerce. Остатки и
  allocation memoized только в пределах текущего PHP-запроса.
- Перед подтверждением заказа выводится ссылка на страницу оплаты и доставки.
- Длительная Folio-обработка должна иметь видимое состояние ожидания и итог: один заказ, split либо передача менеджеру.
- После успешного split пользователь должен видеть понятный список полученных заказов; parent является справочным draft, реальные children — `processing`, missing stock — `on-hold`.

## Кабинет

Базовый shortcode расширяется endpoint-ами собственных MU-плагинов:

- заказы и child orders;
- `Як замовляти` (`yak-zamovyty`) — структурированная оптовая справка перед logout;
- `Баланс із клієнтом` — только при подтверждённой связи с ФОЛИО и допустимой роли;
- `Документи ФОЛІО` — счета, расходные накладные и платежи, детали и repeat-order items.

`pc-wholesale-help` хранит единый server-side allow-list ролей `partner`, `opt`,
`opt_osn`, `schule`. Он применяется к справке, меню и `pc-wholesale-quick-order`;
обычный `customer` и гость не получают контент или AJAX быстрого заказа. На
каталоге, странице списка, cart, checkout, orders, balance и Folio documents
контекстная ссылка ведёт сразу к тематическому anchor справки. Проверено по коду:
2026-09-03.

Локальное дополнение 2026-09-27: `pc-wholesale-help/assets/context-help.js`
содержит явную карту UI selectors → help anchors; не определять действия по
переведённому тексту кнопок. Ссылки role-gated через enqueue владельца, открываются
в новой вкладке и восстанавливаются после AJAX без дублей. Для новой операции
добавлять точный anchor и регрессию в `scripts/test-wholesale-context-help.cjs`.
Документы имеют отдельные `folio-search`, `folio-repeat`, `folio-invoice`,
`folio-invoice-email`; общий `folio` сохранён для старых ссылок. Deployment и
production-проверка этого дополнения пока не выполнены.

Если custom endpoint перенаправляет на заказы, сначала проверь владельца
редиректа `pc-account-tweaks.php`. Query-маршруты не требуют rewrite endpoint:
`pcoe_chat` обслуживает Conversations; `pcoe_approval` (ID запроса, не Woo-заказа)
и `approval_page` — CustomerApproval. Они исключены из редиректа только при
наличии соответствующего класса. Обычный корень кабинета ведёт на orders.
Проверку роли/владельца оставлять в обработчике; исключение из редиректа не даёт
доступ к чужой записи. Затем проверяй rewrite/endpoint, mapping и role gate;
не ослабляй доступ. Код и реальный локальный HTTP сверены 2026-09-29:
`pc-order-import-export/tests/customer-approval-http.php` (10 проверок, до
исправления владелец получал 302 на orders). Старые approval-ссылки сохраняются,
их не нужно пересоздавать. Выпуск и диагностика: `docs/OPERATIONS_RUNBOOK.md`,
«Подтверждение подготовленного менеджером заказа».

## Административные интерфейсы

Менеджерская справка (код проверен 2026-09-28):
`admin.php?page=pcoe-customers&view=help`, владелец `ManagerHelp` в
`pc-order-import-export`. Канон: `docs/MANAGER_CUSTOMER_GUIDE_UK.md`.
Отдельный домен `pcoe-manager-help` с English msgid и UK/RU переводами.
Доступ и enqueue требуют `manage_woocommerce`; клиентскую справку не использовать
вместо менеджерской. Контекстные ссылки задаются selectors/data-pcoe-help → anchor,
не переводом текста кнопки; после AJAX восстанавливаются без дублей. Связанные
экраны confirmations, balance и debtors получают свои тематические ссылки.
Новая операция требует нового/обновлённого объяснения, mapping и теста.

Клиентские сообщения: `?pcoe_chat=1`, владелец `pc-order-import-export`.
Детальная справка — `pc-wholesale-help/messages-guide.php`, канон в
`docs/WHOLESALE_CUSTOMER_GUIDE_UK.md`, PDF 06 в `docs/DEALER_PDF_GUIDES_UK.md`.
При документировании Telegram не считать Start завершением привязки: обязательны
возврат на сайт, проверка личности и подтверждение до истечения 15 минут.
Не создавать привязку и не отправлять реальные сообщения ради скриншота.
Сверено 2026-09-27 с production hashes клиентского кода ae9b494 и экраном Safari.

Навигацией Lavka владеет `paint-core/inc/admin-lavka-hub.php` и
`assets/admin-lavka-menu.*`; каноническая карта путей — раздел «Раскрываемое меню
Лавка» в `docs/OPERATIONS_RUNBOOK.md`. При переносе между WooCommerce и Lavka
меняй parent при регистрации страницы в плагине-владельце с fallback без
paint-core, сохраняя slug/capability/callback; одной JS-перегруппировки недостаточно.
Нова пошта, Checkbox и тестовый WayForPay входят в настройки, должники — в работу
с клиентами, пакетная загрузка изображений остаётся в Media. Проверено по коду и
изолированной проверке регистрации с/без paint-core и WooCommerce: 2026-09-08.

- `Повна синхронізація` — карточки, категории, media reconcile, cron/logs.
- `Синхронізація цін` — role prices, contracts, accounting price и product analytics.
- `Синхронізація цін → Сценарії аналітики` — общие и личные версионируемые
  сценарии schema v4 для вкладок товаров и движений, включая несколько складов.
- `Звіти Lavka` — отчёты, включая прибыль.
- Media Library -> `Lavka Product Media Upload` — пакетная загрузка.
- User edit -> mapping клиента ФОЛИО.
- Order edit -> документ ФОЛИО, сохранённые documents, split и links.
- Admin debtors -> общий снимок задолженности и переход к клиенту/детальному балансу.

## Правила UI

- Показывай loading сразу после клика, блокируй повторную кнопку и всегда отображай HTTP/raw body для диагностируемой ошибки.
- После фонового POST опрашивай status по контракту; не считай новый phase зависанием.
- Кнопка опасного apply доступна только после допустимого preview status и явного подтверждения.
- Основные подписи — понятные менеджеру украинские переводы; Java/Woo/SKU/ID — в технических подробностях.
- Таблицы не должны сжимать текст по одной букве. Для узких экранов используй horizontal scroll или responsive stacked layout.
- Ссылки на товары/документы из отчётов открывай в новой вкладке, если оператор должен сохранить фильтры текущего отчёта.
- Финансовые цвета должны быть согласованы между карточками итогов и таблицей; цвет не заменяет подпись.

## Сценарии товарной аналитики

- Страница управления создаёт, копирует, архивирует и версионирует сценарии.
- Один сценарий хранит базу, один или несколько складов, период, условия вкладок
  `Товари` и `Рух товару`, расчёт, сортировку и вкладку по умолчанию.
- Общий сценарий видят менеджеры; личный — только владелец. Архивирование не
  удаляет историю.
- Выбор сценария на странице товарной аналитики применяет обе группы условий.
  Изменённый вручную фильтр является временным и не сохраняется автоматически.
- Итоги, строки и складская детализация берутся из одного ответа Java schema v4;
  WordPress не складывает страницы и не пересчитывает финансовые коэффициенты.
- Активный редактор показывает только фильтры, поддержанные текущей Java analytics
  schema. Наличие поля в ФОЛІО или roadmap ещё не делает его работающим фильтром.
- Новый фильтр включается после подтверждения источника, проекции в атомарный
  snapshot, публикации через backend capabilities и server-side применения с
  возвратом в `appliedFilters`. Неизвестное условие не заменяется молча на `ANY`.
- Полная матрица и backend contract находятся в
  `docs/api/FOLIO_PRODUCT_ANALYTICS_SCENARIOS_BACKEND_TASK.md`.
- Операторская приёмка сценария Kreul по складам `1, 5, 7` находится в разделе
  «Статистика товара и закупочные решения» файла `docs/OPERATIONS_RUNBOOK.md`.

## Payment invoice export from customer documents

`pc-folio-customer-balance/inc/customer-invoice.php` owns XLSX/email derived from
the signed-in customer's ACCOUNT/EXPENSE. Reuse the server-side partner mapping
and verify returned document identity; never accept posted prices or customer
mapping. Confirmed payee details are runtime configuration, not `receiverName`
from the source document. No Folio document write is part of this export.
Current verified Woo barcodes are not historical document data; absent values
stay blank. Manager workspace does not expose these invoice actions yet.
Setup, mail acceptance/unknown-outcome semantics and acceptance tests:
`docs/OPERATIONS_RUNBOOK.md`, section "Счёт на оплату из документа ФОЛИО".
Source: local implementation and synthetic tests, 2026-09-12.
`pc-folio-invoices` belongs to the Lavka settings menu group and hub links;
keep `manage_options` for payee editing. Runtime defaults survive code deploys.

## Мобильный первый экран каталога

Проверено локально 2026-09-22: `psu-search-filters.php` группирует дополнительные
поля в native details; на ширине до 768 px они свёрнуты, активные группы условий
отмечены счётчиком. Без JS поля открыты. Переключение ширины не отправляет форму.
С 1.7.0 шапкой и размером контактов владеет `paint-shop-ux/inc/site-header.php`
и его assets; выбор склада остаётся у Paint Core.
Инструкция и снимок — `docs/WHOLESALE_CUSTOMER_GUIDE_UK.md` и pc-wholesale-help.

Проверено локально 2026-10-22: PSU 1.9.1 `assets/category-entry.js` на ширине
до 767 px однократно открывает выбранную категорию с её H1. Источник намерения —
обычный клик в плитке, native category menu или Woo breadcrumbs; точный URL и
30-секундный срок хранятся только в sessionStorage этой вкладки. Без storage/JS
остаётся обычная навигация. Маркер потребляется один раз; reload/back-forward,
явный hash, модифицированный клик/новая вкладка, поиск/фильтры/пагинация и
ширина от 768 px не запускают прокрутку. Ручной ввод или уже начатая прокрутка
отменяют отложенный переход. Не менять history.scrollRestoration и не переносить
фильтры в DOM ради этой задачи. Канон и код справки обновлены; deploy ожидается.

### Preorder balance v2 (2026-10-14)

`pc-folio-customer-balance` requires Java `calculationVersion >= 2` for individual/debtors responses. `prepaymentAmount` is now the reserve from active non-accounted ПРД/*РАЗОВАЯ invoices no older than six calendar months (inclusive), not payments. Older orders must be recreated by the manager. The ledger balance is separate; payable adds reserve and subtracts deferral. Individual balance renders `preorders[]`; XLSX shows the invoice list and reserve total below ledger movements on the main sheet, and retains a separate data sheet (2026-10-14 export follow-up). Customer and manager use the same exporter with existing ownership checks. Customer/manager documents have `preordersOnly` server-side filtering over the same six-month window before pagination. Java V18 separates old snapshot generations and live overrides; rebuild debtors after deploying Java + WP. Never relabel a v1 cached payment amount as an invoice reserve. Current outstanding invoice amount already excludes fulfilled goods (owner confirmation).

Profit saved-month comparison (2026-10-21, 0.11.0 local code/tests):
`profit-manager.js::comparisonLayout` owns city order and numbering for both the
manager table and comparison. `profit-comparison.js` uses explicit saved revisions
from the four preceding calendar months, excluding the target/future. At least one
available row value is enough; display coverage, never replace missing with zero.
Manual per-line decimal overrides (including zero) take priority and persist with
reference IDs per WP user/blog in this browser. Missing history requires input;
read errors remain distinct and must not fail an already confirmed calculation.
Refresh history explicitly replaces pinned prior revision IDs. Java amounts and
manager H/I formulas are unchanged; no shared backend baseline or Folio writes.
Migration from comparison storage v1 replaces the old whole-period baseline rule.
Workflow and limitations:
[profit manager guide](../../../../docs/PROFIT_REPORT_MANAGER_RU.md#сравнение-выбранных-месяцев-и-база-отклонений).

## Собственный футер и настройки магазина (2026-10-21)

`paint-shop-ux/inc/site-footer.php` владеет footer markup/CSS; GP adapter заменяет
штатный footer и размещение legal block, `wp_footer` даёт однократный fallback.
Реквизиты/документы остаются у `pc-wayforpay-compliance`; не копировать их в тему
или новые настройки. Ссылка на справку следует `pc_wholesale_customer_can_access`.
Существующие footer widget areas сохраняются. `psu_site_footer_enabled=false` до
`wp` возвращает прежний footer. Page containers ещё принадлежат GP;
шапка с 1.7.0 вынесена в собственный модуль ниже.
Breadcrumb delimiter — `paint-shop-ux/inc/store-presentation.php`; 24 товара по
умолчанию — существующий `psu-force-per-page.php`; дата classic checkout —
`paint-core/inc/checkout-created-date.php`. Не возвращать дубли этих callbacks в
child theme. Семантика `current_time('timestamp')` сохранена переносом.
Источники: названные modules, `tests/site-footer-local.php`, `tests/site-footer.spec.cjs`;
приёмка/rollback/deploy — README Paint Shop UX. Пока локальный выпуск.

## Граница самостоятельных стилей (1.6.0, 2026-10-21)

`paint-shop-ux/inc/storefront-styles.php` определяет `.psu-storefront` по Woo
conditionals и `[pc_quick_order]`, без page IDs. `assets/storefront.css` владеет
content typography/tokens, полями/кнопками/focus, account navigation и GP content
adapter; grid/category tiles — `catalog-cards.css`, geometry PDP — `single-product.css`,
cart — `cart-layout.css`. Legacy width/sidebar child CSS исключает этот scope.
Не добавляй новые content overrides в child theme и не снимай все стили GeneratePress
на живом сайте: PHP wrappers и исключённые из миграции страницы ещё используют тему;
header/menu с 1.7.0 принадлежат собственному модулю ниже. Не расширяй scope на
reports/обычные страницы автоматически: у обычных страниц отдельный scope с 1.8.0. Каталог сохраняет configured sidebar,
на <=1024 он под контентом; task pages cart/checkout/account/quick-order без sidebar.
Проверка CSS независимости удаляет theme links/inline style только в тестовом DOM:
`tests/storefront-styles.spec.cjs`. Заполненный cart/checkout fixture работает через
локальный prepend, гостевую сессию в памяти без persistent cart и carrier requests;
блокировать POST и удалять временные user auth tokens после role checks.
Deployment/rollback переносит plugin и child CSS вместе. Источник — модуль,
styles и acceptance tests; human summary — `docs/SYSTEM_OVERVIEW.md`, пользовательская
инструкция — `docs/WHOLESALE_CUSTOMER_GUIDE_UK.md` и `pc-wholesale-help`.


## Собственная шапка и меню (1.7.0, 2026-10-21)

`paint-shop-ux/inc/site-header.php` и `assets/site-header.css/js` владеют шапкой,
контактами и навигацией; логотип хранится в `assets/site-logo.svg`. Стандартный
`wp_body_open` выводит шапку один раз. GP adapter снимает только известные callbacks
старой шапки/навигации; не удалять все чужие callbacks и featured-page content.
Меню берётся из существующего `primary` через `wp_nav_menu`: сохранять порядок,
Woo/Relevanssi-фильтры и `pc_wholesale_help_filter_quick_order_menu`. Не переносить
жёсткий ID старого mobile CSS: доступ к быстрому заказу определяется ролью и на
телефоне. Без JS ссылки открыты; JS добавляет мобильное раскрытие и клавиатурное
управление. Скрытое меню не должно оставлять доступные Tab элементы.

Шапка вызывает существующий `pc_render_alloc_control()`. Его nonce, DOM IDs,
role gate и правила сохранения/корзины принадлежат Paint Core. Фильтр
`pc_alloc_header_rendered` отключает старый footer mount только после вывода шапки;
при rollback исходное размещение остаётся доступным. Контекстная справка добавляется
рядом с `.pc-alloc`; оставлять место для этой ссылки. Разметка/controls инициализируются
однократно, без пересохранения предпочтения при открытии/изменении ширины меню.

Откат: `psu_site_header_enabled=false` до `wp`. Legacy child code остаётся для этого
пути. Никакая настройка меню/склада не мигрирует в БД. Deploy/rollback должны включать
Paint Shop UX, Paint Core, child CSS и справку/переводы/изображение вместе. GP всё ещё
даёт PHP-шаблоны, регистрацию widget areas и theme/Woo supports; пока не отключать
родительскую тему. Источники — header module и `tests/site-header-local.php`;
приёмка/rollback — README Paint Shop UX. Human ownership — `docs/SYSTEM_OVERVIEW.md`,
инструкция — `docs/WHOLESALE_CUSTOMER_GUIDE_UK.md` и `pc-wholesale-help`.
На 2026-10-21 локальная приёмка пройдена: 518 PHP-проверок и 25 browser-сценариев;
production-публикация ожидает deploy.

### Медиа в описании категории (1.7.1, 2026-10-21)

При расхождении картинки между редактором категории и витриной сначала сравнивай
сохранённый `product_cat.description` с текущим `wp_get_attachment_image_src`
по его `wp-image-ID`/`size-*`. Media Cloud фильтрует `the_editor_content`, а native
Woo archive — отдельный `woocommerce_taxonomy_archive_description_raw`: редактор
может показывать исправный файл при старом URL в БД. Не путать описание с
`thumbnail_id` категории и fallback плиток; не перезагружать/пересвязывать товары
ради такого симптома. Узкий adapter PSU `inc/category-description-media.php`
разрешает адрес существующего attachment на выводе с проверкой host/имени файла;
не вызывает editor pipeline, HTTP или DB writes. Источники: adapter, его local test,
Media Cloud StorageContentHooks и read-only сверка production 2026-10-21.
Приёмка/ограничения — README Paint Shop UX, действия менеджера —
`docs/MEDIA_MANAGER_GUIDE_UK.md`. После deploy проверять публичную страницу,
а не только редактор категории.


### Порядок блоков архива (1.7.2, 2026-10-21)

Общий owner размещения — `woocommerce_shop_loop_header`: оптовая справка
`pc_wholesale_help_catalog_link` @4, `psu_render_catalog_filters` @5,
PSU per-page @7, native Woo header/description @10. Не возвращать форму на
`woocommerce_before_shop_loop` или `woocommerce_no_products_found`: archive
header работает и при пустом поиске, где должна быть ровно одна форма.
Per-page исключает shortcode loops и чужие страницы; сохранять его guard
`woocommerce_product_loop()` и текущий приоритет `pp/per_page`, перенос не меняет
расчёт размера выдачи. Пустой product search не должен показывать 12/24/48.
Счётчик/сортировка/пагинация остаются у товарной выдачи, single-product help hook
не переносится. Проверять порядок title/description/tiles и отсутствие дубля формы
под гостем/оптовиком на desktop/mobile, включая zero results. Источники:
`psu-search-filters.php`, `paint-shop-ux.php`, `pc-wholesale-help.php`; приёмка и
согласованный deploy/rollback — README Paint Shop UX. Пользовательский канон —
`docs/WHOLESALE_CUSTOMER_GUIDE_UK.md`. Проверка production нового порядка ожидает deploy.


## Обычные информационные страницы (1.8.0, 2026-10-21)

Owner — `paint-shop-ux/inc/public-pages.php`, `templates/public-page.php` и
`assets/public-pages.css`. При активном GP выбираются только default page/search/404
шаблоны из parent/child; чужие plugin templates и специальные page templates
сохраняются. Scope `.psu-public-page` включается после выбора шаблона, внешний
PHP-каркас всё ещё у GP. Новый parent theme требует отдельной интеграции.
Не расширять выбор автоматически на главную/Woo, Elementor, приложения на
shortcodes, custom/reusable blocks или отчёты. Товарный поиск определять также
по array `post_type` и `_psu_catalog_query`; fallback обычного поиска не меняет query.
На 404 форма отправляет явный `post_type=product` в существующий каталог.

Сохранять `the_content`, password guard, `wp_link_pages` и comments API.
Featured image на принятой обычной странице принадлежит этому шаблону: adapter
снимает только `generate_featured_page_header`, остальные GP/plugin callbacks
не удалять. Не переносить тексты юридических страниц или media в код/новые options.
Откат — `psu_public_pages_enabled=false` до выбора template. Источник/границы:
`tests/public-pages-local.php`; приёмка и согласованный deploy/rollback — README
Paint Shop UX. Владельцы — `docs/SYSTEM_OVERVIEW.md`; канон и код справки обновлены.
2026-10-22 на production подтверждён активный PSU 1.9.0 с этим модулем.


## Независимый каркас: этап 2 (1.9.0, 2026-10-21)

`wp-content/themes/lavka-storefront` — самостоятельная классическая тема без
Template/GP dependency. Подготовлена 2026-10-21, реально активирована на paint.local
2026-10-22 с разрешения владельца. После установки ZIP 0.1.0 владелец отдельно
разрешил production activation; она завершена 2026-10-22. В code deploy новая
папка не добавлена; будущая доставка обновлений требует отдельного решения.

PSU public-pages принимает `current_theme_supports('psu-public-pages')` с прежними
ограничениями default page/search/404 и исключениями приложений. Сохраняются
`wp_head/wp_body_open/wp_footer`, #page/#content/#primary/#main и Woo gallery supports.
PCOE использует `lavka_storefront_before_main_content` с прежним single-render guard;
account title filter поддерживает обе темы. Меню primary берётся из сохранённых
child theme mods только при отсутствии собственного ключа nav_menu_locations;
явное пустое назначение не заменяется. Widget IDs прежние, sidebar читает старую
настройку без записи. Шаблоны приложений и права остаются у плагинов.

Источник и проверка — README темы и `tests/lavka-theme-{bootstrap-local,local}.php`.
Preview bootstrap не вызывает switch_theme и сверяет сохранённые options.
Отдельная реальная активация 2026-10-22 покрыла следующий WP load/init@99,
после которого core очистил theme_switched и перенёс primary/активные widgets.
Меню теперь сохранено в own theme mods; read-only legacy fallback остаётся для
других установок. Проверка: 59 activation + 103 integration, без pre_option
подмены темы. Core может очищать неактивные ссылки на незарегистрированные widgets;
сравнивать отдельно активные назначения, widget data и inactive list, не считать
изменение сериализованного sidebars_widgets само по себе потерей активных виджетов.
Woo image hash не изменился, regen queue осталась пустой. Backup и точечный возврат:
`docs/BOOTSTRAP_AND_RECOVERY.md`, «Локальная активация Lavka Storefront».
Перенос папки в deploy остаётся отдельным изменением; активация не добавляет её
в список синхронизации автоматически.

Production preflight 2026-10-22: 20 файлов темы и 45 runtime-файлов PSU совпали
с локальными; PSU 1.9.0 активен, WP/PHP совместимы. Во время preflight saved theme была GP Child;
primary и два sidebar widgets доступны. Пустой Customizer iframe воспроизводился
и на действующей теме; для read-only проверки с правом `switch_themes` работает
штатный `?wp_theme_preview=lavka-storefront`, который надо сохранять на каждом URL.
Это не проверяет production `after_switch_theme`. Закрытый локальный снимок
86 options и `.htaccess` не заменяет полную БД: на этапе preflight свежая полная
копия ещё не была подтверждена. Источники: защищённый SHORTINIT preflight и браузер;
подробности — [production preflight](../../../../docs/BOOTSTRAP_AND_RECOVERY.md#production-preflight-lavka-storefront-2026-10-22).

Production activation 2026-10-22: после отдельного решения владельца выполнены
штатные `switch_theme` и следующая полная загрузка WordPress. 10 read-only checks
подтвердили cleared `theme_switched`, собственный primary, неизменные widget
assignments/hash, Woo page/image options и пустые очереди image regeneration.
Lock освобождён. До записи проверены checksums полных backups БД/файлов;
последовательный live backup не считать test restore. Старые темы оставлены,
состояние production теперь `lavka-storefront`. Браузерная приёмка обычных URL
под администратором и гостем пройдена на desktop/390/768 px, включая галерею,
sticky context, мобильное меню, пустую корзину и вход. Полный checkout/платёж,
все бизнес-роли и физические телефоны этим не подтверждены. Источник и точечный возврат —
[production activation](../../../../docs/BOOTSTRAP_AND_RECOVERY.md#production-activation-lavka-storefront-2026-10-22).


Profit warehouse/exclusion settings (2026-10-24, 0.12.0 code/tests):
`class-profit-scope-settings.php` / `profit-scope-settings.js` expose Java-owned
versioned scopes and read-only Folio catalog on the profit page. Four warehouse
lists separate sales/inventory and Kyiv/Odesa. Inventory exclusions are opaque
Folio group-path IDs, not Woo categories or guessed numeric groups. Root and
subgroup branches cover descendants (up to six levels); never infer identity
from labels. Persisted reports use their own `scopeSettings` + `scopeCatalog`,
including names/paths for Excel; reading history must not fetch current catalog.
New batches pin `expectedScopeSettingsVersion`; dirty/unknown/CAS conflict stops
calculation, with no auto retry. Deploy compatible Java (migration V20) first.
See [profit manager guide](../../../../docs/PROFIT_REPORT_MANAGER_RU.md#склады-и-исключения-из-остатков)
and [contract](../../../../docs/api/FOLIO_PROFIT_SAVED_REPORTS_FRONTEND.md).

Profit disclosure UI (2026-10-24): `profit-folds.js` enhances report sections with
native details/summary controls and preserves their state during in-page renders.
Keep DOM IDs and existing event-bound nodes when wrapping; never use visibility
as an export or calculation filter. Revision pagination must stay inside its
fold. Manager steps: [profit guide](../../../../docs/PROFIT_REPORT_MANAGER_RU.md#как-свернуть-длинные-таблицы).
