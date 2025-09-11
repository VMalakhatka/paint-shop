#!/usr/bin/env zsh
set -euo pipefail

# ===== ЛОКАЛЬНЫЕ НАСТРОЙКИ =====
LOCAL_DB="${LOCAL_DB:-local}"

# Найдём сокет Local автоматически, если не задан
SOCK_PATH_DEFAULT=$(ls -1d "$HOME/Library/Application Support/Local/run"/*/mysql/mysqld.sock 2>/dev/null | tail -n1 || true)
SOCK_PATH="${SOCK_PATH:-${SOCK_PATH_DEFAULT}}"
[[ -S "$SOCK_PATH" ]] || { echo "❌ Не найден сокет MySQL: $SOCK_PATH"; exit 1; }

# Найдём mysqldump надёжно
MYSQDUMP="${MYSQDUMP:-$(command -v mysqldump || true)}"
if [[ -z "$MYSQDUMP" && -x /opt/homebrew/opt/mysql-client/bin/mysqldump ]]; then
  MYSQDUMP=/opt/homebrew/opt/mysql-client/bin/mysqldump
fi
[[ -x "$MYSQDUMP" ]] || { echo "❌ mysqldump не найден (brew install mysql-client)"; exit 1; }

# ===== ФАЙЛ ДАМПА =====
STAMP=$(date +%Y%m%d-%H%M)
DUMP="/tmp/site-${STAMP}.sql"
DUMP_GZ="${DUMP}.gz"

# ===== УДАЛЁННЫЕ НАСТРОЙКИ =====
REMOTE_USER="${REMOTE_USER:-vmalakhatka}"
REMOTE_HOST="${REMOTE_HOST:-51.83.33.95}"
REMOTE_PORT="${REMOTE_PORT:-2022}"
REMOTE_WP_DIR="${REMOTE_WP_DIR:-/var/www/virtuals/kreul.com.ua}"
REMOTE_FILE_BASENAME="${REMOTE_FILE_BASENAME:-site.sql.gz}"
REMOTE_FILE="${REMOTE_WP_DIR}/${REMOTE_FILE_BASENAME}"
REMOTE="${REMOTE_USER}@${REMOTE_HOST}"

RUN_REMOTE_IMPORT="${RUN_REMOTE_IMPORT:-yes}"
REMOTE_DEPLOY_SCRIPT="${REMOTE_DEPLOY_SCRIPT:-~/deploy_db.sh}"

echo "== Экспорт из локальной БД =="
echo "DB: ${LOCAL_DB}"
echo "Socket: ${SOCK_PATH}"
echo "mysqldump: ${MYSQDUMP}"

# Экспорт (логин/пароль/сокет берутся из ~/.my.cnf)
"$MYSQDUMP" \
  -S "${SOCK_PATH}" \
  --add-drop-table --default-character-set=utf8mb4 \
  "${LOCAL_DB}" > "${DUMP}"

# Сжатие
echo "== Сжатие =="
if command -v pigz >/dev/null 2>&1; then
  pigz -9 "${DUMP}"
else
  gzip -9 "${DUMP}"
fi
ls -lh "${DUMP_GZ}"

# Копирование на сервер (3 попытки)
echo "== Копирую на сервер =="
tries=0; max=3
until scp -P "${REMOTE_PORT}" \
          -o ConnectTimeout=10 -o ServerAliveInterval=15 \
          "${DUMP_GZ}" "${REMOTE}:${REMOTE_FILE}"; do
  ((tries++))
  if (( tries >= max )); then
    echo "❌ scp не удалось после ${max} попыток"; exit 1
  fi
  echo "… scp retry ${tries}/${max} через 2 сек"; sleep 2
done

# Импорт на сервере
if [[ "${RUN_REMOTE_IMPORT}" == "yes" ]]; then
  echo "== Запускаю импорт на сервере (${REMOTE_DEPLOY_SCRIPT} ${REMOTE_FILE_BASENAME}) =="
  ssh -p "${REMOTE_PORT}" -o ConnectTimeout=10 -o ServerAliveInterval=15 "${REMOTE}" \
     "${REMOTE_DEPLOY_SCRIPT} ${REMOTE_FILE_BASENAME}"
else
  echo "Импорт НЕ запускался (RUN_REMOTE_IMPORT=no)."
  echo "Можно вручную:"
  echo "ssh -p ${REMOTE_PORT} ${REMOTE} \"${REMOTE_DEPLOY_SCRIPT} ${REMOTE_FILE_BASENAME}\""
fi

echo "== Готово =="