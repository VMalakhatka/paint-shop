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

# Базовый rsync: без прав/владельцев/времён (для учётки без sudo)
RSYNC_BASE=( -rtv --delete --exclude .DS_Store --no-perms --no-owner --no-group --omit-dir-times )
if [ "${DRY_RUN:-0}" = "1" ]; then
  echo ">> DRY_RUN=1: rsync будет с -n (без изменений)"
  RSYNC=( "${RSYNC_BASE[@]}" -n )
else
  RSYNC=( "${RSYNC_BASE[@]}" )
fi

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

# 3) Права (мягко; ошибки игнорируем)
for p in \
  "$WP/wp-content/mu-plugins" \
  "$WP/wp-content/themes/generatepress-child" \
  "$PLUG/paint-core" \
  "$PLUG/paint-shop-ux" \
  "$PLUG/role-price"
do
  find "$p" -type d -exec chmod 755 {} \; 2>/dev/null || true
  find "$p" -type f -exec chmod 644 {} \; 2>/dev/null || true
done

# 4) Очистка кэша WP
/opt/remi/php83/root/bin/php /bin/wp-cli.phar --path="$WP" cache flush || true

echo "Done."
echo "==== $(date +'%F %T') deploy_safe.sh end ===="