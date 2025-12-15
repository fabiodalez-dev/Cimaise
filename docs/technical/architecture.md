# Architettura e Flusso - Cimaise

## Panoramica del Progetto

**Cimaise** è un sistema di gestione di contenuti (CMS) specializzato per portfolio fotografici professionali. È costruito seguendo i principi di separazione delle responsabilità, sicurezza e performance.

### Stack Tecnologico

#### Backend
- **PHP**: 8.2+
- **Framework**: Slim 4.12 (micro-framework PSR-7/PSR-15)
- **Template Engine**: Twig (via slim/twig-view)
- **Database**: MySQL 8.0+ / MariaDB 10.6+ / SQLite 3.x
- **CLI**: Symfony Console 6.4
- **Testing**: PHPUnit 10.5
- **Dipendenze**: Gestite via Composer

#### Frontend
- **Build Tool**: Vite 5.4.0
- **CSS Framework**: Tailwind CSS 3.4.17
- **JavaScript**: ES Modules (nativo)
- **Animazioni**: GSAP 3.13.0, Lenis 1.3.11
- **Upload**: Uppy 4.2.2
- **Charts**: Chart.js 4.5.0
- **Editor**: TinyMCE 6.8.6
- **Lightbox**: PhotoSwipe (bundled)

#### Database & Storage
- **ORM**: Nessuno (PDO puro con prepared statements)
- **Astrazione DB**: `app/Support/Database.php` (supporto multi-driver)
- **Cache**: File-based (storage/tmp)
- **Media**: File system (storage/originals + public/media)

---

## Struttura del Progetto

