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
│  └─ stock-sync-to-woo.php         # синк буфера → Woo: _stock_at_{TERM_ID}, _stock, такс. location
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
│     └─ role-price.php            # цены по ролям: мета-ключи _wpc_price_role_*
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

## 🎭 Тема (GeneratePress Child)

<details>
<summary><strong>Общее</strong></summary>

**Идея.** Тема остаётся максимально «тонкой»: сетка/стили/косметика. Бизнес-логика — в плагинах.

**Важно:**
- Количество **колонок** определяет **только CSS Grid**.
- Число товаров на страницу (`per_page`) настраивает MU-плагин, а не тема.

</details>

<details>
<summary><strong>Файлы темы</strong></summary>

| Путь | Назначение |
|---|---|
| `wp-content/themes/generatepress-child/style.css` | CSS-сетка каталога (Grid), стили qty/кнопок, мини-стили шапки («Списание/Склад»). |
| `wp-content/themes/generatepress-child/functions.php` | Подключение стилей темы, лёгкие правки (напр., разделитель хлебных крошек). |
| `wp-content/themes/generatepress-child/header.php` | Шаблон шапки GeneratePress (обычно без бизнес-логики; UI складов монтируем из плагина). |

</details>

<details>
<summary><strong>style.css — ключ к сетке каталога</strong></summary>

Минимальный набор правил (без дублей):

```css
/* Woo Grid base */
.woocommerce ul.products::before,
.woocommerce ul.products::after { content: none !important; }

.woocommerce ul.products{
  list-style:none; margin:0; padding:0;
  display:grid !important;
  gap:20px;
  grid-auto-flow:row;
  grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));
}

/* Tablet */
@media (max-width:1024px){
  .woocommerce ul.products{
    grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));
  }
}

/* Mobile */
@media (max-width:768px){
  .woocommerce ul.products{
    grid-template-columns:repeat(auto-fit, minmax(100px, 1fr));
  }
}

/* Reset widths that fight the grid */
.woocommerce ul.products li.product{
  float:none !important; width:auto !important; margin:0 !important; clear:none !important;
}
.woocommerce ul.products[class*="columns-"] li.product{
  width:auto !important; clear:none !important; margin-right:0 !important;
}

/* Even if Woo forces columns-1 — keep grid */
.woocommerce ul.products.columns-1{ display:grid !important; }

Ручки: меняй «минимум» в minmax(…px, 1fr) — так управляется число колонок на брейкпоинте.
```

</details>

<details>
<summary><strong>functions.php — только лёгкие хуки</strong></summary>
<?php
// Подключение стилей дочерней темы
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('generatepress-child-style', get_stylesheet_uri());
});

// Хлебные крошки: разделитель
add_filter('woocommerce_breadcrumb_defaults', function ($defaults) {
    $defaults['delimiter'] = ' <span class="breadcrumb-delimiter">→</span> ';
    return $defaults;
});

</details>
<details>
<summary><strong>Мини-стили шапки (селекторы «Списание/Склад»)</strong></summary>
/* Рядом с логотипом */
.site-branding{ display:flex; align-items:center; gap:12px; }

