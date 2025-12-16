# Cimaise - Photography Portfolio CMS

<!-- AUTO-MANAGED: project-description -->
## Overview

**Cimaise** is a minimalist photography CMS built with PHP 8.2+, Slim 4, Twig, and SQLite/MySQL. It provides photographers with elegant galleries, advanced image processing (AVIF, WebP, JPEG), film photography metadata support, and comprehensive SEO optimization.

Key features:
- Multi-database support (SQLite default, MySQL optional)
- Responsive image variants with modern formats
- Multiple gallery templates with NSFW protection
- Built-in analytics and cookie consent (GDPR)
- Plugin architecture for extensibility

<!-- END AUTO-MANAGED -->

<!-- AUTO-MANAGED: build-commands -->
## Build & Development Commands

### PHP Backend
```bash
composer install          # Install PHP dependencies
composer test             # Run PHPUnit tests
php bin/console           # CLI commands (migrations, seeding, etc.)
```

### Frontend Assets (Vite + Tailwind)
```bash
npm install               # Install Node dependencies
npm run dev               # Start Vite dev server
npm run build             # Build production assets
```

### CLI Console Commands
```bash
php bin/console migrate              # Run database migrations
php bin/console seed                 # Seed default data
php bin/console user:create          # Create admin user
php bin/console images:generate      # Generate image variants
php bin/console nsfw:blur:generate   # Generate blur variants for NSFW albums
php bin/console sitemap:generate     # Generate sitemap
php bin/console analytics:cleanup    # Clean old analytics data
```

### Release Build
```bash
bash bin/build-release.sh            # Build release package
```

<!-- END AUTO-MANAGED -->

<!-- AUTO-MANAGED: architecture -->
## Architecture

```
photoCMS/
├── app/
│   ├── Config/              # Routes and configuration
│   ├── Controllers/
│   │   ├── Admin/           # Admin panel controllers (28 controllers)
│   │   └── Frontend/        # Public-facing controllers
│   ├── Extensions/          # Twig extensions
│   ├── Middlewares/         # PSR-15 middleware (Auth, RateLimit, etc.)
│   ├── Repositories/        # Data access layer
│   ├── Services/            # Business logic (Upload, Analytics, EXIF, etc.)
│   ├── Support/             # Helpers and utilities
│   ├── Tasks/               # Symfony Console commands
│   └── Views/               # Twig templates
│       ├── admin/           # Admin panel views
│       ├── frontend/        # Public views
│       └── partials/        # Reusable components
├── bin/
│   ├── console              # CLI entry point
│   └── build-release.sh     # Release packaging script
├── database/
│   ├── migrations/          # Database migrations
│   ├── schema.sqlite.sql    # SQLite schema
│   └── schema.mysql.sql     # MySQL schema
├── public/
│   ├── index.php            # Web entry point
│   ├── installer.php        # Web installer
│   ├── assets/              # Compiled CSS/JS
│   └── media/               # Uploaded images and variants
├── plugins/                 # Plugin directory
├── resources/               # Source assets (pre-build)
├── storage/
│   ├── translations/        # i18n JSON files (en.json, it.json)
│   ├── cache/               # Template cache
│   ├── logs/                # Application logs
│   └── tmp/                 # Temporary files
└── vendor/                  # Composer dependencies
```