```
Cimaise/
├── app/                          # Codice applicativo PHP
│   ├── Config/                   # Configurazione
│   │   ├── bootstrap.php         # Inizializzazione (DB, env, container)
│   │   └── routes.php            # Definizione route Slim
│   │
│   ├── Controllers/              # Layer di presentazione
│   │   ├── Admin/                # Controller pannello admin
│   │   │   ├── AdminController.php        # Dashboard
│   │   │   ├── AlbumsController.php       # CRUD album
│   │   │   ├── AnalyticsController.php    # Analytics
│   │   │   ├── CategoriesController.php   # CRUD categorie
│   │   │   ├── CommandsController.php     # Esecuzione CLI da web
│   │   │   ├── DiagnosticsController.php  # Diagnostica sistema
│   │   │   ├── FilterSettingsController.php
│   │   │   ├── MediaController.php
│   │   │   ├── PagesController.php
│   │   │   ├── SeoController.php
│   │   │   ├── SettingsController.php
│   │   │   ├── SocialController.php
│   │   │   ├── TagsController.php
│   │   │   ├── TemplatesController.php
│   │   │   ├── UsersController.php
│   │   │   └── [equipment controllers...]
│   │   │
│   │   ├── Frontend/             # Controller pubblici
│   │   │   ├── HomeController.php
│   │   │   ├── AlbumController.php
│   │   │   ├── CategoryController.php
│   │   │   ├── TagController.php
│   │   │   ├── GalleriesController.php
│   │   │   ├── AboutController.php
│   │   │   └── DownloadController.php
│   │   │
│   │   ├── BaseController.php    # Controller base (render, flash)
│   │   ├── AuthController.php    # Login/logout
│   │   └── InstallerController.php
│   │
│   ├── Services/                 # Business logic
│   │   ├── AnalyticsService.php  # Tracking e reportistica
│   │   ├── BaseUrlService.php    # Gestione URL base
│   │   ├── ExifService.php       # Estrazione metadati immagini
│   │   ├── ImagesService.php     # Operazioni su immagini
│   │   ├── SettingsService.php   # Key-value store settings
│   │   └── UploadService.php     # Upload e validazione file
│   │
│   ├── Repositories/             # Data access layer
│   │   └── LocationRepository.php
│   │
│   ├── Middlewares/              # PSR-15 Middleware
│   │   ├── AuthMiddleware.php    # Verifica autenticazione admin
│   │   ├── CsrfMiddleware.php    # CSRF token validation
│   │   ├── FileBasedRateLimitMiddleware.php
│   │   ├── FlashMiddleware.php   # Flash messages sessione
│   │   ├── RateLimitMiddleware.php
│   │   └── SecurityHeadersMiddleware.php
│   │
│   ├── Tasks/                    # Comandi CLI (Symfony Console)
│   │   ├── AnalyticsCleanupCommand.php
│   │   ├── AnalyticsSummarizeCommand.php
│   │   ├── DbTestCommand.php
│   │   ├── DiagnosticsCommand.php
│   │   ├── ImagesGenerateCommand.php
│   │   ├── InitCommand.php
│   │   ├── MigrateCommand.php
│   │   ├── SeedCommand.php
│   │   ├── SitemapCommand.php
│   │   ├── UserCreateCommand.php
│   │   └── UserUpdateCommand.php
│   │
│   ├── Extensions/               # Twig extensions
│   │   ├── AnalyticsTwigExtension.php
│   │   └── SecurityTwigExtension.php
│   │
│   ├── Support/                  # Utility classes
│   │   ├── Database.php          # Astrazione database (MySQL/SQLite)
│   │   ├── Sanitizer.php         # HTML sanitization
│   │   └── Str.php               # String utilities
│   │
│   ├── Installer/
│   │   └── Installer.php         # Logica wizard installazione
│   │
│   └── Views/                    # Template Twig
│       ├── admin/                # Interfaccia amministrativa
│       ├── frontend/             # Pagine pubbliche
│       ├── installer/            # Wizard installazione
│       ├── errors/               # Pagine errore (404, 500)
│       └── layouts/              # Layout base
│
├── public/                       # Document root (web-accessible)
│   ├── index.php                 # Entry point applicazione
│   ├── .htaccess                 # Rewrite rules Apache
│   ├── assets/                   # Asset compilati (Vite output)
│   │   ├── app-[hash].js
│   │   ├── app-[hash].css
│   │   └── admin-[hash].js
│   ├── media/                    # Immagini servite (varianti responsive)
│   │   └── .htaccess             # Nega esecuzione PHP
│   └── router.php                # Router PHP built-in server
│
├── resources/                    # Sorgenti frontend
│   ├── js/                       # JavaScript modules
│   │   ├── home.js               # Home page logic
│   │   ├── home-gallery.js       # Infinite gallery
│   │   ├── home-carousel.js      # Albums carousel
│   │   ├── hero.js               # Hero animations
│   │   └── analytics.js          # Analytics tracking
│   ├── admin.js                  # Entry point admin
│   └── app.css                   # Entry point CSS (Tailwind)
│
├── storage/                      # Storage privato (no web access)
│   ├── originals/                # Immagini originali caricate
│   │   └── .htaccess             # Deny from all
│   └── tmp/                      # File temporanei, cache, rate limit
│       └── .htaccess             # Deny from all
│
├── database/                     # Database e migrazioni
│   ├── database.sqlite           # SQLite DB (se usato)
│   ├── migrations/               # Schema SQL
│   │   ├── mysql/
│   │   │   └── 001_initial_schema.sql
│   │   └── sqlite/
│   │       └── 001_initial_schema.sql
│   └── seeds/                    # Dati di esempio
│       └── demo_data.sql
│
├── bin/
│   └── console                   # CLI runner (#!/usr/bin/env php)
│
├── vendor/                       # Dipendenze Composer
├── node_modules/                 # Dipendenze npm
│
├── .env.example                  # Variabili ambiente (template)
├── .env                          # Variabili ambiente (effettive, gitignored)
├── composer.json                 # PHP dependencies
├── package.json                  # JavaScript dependencies
├── vite.config.js                # Configurazione Vite bundler
├── tailwind.config.js            # Configurazione Tailwind CSS
├── postcss.config.js             # Configurazione PostCSS
│
└── docs/                         # Questa documentazione
    ├── README.md
    ├── technical/
    └── user-manual/
```