/* Контрол списания/склада */
.pc-alloc{ display:flex; align-items:center; gap:8px; font:14px/1.2 system-ui; }
.pc-alloc small{ color:#666; }
.pc-alloc select{ max-height:34px; padding:4px 8px; line-height:1.2; min-width:0; }

/* Телефоны */
@media (max-width:480px){
  .site-branding{ gap:8px; }
  .pc-alloc{ gap:6px; }
  .pc-alloc small{ font-size:12px; }
  .pc-alloc select{ font-size:12px; height:32px; padding:0 22px 0 8px; }
}

/* Очень узкие — в столбик */
@media (max-width:360px){
  .pc-alloc{ flex-direction:column; align-items:stretch; gap:6px; }
  .pc-alloc > *{ width:100%; }
  .pc-alloc small{ display:none; }
}

</details>

## 🧩 MU-plugins

<details>
<summary><strong>Общее</strong></summary>

MU-плагины грузятся всегда (без активации в админке) из `wp-content/mu-plugins/`.  
Здесь лежат «низкоуровневые» вещи, которые должны применяться раньше темы/обычных плагинов.

</details>

---

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

```  
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

```
</details>

<details>
<summary><strong>Импорт цен по ролям (CSV, для менеджеров)</strong></summary>

**Что это:** простая админ-страница, куда менеджер загружает CSV → плагин обновляет мета-цены `_wpc_price_role_*` у товаров по SKU.

**Где в админке:** <code>Инструменты → Импорт цен (CSV)</code>.

**Поддерживаемый формат CSV (точно эти заголовки):**

```csv
sku;partner;opt;opt_osn;schule
CR-001;10.50;11.00;9.90;10.00
CR-002;12;12;11.5;11.5
```
```txt
- Разделитель определяется автоматически: `;` / `,` / `TAB`.
- Кодировка: UTF-8 / CP1251 — определяется автоматически.
- Пустые клетки не обновляют цену по роли.
- Десятичный разделитель `.` или `,` — допустим.

**Какие роли поддерживаются «из коробки»:**
- `partner` → `_wpc_price_role_partner`
- `opt` → `_wpc_price_role_opt`
- `opt_osn` → `_wpc_price_role_opt_osn`
- `schule` → `_wpc_price_role_schule`  
(можно расширить: добавить колонку — добавить в `$roleColumns` внутри плагина)

**Как работает обновление:**
1) По `sku` находим товар (`_sku`).  
2) Для каждой непустой роли обновляем мета-ключ `_wpc_price_role_<role>`.  
3) Корзина/витрина увидит новые цены (плагин `role-price` уже их отдаёт).

**Безопасность / откат:**
- Опция «Сделать бэкап» — создаёт таблицу `wp_postmeta_backup_role_price_YYYYMMDDHHMMSS` с текущими `_wpc_price_role_*`.

**Шаги для менеджера:**
1. Сформировать CSV (см. шаблон выше).
2. Зайти в **Инструменты → Импорт цен (CSV)**.
3. Выбрать файл → (опц.) включить **Сделать бэкап** → нажать **Импортировать**.
4. Проверить отчёт (сколько SKU найдено/обновлено, сколько пропущено).

**Замечания:**
- На время разработки API — этого достаточно для 1–2 обновлений в неделю.
- Когда API будет готов, страницу можно скрыть, а логику — перевести на CRON/веб-хуки.
```
</details>

<details>
<summary><strong>2) mu-plugins/stock-import-csv-lite.php — импорт остатков из CSV (Lite)</strong></summary>

**Назначение.** Загружает CSV с остатками по складам в таблицу `wp_stock_import`. Поддерживает **длинный** и **широкий** формат, авто-определяет кодировку и разделитель. Есть кнопка **SMOKE-TEST**.

**Где в админке:** ⚙️ Инструменты → **Импорт остатков (Lite)**  
**Права:** `manage_options` (только админы)  
**Таблица назначения:** `${$wpdb->prefix}stock_import`

---

### Форматы CSV
**1) Длинный** — один склад в строке:
```csv
sku;location_slug;qty
CR-TEST-001;kiev1;10
CR-TEST-001;odesa;3.5
CR-TEST-002;kiev1;0
```
**2) Широкий — склады колонками:
```csv
sku,kiev1,odesa
A-AZ-001,"68583,91",0
AB-111-10X15,0,0
AB-111-20X20,3,1.5
```
Пустые/нулевые ячейки в «широком» формате пропускаются (строки не создаются).

⸻
Куда складывает:
	•	Таблица назначения:

