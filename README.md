# 🛒 Paint Shop (WooCommerce)

E-commerce проект на базе **WordPress + WooCommerce**, кастомизированный под задачи магазина красок.

## 📂 Структура проекта
<details>
<summary><strong>Структура проекта</strong></summary>

```text
wp-content/
├─ mu-plugins/
│  ├─ psu-force-per-page.php        # per_page = колонки × ряды (cookie psu_cols/psu_rows)
│  ├─ stock-import-csv-lite.php     # лёгкий CSV-импорт (склады/остатки — lite)
│  └─ stock-locations-ui.php        # UI-патчи отображения остатков по складам
│
├─ plugins/
│  ├─ paint-core/
│  │  ├─ assets/
│  │  │  └─ css/
│  │  │     └─ catalog-qty.css      # стили qty/кнопок в каталоге
│  │  ├─ inc/
│  │  │  ├─ catalog-qty-add-to-cart.php   # qty + «в корзину» в один ряд, состояния и лимиты
│  │  │  ├─ header-allocation-switcher.php# селекторы «Списание/Склад» в шапке + AJAX
│  │  │  ├─ order-allocator.php           # расчёт плана списания по складам (slu_allocation_plan)
│  │  │  ├─ order-attach-csv.php          # вспом. CSV для заказов
│  │  │  ├─ role-price-importer.php       # импорт цен по ролям (страница в админке)
│  │  │  ├─ sku-gtin-admin-columns.php    # колонки SKU/GTIN в админке
│  │  │  ├─ sku-gtin-front-emails.php     # вывод SKU/GTIN на фронте/в письмах
│  │  │  ├─ stock-import-table.php        # таблица импорта остатков
│  │  │  ├─ stock-locations-display.php   # виджеты/шаблоны остатков
│  │  │  ├─ config.php                    # базовые константы/переключатели
│  │  │  └─ paint-core.php                # загрузчик инклудов
│  │  └─ paint-core.php                   # главный файл плагина
│  │
│  ├─ paint-shop-ux/
│  │  └─ paint-shop-ux.php         # мелкие UX-правки магазина
│  │
│  ├─ role-price/
│  │  └─ role-price.php            # цены по ролям: мета-ключи _wpc_price_role_*
│  │
│  └─ stock-sync-to-woo/
│     └─ stock-sync-to-woo.php     # синк остатков в Woo (интеграция)
│
├─ themes/
│  └─ generatepress-child/
│     └─ style.css                 # сетка каталога (CSS Grid), мелкие стили
│
└─ uploads/                        # медиа (в Git не храним)
```
</details>



## 🚀 Как развернуть проект
<details>
    <summary><strong> install </strong></summary>
