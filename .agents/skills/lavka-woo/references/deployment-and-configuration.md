# Разработка, конфигурация и deploy

Этот регламент является источником истины для собственных WordPress-плагинов Lavka/Kreul. Проверено по `wp-content/deploy_safe.sh`, `wp-content/deploy_plugins.list`, `wp-content/update_ops.sh` и `README.md`: 2026-08-24.

## Окружения

- Локальная копия: корень репозитория `paint.local`; перед WP-CLI убедись, что Local и его MariaDB запущены.
- Production WordPress: `/var/www/virtuals/kreul.com.ua`.
- Production checkout репозитория: `~/deploy/paint-shop`.
- Запускаемый production deploy: `~/deploy_safe.sh`.
- Версионный источник deploy-скрипта на production: `~/deploy/paint-shop/wp-content/deploy_safe.sh`.
- Скрипты, которые deploy переносит в `$HOME`: `deploy_db.sh` и `export_and_push.sh`.
- `update_ops.sh` умеет вручную переносить также `deploy_safe.sh` и `full_backup.sh`.

Не считать ошибку соединения WP-CLI с локальной MariaDB ошибкой плагина, пока Local не запущен.

WordPress deploy не выполняется из локального Docker/Java `deploy.sh`. Для этого сайта
локальная машина отправляет код в Git, а production-сервер сам делает
`git pull --ff-only` из `~/deploy/paint-shop` и синхронизирует выбранные каталоги.

## SSH-алиас и первый запуск

Локальный алиас `kreul` должен быть описан в `~/.ssh/config`; hostname, пользователь,
порт и ключ берутся только из этой конфигурации и не записываются в skill. До deploy:

```bash
ssh -G kreul | grep -E '^(hostname|user|port|identityfile) '
ssh -o ConnectTimeout=10 kreul 'echo connected ok'
```

Если SSH даёт timeout, deploy не начинать: код, manifest и конфигурация ещё не
передавались, а production не менялся. Проверку firewall/sshd/allow-list выполняет
администратор сервера.

### Где находится deploy-скрипт и как сделать его запускаемым

Запускаемая production-копия: `~/deploy_safe.sh`. Её единственный версионный источник
после `git pull`: `~/deploy/paint-shop/wp-content/deploy_safe.sh`.

Файл `/var/www/virtuals/kreul.com.ua/wp-content/deploy_safe.sh` не участвует в
запуске `dcode` и специально не синхронизируется как часть WordPress web-root. Его
дата не является признаком актуальности production deploy. Это подтверждено на
production 2026-08-24: Git checkout был актуален, а файл в web-root оставался старым.

При первом bootstrap или если запускаемая копия старее механизма self-update, на
сервере установи её именно из checkout:

```bash
ssh kreul
cp -f ~/deploy/paint-shop/wp-content/deploy_safe.sh ~/deploy_safe.sh
chmod 755 ~/deploy_safe.sh
```

После bootstrap текущий deploy не переносит сам себя в начале запуска. В конце он
сравнивает checkout-источник с `~/deploy_safe.sh`, создаёт `~/deploy_safe.sh.next`,
выполняет `chmod 755` и заменяет старую копию. Поэтому изменение deploy-алгоритма
вступает в силу со следующего запуска. Старая версия, в которой этого блока ещё нет,
сама обновиться не сможет: используй bootstrap-команду выше, затем запускай `dcode`.

### Серверные алиасы

Успешный `deploy_safe.sh` идемпотентно добавляет в `~/.bashrc`:

```bash
alias dcode="~/deploy_safe.sh"
alias ddb="~/deploy_db.sh site.sql.gz"
alias dcode-dry="DRY_RUN=1 ~/deploy_safe.sh"
```

Также добавляются `. ~/.bashrc` в `~/.bash_profile` и `umask 002` в `~/.bashrc`.
После первого добавления выполни `source ~/.bashrc` или заново подключись. `ddb` —
сокращение только для дампа `site.sql.gz`; другой файл передавай явно:
`~/deploy_db.sh <dump-name>`.

## Конфигурация и `.env`

Проверено локально и на production 2026-08-24: WordPress сейчас не использует файлы
`.env`, `.env.local` или `.env.prod` и не загружает Dotenv. Фактическая схема:

- `wp-config.php` — router окружения; отслеживается Git;
- `wp-config.common.php` — общие constants; отслеживается Git;
- `wp-config.local.php` — локальная БД/debug/paths; существует локально и игнорируется Git;
- `wp-config.production.php` — production БД/Redis/log paths; существует только в
  рабочем web-root production и игнорируется Git.

