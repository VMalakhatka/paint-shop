#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "Usage: $0 <plugin-slug> [manual|ensure-active] [repository-root]" >&2
}

SLUG="${1:-}"
POLICY="${2:-manual}"
ROOT="${3:-}"

if [[ ! "$SLUG" =~ ^[a-z0-9][a-z0-9-]*$ ]]; then
  usage
  echo "Invalid plugin slug: $SLUG" >&2
  exit 2
fi

if [ "$POLICY" != "manual" ] && [ "$POLICY" != "ensure-active" ]; then
  usage
  echo "Invalid activation policy: $POLICY" >&2
  exit 2
fi

if [ -z "$ROOT" ]; then
  ROOT="$(git rev-parse --show-toplevel 2>/dev/null || true)"
fi

if [ -z "$ROOT" ] || [ ! -f "$ROOT/.gitignore" ]; then
  echo "Repository root was not found. Pass it as the third argument." >&2
  exit 1
fi

PLUGIN_DIR="$ROOT/wp-content/plugins/$SLUG"
MANIFEST="$ROOT/wp-content/deploy_plugins.list"
GITIGNORE="$ROOT/.gitignore"

if [ ! -d "$PLUGIN_DIR" ]; then
  echo "Plugin directory does not exist: $PLUGIN_DIR" >&2
  exit 1
fi

if [ ! -f "$MANIFEST" ]; then
  echo "Deploy manifest does not exist: $MANIFEST" >&2
  exit 1
fi

IGNORE_DIR="!wp-content/plugins/$SLUG/"
IGNORE_FILES="!wp-content/plugins/$SLUG/**"

grep -Fqx "$IGNORE_DIR" "$GITIGNORE" || printf '\n%s\n' "$IGNORE_DIR" >> "$GITIGNORE"
grep -Fqx "$IGNORE_FILES" "$GITIGNORE" || printf '%s\n' "$IGNORE_FILES" >> "$GITIGNORE"

EXISTING="$(awk -F'|' -v slug="$SLUG" '$1 == slug { print $2; exit }' "$MANIFEST")"
if [ -n "$EXISTING" ] && [ "$EXISTING" != "$POLICY" ]; then
  echo "Plugin is already registered with policy '$EXISTING'; requested '$POLICY'." >&2
  echo "Change the policy manually after reviewing production activation risk." >&2
  exit 1
fi

if [ -z "$EXISTING" ]; then
  printf '%s|%s\n' "$SLUG" "$POLICY" >> "$MANIFEST"
fi

if git -C "$ROOT" check-ignore -q "wp-content/plugins/$SLUG"; then
  echo "Plugin is still ignored by Git; inspect parent ignore rules." >&2
  git -C "$ROOT" check-ignore -v "wp-content/plugins/$SLUG" >&2 || true
  exit 1
fi

echo "Registered plugin: $SLUG"
echo "Activation policy: $POLICY"
echo "Next: review git status, run lint/tests, commit and push."