---

## Flusso di una Richiesta HTTP

### 1. Entry Point

Tutte le richieste HTTP vengono reindirizzate a `public/index.php` tramite `.htaccess` (Apache) o configurazione server.

**public/index.php**:
```php
<?php
require __DIR__ . '/../vendor/autoload.php';

// 1. Carica environment e inizializza DB
$container = require __DIR__ . '/../app/Config/bootstrap.php';

// 2. Crea app Slim
$app = \Slim\Factory\AppFactory::createFromContainer($container);

// 3. Registra route
(require __DIR__ . '/../app/Config/routes.php')($app, $container);

// 4. Esegui applicazione
$app->run();
```

### 2. Bootstrap (`app/Config/bootstrap.php`)

```php
// Carica variabili ambiente (.env)
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

// Inizializza connessione database
$db = \App\Support\Database::connect([
    'driver' => $_ENV['DB_CONNECTION'], // sqlite|mysql
    'database' => $_ENV['DB_DATABASE'],
    'host' => $_ENV['DB_HOST'] ?? null,
    'port' => $_ENV['DB_PORT'] ?? null,
    'username' => $_ENV['DB_USERNAME'] ?? null,
    'password' => $_ENV['DB_PASSWORD'] ?? null,
]);

// Configura DI Container
$container = new \DI\Container();
$container->set('db', $db);
$container->set('view', /* Twig renderer */);
$container->set('settings', new SettingsService($db));
// ... altri servizi

return $container;
```

### 3. Routing (`app/Config/routes.php`)

```php
return function (Slim\App $app, $container) {
    // Frontend routes
    $app->get('/', [HomeController::class, 'index']);
    $app->get('/album/{slug}', [AlbumController::class, 'show']);

    // Admin routes (con middleware)
    $app->group('/admin', function ($group) {
        $group->get('', [AdminController::class, 'dashboard']);
        $group->get('/albums', [AlbumsController::class, 'index']);
        // ... altre route admin
    })->add(new AuthMiddleware($container->get('db')))
      ->add(new CsrfMiddleware());

    // API routes
    $app->get('/api/albums', [ApiController::class, 'albums']);
};
```

### 4. Middleware Stack

Le richieste attraversano i middleware in ordine LIFO (Last In, First Out):

```
Request
    ↓
SecurityHeadersMiddleware (aggiungi header sicurezza)
    ↓
FlashMiddleware (carica flash messages da sessione)
    ↓
RateLimitMiddleware (verifica limite richieste per IP)
    ↓
CsrfMiddleware (valida token su POST/PUT/DELETE)
    ↓
AuthMiddleware (verifica login admin)
    ↓
Controller::action()
    ↓
Response
```

**Esempio AuthMiddleware**:
```php
public function process(Request $request, RequestHandler $handler): Response
{
    // Verifica sessione
    if (!isset($_SESSION['admin_id'])) {
        return new RedirectResponse('/admin/login');
    }

    // Verifica utente attivo in DB
    $user = $this->db->query(
        "SELECT * FROM users WHERE id = ? AND is_active = 1",
        [$_SESSION['admin_id']]
    )->fetch();

    if (!$user) {
        session_destroy();
        return new RedirectResponse('/admin/login');
    }

    // Continua alla prossima middleware/controller
    return $handler->handle($request);
}
```

### 5. Controller Layer

I controller gestiscono la logica di presentazione:

```php
class AlbumController extends BaseController
{
    public function show(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'];

        // 1. Fetch data dal database
        $album = $this->db->query(
            "SELECT * FROM albums WHERE slug = ? AND is_published = 1",
            [$slug]
        )->fetch();

        if (!$album) {
            throw new NotFoundException();
        }

        // 2. Fetch immagini associate
        $images = $this->db->query(
            "SELECT * FROM images WHERE album_id = ? ORDER BY sort_order",
            [$album['id']]
        )->fetchAll();

        // 3. Track pageview (analytics)
        $this->analyticsService->trackPageview($request, 'album', $album['id']);

        // 4. Render template
        return $this->render($response, 'frontend/album.twig', [
            'album' => $album,
            'images' => $images,
        ]);
    }
}
```