`deploy_safe.sh` не синхронизирует корневые `wp-config*.php`. Поэтому версии
`wp-config.php` и `wp-config.common.php` в production web-root могут отличаться от
checkout `~/deploy/paint-shop`; deploy plugin-кода это не исправляет. Перед изменением
конфигурации сравнивай структуру без вывода значений и применяй отдельный
preview/backup/config-deploy сценарий.

Подтверждённый долг безопасности: отслеживаемый `wp-config.common.php` содержит
WordPress authentication salts/keys и cache salt как литералы. Не добавлять туда новые
секреты. Эти значения считать попавшими в Git history; переносить их в server
environment или отдельный ignored configuration, затем ротировать с пониманием, что
смена WordPress salts завершит активные пользовательские сессии.

Целевая модель: tracked `wp-config.php` и `wp-config.common.php` содержат только
несекретную логику/defaults; локальные и production secrets приходят из environment
или отдельных ignored файлов с минимальными правами. Не помещать production `.env`
в публичный web-root. Если выбирать Dotenv, сначала добавить проверенный loader и
хранить файл вне document root; одного создания `.env.prod` недостаточно.

Не переносить секреты через plugin commit, manifest, HTML или обычный лог. После
добавления новой интеграции отдельно настроить на production нужные constants/env/options
и только потом активировать plugin.

В этом репозитории WordPress не использует `.env.local`/`.env.prod`. Фактическая
схема конфигурации такая:

- `wp-config.php` — отслеживаемый Git загрузчик окружения;
- `wp-config.common.php` — отслеживаемые общие несекретные настройки;
- `wp-config.local.php` — локальные секреты и настройки, исключён Git;
- `wp-config.production.php` — production-секреты и настройки, исключён Git;
- `wp-config.staging.php` — аналогично для staging, если окружение создано.

При наличии `wp-config.local.php` загрузчик автоматически выбирает `local`, иначе —
`production`, если `WP_ENVIRONMENT_TYPE` не задан заранее. Поэтому секрет новой
WordPress-интеграции добавляй отдельно в локальный и серверный environment-specific
PHP-конфиг, а не в отслеживаемые `wp-config.php`/`wp-config.common.php`.

Для `paint-nova-poshta-multishipping`:

```php
define('PNPM_NOVA_POSHTA_API_KEY', 'secret-from-this-environment');
define('PNPM_ALLOW_REAL_TTN', false);
```

`PNPM_NOVA_POSHTA_API_KEY` также может поступать из настоящего server environment,
доступного PHP через `getenv()`, но один лишь файл `.env` WordPress автоматически не
загружает. `PNPM_ALLOW_REAL_TTN` текущая версия плагина читает только как PHP-константу;
до отдельного production-разрешения оставляй `false` или не определяй вообще.

Для Java/Docker-проекта используется отдельный deploy-скрипт и отдельные env-файлы;
не смешивай его `.env.prod` с WordPress deploy этого skill.

### Безопасный перенос `wp-config.production.php`

Обычный `dcode` не переносит environment-specific конфиги. Если локальная
административная копия `wp-config.production.php` обновлена, передавай её отдельной
операцией через SSH, не добавляя файл в Git и не печатая его содержимое:

```bash
cd '/Users/admin/Local Sites/paint/app/public'
php -l wp-config.production.php
scp wp-config.production.php \
  kreul:/var/www/virtuals/kreul.com.ua/wp-config.production.php.next
ssh kreul
```

На сервере сначала проверь staged-файл, затем создай закрытую резервную копию и
только после этого атомарно замени рабочий конфиг:

```bash
/opt/remi/php83/root/bin/php -l \
  /var/www/virtuals/kreul.com.ua/wp-config.production.php.next

backup="$HOME/wp-config.production.php.backup-$(date +%Y%m%d-%H%M%S)"
cp -p /var/www/virtuals/kreul.com.ua/wp-config.production.php "$backup"
chmod 600 "$backup"
echo "Backup: $backup"

chgrp apache /var/www/virtuals/kreul.com.ua/wp-config.production.php.next
chmod 640 /var/www/virtuals/kreul.com.ua/wp-config.production.php.next
mv -f /var/www/virtuals/kreul.com.ua/wp-config.production.php.next \
  /var/www/virtuals/kreul.com.ua/wp-config.production.php
```

