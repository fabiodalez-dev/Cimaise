![License](https://img.shields.io/badge/License-GPLv3-blue.svg) ![PHP](https://img.shields.io/badge/PHP-8.3-777BB4.svg?logo=php&logoColor=white) ![Slim](https://img.shields.io/badge/Slim-4.x-74a045.svg) ![SQLite](https://img.shields.io/badge/SQLite-3-003B57.svg?logo=sqlite&logoColor=white) ![MySQL](https://img.shields.io/badge/MySQL-8.x-4479A1.svg?logo=mysql&logoColor=white) ![Multi--arch](https://img.shields.io/badge/arch-amd64%20%7C%20arm64-orange.svg)

# Cimaise — Photography Portfolio CMS

**A modern, minimalist CMS built for photographers.** Elegant galleries, automatic AVIF/WebP image optimization, film-photography metadata, built-in analytics and SEO — self-hosted, open source, zero external services.

![Cimaise home](https://raw.githubusercontent.com/fabiodalez-dev/Cimaise/main/screenshot/home%20pure%20masonry.jpg)

## 🚀 Quick start

```bash
docker run -d --name cimaise -p 8080:80 \
  -v cimaise_storage:/var/www/html/storage \
  -v cimaise_database:/var/www/html/database \
  -v cimaise_media:/var/www/html/public/media \
  fabiodalez/cimaise:latest
```

Open **http://localhost:8080** and complete the web installer (SQLite needs zero configuration). That's it.

### Docker Compose

```yaml
services:
  cimaise:
    image: fabiodalez/cimaise:latest
    container_name: cimaise
    restart: unless-stopped
    ports:
      - "8080:80"
    volumes:
      - cimaise_storage:/var/www/html/storage
      - cimaise_database:/var/www/html/database
      - cimaise_media:/var/www/html/public/media

volumes:
  cimaise_storage:
  cimaise_database:
  cimaise_media:
```

Prefer MySQL? The repo's [`docker-compose.yml`](https://github.com/fabiodalez-dev/Cimaise/blob/main/docker-compose.yml) ships an optional MySQL 8.4 profile: `docker compose --profile mysql up -d`.

## ✨ What you get

- **12 home templates + 6 gallery layouts** — masonry, magazine, parallax, filmstrip, bento…
- **Automatic image pipeline** — AVIF, WebP and JPEG variants in 5 responsive sizes, LQIP placeholders, EXIF extraction
- **Film photography ready** — cameras, lenses, film stocks, developers and labs as first-class metadata
- **Protected galleries** — password-protected and NSFW albums with server-side gating
- **Built-in analytics, SEO structured data, PWA** — no third-party services, GDPR-friendly
- **Custom templates with AI** — bundled LLM guides let you generate new gallery layouts with your favourite assistant

| | |
|---|---|
| ![Gallery](https://raw.githubusercontent.com/fabiodalez-dev/Cimaise/main/screenshot/gallery-2-masonry-portfolio.jpg) | ![Lightbox](https://raw.githubusercontent.com/fabiodalez-dev/Cimaise/main/screenshot/Lightbox.jpg) |
| ![Admin](https://raw.githubusercontent.com/fabiodalez-dev/Cimaise/main/screenshot/admin%20album.jpg) | ![Dark mode](https://raw.githubusercontent.com/fabiodalez-dev/Cimaise/main/screenshot/Dark%20Mode%20Category%20Archive.jpg) |

## 📦 Image details

- **Base:** `php:8.3-apache` (Debian) — multi-arch `linux/amd64` + `linux/arm64`
- **Bundled:** GD with AVIF/WebP, Imagick (AVIF/HEIC), `pdo_sqlite`, `pdo_mysql`, exif, intl, zip, OPcache
- **Web root:** `public/` only; the app's `.htaccess` security rules are fully honoured

### Persistent volumes

| Container path | Holds |
|---|---|
| `/var/www/html/storage` | `.env`, originals, protected media, cache, logs |
| `/var/www/html/database` | SQLite database |
| `/var/www/html/public/media` | processed image variants |

Mount all three — your data then survives every upgrade.

### Tags

| Tag | Meaning |
|---|---|
| `latest` | latest stable release |
| `1.4` | latest patch of the 1.4 line |
| `1.4.x` | immutable, specific release |

### Environment

| Variable | Default | Purpose |
|---|---|---|
| `APP_DEBUG` | `false` | keep `false` in production |
| `APP_TIMEZONE` | `UTC` | server timezone |
| `TRUSTED_PROXIES` | *(empty)* | CIDRs of your reverse proxy for correct client IPs/HTTPS |

## ⬆️ Upgrading

```bash
docker compose pull && docker compose up -d
```

Volumes keep your data; the in-app updater is not used in Docker — new releases are new image tags, published automatically from CI for both architectures.

## 🔗 Links

- **Source & docs:** [github.com/fabiodalez-dev/Cimaise](https://github.com/fabiodalez-dev/Cimaise)
- **Full Docker guide** (reverse proxy/HTTPS, MySQL, backups): [DOCKER.md](https://github.com/fabiodalez-dev/Cimaise/blob/main/DOCKER.md)
- **Changelog:** [CHANGELOG.md](https://github.com/fabiodalez-dev/Cimaise/blob/main/CHANGELOG.md)
- **Issues:** [github.com/fabiodalez-dev/Cimaise/issues](https://github.com/fabiodalez-dev/Cimaise/issues)

*Cimaise (French): the picture rail in a gallery on which artworks are hung.*
