# Плагины проекта

Проверено по активным плагинам и headers локального сайта: 2026-08-24. Перед изменением production сверяй `active_plugins` и MU-каталог.

## Собственные обычные плагины

| Плагин | Ответственность |
|---|---|
| `paint-core` | общие функции остатков, allocation plan и корзины |
| `paint-shop-ux` | каталог, карточка товара, поиск, компактный UX |
| `lavka-sync` | остатки, location mapping, REST для Java |
| `lavka-total-sync` | карточки и категории, полная/force синхронизация, media reconcile; без цен и stock |
| `lavka-price-sync` | цены по ролям, договоры, учётные цены, cron и продуктовая аналитика |
| `role-price` | runtime-подмена цены по `_wpc_price_role_<role>` |
| `lavka-product-media-upload` | проверка и полный цикл batch image upload |
| `lavka-reports` | административные отчёты, включая прибыль |
| `pc-order-import-export` | CSV/XLSX import/export, корзина и draft orders |
| `paint-nova-poshta-multishipping` | прямая багатоскладська інтеграція Нової пошти; окремі shipment/ТТН, зовнішні B2B-ТТН; налаштування складу окремо зберігає зареєстровану адресу відправника і точний пункт передавання НП; реальне створення ТТН до окремого підтвердження заблоковане |
| `pc-checkbox-fiscalization` | универсальный caller-agnostic исполнитель Checkbox: получает готовую команду по REST/PHP или по явно указанному Java source ID, валидирует totals/taxes, обеспечивает idempotency/reconcile; не решает, какие продажи фискализировать; live writes и production activation по умолчанию заблокированы |

Перед переносом функции проверь, не вызывается ли она из другого собственного плагина. Не объединяй плагины только из-за похожего UI.

Меню категорий: в `paint-shop-ux` 1.3.0 собственный widget `psu_category_menu`
(«Категорії Лавки») работает без WPB Accordion. Перенос из активного WPB widget
выполняется явно в Appearance через nonce/capability и журнал исходных/целевых
options; наличие новых файлов не означает, что sidebar уже перенесена. WPB нельзя
отключать на другом окружении без проверки его остальных widgets/shortcodes и
builder content. Для отката сначала активировать WPB; конфликт более поздних
widget edits нельзя затирать backup-ом. Источник: `paint-shop-ux/inc/category-*.php`
и README владельца. Проверено локально и на production 2026-09-17: production
sidebar перенесена явно, WPB отключён после аудита; исходные настройки сохранены.
Результат и границы: `docs/CATEGORY_MENU_LOCAL_VERIFICATION_2026-09-15.md`.

Проверено production read-only 2026-09-17: ветка меню 1.3.0 может сохранить
контекстные quick-order URL в общем кэше и выдать их гостям на обычной витрине.
`get_term_link()` здесь не является независимым от страницы: `term_link` меняют
MU `pc-wholesale-quick-order.php` и child theme. При работе с меню обязательно
проверять обе очередности прогрева «быстрый заказ → каталог/REST» и обратно;
разделять нейтральные category data и контекстные ссылки. Не объявлять оптимизацию
полностью принятой только по скорости. Причина, наблюдаемый отказ гостевого
перехода и следующая задача: `docs/SITE_PERFORMANCE_AUDIT_2026-09-14.md`, раздел
повторного production-аудита. Локальная правка 1.3.1 (2026-09-17): cache хранит
канонические URL/slug через scoped `PCQO_Category_Links::catalogue_url()`, не удаляя
чужие term_link filters. Единственный rewrite принадлежит quick-order MU, не теме.
Контекст публичной quick-order страницы проецируется после кэша и явно передаётся
в REST; это не разрешение на доступ к содержимому. После deploy владельцем
production smoke 2026-09-17 подтвердил версию 1.3.1, обычные гостевые URL и
неактивный WPB; отдельная разрешённая оптовая production-сессия не проверялась.
Источник: README Paint Shop UX и отчёт `CATEGORY_MENU_LOCAL_VERIFICATION_2026-09-15.md`.

Размер страницы каталога принадлежит MU `psu-force-per-page.php`: валидный `pp`
имеет приоритет над legacy `per_page`, затем default24/`psu_products_per_page`.
Оба query-поля posts_per_page/posts_per_archive_page согласованы. Старые cookies
колонок/рядов не используются; viewport не должен перезагружать документ.
Paint Shop UX выводит переключатель, но не дублирует resolver. Фильтры сохраняют
валидный выбор, смена размера сбрасывает только страницу. Проверено локально
2026-09-17; production smoke после deploy подтвердил отсутствие прежнего reload
скрипта. Полный Network trace выполнен локально, не на production.

Приёмка этих двух изменений требует чистого browser Network trace (первое открытие
1 document GET, resize 0, выбор размера/страницы 1) и настоящей оптовой сессии;
имитация ролей в PHP не заменяет проверку доступа. Локальные browser fixtures
создают только короткие сессии существующих пользователей; cleanup отзывает токены,
не меняя права. Сопоставляй renderer в одном процессе и не выдавай колебания HTTP
разных серий за эффект patch. Источник: tests и README Paint Shop UX, приёмка
2026-09-17 в `docs/CATEGORY_MENU_LOCAL_VERIFICATION_2026-09-15.md`.

Frontend versioning Stock Locations принадлежит `paint-shop-ux/inc/slw-assets.php`
(локальная 1.3.2, проверено 2026-09-17). Только известные handles и исходные
host/port/path получают версию SLW + content hash; admin, CDN/replacements и
нечитаемые файлы не трогать. Не deregister/dequeue и не кешировать вместе с JS
персональные inline/localized данные. Local требует revalidation: 304 означает
повторное использование тела, а не отсутствие сети. Browser cache тестировать
без отключающего кеш interception. Серверные заголовки и production compression
не принадлежат этому патчу; inventory/приёмка/откат — в README владельца.

