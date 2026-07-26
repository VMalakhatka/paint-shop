# 🛒 Paint Shop (WooCommerce)
чтобы достучаться до базы на серверер надо поднять тунель 
ssh -p 2022 -N -L 3307:127.0.0.1:3306 vmalakhatka@51.83.33.95

## Folio / Woo Orders

Основная документация по созданию счетов ФОЛІО из Woo-заказов:

`docs/FOLIO_ORDER_JSON_CONTRACT.md`

Там описаны:

- JSON contract Woo -> Java/Folio;
- ручная цепочка preview/create/apply/split;
- автоматическая обработка checkout-заказов;
- meta-поля связи Woo order <-> Folio document;
- статусы parent/child orders после split;
- SQL-проверки и recovery notes.

Автоматическое создание документов ФОЛІО при checkout включено по умолчанию.
Отключить можно в `wp-config.php` или environment-specific config:

```php
define('PC_FOLIO_AUTO_CHECKOUT', false);
```

## 🚀 Перенос базы WordPress из локальной среды (Local) на сервер
<details>
<summary><strong>deploy db to kreul.com.ua </strong></summary>

Для деплоя запусти 

```bash
./export_and_push.sh
```
### Описание нюансов
- `wp-content/deploy_db.sh` — скрипт для сервера.  
  Должен лежать в домашней папке пользователя **vmalakhatka** на сервере: 

  ~/deploy_db.sh 

```text
(и быть исполняемым: `chmod +x ~/deploy_db.sh`).

- `wp-content/export_and_push.sh` — скрипт для локального запуска на Mac.  
Он:
1. Экспортирует базу из Local через сокет.
2. Сжимает дамп.
3. Копирует на сервер.
4. Вызывает `deploy_db.sh` для импорта.

Оба скрипта хранятся в репозитории в `wp-content/`, чтобы всегда были под рукой.

---

###  🔧 Подготовка

1. Убедись, что SSH-ключ добавлен для пользователя `vmalakhatka` на сервере.  
 Проверка:

```bash
 ssh -p 2022 vmalakhatka@51.83.33.95(и быть исполняемым: `chmod +x ~/deploy_db.sh`).
```

- `wp-content/export_and_push.sh` — скрипт для локального запуска на Mac.  
Он:
1. Экспортирует базу из Local через сокет.
2. Сжимает дамп.
3. Копирует на сервер.
4. Вызывает `deploy_db.sh` для импорта.

Оба скрипта хранятся в репозитории в `wp-content/`, чтобы всегда были под рукой.

--- 

### 🔧 Подготовка

1. Убедись, что SSH-ключ добавлен для пользователя `vmalakhatka` на сервере.  
 Проверка:
 
```bash
 ssh -p 2022 vmalakhatka@51.83.33.95
```

(логин без пароля).
	2.	На сервере в ~/deploy_db.sh должны быть права на запуск:

```bash
chmod +x ~/deploy_db.sh
```


🚀 Setup Environment (Local → Server deploy)

Чтобы скрипты deploy_safe.sh и export_and_push.sh работали без ошибок и лишних паролей, нужно настроить локальное окружение:

1. MySQL client

На macOS (Apple Silicon) ставим клиент:

```bash
brew install mysql-client
```
Добавляем в PATH (~/.zshrc и ~/.zprofile):

```bash

if [ -d /opt/homebrew/opt/mysql-client/bin ]; then
  export PATH="/opt/homebrew/opt/mysql-client/bin:$PATH"
fi

```

Перезагрузить оболочку:

```bash

source ~/.zshrc

```

Проверка:

```bash

mysql --version
mysqldump --version


```


2. MySQL credentials (~/.my.cnf)

Создаём файл:

```bash

cat > ~/.my.cnf <<'EOF'
[client]
user=root
password=root
socket=/Users/admin/.local-mysql/mysqld.sock
EOF
chmod 600 ~/.my.cnf

```

⚠️ socket каждый раз может меняться при перезапуске Local. Чтобы не обновлять руками:

и в ~/.my.cnf прописываем socket=/Users/admin/.local-mysql/mysqld.sock.

Теперь mysql и mysqldump работают без передачи -u/-p и без предупреждения про пароль.

⸻
3. SSH config (~/.ssh/config)

Создаём/дополняем файл ~/.ssh/config:

```bash

Host kreul
  HostName 51.83.33.95
  Port 2022
  User vmalakhatka
  ServerAliveInterval 15
  ServerAliveCountMax 4
  ConnectTimeout 10
  IdentityFile ~/.ssh/id_rsa

```

Теперь можно подключаться коротко:

```bash

ssh kreul
scp file.sql.gz kreul:/var/www/virtuals/kreul.com.ua/

```

4. Проверка подключения

```bash 

ssh kreul "echo connected ok"
scp /etc/hosts kreul:/tmp/

```

5. Запуск деплоя

Код → сервер:

```bash
dcode
```

База → сервер:

```bash
./export_and_push.sh
```

```text

👉 Таким образом:
	•	MySQL доступен через mysqldump без паролей;
	•	SSH идёт по алиасу kreul;
	•	Все скрипты (deploy_safe.sh, export_and_push.sh) работают без лишних флагов.

⸻

