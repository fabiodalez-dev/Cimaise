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
  echo "[cimaise] no .env — open the site in a browser to run the installer."
fi

echo "[cimaise] starting Apache…"
exec "$@"
