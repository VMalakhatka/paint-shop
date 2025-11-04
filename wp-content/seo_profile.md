🔍 SEO-профиль проекта Lavka / Kreul (UA / RU / DE)

1. Общая архитектура сайта

CMS / Core:
	•	WordPress + WooCommerce (версия 8.x)
	•	PHP 8.2 / MariaDB 10.6+ / Redis Object Cache / OPcache
	•	Статическая отдача изображений через OVH Object Storage + CDN media.kreul.com.ua

Основные плагины:
	•	paint-core — базовые функции каталога, i18n, общие хуки.
	•	lavka-sync — синхронизация товаров, категорий, остатков, цен.
	•	lavka-total-sync — полная синхронизация контента (описания, категории, атрибуты).
	•	pc-order-import-export — обмен заказами с ERP.
	•	wpc-price-by-user-role — цены по ролям.
	•	yoast-seo — SEO-метаданные.
	•	loco-translate — локализация интерфейса.
	•	zzz-sitemap-guard — защита sitemap от мусорных записей.

Интеграции:
	•	Java-сервис Lavka Sync API (Spring Boot, REST)
	•	Синхронизация остатков, цен, и описаний с MSSQL.
	•	Поддержка трёх языковых доменов:
	•	🇺🇦 lavka.com.ua — основная витрина (украинский)
	•	🇷🇺 paint-shop.ru — русскоязычный клон с отдельной базой товаров
	•	🇩🇪 kreul.de — немецкий поддомен, ориентирован на локальный рынок

⸻

2. Структура контента

Типы контента:
	•	product — товары WooCommerce
	•	product_cat — категории и подкатегории
	•	product_tag — теги
	•	page — контентные страницы (FAQ, Доставка, О нас)
	•	post — статьи / блоги / новости (SEO-материалы)

Иерархия категорий (пример):

Фарби (root)
 ├── Акрилові фарби
 │    ├── Pebeo Studio 100мл
 │    │    ├── Основні кольори
 │    │    ├── Металіки
 │    │    ├── Флуоресцентні
 │    │    └── Іридисцентні
 │    └── Kreul Solo Goya
 └── Масляні фарби
      ├── Сонет
      ├── Мастер Класс
      └── Van Gogh

Контентные страницы:
	•	/about/, /contacts/, /delivery/, /privacy-policy/
	•	/blog/ — статьи с SEO-ключами, категориями и внутренними ссылками

⸻

3. Поля для SEO-анализа

Тип	Поле	Источник
Title	post_title	WooCommerce / CSV / Yoast
Meta title	_yoast_wpseo_title	Yoast SEO
Meta description	_yoast_wpseo_metadesc	Yoast SEO
Canonical URL	_yoast_wpseo_canonical	Yoast SEO
H1	дублирует post_title, иногда переопределён в шаблоне	
Alt-тексты	из wp_postmeta._wp_attachment_image_alt	
OpenGraph	_yoast_wpseo_opengraph-title, _yoast_wpseo_opengraph-description	
Schema	JSON-LD, генерируется Yoast SEO + кастомный paint-core-schema.php	
Robots	контролируется wp_robots и robots.txt	
Product short description	post_excerpt	
SEO-slug	post_name	
Primary category	_yoast_wpseo_primary_product_cat	
Breadcrumbs	yoast_breadcrumb	
Images	хранятся в wp-content/uploads, URL через CDN media.kreul.com.ua	


⸻

4. Где хранятся SEO-поля в БД

Таблица	Описание
wp_posts	базовые поля title, slug, content
wp_postmeta	мета-данные товаров (включая Yoast SEO и _stock_*, _price_*)
wp_terms, wp_term_taxonomy, wp_termmeta	категории, подкатегории, описания, SEO Yoast (_yoast_wpseo_title, _yoast_wpseo_metadesc)
wp_yoast_indexable	кэш-таблица Yoast для быстрой выборки SEO-данных
wp_options	глобальные SEO-настройки и sitemap
wp_lavka_catmap	маппинг категорий Woo ↔ Java-сервис


⸻

5. Инструменты анализа

SQL / WP-CLI / REST:
	•	Просмотр SEO-мета:

SELECT post_id, meta_key, meta_value 
FROM wp_postmeta 
WHERE meta_key LIKE '_yoast_wpseo_%' 
LIMIT 100;


	•	Проверка отсутствующих description/title:

SELECT p.ID, p.post_title
FROM wp_posts p
LEFT JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_yoast_wpseo_metadesc'
WHERE m.meta_value IS NULL AND p.post_type='product';


	•	WP-CLI:

wp yoast index rebuild
wp i18n make-pot .


	•	Sitemap: /sitemap_index.xml
	•	Robots: /robots.txt
	•	REST API: /wp-json/wp/v2/products, /wp-json/yoast/v1/indexables

⸻

6. Рекомендации по автоматическому сбору и анализу SEO-данных

1️⃣ Сбор метаданных:
	•	Скрипт на WP-CLI или PHP-CRON, который выгружает post_title, slug, _yoast_wpseo_title, _yoast_wpseo_metadesc, _yoast_wpseo_canonical в CSV.

2️⃣ Проверка качества:
	•	Проверять наличие title и description у всех publish товаров.
	•	Проверять совпадения alt-текстов с title изображений.
	•	Проверять canonical URL и дубли.

3️⃣ Интеграция с Java-бэком:
	•	Возможен REST-эндпоинт /seo/report, который возвращает список проблемных карточек (например, пустой мета-title или дублирующий slug).

4️⃣ Автоматический аудит sitemap:
	•	Парсить sitemap XML → сверять наличие URL в wp_posts.

5️⃣ Инструменты:
	•	Screaming Frog SEO (локальный аудит)
	•	Google Search Console (индексация и CTR)
	•	Ahrefs / Semrush для анализа ссылок и позиций.