```

▶️ Экспорт и перенос

На локальном Mac, в папке wp-content проекта, запусти:

```bash
./export_and_push.sh
```

```text
Скрипт выведет прогресс:
	•	Экспорт из локальной БД → /tmp/site-YYYYMMDD-HHMM.sql.gz
	•	Копирование дампа на сервер
	•	Бэкап текущей БД на сервере → ~/backup-db-YYYYMMDD-HHMM.sql.gz
	•	Импорт дампа в БД сервера
	•	Обновление URL с http://paint.local → https://kreul.com.ua
	•	Сброс правил пермалинков и кэша

⸻

📦 Бэкапы
	•	Бэкапы базы создаются автоматически в ~ на сервере:
	backup-db-YYYYMMDD-HHMM.sql.gz

	•	При сбое всегда можно восстановить:
```

```bash
gunzip -c ~/backup-db-YYYYMMDD-HHMM.sql.gz | mysql -u aphp -p kreul
```

```text
✅ Результат

После запуска у тебя:
	•	Полная копия локальной базы на продакшене.
	•	Все виджеты, настройки и контент перенесены.
	•	Домен приведён к https://kreul.com.ua.
	•	Кэш и пермалинки обновлены.
```
</details>

<details>
<summary><strong> Перенос кода (из GitHub)</strong></summary>

```text
Скрипт
	•	deploy_safe.sh — лежит на сервере в ~/deploy_safe.sh.
	•	Исходник хранится в репозитории: wp-content/deploy_safe.sh.
	•	если отредактировал deploy_safe.sh и он уже попал на сервер в wp-content/
	•	то его надо переместить в HOME и открыть права 
```

```bash
cp -f /var/www/virtuals/kreul.com.ua/wp-content/deploy_safe.sh ~/
chmod 755 ~/deploy_safe.sh
```

Алгоритм
	1.	Код репозитория на сервере хранится в:

```bash
~/deploy/paint-shop
```
	2.	Запуск деплоя:
```bash
~/deploy_safe.sh
```

```text
Скрипт:
	•	делает git pull,
	•	показывает новые коммиты,
	•	бэкапит плагины и темы (tar.gz в ~/),
	•	синхронизирует только нужные каталоги:
	•	wp-content/mu-plugins/
	•	wp-content/themes/generatepress-child/
	•	wp-content/plugins/paint-core/
	•	wp-content/plugins/paint-shop-ux/
	•	wp-content/plugins/role-price/
	•	чистит кэш WordPress.

Полезные опции
	•	Dry run (показать, что будет скопировано, без изменений):
```
```bash
DRY_RUN=1 ~/deploy_safe.sh
```
	•	Лог: весь вывод пишется в ~/deploy.log.
```bash
tail -n 200 ~/deploy.log
```

```text
	•	Автоматическая ротация лога (хранится ≤1MB).

⸻

3. Алиасы (для удобства)

Можно добавить в ~/.bashrc или ~/.zshrc на сервере:
```
```bash
alias dcode="~/deploy_safe.sh"
alias ddb="~/deploy_db.sh site.sql.gz"
```
```md
## Операционные скрипты (server side)

Скрипты `deploy_db.sh` и `export_and_push.sh` хранятся в репозитории в `wp-content/`, но исполняются с сервера из домашней папки пользователя.

Во время деплоя `deploy_safe.sh` автоматически:
- копирует их из репозитория в `$HOME`,
- делает исполняемыми (`chmod 755`),
- добавляет алиасы (если их ещё нет):
  - `dcode` → `~/deploy_safe.sh`
  - `ddb`   → `~/deploy_db.sh site.sql.gz`

> ⚠️ Сам `deploy_safe.sh` не перезаписывается автоматически, чтобы не менять скрипт в момент его выполнения. Если нужно обновить его версию с репозитория — сделайте это вручную или держите шаблон `deploy_safe.sample.sh`.
```

```text
⚡ После этих шагов:
	•	База = как на локалке (виджеты, плагины, настройки).
	•	Код = свежий из GitHub.
	•	Домен и кэш чинятся автоматически.

```
</details>

<details>
<summary><strong> 🔒 ~/full_backup.sh — полный бэкап + ротация </strong></summary>

pull_latest_backup.sh

лежит в wp-content 
 запустить с этой директории

 ```bash
./pull_latest_backup.sh
 ```
 	3.	При необходимости переопределить параметры на лету:

```bash
PORT=2022 USER=vmalakhatka HOST=51.83.33.95 DEST_DIR=~/Downloads ~/pull_latest_backup.sh
```

или, если бэкапы лежат не в ~/backups:

```bash
REMOTE_DIR=/var/backups PATTERN="kreul-full-*.tar.gz" ~/pull_latest_backup.sh
```

```text
	•	Делает дамп БД
	•	Архивирует весь каталог WP
	•	Склеивает в один архив full-backup-YYYYmmdd-HHMMSS.tar.gz
	•	Хранит только последние 5 архивов (меняется константой RETAIN)

Сохрани на сервере в ~ и сделай исполняемым:
```
```bash
chmod +x ~/full_backup.sh
```
⏰ Поставить на расписание (раз в неделю)

Открой cron:

```bash
crontab -e
```

Добавь (вс, 04:00):
```bash
0 4 * * 0 ~/full_backup.sh >> ~/backup_cron.log 2>&1
```
⬇️ Скопировать бэкап на локальный Mac

с помощью pull_latest_backup.sh 

или вручную 

Вариант A: забрать самый свежий архив одной командой

```bash
scp -P 2022 \
"vmalakhatka@51.83.33.95:$(ssh -p 2022 vmalakhatka@51.83.33.95 'ls -1t ~/backups/full-backup-*.tar.gz | head -1')" \
~/Downloads/
```

или с докачкой через rsync

```bash
LATEST=$(ssh -p 2022 vmalakhatka@51.83.33.95 \
  'ls -1t ~/backups/full-backup-*.tar.gz | head -1')

