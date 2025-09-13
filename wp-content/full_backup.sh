#!/usr/bin/env bash
set -euo pipefail

WP=/var/www/virtuals/kreul.com.ua
BACKUP_DIR=/mnt/backup/backups_kreul
TS=$(date +%Y%m%d-%H%M%S)
RETAIN=1   # сколько последних полных архивов хранить

mkdir -p "$BACKUP_DIR"

# --- Проверки инструментов ---
command -v /opt/remi/php83/root/bin/php >/dev/null || { echo "❌ PHP не найден"; exit 1; }
command -v mysqldump >/dev/null || { echo "❌ mysqldump не найден"; exit 1; }
command -v tar >/dev/null || { echo "❌ tar не найден"; exit 1; }

# --- Данные БД из wp-config.php ---
DB_NAME=$(/opt/remi/php83/root/bin/php -r "include \"$WP/wp-config.php\"; echo DB_NAME;")
DB_USER=$(/opt/remi/php83/root/bin/php -r "include \"$WP/wp-config.php\"; echo DB_USER;")
DB_PASS=$(/opt/remi/php83/root/bin/php -r "include \"$WP/wp-config.php\"; echo DB_PASSWORD;")
DB_HOST=$(/opt/remi/php83/root/bin/php -r "include \"$WP/wp-config.php\"; echo DB_HOST;")

echo "== Дамп базы =="
mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
  --add-drop-table --default-character-set=utf8mb4 \
  | gzip -9 > "$BACKUP_DIR/db-$TS.sql.gz"

echo "== Архивация файлов WordPress =="
tar -C "$(dirname "$WP")" -czf "$BACKUP_DIR/files-$TS.tar.gz" "$(basename "$WP")"

echo "== Итоговый архив =="
tar -C "$BACKUP_DIR" -czf "$BACKUP_DIR/full-backup-$TS.tar.gz" \
  "db-$TS.sql.gz" "files-$TS.tar.gz"

# Чистим промежуточные
rm -f "$BACKUP_DIR/db-$TS.sql.gz" "$BACKUP_DIR/files-$TS.tar.gz"

# --- Ротация: оставляем только RETAIN последних full-backup-*.tar.gz ---
echo "== Ротация: сохраняем только последние $RETAIN архивов =="
cd "$BACKUP_DIR"
# отсортировать по времени, начиная с новых; удалить всё, что после первых RETAIN
ls -1t full-backup-*.tar.gz 2>/dev/null | tail -n +$((RETAIN+1)) | xargs -r rm -f

echo "✅ Готово:"
ls -lh "$BACKUP_DIR"/full-backup-*.tar.gz | head -n $RETAIN