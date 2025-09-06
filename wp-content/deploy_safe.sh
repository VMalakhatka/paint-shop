#!/usr/bin/env bash
set -euo pipefail

# ====== НАСТРОЙКИ ======
WP=/var/www/virtuals/kreul.com.ua
REPO=~/deploy/paint-shop
PLUG="$WP/wp-content/plugins"
THEMES="$WP/wp-content/themes"
THEMES_PLUG="$THEMES/plugins"
LOG=~/deploy.log

# Ротация лога (если > 1 МБ — сдвигаем)
if [ -f "$LOG" ] && [ "$(wc -c <"$LOG")" -gt 1048576 ]; then
  mv -f "$LOG" "$LOG.$(date +%Y%m%d-%H%M%S)"
fi

# Логируем всё на экран и в файл
exec > >(tee -a "$LOG") 2>&1
echo "==== $(date +'%F %T') deploy_safe.sh start ===="
[ "${DRY_RUN:-0}" = "1" ] && echo "▶ DRY RUN: никакие файлы на сервере изменены не будут"

command -v git >/dev/null || { echo "❌ git не найден"; exit 1; }

# Базовый rsync: без прав/владельцев/времён (для учётки без sudo)
RSYNC_BASE=( -rtv --delete --exclude .DS_Store --no-perms --no-owner --no-group --omit-dir-times )
if [ "${DRY_RUN:-0}" = "1" ]; then
  echo ">> DRY_RUN=1: rsync будет с -n (без изменений)"
  RSYNC=( "${RSYNC_BASE[@]}" -n )
else
  RSYNC=( "${RSYNC_BASE[@]}" )
fi

# Проверки наличия инструментов до первого использования
command -v /opt/remi/php83/root/bin/php >/dev/null || { echo "❌ PHP не найден"; exit 1; }
[ -f /bin/wp-cli.phar ] || { echo "❌ wp-cli.phar не найден в /bin"; exit 1; }

echo "== Git pull =="
if [ ! -d "$REPO/.git" ]; then
  echo "❌ Репозиторий не найден: $REPO"
  exit 1
fi

cd "$REPO"
REMOTE_URL=$(git config --get remote.origin.url || echo "<unknown>")
BEFORE=$(git rev-parse --short HEAD || echo "<none>")
git pull --ff-only
AFTER=$(git rev-parse --short HEAD || echo "<none>")

echo "Repo: $REMOTE_URL"
echo "From $BEFORE -> $AFTER"
if [ "$BEFORE" = "$AFTER" ]; then
  echo "✓ В репозитории нет новых коммитов."
else
  echo "== Новые коммиты =="
  git log --oneline "${BEFORE}..${AFTER}" || true
fi


# == Ops-скрипты из репозитория -> в $HOME ==
# Скрипты лежат в репозитории в wp-content; на сервере должны жить в $HOME и быть исполняемыми.
# ВАЖНО: сюда НЕ включаем сам deploy_safe.sh, чтобы не перезаписывать работающий скрипт.
OPS_SRC_DIR="$REPO/wp-content"
OPS_TARGET_DIR="$HOME"
OPS_SCRIPTS=( deploy_db.sh export_and_push.sh )

for s in "${OPS_SCRIPTS[@]}"; do
  if [ -f "$OPS_SRC_DIR/$s" ]; then
    # копируем только если изменился (быстрее и безопаснее)
    rsync -rt --checksum "$OPS_SRC_DIR/$s" "$OPS_TARGET_DIR/$s"
    # делаем исполняемым
    chmod 755 "$OPS_TARGET_DIR/$s" || true
    echo "✓ Обновлён $OPS_TARGET_DIR/$s"
  else
    echo "ℹ Скрипт отсутствует в репо: $OPS_SRC_DIR/$s"
  fi
done

