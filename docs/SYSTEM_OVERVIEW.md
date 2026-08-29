# Архитектура Lavka / KREUL

Документ объясняет границы компонентов и потоков данных. Он не содержит секретов,
переменных окружения со значениями или переносимых между окружениями ID.

Практическая карта исходного кода, API, окружений, разработки и запуска находится в
[BACKEND_GUIDE.md](BACKEND_GUIDE.md).

Проверено по коду WordPress, проектным skills и Java-документации: 2026-08-29.

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

| Область | Владелец | Источник истины |
|---|---|---|
| Каталог, корзина, checkout, кабинет, HPOS-заказы | WooCommerce + собственные PHP-плагины | текущий Woo runtime и PHP-код |
| Компоновка и стили | `generatepress-child` | код child theme и визуальная проверка |
| Общие остатки и allocation | `paint-core` | код, Woo product/order state |
| Полная синхронизация карточек и категорий | `lavka-total-sync` + Java `/sync/run` | код обоих владельцев и run status |
| Остатки и mapping складов | `lavka-sync` | Java/staging, location mapping и Woo stock |
| Цены по ролям | `lavka-price-sync` + `role-price` | mapping роли к договору и product meta |
| Учётные цены и snapshot | Java + `lavka-price-sync` | Java job status и MariaDB generation |
| Статистика товара | Java projection + `lavka-price-sync` | активное поколение `folio_product_*` |
| Отчёты | `lavka-reports` и профильные MU-плагины | Java response + параметры и контрольные суммы |
| Заказ Woo → документы ФОЛИО | `pc-folio-order-link` + Java | стабильный request ID и `documents[]` |
| Изображения Woo | `$image-in-woo`: Media Library + Media Cloud + Java media API | attachment ID, exact S3 HEAD proof и ФОЛИО reference |
| OVH contract/DNS/compute/S3 policy | `$manage-ovh-infrastructure` | текущий OVH Manager/API и фактический resource state |
| Java image/container/env/health | `$build-java-docker-runtime` | текущий Docker/deploy code и runtime state |
| ФОЛИО-документы и бизнес-данные | ФОЛИО/MS SQL | живая схема и подтверждённое поведение |
| Сервер и MSSQL runtime | host/Windows/MSSQL operations | фактическое read-only состояние слоёв |

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

MU-плагины загружаются автоматически. Критические группы: ecosystem lock/events,
Folio customer/order integration, checkout compliance, stock staging/sync, quick
order и диагностические guards. У них нет обычной кнопки активации.

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
