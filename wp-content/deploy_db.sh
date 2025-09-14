#!/usr/bin/env bash
set -euo pipefail

# === НАСТРОЙКИ ===
WP="/var/www/virtuals/kreul.com.ua"
DUMP_REL="${1:-site.sql.gz}"            # имя файла дампа (по умолчанию site.sql.gz в корне WP)
TARGET_URL="https://kreul.com.ua"       # целевой URL после импорта

# === ВСПОМОГАТЕЛЬНОЕ ===
PHPRUN="/opt/remi/php83/root/bin/php /bin/wp-cli.phar --path=$WP --skip-plugins --skip-themes"

# Достаём доступы к БД из wp-config.php (чтобы не хардкодить)
DB_NAME=$(/opt/remi/php83/root/bin/php -r "include \"$WP/wp-config.php\"; echo DB_NAME;")
DB_USER=$(/opt/remi/php83/root/bin/php -r "include \"$WP/wp-config.php\"; echo DB_USER;")
DB_PASS=$(/opt/remi/php83/root/bin/php -r "include \"$WP/wp-config.php\"; echo DB_PASSWORD;")
DB_HOST=$(/opt/remi/php83/root/bin/php -r "include \"$WP/wp-config.php\"; echo DB_HOST;")

echo "== deploy_db.sh =="
echo "WP: $WP"
echo "Dump: $DUMP_REL"
echo "DB: $DB_USER@$DB_HOST/$DB_NAME"
cd "$WP"

# 0) Проверки
if [ ! -f "$DUMP_REL" ]; then
  echo "❌ Файл дампа не найден: $WP/$DUMP_REL"
  exit 1
fi

# 1) Бэкап текущей БД
TS=$(date +%Y%m%d-%H%M%S)
echo "== Бэкап текущей БД -> ~/backup-db-$TS.sql.gz =="
mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
  --add-drop-table --default-character-set=utf8mb4 \
  | gzip -9 > "$HOME/backup-db-$TS.sql.gz" || true

# 2) Импорт дампа
echo "== Импорт дампа =="
if [[ "$DUMP_REL" == *.gz ]]; then
  gunzip -c "$DUMP_REL" | mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME"
else
  mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$DUMP_REL"
fi

# 3) Какой URL приехал из дампа
SRC_URL="$($PHPRUN option get siteurl || true)"
echo "SRC_URL: ${SRC_URL:-<empty>}"
echo "TARGET_URL: $TARGET_URL"

# 4) Мгновенно правим home/siteurl через WP-CLI (надёжнее, чем прямой SQL)
$PHPRUN option update home "$TARGET_URL"   --quiet || true
$PHPRUN option update siteurl "$TARGET_URL" --quiet || true

# 5) Глобальная замена домена (если исходный отличен)
if [ -n "${SRC_URL:-}" ] && [ "$SRC_URL" != "$TARGET_URL" ]; then
  echo "== search-replace $SRC_URL -> $TARGET_URL =="
  # --all-tables-with-prefix безопаснее, чем --all-tables, если в БД много лишних схем
  $PHPRUN search-replace "$SRC_URL" "$TARGET_URL" \
    --all-tables-with-prefix --precise --recurse-objects --skip-columns=guid
else
  echo "== search-replace пропущен (SRC_URL пустой или равен TARGET_URL) =="
fi

# РОТАЦИЯ БЭКАПОВ В $HOME
rotate_keep_latest() {
  local pattern="$1"
  local keep="${2:-2}"   # по умолчанию оставлять 2
  ls -1t $pattern 2>/dev/null | tail -n +$((keep+1)) | xargs -r rm -f
}

(
  # чтобы шаблоны без совпадений не мешали
  shopt -s nullglob
  cd "$HOME" || exit 0
  KEEP=2
  rotate_keep_latest "backup-db-*.sql.gz" "$KEEP"
  rotate_keep_latest "backup-plugins-*.tgz" "$KEEP"
  rotate_keep_latest "backup-themes-plugins-*.tgz" "$KEEP"
)


# 6) Сброс пермалинков и кэша
$PHPRUN rewrite flush --hard || true
$PHPRUN cache flush || true

# 7) Итог
echo "home:    $($PHPRUN option get home || echo '?')"
echo "siteurl: $($PHPRUN option get siteurl || echo '?')"
echo "== Готово =="