```sql
CREATE TABLE wp_stock_import (
  sku           VARCHAR(191) NOT NULL,
  location_slug VARCHAR(191) NOT NULL,
  qty           DECIMAL(18,3) NOT NULL,
  PRIMARY KEY (sku, location_slug)
);
```  

```
Алгоритм и поведение
	•	Кодировка: авто (UTF-8 / CP1251 / ISO-8859-1 / Windows-1252). Убирается BOM.
	•	Разделитель: авто (; / , / TAB). Десятичные: , и . поддерживаются.
	•	Заголовки нормализуются (алиасы):
киев / київ / kiev / к → kiev1, одесса / одеса / odessa / odesa / о → odesa. Незнакомые — sanitize_title().
	•	Запись идёт пакетами по 1000 значений (bulk insert).
	•	Ключ таблицы: (sku, location_slug). Вставка с ON DUPLICATE KEY UPDATE (upsert).
	•	Опция TRUNCATE — предварительно очищает таблицу.
	•	Кнопка SMOKE-TEST создаёт строку (CR-TEST-SMOKE, kiev1, 7).

Схема хранения остатков в базе

👉 После импорта данные распределяются по мета-ключам товара и связям:
	•	Наличие на складах:
_stock_at_{term_id} = количество (например, _stock_at_3942 = 12)
	•	Общий остаток:
_stock = 44
	•	Primary (основной склад):
_yoast_wpseo_primary_location = term_id
	•	Привязка к складам:
wp_term_relationships (taxonomy = location → wp_term_taxonomy → wp_terms)

Где что хранится (итог):

Что                      Где хранится
Общий остаток            wp_postmeta._stock
Остаток по складу        wp_postmeta._stock_at_{term_id}
Primary-склад            wp_postmeta._yoast_wpseo_primary_location (значение = term_id)
Список локаций у товара  wp_term_relationships (таксономия location → wp_term_taxonomy → wp_terms)
```
SQL-пример (выгрузить остатки по складам для товаров)
```sql
SELECT
  p.ID,
  p.post_title,
  sku.meta_value                                AS sku,
  t.term_id,
  t.name                                        AS location_name,
  t.slug                                        AS location_slug,
  CAST(pm_qty.meta_value AS SIGNED)             AS qty,
  CAST(pm_total.meta_value AS SIGNED)           AS total_stock,
  pm_primary.meta_value                         AS primary_location_term_id,
  CASE WHEN pm_primary.meta_value = t.term_id THEN 1 ELSE 0 END AS is_primary
FROM wp_posts p
JOIN wp_postmeta sku
  ON sku.post_id = p.ID
 AND sku.meta_key = '_sku'
 AND sku.meta_value <> ''
/* строки вида _stock_at_{term_id} */
JOIN wp_postmeta pm_qty
  ON pm_qty.post_id = p.ID
 AND pm_qty.meta_key REGEXP '^_stock_at_[0-9]+$'
/* вынимаем term_id из ключа */
JOIN wp_terms t
  ON t.term_id = CONVERT(SUBSTRING_INDEX(pm_qty.meta_key, '_stock_at_', -1), UNSIGNED)
JOIN wp_term_taxonomy tt
  ON tt.term_id = t.term_id
 AND tt.taxonomy = 'location'
/* общий остаток и primary location */
LEFT JOIN wp_postmeta pm_total
  ON pm_total.post_id = p.ID
 AND pm_total.meta_key = '_stock'
LEFT JOIN wp_postmeta pm_primary
  ON pm_primary.post_id = p.ID
 AND pm_primary.meta_key = '_yoast_wpseo_primary_location'
WHERE p.post_type = 'product'
  AND p.post_status IN ('publish','private')
-- AND sku.meta_value = 'CR-CE0900056730'   -- (опционально) отфильтровать по SKU
ORDER BY sku, location_name;
```

Структура таблицы (DDL)