1. Установить WordPress и WooCommerce (через WP-CLI):
   ```bash
   wp core download --locale=ru_RU
   wp core config --dbname=paint --dbuser=root --dbpass=root --dbhost=localhost
   wp core install --url=http://localhost --title="Paint Shop" --admin_user=admin --admin_password=admin --admin_email=admin@example.com
   wp plugin install woocommerce --activate
	2.	Подтянуть кастомные файлы:
   git clone git@github.com:VMalakhatka/paint-shop.git .
   	3.	Активировать тему:
    wp theme activate my-theme
    	4.	Активировать кастомные плагины:

        wp plugin activate my-custom-plugin

</details>

## 🎯 Карта модулей (что за что отвечает)
<details>
### 🧩 MU Plugins
| Файл / Модуль | Назначение | Ключевые настройки / хуки | Где искать в админке |
|---------------|------------|---------------------------|----------------------|
| **mu-plugins/psu-force-per-page.php** | Выдаёт на витринах товаров `per_page = колонки × ряды`. Колонки меряются на клиенте, пишутся в cookie. | Константы: `PSUFP_ROWS`, `PSUFP_FALLBACK_COLS`, `PSUFP_COOKIE_COLS`, `PSUFP_COOKIE_ROWS`, `PSUFP_DEBUG`, `PSUFP_ROWS_MOBILE`, `PSUFP_ROWS_MOBILE_BP` | — (кодовый MU-модуль, без UI) |
| **mu-plugins/stock-import-csv-lite.php** | Лёгкий импорт CSV (остатки по складам). | Чтение CSV, временные таблицы. | Woo → Инструменты импорта |
| **mu-plugins/stock-locations-ui.php** | UI-патчи для отображения остатков по складам (в каталоге и PDP). | Хуки WooCommerce + шаблоны. | В карточках товара |

---

### 🛠 Paint Core (кастомный плагин)
| Файл / Модуль | Назначение | Ключевые настройки / хуки | Где искать в админке |
|---------------|------------|---------------------------|----------------------|
| **paint-core/assets/css/catalog-qty.css** | Стили qty/кнопок «в корзину» в каталоге. | CSS классы: `.loop-qty`, `.loop-buy-row`. | Внешний вид → Редактор файлов темы |
| **paint-core/inc/catalog-qty-add-to-cart.php** | qty + кнопка «в корзину» в один ряд, лимиты и disabled-состояния. | Хуки: `woocommerce_after_shop_loop_item`. | Каталог Woo |
| **paint-core/inc/header-allocation-switcher.php** | Блок «Списание: [режим] [склад]». Сохраняет выбор в сессию + cookie. Режимы: `auto`, `manual`, `single`. | Ajax `pc_set_alloc_pref`; cookie `pc_alloc_pref`. | UI в шапке |
| **paint-core/inc/order-allocator.php** | Расчёт плана списания по складам (`slu_allocation_plan`). | Фильтр `slu_allocation_plan`. | — |
| **paint-core/inc/order-attach-csv.php** | Вспомогательные CSV-инструменты для заказов. | Парсер CSV. | Woo → Заказы |
| **paint-core/inc/role-price-importer.php** | Импорт цен по ролям (страница в админке). | Мета-ключи: `_wpc_price_role_*`. | Woo → Инструменты импорта |
| **paint-core/inc/sku-gtin-admin-columns.php** | Добавляет SKU/GTIN в таблице товаров в админке. | Фильтр `manage_edit-product_columns`. | Woo → Товары |
| **paint-core/inc/sku-gtin-front-emails.php** | Вывод SKU/GTIN на фронте и в email-уведомлениях. | Хуки Woo писем. | Woo → Email-шаблоны |
| **paint-core/inc/stock-import-table.php** | Таблица импорта остатков. | Создание временных таблиц. | Woo → Инструменты импорта |
| **paint-core/inc/stock-locations-display.php** | Виджеты/шаблоны отображения остатков по складам. | Вставка блоков остатков. | PDP / каталог |
| **paint-core/inc/config.php** | Базовые константы и переключатели. | — | — |
| **paint-core/inc/paint-core.php** | Загрузчик инклудов. | `require_once`. | — |
| **paint-core/paint-core.php** | Главный файл плагина Paint Core. | Регистрация плагина. | Woo → Плагины |

---

### 🎨 UX & Доп. плагины
| Файл / Модуль | Назначение | Ключевые настройки / хуки | Где искать в админке |
|---------------|------------|---------------------------|----------------------|
| **paint-shop-ux/paint-shop-ux.php** | Мелкие UX-правки магазина. | — | — |
| **role-price/role-price.php** | Цены по ролям: выбор мета-ключа `_wpc_price_role_*`. | Woo фильтр `woocommerce_product_get_price`. | Woo → Цены по ролям |
| **stock-sync-to-woo/stock-sync-to-woo.php** | Синхронизация остатков в Woo (интеграция с внешними системами). | Крон-хуки / API. | Woo → Инструменты синхронизации |

---

### 🎭 Тема (GeneratePress Child)
| Файл / Модуль | Назначение | Ключевые настройки / хуки | Где искать в админке |
|---------------|------------|---------------------------|----------------------|
| **themes/generatepress-child/style.css** | Сетка каталога (CSS Grid), визуал карточек/кнопок/qty; стили селекторов «Списание/Склад» в шапке. | `grid-template-columns: repeat(auto-fit, minmax(...))` — меняет кол-во колонок. | Внешний вид → Редактор файлов темы |
| **themes/generatepress-child/functions.php** | Подключение стилей, хлебные крошки. ⚠️ Логика `per_page` вынесена в MU. | — | — |
| **themes/generatepress-child/inc/header-allocation-switcher.php** | Дублирующий код селектора склада (UI в теме). | Cookie `pc_alloc_pref`. | Шапка темы |

---

### 🗄 SQL / Импорт
| Файл / Модуль | Назначение | Ключевые настройки / хуки | Где искать в админке |
|---------------|------------|---------------------------|----------------------|
| **(SQL) «Импорт цен по ролям»** | Массовая запись `_wpc_price_role_*` по SKU. | Метаключи: `_wpc_price_role_partner`, `_wpc_price_role_opt`, `_wpc_price_role_opt_osn`, `_wpc_price_role_schule`. | Woo → Инструменты импорта + запуск SQL |
</details>

<details>
    <summary><strong> Как устроено хранение price_role </strong></summary>

      •	Мета-ключ для каждой роли формируется так:_wpc_price_role_<role>	•	Примеры:
      •	_wpc_price_role_partner
      •	_wpc_price_role_opt
      •	_wpc_price_role_opt_osn
      •	_wpc_price_role_schule(суффикс берётся прямо из $user->roles[0], то есть первый элемент массива ролей пользователя).Проверка в базеSELECT post_id, meta_key, meta_value
    FROM wp_postmeta
    WHERE meta_key LIKE '_wpc_price_role_%'
    LIMIT 20;CSV для импортаsku;partner;opt;opt_osn;schule
    CR-001;10.50;11.00;9.90;10.00📌 Итого:
      •	Хранилище: _wpc_price_role_<роль> (каждая роль свой мета-ключ).
      •	Импорт: через CSV + SQL как выше.
      •	Отображение: фильтр woocommerce_product_get_price подтягивает эти цены.


</details>

## SQL - внесения цен - проверить 
<details>
<summary><strong>SQL </strong></summary>

    /* ===========================
      Переносит цены с временной таблицы на сайт 
      4.	Зайди: Инструменты → Импорт цен (CSV).
      5.	Выбери CSV (UTF‑8, разделитель ;, заголовки: sku;partner;opt;opt_osn;schule), при необходимости поставь «очистить таблицу».
      6.	Жми «Импортировать».
    Данные попадут в таблицу wp_role_prices_import (с твоим префиксом БД).

    После этого запускаем этот sql

    Как устроено хранение
      •	Мета-ключ для каждой роли формируется так:  _wpc_price_role_<role>  	•	Примеры:
      •	_wpc_price_role_partner
      •	_wpc_price_role_opt
      •	_wpc_price_role_opt_osn
      •	_wpc_price_role_schule  (суффикс берётся прямо из $user->roles[0], то есть первый элемент массива ролей пользователя).
      MASTER (fixed collations + InnoDB temp)
      =========================== */

    START TRANSACTION;

    /* Бэкап текущих цен по ролям */
    SET @backup := CONCAT('wp_postmeta_backup_role_price_', DATE_FORMAT(NOW(), '%Y%m%d%H%i%s'));
    SET @sql := CONCAT(
      'CREATE TABLE ', @backup, ' AS ',
      'SELECT * FROM wp_postmeta ',
      'WHERE meta_key IN (',
      '''_wpc_price_role_partner'',''_wpc_price_role_opt'',''_wpc_price_role_opt_osn'',''_wpc_price_role_schule''',
      ')'
    );
    PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

    /* Временная таблица SKU → post_id (InnoDB) */
    DROP TEMPORARY TABLE IF EXISTS tmp_sku_map;
    CREATE TEMPORARY TABLE tmp_sku_map
    ENGINE=InnoDB AS
    SELECT
      s.post_id,
      /* приводим значение к нужной коллации для дальнейших JOIN-ов */
      CONVERT(s.meta_value USING utf8mb4) COLLATE utf8mb4_unicode_520_ci AS sku
    FROM wp_postmeta s
    JOIN (
      /* так же приводим SKU из импорта к той же коллации */
      SELECT CONVERT(i.sku USING utf8mb4) COLLATE utf8mb4_unicode_520_ci AS sku
      FROM wp_role_prices_import i
      GROUP BY sku
    ) i ON i.sku = CONVERT(s.meta_value USING utf8mb4) COLLATE utf8mb4_unicode_520_ci
    WHERE s.meta_key = '_sku';

    CREATE INDEX ix_tmp_sku_map_sku ON tmp_sku_map(sku);

    /* Временная таблица с импорт‑данными (InnoDB) */
    DROP TEMPORARY TABLE IF EXISTS tmp_import_cast;
    CREATE TEMPORARY TABLE tmp_import_cast
    ENGINE=InnoDB AS
    SELECT
      CONVERT(i.sku USING utf8mb4) COLLATE utf8mb4_unicode_520_ci AS sku,
      CAST(i.partner  AS CHAR) AS partner,
      CAST(i.opt      AS CHAR) AS opt,
      CAST(i.opt_osn  AS CHAR) AS opt_osn,
      CAST(i.schule   AS CHAR) AS schule
    FROM wp_role_prices_import i;

    CREATE INDEX ix_tmp_import_cast_sku ON tmp_import_cast(sku);

    /* ====== ПАРТНЕР ====== */
    UPDATE wp_postmeta m
    JOIN tmp_sku_map sm
      ON sm.post_id = m.post_id
    JOIN tmp_import_cast i
      ON i.sku = sm.sku
    SET m.meta_value = i.partner
    WHERE m.meta_key = '_wpc_price_role_partner';

    INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
    SELECT sm.post_id, '_wpc_price_role_partner', i.partner
    FROM tmp_sku_map sm
    JOIN tmp_import_cast i ON i.sku = sm.sku
    LEFT JOIN wp_postmeta m
      ON m.post_id = sm.post_id AND m.meta_key = '_wpc_price_role_partner'
    WHERE m.post_id IS NULL AND i.partner IS NOT NULL;

    /* ====== ОПТ ====== */
    UPDATE wp_postmeta m
    JOIN tmp_sku_map sm ON sm.post_id = m.post_id
    JOIN tmp_import_cast i ON i.sku = sm.sku
    SET m.meta_value = i.opt
    WHERE m.meta_key = '_wpc_price_role_opt';

    INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
    SELECT sm.post_id, '_wpc_price_role_opt', i.opt
    FROM tmp_sku_map sm
    JOIN tmp_import_cast i ON i.sku = sm.sku
    LEFT JOIN wp_postmeta m
      ON m.post_id = sm.post_id AND m.meta_key = '_wpc_price_role_opt'
    WHERE m.post_id IS NULL AND i.opt IS NOT NULL;

    /* ====== ОПТ_ОСН ====== */
    UPDATE wp_postmeta m
    JOIN tmp_sku_map sm ON sm.post_id = m.post_id
    JOIN tmp_import_cast i ON i.sku = sm.sku
    SET m.meta_value = i.opt_osn
    WHERE m.meta_key = '_wpc_price_role_opt_osn';

    INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
    SELECT sm.post_id, '_wpc_price_role_opt_osn', i.opt_osn
    FROM tmp_sku_map sm
    JOIN tmp_import_cast i ON i.sku = sm.sku
    LEFT JOIN wp_postmeta m
      ON m.post_id = sm.post_id AND m.meta_key = '_wpc_price_role_opt_osn'
    WHERE m.post_id IS NULL AND i.opt_osn IS NOT NULL;

    /* ====== SCHULE ====== */
    UPDATE wp_postmeta m
    JOIN tmp_sku_map sm ON sm.post_id = m.post_id
    JOIN tmp_import_cast i ON i.sku = sm.sku
    SET m.meta_value = i.schule
    WHERE m.meta_key = '_wpc_price_role_schule';

    INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
    SELECT sm.post_id, '_wpc_price_role_schule', i.schule
    FROM tmp_sku_map sm
    JOIN tmp_import_cast i ON i.sku = sm.sku
    LEFT JOIN wp_postmeta m
      ON m.post_id = sm.post_id AND m.meta_key = '_wpc_price_role_schule'
    WHERE m.post_id IS NULL AND i.schule IS NOT NULL;

    /* Немного статистики */
    SELECT 'mapped_sku' AS metrika, COUNT(*) AS cnt FROM tmp_sku_map
    UNION ALL
    SELECT 'price_partner_rows', COUNT(*) FROM wp_postmeta WHERE meta_key = '_wpc_price_role_partner'
    UNION ALL
    SELECT 'price_opt_rows',     COUNT(*) FROM wp_postmeta WHERE meta_key = '_wpc_price_role_opt'
    UNION ALL
    SELECT 'price_opt_osn_rows', COUNT(*) FROM wp_postmeta WHERE meta_key = '_wpc_price_role_opt_osn'
    UNION ALL
    SELECT 'price_schule_rows',  COUNT(*) FROM wp_postmeta WHERE meta_key = '_wpc_price_role_schule';

    COMMIT;
</details>

⚙️ Кастомные модули и настройки
<details>
<summary><strong>1) mu-plugins/psu-force-per-page.php — авто-пересчёт per_page</strong></summary>

**Идея.** Количество товаров на странице = **колонки × ряды**.  
Колонки меряются на клиенте (по CSS Grid), записываются в cookie → сервер ставит `posts_per_page`.

### Константы (ручки)
| Константа | Что делает | Дефолт |
|---|---|---|
| `PSUFP_COOKIE_COLS` | имя cookie с количеством колонок | `psu_cols` |
| `PSUFP_COOKIE_ROWS` | имя cookie с количеством рядов | `psu_rows` |
| `PSUFP_ROWS_DESKTOP` | ряды для >480px | `3` |
| `PSUFP_ROWS_MOBILE` | ряды для 321–480px | `3` |
| `PSUFP_ROWS_XSMALL` | ряды для ≤320px | `2` |
| `PSUFP_FALLBACK_COLS` | кол-во колонок пока cookie нет | `5` |
| `PSUFP_DEBUG` | отладка (зелёная плашка + console.log) | `false` |

### Cookie
- `psu_cols` — количество колонок, измеренное JS.
- `psu_rows` — количество рядов, вычисленное по брейкпоинтам.

### Где перехватываем `per_page`
- `loop_shop_per_page` (WooCommerce)
- `pre_get_posts` (только main query, архивы товаров)
- `woocommerce_product_query` (только в контексте архивов товаров)

### Важные особенности
- **Явный оверрайд через URL:** добавить `?per_page=N` (1…200).  
  Модуль уважит и вернёт это значение вместо расчёта.

- **Хук для тонкой настройки рядов:** можно переопределить выбор рядов для серверной стороны:
  ```php
  /**
   * @param int $rows   рассчитанные ряды по текущей ширине
   * @param int $width  ширина (если передаётся)
   * @return int
   */
  add_filter('psufp_rows_for_width', function($rows, $width){
      // пример: принудительно 2 ряда на любых мобилках
      if ($width <= 480) return 2;
      return $rows;
  }, 10, 2);

  
