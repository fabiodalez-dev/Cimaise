# Cimaise

**The photography portfolio CMS that gets out of your way.**

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)
![Version](https://img.shields.io/badge/version-1.0.0-green.svg)

<p align="center">
  <img src="docs/preview.png" alt="Cimaise Preview" width="800">
</p>

---

## Your Work Deserves Better

You've spent hours in the darkroom, days on location, years perfecting your craft. Your portfolio platform shouldn't fight against you.

**Cimaise** is built by photographers, for photographers. No bloated page builders. No plugin hell. No monthly fees. Just a clean, fast, beautiful showcase for your work.

### Why Photographers Choose Cimaise

**Blazing Fast** — Your images load instantly with automatic AVIF, WebP, and JPEG optimization. Six responsive breakpoints ensure perfect delivery on any device. No more visitors leaving because your site is slow.

**Film-Ready** — Unlike generic CMSs, Cimaise speaks your language. Track cameras, lenses, film stocks, developers, and labs. Whether you shoot Portra 400 on a Hasselblad or digital on a Leica, your metadata is organized and searchable.

**SEO That Works** — Server-side rendering, structured data, automatic sitemaps. Google actually understands your portfolio. No JavaScript-dependent pages that search engines can't read.

**Privacy First** — Built-in GDPR-compliant cookie consent, privacy-focused analytics, and no third-party tracking by default. Your visitors' data stays yours.

**Truly Yours** — Self-hosted, open source, MIT licensed. Install it on any PHP host. No vendor lock-in, no surprise price increases, no features held hostage behind premium tiers.

---

## See It In Action

### Gallery Templates

<table>
<tr>
<td width="50%">
<strong>Classic Grid</strong><br>
Clean, uniform thumbnails. Perfect for consistent series.
</td>
<td width="50%">
<strong>Masonry</strong><br>
Pinterest-style layout that respects aspect ratios.
</td>
</tr>
<tr>
<td width="50%">
<strong>Magazine</strong><br>
Editorial spreads with dramatic full-width images.
</td>
<td width="50%">
<strong>Magazine + Cover</strong><br>
Hero image with magazine-style scrolling content.
</td>
</tr>
</table>

### Admin Experience

A dashboard that doesn't insult your intelligence:

- **Drag & Drop Everything** — Reorder albums, images, categories with intuitive dragging
- **Bulk Upload** — Drop 100 images at once, grab coffee, come back to organized variants
- **Inline Editing** — Click any text to edit it. No page reloads, no modal dialogs
- **Real-Time Preview** — See exactly how your gallery will look before publishing

### Smart Filtering

Let visitors explore your work:

- Filter by category, tags, year, location
- Equipment-based browsing (by camera, lens, film stock)
- Full-text search across titles and descriptions
- URL-based filters for shareable searches

---

## The Technical Stuff

### Stack

| Layer | Technology |
|-------|------------|
| **Backend** | PHP 8.2+, Slim 4, Twig 3 |
| **Database** | SQLite (default) or MySQL 8+ |
| **Frontend** | Vite 6, Tailwind CSS 3.4, GSAP |
| **Lightbox** | PhotoSwipe 5 |
| **Upload** | Uppy 4 |

### Requirements

- PHP 8.2+ with extensions: `pdo_sqlite` or `pdo_mysql`, `gd`, `curl`, `mbstring`, `json`
- Composer 2.x
- Node.js 18+ (for building frontend assets)
- Any web server (Apache, Nginx, Caddy) or PHP built-in server for development

### Quick Install (5 Minutes)

```bash
# Clone
git clone https://github.com/yourusername/cimaise.git
cd cimaise

# Install dependencies
composer install
npm install && npm run build

# Start the installer
php -S localhost:8080 -t public public/router.php
```

Open `http://localhost:8080/install` and follow the wizard:

1. **Database** — Choose SQLite (zero config) or enter MySQL credentials
2. **Admin Account** — Set your login credentials
3. **Site Settings** — Title, description, language, logo
4. **Done** — Start uploading your work

### CLI Alternative

```bash
php bin/console install
```

Interactive prompts guide you through the same setup without a browser.

---

## Image Processing

Every uploaded image automatically generates:

```
Original (stored safely in storage/originals/)
    └── Variants in public/media/
        ├── image_sm.avif   (768px)
        ├── image_sm.webp
        ├── image_sm.jpg
        ├── image_md.avif   (1200px)
        ├── image_md.webp
        ├── image_md.jpg
        ├── image_lg.avif   (1920px)
        └── ... up to 3840px (xxl)
```

**Smart `<picture>` Elements** — Browsers automatically pick the best format and size:

```html
<picture>
  <source type="image/avif" srcset="image_sm.avif 768w, image_md.avif 1200w, ...">
  <source type="image/webp" srcset="image_sm.webp 768w, image_md.webp 1200w, ...">
  <img src="image_md.jpg" srcset="..." loading="lazy" decoding="async">
</picture>
```

**Quality Settings** — Tune per-format quality from the admin panel:

| Format | Default | Typical Range |
|--------|---------|---------------|
| AVIF | 50% | 40-60% |
| WebP | 75% | 65-85% |
| JPEG | 85% | 75-90% |

---

## Features Deep Dive

### Photography Metadata

Native support for what matters to photographers:

- **Cameras** — Make and model database
- **Lenses** — Focal length, aperture range
- **Film Stocks** — Brand, ISO, format (35mm, 120, 4x5), type (C-41, E-6, B&W)
- **Developers** — Chemical processes
- **Labs** — Your trusted development houses
- **Locations** — Where the magic happened

### SEO & Discovery

- JSON-LD structured data (BreadcrumbList, ImageGallery, Organization)
- Open Graph and Twitter Card meta tags
- Automatic XML sitemaps
- Configurable robots.txt
- Clean, semantic URLs (`/gallery/autumn-in-tokyo` not `/gallery?id=42`)

### Security

- **Authentication**: Argon2id password hashing, rate-limited login
- **CSRF Protection**: Every form, every POST request
- **SQL Injection**: 100% prepared statements
- **XSS Prevention**: Twig auto-escaping
- **Security Headers**: CSP, HSTS, X-Frame-Options, and more
- **Protected Albums**: Password protection with session validation
- **NSFW Handling**: Age-gated content with blur previews

### Multi-Language

- Built-in i18n system with JSON translation files
- Import/export translations
- Admin UI for managing text strings
- Ships with English and Italian

### Analytics (Built-in)

No Google Analytics required:

- Page views and sessions
- Geographic distribution (privacy-respecting)
- Device and browser statistics
- Bot detection and filtering
- Configurable data retention
- Export your data anytime

---

## CLI Commands

```bash
php bin/console install              # Interactive installer
php bin/console migrate              # Run database migrations
php bin/console seed                 # Seed default templates and categories
php bin/console user:create          # Create admin user
php bin/console images:generate      # Generate all image variants
php bin/console sitemap:generate     # Build XML sitemap
php bin/console analytics:cleanup    # Purge old analytics data
```

---

## Deployment

### Apache

The included `.htaccess` handles everything. Just ensure `mod_rewrite` is enabled.

### Nginx

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/cimaise/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Cache static assets aggressively
    location ~* \.(avif|webp|jpg|jpeg|png|gif|ico|css|js)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

### Subdirectory Installation

Cimaise automatically detects subdirectory installations (e.g., `yoursite.com/portfolio/`) and adjusts all URLs accordingly. No configuration needed.

---

## Project Structure

```
cimaise/
├── app/
│   ├── Controllers/      # Admin (28) and Frontend (6) controllers
│   ├── Services/         # Business logic (Upload, Analytics, EXIF, etc.)
│   ├── Views/            # Twig templates
│   └── ...
├── database/
│   ├── schema.sqlite.sql # SQLite schema
│   └── schema.mysql.sql  # MySQL schema
├── public/
│   ├── index.php         # Entry point
│   └── media/            # Generated image variants
├── storage/
│   ├── originals/        # Master images (never served directly)
│   ├── translations/     # i18n JSON files
│   └── logs/             # Application logs
└── ...
```

---

## Contributing

Contributions are welcome! Whether it's bug fixes, new features, translations, or documentation improvements.

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Submit a Pull Request

---

## License

MIT License — Use it however you want, commercially or personally.

---

## Support

- **Issues**: [GitHub Issues](https://github.com/yourusername/cimaise/issues)
- **Discussions**: [GitHub Discussions](https://github.com/yourusername/cimaise/discussions)

---

<p align="center">
  <strong>Built with care for photographers who refuse to compromise.</strong>
</p>