Если таблицы нет — создай:
```
CREATE TABLE wp_stock_import (
  sku           VARCHAR(191) NOT NULL,
  location_slug VARCHAR(191) NOT NULL,
  qty           DECIMAL(18,3) NOT NULL,
  PRIMARY KEY (sku, location_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
```
Поля отчёта (что вернёт страница после импорта)
	•	ok (bool), format (long|wide), encoding, delimiter
	•	rows_read (сколько строк прочитали из CSV)
	•	rows_pushed (сколько записей подготовлено/вставлено)
	•	errors (пропущенные записи из-за валидации)
	•	time_sec (время), last_error (ошибка БД, если была)

⸻

Частые вопросы / диагностика
	•	«Не распознан формат» — проверь заголовки. Для длинного нужны sku,location_slug,qty.
	•	«SKU не найден…» — этот импорт не лезет в продукты; он только пишет в wp_stock_import. Дальше данные заберёт модуль синка.
	•	«Кириллица/кракозябры» — убедись, что CSV в UTF-8 или CP1251 (авто-конвертация включена).
	•	«Нули/пустые ячейки» — в широком формате они игнорируются (не создают строк).
	•	Где смотреть ошибку SQL? — поле last_error в отчёте и wp-content/debug.log (если включён WP_DEBUG_LOG).

⸻

Интеграция в пайплайн
	1.	Загрузили CSV сюда →
	2.	wp_stock_import заполнилась →
	3.	модуль синхронизации переносит данные из wp_stock_import в меты товара (или в вашу систему остатков).
```
</details>

<details>
<summary><strong>stock-sync-to-woo.php — синхронизация остатков в WooCommerce</strong></summary>

```txt
Назначение.
Берёт данные из таблицы wp_stock_import (sku, location_slug, qty) и переносит их в WooCommerce:
	•	пишет остатки в мета-ключи _stock_at_{TERM_ID} (и при опции — _stock_at_{slug}),
	•	суммирует и обновляет _stock,
	•	обновляет статус in stock / out of stock,
	•	привязывает товар к термам таксономии location,
	•	может выставить Primary location.

⸻

Как работает
	1.	Берём партии строк из wp_stock_import (batch size — по умолчанию 500).
	2.	Для каждого SKU:
	•	ищем товар по SKU (product или variation),
	•	ищем склад по location_slug в таксономии location,
	•	пишем количество в _stock_at_{TERM_ID},
	•	при включённой опции — дублируем в _stock_at_{slug}.
	3.	Обновляем суммарный остаток _stock.
	4.	По опциям:
	•	upd_status — обновить _stock_status (instock / outofstock),
	•	set_manage — включить manage_stock=yes,
	•	attach_terms — привязать товар к таксономии location,
	•	set_primary — если нет primary, поставить первый из складов,
	•	delete_rows — удалять обработанные строки,
	•	loop_until_empty — повторять цикл до пустой таблицы.

⸻

Опции (админка → Инструменты → «Синхр. остатков → Woo»)
	•	Batch size — сколько строк обрабатывать за проход.
	•	Dry-Run — только показать, без записи.
	•	Фильтр по SKU (префикс) — обрабатывать только товары с заданным префиксом SKU.
	•	Обновлять статус наличия (_stock_status).
	•	Включать manage_stock.
	•	Удалять строки из wp_stock_import после записи.
	•	Крутиться до пустой таблицы (если включено удаление строк).
	•	Привязывать location к товарам.
	•	Ставить Primary location.
	•	Дублировать меты по slug — писать _stock_at_{slug} для совместимости.

⸻

Пример хранения после синка
	•	_stock_at_3942 = 12
	•	_stock_at_3943 = 32
	•	_stock = 44
	•	_yoast_wpseo_primary_location = 3942
	•	Привязка к taxonomy = location (через wp_term_relationships).

⸻

Отчёт