rsync -avzP -e "ssh -p 2022" \
  "vmalakhatka@51.83.33.95:$LATEST" \
  ~/Downloads/
```

После этого архив будет в ~/Downloads/.

Вариант B: забрать все бэкапы

```bash
scp -P 2022 "vmalakhatka@51.83.33.95:~/backups/full-backup-*.tar.gz" ~/Backups/
```
(Создай каталог заранее: mkdir -p ~/Backups.)

Вариант C: через rsync (удобно для больших файлов/докачки)

```bash
rsync -avP -e "ssh -p 2022" \
  vmalakhatka@51.83.33.95:backups/full-backup-*.tar.gz \
  ~/Backups/
```
🔹 Проверить список доступных бэкапов
```bash
ls -lh ~/backups/full-backup-*.tar.gz
```
🔹 Распаковать локально (например, чтобы проверить)
```bash
cd ~/Downloads
tar -xvzf full-backup-20250904-094059.tar.gz
```
```text
Там будут:
	•	db-YYYYmmdd-HHMMSS.sql.gz — дамп базы,
	•	files-YYYYmmdd-HHMMSS.tar.gz — все файлы WordPress.
```

</details>


E-commerce проект на базе **WordPress + WooCommerce**, кастомизированный под задачи магазина красок.

## 📂 Структура проекта
<details>
<summary><strong>Структура проекта</strong></summary>

временно запуск переводов
```bash

# из папки wp-content/mu-plugins
make i18n      # полный цикл: pot -> merge -> mo
make pot       # только обновить .pot
make merge     # слить .pot в .po (создаст .po, если их нет)
make mo        # собрать .mo
make clean     # удалить .pot и .mo

```

```text

📂 Теперь схема:
	•	wp-config.php (общий загрузчик, в репо)
	•	wp-config.common.php (в репо, всё общее)
	•	wp-config.local.php (в .gitignore, локальные креды и WP_DEBUG)
	•	wp-config.production.php (в .gitignore, продакшен креды и оптимизации)

⸻

wp-content/
├─ mu-plugins/
│  ├─ 00-composer-autoload.php       # общий vendor (autoload для phpoffice/phpspreadsheet)
│  ├─ psu-force-per-page.php
│  ├─ stock-import-csv-lite.php
│  ├─ stock-locations-ui.php
│  └─ stock-sync-to-woo.php
│
├─ plugins/
│  ├─ paint-core/
│  │  ├─ assets/css/catalog-qty.css
│  │  ├─ inc/… (qty, allocator, role-price-importer, sku/gtin, stock-…)
│  │  └─ paint-core.php
│  │
│  ├─ paint-shop-ux/
│  │  └─ paint-shop-ux.php
│  │
│  ├─ role-price/
│  │  └─ role-price.php
│  │
│  ├─ pc-order-import-export/       # 🚀 новый плагин Import/Export
│  │  ├─ pc-order-import-export.php # bootstrap
│  │  ├─ inc/
│  │  │  ├─ Plugin.php              # init, ajax хуки
│  │  │  ├─ Helpers.php             # GTIN, qty, нормализация, labels
│  │  │  ├─ Exporter.php            # експорт CSV/XLSX (Cart/Order)
│  │  │  ├─ ImporterCart.php        # імпорт у кошик
│  │  │  ├─ ImporterDraft.php       # імпорт у чернетку замовлення
│  │  │  └─ Ui.php                  # кнопки, панелі, inline JS
│  │  └─ assets/
│  │     └─ pcoe.js                 # JS (можна inline)
│  │
│  └─ … інші плаґіни …
│
├─ themes/generatepress-child/
│  └─ style.css
│
└─ uploads/
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

## Архитектура управления остатками

<details>
<summary><strong>Описание</strong></summary>

```text
0) Термины и роли
	•	Склад (location) — термин таксономии location.
	•	Пересчитанный остаток товара — сумма остатков по всем складам.
	•	План списания (allocation plan) — разбиение требуемого количества по складам: { term_id => qty, ... }.
	•	Soft-резерв (виртуальный) — корзина/чекаут учитывают «план», но физический сток ещё не изменён.
	•	Hard-списание — уменьшение _stock_at_* и _stock в момент, когда заказ перешёл в processing/completed.

⸻

1) Схема хранения данных

1.1. Пер-складовые остатки (per-term)
	•	В postmeta продукта:
	•	_stock_at_{TERM_ID} — десятичное число (строка), остаток на конкретном складе.
	•	Фолбэк для вариаций: если у вариации мета пуста, читаем у родителя.

```
Хелперы:
```php
read_term_stock($product_id, $term_id): float // читает _stock_at_TERM
add_term_stock($product_id, $term_id, $delta): void // += delta, не < 0
recalc_total_stock($product_id): void // суммирует все _stock_at_* -> _stock, _stock_status
```

```text

1.2. Сводный остаток
	•	_stock — сумма по всем _stock_at_*.
	•	_stock_status — instock/outofstock по правилу (sum > 0).

recalc_total_stock() всегда обновляет оба и чистит товарные транзиенты WooCommerce.

1.3. Предпочтения списания пользователя
	•	Сессия/кука pc_alloc_pref:

```