Примечание: сейчас вычисление рядов делается в JS; этот фильтр — задел для PHP-сценариев и расширений.

	•	Кто решает количество колонок? Только CSS в теме:
grid-template-columns: repeat(auto-fit, minmax(..., 1fr));
JS лишь «считывает» результат и кладёт число в cookie.

Диагностика
	1.	Включи define('PSUFP_DEBUG', true); — внизу появится блок вида:
cols=5, rows=3, per_page=15, w=1280.
	2.	Проверь cookie psu_cols, psu_rows.
	3.	Убедись, что в DevTools у .woocommerce ul.products реально стоит наш grid-template-columns.
	4.	Если «не добивает» последний ряд — обычно либо колонок посчиталось меньше, чем ожидалось (CSS), либо рядов выбрано больше (константы).
</details>

2) Сетка каталога (CSS)

Файл: wp-content/themes/generatepress-child/style.css
Задача: визуальная сетка карточек Woo.
Критичные места:

.woocommerce ul.products{
  display:grid !important;
  gap:20px;
  grid-auto-flow:row;
  grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); /* DESKTOP min */
}
@media (max-width:1024px){
  .woocommerce ul.products{
    grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); /* TABLET min */
  }
}
@media (max-width:768px){
  .woocommerce ul.products{
    grid-template-columns:repeat(auto-fit, minmax(100px, 1fr)); /* MOBILE min */
  }
}