После выполнения отображает:
	•	сколько строк обработано,
	•	сколько товаров обновлено,
	•	какие SKU не найдены,
	•	какие location_slug не распознаны,
	•	какие мета-ключи использовались,
	•	сколько записей добавлено/обновлено в wp_postmeta.

⸻

Диагностика
	•	Dry-Run → можно посмотреть отчёт без записи в мету.
	•	Если SKU не найден — будет в not_found_skus.
	•	Если склад не найден — будет в not_found_locations.
	•	Состояние таблицы: SELECT COUNT(*) FROM wp_stock_import;.
```
</details>

<details>
<summary><strong>3) mu-plugins/stock-locations-ui.php — UI складов (каталог / PDP / корзина)</strong></summary>

```

Назначение. Единый блок остатков по складам и строка «Списание» в корзине/чекауте.
Показывает:
	•	Заказ со склада: приоритетный (выбранный/primary)
	•	Другие склады: список «Имя — qty» (только с qty > 0)
	•	Всего: суммарный остаток
	•	В корзине/чекауте строку «Списание: Київ — 2, Одеса — 1» по плану распределения.

Режимы работы: auto / manual / single (берутся из селектора в шапке: cookie/сессия).
Контекст показа: PDP, луп каталога, корзина/чекаут.

```
Где берутся данные
```

Что                                   Источник

Список локаций товара           таксономия location (wp_term_relationships → wp_terms)
Остаток по локации              wp_postmeta._stock_at_{term_id} (для вариаций — фолбэк к родителю)
Общий остаток                   wp_postmeta._stock (если нет — суммируем _stock_at_%)
Primary-локация                 wp_postmeta._yoast_wpseo_primary_location (значение = term_id)
Уже в корзине                   объём из WC()->cart по продукту/вариации

```
Ключевые функции
```php

pc_build_stock_view( WC_Product $product ): array
// Собирает и сортирует локации под режим (убирает нулевые), возвращает:
// ['mode','preferred','primary','ordered' => [term_id => ['name','qty']], 'sum']

slu_render_stock_panel( WC_Product $product, array $opts = [] ): string
// Рендер блока (каталог + PDP), учитывает режим и опции (см. таблицу ниже)

slu_get_allocation_plan( WC_Product $product, int $need, string $strategy='primary_first' ): array
// Строит план списания [ term_id => qty ] с приоритетом primary → остальные (qty по убыванию)

slu_render_allocation_line( WC_Product $product, int $need ): string
// Возвращает строку "Київ — 2, Одеса — 1" по плану списания

```
Опции рендера панели (slu_render_stock_panel)
```

Опция         Тип       Дефолт        Что делает
wrap_class    string      ''        Доп. класс контейнера
show_primary  bool      true        Оставлено для совместимости (показываем первую строку)
show_others   bool      true        Показ остальных локаций
show_total    bool      true        Показ строки «Всего: N»
show_incart   bool      false       (зарез. на будущее)
show_incart_plan bool   false       (зарез. на будущее)
hide_when_zero   bool   false       Если нечего показывать (после фильтрации нулей) — скрыть блок

Важно: перед рендером нулевые склады зеркально фильтруются (qty <= 0 → не показываем).
В режиме single блок вообще не рисуется, если выбранный склад пуст.

⸻

Встраивание в шаблоны (есть в плагине)
	•	PDP: woocommerce_single_product_summary (приоритет 25)
	•	Каталог: woocommerce_after_shop_loop_item_title (приоритет 11, класс slu-stock-mini, hide_when_zero=true)
	•	Корзина/чекаут (строка «Списание»):
```
```
add_filter('woocommerce_get_item_data', 'slu_cart_allocation_row', 30, 2);
```
```
Хуки/расширение
	•	Переопределение плана списания:
```
```
add_filter('slu_allocation_plan', function($plan, $product, $need, $strategy){
    // верни массив [ term_id => qty ], чтобы полностью заменить логику
    return null; // вернуть массив, чтобы применился он; null — оставить дефолт
}, 10, 4);
```
```
	•	Отключение «старых» строк складов в корзине (если их добавляет другой модуль):