После замены проверяй только факт наличия constants и безопасное значение write-флага,
не значение API-ключа. Для Nova Poshta:

```bash
/opt/remi/php83/root/bin/php /bin/wp-cli.phar \
  --path=/var/www/virtuals/kreul.com.ua eval '
echo defined("PNPM_NOVA_POSHTA_API_KEY") && PNPM_NOVA_POSHTA_API_KEY !== ""
    ? "Nova Poshta key configured\n" : "Nova Poshta key missing\n";
echo defined("PNPM_ALLOW_REAL_TTN") && PNPM_ALLOW_REAL_TTN === false
    ? "Real TTN disabled\n" : "Check real TTN flag\n";
'
```

Если проверка WordPress после замены не проходит, восстанови **конкретный** путь к
backup, напечатанный в серверной сессии, и повтори PHP lint. Не используй wildcard
для выбора резервной копии. Активация plugin является отдельным production-решением
и выполняется только после успешной проверки constants и read-only API.

## Источники конфигурации

Используй уровень по назначению:

1. Секреты и аварийные write-флаги: server environment или `wp-config.php`; никогда не Git/options/HTML/log.
2. Обычные настройки плагина: WordPress options с capability, nonce и sanitization.
3. Связи сущностей: term/user/order meta либо собственные таблицы согласно владельцу данных.
4. Изменяемые Java endpoint/timeout: существующая server-side proxy/configuration, не frontend JavaScript.
5. Переводы: английский `msgid` в коде, PO/MO в Git.

Для новой внешней интеграции документируй точные constants/env names, safe defaults и необходимость перезапуска процесса. Флаг реальной записи по умолчанию должен быть выключен.

## Единый manifest собственных плагинов

`wp-content/deploy_plugins.list` — единственный список обычных собственных плагинов, которые `deploy_safe.sh` копирует на production.

Формат:

```text
plugin-slug|manual
plugin-that-must-stay-active|ensure-active
```

- `manual`: файлы синхронизируются, но активацию/деактивацию deploy не меняет.
- `ensure-active`: после копирования deploy выполняет `wp plugin activate`; использовать только для давно обязательного и безопасного bootstrap-плагина.
- Не ставить новой, финансовой, платёжной или логистической интеграции `ensure-active` до локальной приёмки и явного решения владельца.

Manifest валидирует slug, политику и наличие source-каталога. Один список используется для `mkdir`, `rsync --delete`, прав и активации, поэтому slug больше не надо дублировать в нескольких блоках shell-скрипта.

## Lifecycle нового обычного плагина

### 1. Создание

- Создай `wp-content/plugins/<slug>/<slug>.php` и отдельный text domain.
- Для Woo order data объяви HPOS compatibility и используй Woo CRUD.
- Миграции делай идемпотентно через activation/version check; destructive migration требует backup и отдельного плана.
- Реальные внешние записи блокируй safe default и явным подтверждением.

### 2. Git и deploy registration

Предпочтительный автоматизированный способ из корня репозитория:

```bash
bash .agents/skills/lavka-woo/scripts/register-plugin-deploy.sh <slug>
```

Helper только регистрирует каталог в Git/deploy с безопасной политикой `manual`. Он не активирует плагин, не запускает deploy и не изменяет production. `ensure-active` можно передать вторым аргументом только после отдельной оценки риска.

Ручной эквивалент:

- Добавь в `.gitignore` исключения каталога:

```gitignore
!wp-content/plugins/<slug>/
!wp-content/plugins/<slug>/**
```

- Добавь `<slug>|manual` в `wp-content/deploy_plugins.list`.
- Проверь:

```bash
git check-ignore -v wp-content/plugins/<slug>/<slug>.php || true
git status --short --untracked-files=all -- wp-content/plugins/<slug>
```

Если первая команда печатает правило ignore, registration не завершён.

### 3. Локальная приёмка

Минимум:

```bash
find wp-content/plugins/<slug> -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check
wp plugin activate <slug>
wp plugin status <slug>
```

Затем проверь activation tables/options/capabilities, HPOS order screen, nonce/capability, i18n, AJAX loading/error, desktop/mobile и отсутствие реальных внешних записей в test/safe режиме. Если Local DB выключена, зафиксируй локальную активацию как **не выполнено**, а не как успешную.

### 4. Commit и push

- Не включай чужие незавершённые изменения.
- В коммит должны попасть plugin, `.gitignore`, manifest, переводы, документация и нужные deploy-изменения.
- После push проверь `git log -1` и что production branch может выполнить fast-forward.