### 6. View Layer (Twig)

I template Twig generano l'HTML finale:

```twig
{# frontend/album.twig #}
{% extends 'layouts/frontend.twig' %}

{% block content %}
<div class="album-container">
    <h1>{{ album.title }}</h1>

    <div class="gallery">
        {% for image in images %}
            {% include 'partials/_picture.twig' with {
                image: image,
                sizes: '(min-width: 1024px) 33vw, 100vw'
            } %}
        {% endfor %}
    </div>
</div>
{% endblock %}
```

**Partial `_picture.twig`** (responsive images):
```twig
<picture>
    <source type="image/avif" srcset="{{ image.variants.avif | join(', ') }}">
    <source type="image/webp" srcset="{{ image.variants.webp | join(', ') }}">
    <img src="{{ image.variants.jpg[0] }}"
         srcset="{{ image.variants.jpg | join(', ') }}"
         sizes="{{ sizes }}"
         alt="{{ image.alt_text }}"
         loading="lazy">
</picture>
```

---

## Pattern Architetturali

### MVC (Model-View-Controller)

Pur non usando un ORM formale, Cimaise segue il pattern MVC:

- **Model**: Database + Services/Repositories (business logic)
- **View**: Template Twig
- **Controller**: Classi in `app/Controllers/`

### Service Layer

La business logic è estratta in servizi riutilizzabili:

- `SettingsService`: Gestione configurazioni key-value
- `UploadService`: Validazione e ingest upload
- `ImagesService`: Operazioni su immagini (resize, conversion)
- `AnalyticsService`: Tracking e reportistica
- `ExifService`: Estrazione metadati fotografici

**Esempio SettingsService**:
```php
class SettingsService
{
    private PDO $db;
    private array $cache = [];

    public function get(string $key, $default = null)
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $stmt = $this->db->prepare("SELECT value, type FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if (!$row) {
            return $default;
        }

        // Deserializza in base al tipo
        $value = match($row['type']) {
            'boolean' => (bool) json_decode($row['value']),
            'number' => (int|float) json_decode($row['value']),
            'array' => json_decode($row['value'], true),
            default => json_decode($row['value'])
        };

        $this->cache[$key] = $value;
        return $value;
    }

    public function set(string $key, $value): void
    {
        // Serializza + detect type
        // INSERT ... ON DUPLICATE KEY UPDATE (MySQL)
        // o INSERT OR REPLACE (SQLite)
    }
}
```

### Repository Pattern (parziale)

Per entità complesse, utilizziamo repository:

```php
class LocationRepository
{
    public function findBySlug(string $slug): ?array
    {
        return $this->db->query(
            "SELECT * FROM locations WHERE slug = ?",
            [$slug]
        )->fetch();
    }

    public function getAllWithAlbumCount(): array
    {
        return $this->db->query("
            SELECT l.*, COUNT(a.id) as album_count
            FROM locations l
            LEFT JOIN albums a ON a.location_id = l.id
            GROUP BY l.id
            ORDER BY l.name
        ")->fetchAll();
    }
}
```

### Dependency Injection Container

Il DI Container (PHP-DI) gestisce le dipendenze:

```php
// bootstrap.php
$container->set('db', $db);
$container->set(SettingsService::class, function ($c) {
    return new SettingsService($c->get('db'));
});

// Controller
class AlbumsController
{
    public function __construct(
        private PDO $db,
        private SettingsService $settings,
        private UploadService $upload
    ) {}
}
```

---

## Flussi Applicativi Chiave

### Upload e Generazione Immagini

