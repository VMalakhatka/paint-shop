# Архитектура Lavka / KREUL

Документ объясняет границы компонентов и потоков данных. Он не содержит секретов,
переменных окружения со значениями или переносимых между окружениями ID.

Практическая карта исходного кода, API, окружений, разработки и запуска находится в
[BACKEND_GUIDE.md](BACKEND_GUIDE.md).

Проверено по коду WordPress, проектным skills и Java-документации: 2026-09-03.

## Карта системы

```text
OVH provider layer
  ├─ contracts / renewals
  ├─ domains / DNS / MX / TLS routing
  ├─ Dedicated / VPS / vRack / public network
  └─ Object Storage bucket / policy / lifecycle
                 |
                 v
Покупатель / менеджер
        |
        v
WordPress + WooCommerce + GeneratePress Child
        |
        +-- собственные plugins и MU-plugins
        |       |
        |       +-- MariaDB: сайт, HPOS, настройки, журналы, projections
        |       +-- Media Library -> Media Cloud -> OVH/S3
        |
        +-- server-side proxy/AJAX/REST
                |
                v
        Java API (Spring Boot / Java 17)
                |
                +-- legacy MS SQL / ФОЛИО
                +-- MariaDB projections для аналитики и idempotency
```

Инфраструктурная цепочка ФОЛИО рассматривается отдельно:

```text
OVH service -> physical host -> hypervisor -> Windows VM
            -> MSSQL runtime -> Paint_Ua/Paint_Rus -> Java clients
```

Одинаковый адрес или имя не доказывает, что это один слой. Перед изменением нужно
установить точный target и зависимых клиентов.

## Владельцы поведения и данных

### Legacy 1lavka.com → KREUL

Проверено чтением production-каталогов 2026-10-29: legacy OpenCart и KREUL
обслуживаются на одном сервере; WordPress URL-настройки не нужно менять для
направления старых ссылок на KREUL. Собственный мост находится в
[`scripts/legacy-domain-redirect`](../scripts/legacy-domain-redirect/README.md),
**первый этап включён на production 2026-10-29, 14:31 UTC**. Сопоставлено
8 374 товара по артикулу и 1 723 категории по пути/составу.
Сгенерированная карта публичных URL хранится вне Git.

Мост владеет только HTTP-переходами каталога старого домена. OpenCart сохраняет
старые сессии/корзины, заказы, кабинет, checkout, callbacks и не перенесённое содержимое; Woo —
новые покупки на KREUL. Перенос аккаунтов имеет отдельного владельца
`pc-order-import-export/inc/OpenCartCustomerImport.php`: повторный импорт не следует
из самого включения переходов. Детали проверки, ограничения первого этапа,
preflight, ручное включение и откат — в README моста. Обычный WordPress deploy его
не распространяет. DNS/MX и `paint.dn.ua` не затрагиваются.
Сверка email выполнялась отдельно, только чтением: 710 совпадений из 739 записей;
29 записей и различия оптовых ролей требуют разбора. Не объявлять полный перенос
завершённым: действующие legacy-сессии, не перенесённое содержимое и Search Console
остаются отдельными этапами. `www.1lavka.com` требует DNS/TLS-проверки.