```json

{ "mode": "auto|manual|single", "term_id": <int> }

```

```text
	Чтение/запись: pc_get_alloc_pref() / pc_set_alloc_pref().

1.4. План в корзине/заказе
	•	В корзине (эфемерно):
WC()->cart->cart_contents[$key]['pc_alloc_plan'] = {term_id=>qty,...}
	•	В заказе (строка заказа, перенос с корзины):
	•	_pc_alloc_plan — основной ключ.
	•	В заказе (флаг идемпотентности):
_pc_stock_reduced — «списание по плану уже выполнено».

⸻

2) Модули и их ответственность

2.1. Переключатель стратегии (UI + ядро)

Файл: inc/header-allocation-switcher.php
	•	Рендерит селект режимов и складов в шапке.
	•	Держит единую точку расчёта плана:
	•	pc_build_alloc_plan(WC_Product $product, int $need): array
учитывает mode/term_id, собирает остатки по всем складам (через ваши SLW-хелперы) и строит {term_id=>qty}.
	•	pc_calc_plan_for($product, $qty) — делегатор:
	1.	даёт шанс внешнему фильтру slu_allocation_plan,
	2.	иначе — pc_build_alloc_plan.
	•	Поддерживающие механизмы:
	•	AJAX pc_set_alloc_pref (сохраняет выбор).
	•	pc_recalc_alloc_plans_for_cart() — пересчитывает план для всех позиций корзины при изменении режима/склада, добавлении в корзину и изменении qty.

Дефолты безопасности: при отсутствии префов mode='auto', term_id=0.
Важно: фильтрация каталога по складу не включается, если режим не single или склад не выбран.

2.2. Cart Guard (онлайн-валидация корзины)

Файл: pc-cart-guard.php
	•	На каждом рендере/изменении клампит qty и удаляет недоступные позиции.
	•	Источник доступности:
	•	Если есть ваш pc_build_stock_view($product) — берёт суммарную доступность (sum).
	•	Иначе slu_available_for_add($product).
	•	Иначе фолбэк — Woo _stock.
	•	Обновляет max/step у qty-инпутов.
	•	Рисует мини-панель остатков по складам (если доступен slu_render_stock_panel).
	•	Включает логи, вычищает wc-cart-fragments, дергает апдейты при смене режима/склада (селекторы).
	•	Хуки:
	•	woocommerce_cart_loaded_from_session, woocommerce_before_calculate_totals,
	•	плюс AJAX-ручки для подстройки qty и мини-инфо.

2.3. Аллокатор заказа (жизненный цикл списания)

Файл: order-allocator.php (namespace PaintCore\Stock)
	•	На создании заказа: woocommerce_new_order → build_allocation_plan_for_order()
— переносит план из корзины в мету строк заказа (если план ещё не лежал).
	•	На hard-списании:
woocommerce_order_status_processing/completed → reduce_stock_from_plan():
	1.	включает «коридор записи» $GLOBALS['PC_ALLOW_STOCK_WRITE']=true,
	2.	для каждой строки заказа берёт план (из меты; если нет — пересчитает через тот же калькулятор),
	3.	уменьшает _stock_at_*, далее recalc_total_stock() (обновит _stock, _stock_status),
	4.	помечает заказ _pc_stock_reduced = yes,
	5.	закрывает коридор unset($GLOBALS['PC_ALLOW_STOCK_WRITE']);.
	•	Доп. админ-экшены в карточке заказа:
	•	«Build stock allocation plan (PaintCore)»
	•	«Reduce stock from plan (PaintCore)»
	•	«RESTORE stock from plan (PaintCore)» — обратный ход для тестов/возврата.
	•	Идемпотентность: повторное попадание на хуки списания безопасно — проверяется _pc_stock_reduced.

2.4. Барьеры и аудит записей стока

MU-плагины (рекомендуется в wp-content/mu-plugins):
	•	Stock Barrier (селективный):
update_post_metadata с высокой приоритетностью (9999):
	•	Пропускает записи:
	•	в админке/CRON,
	•	во время наших целевых хуков (processing/completed),
	•	при явном «коридоре» $GLOBALS['PC_ALLOW_STOCK_WRITE'].
	•	Блокирует записи _stock, _stock_status, _stock_at_*, если они приходят с фронта и стек указывает на «посторонние» SLW-функции (helper-slw-frontend.php, class-slw-frontend-cart.php, helper-slw-order-item.php, class-slw-order-item.php).
	•	Пишет лог [STOCK-BARRIER] ... (почти как у вас).
	•	Stock Tap (трассировка):
лёгкий логгер [STOCK-TAP] UPDATE ... trace=..., чтобы видеть, кто пытается писать и откуда.
Привязывается к тем же ключам меты; уважает общие флаги WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY.
	•	Отключение WooCommerce hold stock и автосписаний до выяснения:
```

```php
add_filter('pre_option_woocommerce_hold_stock_minutes', '__return_empty_string', 9999);
add_filter('woocommerce_can_reduce_order_stock', '__return_false', 9999);
remove_action('woocommerce_checkout_order_processed', 'wc_maybe_reduce_stock_levels', 10);
remove_action('woocommerce_payment_complete',          'wc_maybe_reduce_stock_levels', 10);
remove_action('woocommerce_order_status_processing',   'wc_maybe_reduce_stock_levels', 10);
remove_action('woocommerce_order_status_completed',    'wc_maybe_reduce_stock_levels', 10);

```
Итог: все внешние «самодеятельные» попытки менять сток на фронте блокируются, а наши целевые процедуры проходят.