```
Admin: POST /admin/albums/{id}/upload
    ↓
UploadService::ingestAlbumUpload()
    ├─ Validazione file (MIME, magic number, size)
    ├─ Calcolo hash SHA1
    ├─ Salva in storage/originals/{hash}.{ext}
    ├─ Estrazione EXIF (ExifService)
    ├─ INSERT INTO images (album_id, original_path, width, height, exif, ...)
    └─ Genera preview (GD, 480px width)
    ↓
Admin: Click "Generate Images" (POST /admin/settings/generate-images)
    ↓
Esegue ImagesGenerateCommand in background
    ↓
Per ogni immagine:
    ├─ Carica originale (Imagick)
    ├─ Per ogni breakpoint (sm, md, lg, xl, xxl):
    │   ├─ Resize proporzionale
    │   ├─ Per ogni formato (avif, webp, jpg):
    │   │   ├─ Converti e comprimi con qualità impostata
    │   │   ├─ Salva in public/media/{hash}_{variant}_{format}.{ext}
    │   │   └─ INSERT INTO image_variants (image_id, variant, format, path, width, height, size_bytes)
    └─ Log progresso
```

### Autenticazione e Sessione

```
POST /admin/login
    ↓
AuthController::login()
    ├─ Valida CSRF token
    ├─ Query: SELECT * FROM users WHERE email = ?
    ├─ password_verify(input, user.password_hash)
    ├─ Verifica is_active = 1
    ├─ session_regenerate_id(true)  // Previene session fixation
    ├─ $_SESSION['admin_id'] = user.id
    ├─ $_SESSION['admin_email'] = user.email
    ├─ UPDATE users SET last_login = NOW() WHERE id = ?
    └─ Redirect /admin
    ↓
Ogni richiesta successiva /admin/*:
    ↓
AuthMiddleware::process()
    ├─ Verifica $_SESSION['admin_id']
    ├─ Query: SELECT * FROM users WHERE id = ? AND is_active = 1
    ├─ Se non trovato/inattivo → logout + redirect login
    └─ Continua
```

### Analytics Tracking

```
Frontend: Pageview (JavaScript)
    ↓
POST /api/analytics/track
    ├─ RateLimitMiddleware (max 60 req/min per IP)
    ├─ AnalyticsService::track()
    │   ├─ Determina session_id (cookie o genera nuovo)
    │   ├─ Hash IP (SHA256 + salt) per privacy
    │   ├─ Parse User-Agent (browser, OS, device)
    │   ├─ Geolocation lookup (IP → paese, città)
    │   ├─ Bot detection (User-Agent patterns)
    │   ├─ INSERT/UPDATE analytics_sessions
    │   ├─ INSERT analytics_pageviews (page_url, page_type, album_id, ...)
    │   └─ Aggiorna last_activity, page_views count
    └─ Response 200 OK
    ↓
Admin: Visualizza analytics
    ↓
GET /admin/analytics
    ├─ Query aggregate: sessioni giornaliere, top album, top paesi
    ├─ Render grafici (Chart.js)
    └─ Real-time: polling GET /api/admin/analytics/realtime
```

---

## Database Abstraction Layer

**app/Support/Database.php**:

```php
class Database
{
    public static function connect(array $config): PDO
    {
        $driver = $config['driver'] ?? 'sqlite';

        if ($driver === 'sqlite') {
            $pdo = new PDO("sqlite:{$config['database']}");
            $pdo->exec("PRAGMA foreign_keys = ON");
        } else { // mysql
            $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";
            $pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        return $pdo;
    }
}
```

**Nota**: Le query SQL devono essere scritte in modo compatibile con entrambi i driver (SQLite e MySQL). Differenze comuni:
- `AUTO_INCREMENT` (MySQL) vs `AUTOINCREMENT` (SQLite)
- `NOW()` (MySQL) vs `datetime('now')` (SQLite)
- `LIMIT offset, count` vs `LIMIT count OFFSET offset`

---

## Frontend Architecture

### Build Process (Vite)