# Алиасы для быстрого запуска (идемпотентно)
ALIASES_FILE="$HOME/.bashrc"
grep -q 'alias dcode='     "$ALIASES_FILE" 2>/dev/null || echo 'alias dcode="~/deploy_safe.sh"' >> "$ALIASES_FILE"
grep -q 'alias ddb='       "$ALIASES_FILE" 2>/dev/null || echo 'alias ddb="~/deploy_db.sh site.sql.gz"' >> "$ALIASES_FILE"
grep -q 'alias dcode-dry=' "$ALIASES_FILE" 2>/dev/null || echo 'alias dcode-dry="DRY_RUN=1 ~/deploy_safe.sh"' >> "$ALIASES_FILE"
# при желании активировать сразу в текущей сессии — выполни вручную:
# source ~/.bashrc

grep -q 'umask 002' ~/.bashrc || echo 'umask 002' >> ~/.bashrc
grep -q '. ~/.bashrc' ~/.bash_profile || echo '. ~/.bashrc' >> ~/.bash_profile

echo "== Preflight =="
mkdir -p "$PLUG"

# 0) Быстрые бэкапы (не блокируют работу)
TS=$(date +%Y%m%d-%H%M%S)
tar -C "$WP/wp-content" -czf "$HOME/backup-plugins-$TS.tgz" plugins 2>/dev/null || true
tar -C "$THEMES"      -czf "$HOME/backup-themes-plugins-$TS.tgz" plugins 2>/dev/null || true
echo "Backups: ~/backup-plugins-$TS.tgz, ~/backup-themes-plugins-$TS.tgz"

# 1) Если по ошибке существует themes/plugins — вернём в plugins
if [ -d "$THEMES_PLUG" ]; then
  echo "Found $THEMES_PLUG -> moving back to $PLUG"
  rsync -rtv "$THEMES_PLUG"/ "$PLUG"/
  rm -rf "$THEMES_PLUG"
fi

# 2) Синхроним ТОЛЬКО наши каталоги
rsync "${RSYNC[@]}" wp-content/mu-plugins/                 "$WP/wp-content/mu-plugins/"
rsync "${RSYNC[@]}" wp-content/themes/generatepress-child/  "$WP/wp-content/themes/generatepress-child/"
rsync "${RSYNC[@]}" wp-content/plugins/paint-core/          "$PLUG/paint-core/"
rsync "${RSYNC[@]}" wp-content/plugins/paint-shop-ux/       "$PLUG/paint-shop-ux/"
rsync "${RSYNC[@]}" wp-content/plugins/role-price/          "$PLUG/role-price/"
rsync "${RSYNC[@]}" wp-content/plugins/pc-order-exporter/   "$PLUG/pc-order-exporter/"
rsync "${RSYNC[@]}" wp-content/plugins/pc-order-import-export/   "$PLUG/pc-order-import-export/"

# 3) Права (мягко; ошибки игнорируем)
for p in \
  "$WP/wp-content/mu-plugins" \
  "$WP/wp-content/themes/generatepress-child" \
  "$PLUG/paint-core" \
  "$PLUG/role-price" \
  "$PLUG/pc-order-exporter"\
  "$PLUG/pc-order-import-export"
do
  find "$p" -type d -exec chmod 755 {} \; 2>/dev/null || true
  find "$p" -type f -exec chmod 644 {} \; 2>/dev/null || true
done

# 4) Post-sync проверка записи в plugins
TEST_FILE="$PLUG/deploy-test.txt"
if /opt/remi/php83/root/bin/php /bin/wp-cli.phar --path="$WP" eval \
   "file_put_contents('$TEST_FILE','ok: '.date('c'));" >/dev/null 2>&1; then
  if [ -f "$TEST_FILE" ]; then
    echo "✓ WP-CLI смог записать файл в plugins/: $(ls -l "$TEST_FILE")"
    rm -f "$TEST_FILE"
  else
    echo "⚠ WP-CLI отработал, но файл не появился в $PLUG"
  fi
else
  echo "❌ Ошибка: WP-CLI не смог записать в $PLUG"
fi

# 5) Очистка кэша WP
/opt/remi/php83/root/bin/php /bin/wp-cli.phar --path="$WP" cache flush || true

echo "Done."
echo "==== $(date +'%F %T') deploy_safe.sh end ===="