/* Сброс ширин от темы: обязательно! */
.woocommerce ul.products li.product{
  float:none !important;
  width:auto !important;
  margin:0 !important;
  clear:none !important;
}
.woocommerce ul.products[class*="columns-"] li.product{
  width:auto !important;
  clear:none !important;
  margin-right:0 !important;
}


Ручки: числа minmax(…px, 1fr) — чем меньше минимальная ширина, тем больше колонок поместится.

⸻

3) Каталожная “qty + в корзину” (контроль доступного количества)

Файл: wp-content/plugins/paint-core/inc/scatalog-qty-add-to-cart.php (у тебя может называться чуть иначе, но это UI-плагин каталога)
Задача:
	•	показывает плюс/минус и поле qty в листинге;
	•	ограничивает ввод по доступному количеству;
	•	валидирует на сервере при добавлении/обновлении корзины.

Ключевые функции/фильтры:
	•	pcux_available_qty($product) — общий остаток (сумма _stock_at_% либо _stock).
	•	pcux_available_for_add($product) — доступно к добавлению = общий остаток − уже в корзине.
	•	woocommerce_add_to_cart_validation и woocommerce_update_cart_validation — серверная проверка.
	•	Если установлен MU-хелпер, используются \slu_total_available_qty() и \slu_available_for_add().