**vite.config.js**:
```javascript
export default {
  build: {
    outDir: 'public/assets',
    manifest: true,
    rollupOptions: {
      input: {
        app: 'resources/app.css',
        admin: 'resources/admin.js'
      }
    }
  }
}
```

**Output**:
- `public/assets/app-[hash].css` (Tailwind compilato)
- `public/assets/admin-[hash].js` (Admin JS bundle)
- `public/assets/manifest.json` (mapping filename → hashed)

### CSS Architecture (Tailwind)

```css
/* resources/app.css */
@tailwind base;
@tailwind components;
@tailwind utilities;

/* Custom component classes */
@layer components {
  .btn-primary {
    @apply px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700;
  }
}

/* Custom animations */
@layer utilities {
  .fade-in {
    animation: fadeIn 0.5s ease-in-out;
  }
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}
```

**Tailwind config** (`tailwind.config.js`):
```javascript
module.exports = {
  content: [
    './app/Views/**/*.twig',
    './resources/**/*.js'
  ],
  theme: {
    extend: {
      colors: {
        primary: '#...',
      }
    }
  }
}
```

### JavaScript Modules

**Entry point** (`resources/admin.js`):
```javascript
// Import global libraries
import 'chart.js';
import 'uppy';

// Import page-specific modules
import './js/analytics.js';
import './js/album-reorder.js';
```

**Module esempio** (`resources/js/home-gallery.js`):
```javascript
// ES Module
export class HomeGallery {
  constructor(element) {
    this.element = element;
    this.init();
  }

  init() {
    this.setupDrag();
    this.setupHoverPause();
    this.revealItems();
  }

  // ... metodi
}

// Auto-init se elemento presente
if (document.getElementById('home-infinite-gallery')) {
  new HomeGallery(document.getElementById('home-infinite-gallery'));
}
```

---

## Convenzioni di Codice

### PHP (PSR-12)

```php
<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AlbumsController extends BaseController
{
    public function __construct(
        private PDO $db,
        private SettingsService $settings
    ) {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        $albums = $this->db->query("SELECT * FROM albums")->fetchAll();

        return $this->render($response, 'admin/albums/index.twig', [
            'albums' => $albums,
        ]);
    }
}
```

**Regole**:
- `declare(strict_types=1);` SEMPRE
- Type hints su parametri, return types, proprietà
- Naming: PascalCase (classi), camelCase (metodi/variabili)
- Visibilità esplicita (`public`, `private`, `protected`)
- Constructor property promotion (PHP 8.0+)

### JavaScript (ES6+)

```javascript
// Usa const/let (no var)
const element = document.getElementById('gallery');

// Arrow functions
const filterAlbums = (albums, category) => {
  return albums.filter(a => a.category_id === category);
};

// Destructuring
const { title, slug } = album;

// Template literals
const html = `<h1>${title}</h1>`;

// Async/await
async function fetchAlbums() {
  const response = await fetch('/api/albums');
  const data = await response.json();
  return data;
}
```

### SQL

```sql
-- Prepared statements SEMPRE (no concatenazione stringhe)
-- ✅ Corretto
$stmt = $db->prepare("SELECT * FROM albums WHERE id = ?");
$stmt->execute([$id]);

-- ❌ MAI fare questo (SQL injection)
$db->query("SELECT * FROM albums WHERE id = $id");

-- Naming: snake_case (tabelle, colonne)
-- Indici su colonne frequently queried
CREATE INDEX idx_albums_slug ON albums(slug);
CREATE INDEX idx_images_album_id ON images(album_id);
```

---

## Performance Considerations

### Database

1. **Indici**: Creati su `slug`, `*_id`, `published_at`, `sort_order`
2. **Prepared Statements**: Reduce parsing overhead, prevent SQL injection
3. **Connection Pooling**: Riutilizzo connessione PDO (singleton in container)
4. **Query Optimization**: Evitare N+1 queries con JOIN
5. **Pagination**: LIMIT su query lunghe

### Immagini