### Key Files
- `public/index.php` - Application bootstrap with auto-repair logic and base path detection
- `public/router.php` - PHP built-in server router (routes /media/* through PHP for access control)
- `app/Config/routes.php` - All route definitions (120+ routes including protected media)
- `app/Controllers/Frontend/MediaController.php` - Server-side protected media serving with session validation
- `app/Services/UploadService.php` - Image processing and variant generation (AVIF, WebP, JPEG, blur)
- `app/Services/TranslationService.php` - i18n with JSON storage (storage/translations/)
- `app/Middlewares/RateLimitMiddleware.php` - Brute-force protection and API rate limiting
- `app/Views/frontend/_album_card.twig` - Album card template with NSFW blur variant logic
- `app/Views/frontend/_social_sharing.twig` - Social sharing buttons template

### Data Flow
1. Request → `public/index.php` → Slim App
2. Middleware chain (Session, Auth, RateLimit, etc.)
3. Route → Controller → Service → Repository → PDO
4. Response → Twig render or JSON

<!-- END AUTO-MANAGED -->

<!-- AUTO-MANAGED: conventions -->
## Code Conventions

### PHP
- **PSR-4 autoloading**: `App\` namespace maps to `app/`
- **Strict types**: All files use `declare(strict_types=1)`
- **Controllers**: Extend `BaseController`, receive `$db` and `Twig` in constructor
- **Services**: Stateless classes with `$db` dependency injection
- **Naming**: PascalCase for classes, camelCase for methods/variables

### Database
- **PDO with prepared statements**: Always use `?` placeholders
- **Dual database support**: SQLite (default) and MySQL
- **Schema files**: `database/schema.sqlite.sql` and `database/schema.mysql.sql`

### Frontend
- **Twig templates**: `{% extends %}` for layouts, `{% include %}` for partials
- **Translation function**: `{{ trans('key.name') }}` for i18n
- **Tailwind CSS**: Utility-first styling
- **JavaScript**: ES6 modules, localStorage with Safari-safe wrappers

### Middleware Pattern
```php
$app->get('/path', function(...) { ... })
    ->add(new AuthMiddleware($container['db']));
```

### Media Routing (Security-Critical)
- **All /media/* requests** go through PHP (not served directly by web server)
- `public/router.php` routes `/media/*` to `index.php` for mandatory access control
- Routes defined in order: `/media/protected/{id}/*` → `/media/{path:.*}` (catch-all)
- MediaController validates session state before streaming any protected/NSFW album images
- Prevents bypassing NSFW/password protection via direct file access or URL manipulation
- Blur variants (for previews) always allowed without session validation

<!-- END AUTO-MANAGED -->

<!-- AUTO-MANAGED: patterns -->
## Detected Patterns

### Image Processing
- Multi-format variants: AVIF → WebP → JPEG fallback
- Size variants: sm (400w), md (800w), lg (1200w), xl (1600w), xxl (2000w)
- NSFW blur variants: Server-side Gaussian blur for age-gated content
- Uses Imagick when available, falls back to GD

### Rate Limiting
- Session-based tracking for authenticated endpoints
- File-based tracking for pre-session endpoints (login)
- Multi-language error detection for failed attempts

### Cookie Consent (GDPR)
- Silktide Consent Manager integration
- Three categories: Essential, Analytics, Marketing
- Safari private browsing safe (localStorage wrappers)

### Protected Media Serving (Server-Side)
- **MediaController**: Centralized server-side validation before streaming files from protected/NSFW albums
- **Three routes**:
  - `/media/protected/{id}/{variant}.{format}` - Protected image variants (rate limited: 100 req/min)
  - `/media/protected/{id}/original` - Protected original images (rate limited: 100 req/min)
  - `/media/{path:.*}` - Public media with protection validation (rate limited: 200 req/min)
- **Session-based access**:
  - Password-protected: `$_SESSION['album_access'][$albumId]` (24h TTL) via POST `/album/{slug}/unlock`
  - NSFW confirmation: `$_SESSION['nsfw_confirmed'][$albumId]` via POST `/album/{slug}/nsfw-confirm` (rate limited: 10 req/10min)
  - Blur variants always allowed (for preview purposes)
- **Template security**: NSFW album cards only show blur variants, never expose real image URLs in srcset or data-src
- **Path traversal protection**: Validates realpath against allowed directories (storage/ or public/media/)
- **MIME type validation**: Only serves image/* types (jpeg, webp, avif, png)
- **ETag caching**: `private, max-age=3600, must-revalidate` for variants
- **Router enforcement**: `public/router.php` routes all /media/* requests through PHP (prevents direct file access bypass)

### Authentication
- Session-based admin auth with `$_SESSION['admin_id']`
- Password hashing with `password_hash()`
- CSRF tokens on all forms

### Auto-Repair (Bootstrap)
- Auto-creates `.env` from template if missing and `database/template.sqlite` exists
- Auto-copies `template.sqlite` to `database.sqlite` if database is empty
- Auto-redirects to `/install` if installation incomplete

<!-- END AUTO-MANAGED -->

<!-- AUTO-MANAGED: best-practices -->
## Best Practices

### Security
- Never commit `.env` (contains secrets)
- Always use PDO prepared statements
- Validate CSRF tokens on POST requests
- Rate limit all sensitive endpoints (login: 5/10min, media: 100-200/min, NSFW confirm: 10/5min)
- Sanitize file uploads (allowed extensions, MIME validation)
- Route all /media/* requests through PHP for mandatory access control
- Validate file paths with realpath to prevent directory traversal
- Check MIME types server-side before serving files
- NSFW protection: Never expose real image URLs in templates for age-gated content (only blur variants)
- Use server-side session validation for protected albums (not client-side localStorage)
- MediaController enforces session checks before streaming any protected/NSFW image
- Blur variants always allowed for preview purposes (no session required)
- Admin users bypass all access restrictions

### Performance
- Use responsive images with `<picture>` element
- Lazy load images with `loading="lazy"`
- Cache database queries where appropriate
- Run `npm run build` for production assets

### Testing
- Run `composer test` for PHPUnit tests
- Test on both SQLite and MySQL
- Verify rate limiting with failed login attempts

<!-- END AUTO-MANAGED -->

<!-- MANUAL -->
## Project Notes

This section is for manual notes and project-specific information.

### Recent Completed Work
- Server-side NSFW and protected album enforcement (commits eb289e5-d1435a2)
- Comprehensive OWASP security hardening
- Cookie consent banner with GDPR compliance
- SEO enhancements (robots.txt, meta tags)

### Pending Tasks
None currently.

### Important Reminders
- Never list Claude as git commit co-author
- Translation files in `storage/translations/` are JSON format
- Admin panel at `/admin/login`

<!-- END MANUAL -->
