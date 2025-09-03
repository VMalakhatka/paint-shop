#!/usr/bin/env bash
set -euo pipefail

# === Пути ===
WP=/var/www/virtuals/kreul.com.ua
REPO=~/deploy/paint-shop
PLUG="$WP/wp-content/plugins"
THEMES="$WP/wp-content/themes"
THEMES_PLUG="$THEMES/plugins"

# rsync без изменения прав/владельцев (подходит для учётки без sudo)
RSYNC="-rtv --delete --exclude .DS_Store --no-perms --no-owner --no-group --omit-dir-times"

echo "== Git pull =="
if [ ! -d "$REPO/.git" ]; then
  echo "❌ Репозиторий не найден в $REPO"
  exit 1
fi
cd "$REPO"
REPO_URL=$(git config --get remote.origin.url || true)
BEFORE=$(git rev-parse --short HEAD || true)
git pull --ff-only
AFTER=$(git rev-parse --short HEAD || true)
echo "Repo: ${REPO_URL:-<unknown>}"
echo "From $BEFORE -> $AFTER"
git log -1 --oneline || true

echo "== Preflight =="
# гарантируем наличие целевых директорий
mkdir -p "$WP/wp-content/mu-plugins" \
         "$WP/wp-content/themes/generatepress-child" \
         "$PLUG/paint-core" "$PLUG/paint-shop-ux" "$PLUG/role-price"

# быстрые бэкапы
TS=$(date +%Y%m%d-%H%M%S)
tar -C "$WP/wp-content" -czf "$HOME/backup-plugins-$TS.tgz" plugins 2>/dev/null || true
tar -C "$THEMES"      -czf "$HOME/backup-themes-plugins-$TS.tgz" plugins 2>/dev/null || true
echo "Backups: ~/backup-plugins-$TS.tgz, ~/backup-themes-plugins-$TS.tgz"

# если по ошибке существует themes/plugins — вернём её в plugins
if [ -d "$THEMES_PLUG" ]; then
  echo "Found $THEMES_PLUG -> moving back to $PLUG"
  rsync -rtv "$THEMES_PLUG"/ "$PLUG"/
  rm -rf "$THEMES_PLUG"
fi

# синхроним ТОЛЬКО наши каталоги
rsync $RSYNC wp-content/mu-plugins/                 "$WP/wp-content/mu-plugins/"
rsync $RSYNC wp-content/themes/generatepress-child/  "$WP/wp-content/themes/generatepress-child/"
rsync $RSYNC wp-content/plugins/paint-core/          "$PLUG/paint-core/"
rsync $RSYNC wp-content/plugins/paint-shop-ux/       "$PLUG/paint-shop-ux/"
rsync $RSYNC wp-content/plugins/role-price/          "$PLUG/role-price/"

# права на наши каталоги (не фатально, могут быть «Operation not permitted»)
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

# очистка кэша WP
/opt/remi/php83/root/bin/php /bin/wp-cli.phar --path="$WP" cache flush || true
echo "Done."