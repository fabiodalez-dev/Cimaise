# Guida Sviluppo - Cimaise

## Setup Ambiente di Sviluppo

### Requisiti

- **PHP**: 8.2+ con estensioni:
  - `pdo_mysql` o `pdo_sqlite`
  - `gd` o `imagick` (image processing)
  - `exif` (metadata extraction)
  - `mbstring`, `fileinfo`, `zip`
- **Composer**: 2.x
- **Node.js**: 18+ con npm/pnpm
- **Database**: MySQL 8.0+ o SQLite 3

**Optional**:
- **Docker**: Per ambiente isolato
- **Git**: Version control

---

## Installazione Locale

### 1. Clone Repository

```bash
git clone https://github.com/yourusername/Cimaise.git
cd Cimaise
```

### 2. Install Dependencies

```bash
# PHP dependencies
composer install

# JavaScript dependencies
npm install
# o
pnpm install
```

### 3. Environment Setup

```bash
cp .env.example .env
```

Modifica `.env`:
```env
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite

SESSION_SECRET=your-random-secret-key-here
```

**Genera secret**:
```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

### 4. Database Setup

**SQLite** (default, zero config):
```bash
touch database/database.sqlite
```

**MySQL** (setup manuale o Docker):
```bash
# Docker
docker run --name cimaise-mysql \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=cimaise \
  -p 3306:3306 -d mysql:8

# Update .env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cimaise
DB_USERNAME=root
DB_PASSWORD=root
```

### 5. Initialize Application

```bash
# Esegui migrazioni + seed dati demo + crea admin user
php bin/console init

# O manualmente:
php bin/console migrate
php bin/console seed
php bin/console user:create
```

**Admin Credentials** (dopo init):
- Email: `admin@cimaise.local`
- Password: `admin` (CAMBIARE SUBITO)

### 6. Build Frontend Assets

```bash
# Development (watch mode)
npm run dev

# Production build
npm run build
```

### 7. Start Development Server

```bash
# PHP built-in server
php -S 127.0.0.1:8000 -t public

# Accedi a: http://localhost:8000
```

**Alternative**:
- **Valet** (macOS)
- **Laragon** (Windows)
- **Docker Compose** (multi-OS)

---

## Struttura Comandi CLI

**File**: `bin/console` (Symfony Console)

### Comandi Disponibili

```bash
# Mostra tutti i comandi
php bin/console list

# Database
php bin/console migrate              # Esegui migrazioni
php bin/console seed                 # Seed dati demo
php bin/console init                 # migrate + seed + create user + sitemap
php bin/console db:test              # Test connessione DB

# Images
php bin/console images:generate              # Genera tutte le varianti
php bin/console images:generate --missing    # Solo varianti mancanti
php bin/console images:generate --limit 10   # Limita a 10 immagini

# Analytics
php bin/console analytics:cleanup --days 90  # Elimina dati > 90 giorni
php bin/console analytics:summarize          # Pre-compute daily summaries

# SEO
php bin/console sitemap:generate     # Genera sitemap.xml

# Users
php bin/console user:create          # Crea nuovo admin user
php bin/console user:update          # Aggiorna user esistente

# Diagnostics
php bin/console diagnostics          # System health check
```

---

## Workflow Sviluppo

### 1. Feature Development

```bash
# Crea feature branch
git checkout -b feature/my-feature

# Sviluppo...

# Test locally
npm run build
php -S 127.0.0.1:8000 -t public

# Commit
git add .
git commit -m "Add my feature"

# Push
git push origin feature/my-feature