Реестр НП (0.10.0, 25.10.2026, локальная реализация):
`paint-nova-poshta-multishipping/src/Registry` хранит отдельную проекцию отправлений,
наблюдений и подтверждённых связей с ФОЛИО в пяти `pnpm_registry*` таблицах MariaDB.
Она не требует Woo order и не изменяет lifecycle `pnpm_shipments`.
НП → preview → подтверждение → observation; менеджер → классификация/аудит;
Java read API → проверенные реквизиты → подтверждённая связь. Для возвратов есть
явная ручная верификация, для приходных API не подтверждает поставщика.
Счёт и связанная расходная остаются отдельными связями. Эта проекция пока не
является источником сумм для отчёта прибыли. Период истории начинается 01.06.2026;
полнота входящих и старых событий требует отдельной сверки. Production-миграция
запускается администратором явно, не при загрузке кода. Доступ, replay, блокировки,
публикация и откат: [README реестра](../wp-content/plugins/paint-nova-poshta-multishipping/README.md#shipment-registry-0100--2026-10-25).

| Область | Владелец | Источник истины |
|---|---|---|
| Каталог, корзина, checkout, кабинет, HPOS-заказы | WooCommerce + собственные PHP-плагины | текущий Woo runtime и PHP-код |
| Шапка и основное меню | `paint-shop-ux/inc/site-header.php` | собственные разметка/CSS/JS; меню WordPress `primary` и существующие фильтры доступа; выбор склада — `paint-core` |
| Внешняя PHP-оболочка и резервные шаблоны | `lavka-storefront` активна локально и на production; GeneratePress + `generatepress-child` сохранены для возврата | `wp-content/themes/lavka-storefront/`; штатная production-активация и 10 серверных postchecks 2026-10-22 |
| Обычные информационные страницы и 404 | `paint-shop-ux/inc/public-pages.php` | ограниченный выбор default templates, `templates/public-page.php` и `assets/public-pages.css`; содержимое — WordPress и прежние провайдеры |
| Футер | `paint-shop-ux/inc/site-footer.php` | собственная разметка/CSS, adapter GP + однократный fallback `wp_footer`; юридические данные — `pc-wayforpay-compliance` |
| Каталог, товар и стили покупательских страниц | `paint-shop-ux` | собственные modules/templates/assets, scoped storefront.css; Woo hooks сохраняют цены, склады и покупку |
| Общие остатки и allocation | `paint-core` | код, Woo product/order state |
| Полная синхронизация карточек и категорий | `lavka-total-sync` + Java `/admin/sync/jobs`, V19 checkpoints | job ID, подтверждённые порции, terminal status и общий lease |
| Остатки, названия и глобальные группы складов | `lavka-sync` | стабильные location term ID/slug, `lavka_folio_warehouses`, Folio warehouse ID и Woo stock |
| Цены по ролям | `lavka-price-sync` + `role-price` | mapping роли к договору и product meta |
| Оптовая справка и единый доступ к быстрому заказу | `pc-wholesale-help` + `pc-wholesale-quick-order` | server-side allow-list ролей `partner`, `opt`, `opt_osn`, `schule` |
| Учётные цены и snapshot | Java + `lavka-price-sync` | Java job status и MariaDB generation |
| Статистика товара | Java projection + `lavka-price-sync` | активное поколение `folio_product_*` |
| Отчёты | `lavka-reports` и профильные MU-плагины | Java response + параметры и контрольные суммы |
| Заказ Woo → документы ФОЛИО | `pc-folio-order-link` + Java | стабильный request ID и `documents[]` |
| WayForPay → независимый банковский приход ФОЛИО | `pc-wayforpay-checkbox/FolioPayments` + Java `folio.payment` | verified Approved, снимок плательщика, отдельный outbox и атомарный MSSQL-журнал; [запуск](FOLIO_WFP_PAYMENTS.md) |
| Реквизиты для банковского перевода и счетов на оплату | полная PHP-форма WooCommerce BACS в `pc-folio-customer-balance/inc/bacs-settings.php` + `inc/customer-invoice.php` и `inc/payment-details.php` | единственный счёт из `woocommerce_bacs_accounts`, дополненный налоговым номером и подтверждением точного набора реквизитов в настройках Woo; BACS email/thank-you/Blocks, счета ФОЛИО и shortcode контактов читают этот источник; [проверка и откат](MANAGER_CUSTOMER_GUIDE_UK.md#payment-recipient) |
| Изображения Woo | `$image-in-woo`: Media Library + Media Cloud + Java media API | attachment ID, exact S3 HEAD proof и ФОЛИО reference |
| OVH contract/DNS/compute/S3 policy | `$manage-ovh-infrastructure` | текущий OVH Manager/API и фактический resource state |
| Java image/container/env/health | `$build-java-docker-runtime` | текущий Docker/deploy code и runtime state |
| ФОЛИО-документы и бизнес-данные | ФОЛИО/MS SQL | живая схема и подтверждённое поведение |
| Сервер и MSSQL runtime | host/Windows/MSSQL operations | фактическое read-only состояние слоёв |

Источник реквизитов проверен по коду 29.10.2026: редактирование выполняется только
в WooCommerce → Настройки → Платежи → Банковский перевод. В разделе «Лавка:
рахунки на оплату» остаются логотип и ссылка на Woo; старые поля получателя в
`pc_folio_invoice_settings` не являются резервным источником. Подтверждение связано
с точными реквизитами: их изменение требует повторной проверки. Адаптер не меняет
заказы, платежи или сохранённые настройки при чтении. Отсутствующий, неоднозначный,
неподтверждённый или повреждённый набор реквизитов скрывается с сообщением клиенту.
Старые письма, XLSX и сохранённые запросы подтверждения остаются историческими
снимками; страница старого запроса скрывает реквизиты, не совпадающие с текущими.
Новый шаблон контактов использует shortcode `pc_folio_payment_details`. Для
существующей страницы из `PC_WAYFORPAY_PAGES_OPTION['contacts']` с меткой управления
фильтр `the_content` заменяет только узнаваемую пару старых банковских строк
в `pc-seller-details` на этот shortcode при показе. Сохранённый текст не меняется;
нестандартная разметка требует отдельной проверки. Версия содержимого всех
юридических страниц не повышается, остальные страницы и юридические данные
продавца не переписываются.

С 2026-10-21 настройки магазина вынесены из дочерней темы: разделитель хлебных
крошек — `paint-shop-ux/inc/store-presentation.php`, исходные 24 товара на странице —
существующий MU `psu-force-per-page.php`, установка даты создания заказа при classic
checkout — `paint-core/inc/checkout-created-date.php`. Семантика даты сохранена.
Новый футер заменяет вывод GeneratePress, сохраняет юридические ссылки и активные
footer widgets. В 1.6.0 базовые стили
каталога, товара, корзины, checkout, кабинета и списка товаров перенесены в
`paint-shop-ux/assets/storefront.css` и component CSS. Контекст определяется Woo
conditionals/shortcode; отчёты остаются у своих владельцев. Обычные страницы
с 1.8.0 обслуживает отдельный ограниченный модуль ниже.
Контейнеры, сетка, типографика и controls проверяются локально с удалённым CSS темы;
это не проверка переключения PHP-темы. Локальная приёмка
и откат описаны в [Paint Shop UX](../wp-content/plugins/paint-shop-ux/README.md).

В 1.7.0 шапка, контакты и основная навигация переходят в `inc/site-header.php`,
`assets/site-header.css` и `assets/site-header.js`. Вывод использует стандартный
`wp_body_open`; adapter отключает прежние callbacks шапки/меню GP. Сохранённое
меню `primary` продолжает проходить фильтры ролей. Переключатель склада выводит
существующий `pc_render_alloc_control()` прямо в шапке; его режимы, сохранение
выбора и правила корзины остаются у Paint Core. Старый footer mount пропускается
только после фактического вывода собственной шапки. При
`psu_site_header_enabled=false` до `wp` остаётся прежний путь темы.

В 1.8.0 информационные страницы с default template и поддерживаемым содержимым,
404 и запасной обычный поиск получают собственную разметку контента и стили.
WooCommerce, главная, Elementor, приложения на shortcodes, custom blocks и чужие
шаблоны исключены. Товарный поиск остаётся у каталога; новый шаблон не меняет
query routing. Содержимое, ссылки, парольная защита и разделение страниц сохраняют
WordPress API. Локально 2026-10-21 пройдены 166 PHP-проверок, сравнение текста
четырёх правовых страниц и browser-проверки 320/390/768/1280 px, в том числе без
CSS темы. Канон и код справки обновлены; 2026-10-22 на production подтверждён
активный Paint Shop UX 1.9.0, включающий этот модуль.
Приёмка и откат — [Paint Shop UX](../wp-content/plugins/paint-shop-ux/README.md).

Самостоятельная тема `lavka-storefront`, без родительской темы, подготовлена
2026-10-21 и активирована на `paint.local` и production 2026-10-22 по отдельным
решениям владельца. Она
предоставляет PHP-каркас, резервные шаблоны WordPress, регистрацию меню, областей
виджетов и возможностей WooCommerce, включая галерею товара. Шапка, футер,
каталог, карточка товара и бизнес-правила остаются у прежних плагинов. Чужие
шаблоны приложений и Elementor сохраняют своих владельцев.

Для совместимости новая тема читает прежнее назначение меню `primary` из
`theme_mods_generatepress-child`, только пока у неё нет собственного сохранённого
назначения. Идентификаторы областей виджетов сохранены; расположение боковой
колонки каталога использует прежний `generate_settings.blog_layout_setting`
как запасное значение, если новый `catalog_sidebar` не задан. Это чтение прежних
настроек, без копирования или изменения данных. Контракт находится в
[functions.php новой темы](../wp-content/themes/lavka-storefront/functions.php).

Проверено: 2026-10-22, отдельные PHP-процессы и браузер после настоящего локального
переключения. WordPress завершил `after_switch_theme`: назначение primary и все
активные виджеты/их порядок сохранились; настройки виджетов и Woo-страниц прежние.
59 проверок активации и 103 проверки интеграции прошли без подмены темы.
Проверены каталог/поиск, галерея, мобильное меню, корзина/checkout и кабинет.
Резервная копия настроек сохранена вне публичного каталога; процедура возврата —
[локальная активация](BOOTSTRAP_AND_RECOVERY.md#локальная-активация-lavka-storefront-2026-10-22).
Позже в тот же день, после отдельного разрешения и проверки резервных копий,
production переключён на Lavka Storefront 0.1.0. Свежая загрузка WordPress завершила
`after_switch_theme`; 10 read-only postchecks подтвердили собственное назначение
primary, неизменность виджетов, Woo-страниц и параметров изображений, отсутствие
задач регенерации. Браузерная приёмка обычных URL пройдена под администратором и
гостем: каталог, галерея, mobile menu, пустая корзина, вход и обычные страницы.
Старые темы сохранены. Полные backups БД и файлов проверены по checksum,
тестовое восстановление не выполнялось. Подробности и границы приёмки —
[production activation](BOOTSTRAP_AND_RECOVERY.md#production-activation-lavka-storefront-2026-10-22).
Обычный code deploy тему не переключает и пока не обновляет её файлы.

## Собственные WordPress-компоненты

Основные обычные плагины, включённые в deploy manifest:

- `paint-core`, `paint-shop-ux` — базовое поведение магазина;
- `lavka-total-sync`, `lavka-sync`, `lavka-price-sync`, `role-price` — карточки,
  остатки и цены;
- `lavka-reports` — административные отчёты;
- `pc-order-import-export` — импорт/экспорт заказов и drafts;
- `lavka-product-media-upload` — пакетный media workflow;
- `paint-nova-poshta-multishipping` — многоскладская доставка Новой почтой;
- `pc-checkbox-fiscalization` — универсальный исполнитель Checkbox.
- `pc-wayforpay-checkbox` — проверка callback WayForPay и очередь команд чека;
  сохраняет snapshot до Folio split, не выбирает накладные и не выполняет возвраты.
  С версии 0.2.0 также владеет независимой очередью банковских приходов: склад5,
  плательщик на момент Approved, без ожидания/распределения счетов. Java сохраняет
  native приход и idempotency ledger одной транзакцией. Этот новый поток default-off;
  production rollout отдельно, инструкция — [банковские приходы](FOLIO_WFP_PAYMENTS.md).
  [Подключение и приёмка](../wp-content/plugins/pc-wayforpay-checkbox/README.md).

MU-плагины загружаются автоматически. Критические группы: ecosystem lock/events,
Folio customer/order integration, checkout compliance, stock staging/sync,
role-gated quick order, `pc-wholesale-help` с контекстной справкой и диагностические
guards. У них нет обычной кнопки активации.

Полная карта и актуальные ограничения находятся в
`.agents/skills/lavka-woo/references/plugins.md`.

## Непереговорные границы

- MariaDB и ФОЛИО/MS SQL не имеют общей транзакции. Межсистемная надёжность строится
  на idempotency, порядке commit, статусе и проверяемой компенсации.
- HPOS-заказы меняются через Woo CRUD, а не прямой записью в `wp_posts`/`wp_postmeta`.
- PHP не повторяет расчёты и классификацию, уже выполненные Java/ФОЛИО.
- S3-объект не заменяет WordPress attachment: Woo хранит `attachment_id`.
- `s3_media_index` является поисковой проекцией, а не доказательством существования:
  выбранный object подтверждается exact key, size и ETag через S3 `HEAD`.
- Folio владеет желаемым basename/main/gallery order, WordPress — attachment,
  OVH S3 — bytes, а OVH Manager — bucket policy/contract. Эти роли не взаимозаменяемы.
- Все массовые процессы используют единый ecosystem lock.
- Страница статистики не запускает перерасчёт учётных цен.

Менеджерская навигация по учётным ценам (проверено 2026-10-07): свёрнутые
таблицы кампании и детали под общим обзором складов формирует
`lavka-price-sync/assets/accounting-price-campaign.js`; контекстные пояснения
принадлежат `accounting-price-help.js` и `inc/accounting-price-help.php`.
Пошаговый маршрут, отдельные XLSX состояния/постоянного журнала и трактовка
отрицательного остатка: [инструкция менеджера](FOLIO_ACCOUNTING_PRICES_MANAGER_UK.md#excel).
Справка не запускает расчёт; раскрытие таблицы и выгрузка не исправляют ФОЛІО.
- Сценарий аналитики хранит выбор складов, но не копирует состав глобальной
  группы. WordPress передаёт Java актуальные группы и SHA-256 revision; Java
  хранит каноническую подневную доступность физических складов и не закрепляет
  изменяемые группы внутри product snapshot.
- `*РАЗОВАЯ` входит в финансовый факт, но исключается из регулярного закупочного
  спроса; условия оплаты не изменяют класс движения.

## Зависимости запуска

Минимальная последовательность после обслуживания или восстановления:

1. точные OVH services сопоставлены проекту; DNS/TLS, compute/network, storage и базы
   доступны, а текущая DNS zone сохранена для rollback;
2. host/VM/MSSQL и ФОЛИО проверены без записи;
3. MariaDB сайта доступна и миграции ожидаемой версии применены;
4. Java стартует в read-only/safe режиме и проходит health-check;
5. WordPress и WooCommerce проходят frontend/admin health-check;
6. server-side proxy подтверждает связь с Java;
7. сначала выполняются точечные read-only проверки, затем последовательные
   snapshots/синхронизации;
8. платежи, фискализация, реальные ТТН и автоматические расписания включаются
   последними и отдельными решениями.

Остановка выполняется в обратном порядке: запретить новые внешние записи и cron,
дождаться/безопасно остановить batch, затем останавливать application и базы.

## Что является доказательством здоровья

Зелёная страница или HTTP 200 — только один сигнал. Сквозная проверка включает:

- WordPress frontend и admin без фатальных ошибок;
- доступность Woo страниц из options, а не по сохранённым ID;
- Java health и валидный JSON выбранного read-only endpoint;
- доступность нужной базы/склада и ожидаемую версию контракта;
- отсутствие активного конфликтующего ecosystem lock;
- свежий terminal status последней операции;
- бизнес-сверку по контрольному SKU/заказу/отчёту;
- наблюдаемое состояние backup и дату последнего test restore.

Неподтверждённые детали production topology и восстановления перечислены в
[KNOWN_GAPS.md](KNOWN_GAPS.md).

## Мастер-классы

`lavka-workshops` владеет публичным расписанием и статьями МК, строками дат в
post meta и закрытыми заявками. Это отдельный контур WordPress: заказов Woo,
платежей и записей ФОЛИО не создаёт. Роль `lavka_instructor` управляет этим контуром
без доступа к настройкам магазина. Проверено локально 2026-09-25; production
не развёрнут. [Инструкция мастера, установка и откат](WORKSHOPS_GUIDE_RU.md).

### Імпорт клієнтів із ФОЛІО (2026-10-06, локально)

`pc-order-import-export` (`FolioCustomerImport` / `FolioCustomerImportUi`) володіє
відбором організацій, приватним попереднім переглядом, створенням нових Woo-клієнтів
та необов’язковими запрошеннями. Java `FolioPartnerController` / `FolioPartnerDao`
лише читає контакти через захищений токеном `/admin/folio/partners/registration`;
ФОЛІО залишається джерелом облікової ідентичності. Операції запису виконуються
лише у WordPress, під загальним ecosystem lock, по одному клієнту за запит.
Тип організації не визначає цінову роль: менеджер вибирає погоджену роль, пов’язану
з договором у налаштуваннях сайту. Наявні профілі не перезаписуються.

Основна інструкція: [реєстрація клієнтів](MANAGER_CUSTOMER_GUIDE_UK.md#register-wholesale).
Конфігурація доступу, сім днів зберігання приватних звітів, відновлення та порядок
Java → WordPress deploy описані в [README плагіна](../wp-content/plugins/pc-order-import-export/README.md#manager-import-of-folio-customers-2026-10-06).
Java API-контракт: `kreul_com_ua/docs/api/FOLIO_PARTNERS_ENDPOINT_TASK.md`.
Production deploy та імпорт реальних клієнтів у цій задачі не виконувалися.

## XML sitemap категорий: границы страниц

Проверено 2026-10-08: production `sitemap_index.xml` Rank Math ссылался на
`product_cat-sitemap14.xml`–`product_cat-sitemap17.xml`, отвечавшие HTTP 404.
Локально воспроизведено расхождение: индекс учитывает иерархических родителей,
а страницы sitemap используют плоскую выборку категорий. После исправления
локально осталось 7 заполненных страниц вместо 10 (1 393 ссылки, размер 200).
Эти числа зависят от окружения и не являются настройками.

Владелец адаптера: `paint-shop-ux/inc/category-sitemap.php`, фильтр
`rank_math/sitemap/index/entry`. Он убирает только страницы `product_cat` за
границей нативной выборки Rank Math: учитывает include-empty, exclude-terms,
noindex и размер страницы. Важно сохранять `orderby=term_order`: иначе Woo
подключает сортировку по meta `order`, меняющую выборку при наличии meta-query.
Используются уникальные ID и кэш WP term-query, без HTTP-запросов и запросов
товаров по каждой категории. При ошибке чтения исходная запись сохраняется.
Сами категории, видимость меню, sitemap товаров и прочие taxonomy не меняются.
Отдельные исключения ссылок через canonical или сторонние sitemap-фильтры
остаются ответственностью Rank Math; адаптер не заменяет его генератор.

Приёмка: standalone `php wp-content/plugins/paint-shop-ux/tests/category-sitemap.php`
и локально `wp eval-file wp-content/plugins/paint-shop-ux/tests/category-sitemap-integration.php`.
Вторая проверка читает текущую базу и вызывает реальный провайдер Rank Math,
сверяет количество страниц, непустоту каждой и отсутствие следующей страницы.

Для production нужен разрешённый деплой существующего `paint-shop-ux`, без Java
и новых плагинов. После него очистить sitemap-кэш Rank Math (штатный
`RankMath\Sitemap\Cache::invalidate_storage()` в загруженном WordPress), а также
внешний page/CDN cache XML, если он используется. Открыть свежий индекс и
проверить HTTP 200 и непустой XML каждой ссылки категорий. Старые адреса сверх
границы могут по-прежнему отвечать 404, но не должны рекламироваться индексом.
Откат: вернуть прежний код плагина и снова очистить sitemap-кэш. Данные товаров
и категорий для этого не изменять. Production-деплой и очистка кэша в этой задаче
не выполнялись.

## XML sitemap товаров: обновление кэша

Проверено: 2026-10-16, production, WP-CLI и анонимные публичные GET. Две
опубликованные карточки (альбом Fabriano Accademia A4 и сердечко RR 6×3 см)
отсутствовали в сохранённых XML, хотя штатный провайдер Rank Math выдавал их URL,
`is_object_indexable()` возвращал true, а исключений и индивидуальных canonical
не было. Обе имели Woo catalog visibility `hidden`; это состояние сохранено,
поскольку включение в sitemap не разрешает менять видимость или условия продажи.

По разрешению владельца выполнен только штатный
`RankMath\Sitemap\Cache::invalidate_storage('product')`. Перед операцией проверены
целевые URL, окружение и отсутствие фонового ecosystem lock; на время операции
взят общий lock. В закрытом каталоге вне web-root сохранены 177 файлов товарного
кэша, mapping/transients и снимок двух карточек. Сверка после очистки подтвердила
неизменность записей, meta и product visibility. Исходный код, товары, цены,
остатки, SEO options и карта категорий не изменялись.

Публичная приёмка: индекс карты HTTP 200 ссылается на
`product-sitemap168.xml` и `product-sitemap126.xml`; обе карты HTTP 200 содержат
соответствующий товарный URL, обе карточки отвечают HTTP 200 и имеют шаблон товара.
Номера частей изменчивы: перед следующей операцией определять их по текущей
выборке провайдера, а не использовать как постоянные настройки. При сопоставлении
декодировать percent-encoding; регистр пути и завершающий слеш сохранять.

Регламент: сначала проверить публикацию, robots/canonical, явные исключения и
результат нативного провайдера; при устаревшем кэше сохранить snapshot и применить
только согласованный тип очистки. После неизвестного результата перечитать
состояние, не повторять запись вслепую. Кэш производный: при сбое повторной
генерации остановиться и сохранить диагностический snapshot; возврат к старому
XML не исправляет исходное рассогласование. Данные карточек не менять ради
обновления sitemap. Эта проверка не доказывает включение в индекс Google и не
является повторным полным аудитом всех частей sitemap.

## Служебные страницы: индексация production

Проверено и применено с разрешения владельца 2026-10-08. Это настройка Rank Math
в production БД, не изменение кода и не перенос локальной базы.

В `rank_math_robots` установлено `noindex` для опубликованных страниц
`sample-page`, `elementor-102078`, `evercompare`, `shvydke-zamovlennia`,
`checkout`, `checkout-classic`, `cart_lh_klassick`, `my-account`.
Перед применением подтверждено: Sample Page содержит шаблон WordPress, Elementor
пустой, остальные страницы содержат служебные shortcodes сравнения, списка заказа,
checkout, корзины и кабинета. Статусы публикации и содержимое не менялись.
Конкретные slugs относятся к проверенному production, не переносить числовые ID
в код или другое окружение.

Для taxonomy `location` в `rank-math-options-titles` включены
`tax_location_custom_robots=on` и `tax_location_robots=[noindex]`.
Индивидуальных robots overrides у двух существующих складов не было.
XML/HTML sitemap складов уже были отключены и оставлены отключёнными.
Настройки `product_cat`, товаров и страниц контактов не менялись.

После записи очищен sitemap-кэш штатным API Rank Math. Гостевые HTTP-проверки
подтвердили `noindex` на семи служебных страницах и двух `/location/` архивах;
`checkout/` при пустой корзине штатно перенаправляет в корзину с `noindex`,
его собственная настройка отдельно проверена в БД. Главная и контакты отдают
`index`. В `page-sitemap.xml` остались главная, условия, оплата/доставка, возвраты
и контакты. `robots.txt` не блокирует обход проверенных страниц, Google может
прочитать `noindex`. Удаление из выдачи произойдёт после повторного обхода;
фактическое состояние индекса Google в этой проверке не устанавливалось.

Резервная копия до записи: `~/lavka-seo-before-20261008-104756.json` на сервере,
вне web-root, права `0600`; чтение JSON сверено до применения. Для отката
восстановить только сохранённые `rank_math_robots` этих страниц (отсутствовавший
meta удалить) и два сохранённых ключа taxonomy в текущей option, не перезаписывая
чужие настройки. Затем очистить sitemap-кэш и повторить HTTP-проверку.
Настройки доступны в Rank Math у страницы и в Titles & Meta для складов;
будущие служебные страницы требуют отдельной проверки. `noindex` не заменяет
авторизацию и защиту клиентских документов.

## Операции менеджера ФОЛИО (2026-10-22)

Новый экран существующего `lavka-price-sync` передаёт шесть фиксированных операций в Java `/admin/folio/manager/*`. PHP владеет импортом, авторизацией пользователя, audit и экспортом; Java — Paint_Ua, SQL и транзакциями. Расчёт сборки использует временные таблицы отдельного соединения и не создаёт накладную. Статус внедрения и инструкции: [Операции менеджера](FOLIO_MANAGER_OPERATIONS.md).

## Контроль исполнения заказов (2026-10-26, локальный пилот)

`pc-order-import-export/inc/OrderExecution.php` владеет отдельной внутренней
карточкой исполнения группы связанных Woo-заказов: ответственный, сроки,
следующее действие и журнал наблюдений. С 28.10.2026 `OrderExecutionRoadmap` и
`OrderExecutionRoadmapUi` задают 19 именованных этапов с обязательностью,
сценарием и ручным результатом. Вложенная `roadmap.version=1` хранит стабильные ID,
состояние этапов и текущую задачу; прежний свободный текст сохраняется примечанием.
Переход вперёд не завершает прежние этапы, закрытие проверяет обязательные этапы
и открытые дополнительные ветки. С 29.10.2026 `OrderExecutionOperations` и
`OrderExecutionOperationsUi` отдельно записывают фактическую операцию: ранее
сохранённый план, другой этап или произвольное действие. `operation_recorded`
сохраняет результат, фактическое время и снимок плана в неизменяемой истории;
планирование само не означает выполнения. Завершение текущего плана очищает
следующую задачу и её срок для явного назначения; другая операция сохраняет их.
Это запись проверки менеджером, не исполнение бизнес-команды и не доказательство,
полученное из ФОЛИО/НП. `_pcoe_execution_v1` хранится через Woo
CRUD у исходного заказа; lock и revision защищают совместное редактирование.
ФОЛИО остаётся источником учётных документов, модуль НП — владельцем отправлений.
Этот слой читает один известный документ ФОЛИО за запуск Action Scheduler и
сохранённые данные отправлений; он не создаёт документы, оплаты, ТТН, сообщения,
бонусы и не завершает Woo-заказы. Контроль включается явно для отдельных заказов.
Инструкция, пределы пилота и остановка:
[Контроль виконання замовлення](MANAGER_CUSTOMER_GUIDE_UK.md#execution-control).


Order metadata capacity (2026-10-27): HPOS `wc_orders_meta.meta_value` must be
LONGTEXT for large Folio responses and the complete execution audit. The operator-only
`scripts/expand-order-meta.php` previews by default; explicit apply validates a private
backup and widens the column without changing the collation or existing values.
No automatic schema change is run on requests. Recheck after Woo schema upgrades;
never narrow during code rollback. ManagerWorkspace verifies persisted command/result
checkpoints before sending and after receiving Folio requests. OrderExecution separately
reports persistence failure and normalizes creation types (`account`,
`non_accounting_account`, `missing_stock_account`) to the read API's `ACCOUNT`, without
changing accounting flags. Operator recovery and limits are in the manager guide above.

Manager one-action preparation (2026-10-28): new `ManagerQuickOrder` jobs use
`flow=direct_children`; the source draft owns the payload, saved Folio response
and per-document child map. No intermediate preparation order is created.
Every actual response document becomes a direct Woo child: reserved goods use
processing, waiting transfers use on-hold, and shortages use pc-draft. Even a
single document gets its own child. Source items, title, status and historical
Folio links remain unchanged; new result data stays in the quick-job journal.
The execution family links source and children directly. Preflight failure
creates no new Woo orders. A saved write-attempt marker blocks automatic replay,
including after a partial Folio or local materialization result.
Action Scheduler optionally sends one `CustomerApproval` notice per reserved
child. The checkbox defaults on; unchecked sends no customer preparation email.
Native processing/on-hold preparation emails are suppressed. Legacy jobs without
this flow marker retain their work-copy path and saved links; no automatic
migration or deletion of old preparation copies is performed. Existing PRD
sources require reconciliation before conversion. The manager form explains this
requirement before offering creation; recovery of a stale save command preserves
the preorder purpose and cannot silently remove its balance reserve. Auxiliary availability uses
Java's authenticated `/admin/folio/manager-order-accounts/v2` route. The single
manager form preserves automatic/priority/single allocation; the first destination
is the pool target. Ready quantities and `waiting_transfer_account` use separate
Folio documents and Woo orders, even on the same warehouse. Waiting uses on-hold
and an explicit customer notice; no automatic transfer or readiness transition.
Only target REZ_KOLCH decreases; physical stock and auxiliary REZ are unchanged.
The old manager pool route is closed to prevent old PHP misclassifying waiting
orders; deploy Java then PHP, and retain persisted journals during rollback. The mode
is not a cross-warehouse exclusivity guarantee against independent Folio writes.
See [manager workflow](MANAGER_CUSTOMER_GUIDE_UK.md#чернетка--замовлення-однією-дією-27102026)
and Java `docs/api/FOLIO_ACCOUNT_JS_API.md` for operation/rollback details.

Manager preflight recovery/authentication (2026-10-27): pooled orders use
`lps_java_post` and Lavka Price Sync credentials, matching the warehouse directory;
the legacy checkout transport uses independent Total Sync settings. Production
read-only comparison confirmed 401 via the old transport and successful preview
via Lavka, including the separate waiting account. No accounting write was tested.
An explicit manager action can archive a failed preflight and restore settings
only before any write attempt or child creation. New jobs inspect the source
journal; legacy jobs additionally inspect their same-customer work copy for
create journals, Folio links and processing markers, including when it is in trash.
Source/work and legacy Folio locks guard recovery. Recovery never restores a
trashed copy; it unlocks the source for a new direct-children run.
No API call, worker enqueue or email occurs. Unknown writes stay blocked. See the
manager guide one-action section for recovery and deployment steps. This PHP fix
does not require changing Java or overwriting either saved API token.

Manager section disclosures (2026-10-27): PCOE `manager.js` / `manager.css`
add accessible collapse/expand controls to titled workspace cards, including
nested Folio headings and subscription cards. CSS hides late AJAX siblings too,
without moving existing forms or replacing field nodes.
Session storage holds only per-page section visibility, scoped to the current
manager. Storage failure keeps controls usable; no-JS leaves content visible.
Anchor navigation and invalid form controls reopen their containing cards.
This presentation state never changes draft purpose, stock, documents or jobs.
See the [manager guide](MANAGER_CUSTOMER_GUIDE_UK.md#згортання-розділів-картки-клієнта-та-замовлення).
