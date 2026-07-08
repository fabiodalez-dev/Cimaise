#!/bin/sh
# ───────────────────────────────────────────────────────────────────────────
# Cimaise container entrypoint
#
# Prepares the writable runtime tree (which is normally bind/volume-mounted and
# therefore may come up owned by root or empty) and hands control to Apache.
# The application is installed through the web installer on first visit, so we
# only need to guarantee that the paths the installer and the app write to are
# present and owned by the web-server user.
# ───────────────────────────────────────────────────────────────────────────
set -e

APP_DIR="/var/www/html"
WEB_USER="www-data"

# Directories the application must be able to write to at runtime.
WRITABLE_DIRS="
  storage/cache
  storage/logs
  storage/originals
  storage/protected-media
  storage/rate_limits
  storage/tmp
  storage/translations
  database
  public/media
  public/assets/media
"

echo "[cimaise] preparing writable runtime tree…"
for d in $WRITABLE_DIRS; do
  mkdir -p "$APP_DIR/$d"
done

# The web installer writes .env to the application root, so the root directory
# itself must be writable by the web user (the file may not exist yet).
chown "$WEB_USER":"$WEB_USER" "$APP_DIR" 2>/dev/null || true

# Own the writable subtrees. Done every boot so freshly-mounted volumes work
# without manual chown on the host. Kept shallow-fast by only touching the
# runtime dirs, never the (read-only) application code.
for d in $WRITABLE_DIRS; do
  chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR/$d" 2>/dev/null || true
done

# ── base translation packs ──────────────────────────────────────────────────
# The base language packs (en/it *.json) are application code, but the storage
# volume mounts OVER the image copy of storage/, hiding them — without this
# seed the UI renders raw translation keys. Refreshed on every boot so image
# upgrades deliver new/updated keys; user customizations live as DB overlays
# and extra language packs we don't ship are left untouched.
SEED_DIR="/usr/share/cimaise/translations"
if [ -d "$SEED_DIR" ]; then
  echo "[cimaise] seeding base translation packs…"
  cp -f "$SEED_DIR"/*.json "$APP_DIR/storage/translations/" 2>/dev/null || true
  chown "$WEB_USER":"$WEB_USER" "$APP_DIR/storage/translations/"*.json 2>/dev/null || true
fi

# ── .env persistence ────────────────────────────────────────────────────────
# isInstalled() reads <root>/.env, but the application root is part of the
# read-only image layer, so a plain install would be lost on the next restart.
# We keep the real file inside the persisted storage/ volume and expose it at
# the root via a symlink. The installer writes through the symlink; the file
# survives restarts and image upgrades. A regular .env at the root (e.g. left
# by the updater's atomic rename) is migrated into the volume on boot.
ENV_STORE="$APP_DIR/storage/.env"
ENV_LINK="$APP_DIR/.env"

if [ -f "$ENV_LINK" ] && [ ! -L "$ENV_LINK" ]; then
  echo "[cimaise] migrating existing .env into the storage volume…"
  cp -a "$ENV_LINK" "$ENV_STORE"
  rm -f "$ENV_LINK"
fi

# (Re)create the symlink unless the link already points at the store.
if [ ! -L "$ENV_LINK" ] || [ "$(readlink "$ENV_LINK" 2>/dev/null)" != "$ENV_STORE" ]; then
  ln -sfn "$ENV_STORE" "$ENV_LINK"
fi
chown -h "$WEB_USER":"$WEB_USER" "$ENV_LINK" 2>/dev/null || true
[ -f "$ENV_STORE" ] && chown "$WEB_USER":"$WEB_USER" "$ENV_STORE" 2>/dev/null || true

if [ -f "$ENV_STORE" ]; then
  echo "[cimaise] .env present — application already configured."
else
  # First-boot banner: everything a user needs to reach the installer. When
  # CIMAISE_DB_* is set (compose does this) the installer form comes already
  # prefilled with the bundled-MySQL credentials — no typing needed.
  DB_HOST_HINT="${CIMAISE_DB_HOST:-mysql}"
  DB_NAME_HINT="${CIMAISE_DB_DATABASE:-cimaise}"
  DB_USER_HINT="${CIMAISE_DB_USER:-cimaise}"
  DB_PASS_HINT="${CIMAISE_DB_PASSWORD:-cimaise}"
  echo "[cimaise] ────────────────────────────────────────────────────────────"
  echo "[cimaise]  No .env yet — open the app in a browser to run the installer."
  echo "[cimaise]  The container listens on port 80; map it when starting:"
  echo "[cimaise]    docker run -p 8080:80 …      →  http://localhost:8080"
  echo "[cimaise]    (docker compose maps 8080 out of the box)"
  echo "[cimaise]"
  echo "[cimaise]  Installer → database:"
  echo "[cimaise]    • SQLite (default): no credentials needed."
  if getent hosts "$DB_HOST_HINT" >/dev/null 2>&1; then
    echo "[cimaise]    • MySQL (compose service detected): the installer form is"
    echo "[cimaise]      PREFILLED with the bundled credentials — just confirm."
  else
    echo "[cimaise]    • MySQL: start it with  docker compose --profile mysql up -d"
    echo "[cimaise]      and the installer form comes prefilled."
  fi
  echo "[cimaise]      (host: $DB_HOST_HINT  db: $DB_NAME_HINT  user: $DB_USER_HINT  pass: $DB_PASS_HINT)"
  echo "[cimaise]      Override via CIMAISE_MYSQL_* in a .env next to"
  echo "[cimaise]      docker-compose.yml BEFORE the first start (see DOCKER.md)."
  echo "[cimaise] ────────────────────────────────────────────────────────────"
fi

echo "[cimaise] starting Apache…"
exec "$@"