### 5. Production deploy

До изменения production:

```bash
DRY_RUN=1 ~/deploy_safe.sh
```

Проверь список нового плагина и отсутствие неожиданных удалений. Затем:

```bash
~/deploy_safe.sh
```

С локального компьютера тот же сценарий запускается через SSH-алиас:

```bash
ssh kreul 'DRY_RUN=1 ~/deploy_safe.sh'   # preview
ssh kreul '~/deploy_safe.sh'             # apply после проверки
```

Или на открытой серверной SSH-сессии:

```bash
dcode-dry
dcode
```

`DRY_RUN=1` переводит `rsync` в режим `-n`, но не гарантирует строгий no-side-effect:
скрипт всё ещё может выполнить `git pull`, создать каталоги и выполнить подготовочные
проверки. Перед критическим изменением дополнительно проверяй diff и backup.

Важный bootstrap-нюанс: текущий `~/deploy_safe.sh` обновляет сам себя только в конце запуска. Это работает только после установки версии с self-update. Если сервер ещё использует более старый скрипт, сначала выполни bootstrap из раздела выше. После этого при изменении deploy-алгоритма первый запуск подтянет новую версию для следующего запуска, второй применит её:

```bash
~/deploy_safe.sh
~/deploy_safe.sh
```

После того как новая версия уже установлена, обычному plugin-only коммиту достаточно одного запуска.

## Отдельный deploy базы и контента

`export_and_push.sh` и `deploy_db.sh` не нужны для обычного обновления plugin-кода.
Они переносят полную локальную базу и поэтому являются отдельной потенциально
разрушающей операцией.

Предварительно запусти Local и проверь локальный MySQL socket, `mysql`/`mysqldump`,
а также доступ по SSH. Из `wp-content` локального проекта:

```bash
./export_and_push.sh
```

Скрипт создаёт локальный сжатый dump, делает до трёх попыток `scp`, запускает на
сервере `~/deploy_db.sh site.sql.gz`, сохраняет текущую production БД, импортирует
дамп, меняет URL на production и сбрасывает cache/permalinks. Для проверки доставки
без импорта:

```bash
RUN_REMOTE_IMPORT=no ./export_and_push.sh
```

Ручной серверный импорт уже загруженного файла:

```bash
ssh kreul '~/deploy_db.sh site.sql.gz'
```

Перед импортом требуется подтверждение владельца и backup. Скрипт `deploy_db.sh`
берёт DB-реквизиты из production `wp-config.php`, хранит DB-backup в
`/mnt/backup/backups_kreul` (или `$HOME`, если каталог недоступен) и оставляет три
последних backup. Не применять этот сценарий для деплоя одного plugin.

### Как новый plugin попадает в deploy

Из корня WordPress-репозитория:

```bash
bash .agents/skills/lavka-woo/scripts/register-plugin-deploy.sh <plugin-slug>
```

Helper добавляет каталог в Git allow-list `.gitignore` и строку
`<plugin-slug>|manual` в `wp-content/deploy_plugins.list`. Он не активирует plugin,
не запускает production deploy и не меняет production. Для новых, финансовых,
платёжных и логистических plugins сохраняй `manual`.

Минимальный сценарий после регистрации:

```bash
find wp-content/plugins/<plugin-slug> -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check
git status --short --untracked-files=all
git add wp-content/plugins/<plugin-slug> wp-content/deploy_plugins.list .gitignore
git commit -m "Add <plugin-slug>"
git push

ssh kreul 'DRY_RUN=1 ~/deploy_safe.sh'
ssh kreul '~/deploy_safe.sh'
ssh kreul '/opt/remi/php83/root/bin/php /bin/wp-cli.phar --path=/var/www/virtuals/kreul.com.ua plugin activate <plugin-slug>'
ssh kreul '/opt/remi/php83/root/bin/php /bin/wp-cli.phar --path=/var/www/virtuals/kreul.com.ua plugin status <plugin-slug>'
```

До activation проверь обязательные constants/env, safe write-флаги, миграции и HPOS.
Наличие каталога на production не означает, что plugin активен.

### 6. Production activation

Для политики `manual` deploy не активирует новый плагин:

```bash
/opt/remi/php83/root/bin/php /bin/wp-cli.phar \
  --path=/var/www/virtuals/kreul.com.ua \
  plugin activate <slug>
```

Проверь:

