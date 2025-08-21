# 🛒 Paint Shop (WooCommerce)

E-commerce проект на базе **WordPress + WooCommerce**, кастомизированный под задачи магазина красок.

## 📂 Структура проекта
app/public/           # корень WordPress
├─ wp-content/
│   ├─ themes/
│   │   └─ my-theme/           # кастомная тема (с оверрайдами WooCommerce)
│   ├─ plugins/
│   │   └─ my-custom-plugin/   # кастомные плагины
│   ├─ uploads/                # медиафайлы (не в Git)
│   └─ mu-plugins/             # must-use плагины (если есть)
├─ .gitignore
├─ wp-cli.yml
└─ README.md

## 🚀 Как развернуть проект

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



Как устроено хранение
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