# Cimaise - Photography Portfolio CMS

<!-- AUTO-MANAGED: project-description -->
## Overview

**Cimaise** is a photography portfolio CMS built by photographers, for photographers. Built with PHP 8.2+, Slim 4, Twig, and SQLite/MySQL.

Tagline: "The photography portfolio CMS that gets out of your way."

Core value propositions:
- **Blazing Fast**: AVIF, WebP, JPEG optimization with 6 responsive breakpoints. Automatic `<picture>` elements with format fallback. Configurable quality per format (AVIF: 50%, WebP: 75%, JPEG: 85%)
- **Film-Ready**: Track cameras, lenses, film stocks (35mm/120/4x5), developers, labs. Unlike generic CMSs, speaks photographer language
- **SEO That Works**: Server-side rendering, JSON-LD structured data (BreadcrumbList, ImageGallery, Organization, LocalBusiness), Open Graph, Twitter Cards, automatic XML sitemaps
- **Privacy First**: GDPR-compliant cookie consent (Silktide), privacy-focused analytics, no third-party tracking by default
- **Truly Yours**: Self-hosted, open source (MIT), no vendor lock-in, no monthly fees

Gallery templates: Classic Grid, Masonry, Magazine, Magazine + Cover
Home templates: Classic (masonry + infinite scroll), Modern (fixed sidebar + grid layout), Parallax (vertical scrolling grid with parallax effect), Masonry (pure CSS column-based wall), Snap Albums (split-screen album covers), Gallery Wall (horizontal scrolling individual images)

Key features:
- **Password-protected galleries**: Per-album passwords with session-based access (24h TTL), clean URLs, rate-limited brute-force protection
- **NSFW/Adult content mode**: Blur previews, age gate (18+ confirmation), per-album setting, server-side enforcement
- **Advanced filtering**: Multi-criteria (categories, tags, cameras, lenses, films, locations, year, search), AJAX-based, shareable filter URLs
- **Automatic image optimization**: Upload once, generates 5 sizes × 3 formats (AVIF/WebP/JPEG), configurable quality settings
- **Multilingual**: Full i18n support (frontend + admin), translation management UI, preset languages (English, Italian)

Admin features: Drag & drop reordering, bulk upload (100+ images), inline editing, real-time preview, equipment-based browsing, full-text search, visual template selector

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
npm run build             # Build production assets (outputs to public/assets/)
```

**Vite Entry Points** (`vite.config.js`):
- `resources/js/hero.js` → `public/assets/js/hero.js`
- `resources/js/home.js` → `public/assets/js/home.js`
- `resources/js/home-modern.js` → `public/assets/js/home-modern.js` (Lenis smooth scroll + infinite grid)
- `resources/js/home-gallery.js` → `public/assets/js/home-gallery.js` (Gallery Wall horizontal scroll)
- `resources/js/smooth-scroll.js` → `public/assets/js/smooth-scroll.js`
- `resources/admin.js` → `public/assets/admin.js`

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
│   │   ├── Admin/           # Admin panel controllers (32 controllers)
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
│       ├── errors/          # Error pages (404, 500) for frontend and admin
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
│   │   ├── css/             # Compiled stylesheets (home-modern.css, etc.)
│   │   └── js/              # Compiled JavaScript (hero.js, home.js, home-modern.js, smooth-scroll.js)
│   └── media/               # Uploaded images and variants
├── plugins/                 # Plugin directory
├── resources/               # Source assets (pre-build)
│   └── js/                  # Source JavaScript (hero.js, home.js, home-modern.js, smooth-scroll.js)
├── storage/
│   ├── translations/        # i18n JSON files (en.json, it.json, en_admin.json, it_admin.json)
│   ├── cache/               # Template cache
│   ├── logs/                # Application logs
│   └── tmp/                 # Temporary files
└── vendor/                  # Composer dependencies
```