Где править поведение: внутри функций pcux_available_qty/pcux_available_for_add или прокинуть свою MU-функцию slu_*.

⸻

4) UI складов (PDP/каталог/корзина) + план списания в корзине

Файл-плагин: wp-content/plugins/stock-locations-ui/stock-locations-ui.php (по твоему коду)
Задача:
	•	показывает “Заказ со склада … / Другие склады … / Всего: N”;
	•	режимы: auto / manual / single;
	•	не показывает нулевые склады;
	•	выводит строку “Списание” в корзине/чекауте по плану.

Ключевое:
	•	pc_build_stock_view($product) — собирает локации и фильтрует нули.
	•	slu_render_stock_panel($product, $opts) — рендер панели; в режиме single скрывает блок, если склад = 0.
	•	slu_render_allocation_line() — “Київ — 2, Одеса — 1” по плану.
	•	add_filter('woocommerce_get_item_data', 'slu_cart_allocation_row', 30, 2) — добавляет “Списание” в карточку корзины.

Где править:
	•	чтобы точно скрывать нулевые — см. фильтрацию в pc_build_stock_view();
	•	чтобы поменять текст/порядок — в slu_render_stock_panel().

⸻

5) Распределение и реальное списание остатков

Файл: wp-content/plugins/paint-core/inc/order-allocator.php
Задача:
	•	На этапе оформления/статуса строит план списания по складам для каждой строки заказа