Для `paint-nova-poshta-multishipping` не отождествляй адрес контрагента из
`Counterparty/getCounterpartyAddresses` с физическим отделением/почтоматом сдачи.
При `sender_type=warehouse` строка склада готова только после выбора отдельного
`handover_warehouse_ref` из `Address/getWarehouses`; при `sender_type=doors`
достаточно зарегистрированного адреса для курьерского забора. Проверено read-only
запросами официального API НП 2026-08-24.

Политика оплаты НП хранится отдельно в option `pnpm_delivery_policy_v1` и имеет
версионированную схему. Она сопоставляет Woo-роли с профилями `retail`/`partner`,
задаёт порог заказа, бюджет магазина (полная, фиксированная или процентная
компенсация), плательщика каждой составляющей доставки и отдельные разрешения COD
для одной/нескольких посылок. В версии plugin `0.4.0` policy сохраняется, но ещё не
применяется к checkout; это исключает скрытое изменение текущей стоимости доставки.
Проверено по коду `Domain/DeliveryPolicy.php` и `Admin/SettingsPage.php`: 2026-08-24.

## MU-плагины

| Файл/группа | Ответственность |
|---|---|
| `lavka-ecosystem-lock.php` | общий lock и события длительных процессов |
| `pc-folio-customer-map.php` | связь WP user с партнёром ФОЛИО |
| `pc-folio-customer-balance*` | баланс клиента, документы, административные должники |
| `pc-folio-order-link.php` | order preview/create, multiple documents, split и parent/child links |
| `pc-guest-customer-register.php` | регистрация гостя и default Folio internet-client mapping |
| `pc-wholesale-quick-order.php` | табличное быстрое оптовое оформление |
| `pc-wayforpay-compliance.php` | обязательные страницы и classic checkout/cart compliance |
| `pc-wayforpay-test-access.php` | ограничение тестового gateway выбранным пользователям |
| `pc-stock-tap.php` | barrier/trace для stock writes |
| `stock-import-csv-lite.php` | ручной staging остатков |
| `stock-sync-to-woo.php` | перенос staging в Woo location stock |
| `stock-locations-ui.php` | вывод складов и allocation shortcode |
| `psu-search-filters.php`, `psu-force-per-page.php` | фильтры, Relevanssi и размеры выдачи |
| `role-price-import-lite.php` | ручной импорт role prices |
| guards/debug/loaders | точечная защита, диагностика и загрузка переводов |

MU-плагины загружаются автоматически и не видны как обычная кнопка активации. Проверяй конфликты hooks по всему `wp-content/mu-plugins`, а не только по главному файлу.

## Критические сторонние плагины

- WooCommerce — products/orders/checkout и HPOS.
- Stock Locations for WooCommerce — location taxonomy и stock per location.
- Relevanssi — поиск по названию, SKU и штрихкоду; после изменения индексируемых данных нужен точечный или полный reindex.
- Media Cloud (`ilab-media-tools`) — offload WordPress attachments в OVH/S3.
- WayForPay gateway — classic checkout совместимость; в test mode ограничивается собственным MU-плагином.
- WPC Price by User Role — соседняя система ролей; проверяй пересечение с `role-price`.
- GeneratePress + child theme — тема.
- Loco Translate — UI-переводы, но исходные `.po/.mo` собственного плагина должны жить в Git.
- WP Mail SMTP/MailPoet/EmailKit — почта; не путать transactional Woo emails с маркетинговыми.
- Rank Math, Smush, WP All Import и import/export plugins — менять только через их публичные hooks/API.

Не патчить сторонний plugin в `wp-content/plugins` без доказанной невозможности extension hook и явного решения пользователя: обновление сотрёт правку.

## i18n

Довідник точок НП, локально 2026-09-26: `WarehouseDirectory::searchPage` у 0.7.0
пагінує сирі рядки до фільтрації типу; порожня сторінка branch/postomat не доводить
відсутності наступних точок. `PointCard`/checkout показує API-ліміти, але не
валідує запаковані місця замовлення. Нуль/відсутній ліміт — unknown, не unlimited;
не змішувати PlaceMaxWeightAllowed та TotalMaxWeightAllowed. Exact Ref при
відновленні картки перевіряється разом із CityRef/типом. Контракт і перевірки:
README `paint-nova-poshta-multishipping`; інструкція —
`docs/NOVA_POSHTA_WHOLESALE_GUIDE_UK.md`. Production 0.7.0 не розгорнуто.

Клиентские ТТН, проверено локально 2026-09-20: `paint-nova-poshta-multishipping`
0.6.0 принимает текст/ссылку на checkout только по `pc_wholesale_customer_roles`.
Один логический shipping package → несколько физических складов. Shipment хранится
у исходного Woo order; Folio children ссылаются на него без копирования ТТН.
Checkout retry сохраняет тот же набор и обновляет связи recreated item IDs.
COD клиента не является нашим платежом. Операторский flow и ограничения:
`docs/OPERATIONS_RUNBOOK.md`, раздел «Клиентские ТТН Новой почты»;
подробный контракт — README плагина. Production не проверен.

- Каждый собственный plugin использует собственный text domain.
- Код содержит английский `msgid`; украинский и русский живут в catalog переводов.
- После изменения строк обнови POT/PO/MO существующим инструментом проекта и проверь runtime locale.
- Технический термин можно оставить в отдельном раскрываемом блоке, но основная подпись менеджеру должна быть понятной.
