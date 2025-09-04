#!/usr/bin/env bash
set -euo pipefail

# === Настройки по умолчанию ===
PORT=2022
USER="vmalakhatka"
HOST="51.83.33.95"
REMOTE_DIR="\$HOME/backups"
PATTERN="full-backup-*.tar.gz"   # что считать "полным" бэкапом
DEST_DIR="\$HOME/Downloads"      # куда класть локально

# Переопределения через переменные окружения (необязательно):
#   PORT=2222 USER=me HOST=1.2.3.4 REMOTE_DIR=/path PATTERN="*.tgz" DEST_DIR=/tmp ./pull_latest_backup.sh

command -v rsync >/dev/null || { echo "❌ rsync не найден"; exit 1; }
command -v ssh   >/dev/null || { echo "❌ ssh не найден"; exit 1; }

mkdir -p "$DEST_DIR"

echo "== Ищу последний архив на $USER@$HOST:$REMOTE_DIR/$PATTERN =="
LATEST=$(ssh -p "$PORT" "$USER@$HOST" "ls -1t $REMOTE_DIR/$PATTERN 2>/dev/null | head -1" || true)

if [ -z "$LATEST" ]; then
  echo "❌ Не найден ни один файл по шаблону: $REMOTE_DIR/$PATTERN"
  exit 2
fi

BASE_NAME=$(basename "$LATEST")
echo "Найден: $LATEST"

echo "== Копирую с докачкой (rsync -P) =="
rsync -avzP -e "ssh -p $PORT" "$USER@$HOST:$LATEST" "$DEST_DIR/"

echo "✅ Готово: $DEST_DIR/$BASE_NAME"