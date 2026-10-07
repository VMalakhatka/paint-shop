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
  только к конечной категории и её родителю, у которого все видимые дети конечные.
  Контекст берётся из индекса `PSU_Category_Menu`, а поиск всегда выводит полное имя.
  Не возвращать конкурирующий clamp в child theme. `stock-locations-ui.php`
  отдельно строит mini-панель: имена складов и количества без видимых подписей
  и итога; приоритет выделяется CSS, выбранный нулевой склад сохраняется.
  PDP/распределение не используют этот renderer. Канон и локальная проверка
  2026-10-07: `docs/WHOLESALE_CUSTOMER_GUIDE_UK.md`, тест `catalog-cards-local.php`.
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
  в option `psu_catalog_suppliers`. Видимость скрывает только пункт фильтра,
  порядок группирует товары без текстового поиска до пагинации; keyword search
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
Шапкой и размером контактов владеет generatepress-child; выбор склада сохраняется.
Инструкция и снимок — `docs/WHOLESALE_CUSTOMER_GUIDE_UK.md` и pc-wholesale-help.