3) Потоки (приход, резерв, расход)

3.1. Приход (оприходование)

В админке или скриптом:

```php

add_term_stock($product_id, $term_id, +$qty);
recalc_total_stock($product_id);

```
Рекомендация: делать батчами (bulk) и один общий recalc_total_stock() на позицию после всех add_term_stock().

```text
3.2. Резервирование (soft)
	•	Не используем Woo hold stock.
	•	Резерв — виртуальный: корзина и чекаут считают план через pc_calc_plan_for() и клампят qty по доступности; мета _stock* не меняется.
	•	UI может показывать «план» и доступности (мини-панели).

Плюсы: нет подвисших резервов, нет гонок/таймаутов, чёткий момент расхода.

3.3. Расход (hard) — докручено

Момент: смена статуса заказа на processing или completed.

Алгоритм reduce_stock_from_plan($order_or_id):
	1.	«Коридор записи»: $GLOBALS['PC_ALLOW_STOCK_WRITE']=true.
	2.	Для каждого WC_Order_Item_Product:
	•	получить $plan = ensure_item_plan($item)
(из меты _pc_alloc_plan или пересчитать: внешний slu_allocation_plan → pc_calc_plan_for() → pc_build_alloc_plan()).
	•	для каждой пары (term_id => qty):
	•	add_term_stock($pid, $term_id, -$qty).
	3.	Для всех затронутых продуктов: recalc_total_stock($pid).
	4.	Поставить флаг _pc_stock_reduced = yes на заказ (идемпотентность).
	5.	Закрыть коридор: unset($GLOBALS['PC_ALLOW_STOCK_WRITE']);.

Свойства:
	•	Идемпотентно (повторный вызов — no-op).
	•	Барьер не мешает (коридор + целевые хуки).
	•	Совместимо с ручными админ-действиями «Reduce from plan».

3.4. Возврат/отмена (restore)

Админ-экшен «RESTORE stock from plan (PaintCore)»:
	•	для каждой строки заказа берёт план и делает add_term_stock(..., +qty);
	•	recalc_total_stock($pid);
	•	очищает _pc_stock_reduced.

Примечание: для частичного возврата — оформить частичный план или провести оприходование только нужных позиций.

4) Фильтрация каталога по складу (опционально)

Если нужно показывать только товары выбранного склада, делаем это в pre_get_posts, строго:
	•	Только !is_admin() и $q->is_main_query().
	•	Только страницы каталога: is_shop() || is_product_category() || is_product_tag().
	•	Только если mode==='single' и term_id>0.
	•	Остальные запросы (REST/AJAX/виджеты/поиск) игнорируем.

Добавляем условия (пример через таксономию location, или через meta_query по _stock_at_TERM > 0 — по ситуации).

⸻

5) Публичные интерфейсы (API/хуки)

5.1. Функции

```

```php

// Преференции
pc_get_alloc_pref(): array
pc_set_alloc_pref(array $pref): void

// Расчёт планов
pc_build_alloc_plan(WC_Product $p, int $need): array
pc_calc_plan_for(WC_Product $p, int $qty): array

// Корзина
pc_recalc_alloc_plans_for_cart(): void

// Заказ
PaintCore\Stock\build_allocation_plan_for_order($order_or_id): void
PaintCore\Stock\reduce_stock_from_plan($order_or_id): void

// Остатки
PaintCore\Stock\read_term_stock(int $pid, int $tid): float
PaintCore\Stock\add_term_stock(int $pid, int $tid, float $delta): void
PaintCore\Stock\recalc_total_stock(int $pid): void

```

