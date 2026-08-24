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

- Каждый собственный plugin использует собственный text domain.
- Код содержит английский `msgid`; украинский и русский живут в catalog переводов.
- После изменения строк обнови POT/PO/MO существующим инструментом проекта и проверь runtime locale.
- Технический термин можно оставить в отдельном раскрываемом блоке, но основная подпись менеджеру должна быть понятной.
