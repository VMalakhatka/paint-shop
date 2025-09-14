#!/usr/bin/env bash
set -euo pipefail

# === НАСТРОЙКИ ===
WP="/var/www/virtuals/kreul.com.ua"
DUMP_REL="${1:-site.sql.gz}"             # файл дампа (по умолчанию site.sql.gz в корне WP)
TARGET_URL="https://kreul.com.ua"        # целевой URL после импорта
BACKUP_DIR="/mnt/backup/backups_kreul"   # куда складывать бэкапы текущей БД
RETAIN=3                                 # сколько последних бэкапов хранить

# === ВСПОМОГАТЕЛЬНОЕ ===
PHPRUN="/opt/remi/php83/root/bin/php /bin/wp-cli.phar --path=$WP --skip-plugins --skip-themes"

# Достаём доступы к БД из wp-config.php
DB_NAME=$(/opt/remi/php83/root/bin/php -r "include \"$WP/wp-config.php\"; echo DB_NAME;")
DB_USER=$(/opt/remi/php83/root/bin/php -r "include \"$WP/wp-config.php\"; echo DB_USER;")
DB_PASS=$(/opt/remi/php83/root/bin/php -r "include \"$WP/wp-config.php\"; echo DB_PASSWORD;")
DB_HOST=$(/opt/remi/php83/root/bin/php -r "include \"$WP/wp-config.php\"; echo DB_HOST;")

echo "== deploy_db.sh =="
echo "WP: $WP"
echo "Dump: $DUMP_REL"
echo "DB: $DB_USER@$DB_HOST/$DB_NAME"
cd "$WP"

# Готовим каталог бэкапов
mkdir -p "$BACKUP_DIR" 2>/dev/null || true
if [ ! -d "$BACKUP_DIR" ] || [ ! -w "$BACKUP_DIR" ]; then
  echo "⚠ $BACKUP_DIR недоступен для записи — переключаюсь на \$HOME"
  BACKUP_DIR="$HOME"
  mkdir -p "$BACKUP_DIR"
fi

# 0) Проверки
if [ ! -f "$DUMP_REL" ]; then
  echo "❌ Файл дампа не найден: $WP/$DUMP_REL"
  exit 1
fi

# Покажем текущие значения до импорта
CUR_HOME=$($PHPRUN option get home || true)
CUR_SITEURL=$($PHPRUN option get siteurl || true)
echo "Before import: home=${CUR_HOME:-<n/a>} siteurl=${CUR_SITEURL:-<n/a>}"

# 1) Бэкап текущей БД
TS=$(date +%Y%m%d-%H%M%S)
BACKUP_FILE="$BACKUP_DIR/backup-db-$TS.sql.gz"
echo "== Бэкап текущей БД -> $BACKUP_FILE =="
mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
  --add-drop-table --default-character-set=utf8mb4 \
  | gzip -9 > "$BACKUP_FILE" || true

# 2) Импорт дампа
echo "== Импорт дампа =="
if [[ "$DUMP_REL" == *.gz ]]; then
  gunzip -c "$DUMP_REL" | mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME"
else
  mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$DUMP_REL"
fi

# 3) Прямо жёстко выставляем URL
TABLE_PREFIX=$(/opt/remi/php83/root/bin/php -r "include '$WP/wp-config.php'; echo isset(\$table_prefix)?\$table_prefix:'wp_';")
echo "TABLE_PREFIX: $TABLE_PREFIX"
echo "TARGET_URL: $TARGET_URL"

mysql -h "$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "
  UPDATE ${TABLE_PREFIX}options
  SET option_value='$TARGET_URL'
  WHERE option_name IN ('home','siteurl');
"

# 4) Глобальная замена домена (нужно, чтобы убрать старый paint.local из постов, мета и т.п.)
$PHPRUN search-replace "http://paint.local" "$TARGET_URL" \
  --all-tables-with-prefix --precise --recurse-objects --skip-columns=guid || true



# 5) Дублируем через WP-CLI (обновляет кэши WP)
$PHPRUN option update home "$TARGET_URL"    --quiet || true
$PHPRUN option update siteurl "$TARGET_URL" --quiet || true


# 7) Сброс пермалинков и кэша
$PHPRUN rewrite flush --hard || true
$PHPRUN cache flush || true

# 8) Ротация бэкапов в $BACKUP_DIR
rotate_keep_latest() {
  local pattern="$1"
  local keep="${2:-3}"
  ls -1t $pattern 2>/dev/null | tail -n +$((keep+1)) | xargs -r rm -f
}
(
  cd "$BACKUP_DIR" 2>/dev/null || exit 0
  rotate_keep_latest "backup-db-*.sql.gz" "$RETAIN"
)

# 9) Итоговые значения
NEW_HOME=$($PHPRUN option get home || true)
NEW_SITEURL=$($PHPRUN option get siteurl || true)
echo "After import:  home=${NEW_HOME:-<n/a>} siteurl=${NEW_SITEURL:-<n/a>}"
echo "== Готово =="