// add_filter('pc_disable_legacy_cart_locations', '__return_true');

Шорткод

Показать план списания в любом месте:

[pc_stock_allocation product_id="43189" qty="3"]

Классы и стили (вшитые; можно вынести в тему)
	•	slu-stock-box — базовый контейнер (PDP)
	•	slu-stock-mini — компактный вид (каталог)
	•	.is-preferred — подсветка приоритетного склада
	•	.slu-nb .slu-stock-total — «Всего: N» фиксируем в одну строку

⸻

Диагностика
	1.	На PDP/каталоге нет блока — проверьте, что остатков > 0 (нули скрываются), и что товар привязан к таксономии location.
	2.	Корзина не показывает «Списание» — убедитесь, что находит план (slu_get_allocation_plan) и хук woocommerce_get_item_data активен.
	3.	Нужен другой порядок приоритета — используйте фильтр slu_allocation_plan (например, «всегда сначала Одесса»).
	4.	В режиме single пустой склад → блок скрывается по дизайну.
```

</details>

<details>
<summary><strong>5) role-price/role-price.php — цены по ролям (runtime)</strong></summary>

**Идея.** Для каждого товара можно задать **свою цену под роль пользователя**.  
Плагин в рантайме подменяет цену, если для текущей роли найдена мета.

### Как формируется мета-ключ

wpc_price_role
```
Примеры:
- `_wpc_price_role_partner`
- `_wpc_price_role_opt`
- `_wpc_price_role_opt_osn`
- `_wpc_price_role_schule`

> Суффикс берётся из **первой роли** пользователя: `$user->roles[0]`.

### Где хранится
- Таблица: `wp_postmeta`  
- Ключ: `_wpc_price_role_<role>`  
- Значение: цена как строка/decimal (потом приводится к `wc_get_price_decimals()`)

Быстрая проверка в БД:
```sql
SELECT post_id, meta_key, meta_value
FROM wp_postmeta
WHERE meta_key LIKE '_wpc_price_role_%'
LIMIT 20;
```
Как рассчитывается цена (хуки и приоритеты)

```
Этап                        Хук/механизм                                   Что делает

Подмена цены товара     woocommerce_product_get_price (prio 5)           Если найдена цена под роль — вернуть её; 
                                                                            иначе не трогать ($price как был)
                                                                            
Подмена цены вариации   woocommerce_product_variation_get_price (prio 5)  То же, для вариаций

Разные цены в кэше вариаций   woocommerce_get_variation_prices_hash      Добавляет роль в хеш: один и тот же 
                                                                        товар может иметь разные цены для разных ролей

Пересчёт в корзине        woocommerce_before_calculate_totals            Обновляет цену, если товар добавили «до» 
                                                                          смены роли/правил

Приоритет 5 выбран специально: если своей цены нет, мы не мешаем сторонним скидкам/плагинам 
(которые обычно висят на ~10 и ниже).

```
CSV / импорт