```text

5.2. Хуки
	•	add_filter('slu_allocation_plan', callable $fn, 10, 4)
$fn($plan, WC_Product $product, int $need, string $context) → {term_id=>qty} или пусто.
Контексты: 'frontend-preview', 'order-fallback' (и т.п.).
	•	Хуки Woo:
	•	woocommerce_checkout_create_order_line_item — перенос плана из корзины.
	•	woocommerce_new_order — штамповка плана в строках.
	•	woocommerce_order_status_processing/completed — списание.

⸻

6) Структура данных и миграции

6.1. Хранимые ключи/типы
	•	На продукте:
	•	_stock_at_{TERM_ID} — DECIMAL(12,3) как строка меты (Woo хранит строки).
	•	_stock — DECIMAL(12,3) строкой.
	•	_stock_status — instock|outofstock.
	•	В строке заказа:
	•	_pc_alloc_plan — array<int,int>.
	•	_stock_location_id/_slug — первый склад из плана (для совместимости с отчётами).
	•	На заказе:
	•	_pc_stock_reduced — yes|null.

6.2. Миграции
	•	Инициализация: для каждого товара с заведёнными складами — выставить _stock_at_* и выполнить recalc_total_stock().
	•	Изменение структуры не требуется (используем postmeta).
	•	Первичный пересчёт: скрипт-одноразка, который суммирует legacy-останк и раскладывает по складам (если нужно).

⸻

7) Конфигурация / переменные окружения
	•	WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY — управляют объёмом логов; на проде: false/false/false (или LOG=true только на время расследования).
	•	Константы в MU-плагинах:
	•	тумблеры логов (PC_CART_GUARD_DEBUG и т.п.).
•	Настройки Woo:
	•	hold_stock_minutes → принудительно пусто на фронте.
	•	Отключены вызовы wc_maybe_reduce_stock_levels на фронтовых событиях.

8) Логи, аудит, мониторинг
	•	Stock Tap:
[STOCK-TAP] UPDATE post={id} key={meta} old={x} new={y} user={id} url={uri} trace={stack}
Включать на локалке / временно на проде.
	•	Stock Barrier:
[STOCK-BARRIER] blocked (SLW): post=... key=... trace=...
	•	Cart-guard: pc(cart): ... — помогает отлавливать клампы, max, пересчёты.
	•	Рекомендуется настроить сбор логов в централизованный storage и фильтры по префиксам.

KPI/алерты:
	•	Несоответствие суммы _stock_at_* и _stock (порог > 0.001).
	•	Появление STOCK-BARRIER blocked > N за окно времени.
	•	Попытка повторного списания заказа без _pc_stock_reduced.

9) Тест-кейсы / чек-листы

9.1. Юниты (можно как интеграционные скрипты)
	•	pc_build_alloc_plan():
	•	mode=auto|manual|single, разные комбинации остатков, проверка приоритета primary и выбранного склада.
	•	reduce_stock_from_plan():
	•	Идемпотентность (второй вызов ничего не меняет).
	•	Несколько строк заказа/товаров.
	•	Отрицательные сценарии: пустой план, нулевые остатки.

9.2. E2E сценарии
	•	Soft-резерв: добавить товар(ы) в корзину, менять режим/склад — qty клампится, _stock* не меняется.
	•	Hard-списание: создать заказ COD → статус processing → _stock_at_* убывает, _stock/_stock_status пересчитываются.
	•	Restore: нажать «RESTORE» → остатки возвращены, _pc_stock_reduced очищен.
	Барьер: дернуть фронтовые пути SLW (cart/order-item helpers) и убедиться по логам, что записи _stock/_stock_status/_stock_at_* блокируются ([STOCK-BARRIER] blocked …).
	•	Каталог: включить mode=single + выбрать конкретный склад → список товаров в каталоге должен ограничиться этим складом. При mode=auto/manual список остаётся полным.
	•	Смешанный заказ: добавить в корзину товары с разными складами → при оформлении заказа план в строках заказа должен отражать разбиение по складам.
	•	Массовый импорт: залить CSV с приходом (несколько складов, один товар) → после add_term_stock + recalc_total_stock сумма должна совпадать.

⸻

10) FAQ

Q: Можно ли использовать это как «полную схему»?
A: Да — покрывает все уровни: хранение остатков, приход, soft-резерв, hard-списание, возврат, фильтрацию каталога, барьеры и аудит.

Q: Что если план не записался в строку заказа?
A: ensure_item_plan() пересчитает по тем же правилам (сначала внешний фильтр slu_allocation_plan, потом наш калькулятор).

Q: Почему не используем WooCommerce hold stock?
A: Чтобы не оставались подвисшие резервы и не было гонок между пользователями. Soft-резерв реализован через кламп корзины, а реальное списание — один раз по событию статуса заказа.

Q: Как защититься от сторонних автозаписей _stock?
A: MU-плагин-барьер (update_post_metadata с приоритетом 9999) блокирует любые нежелательные записи на фронте. Наши процедуры открывают «коридор записи» $GLOBALS['PC_ALLOW_STOCK_WRITE'].

Q: Как быстро принять приход по CSV?
A: Для каждой строки product_id, term_id, delta:

```		

```php

add_term_stock($product_id, $term_id, +$delta);
recalc_total_stock($product_id);

```
После батча — проверить, что _stock совпадает с суммой _stock_at_*.

⸻
```text

1) Приложение A — Последовательности

Чекаут (COD):
	1.	Пользователь выбирает режим/склад → сохраняется pc_alloc_pref.
	2.	Корзина пересчитывает планы (pc_recalc_alloc_plans_for_cart) и клампит qty.
	3.	При создании заказа планы переносятся в мету строк.
	4.	Статус processing → reduce_stock_from_plan():
	•	открыть ALLOW_WRITE,
	•	списать add_term_stock(-qty) по плану,
	•	recalc_total_stock(),
	•	отметить заказ _pc_stock_reduced=yes,
	•	закрыть ALLOW_WRITE.

Возврат:
	•	Админ выбирает «RESTORE» → add_term_stock(+qty) → recalc_total_stock() → _pc_stock_reduced удаляется.

```

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
<summary><strong>PC Order Import/Export</strong></summary>

