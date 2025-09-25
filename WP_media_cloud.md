🖼️ Интеграция WordPress Media с OVH Object Storage (через Media Cloud)

1. Настройки WordPress (таблица wp_options)

Обязательно проверить и задать значения:

```sql

SELECT option_name, option_value
FROM wp_options
WHERE option_name IN ('upload_url_path','upload_path','siteurl','home');

```

option_name                 значение
home                http://paint.local (на локалке) или https://kreul.com.ua (на проде)
siteurl             http://paint.local (на локалке) или https://kreul.com.ua (на проде)
upload_path         (пусто)
upload_url_path     https://kreul-media.s3.gra.io.cloud.ovh.net/wp-content/uploads

⚠️ Важно: upload_url_path указывает базовый URL S3-бакета, а upload_path должен быть пустым (иначе WP подставляет локальный путь).

⸻

```text

2. Настройки плагина Media Cloud

В админке → Media Cloud → Storage Settings:
	•	Upload Privacy ACL → Public
	•	Upload Path → wp-content/uploads/@{date:Y/m}/
	•	CDN Base URL → https://kreul-media.s3.gra.io.cloud.ovh.net
	•	(опция) Включить галочки:
	•	«Rewrite URLs on frontend»
	•	«Rewrite URLs in admin»
	•	Cache-Control: public,max-age=31536000

После этого: все новые файлы будут загружаться прямо в S3 и подменяться на фронтенде.

⸻

3. База данных (media attachments)

Важные поля:
	•	В wp_posts для post_type = attachment:
	•	guid → полный URL на S3
	•	пример:

    https://kreul-media.s3.gra.io.cloud.ovh.net/wp-content/uploads/2025/06/cr-ce0900056730.jpg

    	•	В wp_postmeta:
	•	_wp_attached_file → относительный путь внутри uploads
	•	пример:

    2025/06/cr-ce0900056730.jpg

    	•	Связки с товарами (WooCommerce):
	•	_thumbnail_id → ID основного изображения
	•	_product_image_gallery → список ID галереи (через запятую)

Пример проверки:

```

```sql

SELECT p.ID, p.guid,

```

🔧 Массовое обновление БД для миграции медиа в S3

1. Обновить guid в wp_posts

⚠️ guid должен хранить полный публичный URL (иначе RSS и некоторые плагины будут ругаться).

```sql

UPDATE wp_posts
SET guid = REPLACE(
    guid,
    'http://paint.local/wp-content/uploads/',
    'https://kreul-media.s3.gra.io.cloud.ovh.net/wp-content/uploads/'
)
WHERE post_type = 'attachment';

```

```text

2. Обновить _wp_attached_file в wp_postmeta

⚠️ Здесь храним только относительный путь (год/месяц/файл.jpg).
Поэтому просто убираем старый префикс wp-content/uploads/.

```

```sql

UPDATE wp_postmeta
SET meta_value = REPLACE(
    meta_value,
    'wp-content/uploads/',
    ''
)
WHERE meta_key = '_wp_attached_file';

```
3. На всякий случай — очистить кеш ссылок

```bash
wp cache flush
wp transient delete --all

```
(на сервере нужно выполнить из корня WP или добавить --path=/var/www/...).

⸻

4. Проверка (выборка 5 любых картинок)

```sql

SELECT p.ID, p.guid, m.meta_value AS attached_file
FROM wp_posts p
LEFT JOIN wp_postmeta m ON m.post_id=p.ID AND m.meta_key='_wp_attached_file'
WHERE p.post_type = 'attachment'
ORDER BY p.ID DESC
LIMIT 5;

```

```text

👉 Должно быть:
	•	guid = полный URL в S3
	•	attached_file = 2025/09/имяфайла.jpg

⸻

5. WooCommerce товары

WooCommerce сам подтянет картинки из _thumbnail_id и _product_image_gallery.
Проверить можно так:

```

```sql

SELECT post_id, meta_key, meta_value
FROM wp_postmeta
WHERE meta_key IN ('_thumbnail_id','_product_image_gallery')
ORDER BY post_id DESC
LIMIT 10;

```

```text

📌 Результат

После этих шагов:
	•	Все старые картинки будут выглядеть так же, как новые, с «облачком» в Media Cloud.
	•	Фронтенд будет рендерить ссылки напрямую из OVH S3.
	•	Когда добавишь CNAME media.kreul.com.ua, достаточно будет в CDN Base URL (Media Cloud) заменить базовый URL, и весь сайт перейдёт на красивый поддомен.

⸻

Хочешь, я соберу эти SQL в один файл migrate_media_to_s3.sql, чтобы можно было разово прогнать на сервере через mysql < migrate_media_to_s3.sql?

```