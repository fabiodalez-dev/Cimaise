# photoCMS

A minimalist, high-performance photography portfolio content management system designed for professional photographers.

![License](https://img.shields.io/badge/license-MIT-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-purple.svg)
![Version](https://img.shields.io/badge/version-1.0.0-green.svg)

---

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Tech Stack](#tech-stack)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Admin Panel](#admin-panel)
- [Frontend Features](#frontend-features)
- [CLI Commands](#cli-commands)
- [Image Processing](#image-processing)
- [Security](#security)
- [Project Structure](#project-structure)
- [Database Schema](#database-schema)
- [Contributing](#contributing)
- [License](#license)

---

## Overview

**photoCMS** is a modern, lightweight CMS built specifically for photographers who want full control over their portfolio presentation. It emphasizes:

- **Performance**: Responsive images in AVIF/WebP/JPEG with lazy loading
- **SEO**: Server-side rendering, structured data, sitemaps
- **Security**: Argon2id hashing, CSRF protection, security headers
- **Simplicity**: Clean admin interface, intuitive workflows
- **Flexibility**: SQLite for quick setup, MySQL for production

Whether you're a film photographer showcasing analog work or a digital artist presenting your latest series, photoCMS provides the tools you need without unnecessary bloat.

---

## Features

### Portfolio Management
- **Albums**: Create, edit, and organize photo albums with rich metadata
- **Categories**: Hierarchical organization with custom cover images
- **Tags**: Flexible tagging system for cross-album organization
- **Templates**: Multiple gallery display layouts (grid, masonry, magazine)

### Photography Metadata
- **Cameras**: Track camera bodies used (make, model)
- **Lenses**: Catalog lenses with focal range and aperture specs
- **Films**: Film stock database (brand, ISO, format, type)
- **Developers**: Chemical processes (C-41, E-6, B&W)
- **Labs**: Development and scanning facility records
- **EXIF**: Automatic extraction and manual override support

### Image Handling
- **Multi-format Output**: Automatic AVIF, WebP, and JPEG generation
- **Responsive Variants**: 6 breakpoints (xs to xxl) for optimal delivery
- **Lazy Loading**: Native lazy loading with blur-up LQIP previews
- **Bulk Upload**: Drag-and-drop with progress tracking via Uppy

### SEO & Analytics
- **Built-in Analytics**: Visitor tracking with geographic data
- **Schema.org Markup**: JSON-LD structured data for rich snippets
- **Open Graph & Twitter Cards**: Social sharing optimization
- **Sitemap Generation**: Automatic XML sitemaps
- **Robots.txt Control**: Fine-grained crawling directives

### Admin Panel
- **Dashboard**: Real-time statistics and quick actions
- **Media Library**: Centralized asset management
- **User Management**: Multi-admin support with role-based access
- **Settings**: Comprehensive site configuration
- **Diagnostics**: System health monitoring

---

## Tech Stack

### Backend
| Technology | Version | Purpose |
|------------|---------|---------|
| PHP | 8.2+ | Core language |
| Slim Framework | 4.x | Routing and middleware |
| Twig | 3.x | Templating engine |
| PDO | - | Database abstraction |
| Symfony Console | 6.x | CLI commands |

### Frontend
| Technology | Version | Purpose |
|------------|---------|---------|
| Vite | 6.x | Build tool and dev server |
| Tailwind CSS | 3.4+ | Utility-first styling |
| GSAP | 3.13 | Animations |
| Lenis | 1.3 | Smooth scrolling |
| PhotoSwipe | 5.x | Lightbox |
| Uppy | 4.x | File uploads |
| Bootstrap | 5.3 | UI components |

### Database
- **SQLite** (default): Zero-configuration, perfect for development
- **MySQL 8.0+**: Recommended for production deployments

---

## Requirements

### Server Requirements
- PHP 8.2 or higher
- Composer 2.x
- Node.js 18+ and npm (for frontend build)

### PHP Extensions
```
pdo_sqlite or pdo_mysql
gd (image processing)
curl (GeoIP lookups)
json
mbstring
```

### Writable Directories
```
database/           # SQLite database file
storage/originals/  # Original uploaded images
storage/tmp/        # Temporary upload files
storage/logs/       # Application logs
storage/cache/      # Fragment cache
public/media/       # Generated image variants
```

---

## Installation

### Quick Start (5 minutes)

```bash
# Clone the repository
git clone https://github.com/fabiodalez-dev/photoCMS.git
cd photoCMS

# Install PHP dependencies
composer install

# Install frontend dependencies
npm install

# Build frontend assets
npm run build

# Copy environment file
cp .env.example .env

# Run initialization (creates tables, seeds demo data)
php bin/console init

# Start development server
php -S 127.0.0.1:8000 -t public
```

Access your site at `http://127.0.0.1:8000`

### Web Installer

For a guided installation, visit:
- **Full Installer**: `http://your-domain.com/installer.php`
- **Simple Installer**: `http://your-domain.com/simple-install.php`

The installer will:
1. Validate server requirements
2. Configure database connection
3. Create database tables
4. Set up admin account
5. Generate initial sitemap

### Docker (MySQL)

```bash
# Start MySQL container
docker run --name photocms-mysql \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=photocms \
  -e MYSQL_USER=photocms \
  -e MYSQL_PASSWORD=photocms123 \
  -p 3306:3306 -d mysql:8

# Update .env with MySQL credentials
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=photocms
DB_USERNAME=photocms
DB_PASSWORD=photocms123
```

---

## Configuration

### Environment Variables (.env)

```env
# Application
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com
APP_TIMEZONE=Europe/Rome

# Database (SQLite)
DB_DRIVER=sqlite
DB_DATABASE=database/database.sqlite

# Database (MySQL)
# DB_DRIVER=mysql
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=photocms
# DB_USERNAME=root
# DB_PASSWORD=secret

# Session
SESSION_LIFETIME=120

# Image Processing
IMAGE_QUALITY_AVIF=50
IMAGE_QUALITY_WEBP=72
IMAGE_QUALITY_JPEG=85
```

### Image Quality Settings

Configure via Admin Panel → Settings:

| Format | Recommended | Range |
|--------|-------------|-------|
| AVIF | 45-55% | 30-70 |
| WebP | 70-75% | 50-90 |
| JPEG | 82-85% | 70-95 |

### Responsive Breakpoints

Default breakpoints (pixels):
- **xs**: 480
- **sm**: 768
- **md**: 1200
- **lg**: 1920
- **xl**: 2560
- **xxl**: 3840

---

## Admin Panel

Access at `/admin/login` with your admin credentials.

### Dashboard
Overview of portfolio statistics, recent activity, and quick actions.

### Albums
- Create albums with title, description, and cover image
- Bulk upload images with drag-and-drop
- Reorder images via drag-and-drop
- Assign categories and tags
- Configure SEO metadata per album
- Set publish status and date
- Optional password protection
- Control download permissions

### Categories
Hierarchical organization for albums:
- Parent/child relationships
- Custom category images
- URL slug configuration
- Sort order control

### Media
Central library for all uploaded images:
- Thumbnail grid view
- Search and filter
- Variant regeneration
- File deduplication via hash tracking

### Photography Metadata
Dedicated management for:
- **Cameras**: Make and model database
- **Lenses**: Brand, focal length, aperture
- **Films**: Brand, ISO, format (35mm/120/4x5/8x10), type
- **Developers**: Chemical processes
- **Labs**: Development facilities

### SEO
Global and per-page SEO configuration:
- Meta titles and descriptions
- Open Graph settings
- Twitter Card settings
- Schema.org author/organization
- Sitemap generation

### Analytics
Built-in visitor tracking:
- Page views and sessions
- Geographic distribution
- Device and browser stats
- Bot detection and filtering
- Data retention controls

### Settings
Site-wide configuration:
- Site title, description, copyright
- Logo upload
- Image processing quality
- Responsive breakpoints
- Cache TTL
- Pagination limits

---

## Frontend Features

### Home Page
- **Hero Section**: Customizable title and subtitle
- **Infinite Gallery**: Auto-scrolling image mosaic
- **Albums Carousel**: Horizontal featured albums

### Gallery Views
- **Standard Grid**: Clean thumbnail layout
- **Masonry**: Pinterest-style varied heights
- **Magazine**: Editorial full-width layout
- **Magazine with Cover**: Featured hero image

### Lightbox
PhotoSwipe-powered fullscreen viewer:
- Keyboard navigation
- Touch gestures on mobile
- Image captions and metadata
- Download button (when enabled)

### Filtering
Advanced gallery filtering by:
- Process type (digital/analog/hybrid)
- Camera and lens
- Film stock
- Developer and lab
- ISO range
- Location
- Date range

### Responsive Design
- Mobile-first approach
- Touch-friendly interactions
- Adaptive image loading
- Collapsible navigation

---

## CLI Commands

Run via `php bin/console [command]` or access through Admin Panel → Commands.

| Command | Description |
|---------|-------------|
| `init` | Full initialization (install, migrate, seed, admin) |
| `install` | Create database tables |
| `migrate` | Run pending migrations |
| `seed` | Insert demo data |
| `db:test` | Test database connection |
| `images:generate` | Generate all image variants |
| `images:generate:variants` | Generate missing variants only |
| `sitemap` | Build XML sitemaps |
| `user:create` | Create admin user |
| `user:update` | Update admin user |
| `diagnostics` | System health check |
| `analytics:cleanup` | Remove old tracking data |
| `analytics:summarize` | Aggregate analytics data |

---

## Image Processing

### Variant Generation

When an image is uploaded, photoCMS generates optimized variants:

```
Original (storage/originals/)
    ├── image_xs.avif (480w)
    ├── image_xs.webp
    ├── image_xs.jpg
    ├── image_sm.avif (768w)
    ├── image_sm.webp
    ├── image_sm.jpg
    ├── image_md.avif (1200w)
    ...
    └── image_xxl.jpg (3840w)
```

### HTML Output

Images are served with modern `<picture>` elements:

```html
<picture>
  <source type="image/avif"
          srcset="image_sm.avif 768w, image_md.avif 1200w, image_lg.avif 1920w">
  <source type="image/webp"
          srcset="image_sm.webp 768w, image_md.webp 1200w, image_lg.webp 1920w">
  <img src="image_md.jpg"
       srcset="image_sm.jpg 768w, image_md.jpg 1200w, image_lg.jpg 1920w"
       loading="lazy"
       decoding="async"
       alt="Image description">
</picture>
```

### Regenerating Variants

```bash
# Regenerate all variants
php bin/console images:generate

# Generate only missing variants
php bin/console images:generate:variants
```

---

## Security

### Authentication
- Session-based admin authentication
- Argon2id password hashing
- Login attempt rate limiting
- Session regeneration on login

### Request Protection
- CSRF tokens on all forms
- Prepared statements (SQL injection prevention)
- Twig auto-escaping (XSS prevention)
- Input sanitization

### Headers
Security headers automatically applied:
```
Content-Security-Policy: default-src 'self'; ...
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=31536000
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: geolocation=(), microphone=(), camera=()
```

### File Upload Security
- MIME type validation
- Extension whitelist
- PHP execution disabled in upload directories
- Maximum file size enforcement

---

## Project Structure

```
photoCMS/
├── app/
│   ├── Config/
│   │   ├── bootstrap.php      # Dependency injection
│   │   └── routes.php         # Route definitions
│   ├── Controllers/
│   │   ├── Admin/             # 24 admin controllers
│   │   └── Frontend/          # 6 frontend controllers
│   ├── Services/              # Business logic
│   ├── Middlewares/           # Request/response filters
│   ├── Repositories/          # Data access layer
│   ├── Views/
│   │   ├── admin/             # Admin templates
│   │   └── frontend/          # Public templates
│   ├── Tasks/                 # CLI commands
│   ├── Support/               # Utility classes
│   └── Extensions/            # Twig extensions
├── database/
│   ├── migrations/            # Schema migrations
│   └── seeds/                 # Demo data
├── public/
│   ├── index.php              # Entry point
│   ├── assets/                # Compiled CSS/JS
│   └── media/                 # Generated images
├── resources/
│   ├── js/                    # Source JavaScript
│   └── css/                   # Source CSS
├── storage/
│   ├── originals/             # Master images
│   ├── tmp/                   # Uploads in progress
│   ├── cache/                 # Fragment cache
│   └── logs/                  # Application logs
├── vendor/                    # Composer packages
├── node_modules/              # npm packages
├── .env                       # Configuration
├── composer.json
├── package.json
├── vite.config.js
└── tailwind.config.js
```

---

## Database Schema

### Core Tables

| Table | Purpose |
|-------|---------|
| `users` | Admin accounts |
| `categories` | Album categories (hierarchical) |
| `tags` | Album tags |
| `albums` | Photo albums/galleries |
| `album_tag` | Album-tag relationships |
| `images` | Image metadata and EXIF |
| `image_variants` | Generated image files |

### Photography Metadata

| Table | Purpose |
|-------|---------|
| `cameras` | Camera body catalog |
| `lenses` | Lens catalog |
| `films` | Film stock catalog |
| `developers` | Chemical processes |
| `labs` | Development facilities |
| `locations` | Shoot locations |

### System Tables

| Table | Purpose |
|-------|---------|
| `settings` | Key-value configuration |
| `templates` | Gallery templates |
| `filter_settings` | Frontend filter visibility |
| `analytics_sessions` | Visitor sessions |
| `analytics_pageviews` | Page view tracking |
| `analytics_events` | Custom event tracking |

---

## Development

### Frontend Build

```bash
# Development with hot reload
npm run dev

# Production build
npm run build

# Preview production build
npm run preview
```

### Code Style

The project follows PSR-12 coding standards for PHP and uses ESLint for JavaScript.

### Running Tests

```bash
# Compatibility smoke test
php bin/console compat:smoke

# Database connection test
php bin/console db:test

# System diagnostics
php bin/console diagnostics
```

---

## Deployment

### Apache

Ensure `mod_rewrite` is enabled. The `.htaccess` file in `/public` handles routing.

### Nginx

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/photocms/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~* \.(avif|webp|jpg|jpeg|png|gif|ico|css|js)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

### Subdirectory Installation

photoCMS supports installation in subdirectories (e.g., `/portfolio/`). The system automatically detects the base path and adjusts all URLs accordingly.

---

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

---

## Credits

**Author**: Fabio

Built with modern open-source technologies including Slim Framework, Twig, Tailwind CSS, Vite, and many others.

---

## Support

- **Issues**: [GitHub Issues](https://github.com/fabiodalez-dev/photoCMS/issues)
- **Documentation**: See `/docs` folder for additional guides