# Open PR
```

### 2. Database Changes

**Creare Migration**:

```bash
# Crea file: database/migrations/sqlite/0020_my_feature.sql
```

**Esempio**:
```sql
-- 0020_add_album_views_count.sql
ALTER TABLE albums ADD COLUMN views_count INTEGER DEFAULT 0;
CREATE INDEX idx_albums_views ON albums(views_count);
```

**Eseguire**:
```bash
php bin/console migrate
```

**Nota**: Crea sia versione SQLite che MySQL.

### 3. Frontend Changes

**Vite HMR** (Hot Module Reload):
```bash
npm run dev
```

**Files**:
- `resources/app.css` - Tailwind CSS
- `resources/js/*.js` - JavaScript modules
- `resources/admin.js` - Admin entry point

**Build**:
```bash
npm run build
# Output: public/assets/
```

### 4. Testing

**PHPUnit**:
```bash
# Run all tests
vendor/bin/phpunit

# Run specific test
vendor/bin/phpunit --filter testAlbumCreation

# Coverage
vendor/bin/phpunit --coverage-html coverage/
```

**Frontend** (future):
```bash
# Vitest
npm run test

# E2E (Playwright)
npm run test:e2e
```

---

## Coding Standards

### PHP (PSR-12)

**PhpCS Fixer**:
```bash
composer require friendsofphp/php-cs-fixer --dev

# Fix automaticamente
vendor/bin/php-cs-fixer fix app/
```

**Regole**:
- `declare(strict_types=1);` SEMPRE
- Type hints su tutto (params, returns, properties)
- Visibilità esplicita (`public`, `private`, `protected`)
- No `var` keyword
- PascalCase classi, camelCase metodi/variabili

### JavaScript (ESLint)

**.eslintrc.json**:
```json
{
  "extends": "eslint:recommended",
  "env": {
    "browser": true,
    "es2021": true
  },
  "rules": {
    "no-var": "error",
    "prefer-const": "error",
    "semi": ["error", "always"]
  }
}
```

```bash
npm run lint
npm run lint:fix
```

### Tailwind CSS

**Config**: `tailwind.config.js`

**Purge**: Automatico su `npm run build`

**Custom Classes**:
```css
@layer components {
  .btn-primary {
    @apply px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700;
  }
}
```

---

## Debug

### PHP

**Xdebug** (optional):
```ini
; php.ini
zend_extension=xdebug
xdebug.mode=debug
xdebug.start_with_request=yes
```

**VS Code** `launch.json`:
```json
{
  "version": "0.2.0",
  "configurations": [
    {
      "name": "Listen for Xdebug",
      "type": "php",
      "request": "launch",
      "port": 9003
    }
  ]
}
```

**Alternative (semplice)**:
```php
// Controller
var_dump($data);
die();

// Logs
error_log("Debug: " . json_encode($data));
```

### JavaScript

**Browser DevTools**:
```javascript
console.log('Debug:', data);
console.table(arrayData);
debugger;  // Breakpoint
```

**Vue DevTools** (if Vue used future)

---

## Database Seeding

### Seeders Location

`database/seeds/demo_data.sql`

### Custom Seed Data

**Modificare seed**:
```sql
-- Aggiungi custom albums
INSERT INTO albums (title, slug, category_id, excerpt, is_published) VALUES
('My Test Album', 'my-test-album', 1, 'Test data', 1);

-- Aggiungi images
INSERT INTO images (album_id, original_path, file_hash, width, height, mime) VALUES
(1, 'test.jpg', 'abc123', 1920, 1080, 'image/jpeg');
```

**Eseguire**:
```bash
php bin/console seed
```

---

## Estendere l'Applicazione

### Aggiungere Nuovo Controller

**1. Crea file**: `app/Controllers/Admin/MyController.php`

```php
<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class MyController extends BaseController
{
    public function __construct(
        private \PDO $db,
        private \Slim\Views\Twig $view
    ) {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        $data = $this->db->query("SELECT * FROM my_table")->fetchAll();

        return $this->render($response, 'admin/my/index.twig', [
            'data' => $data
        ]);
    }
}
```

**2. Registra route**: `app/Config/routes.php`

```php
$app->get('/admin/my-feature', function (Request $request, Response $response) use ($container) {
    $controller = new \App\Controllers\Admin\MyController(
        $container['db'],
        Twig::fromRequest($request)
    );
    return $controller->index($request, $response);
})->add($container['db'] ? new AuthMiddleware($container['db']) : /* noop */);
```

**3. Crea template**: `app/Views/admin/my/index.twig`

```twig
{% extends 'layouts/admin.twig' %}

{% block content %}
<h1>My Feature</h1>
<table>
  {% for item in data %}
    <tr><td>{{ item.name }}</td></tr>
  {% endfor %}