→ мета _pc_stock_breakdown = [ term_id => qty, ... ], видимая мета “Склад: Київ × N, Одеса × M”, _stock_location_id/_slug.
	•	Потом списывает реальные остатки из мет _stock_at_{term_id}, пересчитывает _stock/_stock_status.
	•	Антидубль: флажок _pc_stock_reduced = yes.

Хуки:
	•	План: woocommerce_new_order (раньше), woocommerce_checkout_order_processed (40), woocommerce_order_status_processing/completed (30).
	•	Редукция: те же статусы, но (60) — после построения плана.

Переопределение алгоритма:
	•	фильтр slu_allocation_plan — можешь вернуть свой [term_id => qty], чтобы изменить стратегию.

Где править приоритеты:
	•	в pc_build_allocation_plan() порядок — сначала primary, потом по имени (или меняй на “по убыванию остатков”).

⸻

6) Базовый конфиг ядра

Файл: wp-content/plugins/paint-core/inc/Config.php (или рядом)
Задача/настройки:
	•	Config::DEBUG — включает pc_log() (лог в error_log).
	•	Config::DISABLE_LEGACY_CART_LOCATIONS — отключает “старые” строки складов в корзине из старого Paint Core.
	•	Config::ENABLE_STOCK_ALLOCATION — включает новый алгоритм распределения.
	•	Хелпер pc_log($msg) (в неймспейсе PaintCore) — не забывай use function PaintCore\pc_log; в файлах, где зовёшь.