1. **Lazy Loading**: `loading="lazy"` su tutte le img
2. **Responsive Images**: `srcset` + `sizes` per servire dimensione corretta
3. **Modern Formats**: AVIF (primario), WebP (fallback), JPEG (ultimate)
4. **CDN-ready**: Hash immutabili in filename (`{hash}_{variant}_{format}.ext`)
5. **HTTP Caching**: `.htaccess` cache headers (1 anno su media)

### Frontend

1. **Code Splitting**: Vite automatic (dynamic import)
2. **Minification**: Vite build minify JS/CSS
3. **Tree Shaking**: Tailwind purge unused CSS
4. **Asset Hashing**: Cache busting automatico
5. **Critical CSS**: Inline above-the-fold (future)

---

## Testing Strategy

### Unit Tests (PHPUnit)

```php
// tests/Services/SettingsServiceTest.php
class SettingsServiceTest extends TestCase
{
    public function testGetReturnsDefaultWhenKeyNotFound()
    {
        $service = new SettingsService($this->mockDb());
        $this->assertEquals('default', $service->get('nonexistent', 'default'));
    }

    public function testSetCreatesNewSetting()
    {
        $service = new SettingsService($this->db);
        $service->set('test.key', 'value');
        $this->assertEquals('value', $service->get('test.key'));
    }
}
```

**Esegui**: `vendor/bin/phpunit`

### Integration Tests

```php
// Test full request cycle
class AlbumControllerTest extends TestCase
{
    public function testShowRendersAlbumPage()
    {
        $response = $this->get('/album/my-album');
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('My Album', (string) $response->getBody());
    }
}
```

### Frontend Tests (Future)

- Vitest per unit test JS
- Playwright per E2E

---

## Logging e Debug

### Development Mode

`.env`:
```env
APP_ENV=development
APP_DEBUG=true
```

- Error reporting: E_ALL
- Display errors: On
- Detailed stack traces
- No cache

### Production Mode

`.env`:
```env
APP_ENV=production
APP_DEBUG=false
```

- Error reporting: E_ALL & ~E_DEPRECATED & ~E_STRICT
- Display errors: Off
- Logged to file (configurabile)
- Cache abilitato

### Logging

```php
// Usa error_log() per logging
error_log("Album created: {$album['id']}");

// Future: Monolog integration
$logger->info('Album created', ['album_id' => $album['id']]);
```

---

## Extensibility

### Custom Middleware

```php
// app/Middlewares/CustomMiddleware.php
class CustomMiddleware
{
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // Pre-processing
        $request = $request->withAttribute('custom', 'value');

        // Passa alla prossima middleware
        $response = $handler->handle($request);

        // Post-processing
        return $response->withHeader('X-Custom', 'Header');
    }
}

// Registra in routes.php
$app->add(new CustomMiddleware());
```

### Custom Commands

```php
// app/Tasks/CustomCommand.php
class CustomCommand extends Command
{
    protected static $defaultName = 'custom:command';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Executing custom command...');
        // ... logica
        return Command::SUCCESS;
    }
}

// Registra in bin/console
$application->add(new CustomCommand());
```

### Custom Twig Functions

```php
// app/Extensions/CustomTwigExtension.php
class CustomTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('custom_function', [$this, 'customFunction']),
        ];
    }

    public function customFunction(string $input): string
    {
        return strtoupper($input);
    }
}

// Registra in bootstrap.php
$twig->addExtension(new CustomTwigExtension());
```

---

## Prossimi Passi

Consulta le altre sezioni della documentazione tecnica:

- **[Schema Database](./database.md)**: Dettaglio completo tabelle e relazioni
- **[API e Endpoint](./api.md)**: Riferimento completo API
- **[Sicurezza](./security.md)**: Best practices e implementazioni
- **[Guida Sviluppo](./development.md)**: Setup ambiente e workflow
- **[Deployment](./deployment.md)**: Installazione e messa in produzione

---

**Ultimo aggiornamento**: 17 Novembre 2025
**Versione**: 1.0.0