</table>
{% endblock %}
```

### Aggiungere Service

**1. Crea**: `app/Services/MyService.php`

```php
<?php
declare(strict_types=1);

namespace App\Services;

class MyService
{
    public function __construct(private \PDO $db) {}

    public function doSomething(): array
    {
        return $this->db->query("SELECT ...")->fetchAll();
    }
}
```

**2. Registra in DI Container**: `app/Config/bootstrap.php`

```php
$container->set(MyService::class, function ($c) {
    return new MyService($c->get('db'));
});
```

**3. Usa nel Controller**:

```php
public function __construct(
    private MyService $myService
) {}
```

### Aggiungere Middleware

**1. Crea**: `app/Middlewares/MyMiddleware.php`

```php
<?php
declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class MyMiddleware
{
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // Pre-processing
        $request = $request->withAttribute('my_data', 'value');

        // Execute next middleware/controller
        $response = $handler->handle($request);

        // Post-processing
        return $response->withHeader('X-My-Header', 'value');
    }
}
```

**2. Registra**:

```php
// Globale (tutte le route)
$app->add(new MyMiddleware());

// Specifica route
$app->get('/path', $handler)->add(new MyMiddleware());

// Route group
$app->group('/admin', function ($group) {
    // routes...
})->add(new MyMiddleware());
```

---

## Performance Optimization

### Opcode Cache (OPcache)

```ini
; php.ini (production)
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0  ; Disable in production
```

### Database Query Optimization

**Explain Query**:
```sql
EXPLAIN SELECT * FROM albums WHERE category_id = 1;
```

**Indici**:
```sql
CREATE INDEX idx_albums_category_published ON albums(category_id, is_published);
```

### Frontend

**Vite Build Optimization**:
```javascript
// vite.config.js
export default {
  build: {
    minify: 'terser',
    cssCodeSplit: true,
    rollupOptions: {
      output: {
        manualChunks: {
          vendor: ['gsap', 'lenis']
        }
      }
    }
  }
}
```

---

## Troubleshooting

### Database Connection Failed

```bash
# Verifica connessione
php bin/console db:test

# Check .env
cat .env | grep DB_

# SQLite: check permessi
ls -la database/database.sqlite
chmod 664 database/database.sqlite
```

### Images Not Generating

```bash
# Check GD/Imagick
php -m | grep -E 'gd|imagick'

# Check storage permessi
ls -la storage/originals/
chmod -R 755 storage/

# Run manualmente
php bin/console images:generate --missing
```

### 500 Error

```bash
# Check logs
tail -f storage/logs/error.log

# Enable debug
# .env: APP_DEBUG=true

# Check PHP error log
tail -f /var/log/php_errors.log
```

### Vite Build Failed

```bash
# Clear cache
rm -rf node_modules/.vite

# Reinstall
rm -rf node_modules package-lock.json
npm install

# Check Node version
node --version  # >= 18
```

---

## Git Workflow

### Branches

- `main` - Production-ready
- `develop` - Development branch
- `feature/*` - Feature branches
- `hotfix/*` - Emergency fixes

### Commit Messages

```
feat: Add album sorting feature
fix: Resolve image upload validation bug
docs: Update API documentation
refactor: Optimize database queries
test: Add unit tests for SettingsService
chore: Update dependencies
```

**Prefix**:
- `feat:` New feature
- `fix:` Bug fix
- `docs:` Documentation
- `refactor:` Code refactoring
- `test:` Tests
- `chore:` Maintenance

---

## CI/CD (GitHub Actions Example)

**.github/workflows/tests.yml**:
```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: pdo_sqlite, gd, exif

      - name: Install dependencies
        run: composer install

      - name: Run tests
        run: vendor/bin/phpunit

      - name: Security audit
        run: composer audit
```

---

## Risorse Utili

- **Slim Framework**: https://www.slimframework.com/docs/v4/
- **Twig**: https://twig.symfony.com/doc/3.x/
- **Tailwind CSS**: https://tailwindcss.com/docs
- **GSAP**: https://gsap.com/docs/v3/
- **Vite**: https://vitejs.dev/guide/

---

**Ultimo aggiornamento**: 17 Novembre 2025
**Versione**: 1.0.0
