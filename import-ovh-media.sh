#!/usr/bin/env bash
set -euo pipefail

# WP-CLI без плагинов/тем и с увеличенной памятью
WP_CLI="$(command -v wp)"
if [[ -z "$WP_CLI" ]]; then
  echo "wp not found in PATH. If WP is not in current dir, run with --path=/full/path" >&2
  exit 1
fi
WP="php -d memory_limit=512M $WP_CLI --skip-plugins --skip-themes"

# Если WP находится не в текущей директории — можно задать:
# WP="$WP --path=${WP_PATH:-}"

# Параметры импорта
BUCKET="${BUCKET:-kreul-media}"
PREFIX="${PREFIX:-wp-content/uploads/2025/10/}"
BASE="${BASE:-https://kreul-media.s3.gra.io.cloud.ovh.net}"
MAX="${MAX:-0}"   # 0 = без лимита

# Префикс таблиц
PFX="$($WP db prefix --quiet)"

# Кэш уже существующих относительных путей (_wp_attached_file)
EXIST="$(mktemp)"
$WP db query --skip-column-names \
  "SELECT meta_value FROM ${PFX}postmeta WHERE meta_key='_wp_attached_file';" \
  | sort -u > "$EXIST"
echo "Loaded $(wc -l < "$EXIST") existing attachments."

count=0
s3cmd ls "s3://$BUCKET/$PREFIX" \
| awk '{print $4}' \
| sed -E "s#^s3://$BUCKET/##" \
| grep -Ei '\.(png|jpe?g|webp)$' \
| grep -Eiv -- '-[0-9]{2,4}x[0-9]{2,4}\.' \
| while IFS= read -r KEY; do
  REL="${KEY#wp-content/uploads/}"      # 2025/10/file.png
  URL="$BASE/$KEY"

  if grep -Fqx "$REL" "$EXIST"; then
    printf 'SKIP: %s\n' "$REL"
    continue
  fi

  if $WP media import "$URL" --porcelain >/dev/null; then
    printf 'IMPORTED: %s\n' "$URL"
    echo "$REL" >> "$EXIST"
    if [[ "$MAX" -gt 0 ]]; then
      count=$((count+1))
      [[ "$count" -ge "$MAX" ]] && echo "Reached MAX=$MAX. Stop." && break
    fi
  else
    printf 'FAILED: %s\n' "$URL" >&2
  fi
done
