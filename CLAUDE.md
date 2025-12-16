# Cimaise - Photography Portfolio CMS

<!-- AUTO-MANAGED: project-description -->
## Overview

**Cimaise** is a minimalist photography CMS built with PHP 8.2+, Slim 4, Twig, and SQLite/MySQL. It provides photographers with elegant galleries, advanced image processing (AVIF, WebP, JPEG), film photography metadata support, and comprehensive SEO.

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
php bin/console install              # Install Cimaise (interactive CLI installer)
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

```text
photoCMS/
├── app/
│   ├── Config/              # Routes and configuration
│   ├── Controllers/
│   │   ├── Admin/           # Admin panel controllers (28 controllers)
│   │   └── Frontend/        # Public-facing controllers
│   ├── Extensions/          # Twig extensions
│   ├── Installer/           # Installation logic
│   ├── Middlewares/         # PSR-15 middleware (Auth, RateLimit, etc.)
│   ├── Repositories/        # Data access layer
│   ├── Services/            # Business logic (Upload, Analytics, EXIF, etc.)
│   ├── Support/             # Helpers and utilities
│   ├── Tasks/               # Symfony Console commands
│   └── Views/               # Twig templates
│       ├── admin/           # Admin panel views
│       ├── frontend/        # Public views
│       ├── installer/       # Installer wizard views
│       └── partials/        # Reusable components
├── bin/
│   ├── console              # CLI entry point
│   └── build-release.sh     # Release packaging script
├── database/
│   ├── migrations/          # Database migrations
│   ├── schema.sqlite.sql    # SQLite schema
│   ├── schema.mysql.sql     # MySQL schema (structure only)
│   └── complete_mysql_schema.sql  # MySQL schema with seed data
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
- `app/Controllers/Frontend/GalleriesController.php` - Advanced filtering for galleries (category, tags, cameras, lenses, films, locations, year, search)
- `app/Controllers/InstallerController.php` - Multi-step installer with session-based config storage
- `app/Installer/Installer.php` - Installation logic with rollback support on failure
- `app/Tasks/InstallCommand.php` - CLI installer command (interactive prompts)
- `app/Services/UploadService.php` - Image processing and variant generation (AVIF, WebP, JPEG, blur)
- `app/Services/SettingsService.php` - Settings management with JSON storage, defaults, and type tracking (null/boolean/number/string)
- `app/Services/TranslationService.php` - i18n with JSON storage (storage/translations/)
- `app/Controllers/Admin/TextsController.php` - Translation management with import/export, search/filter, and preset language support
- `app/Extensions/DateTwigExtension.php` - Twig extension for date formatting (filters: date_format, datetime_format, replace_year; functions: date_format_pattern)
- `app/Middlewares/RateLimitMiddleware.php` - Brute-force protection and API rate limiting
- `app/Views/admin/settings.twig` - Settings page with image formats, breakpoints, site config, and gallery templates
- `app/Views/admin/texts/index.twig` - Translation management UI with import/export/upload and language preset selection
- `app/Views/frontend/_album_card.twig` - Album card template with NSFW blur variant logic
- `app/Views/frontend/_breadcrumbs.twig` - Breadcrumbs with automatic JSON-LD schema generation
- `app/Views/frontend/_social_sharing.twig` - Social sharing buttons template
- `app/Views/frontend/galleries.twig` - Galleries page with filter UI and album grid
- `app/Views/installer/database.twig` - Database configuration step with SQLite/MySQL connection options and testing
- `app/Views/installer/*.twig` - 5-step installer wizard templates (index, database, admin, settings, confirm)

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
- **PDO with prepared statements**: Always use `?` or `:name` placeholders
- **Dual database support**: SQLite (default) and MySQL
- **Schema files**:
  - SQLite: `database/schema.sqlite.sql` (structure + seed data)
  - MySQL: `database/schema.mysql.sql` (structure only), `database/complete_mysql_schema.sql` (with seed data)
- **Default MySQL collation**: `utf8mb4_unicode_ci` (more compatible than `utf8mb4_0900_ai_ci`)

### Frontend
- **Twig templates**: `{% extends %}` for layouts, `{% include %}` for partials
- **Translation function**: `{{ trans('key.name') }}` for i18n
- **Date formatting**: Use `{{ date|date_format }}`, `{{ datetime|datetime_format }}`, `{{ text|replace_year }}` filters
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
- **RateLimitMiddleware**: Session-based tracking for authenticated and post-session endpoints
- **FileBasedRateLimitMiddleware**: File-based tracking for pre-session endpoints (login, MySQL test, analytics)
  - Parameters: `(storage_path, max_requests, time_window_seconds, identifier)`
  - Stores request counts in `storage/tmp/` directory
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
  - Password-protected: `$_SESSION['album_access'][$albumId]` (24h TTL) via POST `/album/{slug}/unlock` (rate limited: 5 req/10min)
  - NSFW confirmation: `$_SESSION['nsfw_confirmed'][$albumId]` via POST `/album/{slug}/nsfw-confirm` (rate limited: 10 req/5min)
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

### Advanced Filtering (Galleries)
- Multi-criteria filtering: categories, tags, cameras, lenses, films, locations, year, search
- AJAX-based filter API endpoint (`/galleries/filter`)
- Client-side filter state management with URL parameters
- Filter options dynamically generated from database (with album counts)
- Empty state handling when no galleries match filters

### Frontend JavaScript (Uppy Upload, TinyMCE, GSAP, Tom Select)
- **Uppy file upload**: XHR-based upload with CSRF token, file restrictions (JPEG, PNG, WebP), drag-drop support
- **Progress tracking**: Real-time per-file and total progress UI with visual bars and file list
- **Error handling**: XSS-safe filename sanitization, duplicate detection, user notifications (toast messages)
- **File deduplication**: Compares files by name and size to prevent redundant uploads
- **TinyMCE editor**: Configured with link, lists, and autoresize plugins for content editing
- **Tom Select dropdowns**: Auto-initialized on select elements with `tom-select` class, supports multi-select
- **Sortable drag-drop**: Sortablejs for reorderable gallery/image lists (client-side only)
- **GSAP animations**: Imported for smooth transitions and animations (gsap library)
- **Idempotent initialization**: Guards against double-initialization of upload areas with `_uppyInitialized` flag
- **Global instance tracking**: `window.uppyInstances` array for proper cleanup and SPA re-initialization

### Auto-Repair (Bootstrap)
- Auto-creates `.env` from template if missing and `database/template.sqlite` exists
- Auto-copies `template.sqlite` to `database.sqlite` if database is empty
- Auto-redirects to `/install` if installation incomplete

### Installer Flow (Multi-Step Wizard)
- **5-step process**: Welcome (requirements check) → Database → Admin User → Settings → Confirm & Install
- **Session-based config storage**: Each step stores data in `$_SESSION['install_*_config']` arrays (db_config, admin_config, settings_config)
- **CSRF protection**: All forms include CSRF token validation with hash_equals comparison
- **MySQL auto-detection**: AJAX endpoint `/install/test-mysql` tests connection and auto-detects charset/collation (rate limited: 10 req/5min)
- **Separate database fields**: Different input fields for SQLite path vs MySQL database name in database step
- **Default collation**: Uses `utf8mb4_unicode_ci` (more compatible than `utf8mb4_0900_ai_ci`)
- **Database connection testing**: Validates MySQL/SQLite connection before proceeding to next step
- **Visual step indicator**: Shows progress through installer stages with step numbers
- **Settings step**: Collects site title, description, copyright (with {year} placeholder), email, language (en/it), date format (Y-m-d/d-m-Y), and optional logo upload
- **Rollback on failure**: Auto-cleanup of .env and SQLite database files if installation fails; drops MySQL tables via `rollback()` method
- **State tracking**: `Installer` class tracks `envWritten`, `dbCreated`, `createdDbPath` for proper rollback logic
- **CLI installer**: `php bin/console install` provides interactive command-line installation with SymfonyStyle prompts for all configuration
- **Post-install redirect**: Redirects to `/admin/login` after successful installation
- **Base path detection**: Handles subdirectory installations by detecting and removing '/public' suffix from base path

### Breadcrumbs & Schema
- **Auto-generated breadcrumbs**: Dynamically builds breadcrumb trail based on page context (home, category, tag, album, about)
- **JSON-LD schema**: Automatically generates BreadcrumbList structured data for SEO
- **Subdirectory-safe**: Uses `base_path` for correct URL generation in subdirectory installations
- **Translation support**: All breadcrumb labels use `trans()` function for i18n

### Date Formatting & Dynamic Placeholders
- **Twig filters**: `date_format` (date only), `datetime_format` (date + time), `replace_year` (replaces {year} with current year)
- **Twig functions**: `date_format_pattern()` returns current format for JavaScript date pickers
- **Format setting**: Configurable via `date.format` setting stored in database (Y-m-d or d-m-Y)
- **Backend helper**: DateHelper class provides consistent formatting across controllers
- **Copyright placeholders**: Use `{year}` in copyright text (e.g., "© {year} Photography Portfolio") - auto-replaced on render
- **Extension class**: `DateTwigExtension` registers all date-related filters and functions
- **Used extensively**: Album shoot dates, user timestamps, admin panel date displays, footer copyright

### Translation Management (i18n)
- **TextsController**: Admin panel for managing translations with CRUD operations, search/filter, and context grouping
- **Import/Export system**: Download translations as JSON, import from preset languages, upload custom JSON files
- **Three import modes**:
  - Merge: Update existing keys, add new ones (default)
  - Replace: Overwrite existing keys, add new ones
  - Skip: Only add new keys, leave existing unchanged
- **Preset languages**: Server-side language files with versioning (e.g., en.json v1.0, it.json v1.0)
- **Save as preset**: Upload custom JSON and save it as a preset language file in storage/translations/
- **Context grouping**: Organize translations by context (general, admin, nav, etc.) for easier management
- **Inline editing**: AJAX-based inline text editing without page reload (with CSRF protection)
- **TranslationService**: Backend service for database-driven i18n with fallback to JSON files
- **Usage in templates**: `{{ trans('key.name') }}` function for all translatable text

### Social Sharing
- **Template component**: `_social_sharing.twig` for social media sharing buttons
- **Configurable networks**: Dynamically enables/disables social networks via settings
- **URL encoding**: Properly encodes title and URL for sharing parameters
- **Multiple link types**: Supports standard URLs, JavaScript handlers, and display-only buttons
- **Security**: Validates share URLs, adds `rel="noopener noreferrer"` for external links
- **Icon support**: Uses FontAwesome icons for network branding

<!-- END AUTO-MANAGED -->

<!-- AUTO-MANAGED: best-practices -->
## Best Practices

### Security
- Never commit `.env` (contains secrets)
- Always use PDO prepared statements
- Validate CSRF tokens on POST requests
- Rate limit all sensitive endpoints (login: 5 req/10min, album unlock: 5 req/10min, NSFW confirm: 10 req/5min, protected media: 100-200 req/min)
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