⸻

7) Переключатель режима складов (если есть)

Файл: inc/header-allocation-switcher.php (или похожее место в теме/плагине)
Задача: UI для auto/manual/single + выбранный term_id.
Важно: после смены режима перерисовывать PDP/каталог. Если не обновляется — проверь, что:
	•	состояние хранится (cookie/option/session?),
	•	расчёт в pc_build_stock_view() читает именно это состояние,
	•	есть хук/JS, который дёргает перерисовку (или обычный reload).

⸻

8) Тема: functions.php (дочерняя)

Файл: wp-content/themes/generatepress-child/functions.php
Что тут теперь стоит оставлять:
	•	Подключение стилей дочерней темы;
	•	Мелкие правки (хлебные крошки, заголовки, и т.п.).
Не держим тут: логику per-page (вынесено в MU-плагин), сетку можно держать в style.css.

⸻

Частые задачи и где править
	•	“Не добивается последний ряд”: см. MU-плагин (ряды/колонки), CSS minmax, cookie psu_cols/psu_rows.
	•	“Показывает нулевой склад”: stock-locations-ui.php → pc_build_stock_view() — фильтруем нули.
	•	“В корзине/чекауте план пересчитался не так”: см. order-allocator.php (план/редукция), фильтр slu_allocation_plan.
	•	“Хочу первично списывать из Одессы”: либо сделай её primary в метах Yoast, либо перепиши порядок в slu_allocation_plan.
	•	“Каталог даёт добавить больше, чем на выбранном складе”: сейчас лимит идёт от общего остатка. Если надо “по выбранному складу” в режиме single, скорректируй pcux_available_qty()/pcux_available_for_add() (или дай MU-функции slu_* учитывать текущий режим).

⸻

Быстрый чек-лист (диагностика)
	1.	Внизу страницы (если PSUFP_DEBUG = true) — строка вида:
cols=5, rows=3, per_page=15 (помогает понять, что именно считает клиент/сервер).
	2.	В консоли браузера — сообщения [PSUFP] … (колонки/ряды/перезагрузка).
	3.	В PHP-логах — [PSU] … о перехвате posts_per_page.
	4.	Проверить cookie psu_cols/psu_rows.
	5.	Открыть devtools → Elements: убедиться, что .woocommerce ul.products имеет наш grid-template-columns и что .columns-* не ломают.

⸻

Если хочешь, упакую всё это в один “живой” README.md (с кодовыми сниппетами), либо добавлю в админке страницу “Настройки каталога”, где можно менять PSUFP_ROWS_* и minmax без лезания в файлы.