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
Home templates: Classic (masonry + infinite scroll), Modern (fixed sidebar + grid layout)

Admin features: Drag & drop reordering, bulk upload (100+ images), inline editing, real-time preview, equipment-based browsing, full-text search

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
- `public/index.php` - Application bootstrap with auto-repair logic and base path detection
- `public/router.php` - PHP built-in server router (routes /media/* through PHP for access control)
- `app/Config/routes.php` - All route definitions (120+ routes including protected media)
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
- `app/Controllers/Admin/TextsController.php` - Translation management with import/export, search/filter, scope selector, server-side language dropdown via `getAvailableLanguages()`, and preset language support
- `app/Controllers/Admin/SeoController.php` - SEO settings management with Open Graph, Twitter Cards, Schema.org (Person, Organization, LocalBusiness), analytics integration (Google Tag Manager, GA4), image metadata, and sitemap generation
- `app/Controllers/Admin/SocialController.php` - Social sharing settings management with network enable/disable, ordering, AJAX/form support
- `app/Controllers/Admin/TemplatesController.php` - Gallery template management (edit only, creation/deletion disabled) with responsive column configuration, layout settings, PhotoSwipe options, and magazine-specific animations
- `app/Controllers/Admin/PagesController.php` - Pages management (home, about, galleries) with home template selection (classic/modern), hero sections, gallery text content
- `app/Controllers/Admin/SettingsController.php` - Site settings with image formats/quality/breakpoints, gallery templates, date format, site language, reCAPTCHA configuration (requires both site and secret keys to enable), performance settings, admin debug logs toggle, triggers favicon generation after logo upload
- `app/Controllers/Frontend/PageController.php` - Frontend page rendering with SEO builder, template normalization, home template routing (classic/modern)
- `app/Extensions/DateTwigExtension.php` - Twig extension for date formatting (filters: date_format, datetime_format, replace_year; functions: date_format_pattern)
- `app/Middlewares/RateLimitMiddleware.php` - Brute-force protection and API rate limiting
- `app/Middlewares/SecurityHeadersMiddleware.php` - Security headers (CSP, HSTS, X-Frame-Options) with per-request nonce generation
- `app/Views/admin/_layout.twig` - Admin panel layout with CSP nonce, sidebar navigation, TinyMCE toolbar fixes, `window.__ADMIN_DEBUG` injection
- `app/Views/admin/settings.twig` - Settings page with image formats, breakpoints, site config, gallery templates, and admin debug logs toggle
- `app/Views/admin/texts/index.twig` - Translation management UI with import/export/upload, scope selector, server-side language dropdown, and language preset selection
- `app/Views/admin/albums/*.twig` - Album CRUD views with full i18n via trans() function
- `app/Views/frontend/_layout.twig` - Frontend layout with SEO meta tags, Open Graph, Twitter Cards, JSON-LD schemas (Person/Organization, BreadcrumbList, LocalBusiness), CSP nonce support
- `app/Views/frontend/_layout_modern.twig` - Modern template layout with Lenis smooth scroll, minimal header, mega menu overlay, JSON-LD schemas (BreadcrumbList, LocalBusiness)
- `app/Views/frontend/home.twig` - Classic home template with masonry layout and infinite scroll
- `app/Views/frontend/home_modern.twig` - Modern home template with fixed sidebar (filters, info) and scrollable grid (two-column infinite scroll)
- `app/Views/admin/pages/home.twig` - Home page settings with visual template selector (classic/modern), hero sections, gallery text, scroll direction
- `app/Views/frontend/_album_card.twig` - Album card template with NSFW blur variant logic
- `app/Views/frontend/_breadcrumbs.twig` - Breadcrumbs with automatic JSON-LD schema generation
- `app/Views/frontend/_social_sharing.twig` - Social sharing buttons template
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

### Frontend
- **Twig templates**: `{% extends %}` for layouts, `{% include %}` for partials
  - Template inheritance: `_layout.twig` (classic frontend), `_layout_modern.twig` (modern template with Lenis)
  - Home templates: `home.twig` (classic masonry), `home_modern.twig` (grid with sidebar)
- **Translation function**: `{{ trans('key.name') }}` for i18n (used in both frontend and admin templates)
- **Admin panel i18n**: Fully internationalized with `trans('admin.*')` keys and `en_admin.json`/`it_admin.json` files (complete Italian translation added in commit 41acb3b)
- **Date formatting**: Use `{{ date|date_format }}`, `{{ datetime|datetime_format }}`, `{{ text|replace_year }}` filters
- **CSP nonce**: Use `{{ csp_nonce() }}` for inline scripts (required by Content Security Policy)
- **Image error handling**: Use `data-fallback="hide"` attribute for graceful degradation (not inline `onerror` for CSP compliance)
- **Tailwind CSS**: Utility-first styling (classic templates)
- **Custom CSS**: Modern template uses custom CSS (`home-modern.css`) with CSS variables (`--modern-*`)
- **JavaScript**: ES6 modules, localStorage with Safari-safe wrappers, npm package imports (Lenis via Vite)

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
- **Template security**: NSFW album cards only show blur variants, never expose real image URLs in srcset or data-src
- **Path traversal protection**: Validates realpath against allowed directories (storage/ or public/media/), rejects paths with `..` or backslashes
- **MIME type validation**: Only serves image/* types (jpeg, webp, avif, png) via finfo_file()
- **ETag caching**: `private, max-age=3600, must-revalidate` for variants with 304 Not Modified support
- **File streaming**: Chunked streaming with 8KB buffer for memory efficiency
- **Router enforcement**: `public/router.php` routes all /media/* requests through PHP (prevents direct file access bypass)

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
  - Styles: `<style nonce="{{ csp_nonce() }}">...</style>` for inline CSS
- **Admin layout**: All inline scripts and styles in `_layout.twig` benefit from relaxed CSP policy
  - Window globals: `window.basePath`, `window.cspNonce`
  - Inline styles: Component styling, TinyMCE fixes, sidebar styles
  - TinyMCE initialization and configuration work without nonce requirement
- **Additional headers**: X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, HSTS, Referrer-Policy, Permissions-Policy, Cross-Origin-Opener-Policy, X-Permitted-Cross-Domain-Policies, Expect-CT
- **CSP directives**: upgrade-insecure-requests, img-src with data: and blob:, style-src with unsafe-inline for third-party CSS, font-src for Google Fonts, object-src none, base-uri self, form-action self, frame-ancestors none
- **reCAPTCHA whitelist**: Frontend CSP includes `frame-src` and `script-src` for Google reCAPTCHA domains (www.google.com/recaptcha/, www.gstatic.com/recaptcha/)

### Advanced Filtering (Galleries)
- Multi-criteria filtering: categories, tags, cameras, lenses, films, locations, year, search
- AJAX-based filter API endpoint (`/galleries/filter`)
- Client-side filter state management with URL parameters
- Filter options dynamically generated from database (with album counts)
- Empty state handling when no galleries match filters

### Frontend JavaScript (Uppy Upload, TinyMCE, GSAP, Tom Select, PhotoSwipe)
- **Uppy file upload**: XHR-based upload with CSRF token, file restrictions (JPEG, PNG, WebP), drag-drop support
- **Progress tracking**: Real-time per-file and total progress UI with visual bars and file list
- **Error handling**: XSS-safe filename sanitization, duplicate detection, user notifications (toast messages)
- **File deduplication**: Compares files by name and size to prevent redundant uploads
- **TinyMCE editor**: Configured with link, lists, and autoresize plugins for content editing
- **Tom Select dropdowns**: Auto-initialized on select elements with `tom-select` class, supports multi-select
- **Sortable drag-drop**: Sortablejs for reorderable gallery/image lists (client-side only)
- **GSAP animations**: Imported for smooth transitions and animations (gsap library)
- **PhotoSwipe lightbox**: Enhanced layout with zoom-friendly sizing, minimal floating UI
  - No max constraints on images to allow proper zoom functionality
  - Zoom disabled: `wheelToZoom: false`, manual zoom controls preferred
  - Drag disabled: `closeOnVerticalDrag: false` prevents accidental closes
  - Padding: `{ top: 28, bottom: 40, left: 28, right: 28 }` for balanced centering
  - Background scroll lock: `html.pswp-open` and `body.pswp-open` with `position: fixed` prevents body scroll
  - Arrow buttons: 46px floating circles (38px on mobile) positioned at `left: 12px` / `right: 12px`
  - Top bar: Positioned at `top: 12px, right: 12px` with flex-end justify, no background
  - Object-fit: `contain` with `center` positioning and `cursor: pointer` for proper image display
  - Click/tap action: `next` (configurable via `psCfg.imageClickAction`)
- **Idempotent initialization**: Guards against double-initialization of upload areas with `_uppyInitialized` flag
- **Global instance tracking**: `window.uppyInstances` array for proper cleanup and SPA re-initialization

### Home Page Templates & Animations
- **Template system**: Configurable via `home.template` setting (classic or modern)
  - Setting managed in `PagesController::saveHome()` and rendered in `PageController::home()`
  - Template selection: `$homeTemplate === 'modern' ? 'frontend/home_modern.twig' : 'frontend/home.twig'`
  - Classic template: `frontend/home.twig` (default)
  - Modern template: `frontend/home_modern.twig` (grid-based layout)
- **Admin template selector**: Visual radio button UI in `admin/pages/home.twig`
  - Two template options with icons and descriptions
  - Classic: Gradient gray-to-black icon (from-gray-700 to-gray-900, fa-images), "Feature-rich layout" with masonry and carousel
  - Modern: Gradient indigo-to-purple icon (from-indigo-500 to-purple-600, fa-th-large), "Minimal & clean" with grid and sidebar
  - Active state: Border black, background gray-50, check icon visible (fas fa-check-circle)
  - Translation keys: `admin.pages.home.template_*` (selection, classic, modern, descriptions, subtitles)
  - Template-specific settings visibility: Classic settings hidden when modern selected via conditional `hidden` class
  - JavaScript-based template switching: Updates radio buttons, visual states, and shows/hides corresponding settings sections

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
  - Template files: `app/Views/frontend/home_modern.twig` extends `_layout_modern.twig`
  - Assets: `public/assets/css/home-modern.css`, `public/assets/js/home-modern.js`
  - Source: `resources/js/home-modern.js` (built via Vite)
- **Layout components**:
  - Global header: Fixed top-left logo with mix-blend-mode difference
  - Menu button: Fixed top-right with uppercase text, opens mega menu overlay
  - Left sidebar: Category filters (data-filter attributes), photo counter, hover info display
  - Right grid: Two-column image layout with infinite scroll
  - Mega menu: Full-screen overlay with close button and navigation links
  - Page transition: Overlay for smooth page changes
- **Lenis smooth scroll**: Integration via ES module import from npm (`lenis@1.1.18`)
  - Duration: 2.0s, easing: `(t) => 1 - Math.pow(1 - t, 4)`, direction: vertical
  - Touch multiplier: 1.5, wheel multiplier: 0.8, smooth touch disabled
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
  - Attributes: `image-grid_title` and `image-grid_copy` populated from `work-title` and `work-copy` data attributes
  - Updates on mouseenter event for each `.inf-work_item`
- **Mega menu overlay**: Full-screen navigation with close button
  - Menu toggle: `.menu-btn` opens, `.menu-close` closes
  - Page transition overlay: `.page-transition` for smooth navigation
  - Navigation links: Home, Galleries, About with `.is-current` class for active page
  - Current page link behavior: Clicking `.mega-menu_link.is-current` closes menu instead of navigating
- **CSS variables**: `--modern-black`, `--modern-white`, `--modern-grey`, `--modern-dark-grey`
- **Responsive font sizing**: Root font size set to `1.1111111111vw` (capped at `21.333px` on 1920px+ screens)
- **Empty state**: Custom empty state with icon, title, text when no images available
  - Uses `home_settings.empty_title` and `home_settings.empty_text` with translation fallbacks
- **Translation keys**:
  - `frontend.home_modern.*`: all_work, albums, photos, close, empty_title, empty_text, menu
  - `frontend.menu.*`: home, galleries, about (for mega menu navigation)
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
- **Settings step**: Collects site title, description, copyright (with {year} placeholder), email, language (en/it for both frontend and admin), date format (Y-m-d/d-m-Y), and optional logo upload
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
- **Favicon support**: Standard favicon and Apple touch icon with theme color
- **Accessibility**: `lang` attribute on `<html>` tag with i18n support

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
- **Layout options**: grid, masonry, slideshow, fullscreen
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
- **Settings structure example**:
  ```php
  {
    "layout": "grid|masonry|slideshow|fullscreen",
    "columns": {"desktop": 3, "tablet": 2, "mobile": 1},
    "masonry": true|false,
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
  - Library: ReCaptcha\ReCaptcha from google/recaptcha composer package
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
