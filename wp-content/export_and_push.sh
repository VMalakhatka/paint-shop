#!/usr/bin/env zsh
set -euo pipefail

# ===== Настройки ЛОКАЛЬНО =====
# Имя локальной БД в Local (как в Adminer): 
LOCAL_DB="${LOCAL_DB:-local}"
# Логин/пароль Local:
LOCAL_USER="${LOCAL_USER:-root}"
LOCAL_PASS="${LOCAL_PASS:-root}"
# Путь к сокету MySQL от Local (проверь!):
# Подставь актуальный путь из Local » Reveal in Finder (mysql) / Adminer:
SOCK_PATH="${SOCK_PATH:-/Users/admin/Library/Application Support/Local/run/OtIxFLAFM/mysql/mysqld.sock}"

# ===== Куда класть временный дамп =====
STAMP=$(date +%Y%m%d-%H%M)
DUMP="/tmp/site-${STAMP}.sql"
DUMP_GZ="${DUMP}.gz"

# ===== Настройки УДАЛЁННО =====
REMOTE_USER="${REMOTE_USER:-vmalakhatka}"
REMOTE_HOST="${REMOTE_HOST:-51.83.33.95}"
REMOTE_PORT="${REMOTE_PORT:-2022}"
REMOTE_WP_DIR="${REMOTE_WP_DIR:-/var/www/virtuals/kreul.com.ua}"
REMOTE_FILE_BASENAME="${REMOTE_FILE_BASENAME:-site.sql.gz}"
REMOTE_FILE="${REMOTE_WP_DIR}/${REMOTE_FILE_BASENAME}"

# ===== Импорт на сервере (скрипт уже там) =====
RUN_REMOTE_IMPORT="${RUN_REMOTE_IMPORT:-yes}"   # yes/no
REMOTE_DEPLOY_SCRIPT="${REMOTE_DEPLOY_SCRIPT:-~/deploy_db.sh}"

echo "== Экспорт из локальной БД =="
echo "DB: ${LOCAL_DB}  via socket: ${SOCK_PATH}"
command -v mysqldump >/dev/null 2>&1 || { echo "mysqldump не найден (brew install mysql-client)"; exit 1; }

# Экспорт с добавлением DROP TABLE и корректной кодировкой
mysqldump -u "${LOCAL_USER}" -p"${LOCAL_PASS}" -S "${SOCK_PATH}" \
  --add-drop-table --default-character-set=utf8mb4 \
  "${LOCAL_DB}" > "${DUMP}"

echo "== Сжатие =="
gzip -9 "${DUMP}"
ls -lh "${DUMP_GZ}"

echo "== Копирую на сервер =="
scp -P "${REMOTE_PORT}" "${DUMP_GZ}" "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_FILE}"

if [[ "${RUN_REMOTE_IMPORT}" == "yes" ]]; then
  echo "== Запускаю импорт на сервере (${REMOTE_DEPLOY_SCRIPT} ${REMOTE_FILE_BASENAME}) =="
  ssh -p "${REMOTE_PORT}" "${REMOTE_USER}@${REMOTE_HOST}" \
    "${REMOTE_DEPLOY_SCRIPT} ${REMOTE_FILE_BASENAME}"
else
  echo "Импорт НЕ запускался (RUN_REMOTE_IMPORT=no)."
  echo "Можно вручную: ssh -p ${REMOTE_PORT} ${REMOTE_USER}@${REMOTE_HOST} \"${REMOTE_DEPLOY_SCRIPT} ${REMOTE_FILE_BASENAME}\""
fi

echo "== Готово =="