```markdown
# PC Order Import/Export

Плагін для WooCommerce: експорт кошика/замовлень у CSV/XLSX + імпорт у кошик/чернетку.

## 📦 Востановлення
1. Скопіювати каталог `pc-order-import-export` у `wp-content/plugins/`.
2. Активувати плагін у WordPress → Плагіни.
3. Для XLSX потрібен пакет [`phpoffice/phpspreadsheet`](https://phpspreadsheet.readthedocs.io).

## 📤 Експорт
- Доступні формати: **CSV** і **XLSX**.
- Параметри:
  - вибір колонок (SKU, GTIN, Name, Qty, Price, Total, Note);
  - режим split: `agg` (зведено) або `per_loc` (по складах з колонкою Note).

## 📥 Імпорт

### У кошик
- Додаються лише товари, що є на складі.
- Перевіряються `min/max` і залишок.
- Якщо немає на складі — рядок пропускається.

### У чернетку замовлення
- Створюється замовлення зі статусом **Чернетка (імпорт)**.
- Додаються всі товари незалежно від залишків.
- Єдина перевірка: кількість > 0.
- Email-повідомлення не відправляються.

## 📑 Формат CSV/XLSX
Мінімум дві колонки:

sku;qty
gtin;qty


# PC Order Import/Export

Плагін для WooCommerce, що додає експорт та імпорт кошика/замовлень у CSV/XLSX.

## Можливості

- **Експорт**
  - Кошик або окреме замовлення
  - Формати: CSV (UTF-8, `;`) або XLSX (через PhpSpreadsheet)
  - Налаштовувані колонки (SKU, GTIN, Назва, К-сть, Ціна, Сума, Примітка)
  - Режими: «Загальна» або «По складах» (split per location)
  - Пам’ятає вибір користувача (localStorage)

- **Імпорт**
  - Імпорт у **кошик** (з урахуванням складів, залишків, мін/макс)
  - Імпорт у **чернетку замовлення** (новий статус `wc-pc-draft`)
    - Додає всі позиції незалежно від наявності на складі
    - Підходить для «шаблонів замовлень» чи попередніх заявок
    - Не надсилає емейли

- **Формат CSV**

```
sku;qty
gtin;qty
```
•	Минимум без заголовков

```csv
CR-CE0900056400;3
CR-CE0900056428;10
```

→ трактуется как sku;qty.

	•	С заголовками (рекомендуется)

```csv
Артикул;Кількість
CR-CE0900056400;3
CR-CE0900056428;10
```

Поддерживаемые названия колонок

Плагин нормализует заголовки (нижний регистр, убирает пробелы, варианты на укр/рус/англ).
Для каждой логической колонки есть несколько допустимых вариантов:

Поле				Примеры заголовков
SKU				sku, артикул, код, product_sku, товар
GTIN			gtin, штрихкод, ean, ean13, barcode, UPC
Кількість (qty)	qty, кількість, кол-во, к-сть, quantity, amount, q
Ціна (price)	price, ціна, стоимость, unit price, cena

Алгоритм
	1.	Если заголовков нет → считаем, что первые две колонки это sku;qty.
	2.	Если заголовки есть → ищем совпадения по таблице выше.
	3.	Остальное (например name, note) можно включать, но оно будет проигнорировано при импорте.

⸻

Пример «гибкого» файла

```csv
ean13,amount,unit price
4820035801234,5,124.00
4820035805678,2,248.00

Код;К-сть;Ціна
CR-CE0900056400;3;124.00
CR-CE0900056428;10;124.00
```

оба корректны 👍

- Розділювач `;` або `,`
- Дробові: крапка або кома
- Тисячні пробіли і не-знак ігноруються

## Інтеграція

- М’яка залежність від WooCommerce
- PhpSpreadsheet тягнеться через загальний `wp-content/vendor`  
(autoload у `mu-plugins/00-composer-autoload.php`)

## Статус

- Версія: 1.0.0
- Автор: PaintCore
- Ліцензія: GPL-2.0+


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

🔎 Поиск и подсветка (Relevanssi + MU)
<details>
<summary><strong>6) psu-search-filters.php — Поиск и подсветка </strong></summary>

```text
Что даёт:
	•	Релевантный поиск (через плагин Relevanssi).
	•	Подсветка найденных слов в заголовках карточек на странице поиска.
	•	(Опц.) Базовые фильтры ?location= и ?in_stock=1 для витрин Woo.

```
1) MU-плагин: wp-content/mu-plugins/psu-search-filters.php
2) Тема (child): wp-content/themes/generatepress-child/functions.php

```php

// Сниппет Relevanssi под заголовком карточки в выдаче поиска
add_action('woocommerce_after_shop_loop_item_title', function(){
    if (!is_search()) return;
    if (!function_exists('relevanssi_the_excerpt')) return;
    echo '<div class="relevanssi-snippet" style="margin:.35rem 0 .5rem; font-size:.9em; color:#555;">';
    relevanssi_the_excerpt();
    echo '</div>';
}, 8);

```

3) Тема (child): style.css — подсветка найденных слов

```css
/* === Search / Relevanssi highlights === */
.relevanssi-query-term{
  font-weight: 700;        /* жирный */
  background: #fff3a6;     /* мягкая жёлтая подложка */
}
/* === End Search === */

```

4) Рекомендации по настройке Relevanssi

```text
	1.	Indexing → Post types: включить product.
	2.	Indexing → Custom fields: добавить _sku (если хотите искать по артикулу).
	3.	Searching → Default operator: обычно AND (точнее по фразам).
	4.	Excerpts and highlights:
	•	включить Custom excerpts и Highlighting search terms;
	•	можно оставить тип <strong> или стиль/класс не трогать (мы подсвечиваем своим классом).
	5.	Build the index (первый раз — вручную, потом индекс обновляется автоматически).
```

</details>
## 🔌 Плагины
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
| `PSU_COLS_DESKTOP`  | управлять колонками PHP-ом (не используется; сетка у темы) | `0` (=выкл) |
| `PSU_IMG_H_DESKTOP` | высота изображения в каталоге, px | `210` |
| `PSU_IMG_H_TABLET`  | высота на планшете, px | `190` |
| `PSU_IMG_H_MOBILE`  | высота на мобилке, px | `180` |
| `PSU_TITLE_RESERVE` | длина компактного названия (если нет «\|») | `25` |

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


```text
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
5) новоописание Розподіл і фактичне списання залишків (уніфіковано)
Файл: wp-content/plugins/paint-core/inc/order-allocator.php

