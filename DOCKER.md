# Running Cimaise with Docker

Cimaise ships as a self-contained, multi-architecture image (`linux/amd64` +
`linux/arm64`) built on **PHP 8.3 + Apache**. It bundles every extension the CMS
needs — GD (with AVIF/WebP), Imagick (AVIF/HEIC/WebP), `pdo_sqlite`,
`pdo_mysql`, `exif`, `intl`, `zip`, `bcmath` and OPcache — so you don't install
PHP, Composer or Node on the host.

- **Docker Hub:** `fabiodalez/cimaise`
- **GHCR:** `ghcr.io/fabiodalez-dev/cimaise`

---

## 1. Quick start (SQLite — zero config)

```bash
docker run -d --name cimaise -p 8080:80 \
  -v cimaise_storage:/var/www/html/storage \
  -v cimaise_database:/var/www/html/database \
  -v cimaise_media:/var/www/html/public/media \
  fabiodalez/cimaise:latest
```

Open <http://localhost:8080> and complete the **web installer**:

1. **Database** — choose *SQLite* (no extra service needed).
2. **Admin account** — your login.
3. **Site settings** — title, description, language, logo.

That's it. The installer writes `.env` and creates the SQLite database inside
the persisted volumes (see [Persistence](#3-persistence--your-data)).

---

## 2. Docker Compose

A ready-made [`docker-compose.yml`](docker-compose.yml) is included.

```bash
# SQLite (default) — builds locally if you cloned the repo, else pulls the image
docker compose up -d

# With a bundled MySQL 8.4 database
docker compose --profile mysql up -d
```

The app is exposed on **<http://localhost:8080>**.

To run the published image instead of building locally, comment out the
`build:` block in `docker-compose.yml` (the `image:` line already points at
`fabiodalez/cimaise:latest`).

### Using MySQL

Start with the `mysql` profile (above), then in the web installer pick
**MySQL** and enter:

| Field    | Value     |
|----------|-----------|
| Host     | `mysql`   |
| Port     | `3306`    |
| Database | `cimaise` |
| Username | `cimaise` |
| Password | `cimaise` |

Change these defaults in `docker-compose.yml` (`mysql.environment`) before the
first launch for anything beyond local testing.

---

## 3. Persistence — your data

All mutable state lives in three volumes. **Mount them** or you lose your data
when the container is recreated:

| Container path                   | Holds                                              |
|----------------------------------|----------------------------------------------------|
| `/var/www/html/storage`          | `.env`, logs, cache, originals, protected media, rate limits |
| `/var/www/html/database`         | the SQLite database file                           |
| `/var/www/html/public/media`     | processed image variants served to visitors        |

The installer's `.env` is stored at `storage/.env` and symlinked to the app
root by the entrypoint, so a fresh image (an upgrade) immediately recognises an
existing installation. The entrypoint also re-applies ownership on every boot,
so freshly-created volumes work without manual `chown`.

```bash
docker compose down       # stop, keep data
docker compose down -v    # stop and WIPE all volumes (irreversible)
```

### Backups

```bash
# SQLite database + uploads
docker run --rm -v cimaise_database:/db -v "$PWD:/backup" alpine \
  tar czf /backup/cimaise-db-$(date +%F).tar.gz -C /db .
docker run --rm -v cimaise_media:/media -v "$PWD:/backup" alpine \
  tar czf /backup/cimaise-media-$(date +%F).tar.gz -C /media .
```

For MySQL, back up with `mysqldump` against the `mysql` service as usual.

---

## 4. Configuration (environment variables)

The image runs in production mode by default. Useful variables:

| Variable          | Default      | Purpose                                                        |
|-------------------|--------------|----------------------------------------------------------------|
| `APP_ENV`         | `production` | App environment.                                               |
| `APP_DEBUG`       | `false`      | Keep `false` in production (debug output corrupts media bytes).|
| `APP_TIMEZONE`    | `UTC`        | Default timezone.                                              |
| `TRUSTED_PROXIES` | *(empty)*    | Comma-separated proxy CIDRs to honour `X-Forwarded-*` headers. |

> Database credentials, site URL and secrets are written to `.env` by the
> installer — you don't pass them as environment variables.

---

## 5. Behind a reverse proxy / HTTPS

The container serves plain HTTP on port 80. Terminate TLS at a reverse proxy
(Traefik, Caddy, nginx) and forward to the container. Set `TRUSTED_PROXIES` so
Cimaise trusts the forwarded client IP and scheme:

```yaml
services:
  cimaise:
    image: fabiodalez/cimaise:latest
    environment:
      TRUSTED_PROXIES: "172.16.0.0/12"   # your proxy network
    volumes:
      - cimaise_storage:/var/www/html/storage
      - cimaise_database:/var/www/html/database
      - cimaise_media:/var/www/html/public/media
    # no host port published — only the proxy talks to it
```

During installation, set the **Application URL** to your public `https://`
address so generated links, sitemaps and the PWA manifest are correct.

---

## 6. Upgrading

```bash
docker compose pull        # fetch the new image
docker compose up -d       # recreate the container; volumes (data) are kept
```

Because the install state lives in volumes, upgrades are non-destructive. The
in-app updater is **not** needed (and should not be used) for the Docker image —
upgrade by pulling a newer tag instead.

---

## 7. Building the image yourself

```bash
# Native build for your machine's architecture
docker build -t cimaise:local .

# Multi-arch build (requires buildx with QEMU)
docker buildx build --platform linux/amd64,linux/arm64 -t cimaise:local .
```

> **Note on local multi-arch builds.** Building the *foreign* architecture on
> your machine runs the whole toolchain under QEMU user-mode emulation, which is
> known to be unstable for heavy compilation (the Python interpreter and `g++`
> can segfault mid-build). This is an emulator limitation, not a Dockerfile
> problem — build each architecture on a native host, or just let CI do it: the
> publishing workflow builds `amd64` and `arm64` on native runners.

The [`Dockerfile`](Dockerfile) is a three-stage build:

1. **assets** (`node:18`) — `npm ci` + `npm run build` (Vite + Tailwind + the
   FontAwesome subset) produces the served JS/CSS from source.
2. **vendor** (`composer:2`) — `composer install --no-dev` with an optimized,
   classmap-authoritative autoloader.
3. **runtime** (`php:8.3-apache`) — extensions via
   [`mlocati/docker-php-extension-installer`](https://github.com/mlocati/docker-php-extension-installer),
   the project's `.htaccess` honoured (`AllowOverride All`), and an entrypoint
   that prepares the writable volumes before starting Apache.

---

## 8. Publishing a release

Two paths — automated (recommended) and manual.

### Automated (GitHub Actions)

[`.github/workflows/docker-publish.yml`](.github/workflows/docker-publish.yml)
builds each architecture on its **own native runner** (amd64 on `ubuntu-24.04`,
arm64 on `ubuntu-24.04-arm` — no QEMU emulation), then merges them into one
multi-arch manifest. It runs on every `v*.*.*` tag you push (and on manual
`workflow_dispatch`), always pushing to **GHCR** using the built-in
`GITHUB_TOKEN`, and additionally to **Docker Hub** when these repository secrets
are set (Settings → Secrets and variables → Actions):

| Secret               | Value                                                  |
|----------------------|--------------------------------------------------------|
| `DOCKERHUB_USERNAME` | your Docker Hub account / namespace (e.g. `fabiodalez`)|
| `DOCKERHUB_TOKEN`    | a Docker Hub **access token** (Read/Write)             |

Create a Docker Hub access token at <https://hub.docker.com/settings/security>.
Then cut a release:

```bash
git tag v1.4.14
git push origin v1.4.14
```

The workflow publishes `fabiodalez/cimaise:1.4.14`, `:1.4`, and `:latest`.

> The **same tag** also triggers the app release workflow (`release.yml`), so a
> single `git tag vX.Y.Z` ships both the release ZIP and the refreshed Docker
> image. If a change touches the runtime (PHP extensions, build steps, writable
> dirs), update the [`Dockerfile`](Dockerfile) in the same commit. See the
> maintainer release flow in [`docs/updater.md`](docs/updater.md).

### Manual (one-off, from your machine)

1. **Create the Docker Hub repository** (once): sign in at
   <https://hub.docker.com> → *Create repository* → name it `cimaise`,
   visibility **Public**.

2. **Log in and push a multi-arch image:**

   ```bash
   docker login -u <your-dockerhub-username>

   docker buildx build \
     --platform linux/amd64,linux/arm64 \
     -t <your-dockerhub-username>/cimaise:1.4.14 \
     -t <your-dockerhub-username>/cimaise:latest \
     --push .
   ```

   `--push` uploads every architecture under a single multi-arch manifest, so
   `docker pull` automatically serves the right one per host.

3. **Verify:**

   ```bash
   docker buildx imagetools inspect <your-dockerhub-username>/cimaise:latest
   ```

---

## 9. Troubleshooting

| Symptom                                   | Fix                                                                 |
|-------------------------------------------|---------------------------------------------------------------------|
| Browser stuck on `/installer.php`         | Expected on first run — complete the installer. It's done once.     |
| "directory not writable" during install  | Ensure the three volumes are mounted; the entrypoint chowns them.   |
| Site loads but links use `http://`        | Set the **Application URL** to your `https://` host in the installer, and `TRUSTED_PROXIES`. |
| Images don't generate                     | Confirm the container has Imagick/GD: `docker exec cimaise php -m \| grep -E 'gd\|imagick'`. |
| Changed MySQL creds after install         | Edit `storage/.env` in the volume, then restart the container.      |
