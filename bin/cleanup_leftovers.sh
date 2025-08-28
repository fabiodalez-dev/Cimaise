#!/usr/bin/env bash
set -euo pipefail

# photoCMS cleanup utility — removes leftover/test/backup files.
#
# Usage:
#   bin/cleanup_leftovers.sh           # dry-run (shows what would be removed)
#   bin/cleanup_leftovers.sh --apply   # actually delete
#
# Optional flags:
#   --remove-installers  Remove standalone installers in public/ (keep Slim routes only)
#   --remove-sitemaps    Remove placeholder sitemap files in public/
#   --remove-dev         Remove dev/demo helpers under bin/dev/

APPLY=0
REMOVE_INSTALLERS=0
REMOVE_SITEMAPS=0
REMOVE_DEV=0

for arg in "$@"; do
  case "$arg" in
    --apply) APPLY=1 ;;
    --remove-installers) REMOVE_INSTALLERS=1 ;;
    --remove-sitemaps) REMOVE_SITEMAPS=1 ;;
    --remove-dev) REMOVE_DEV=1 ;;
    *) echo "Unknown option: $arg" >&2; exit 1 ;;
  esac
done

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

say() { echo "[cleanup] $*"; }
rm_path() {
  local p="$1";
  if [[ -e "$p" ]]; then
    if [[ $APPLY -eq 1 ]]; then
      rm -rf "$p" && say "removed: $p"
    else
      say "would remove: $p"
    fi
  fi
}

# 1) macOS metadata and obvious archives
find "$ROOT_DIR" \
  -type f -name '.DS_Store' \
  -not -path '*/vendor/*' -not -path '*/node_modules/*' \
  -print | while read -r f; do rm_path "$f"; done

rm_path "$ROOT_DIR/Archive.zip"
rm_path "$ROOT_DIR/cookies.txt"
rm_path "$ROOT_DIR/.claude"
rm_path "$ROOT_DIR/.qodo"

# 2) Nested/duplicated public trees
rm_path "$ROOT_DIR/public/public"
rm_path "$ROOT_DIR/public/assets/public"

# 3) Photoswipe unneeded sources/maps (keep minified builds and CSS)
rm_path "$ROOT_DIR/public/assets/photoswipe/js"
rm_path "$ROOT_DIR/public/assets/build/config-builder.js"
find "$ROOT_DIR/public/assets/photoswipe/dist" \
  -type f \( -name '*.map' -o -name 'README.md' \) -print | while read -r f; do rm_path "$f"; done
rm_path "$ROOT_DIR/public/assets/photoswipe/dist/types"

# 4) Duplicate installer asset (keep only one, or remove all with flag)
rm_path "$ROOT_DIR/public/assets/installer.php"
if [[ $REMOVE_INSTALLERS -eq 1 ]]; then
  rm_path "$ROOT_DIR/public/installer.php"
  rm_path "$ROOT_DIR/public/simple-install.php"
  rm_path "$ROOT_DIR/public/repair_install.php"
fi

# 5) App-level storage duplicate
rm_path "$ROOT_DIR/app/storage"

# 6) Database backups and local helper SQL
rm_path "$ROOT_DIR/database/template.sqlite.backup"
rm_path "$ROOT_DIR/database/test_template.sql"
rm_path "$ROOT_DIR/database/bootstrap_local.sql"
rm_path "$ROOT_DIR/database/check_tables.sql"
rm_path "$ROOT_DIR/database/check_analytics_tables.sql"

# Note: we do NOT delete database/database.sqlite or database/template.sqlite here.

# 7) Public placeholder sitemaps
if [[ $REMOVE_SITEMAPS -eq 1 ]]; then
  rm_path "$ROOT_DIR/public/sitemap.xml"
  rm_path "$ROOT_DIR/public/sitemap_index.xml"
fi

# 8) Dev/demo helpers
if [[ $REMOVE_DEV -eq 1 ]]; then
  rm_path "$ROOT_DIR/bin/dev"
  # Keep CLI utilities unless you explicitly remove them manually
fi

if [[ $APPLY -eq 0 ]]; then
  say "dry-run complete. Re-run with --apply to delete."
else
  say "cleanup complete."
fi

