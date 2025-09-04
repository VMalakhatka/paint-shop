#!/usr/bin/env bash
set -euo pipefail

# Пути
SRC_DIR="/var/www/virtuals/kreul.com.ua/wp-content"
DST_DIR="$HOME"
SCRIPTS=( deploy_safe.sh deploy_db.sh export_and_push.sh )

echo "== Обновляем ops-скрипты =="

for s in "${SCRIPTS[@]}"; do
  if [ -f "$SRC_DIR/$s" ]; then
    cp -f "$SRC_DIR/$s" "$DST_DIR/$s"
    chmod 755 "$DST_DIR/$s"
    echo "✓ $s скопирован в $DST_DIR и сделан исполняемым"
  else
    echo "⚠ Не найден $SRC_DIR/$s"
  fi
done

echo "== Готово =="