```bash
/opt/remi/php83/root/bin/php /bin/wp-cli.phar \
  --path=/var/www/virtuals/kreul.com.ua \
  plugin status <slug>
```

Активацию выполняй только после настройки обязательных constants/env и проверки, что write-флаги имеют безопасные значения. Наличие файлов на production не означает активацию.

### 7. Post-deploy

- Открой административную страницу и один реальный read-only сценарий.
- Проверь логи без секретов, capability, tables/options и HPOS screen.
- Очистку WP cache и OPcache выполняет deploy, но проверяй фактический UI.
- Для cron/action scheduler проверь наличие события и первый безопасный run отдельно.
- Для proxy/API проверь HTTP, JSON body, reqId и safe failure; зелёный HTTP сам по себе не доказывает бизнес-результат.
- Для нового plugin не считай список файлов в FTP/SFTP-клиенте доказательством: он может
  быть не обновлён. Строки `f+++++++++` в выводе `rsync` означают, что файл скопирован;
  затем подтверди это на сервере и проверь activation отдельно:

```bash
ssh kreul 'stat -c "%s bytes | %y | %n" /var/www/virtuals/kreul.com.ua/wp-content/plugins/<slug>/<slug>.php'
ssh kreul 'find /var/www/virtuals/kreul.com.ua/wp-content/plugins/<slug> -type f | wc -l'
ssh kreul '/opt/remi/php83/root/bin/php /bin/wp-cli.phar --path=/var/www/virtuals/kreul.com.ua plugin status <slug>'
```

  Наличие файлов и activation — разные состояния: для `manual` plugin после успешной
  копии остаётся inactive до явного `wp plugin activate`.

## Что делает deploy_safe.sh

1. Выполняет `git pull --ff-only`.
2. При необходимости запускает Composer и синхронизирует vendor.
3. Копирует Loco translations и ops scripts.
4. Создаёт быстрый backup plugins и child theme, оставляет два последних.
5. Читает `deploy_plugins.list` и синхронизирует только собственные plugins через checksum + delete внутри каждого каталога.
6. Активирует только `ensure-active` entries.
7. Выставляет каталоги `755`, файлы `644` и печатает статус активации каждого собственного plugin; для `manual` показывает готовую WP-CLI команду.
8. Проверяет запись WP-CLI, очищает cache и обновляет `~/deploy_safe.sh` для следующего запуска.

`--delete` действует внутри каждого явно указанного собственного каталога. Нельзя добавлять в manifest сторонний plugin: локальная неполная копия может удалить его production-файлы.

## Rollback

Код:

1. При проблеме сначала деактивируй новый `manual` plugin.
2. Верни исправляющим commit/revert безопасную версию и повтори deploy.
3. При аварии используй последний `backup-plugins-*.tgz` только точечно; не распаковывай весь архив поверх production без проверки.

Данные:

- Деактивация не откатывает schema migration и не удаляет данные.
- Для destructive DB rollback нужен отдельный проверенный SQL/backup-план.
- Не запускай uninstall ради rollback, если uninstall удаляет tables/options.
- После неизвестного результата внешнего мутирующего API сначала сверяй status/idempotency, не повторяй запрос вслепую.

## MU-плагины и темы

- MU-плагины синхронизируются всем каталогом и загружаются автоматически; кнопки активации нет.
- Child theme синхронизируется отдельно.
- Новый обычный функционал по умолчанию размещай в собственном plugin, а не в MU, если ему нужны lifecycle, activation migration или управляемое отключение.

## TODO инфраструктуры

- `DRY_RUN=1` гарантирует dry-run для rsync, но остальной shell-код исторически выполняет часть подготовительных/read-only действий. Перед использованием как строгого no-side-effect режима провести отдельный аудит скрипта.
- Нет автоматического health-check каждого собственного plugin после deploy; пока post-deploy проверка ручная.
- Нет автоматического rollback schema migration.
- Нет staging production-like окружения; новые интеграции сначала принимаются на `paint.local`, затем включаются на production в safe/read-only режиме.
- Нет единого локального wrapper, который безопасно выполняет регистрацию plugin,
  lint, commit/push, dry-run, apply и manual activation одной командой. Пока эти
  шаги выполняются последовательно по сценарию выше; автоматизировать production
  запись без отдельного решения владельца нельзя.
- SSH-алиас `kreul`, ключ и доступ не проверяются Git или helper регистрации plugin;
  это обязательный preflight пользователя/сисадмина.