Що робить
	•	План у рядках замовлення: на етапі створення замовлення записує:
	•	_pc_alloc_plan (джерело з хедера/свічера),
	•	видиму мету “Склад: Київ × N, Одеса × M”,
	•	_stock_location_id/_slug (перший реально використаний склад).
	•	Реальне списання: читає план і зменшує _stock_at_{term_id} для всіх позицій, потім перераховує _stock та _stock_status.
Антидубль: _pc_stock_reduced = yes.

Хуки
	•	Побудова плану: woocommerce_new_order (priority 5).
	•	Редукція (ОДИН раз):
woocommerce_order_status_processing (60),
woocommerce_order_status_completed (60).

На checkout_order_processed списань більше немає.

Переозначення алгоритму
	•	Фільтр slu_allocation_plan — якщо повернути масив [term_id => qty], буде використано ваш розподіл.
	•	Якщо фільтр нічого не дав — використовується централізований калькулятор з header-allocation-switcher.php (функція pc_calc_plan_for()).

Ручні дії в адмінці
	•	Build stock allocation plan (PaintCore) — перебудувати план і мети рядків.
	•	Reduce stock from plan (PaintCore) — примусово списати.
	•	RESTORE stock from plan (PaintCore) — відкотити списання (повернути залишки) і прибрати мету _pc_stock_reduced.

Залежності/сумісність
	•	Поля залишків по складах: _stock_at_{TERM_ID}.
	•	Загальний _stock рахується як сума по _stock_at_*.
	•	Стандартне Woo-списання вимкнено (ти вже видалив wc_maybe_reduce_stock_levels) + hold_stock_minutes = порожньо.новоописание 



5) старое описание Распределение и реальное списание остатков

Файл: wp-content/5) Распределение и реальное списание остатков

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
Задача:
	•	На этапе оформления/статуса строит план списания по складам для каждой строки заказа
→ мета _pc_stock_breakdown = [ term_id => qty, ... ], видимая мета(старая уже) “Склад: Київ × N, Одеса × M”, _stock_location_id/_slug.
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
```

## 1) Удаляем дубли _stock_at_*, оставляя последнюю запись

```sql

-- A. Посмотреть, что именно удалим (превью TOP 20)
SELECT pm.post_id, pm.meta_key, pm.meta_id, pm.meta_value
FROM wp_postmeta pm
JOIN (
  SELECT post_id, meta_key, MAX(meta_id) AS keep_id
  FROM wp_postmeta
  WHERE meta_key REGEXP '^_stock_at_[0-9]+$'
  GROUP BY post_id, meta_key
  HAVING COUNT(*) > 1
) d ON d.post_id = pm.post_id AND d.meta_key = pm.meta_key
WHERE pm.meta_id <> d.keep_id
ORDER BY pm.post_id, pm.meta_key
LIMIT 20;

-- B. Удаление дублей (оставляем запись с максимальным meta_id)
DELETE pm
FROM wp_postmeta pm
JOIN (
  SELECT post_id, meta_key, MAX(meta_id) AS keep_id
  FROM wp_postmeta
  WHERE meta_key REGEXP '^_stock_at_[0-9]+$'
  GROUP BY post_id, meta_key
  HAVING COUNT(*) > 1
) d ON d.post_id = pm.post_id AND d.meta_key = pm.meta_key
WHERE pm.meta_id <> d.keep_id;

```

## 2) Синхронизируем _stock с суммой по складам

Оставляем _stock (вы сами писали, что на него кое-что ссылается), но приводим его к сумме _stock_at_%.

```sql

-- Обновить _stock там, где он уже есть
UPDATE wp_postmeta s
JOIN (
  SELECT post_id, SUM(CAST(meta_value AS DECIMAL(10,3))) AS sum_qty
  FROM wp_postmeta
  WHERE meta_key LIKE '_stock_at\_%'
  GROUP BY post_id
) t ON t.post_id = s.post_id
SET s.meta_value = t.sum_qty
WHERE s.meta_key = '_stock';

-- Создать _stock там, где его нет, но есть _stock_at_*
INSERT INTO wp_postmeta (post_id, meta_key, meta_value)
SELECT t.post_id, '_stock', t.sum_qty
FROM (
  SELECT post_id, SUM(CAST(meta_value AS DECIMAL(10,3))) AS sum_qty
  FROM wp_postmeta
  WHERE meta_key LIKE '_stock_at\_%'
  GROUP BY post_id
) t
LEFT JOIN wp_postmeta s
  ON s.post_id = t.post_id AND s.meta_key = '_stock'
WHERE s.post_id IS NULL;

```

## 3) Быстрая проверка

```sql

-- Должно вернуть 0 строк (нет дублей)
SELECT post_id, meta_key, COUNT(*) c
FROM wp_postmeta
WHERE meta_key REGEXP '^_stock_at_[0-9]+$'
GROUP BY post_id, meta_key
HAVING c > 1
ORDER BY post_id, meta_key;

-- Проверить конкретный проблемный товар (пример: 17410)
SELECT meta_key, meta_value
FROM wp_postmeta
WHERE post_id=17410
  AND (meta_key='_stock' OR meta_key LIKE '_stock_at\_%')
ORDER BY meta_key;

```