Обычно роли-цены завозятся пакетом вместе со SKU (см. раздел «SQL — внесение цен»).
Минимальный CSV:
```
sku;partner;opt;opt_osn;schule
CR-001;10.50;11.00;9.90;10.00
```
```
	•	После импорта ты получишь меты:
_wpc_price_role_partner, _wpc_price_role_opt, _wpc_price_role_opt_osn, _wpc_price_role_schule на постах-товарах.
	•	Сам role-price только читает эти меты и подставляет цену в рантайме. Импорт делает отдельный модуль/SQL.

Алгоритм плагина (в 3 шагах)
	1.	Получить текущего пользователя и его первую роль.
	2.	Сформировать мета-ключ _wpc_price_role_<role> и прочитать мету для текущего товара/вариации.
	3.	Если мета не пустая — вернуть эту цену; иначе оставить то, что вернуло ядро/другие плагины.

Частые вопросы и диагностика
	•	«Цена не меняется» — проверь, что у пользователя реально есть роль (а не guest) и что у товара есть соответствующая мета.
	•	«Скидки не применяются» — это норма, если есть кастомная роль-цена: она главнее. Если роли-цены нет — скидки сторонних плагинов остаются.
	•	«Вариации показывают одну цену для всех» — нужен хук woocommerce_get_variation_prices_hash (он добавлен).
	•	«После смены роли в корзине старая цена» — пересчёт делает хук woocommerce_before_calculate_totals (он добавлен).

Куда смотреть в коде

wp-content/plugins/role-price/role-price.php
Ключевые точки:
	•	vp_role_price_override() — подмена цены товара/вариации;
	•	фильтр хеша вариаций;
	•	пересчёт цены в корзине.
```
</details>

<details>
<summary><strong>paint-shop-ux/paint-shop-ux.php — UX-правки каталога</strong></summary>

**Назначение.** Делает карточки ровнее и компактнее:
- короткие названия в каталоге (берёт часть после `|`, иначе последние N символов),
- единая высота блока изображения (desktop/tablet/mobile),
- фиксированная высота заголовка (ровно 2 строки), «подвал» карточки прижат вниз,
- чуть меньший H1 в листингах на мобилках.

### Константы (ручки плагина)
| Константа | Что делает | Дефолт |
|---|---|---|
| `PSU_COLS_DESKTOP` | управлять колонками PHP-ом (не используется; сетка у темы) | `0` (=выкл) |
| `PSU_IMG_H_DESKTOP` | высота изображения в каталоге, px | `210` |
| `PSU_IMG_H_TABLET` | высота на планшете, px | `190` |
| `PSU_IMG_H_MOBILE` | высота на мобилке, px | `180` |

> Сетка каталога остаётся за **child-theme** (CSS Grid); этот плагин не трогает количество колонок.

### Как работает
- **Компактный title:** хук `woocommerce_shop_loop_item_title` заменён на свой вывод.  
  Логика: если в названии есть `|`, берём правую часть. Иначе показываем **последние N символов** (по умолчанию 25, юникод-безопасно).
- **Картинка:** инлайн-CSS фиксирует высоту, делает `object-fit: contain`, белый фон, паддинги.
- **Ровные карточки:** `.product { display:flex; flex-direction:column }` и марджины у кнопок/цены → «подвал» всегда внизу.
- **Заголовки списков:** `H1` в выдаче категорий на мобилках уменьшен.

### Хуки
- `init` → отключаем стандартный `woocommerce_template_loop_product_title` и включаем `psu_loop_title`.
- `wp_enqueue_scripts` → инлайн-CSS с высотами картинок и фиксами карточек.
- (опц.) `loop_shop_columns` комментирован — не нужен при CSS Grid из темы.

### Совместимость
- **psu-force-per-page (MU):** совместим. Этот плагин не меняет `per_page`, только вёрстку карточек.
- **Тема (GeneratePress Child):** сетка (Grid) задаётся в теме; при конфликте стилей — удали дублирующиеся ресеты из `style.css` темы.

### Быстрая настройка (примеры)
```php
// Сделать изображения выше на десктопе и ниже на мобилке
// в начале плагина обнови константы:
const PSU_IMG_H_DESKTOP = 240;
const PSU_IMG_H_MOBILE  = 160;
```

```text
Что менять, если «не ловит» часть после |
	•	Проверь, что в реальном базе/имени товара разделитель — вертикальная черта | (а не «—»/«-»).
	•	В функции psu_compact_title_after_pipe($title, $reserve) можно:
	•	Заменить strpos($t, '|') на поиск по другому символу,
	•	Увеличить $reserve (сколько символов показывать, если | нет).
```

</details>

⚙️ Кастомные модули и настройки


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