### Key Files
- `public/index.php` - Application bootstrap with auto-repair logic, base path detection, and global Twig variable injection (SEO settings, analytics, site config injected as globals for frontend templates)
- `public/router.php` - PHP built-in server router (routes /media/* through PHP for access control)
- `app/Config/routes.php` - All route definitions (240+ routes including protected media, custom fields management)
- `app/Controllers/BaseController.php` - Base controller with helper methods (validateCsrf, csrfErrorJson, isAjaxRequest, isAdmin, redirect with base path handling)
- `app/Controllers/Frontend/MediaController.php` - Server-side protected media serving with session validation
- `app/Controllers/Frontend/GalleriesController.php` - Advanced filtering for galleries (category, tags, cameras, lenses, films, locations, year, search)
- `app/Controllers/InstallerController.php` - Multi-step installer with session-based config storage
- `app/Installer/Installer.php` - Installation logic with rollback support on failure
- `app/Tasks/InstallCommand.php` - CLI installer command (interactive prompts)
- `app/Services/UploadService.php` - Image processing and variant generation (AVIF, WebP, JPEG, blur) with magic number validation and NSFW blur generation
- `app/Services/FaviconService.php` - Favicon generation in multiple sizes (16x16, 32x32, 96x96, 180px Apple touch, 192x192, 512x512) using GD library
- `app/Services/NavigationService.php` - Navigation category fetching service for frontend menus (ordered by sort_order/name)
- `app/Services/VariantMaintenanceService.php` - Daily maintenance service for generating missing image variants and NSFW blur variants with file-based locking
- `app/Controllers/Admin/UploadController.php` - Image upload endpoint with automatic NSFW blur generation (checks album is_nsfw flag), logo upload with automatic favicon generation
- `app/Services/SettingsService.php` - Settings management with JSON storage, defaults, and type tracking (null/boolean/number/string)
- `app/Services/TranslationService.php` - i18n with dual-scope support (frontend/admin), JSON storage (storage/translations/), separate language tracking
- `app/Services/CustomFieldService.php` - Custom metadata field management with CRUD operations, field types (text/select/multi_select), value management, and image/album metadata inheritance
- `app/Services/MetadataExtensionService.php` - Plugin extensibility service for arbitrary metadata storage on entities (images, albums, pages) with dual-database upsert support (MySQL/SQLite), batch enrichment, and hook integration
- `app/Controllers/Admin/CustomFieldTypesController.php` - Admin CRUD for custom field types with system vs custom distinction, icon selection, visibility toggles (lightbox/gallery)
- `app/Controllers/Admin/CustomFieldValuesController.php` - Admin management for custom field predefined values with drag-drop reordering, validation, and duplicate detection
- `app/Controllers/Admin/TextsController.php` - Translation management with import/export, search/filter, scope selector, server-side language dropdown via `getAvailableLanguages()`, and preset language support
- `app/Controllers/Admin/SeoController.php` - SEO settings management with Open Graph, Twitter Cards, Schema.org (Person, Organization, LocalBusiness), analytics integration (Google Tag Manager, GA4), image metadata, and sitemap generation
- `app/Controllers/Admin/SocialController.php` - Social sharing settings management with network enable/disable, ordering, AJAX/form support
- `app/Controllers/Admin/TemplatesController.php` - Gallery template management (edit only, creation/deletion disabled) with responsive column configuration, layout settings, PhotoSwipe options, and magazine-specific animations
- `app/Views/admin/templates/edit.twig` - Template editor with i18n-compliant layout options (masonry_full uses trans() function)
- `app/Controllers/Admin/PagesController.php` - Pages management (home, about, galleries) with home template selection (classic/modern/parallax/masonry), hero sections, gallery text content, masonry gap/column settings
- `app/Controllers/Admin/SettingsController.php` - Site settings with image formats/quality/breakpoints, gallery templates, date format, site language, reCAPTCHA configuration (requires both site and secret keys to enable), performance settings, admin debug logs toggle, triggers favicon generation after logo upload
- `app/Controllers/Frontend/PageController.php` - Frontend page rendering with SEO builder, template normalization, home template routing (classic/modern/parallax/masonry), image variant path resolution with file existence checks
- `app/Extensions/DateTwigExtension.php` - Twig extension for date formatting (filters: date_format, datetime_format, replace_year; functions: date_format_pattern)
- `app/Middlewares/RateLimitMiddleware.php` - Brute-force protection and API rate limiting
- `app/Middlewares/SecurityHeadersMiddleware.php` - Security headers (CSP, HSTS, X-Frame-Options) with per-request nonce generation
- `app/Views/admin/_layout.twig` - Admin panel layout with CSP nonce, responsive sidebar navigation (fixed mobile positioning with `top-0 left-0 h-screen overscroll-contain`), TinyMCE toolbar fixes, window globals (`window.basePath`, `window.cspNonce`, `window.__ADMIN_DEBUG`), admin i18n system (`window.adminTranslations`, `window.adminT()`, `window.adminTf()` for parameterized translations)
- `app/Views/admin/settings.twig` - Settings page with image formats, breakpoints, site config, gallery templates, and admin debug logs toggle
- `app/Views/admin/texts/index.twig` - Translation management UI with import/export/upload, scope selector, server-side language dropdown, and language preset selection
- `app/Views/admin/albums/*.twig` - Album CRUD views with full i18n via trans() function
- `app/Views/frontend/_layout.twig` - Frontend layout with SEO meta tags, Open Graph, Twitter Cards, JSON-LD schemas (Person/Organization, BreadcrumbList, LocalBusiness), CSP nonce support
- `app/Views/frontend/_layout_modern.twig` - Modern template layout with Lenis smooth scroll, minimal header, mega menu overlay, JSON-LD schemas (BreadcrumbList, LocalBusiness)
- `app/Views/frontend/home.twig` - Classic home template with masonry layout and infinite scroll
- `app/Views/frontend/home_modern.twig` - Modern home template with fixed sidebar (filters, info), scrollable grid (two-column infinite scroll), and mobile classic header integration (imports `_seo_macros.twig`)
- `app/Views/frontend/home_parallax.twig` - Parallax home template with three-column grid and smooth scroll parallax effects
- `app/Views/frontend/home_masonry.twig` - Pure masonry home template with CSS column-based layout, configurable gap and column counts per device
- `app/Views/frontend/home_snap.twig` - Snap Albums template with split-screen layout (45% info / 55% images), synchronized vertical scrolling, snap indicators, mobile vertical scroll fallback
- `app/Views/frontend/home_gallery.twig` - Gallery Wall template with horizontal scrolling wall of individual images, responsive aspect ratios (1.5x horizontal / 0.67x vertical), sticky positioning, mobile 2-column grid fallback
- `app/Views/admin/pages/home.twig` - Home page settings with visual template selector (classic/modern/parallax/masonry/snap/gallery), hero sections, gallery text, scroll direction, masonry-specific gap/column controls
- `app/Views/admin/custom_field_types/index.twig` - Custom field types listing with icon display, field type badges, visibility indicators (lightbox/gallery), manage values link
- `app/Views/admin/custom_field_types/create.twig` - Custom field type creation form with name/label validation, icon picker, field type selector (text/select/multi_select), visibility toggles
- `app/Views/admin/custom_field_types/edit.twig` - Custom field type editor (system types cannot be edited), prevents name changes on existing types
- `app/Views/admin/custom_field_values/index.twig` - Custom field values management with add form sidebar, sortable table, extra_data support, duplicate detection
- `app/Views/admin/albums/create.twig` - Album creation with custom field support
- `app/Views/admin/albums/edit.twig` - Album editor with custom field support
- `app/Views/admin/pages/galleries.twig` - Galleries page editor with custom field support
- `app/Views/frontend/_album_card.twig` - Album card template with NSFW blur variant logic
- `app/Views/frontend/album.twig` - Album detail view with NSFW age gate, error handling restores full gate state on consent sync failure
- `app/Views/frontend/_breadcrumbs.twig` - Breadcrumbs with automatic JSON-LD schema generation, responsive spacing (pb-5 md:pb-0), compact gap layout (gap-x-1 gap-y-1), leading-normal line-height for tight wrapping
- `app/Views/frontend/_social_sharing.twig` - Social sharing buttons template
- `app/Views/errors/404.twig` - Frontend 404 error page with i18n (extends frontend layout)
- `app/Views/errors/404_admin.twig` - Admin 404 error page with i18n (extends admin layout)
- `app/Views/errors/500.twig` - Frontend 500 error page with i18n and optional error message display
- `app/Views/errors/500_admin.twig` - Admin 500 error page with i18n and optional error message display
- `app/Views/frontend/_seo_macros.twig` - Reusable Twig macros for SEO title generation (seo_title macro combines image alt/caption, album title, and site title)
- `app/Views/frontend/_caption.twig` - Reusable macro for building image captions with equipment metadata (camera, lens, film, developer, lab, location, EXIF data)
- `app/Views/frontend/_gallery_magazine_content.twig` - Magazine split gallery template with responsive three-column layout, infinite scroll, and PhotoSwipe integration
- `app/Views/frontend/galleries.twig` - Galleries page with filter UI and album grid
- `app/Views/installer/database.twig` - Database configuration step with SQLite/MySQL connection options and testing
- `app/Views/installer/*.twig` - 5-step installer wizard templates (index, database, admin, settings, confirm) - post_setup step removed

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
  - Use `validateCsrf($request)` for timing-safe CSRF validation with `hash_equals()`
  - Use `csrfErrorJson($response)` for 403 JSON error responses
  - Use `isAjaxRequest($request)` to detect AJAX/JSON requests (checks `X-Requested-With` header and `Accept: application/json`)
  - Use `isAdmin()` to check if user is authenticated admin via `$_SESSION['admin_id']`
  - Use `redirect($path)` to prepend base path with automatic `/public` suffix removal
- **Services**: Stateless classes with `$db` dependency injection
  - **Graceful degradation**: Optional services use nullable properties with try-catch initialization
  - Pattern: `private ?ServiceClass $service = null;` + `try { $this->service = new ServiceClass(...); } catch (\Throwable) { }`
  - Example: AlbumsController initializes CustomFieldService but continues without it if tables don't exist yet
- **Naming**: PascalCase for classes, camelCase for methods/variables

### Database
- **PDO with prepared statements**: Always use `?` or `:name` placeholders
- **Dual database support**: SQLite (default) and MySQL
- **Schema files**:
  - SQLite: `database/schema.sqlite.sql` (structure + seed data)
  - MySQL: `database/schema.mysql.sql` (structure only), `database/complete_mysql_schema.sql` (with seed data)
- **Default MySQL collation**: `utf8mb4_unicode_ci` (more compatible than `utf8mb4_0900_ai_ci`)
- **Settings storage**: SettingsService stores values as JSON with type tracking (null, boolean, number, string)
- **PWA settings**: `pwa.theme_color` and `pwa.background_color` for configurable PWA colors (defaults: `#ffffff`)
- **Plugin extensibility tables**:
  - `metadata_extensions`: Key-value metadata storage for plugins (entity_type, entity_id, extension_key, extension_value, plugin_id)
    - MySQL: Uses `ON UPDATE CURRENT_TIMESTAMP` for automatic timestamp updates (not `NOW()`)
    - SQLite: Uses `ON CONFLICT DO UPDATE` for upserts with `CURRENT_TIMESTAMP`
  - `custom_field_types`: User-defined field definitions (name, label, icon, field_type, is_system, show_in_lightbox, show_in_gallery)
    - UNIQUE constraint on `name` column (no separate index needed)
  - `custom_field_values`: Predefined values for select/multi_select fields (field_type_id, value, extra_data, sort_order)
  - `image_custom_fields`, `album_custom_fields`: Junction tables linking entities to custom field values
  - UNIQUE constraints: `(entity_type, entity_id, extension_key)` on metadata_extensions, `(field_type_id, value)` on custom_field_values

### Frontend
- **Twig templates**: `{% extends %}` for layouts, `{% include %}` for partials, `{% import %}` for macros
  - Template inheritance: `_layout.twig` (classic frontend), `_layout_modern.twig` (modern template with Lenis)
  - Home templates: `home.twig` (classic masonry), `home_modern.twig` (grid with sidebar), `home_parallax.twig` (parallax grid with smooth scroll), `home_masonry.twig` (pure CSS column-based wall), `home_snap.twig` (split-screen album covers), `home_gallery.twig` (horizontal scrolling image wall)
  - Macro imports: `{% import 'frontend/_seo_macros.twig' as Seo %}` for reusable SEO title generation
- **Translation function**: `{{ trans('key.name') }}` for i18n (used consistently across all templates)
- **Complete i18n coverage**: All templates use `trans()` function (2216+ occurrences across 82 files)
  - Admin panel: `trans('admin.*')` keys in all CRUD views (albums, categories, tags, settings, analytics, etc.)
  - Frontend: `trans('frontend.*')`, `trans('nav.*')`, `trans('filter.*')` keys for public-facing UI
  - Error pages: `errors.*` keys for 404/500 templates (frontend and admin variants)
  - Translation files: `en.json`, `it.json` (frontend), `en_admin.json`, `it_admin.json` (admin panel)
- **Admin i18n scope**: Albums, settings, pages, SEO, social, privacy, filters, analytics, updates, custom fields all use `admin.*` namespace
  - Custom fields: `admin.custom_fields.*` (title, description, add_type, name, label, type, visibility, values, etc.)
- **Error page templates**: Extend layout templates (not dedicated error layout)
  - Frontend errors: `errors/404.twig`, `errors/500.twig` extend `frontend/_layout.twig`
  - Admin errors: `errors/404_admin.twig`, `errors/500_admin.twig` extend `admin/_layout.twig`
  - Translation keys: `errors.404.*` and `errors.500.*` (title, message, back_home, back_dashboard)
  - Consistent styling: Large numeric code display, centered layout, i18n-based messaging
- **Date formatting**: Use `{{ date|date_format }}`, `{{ datetime|datetime_format }}`, `{{ text|replace_year }}` filters
- **CSP nonce**: Use `{{ csp_nonce() }}` for inline scripts and styles (required by Content Security Policy)
  - Generated per-request by SecurityHeadersMiddleware via `base64_encode(random_bytes(16))`
  - Required for all inline `<script>` and `<style>` tags on frontend routes
  - JSON-LD scripts also require nonce: `<script type="application/ld+json" nonce="{{ csp_nonce() }}">`
  - Admin routes use relaxed CSP policy (allows `unsafe-inline` and `unsafe-eval` for TinyMCE)
- **Image error handling**: Use `data-fallback="hide"` attribute for graceful degradation (not inline `onerror` for CSP compliance)
- **HTML5 data attributes**: Use `data-*` attributes for JavaScript element targeting and metadata storage
  - Element markers: `data-inf-item`, `data-filter` for querySelector/querySelectorAll selection
  - Metadata storage: `data-work-title`, `data-work-copy`, `data-album-id` for getAttribute() access
  - Target containers: `data-image-grid-title`, `data-image-grid-copy` for dynamic content insertion
  - Always prefix custom attributes with `data-` for HTML5 compliance and CSP compatibility
- **Tailwind CSS**: Utility-first styling (classic templates)
- **Custom CSS**: Modern template uses custom CSS (`home-modern.css`) with CSS variables (`--modern-*`)
- **JavaScript**: ES6 modules, localStorage with Safari-safe wrappers, npm package imports (Lenis via Vite)
- **Admin panel UI conventions**:
  - Responsive sticky footers: `fixed bottom-0 left-0 lg:left-64 right-0` for mobile sidebar compatibility (10+ admin forms)
  - Bottom padding: `pb-48` on form containers to prevent content overlap with sticky action bars
  - Class naming: `.admin-sticky-actions` for consistent footer styling across admin templates
  - Z-index hierarchy: `.admin-sticky-actions { z-index: 9000 }` above dropdowns (1000) for proper layering
  - Mobile sidebar shift: `.sidebar-open` class on body shifts sticky actions via CSS transition when sidebar opens
- **Global Twig variables**: SEO settings and site config injected in `public/index.php` for all frontend templates
  - Injected only for frontend routes (not admin routes) via `$twig->getEnvironment()->addGlobal()`
  - SEO globals: `og_site_name`, `og_type`, `og_locale`, `twitter_card`, `twitter_site`, `twitter_creator`, `robots`
  - Schema globals: `schema` array with `enabled`, `author_name`, `author_url`, `organization_name`, `organization_url`, `image_copyright_notice`, `image_license_url`
  - Analytics globals: `analytics_gtag`, `analytics_gtm`
  - Lightbox globals: `lightbox_show_exif` (boolean) controls EXIF metadata display in PhotoSwipe, exposed as `window.__showExifInLightbox`
  - Available in all frontend templates without passing through controllers
  - Fallback defaults provided in catch block if settings service unavailable

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
- NSFW blur variants: Automatically generated during upload if album is_nsfw flag is true
  - Generated via `UploadService::generateBlurredVariant()` after successful image ingest
  - Fails gracefully with warning log if blur generation fails (upload still succeeds)
- Favicon generation: Automatic multi-size favicon creation on logo upload
  - Triggered by `UploadController::uploadSiteLogo()` after logo save
  - Generates 7 sizes: favicon.ico (32px), 16x16, 32x32, 96x96, apple-touch-icon (180px), android-chrome 192x192 and 512x512
  - Uses GD library with transparency preservation and high-quality resampling
- **Image upload validation**: Controllers use `validateImageUpload()` method for comprehensive security
  - Magic number validation via `finfo(FILEINFO_MIME_TYPE)` checks actual file content, not just extension
  - Allowed MIME types: image/jpeg, image/png, image/webp, image/avif
  - Used in: PagesController (about photo), CategoriesController (category images)
  - Returns boolean; rejected uploads logged and fail gracefully with user-facing error
- **EXIF metadata extraction**: ExifService extracts camera, lens, and shooting data from uploaded images
  - Camera info: Make, Model from IFD0 or EXIF tags
  - Lens detection: Multiple tag support (UndefinedTag:0xA434, LensModel, LensMake, LensSpecification)
  - Exposure settings: ISO, shutter speed, aperture (FNumber), focal length
  - GPS extraction: Converts DMS to decimal degrees with hemisphere detection
  - Orientation normalization: Auto-rotates images using Imagick or GD based on EXIF orientation tag
  - Equipment mapping: `mapToLookups()` links EXIF data to cameras/lenses tables with fuzzy matching
  - Fuzzy matching: Uses `LOWER(model) LIKE LOWER(:model)` for SQLite compatibility (replaced SOUNDEX)
  - Auto-creates missing equipment: `findOrCreateCamera()` and `findOrCreateLens()` methods
- Uses Imagick when available, falls back to GD
- **Responsive image srcset**: Dynamically generates srcset attributes with full multi-format support
  - Pattern: `{% for variant in image.variants %}...{% endfor %}` builds separate srcset arrays per format
  - Multi-format srcsets: Generates `srcset_avif`, `srcset_webp`, `srcset_jpg` arrays for progressive enhancement
  - Format detection: `{% if variant.format == 'avif' %}` / `'webp'` / `'jpg' or 'jpeg'` for format-specific srcsets
  - Picture element: `<picture>` with `<source>` tags for each format (AVIF → WebP → JPEG fallback)
  - Width descriptors: `srcset="{{ srcset_avif|join(', ') }}"` with `{url} {width}w` format
  - Sizes attribute: `sizes="(min-width: 1024px) 33vw, (min-width: 768px) 50vw, 100vw"`
  - Fallback: Single `src` uses `image.fallback_src` or first available variant or original path
  - Best JPG selection: Tracks largest JPG variant for optimal fallback (`best_jpg_w`, `best_jpg_path`)
  - **Storage path security**: Always excludes protected paths (`/storage/`) from srcsets for protected albums
    - Security filter: `{% if ... and not (v.path starts with '/storage/') %}` in variant loops
    - Applied regardless of `allow_downloads` flag to prevent bypassing server-side access control
    - Ensures MediaController validates session before serving any protected album images
    - Templates: `gallery_hero.twig`, `home_modern.twig`, `_image_item_masonry.twig`, `_gallery_magazine_content.twig`
  - SEO: Uses `Seo.seo_title()` macro for semantic title generation combining image alt, album title, and site title
  - Applied in: `home_modern.twig` image grid, `_image_item_masonry.twig` masonry layout

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
- **MediaController**: Centralized server-side validation before streaming files from protected/NSFW albums (access validation synchronized in commit 63a9f77)
- **Three routes**:
  - `/media/protected/{id}/{variant}.{format}` - Protected image variants (rate limited: 100 req/min)
  - `/media/protected/{id}/original` - Protected original images (rate limited: 100 req/min)
  - `/media/{path:.*}` - Public media with protection validation (rate limited: 200 req/min)
- **Session-based access**:
  - Password-protected: `$_SESSION['album_access'][$albumId]` (24h TTL) via POST `/album/{slug}/unlock` (rate limited: 5 req/10min)
  - NSFW confirmation: `$_SESSION['nsfw_confirmed'][$albumId]` via POST `/album/{slug}/nsfw-confirm` (rate limited: 10 req/5min)
  - Blur variants always allowed (for preview purposes without session validation)
  - Admin users bypass all access restrictions via `$_SESSION['admin_id']` check
- **Access validation**: `validateAlbumAccess()` method checks session state before streaming (password + NSFW + admin checks, logic cleaned in commit 63a9f77)
- **Simplified protection logic**: Controllers always use protected endpoint for password/NSFW albums
  - Pattern: `$lightboxUrl = $isProtectedAlbum ? '/media/protected/{id}/...' : $publicPath`
  - URL construction: Uses relative paths `/media/protected/...` without base path prefix for consistency
  - Storage path protection: Always routes `/storage/` paths through protected endpoint for protected albums
  - Applied across: GalleryController, PageController album rendering
  - Templates exclude `/storage/` paths from srcsets for protected albums regardless of settings
  - Note: `allow_downloads` flag still used client-side for download button visibility
- **Template security**: NSFW album cards only show blur variants, never expose real image URLs in srcset or data-src
- **Path traversal protection**: Validates realpath against allowed directories (storage/ or public/media/), rejects paths with `..` or backslashes
- **MIME type validation**: Only serves image/* types (jpeg, webp, avif, png) via finfo_file()
- **ETag caching**: `private, max-age=3600, must-revalidate` for variants with 304 Not Modified support
- **File streaming**: Chunked streaming with 8KB buffer for memory efficiency
- **Router enforcement**: `public/router.php` routes all /media/* requests through PHP (prevents direct file access bypass)

### Plugin Extensibility & Custom Fields
- **MetadataExtensionService**: Arbitrary metadata storage system for plugins
  - Entity types: images, albums, pages (extensible to any entity)
  - Key-value storage: `entity_type`, `entity_id`, `extension_key`, `extension_value` (JSON-encoded)
  - Dual-database upserts: MySQL `ON DUPLICATE KEY UPDATE` vs SQLite `ON CONFLICT DO UPDATE`
  - Batch enrichment: `enrichEntities()` reduces N+1 queries via single query with IN clause
  - Plugin tracking: `plugin_id` field associates extensions with plugins for cleanup
  - Hook integration: `doAction('metadata_extension_saved')` and `applyFilter("metadata_{$entityType}_enriched")`
  - Error handling: JSON encode failures logged via `error_log()`, consistent `JSON_THROW_ON_ERROR` in `decodeValue()`
  - Bulk removal hook: `metadata_extensions_cleared` action fires when clearing all extensions for an entity (provides entity_type, entity_id, plugin_id for cleanup)
- **CustomFieldService**: User-defined metadata fields system
  - Field types: text (free input), select (single choice), multi_select (multiple choices)
  - System vs custom: `is_system` flag protects built-in fields (cameras, lenses, films) from deletion
  - Inheritance model: Image-specific metadata overrides album defaults via `mergeWithOverride()`
  - Visibility controls: `show_in_lightbox`, `show_in_gallery` toggles per field type
  - Value management: Predefined values with `sort_order`, `extra_data` (JSON) support
  - Hook integration: `doAction('custom_field_type_created/updated/deleted')` for extensibility
- **Hook System**: Event-driven architecture for plugins
  - Actions: `Hooks::doAction($tag, ...$args)` for fire-and-forget events
  - Filters: `Hooks::applyFilter($tag, $value, ...$args)` for data transformation
  - Registration: Plugins register callbacks via `Hooks::addAction()` and `Hooks::addFilter()`
  - Used in: CustomFieldService, MetadataExtensionService, UploadService, equipment controllers (CamerasController, LensesController, FilmsController, DevelopersController, LabsController, LocationsController)
  - Equipment hooks: `metadata_camera_created/updated/deleted`, `metadata_lens_created/updated/deleted`, etc. for extensibility
- **Admin UI Patterns**: Custom field management
  - System type protection: Edit/delete disabled for `is_system = 1` types
  - Name immutability: Field names cannot be changed after creation (used as identifiers)
  - Name format validation: Client-side regex `/^[a-z0-9_]+$/` enforces lowercase letters, numbers, underscores only
  - Value ordering: Drag-drop sortable via JavaScript with AJAX save endpoint
  - Icon picker security: Uses safe DOM manipulation (`createElement` + `appendChild`) instead of `innerHTML` to prevent XSS
  - Duplicate detection: UNIQUE constraint error handling with user-friendly messages
  - Icon selection: FontAwesome icon picker with server-side validation against `getAvailableIcons()` whitelist
  - Icon validation pattern: `$allowedIcons = array_keys($service->getAvailableIcons()); if (!in_array($icon, $allowedIcons)) { $icon = 'fa-tag'; }`
  - Field type change warnings: Visual warning when changing from select/multi_select to text (existing predefined values become unused)
  - CSP compliance: All inline scripts in custom field templates use `nonce="{{ csp_nonce() }}"` attribute
  - Empty states: Conditional rendering with helpful hints when no data exists

### System Updater & Maintenance
- **Updater** (App\Support\Updater): Manages GitHub Releases, backup/restore, and rollback support
- **Database backup/restore**: Automatic backup before updates with restore and rollback paths
- **Maintenance mode**: Blocks access during updates to prevent inconsistent states
- **Update history tracking**: Records update attempts and outcomes
- **Rollback support**: Restore previous version on failure
- **Admin interface**: `/admin/updates` dashboard for monitoring, backups, and maintenance toggles
- **VariantMaintenanceService**: Daily automated maintenance for image variants
  - File-based locking (`storage/tmp/variants_daily.lock`) prevents concurrent execution with `LOCK_EX | LOCK_NB`
  - Date tracking via `maintenance.variants_daily_last_run` setting (UTC Y-m-d format)
  - Generates missing variants for all enabled formats and breakpoints using UploadService
  - Generates blur variants for NSFW album images (`album.is_nsfw = 1`) that lack them
  - Stats tracking: images_checked, variants_generated/skipped/failed, blur_generated/failed
  - Double-check pattern: Validates last run date before and after acquiring lock to prevent race conditions
  - Graceful failure: Logs warnings on errors without blocking other operations

### Authentication
- Session-based admin auth with `$_SESSION['admin_id']`
- Password hashing with `password_hash()`
- CSRF tokens on all forms

### Admin Debug Logs
- **Toggle setting**: `admin.debug_logs` (default: false) in `/admin/settings`
- **Window global**: `window.__ADMIN_DEBUG` injected in admin layout template
- **Debug helper**: `debugLog()` function in `resources/admin.js` wraps `console.log`
  - Only outputs when `window.__ADMIN_DEBUG === true`
  - Silent in production (toggle disabled)
- **Usage**: All admin JavaScript logging passes through `debugLog()` for conditional output
- **Scope**: TomSelect, Sortable, TinyMCE, and gallery refresh operations log via debugLog

### Content Security Policy (CSP)
- **SecurityHeadersMiddleware**: Generates unique nonce per request for inline scripts and styles
- **Nonce generation**: `base64_encode(random_bytes(16))` stored in static property per request
- **Request attribute**: Nonce attached to request as `csp_nonce` attribute and accessible via `SecurityHeadersMiddleware::getNonce()`
- **Twig function**: `{{ csp_nonce() }}` function via SecurityTwigExtension for inline scripts and styles in templates
- **Dual CSP policies**:
  - **Admin routes** (paths starting with `/admin` or `/cimaise/admin`): Allows `unsafe-inline` and `unsafe-eval` for scripts (required for SPA navigation and TinyMCE)
  - **Frontend routes**: Strict nonce-based CSP with `script-src 'self' 'nonce-{nonce}'`, no unsafe-inline allowed
  - Route detection: `str_starts_with($path, '/admin')` in middleware process method
- **CSP header**: `script-src 'self' 'nonce-{nonce}'` (frontend) or `'self' 'unsafe-inline' 'unsafe-eval'` (admin)
- **Usage pattern**:
  - Scripts: `<script nonce="{{ csp_nonce() }}">...</script>` for inline JavaScript (frontend only)
  - Styles: `<style nonce="{{ csp_nonce() }}">...</style>` for inline CSS (all frontend templates)
- **Frontend template compliance**: All inline styles use CSP nonce for security
  - Layout: `_layout.twig` uses `<style nonce="{{ csp_nonce() }}">` for custom styles (line-clamp, masonry, FOUC prevention, PhotoSwipe z-index)
  - Galleries: `galleries.twig` inline styles for filter UI and responsive layout
  - Gallery hero: `gallery_hero.twig` inline overlay and hero image styles
  - Modern template: `home_modern.twig` uses nonce for webkit search input styling and CSS reset scoping
  - Parallax template: `home_parallax.twig` uses nonce for inline styles (grid layout, parallax effects) and scripts (RAF loop)
  - Masonry template: `home_masonry.twig` uses nonce for inline styles (item sizing, responsive gaps) and scripts (Masonry.js initialization)
- **Admin template compliance**: Admin templates with inline scripts use CSP nonce even though policy is relaxed
  - Admin layout: `_layout.twig` uses `<script nonce="{{ csp_nonce() }}">` for window globals (`basePath`, `cspNonce`, `__ADMIN_DEBUG`, admin i18n)
  - Custom fields: `create.twig` and `edit.twig` use nonces for name validation, icon picker, field type warnings
  - Inline styles: Component styling, TinyMCE fixes, sidebar styles benefit from relaxed CSP policy
  - TinyMCE initialization and configuration work with `unsafe-inline` and `unsafe-eval` allowed
- **Additional headers**: X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, HSTS, Referrer-Policy, Permissions-Policy, Cross-Origin-Opener-Policy, X-Permitted-Cross-Domain-Policies, Expect-CT
- **CSP directives**: upgrade-insecure-requests, img-src with data: and blob:, style-src with unsafe-inline for third-party CSS, font-src for Google Fonts, object-src none, base-uri self, form-action self, frame-ancestors none
- **reCAPTCHA whitelist**: Frontend CSP includes `frame-src` and `script-src` for Google reCAPTCHA domains (`www.google.com/recaptcha/`, `www.gstatic.com/recaptcha/`)

### Advanced Filtering (Galleries)
- Multi-criteria filtering: categories, tags, cameras, lenses, films, locations, year, search
- AJAX-based filter API endpoint (`/galleries/filter`)
- Client-side filter state management with URL parameters
- Filter options dynamically generated from database (with album counts)
- Empty state handling when no galleries match filters

### Frontend JavaScript (Uppy Upload, TinyMCE, GSAP, Tom Select, PhotoSwipe)
- **Uppy file upload**: XHR-based upload with CSRF token, file restrictions (JPEG, PNG, WebP), drag-drop support
- **Client-side compression**: @uppy/compressor plugin for pre-upload optimization
  - Quality: 85%, max dimensions: 4000x4000px
  - Auto-converts large PNGs to JPEG for smaller file sizes
  - Reduces upload size by 50-70% for high-resolution photos
  - Compression progress feedback in UI with translation support
- **Parallel uploads**: Concurrent upload limit of 3 files for faster bulk operations
- **Upload timeout**: 2-minute XHR timeout for large compressed files
- **Progress tracking**: Real-time per-file and total progress UI with visual bars and file list
- **Error handling**: XSS-safe filename sanitization, duplicate detection, user notifications (toast messages)
- **File deduplication**: Compares files by name and size to prevent redundant uploads
- **TinyMCE editor**: Configured with link, lists, and autoresize plugins for content editing
- **Tom Select dropdowns**: Auto-initialized on select elements with `tom-select` class, supports multi-select
- **Sortable drag-drop**: Sortablejs for reorderable gallery/image lists (client-side only)
- **GSAP animations**: Imported for smooth transitions and animations (gsap library)
  - Album cards: Use `gsap.fromTo()` pattern with explicit start/end values to prevent CSS race conditions
  - Pattern: `gsap.fromTo(elements, {opacity: 0, y: 20}, {opacity: 1, y: 0, duration: 0.5, stagger: {...}, onComplete: () => markAnimated})` ensures GSAP reads from values before CSS overrides
  - Animation tracking: `data-animated` attribute set in `onComplete` callback prevents re-animation on repeated calls
  - Fallback: If GSAP unavailable, immediately sets `data-animated` attribute to show elements via CSS
  - Timeout fallback: 2-second timeout ensures album cards always visible even if GSAP fails
  - Stagger timing: `amount: Math.min(0.4, items.length * 0.05)` for dynamic stagger based on item count
  - CSS coordination: `.album-card:not([data-animated]) { opacity: 0 }` and `.album-card[data-animated] { opacity: 1 }` for initial hiding
  - Gallery items: `animateGalleryOnce()` uses same `fromTo()` pattern with explicit `{opacity: 0, scale: 0.9}` from state
- **PhotoSwipe lightbox**: Enhanced layout with zoom-friendly sizing, minimal floating UI
  - No max constraints on images to allow proper zoom functionality
  - Zoom disabled: `wheelToZoom: false`, manual zoom controls preferred
  - Drag disabled: `closeOnVerticalDrag: false` prevents accidental closes
  - Padding: `{ top: 28, bottom: 40, left: 28, right: 28 }` for balanced centering
  - Background scroll lock: `html.pswp-open` and `body.pswp-open` with `position: fixed` prevents body scroll
  - Arrow buttons: 46px floating circles (38px on mobile) with safe-area-inset support (`left: calc(env(safe-area-inset-left) + 8px)`)
  - Z-index management: Zoomed image and scroll wrap use z-30, UI elements (caption, counter, meta) use z-1 with pointer-events-none when zoomed
  - Top bar and arrow buttons: Maintain z-40 accessibility when zoomed via `.pswp--zoomed-in .pswp__top-bar` and `.pswp__button--arrow`
  - UI idle state: Top bar and buttons always visible (`opacity: 1 !important`) for better UX
  - Top bar: Positioned at `top: 12px, right: 12px` with flex-end justify, no background
  - Object-fit: `contain` with `center` positioning and `cursor: pointer` for proper image display
  - Click/tap action: `next` (configurable via `psCfg.imageClickAction`)
- **Idempotent initialization**: Guards against double-initialization of upload areas with `_uppyInitialized` flag
- **Global instance tracking**: `window.uppyInstances` array for proper cleanup and SPA re-initialization

### Home Page Templates & Animations
- **Template system**: Configurable via `home.template` setting (classic, modern, parallax, masonry, snap, or gallery)
  - Setting managed in `PagesController::saveHome()` and rendered in `PageController::home()`
  - Template selection: Template map with 'modern' → 'home_modern.twig', 'parallax' → 'home_parallax.twig', 'masonry' → 'home_masonry.twig', 'snap' → 'home_snap.twig', 'gallery' → 'home_gallery.twig', default → 'home.twig'
  - Validation: `in_array($homeTemplate, ['classic', 'modern', 'parallax', 'masonry', 'snap', 'gallery'], true)` with 'classic' fallback
  - Classic template: `frontend/home.twig` (default)
  - Modern template: `frontend/home_modern.twig` (grid-based layout)
  - Parallax template: `frontend/home_parallax.twig` (vertical scrolling with parallax effect)
  - Masonry template: `frontend/home_masonry.twig` (pure CSS column-based wall of photos)
  - Snap template: `frontend/home_snap.twig` (album cover-focused layout)
  - Gallery template: `frontend/home_gallery.twig` (wall of individual images)
- **Admin template selector**: Visual radio button UI in `admin/pages/home.twig`
  - Six template options with gradient icons and descriptions
  - Classic: Gray-to-black gradient (from-gray-700 to-gray-900, fa-images), "Feature-rich layout" with masonry and carousel
  - Modern: Indigo-to-purple gradient (from-indigo-500 to-purple-600, fa-th-large), "Minimal & clean" with grid and sidebar
  - Parallax: Cyan-to-blue gradient (from-cyan-500 to-blue-600, fa-layer-group), "Immersive scrolling" with parallax effect
  - Masonry: Rose-to-orange gradient (from-rose-500 to-orange-500, fa-grip-vertical), "Pure masonry wall" with CSS columns
  - Snap: Purple-to-pink gradient (from-purple-500 to-pink-500, fa-book-open), "Album cover showcase" with snap scrolling
  - Gallery: Emerald-to-teal gradient (from-emerald-500 to-teal-500, fa-th), "Image wall" with individual photos
  - Visual feedback: Active template has black border (border-black), gray-50 background, visible check icon (fas fa-check-circle in top-right corner)
  - Translation keys: `admin.pages.home.template_*` (selection, classic, modern, parallax, masonry, snap, gallery, descriptions, subtitles)
  - Template-specific settings visibility: Classic settings hidden when modern/parallax/masonry/snap/gallery selected, masonry settings shown only for masonry template via `#masonry-home-settings` div
  - JavaScript-based template switching: Updates radio buttons, visual states via `.home-template-option` class, shows/hides corresponding settings sections dynamically

#### Classic Template CSS Animations
- **Mobile masonry layout**: CSS columns with `column-count: 1` (1 column mobile) / 2 (640px+)
- **Desktop infinite scroll**: Vertical auto-scroll with `animation-direction` data attribute (up/down)
  - Keyframes: `homeScrollUp` (0% translate -50% → 100% 0%) / `homeScrollDown` (0% 0% → 100% -50%)
  - CSS variables: `--home-duration` (animation time) and `--home-delay` (stagger)
  - Pause on hover: `:hover { animation-play-state: paused }` for single column interaction
- **Horizontal scroll mode**: CSS animation with `animation-direction` (left/right) for row-based scrolling
  - Keyframes: `homeScrollLeft` / `homeScrollRight` with `translate3d()` for hardware acceleration
  - Mobile-first: Hidden on mobile (`display: none`), shown on 768px+ as flex layout
  - Responsive heights: 220px (mobile) / 280px (tablet) / 320px (desktop)
- **Albums carousel**: Horizontal infinite carousel with auto-scrolling
  - Responsive gaps: 16px (mobile) / 20px (640px) / 24px (768px) / 28px (1024px)
  - Item sizing: `min-width: 320px` (mobile) / 384px (768px+)
  - Edge gradients: Veil overlays (`.albums-edge-left/right`) with fade-out to prevent jarring scroll
  - Navigation arrows: 40px circles (44px on 768px+) with hover states
  - Dragging state: `animation: none` and `.dragging { cursor: grabbing }`
- **Performance optimizations**: `backface-visibility: hidden`, `transform-style: preserve-3d`, `transform: translateZ(0)`, `will-change: transform`, `contain: content`
- **Entry animations**: Initial state `.entry-init .home-item { opacity: 0; filter: blur(4px); transform: scale(0.985) }` revealing with `.home-item--revealed` class
- **Gallery text content**: Semantic styling for rich text (h2-h4, blockquote, lists, links with underline)

#### Modern Template (Grid Layout)
- **Layout structure**: Fixed left sidebar (50% width) + scrollable right grid (50% width)
  - Left column: Fixed position with vertical centering, contains filters and info
  - Right column: Scrollable work grid with infinite scroll effect
  - Template files: `app/Views/frontend/home_modern.twig` extends `_layout_modern.twig`, imports `_seo_macros.twig`
  - Assets: `public/assets/css/home-modern.css`, `public/assets/js/home-modern.js`
  - Source: `resources/js/home-modern.js` (built via Vite)
- **Layout components**:
  - Global header: Fixed top-left logo with mix-blend-mode difference (desktop only)
  - Menu button: Fixed top-right with uppercase text, opens mega menu overlay (desktop only)
  - Mobile header: Full classic header (navigation, search, categories mega menu) wrapped in `.modern-mobile-only` div, shown below 768px with `display: block` (hidden on desktop with `!important`)
  - Left sidebar: Category filters (data-filter attributes), photo counter, hover info display (desktop only)
  - Right grid: Two-column image layout with infinite scroll (desktop), standard grid (mobile)
  - Mega menu: Full-screen overlay with close button and navigation links
  - Page transition: Overlay for smooth page changes
- **Lenis smooth scroll**: Integration via ES module import from npm (`lenis@1.1.18`)
  - **Global initialization** (`resources/js/smooth-scroll.js`): Window singleton pattern with `window.lenisInstance`
    - Configuration: lerp 0.08, wheelMultiplier 1.2, infinite false, gestureOrientation vertical, smoothTouch false
    - Optimized settings: Lower lerp (0.08 vs 0.1) for smoother deceleration, higher wheelMultiplier (1.2 vs 1) for more responsive scrolling
    - GSAP integration: Auto-detects `window.gsap.ticker`, falls back to RAF loop if unavailable
    - Resize recalculation: Multiple strategies (load event, resize with debounce, periodic for lazy-loaded content)
    - Periodic recalc: 10 iterations at 500ms intervals during first 5 seconds for dynamic content
    - Exposed globally: `window.lenisResize()` for manual recalculation triggers
  - **Pause/Resume exports**: `pauseLenis()` and `resumeLenis()` functions for lightbox integration
    - Used by PhotoSwipe to stop body scroll during lightbox view
  - **Modern template** (`home-modern.js`): Local instance with different settings
    - Duration: 2.0s, easing: `(t) => 1 - Math.pow(1 - t, 4)`, direction: vertical
    - Touch multiplier: 1.5, wheel multiplier: 0.8, smooth touch disabled
    - Desktop only: Not initialized below 768px breakpoint
    - RAF loop: `lenis.raf(time)` in `requestAnimationFrame` for continuous updates
    - Applied to `html.lenis` class for global smooth scrolling
- **Infinite scroll grid**: JavaScript-based with lerp animation and wrap-around
  - Two-column system: odd items (left column - `nth-child(2n+1)`), even items (right column - `nth-child(2n+2)`)
  - Mobile detection: animations disabled below 768px breakpoint via `isMobile()` function
  - Transform-based positioning with `translate(0px, ${finalY}px)` for GPU acceleration
  - Lerp smoothing: `lerp(v0, v1, 0.09)` for left column, `0.08` for right (stagger effect)
  - Wrap-around logic: `((newY % wrapHeight) + wrapHeight) % wrapHeight` for continuous loop
  - Minimum items: Requires 8+ items for infinite scroll effect (MIN_ITEMS_FOR_INFINITE = 8), automatically clones items if fewer
  - Item cloning: `cloneItemsForWall()` duplicates original items to reach minimum threshold, marked with `is-clone` class
  - Clear transforms on mobile: `clearTransforms()` removes all transform styles below 768px breakpoint
  - Wheel event handling: Updates `scrollY` with delta for manual scroll control
  - Filtered state: Disables infinite scroll when category filter active, shows simple grid layout
- **Category filtering**: Client-side filtering with hover effects
  - Shimmer animation on hover: gradient background-clip with 3s animation
  - Active state: opacity 1, inactive: opacity 0.5
  - Data attributes: `data-filter="all"` for all work, `data-filter="{slug}"` for categories
  - Filtering toggles `.is-active` class and updates visibility of items by category class
- **Hover info display**: Left sidebar shows image title and description on hover
  - HTML5 data attributes for element targeting and metadata:
    - `data-inf-item`: Marks grid items for JavaScript selection via `querySelectorAll('[data-inf-item]')`
    - `data-filter`: Category filter identifiers ("all" or category slug) on `.grid-toggle_item` elements
    - `data-image-grid-title`, `data-image-grid-copy`: Target containers for hover info display
    - `data-work-title`, `data-work-copy`: Store image metadata (album title, description) on work items
    - `data-album-id`: Album identifier for tracking
  - Updates on mouseenter event: `getAttribute('data-work-title')` and `getAttribute('data-work-copy')`
  - All data attributes properly prefixed with `data-` for HTML5 compliance
- **Mega menu overlay**: Full-screen navigation with close button
  - Menu toggle: `.menu-btn` opens, `.menu-close` closes
  - Page transition overlay: `.page-transition` for smooth navigation
  - Navigation links: Home, Galleries, About with `.is-current` class for active page
  - Current page link behavior: Clicking `.mega-menu_link.is-current` closes menu instead of navigating
- **CSS variables**: `--modern-black`, `--modern-white`, `--modern-grey`, `--modern-dark-grey`
- **Responsive font sizing**: Root font size set to `1.1111111111vw` (capped at `21.333px !important` on 1920px+ screens)
  - VW-based sizing: Only applied on desktop (768px+) via `@media (min-width: 768px)` wrapper
  - Mobile: Standard browser font-size preserved, no VW scaling applied
- **Mobile-specific adjustments**: Below 768px breakpoint
  - Desktop components hidden: `.global-head`, `.menu-btn`, `.menu-component`, `.work-col.is-static` with `display: none !important`
  - Classic header shown: `.modern-mobile-only` wrapper (display: block below 768px, hidden with `!important` on desktop) contains full classic header (navigation, search, categories mega menu)
  - Layout adjustments: Full-width work column, no left margin, reduced padding (10px)
  - Inline styles: CSP nonce-protected `<style nonce="{{ csp_nonce() }}">` tag in template for mobile-specific CSS and webkit search input overrides
  - Webkit search styling: Removes default search decorations (cancel button, search icons) for consistent cross-browser appearance
  - CSS reset scoping: Global reset rules exclude `.modern-mobile-only` wrapper and descendants via `*:not(.modern-mobile-only):not(.modern-mobile-only *)` selector to prevent Tailwind conflicts
- **Empty state**: Custom empty state with icon, title, text when no images available
  - Uses `home_settings.empty_title` and `home_settings.empty_text` with translation fallbacks
- **Translation keys**:
  - `frontend.home_modern.*`: all_work, albums, photos, close, empty_title, empty_text, menu
  - `frontend.menu.*`: home, galleries, about (for mega menu navigation)
  - `filter.subcategory`, `filter.subcategories`: Used in categories mega menu for subcategory count display
- **Performance optimizations**: Dynamic image loading prioritization
  - Images in initial viewport: `fetchpriority="high"`, `loading` attribute removed
  - Below-fold images: `loading="lazy"` preserved
  - Viewport detection: `getBoundingClientRect()` checks if image is in initial viewport on page load
- **Focus accessibility**: Focus-visible outlines on all interactive elements (menu button, filter items, links)
- **Mobile fade-in animation**: IntersectionObserver-based reveal for work items on mobile
  - `setupFadeInObserver()` observes `.inf-work_item` elements not already visible
  - Threshold: 0.15, rootMargin: `0px 0px -50px 0px`
  - Adds `.is-visible` class on intersection, then unobserves
  - Skipped for items already visible (desktop infinite scroll handles visibility)

#### Parallax Template (Grid Layout with Scroll Effects)
- **Layout structure**: Three-column grid layout with smooth parallax scrolling on image cards
  - Template file: `app/Views/frontend/home_parallax.twig` extends `_layout.twig`
  - Grid system: Three-column responsive grid using CSS Grid (`grid-template-columns: repeat(3, 1fr)`)
  - Mobile responsive: Reduces to 2 columns (800px breakpoint), 1 column (550px breakpoint)
  - Fixed positioning: `.gallery-track { position: fixed }` for scroll-independent positioning
  - Gallery height: Dynamically set to `track.clientHeight` for proper scroll range
- **Parallax effect**: Per-card image parallax using JavaScript RAF loop
  - Card container: Fixed height (400px) with `overflow: hidden`
  - Image wrapper: 135% height to allow parallax movement range
  - Parallax calculation: `diff * progress` where diff is wrapper overflow and progress is card position in viewport
  - Transform application: `translateY(${yPos}px)` on image wrapper for smooth vertical shift
  - Viewport-based progress: Uses `card.getBoundingClientRect().top / window.innerHeight` for scroll position
- **Smooth scrolling**: Custom lerp-based scroll smoothing with RAF loop
  - Easing constant: 0.05 for gentle deceleration
  - Lerp function: `lerp(start, end, t) => start * (1 - t) + end * t` for smooth interpolation
  - Variables: `startY` (interpolated position), `endY` (target scroll position)
  - RAF cancellation: Stops animation when `startY.toFixed(1) === window.scrollY.toFixed(1)`
  - Track transform: `translateY(-${startY}px)` for vertical movement
- **Inline styles**: All CSS inlined in `{% block styles %}` with CSP nonce
  - Body overflow-x hidden to prevent horizontal scrolling
  - Gallery reset: Scoped to `.gallery, .gallery *` to prevent global pollution
  - Gap configuration: 0.25rem grid gap and padding
  - Will-change hints: `will-change: transform` on track and image wrappers
- **Hover interactions**: Card overlay with album info
  - Overlay positioning: Absolute bottom with gradient background (`linear-gradient(transparent, rgba(0,0,0,0.7))`)
  - Opacity transition: 0 to 1 on hover with 0.3s ease
  - Content: Album title (h3, 0.875rem) and category name (p, 0.75rem) with responsive typography
- **Performance optimizations**: Will-change hints and GPU acceleration
  - Card styles: `will-change: transform` on image wrapper
  - CSS containment: Proper overflow handling with `overflow: hidden`
  - Lazy loading: All images use `loading="lazy"` attribute
  - RAF efficiency: Only runs when scroll position changes
- **Empty state**: Reuses shared empty state template with icon, title, and text
  - Uses `home_settings.empty_title` and `home_settings.empty_text` settings with translation fallbacks
- **Event listeners**: Load, scroll, and resize events for maintaining smooth parallax
  - Initial setup: `activateParallax()` and `startScroll()` on window load
  - Scroll updates: `endY = window.scrollY` triggers RAF loop via `startScroll()`
  - Resize recalc: Updates gallery height and recalculates parallax on window resize
- **Inline JavaScript**: Complete parallax logic in `{% block scripts %}` with IIFE pattern
  - Guard check: Early return if `.gallery`, `.gallery-track`, or `.card` not found
  - Activation: `init()` function called on DOMContentLoaded and window load events

#### Masonry Template (Hybrid CSS Grid + Masonry.js Layout)
- **Layout structure**: Progressive enhancement approach with CSS Grid base and Masonry.js enhancement
  - Template file: `app/Views/frontend/home_masonry.twig` extends `_layout.twig`
  - Base layer: CSS Grid layout (works without JavaScript) with responsive column counts via inline `<style nonce="{{ csp_nonce() }}">` tag
  - Enhanced layer: Masonry.js (masonry.pkgd.min.js) + imagesLoaded.js for optimal brick layout
  - Item sizing: Responsive width calculation based on column count and gap settings
  - CSP-safe styling: Gap values and column counts injected via nonce-protected inline styles in template
- **Gap configuration**: Configurable horizontal and vertical spacing (0-40px range)
  - Horizontal gap: `data-gap` attribute controls gutter between columns, setting: `home.masonry_gap_h`
  - Vertical gap: Applied via CSS Grid `gap` property, setting: `home.masonry_gap_v`
  - CSP-safe injection: Gap values applied via `<style nonce="{{ csp_nonce() }}">` inline styles in template
  - CSS Grid gap: `gap: {{ home_settings.masonry_gap_v|default(0) }}px {{ home_settings.masonry_gap_h|default(0) }}px;`
  - Admin UI: Range sliders with live value display showing "Xpx" in `admin/pages/home.twig`
  - Slider IDs: `#masonry-gap-h-slider` and `#masonry-gap-v-slider` with corresponding value displays (`#masonry-gap-h-value`, `#masonry-gap-v-value`)
- **Column settings**: Responsive breakpoints with device-specific column counts
  - Desktop (1025px+): 2-8 columns (default: 5), setting: `home.masonry_col_desktop`
  - Tablet (641-1024px): 2-6 columns (default: 3), setting: `home.masonry_col_tablet`
  - Mobile (<641px): 1-4 columns (default: 2), setting: `home.masonry_col_mobile`
  - Template rendering: `grid-template-columns: repeat({{ home_settings.masonry_col_mobile|default(2) }}, 1fr)` with responsive media queries
  - Data attributes: `data-cols-mobile`, `data-cols-tablet`, `data-cols-desktop` for JavaScript configuration
  - Validation: `PagesController::saveHome()` enforces min/max ranges per device with `max(min())` clamping
- **Masonry initialization**: JavaScript-based layout with dynamic column width calculation
  - imagesLoaded: Waits for all images to load before initializing Masonry via `.imagesLoaded()` callback
  - Dynamic width: `getColumnWidth()` calculates item width based on viewport and column count
  - Configuration: `itemSelector: '.masonry-item'`, `columnWidth: '.masonry-item'`, `gutter: gap`, `fitWidth: false`, `horizontalOrder: true`
  - Grid activation: Switches from CSS Grid (`display: grid`) to Masonry mode (`display: block`, absolute positioning) via `.masonry-active` class
  - Resize handling: Debounced resize listener (100ms) updates item widths and re-layouts grid
- **Infinite scroll**: Automatic content cloning and appending for seamless vertical scrolling with memory leak prevention
  - Viewport fill target: 3x viewport height (reduced from 4.6x for better performance)
  - Clone tracking: Cloned items marked with `.is-clone` class for management
  - Column balancing: `balanceColumns()` calculates required clones upfront for single batch append (no iterative while loops, max 5 recursions)
  - Viewport observer: IntersectionObserver with `rootMargin: '200% 0px 200% 0px'` for preloading 2 viewports ahead/behind
  - Infinite scroll observer: Triggers new batch append when sentinel element enters viewport
  - Batch append: Adds items per trigger (minimum 2x column count), distributed evenly across columns
  - Layout refresh: Calls `masonry.appended()` after each batch for proper positioning
  - imagesLoaded fallback: Includes `imagesLoaded` callback consistency check for cross-browser compatibility
  - **Memory management**: DOM item limits and cleanup to prevent memory leaks
    - Max DOM items: 200 items (`maxItemsInDom` limit) to prevent unbounded growth
    - Offscreen cleanup: `cleanupOffscreenItems()` removes items 3+ viewports above current scroll position
    - Observer cleanup: Unobserves removed items from IntersectionObserver to release memory
    - Clone pooling: Recycles removed DOM nodes in pool (max 50 items) for reuse via `clonePool` array
    - Recursion limits: `ensureViewportFilled()` capped at 10 iterations, `balanceColumns()` at 5 to prevent infinite loops
    - Preservation: Never removes original source items, only clones eligible for cleanup
- **Fade-in animation**: Staggered reveal for masonry items
  - Animation: `masonryFadeIn` keyframes (opacity 0→1, translateY 15px→0, 0.4s ease-out)
  - Progressive reveal: Items fade in with `.fade-in` class after images load
  - Reduced motion support: Disables animations via `@media (prefers-reduced-motion: reduce)`
- **Image preloading**: Optimizes perceived performance with priority hints
  - Above-fold images: First 10 items get `fetchpriority="high"` and `loading="eager"`
  - Below-fold images: Remaining items use `loading="lazy"` for deferred loading
  - Viewport detection: Checks if image is in initial viewport on page load
- **Responsive images**: Picture element with multi-format support
  - Format sources: Separate `<source>` tags for AVIF, WebP, and JPEG with srcset
  - Fallback: Single `<img>` tag with lazy loading for deferred loading
  - Alt text: Uses image title or album title for accessibility
- **Empty state**: Reuses shared empty state template
  - Uses `home_settings.empty_title` and `home_settings.empty_text` with translation fallbacks
- **Admin controls**: Template-specific settings section in home page editor
  - Visibility: Shown only when `home.template == 'masonry'` via conditional class `{{ settings['home.template']|default('classic') != 'masonry' ? 'hidden' : '' }}`
  - Gap sliders: Dual range inputs (0-40px) with real-time value updates via JavaScript listeners
  - Column selects: Dropdown menus for desktop (2-8), tablet (2-6), mobile (1-4) column counts using Twig loops `{% for i in 2..8 %}`
  - Icon indicators: Device icons (fa-desktop, fa-tablet-alt, fa-mobile-alt) for clarity
  - Translation keys: `admin.pages.home.masonry_*` (settings, gap, gap_h, gap_v, columns, col_desktop, col_tablet, col_mobile, columns_hint, gap_hint)
- **PagesController validation**: Server-side validation in `saveHome()` method
  - Template validation: `in_array($homeTemplate, ['classic', 'modern', 'parallax', 'masonry'], true)` with 'classic' fallback
  - Gap range: `max(0, min(40, $value))` enforces 0-40px for both horizontal and vertical gaps (matches UI slider limits)
  - Column ranges: Device-specific validation (desktop: 2-8, tablet: 2-6, mobile: 1-4) with `max(min())` clamping
  - Fallback defaults: Invalid values reset to defaults (desktop: 5, tablet: 3, mobile: 2)
  - Settings keys: `home.masonry_gap_h`, `home.masonry_gap_v`, `home.masonry_col_desktop`, `home.masonry_col_tablet`, `home.masonry_col_mobile`
- **Performance**: Masonry.js handles efficient layout calculation
  - imagesLoaded prevents layout shifts from image loading
  - Resize debouncing (100ms) prevents excessive re-calculations
  - CSS calc() for responsive item widths reduces JavaScript overhead

#### Snap Albums Template (Split-Screen Layout)
- **Layout structure**: Split-screen design with synchronized scrolling between album info and cover images
  - Template file: `app/Views/frontend/home_snap.twig` extends `_layout.twig`
  - Desktop layout: 45% left column (album info) + 55% right column (cover images) with vertical divider
  - Left column: Vertical scrolling album info panels with title, year, description, photo count
  - Right column: Vertical scrolling cover images synchronized with left column scroll position
  - Snap scrolling: Each scroll snaps to next album (desktop) via CSS `scroll-snap-type: y mandatory`
  - Full-screen positioning: `.snap-container { position: fixed }` with `height: calc(100vh - var(--header-height, 80px))`
  - Footer hiding: `footer { display: none !important; }` hides footer immediately (FOUC prevention) since full-screen layout doesn't need footer
  - Mobile search toggle: Explicit desktop hiding via `@media (min-width: 768px) { #mobile-search-toggle { display: none !important; } }` to reinforce Tailwind md:hidden class
- **Synchronized scrolling**: JavaScript-based coordination between left and right columns
  - Transition: Smooth 0.8s cubic-bezier(0.16, 1, 0.3, 1) for elegant snap animation
  - Transform-based positioning: `translateY()` on both tracks for GPU acceleration
  - Scroll indicators: Right-side dot navigation with active state tracking, clickable for direct album access
- **Desktop styling**: Elegant typography and spacing
  - Album titles: Clamp font-size (2rem-4.5rem) with uppercase, 300 weight, 0.05em letter-spacing
  - Year badge: Small gray text (0.9rem) with 0.2em letter-spacing
  - Description: Max-width 400px, 1.8rem line-height for readability
  - Cover images: Full-height object-cover with top alignment, PhotoSwipe lightbox integration
- **Mobile layout**: Vertical scroll with stacked album cards (below 768px)
  - Card structure: Cover image top, album info bottom overlay with gradient background
  - Compact spacing: Reduced padding and font sizes for mobile viewport
  - Scroll behavior: Standard vertical scroll, snap scrolling disabled for mobile usability
- **Responsive breakpoints**: Tablet adjustments at 1024px, mobile at 768px
  - Tablet: 50/50 split between columns, reduced padding (3rem)
  - Mobile: Single column vertical cards with image aspect ratio control
- **Indicators**: Desktop-only dot navigation for album selection
  - Position: Fixed right side, vertically centered with 0.8rem gap
  - States: 8px circles, gray inactive, black active with 1.3x scale
  - Click navigation: Direct jump to specific album via indicator click
- **CSP compliance**: Inline styles use `<style nonce="{{ csp_nonce() }}">` for security
- **Empty state**: Reuses shared empty state template with fallback messaging
- **PhotoSwipe integration**: Cover images open in lightbox with full metadata

#### Gallery Wall Template (Horizontal Scrolling)
- **Layout structure**: Horizontal scrolling wall of individual images from all albums
  - Template file: `app/Views/frontend/home_gallery.twig` extends `_layout.twig`
  - Assets: `public/assets/js/home-gallery.js` (built via Vite from `resources/js/home-gallery.js`)
  - Desktop layout: Sticky container at header height with horizontal flexbox track
  - Scroll mechanics: JavaScript-based horizontal scroll with lerp smoothing and parallax easing
  - Header behavior: Sets `body.gallery-wall-page` class to prevent header hide-on-scroll (handled in `_layout.twig`)
- **Responsive image sizing**: Aspect ratio-based width calculation for full-height images
  - Horizontal images (landscape): Width = viewport height × 1.5 (1.3x on tablet ≤1024px)
  - Vertical images (portrait): Width = viewport height × 0.67 (0.6x on tablet)
  - Height: Always 100% of viewport minus header height for consistent wall appearance
  - Aspect detection: Uses `image.width / image.height` ratio to classify as horizontal or vertical
- **Horizontal scroll animation**: JavaScript RAF loop with smooth scrolling
  - Lerp easing: Linear interpolation for smooth deceleration effect
  - Transform-based: `translateX()` on track for GPU-accelerated horizontal movement
  - Section height: Dynamically calculated (track width + viewport width) for proper scroll range
  - Sticky positioning: Container stays fixed while user scrolls vertically, creating parallax effect
- **Visual design**: Minimal gallery wall aesthetic
  - Thin borders: 2px white separator between adjacent images via `::after` pseudo-element
  - Hover overlay: Gradient bottom overlay (transparent to rgba(0,0,0,0.7)) with album info
  - Album metadata: Title and category name displayed on hover with 0.3s opacity transition
  - Object-fit cover: Images fill container with proper cropping
- **Mobile layout**: Standard vertical grid (below 768px)
  - Two-column grid: `grid-template-columns: repeat(2, 1fr)` with 0.5rem gap
  - Wide items: Every 4th item spans 2 columns with 16:9 aspect ratio for visual variety
  - Standard items: 1:1 square aspect ratio for consistent grid
  - Vertical scroll: Desktop horizontal scroll hidden, replaced with standard mobile grid
- **Responsive breakpoints**: Tablet at 1024px, mobile at 768px
  - Desktop: Full horizontal scroll wall with sticky positioning
  - Mobile: Compact grid with reduced gaps (0.3rem on ≤480px)
- **CSP compliance**: All inline styles use nonce-protected `<style>` tags
- **PhotoSwipe integration**: Each image opens in lightbox with full metadata and equipment info
- **Performance optimizations**: will-change hints, GPU acceleration, lazy loading
- **Empty state**: Shared empty state template with icon and messaging

### Auto-Repair (Bootstrap)
- Auto-creates `.env` from template if missing and `database/template.sqlite` exists
- Auto-copies `template.sqlite` to `database.sqlite` if database is empty
- Auto-redirects to `/install` if installation incomplete

### Installer Flow (Multi-Step Wizard)
- **5-step process**: Welcome (requirements check) → Database → Admin User → Settings → Confirm & Install
- **Note**: Post-setup step removed (commit 78ff3d2) - settings now collected before installation, not after
- **Session-based config storage**: Each step stores data in `$_SESSION['install_*_config']` arrays (db_config, admin_config, settings_config)
- **CSRF protection**: All forms include CSRF token validation with hash_equals comparison
- **Session initialization**: InstallerController ensures session started in constructor (`session_start()` if `PHP_SESSION_NONE`), generates CSRF token if missing (`bin2hex(random_bytes(32))`)
- **MySQL auto-detection**: AJAX endpoint `/install/test-mysql` tests connection and auto-detects charset/collation (rate limited: 10 req/5min via FileBasedRateLimitMiddleware)
- **Separate database fields**: Different input fields for SQLite path vs MySQL database name in database step
- **Default collation**: Uses `utf8mb4_unicode_ci` (more compatible than `utf8mb4_0900_ai_ci`)
- **Database connection testing**: Validates MySQL/SQLite connection before proceeding to next step
- **MySQL privilege testing**: `testMySQLPrivileges()` verifies CREATE, ALTER, INSERT, UPDATE, DELETE privileges via test table operations before schema installation
- **Visual step indicator**: Shows progress through installer stages with step numbers
- **Settings step**: Collects site title (required), description, copyright (with {year} placeholder), email (required), language (en/it for both frontend and admin), date format (Y-m-d/d-m-Y), and optional logo upload
- **Requirement verification**: `collectRequirementErrors()` validates PHP 8.2+, extensions (pdo, gd, mbstring, openssl, json, fileinfo), database drivers (pdo_sqlite or pdo_mysql), writable directories, disk space (100MB minimum)
- **Directory auto-creation**: Creates missing directories (database, storage, public/media, storage/originals) during installation with proper permissions (0755)
- **Favicon generation during install**: Installer calls `generateFavicons()` if logo uploaded in settings step, graceful failure logging if generation fails (doesn't block installation)
- **Multi-language page defaults**: `getPageSettingsForLanguage()` provides translated default content for home/about/galleries pages based on selected installation language (en/it)
- **Rollback on failure**: Auto-cleanup of .env and SQLite database files if installation fails; drops MySQL tables via `rollback()` method in Installer class
- **State tracking**: `Installer` class tracks `envWritten`, `dbCreated`, `createdDbPath` for proper rollback logic
- **Table cleanup**: Rollback drops all tables in defined order (junction tables first, then main tables) to handle foreign key constraints
- **CLI installer**: `php bin/console install` provides interactive command-line installation with SymfonyStyle prompts for all configuration
- **Post-install redirect**: Redirects to `/admin/login` after successful installation
- **Base path detection**: Handles subdirectory installations by detecting and removing '/public' suffix from base path in InstallerController constructor
- **Already installed check**: All installer routes check `Installer::isInstalled()` and redirect to admin login if already installed

### SEO & Schema Markup
- **Admin management**: `SeoController` provides comprehensive SEO settings dashboard at `/admin/seo`
- **Settings categories**:
  - Site-wide SEO: title, description, keywords, author info, organization details
  - Open Graph & Social: og_site_name, og_type, og_locale, twitter_card, twitter_site, twitter_creator
  - Schema.org toggles: schema_enabled, breadcrumbs_enabled, local_business_enabled
  - Professional Photographer Schema: job_title, services, area_served, sameAs (social profiles)
  - Local Business Schema: name, type (ProfessionalService), address, city, postal_code, country, phone, geo coordinates (lat/lng), opening_hours
  - Technical SEO: robots_default (index,follow), canonical_base_url, sitemap_enabled
  - Analytics: Google Analytics 4 (gtag), Google Tag Manager (gtm)
  - Image SEO: auto alt text, copyright notice, license URL, acquire license page
  - Performance: preload_critical_images, lazy_load_images, structured_data_format (json-ld)
- **Meta tags**: Standard SEO meta tags (description, robots, canonical URL)
- **Open Graph protocol**: Full OG tag support (title, description, image, type, URL, locale, site_name)
  - Image optimization: width/height hints (1200x630), alt text
  - Dynamic fallbacks: uses `site_title` for `og:site_name`, `current_url` for canonical
- **Twitter Cards**: summary_large_image card with title, description, image, site, creator
- **Canonical URLs**: Configurable canonical links for duplicate content management
- **JSON-LD schemas**: Three schema types via conditional rendering
  - **Person schema**: Author/photographer with name, URL, image, jobTitle, description, sameAs (social profiles)
  - **Organization schema**: Business entity with name, URL, logo
  - **BreadcrumbList schema**: Automatic breadcrumb structured data with position tracking
  - **LocalBusiness schema**: Local SEO with business type, address, phone, geo coordinates, opening hours, price range
- **Schema configuration**: All schemas controlled via `schema` variable with enable/disable flags
- **Sitemap generation**: Manual trigger via `/admin/seo` (POST to `generateSitemap` action) uses SitemapService with database-driven URL generation
- **Preconnect hints**: DNS prefetch for Google Fonts (fonts.googleapis.com, fonts.gstatic.com)
- **Favicon support**: Multi-size favicon set with web manifest
  - Sizes: favicon.ico, 16x16, 32x32 (PNG), apple-touch-icon 180x180, site.webmanifest
- **Accessibility**: `lang` attribute on `<html>` tag with i18n support

### Breadcrumbs & Schema
- **Auto-generated breadcrumbs**: Dynamically builds breadcrumb trail based on page context (home, category, tag, album, about)
- **JSON-LD schema**: Automatically generates BreadcrumbList structured data for SEO
  - CSP nonce included: `<script type="application/ld+json" nonce="{{ csp_nonce() }}">` for strict CSP compliance
  - BreadcrumbList only rendered when `items|length > 1` to avoid single-item schemas
- **Subdirectory-safe**: Uses `base_path` for correct URL generation in subdirectory installations
- **Translation support**: All breadcrumb labels use `trans()` function for i18n
- **Responsive spacing**: Bottom padding (`pb-5 md:pb-0`) adapts to viewport - 1.25rem on mobile, 0 on desktop for compact integration
- **Compact layout**: Minimal horizontal (gap-x-1) and vertical (gap-y-1) gaps between breadcrumb items with leading-normal line-height for tight wrapping

### Date Formatting & Dynamic Placeholders
- **Twig filters**: `date_format` (date only), `datetime_format` (date + time), `replace_year` (replaces {year} with current year)
- **Twig functions**: `date_format_pattern()` returns current format for JavaScript date pickers
- **Format setting**: Configurable via `date.format` setting stored in database (Y-m-d or d-m-Y)
- **Backend helper**: DateHelper class provides consistent formatting across controllers
- **Copyright placeholders**: Use `{year}` in copyright text (e.g., "© {year} Photography Portfolio") - auto-replaced on render via `replace_year` filter (added in commit 3c1107f)
- **Extension class**: `DateTwigExtension` registers all date-related filters and functions
- **Used extensively**: Album shoot dates, user timestamps, admin panel date displays, footer copyright

### Translation Management (i18n)
- **Dual translation system**: Separate JSON files for frontend and admin panel
  - Frontend: `en.json`, `it.json` (public-facing UI)
  - Admin: `en_admin.json`, `it_admin.json` (admin panel UI)
- **Settings**: Two separate language settings in SettingsService defaults:
  - `site.language` - Language for public website (default: 'en')
  - `admin.language` - Language for admin panel (default: 'en')
- **TranslationService scope**: Service uses `setScope('frontend'|'admin')` to switch between translation contexts
  - Separate language tracking: `language` (frontend) and `adminLanguage` (admin) properties
  - Methods: `setLanguage()`, `setAdminLanguage()`, `getActiveLanguage()` returns current scope's language
  - Cache invalidation: Setting new language only invalidates cache if scope matches (frontend language change doesn't clear admin cache)
  - Scope-aware loading: `getActiveLanguage()` returns `adminLanguage` if scope is 'admin', otherwise `language`
- **TextsController**: Admin panel for managing translations with CRUD operations, search/filter, and scope selector (frontend/admin tabs)
- **Import/Export system**: Download translations as JSON, import from preset languages, upload custom JSON files
- **Three import modes**:
  - Merge: Update existing keys, add new ones (default)
  - Replace: Overwrite existing keys, add new ones
  - Skip: Only add new keys, leave existing unchanged
- **Preset languages**: Server-side language files with versioning in `storage/translations/`
- **Language dropdown**: Server-side rendering via `getAvailableLanguages()` method in TextsController (not AJAX) to prevent silent failures on fresh installs
- **Language metadata**: Each preset includes code, name, version fields for display in dropdown
- **Save as preset**: Upload custom JSON and save it as a preset language file in storage/translations/
- **Translation source**: Loads from JSON files in `storage/translations/` by default; database table used only for user customizations
- **Schema approach**: Database schemas do not include default translation INSERTs; keeps JSON as single source of truth
- **Context grouping**: Organize translations by context (general, admin, nav, etc.) for easier management
- **Inline editing**: AJAX-based inline text editing without page reload (with CSRF protection)
- **Case-insensitive normalization**: Language codes normalized to lowercase via `strtolower()` for filesystem compatibility
- **Usage in templates**: `{{ trans('key.name') }}` function for all translatable text in both frontend and admin
- **Admin panel i18n**: All admin templates use `trans('admin.*')` keys for full internationalization (albums, texts, analytics, etc.)
- **Error page i18n**: Error templates use `errors.*` translation keys
  - 404 keys: `errors.404.title`, `errors.404.message`, `errors.404.back_home`, `errors.404.back_dashboard`
  - 500 keys: `errors.500.title`, `errors.500.message`, `errors.500.back_home`, `errors.500.back_dashboard`

### Social Sharing
- **Template component**: `_social_sharing.twig` for social media sharing buttons
- **Admin management**: `SocialController` provides UI for enabling/disabling networks and reordering
- **Settings storage**: `social.enabled` (array of enabled networks) and `social.order` (display order)
- **Default networks**: Behance, WhatsApp, Facebook, X, DeviantArt, Instagram, Pinterest, Telegram, Threads, Bluesky
- **Dual input support**: Accepts both form-encoded and JSON payloads with AJAX response capability
  - Form payload: `social_<key>=on` checkboxes + `social_order` JSON string
  - JSON payload: `enabled` array + `order` array
  - AJAX detection: Checks `X-Requested-With` header and `Accept: application/json`
- **Network configuration**: Each network has name, icon, color, and URL template with {title}/{url} placeholders
- **Order preservation**: Sanitizes order array to only include enabled socials, adds missing enabled socials to end
- **CSRF validation**: Supports token in both form body (`csrf` field) and header (`X-CSRF-Token`)
- **URL encoding**: Properly encodes title and URL for sharing parameters
- **Security**: Validates share URLs, adds `rel="noopener noreferrer"` for external links
- **Icon support**: Uses FontAwesome icons for network branding

### Gallery Templates Management
- **TemplatesController**: Admin panel for editing pre-built gallery templates (creation/deletion disabled for safety)
- **Template data structure**: JSON-encoded `settings` (layout, columns, masonry, photoswipe) and `libs` (required libraries like photoswipe, masonry)
- **Layout options**: grid, masonry, masonry_fit, slideshow, fullscreen
- **Responsive columns**: Separate configuration for desktop (1-6 columns), tablet (1-4), and mobile (1-2)
- **Masonry library**: Auto-included in `libs` array when masonry is enabled
- **PhotoSwipe configuration**: Boolean toggles (loop, zoom, share, counter, arrowKeys, escKey, allowPanToNext) plus numeric settings (bgOpacity: 0-1, spacing: 0-1)
- **Magazine-specific settings** (template id 3): Separate duration values for 3 columns (min: 10s, max: 300s) and gap setting (0-80px)
- **Template normalization**: `PageController::normalizeTemplateSettings()` flattens deeply nested column structures
  - Handles recursive nested objects: `columns.desktop.desktop.desktop` → `columns.desktop`
  - Unwrapping logic: `while (is_array($value) && isset($value[$device])) { $value = $value[$device]; }`
  - Validates column counts: desktop (1-6), tablet (1-4), mobile (1-2), with numeric and range validation
  - Fallback defaults on invalid values: desktop=3, tablet=2, mobile=1 via match expression
  - Applied to all template settings before rendering gallery pages in `gallery()` and `album()` methods
- **Masonry Full layout** (masonry_fit): Displays full images without cropping using CSS columns (not Masonry.js)
  - Pure CSS implementation: Uses `column-count` for responsive column layout (no JavaScript library)
  - CSS properties: `break-inside: avoid` on items, `column-gap` for spacing, `margin-bottom` for vertical gaps
  - Gap settings: `gap.horizontal` (column-gap) and `gap.vertical` (margin-bottom) in pixels (0-100px)
  - Responsive breakpoints: desktop (1024px+), tablet (768-1023px), mobile (<768px) with per-device column counts
  - CSP-safe inline styles: Gap values injected via `<style nonce="{{ csp_nonce() }}">` in album.twig and gallery.twig
  - Template: `_image_item_masonry.twig` for uncropped image display with hover effects and responsive srcset
  - Reusable helper: `window.initAlbumMasonryFit()` for consistent masonry_fit initialization across album views
  - Admin UI: Gap settings visible when layout is masonry_fit or template slug is 'masonry-full'
  - Admin controller: Processes `masonry_gap_h` and `masonry_gap_v` form fields, validates 0-100px range
- **Template switcher UI**: Frontend template selector on gallery/album pages
  - Position: Above gallery content, right-aligned, hidden on mobile (`max-md:hidden`)
  - Button styling: Inline flex with icon-based template identification, active state uses `bg-black text-white`
  - Icon mapping: masonry_fit (expand-arrows-alt), slideshow (play-circle/images), grid (th/pause/grip-horizontal), mosaic (puzzle-piece), carousel (arrows-alt-h), polaroid (camera-retro), fullscreen (expand)
  - Column-based icons: Grid templates show different icons based on desktop column count (2: pause, 3: th, 5+: grip-horizontal)
  - Template data: Uses `data-template-id` and `data-album-ref` attributes for AJAX switching
  - Desktop-only: Switcher hidden on mobile to preserve screen space
- **Masonry fit helper**: Reusable `window.initAlbumMasonryFit()` function for consistent masonry_fit initialization with cleanup
  - Global helper: Defined in album.twig for use across album and gallery views
  - Configuration: Handles imagesLoaded callback, Masonry.js initialization with `horizontalOrder: true`
  - Consistency: Ensures uniform masonry_fit behavior across different album templates
  - **Cleanup support**: Returns `destroy()` function for proper resource cleanup
    - Observer cleanup: Destroys IntersectionObserver instances to prevent memory leaks
    - Resize handler cleanup: Removes stored resize event listeners before reinitializing
    - Handler references: Stores resize handler in variable for proper removal via `removeEventListener()`
    - Prevents handler accumulation on template switches or re-initialization
- **Settings structure example**:
  ```php
  {
    "layout": "grid|masonry|masonry_fit|slideshow|fullscreen",
    "columns": {"desktop": 3, "tablet": 2, "mobile": 1},
    "masonry": true|false,
    "gap": {"horizontal": 16, "vertical": 16},  // masonry_fit layout
    "photoswipe": {
      "loop": true, "zoom": true, "share": false,
      "counter": true, "arrowKeys": true, "escKey": true,
      "bgOpacity": 0.8, "spacing": 0.12, "allowPanToNext": true
    },
    "magazine": {"durations": [60, 72, 84], "gap": 20}  // template id 3 only
  }
  ```
- **CSRF protection**: All form submissions validated with timing-safe CSRF tokens
- **Slug auto-generation**: Uses `App\Support\Str::slug()` for SEO-friendly identifiers
- **Slug uniqueness enforcement**: Automatic numeric suffix appending when slug collision detected
  - Pattern: `$checkStmt = $pdo->prepare('SELECT COUNT(*) FROM {table} WHERE slug = :s')` in loop
  - Suffix generation: `$slug = $baseSlug . '-' . $counter++` until unique slug found
  - Applied in: AlbumsController (create/update operations, excludes current album ID on update)

### Magazine Gallery Layout (Responsive)
- **Template file**: `app/Views/frontend/_gallery_magazine_content.twig` for magazine split gallery
- **Three responsive layouts**: Mobile (1 column), tablet (2 columns), desktop (3 columns)
  - Mobile: `.m-mobile-wrap` - Single vertical scrolling track with all images
  - Tablet: `.m-tablet-wrap` - Two columns (768px-1199px), alternating up/down scroll
  - Desktop: `.m-desktop-wrap` - Three columns (1200px+), center column up, outer columns down
- **Infinite scroll pattern**: Images duplicated in DOM for seamless loop effect
  - Each column contains original items + duplicate items marked with `.pswp-is-duplicate`
  - Duplicate items use same `data-pswp-index` as originals for PhotoSwipe sync
- **CSS animations**: Column-based vertical scrolling with configurable durations and gaps
  - Duration variables: `--m-duration` per column (configurable via `template_settings.magazine.durations`)
  - Gap setting: Configurable spacing between columns (0-80px via `template_settings.magazine.gap`)
  - Direction attribute: `data-direction="up|down"` controls animation direction per column
- **Touch compatibility**: `touch-action: pan-y` allows page scrolling through animated columns
  - Compatible with Lenis smooth scroll on parent page
  - Explicit height declarations: Uses `dvh` (dynamic viewport height) for mobile compatibility with dynamic address bar
  - min-height values: 100dvh (mobile), 130dvh (tablet), 140dvh (desktop)
- **Image distribution**: Smart column assignment preserving original index
  - Desktop: Round-robin distribution (i % 3) into col1, col2, col3
  - Tablet: Alternating distribution (i % 2) into tablet_col1, tablet_col2
  - Mobile: Sequential display of all images in single column
  - `original_index` merged into image data: `image|merge({'original_index': i})` for PhotoSwipe navigation
- **PhotoSwipe integration**: Extensive metadata attributes on each image link
  - Lightbox URL with base path handling: conditional prepend if path starts with '/'
  - Dimension attributes: `data-pswp-width`, `data-pswp-height` for aspect ratio calculation
  - Caption building: Uses `Caption.build(image)` macro for equipment metadata
  - Equipment data: camera, lens, film, developer, lab, location, ISO, shutter speed, aperture
  - Index tracking: `data-pswp-index` uses `original_index` for proper navigation across duplicates
- **SEO macro integration**: Uses `Seo.seo_title()` for semantic title generation
  - Pattern: `{% import 'frontend/_seo_macros.twig' as Seo %}` at template top
  - Title composition: Combines image alt/caption, album title, and site title
  - Applied to: `title` attribute on links and `alt` on images
- **Responsive images**: `<picture>` element with multi-format support (AVIF, WebP, JPEG)
  - Macro-based generation: `{% import _self as G %}` and `{{ G.pic(image, base_path, album, seo_title) }}`
  - Format sources: Separate `<source>` tags for each format with `srcset` and `sizes`
  - Sizes attribute: `(min-width:1200px) 50vw, (min-width:768px) 70vw, 100vw`
  - Lazy loading: `loading="lazy" decoding="async"` on fallback `<img>` tag
- **Edge fade effect**: Top and bottom gradient veils for smooth visual boundaries
  - Positioning: `.masonry-veil-top` (-top-1, h-40), `.masonry-veil-bottom` (-bottom-1, h-40)
  - Layer order: z-20, pointer-events-none to allow interaction with underlying images

### PWA Manifest Generation
- **Dynamic web manifest**: `PageController::webManifest()` generates `/site.webmanifest` endpoint
- **Configurable colors**: Uses `pwa.theme_color` and `pwa.background_color` settings (defaults: `#ffffff`)
- **Short name truncation**: `truncateShortName()` helper truncates site name at word boundary (max 12 chars) for PWA short_name field
- **Favicon integration**: Reads generated favicons from FaviconService for manifest icons
- **Base path support**: Handles subdirectory installations with proper icon path resolution

### Settings Validation Patterns
- **reCAPTCHA validation**: `SettingsController::save()` validates both keys before enabling
  - Requires both site key and secret key to enable reCAPTCHA (cannot enable with empty keys)
  - Key format validation: Regex pattern `/^[A-Za-z0-9_-]+$/` for alphanumeric, underscore, hyphen only
  - Rejects invalid keys with flash error: "Invalid reCAPTCHA Site/Secret Key format"
  - Reads existing keys from database if new values not provided (lines 147-148)
  - Validates final keys before allowing enablement: `if ($recaptchaEnabled && ($finalSiteKey === '' || $finalSecretKey === ''))` (line 155)
  - Auto-disables reCAPTCHA with flash error if keys missing
- **Conditional key updates**: Secret keys only updated if new value provided (preserves existing keys on empty input)
  - Pattern: `if ($recaptchaSecretKey !== '') { $svc->set('recaptcha.secret_key', $recaptchaSecretKey); }` (line 162-164)
  - Security: Never exposes existing secret key to client (one-way write only)
- **Fallback handling**: Uses `$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'` for reCAPTCHA verification to handle missing REMOTE_ADDR

### Contact Form Protection (reCAPTCHA v3)
- **Admin configuration**: SettingsController manages site key, secret key, and enable/disable toggle
- **Frontend integration**: about.twig conditionally loads reCAPTCHA v3 script when enabled
  - Script URL: `https://www.google.com/recaptcha/api.js?render={site_key}`
  - Action name: 'contact' for form submissions
  - Client-side: `grecaptcha.execute()` on form submit, adds hidden `recaptcha_token` field
- **Backend verification**: PageController::contact() validates token before processing
  - Library: ReCaptcha\ReCaptcha from the Google reCAPTCHA (`google/recaptcha`) Composer package
  - Expected action: 'contact' (matches frontend)
  - Score threshold: 0.5 (v3 returns 0.0-1.0, higher = more human)
  - Token validation: `setExpectedAction('contact')->setScoreThreshold(0.5)->verify()`
  - Rejection handling: Logs warning with error codes and score, redirects to `/about?error=1`
- **Settings storage**: `recaptcha.enabled` (boolean), `recaptcha.site_key` (string), `recaptcha.secret_key` (string)
- **CSP compatibility**: reCAPTCHA domains whitelisted in SecurityHeadersMiddleware
- **Graceful degradation**: Form works without JavaScript if reCAPTCHA disabled

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
- **CSP nonce**: Always add `nonce="{{ csp_nonce() }}"` to inline `<script>` and `<style>` tags for Content Security Policy compliance

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
- Snap Albums and Gallery Wall home templates (commit 13a60c1)
  - New Gallery Wall template (`home_gallery.twig`): Horizontal scrolling wall of individual images with sticky positioning
  - Responsive image sizing: Horizontal (1.5x viewport height) and vertical (0.67x viewport height) aspect ratios
  - Mobile fallback: 2-column vertical grid with wide items every 4th position
  - GSAP animation improvements: Changed from `gsap.to()` to `gsap.fromTo()` with explicit start/end values
  - Animation marker timing: `data-animated` attribute set in `onComplete` callback prevents CSS race conditions
  - Header behavior: Added `gallery-wall-page` body class detection to skip header hide-on-scroll
  - CSP compliance: All inline styles use nonce-protected tags
- Code review fixes for custom fields system (commit 8998a86)
  - MetadataExtensionService: Added `error_log()` for JSON encode failures, consistent `JSON_THROW_ON_ERROR` in `decodeValue()`, added `metadata_extensions_cleared` hook for bulk removal operations with entity context
  - Icon picker templates (create.twig, edit.twig): Replaced `innerHTML` with safer DOM manipulation using `createElement` + `appendChild` for XSS prevention
  - Migration 2024_02_metadata_extensions.php: MySQL-compatible index creation using `INFORMATION_SCHEMA.STATISTICS` check instead of `IF NOT EXISTS` syntax
- Security hardening and UX improvements for custom fields (commit d3dbca5)
  - Icon validation: Server-side whitelist validation against `getAvailableIcons()` to prevent injection attacks
  - Name format validation: Client-side regex `/^[a-z0-9_]+$/` with real-time error display
  - Field type change warnings: Visual warning when changing from select/multi_select to text
  - CSP compliance: Added nonces to all inline scripts in custom field create/edit templates
  - Database optimization: Removed redundant idx_cft_name index (name already has UNIQUE constraint)
  - MySQL syntax fix: Changed `ON UPDATE NOW()` to `ON UPDATE CURRENT_TIMESTAMP` in metadata_extensions
  - Early return pattern: CustomFieldService::deleteFieldValue() exits early for non-existent values
  - Sidebar icon update: Changed from fa-sliders to fa-sliders-h for better visual distinction
- Custom fields system implementation (commit d7c3279)
  - Full CRUD for custom field types with text/select/multi_select support
  - Icon picker with 20+ FontAwesome icons, drag-drop value ordering
  - Image and album metadata inheritance with override capability
  - Admin UI with system type protection and graceful degradation
  - Migration required detection with helpful admin messaging
- Memory leak fixes and animation improvements (commit 9fd9eb7)
  - Memory leak prevention in home_masonry.twig: clone pooling with 200 item DOM limit, offscreen cleanup (3+ viewports), observer cleanup, recycled DOM nodes (max 50 pool), recursion limits (balanceColumns: 5, ensureViewportFilled: 10)
  - Double animation fix: changed GSAP from gsap.from() to gsap.to() pattern, added data-animated tracking, CSS coordination for initial hiding, 2-second timeout fallback
  - Cleanup improvements in album.twig: destroy() function in initAlbumMasonryFit for observer cleanup, fixed resize handler accumulation with proper removeEventListener
  - Performance: reduced viewport fill target from 4.6x to 3x, rootMargin from 300% to 200%
- Code review refinements for masonry and admin layouts (commit 25d85d2)
  - Fixed balanceColumns while loop to calculate clones upfront for single batch append
  - Reduced IntersectionObserver rootMargin from 300% to 200% for balanced preload performance
  - Simplified CSS visibility rules by removing redundant properties
  - Increased admin-sticky-actions z-index from 50 to 9000 for proper layering above dropdowns
  - Extracted masonry_fit logic to reusable window.initAlbumMasonryFit helper for consistency
  - Added imagesLoaded fallback consistency in regular masonry layout
- Image upload performance optimizations (commit 2f5b717)
  - Client-side compression with @uppy/compressor (85% quality, 4000x4000px max)
  - Parallel uploads with 3 concurrent file limit for faster bulk operations
  - 2-minute XHR timeout for large compressed files
  - Compression progress feedback with EN/IT translation support
  - Reduces upload size by 50-70% for high-resolution photos
- Masonry home template infinite scroll enhancements (commit b6b9ccc)
  - Hybrid CSS Grid + Masonry.js approach for progressive enhancement
  - Infinite scroll with automatic cloning (4.6x viewport fill target)
  - IntersectionObserver-based batch append (20 items per trigger, 80% threshold)
  - Column balancing and fade-in animations with reduced-motion support
  - Image preloading with fetchpriority="high" for above-fold content
  - Optimized Lenis smooth scroll (lerp: 0.08, wheelMultiplier: 1.2)
  - Fixed masonry_fit layout in album.twig with horizontalOrder: true
- Pure Masonry home template implementation (commits 1b71338, 6b793fa)
  - New `home_masonry.twig` with Masonry.js-based layout
  - Configurable gaps (horizontal/vertical 0-40px) and responsive columns (desktop: 2-8, tablet: 2-6, mobile: 1-4)
  - Dynamic column width calculation with imagesLoaded support
  - Admin UI: Range sliders and device-specific column selects
  - Template validation expanded to include 'masonry' as 4th option
  - Visual template selector: Rose-to-orange gradient icon for masonry
- Feature analysis documentation (commit 56a9ce5)
  - Added `docs/FEATURE_ANALYSIS.md` comparing Cimaise to Envira Gallery
  - Prioritized roadmap for photographer-focused features (watermarking, client proofing, video galleries)
  - Implementation effort estimates and feature comparison matrix
- Parallax home template implementation (commit 8379fe7)
  - New `home_parallax.twig` with smooth scroll parallax effects
  - Responsive grid layout (3→2→1 columns based on viewport)
  - Fixed image variant path resolution with file existence checks
  - Added EN/IT translations for parallax template
  - Visual template selector in admin panel (classic/modern/parallax/masonry)
- Code review refinements and album category migration (commit defefaf)
  - Album-category junction table for multi-category support
  - Simplified protected media logic: removed `allow_downloads` conditionals, always use protected endpoint for password/NSFW albums
  - Date input picker visibility improvements
  - CSP compliance for filter labels and i18n
  - Template switcher and lightbox animation enhancements
  - Magazine gallery mobile compatibility: switched from `vh` to `dvh` (dynamic viewport height) for better mobile address bar handling
  - Image error handling: added `data-fallback="hide"` attribute for CSP-compliant graceful degradation
- README documentation overhaul with comprehensive feature showcase
  - Two-template system (Classic vs Modern home layouts) with visual comparisons
  - Password-protected galleries and NSFW/adult content mode documentation
  - Multi-criteria filtering (categories, tags, equipment, location, year)
  - Automatic image optimization workflow (5 sizes × 3 formats)
  - Settings management overview (site identity, gallery presentation, image handling)
- Breadcrumb schema clarification: JSON-LD doesn't require CSP nonce (type="application/ld+json")
- Security fix: Protected album storage path enforcement (commit 3999f0c)
  - Always exclude `/storage/` paths from srcsets for protected albums regardless of `allow_downloads` flag
  - Prevents bypassing server-side access control via direct URL manipulation
  - Ensures MediaController validates session before serving any protected album images
  - Applied to: `gallery_hero.twig`, `home_modern.twig`, `_image_item_masonry.twig`, `_gallery_magazine_content.twig`
- CSP compliance improvements: Added CSP nonces to all inline styles in frontend templates
  - `_layout.twig`, `galleries.twig`, `gallery_hero.twig` now use `<style nonce="{{ csp_nonce() }}">`
  - Fixed block scripts placement outside block content in `_layout.twig`
- Modern home template mobile integration and refinements (commits 9b055df, 93c8152, cccab73, a817551, fc701fe)
  - Mobile footer visibility and webkit search input styling
  - CSS reset scoped to desktop only to prevent Tailwind conflicts
  - Classic header integration for mobile via `.modern-mobile-only` wrapper
  - Hardened variant registration with alt fallback logic and SEO macro improvements
  - Breadcrumb responsive spacing and compact layout for mobile
- Enhanced header search UX with filter sync and mobile responsiveness (commit 1aa2c46)
- Lightbox click navigation improvements (commit 9172c13)
- Server-side NSFW and protected album enforcement with blur variants (commit 1348102)
- Comprehensive security hardening (CSP nonces, reCAPTCHA, image validation)
- Full admin panel internationalization with Italian translation
- Translation management system with import/export
- Advanced gallery filtering and responsive srcset optimization
- Controller cleanup with improved data attribute patterns (HTML5 compliance, commits afb565c, 1ae17f5)

### Pending Tasks
None currently.

### Important Reminders
- Never list Claude as git commit co-author
- Translation files in `storage/translations/` are JSON format
- Admin panel at `/admin/login`

<!-- END MANUAL -->
