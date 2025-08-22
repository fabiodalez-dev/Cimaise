# Repository Guidelines

This repository hosts a minimalist, high‑performance photography CMS built with PHP 8.2, Slim 4, Twig, MySQL/SQLite, Vite, Tailwind, and GSAP. Follow the conventions below to keep the codebase fast, secure, and consistent.

## Project Structure & Module Organization
- app/Config, Controllers, Views (Twig), Repositories, Services, Middlewares, Tasks, Support
- public/ (entry: public/index.php, built assets in public/assets, media in public/media)
- storage/ (originals/, tmp/) — never serve directly
- tests/ (PHPUnit for Services/Repos; snapshot tests for Twig)
- Build assets via Vite; Twig handles SSR; Minimal API for filters/AJAX

## Build, Test, and Development Commands
- composer install — install PHP deps; npm install — install frontend deps
- npm run dev — Vite dev server (assets); npm run build — production build
- php -S 127.0.0.1:8000 -t public — local PHP server
- composer test — run PHPUnit; composer audit && npm audit — security audits
- php bin/console images:generate [--missing] — build AVIF/WebP/JPEG variants

### Database: MySQL e SQLite (compatibile)
- Stato attuale: sviluppo con SQLite (vedi `.env`), supporto pieno anche a MySQL.
- Bootstrap: `app/Config/bootstrap.php` sceglie il driver in base a `DB_CONNECTION` (`sqlite`|`mysql`).
- Migrazioni: `php bin/console db:migrate` seleziona automaticamente gli script MySQL (`database/migrations/*.sql`) o SQLite (`database/migrations/sqlite/*.sql`).
- Seed: `php bin/console db:seed` (dati minimi demo); test connessione: `php bin/console db:test`.
- Config `.env` esempio:
  - SQLite
    - `DB_CONNECTION=sqlite`
    - `DB_DATABASE=/percorso/assoluto/alla/storage/database.sqlite`
  - MySQL
    - `DB_CONNECTION=mysql`
    - `DB_HOST=127.0.0.1` `DB_PORT=3306` `DB_DATABASE=photocms` `DB_USERNAME=...` `DB_PASSWORD=...`
- Portabilità SQL (linee guida):
  - Evitare funzioni specifiche MySQL; preferire SQL neutro. Esempio: concatenazione stringhe — MySQL usa `CONCAT(a,' ',b)`, SQLite usa `a || ' ' || b`. Nel codice pubblico sono presenti alcuni `CONCAT(...)` da sostituire con una strategia compatibile (TODO).
  - Tipi `ENUM/JSON`: in SQLite sono gestiti come `TEXT`; trattare i JSON sempre via `json_encode/json_decode` lato PHP.
  - Date/ora: in SQLite salvate come stringhe ISO; in MySQL usare `DATETIME`. Normalizzare sempre il formato `Y-m-d H:i:s` lato app.
  - `REPLACE INTO` è supportato da entrambi i driver (usato per `image_variants`).

Nota: l’ambiente locale attuale usa SQLite (vedi `.env`), obiettivo è mantenere piena compatibilità con entrambe le soluzioni senza ramificare la logica applicativa.

## Coding Style & Naming Conventions
- PHP: strict_types=1, PSR‑12, typed properties/params/returns, PDO prepared statements only
- Twig auto‑escaping on; sanitize rich content (whitelist) before render
- Folders: PascalCase for classes in app/, snake_case for DB columns, kebab-case for assets
- Environment via .env (provide .env.example; never commit secrets)

## Testing Guidelines
- Framework: PHPUnit; place tests under tests/ mirroring app/ structure
- Name tests as ClassNameTest.php; cover queries, services, image pipeline
- Add snapshot tests for critical Twig templates; keep tests deterministic

## Commit & Pull Request Guidelines
- Conventional Commits + Task ID: e.g., feat(albums): add CRUD [TASK-004]
- Branches: feat/<slug>, fix/<slug>, chore/<slug>, docs/<slug>
- PRs include: SUMMARY, COMMANDS, CHANGED FILES, PLAN.MD UPDATE; note risks/migrations and rollback

## Security & Configuration Tips
- Argon2id passwords, CSRF on mutating routes, secure session cookies
- Enforce MIME checks on uploads; no executable code in /uploads or /public/media
- Set CSP, nosniff, strict-origin-when-cross-origin, minimal Permissions‑Policy

## Agent‑Specific Instructions
- Maintain plan.md with Backlog/Doing/Done/Log; reference TASK‑### in commits/PRs
- Prefer SSR, minimal JS, and indexed queries; prioritize performance and SEO

# Portfolio CMS — Specifica Tecnica (PHP/MySQL, minimalista B/N)

> Versione: 1.0 — Autore: Fabio (con il mio aiuto). Obiettivo: CMS veloce, moderno, SEO‑friendly, con backend pulito per **album/progetti**, **categorie**, **tag** e **metadati fotografici** (fotocamera, lente, pellicola, sviluppo, laboratorio, scanner, ecc.). Frontend dinamico (GSAP), filtri AJAX, immagini ottimizzate (AVIF/WebP/JPEG). Niente barocchismi.

---

## 1) Stack & Principi

- **Linguaggi**: PHP 8.2+, MySQL 8 (o MariaDB ≥10.6), JavaScript ES Modules.
- **Framework**: Slim 4 (router/middlewares), Twig (SSR + template), PDO (query tipizzate), Symfony Console (task CLI).
- **Build front‑end**: Vite (JS/TS opzionale), Tailwind CSS (admin + componenti base).
- **Librerie front**: GSAP (+ ScrollTrigger), PhotoSwipe (lightbox), Shuffle.js (griglia e filtri), lazysizes (lazyload), imagesLoaded (quando serve), Barba.js (facoltativa per transizioni pagina), Lenis (smooth scroll, opzionale).
- **Upload**: Uppy (drag&drop, progress, chunking opzionale).
- **Immagini**: Intervention Image (GD/Imagick) o Glide/php‑vips. **Default**: Intervention + varianti generate.
- **Cache**: Redis (opzionale ma consigliato) per query/fragment; HTTP cache per media.
- **Stile**: tema **bianco/nero**, tipografia pulita, spazio negativo generoso, focus sulle immagini.
- **SEO**: SSR, URL semantici, JSON‑LD, Sitemap, Open Graph, alt obbligatori.
- **Sicurezza**: sessioni server‑side, CSRF, validazioni server, upload hardening, rate limiting login.

> Filosofia: SSR per SEO e time‑to‑first‑paint rapido; API leggere **solo** per filtri/griglie. Template semplici, moduli separati, niente mega‑framework MVC verbosi.

---

## 2) Struttura del progetto

```
/app
  /Config (env.php, routes.php)
  /Controllers (Frontend, Admin, API)
  /Views (Twig: frontend/..., admin/...)
  /Repositories (AlbumRepo, ImageRepo, TaxonomyRepo, MetaRepo)
  /Services (ImagesService, UploadService, AuthService, CacheService, ExifService)
  /Middlewares (Auth, Csrf, Cache, JsonBody)
  /Tasks (Symfony Console: generate:thumbs, import, sitemap)
  /Support (helpers, validators)
/public
  index.php
  /assets (bundled da Vite)
  /media   (varianti generate — cache lunga)
  /uploads (solo placeholder/symlink — nessun PHP eseguibile)
/storage
  /originals (master normalizzati)
  /tmp       (temporanei upload/chunk)
/docker (config nginx/php-fpm) — opzionale
/vendor
```

---

## 3) Modello Dati (MySQL)

### 3.1 Tabelle base

```sql
-- users
CREATE TABLE users (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(190) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin') DEFAULT 'admin',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- categories (1:N con albums)
CREATE TABLE categories (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(140) NOT NULL,
  slug VARCHAR(160) UNIQUE NOT NULL,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(slug), INDEX(sort_order)
) ENGINE=InnoDB;

-- tags (N:M)
CREATE TABLE tags (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(140) NOT NULL,
  slug VARCHAR(160) UNIQUE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX(slug)
) ENGINE=InnoDB;

-- albums (progetti)
CREATE TABLE albums (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  title VARCHAR(200) NOT NULL,
  slug VARCHAR(220) UNIQUE NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  excerpt TEXT NULL,
  body MEDIUMTEXT NULL,
  cover_image_id BIGINT UNSIGNED NULL,
  shoot_date DATE NULL,                -- data dello shooting
  published_at DATETIME NULL,
  is_published TINYINT(1) DEFAULT 0,
  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
  INDEX(slug), INDEX(category_id), INDEX(is_published), INDEX(published_at), INDEX(sort_order)
) ENGINE=InnoDB;

-- album <-> tags
CREATE TABLE album_tag (
  album_id BIGINT UNSIGNED NOT NULL,
  tag_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY(album_id, tag_id),
  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

### 3.2 Tassonomia e metadati fotografici (analogico/digitale)

Lookup riutilizzabili (evitano stringhe duplicate). Tutti opzionali; puoi sempre valorizzare campi “custom_*” nell’immagine se vuoi disaccoppiare da lookup.

```sql
-- camere
CREATE TABLE cameras (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  make VARCHAR(120) NOT NULL,     -- es. Nikon, Canon, Leica
  model VARCHAR(160) NOT NULL,    -- es. F3, M6, 5D Mark IV
  UNIQUE KEY (make, model)
) ENGINE=InnoDB;

-- lenti
CREATE TABLE lenses (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  brand VARCHAR(120) NOT NULL,
  model VARCHAR(160) NOT NULL,    -- es. 50mm f/1.4
  focal_min DECIMAL(6,2) NULL,    -- 35.00
  focal_max DECIMAL(6,2) NULL,
  aperture_min DECIMAL(4,2) NULL, -- 1.40
  UNIQUE KEY (brand, model)
) ENGINE=InnoDB;

-- pellicole
CREATE TABLE films (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  brand VARCHAR(120) NOT NULL,    -- es. Kodak, Ilford
  name VARCHAR(160) NOT NULL,     -- es. Portra 400, HP5+
  iso INT NULL,                   -- 100, 400, 800...
  format ENUM('35mm','120','4x5','8x10','other') DEFAULT '35mm',
  type ENUM('color_negative','color_reversal','bw') NOT NULL, -- C-41, E-6, B/W
  UNIQUE KEY (brand, name, iso, format)
) ENGINE=InnoDB;

-- sviluppi / chimiche
CREATE TABLE developers (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,           -- es. Rodinal, D-76, Cinestill C-41
  process ENUM('C-41','E-6','BW','Hybrid','Other') DEFAULT 'BW',
  notes VARCHAR(255) NULL,
  UNIQUE KEY (name, process)
) ENGINE=InnoDB;

-- laboratori di sviluppo/scansione
CREATE TABLE labs (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  city VARCHAR(120) NULL,
  country VARCHAR(120) NULL,
  UNIQUE KEY (name, city)
) ENGINE=InnoDB;
```

### 3.3 Immagini con metadati estesi

```sql
-- immagini singole (legate a un album/progetto)
CREATE TABLE images (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  album_id BIGINT UNSIGNED NOT NULL,

  -- file
  original_path VARCHAR(255) NOT NULL,  -- /storage/originals/sha1.ext
  file_hash CHAR(40) NOT NULL,
  width INT NOT NULL, height INT NOT NULL,
  mime VARCHAR(60) NOT NULL,

  -- testo/accessibilità
  alt_text VARCHAR(200) NULL,
  caption VARCHAR(300) NULL,

  -- EXIF grezzi (digital) o dati scannerizzati
  exif JSON NULL,

  -- metadati fotografici strutturati
  camera_id BIGINT UNSIGNED NULL,
  lens_id BIGINT UNSIGNED NULL,
  film_id BIGINT UNSIGNED NULL,
  developer_id BIGINT UNSIGNED NULL,
  lab_id BIGINT UNSIGNED NULL,

  -- override liberi (se non vuoi usare lookup)
  custom_camera VARCHAR(160) NULL,
  custom_lens VARCHAR(160) NULL,
  custom_film VARCHAR(160) NULL,        -- es. "Portra 400 120"
  custom_development VARCHAR(160) NULL, -- es. "C-41 @ 38°C, 3:15"
  custom_lab VARCHAR(160) NULL,
  custom_scanner VARCHAR(160) NULL,     -- es. Noritsu HS-1800
  scan_resolution_dpi INT NULL,         -- 2400, 3200...
  scan_bit_depth INT NULL,              -- 8, 16...
  process ENUM('digital','analog','hybrid') DEFAULT 'digital',
  development_date DATE NULL,

  iso INT NULL,                         -- ISO effettivo (push/pull)
  shutter_speed VARCHAR(40) NULL,       -- "1/125", "1s"
  aperture DECIMAL(4,2) NULL,           -- 1.40, 5.60

  sort_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  FOREIGN KEY (camera_id) REFERENCES cameras(id) ON DELETE SET NULL,
  FOREIGN KEY (lens_id) REFERENCES lenses(id) ON DELETE SET NULL,
  FOREIGN KEY (film_id) REFERENCES films(id) ON DELETE SET NULL,
  FOREIGN KEY (developer_id) REFERENCES developers(id) ON DELETE SET NULL,
  FOREIGN KEY (lab_id) REFERENCES labs(id) ON DELETE SET NULL,

  INDEX(album_id), INDEX(sort_order), INDEX(file_hash),
  INDEX(camera_id), INDEX(lens_id), INDEX(film_id),
  INDEX(developer_id), INDEX(lab_id), INDEX(process), INDEX(iso)
) ENGINE=InnoDB;

-- varianti generate (AVIF/WebP/JPEG per breakpoints)
CREATE TABLE image_variants (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  image_id BIGINT UNSIGNED NOT NULL,
  variant VARCHAR(50) NOT NULL,         -- xs, sm, md, lg, xl
  format ENUM('avif','webp','jpg') NOT NULL,
  path VARCHAR(255) NOT NULL,           -- /public/media/..
  width INT NOT NULL, height INT NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  UNIQUE KEY uniq (image_id, variant, format),
  FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```
> Indicizzazione dove filtri/ordini: `process`, `film_id`, `camera_id`, `developer_id`, `lab_id`, `iso`, `shoot_date` (album) e `published_at`.

---

## 4) Rotte

### 4.1 Frontend (SSR, implementato)
- `GET /` — Home: ultimi album pubblicati con categorie e tag popolari.
- `GET /album/{slug}` — Scheda album: testo + gallery (variants) + metadati.
- `GET /category/{slug}` — Listing per categoria.
- `GET /tag/{slug}` — Listing per tag.

### 4.2 API Pubbliche (AJAX filtri)
- `GET /api/albums`
  - Query: `category, tags (csv), process, camera, film, page (1..), per_page (<=50), sort (published_desc|published_asc|shoot_date_desc|shoot_date_asc|title_asc|title_desc)`
  - Risposta: JSON `{ itemsHtml, pagination { page, per_page, total, pages, has_next, has_prev }, filters }`
- `GET /api/album/{id}/images`
  - Query: `process, camera, film, lens, page (1..), per_page (<=100)`
  - Risposta: JSON `{ itemsHtml, pagination {...}, album, filters }`

### 4.3 Admin (SSR + azioni)
- Auth: `GET /admin/login`, `POST /admin/login`, `GET /admin/logout`, redirect helper `GET /admin-login`.
- Dashboard: `GET /admin`.
- Upload: `POST /admin/albums/{id}/upload` (Uppy; richiede auth/CSRF).
- Albums CRUD: 
  - `GET /admin/albums`, `GET /admin/albums/create`, `POST /admin/albums`, 
  - `GET /admin/albums/{id}/edit`, `POST /admin/albums/{id}` (update), 
  - `POST /admin/albums/{id}/delete`, `POST /admin/albums/{id}/publish`, `POST /admin/albums/{id}/unpublish`.
  - Azioni immagini/tag: `POST /admin/albums/{id}/cover/{imageId}`, `POST /admin/albums/{id}/images/reorder`, `POST /admin/albums/{id}/tags`.
- Categories CRUD: `GET/POST /admin/categories`, `GET /admin/categories/create`, `GET /admin/categories/{id}/edit`, `POST /admin/categories/{id}`, `POST /admin/categories/{id}/delete`.
- Tags CRUD: `GET/POST /admin/tags`, `GET /admin/tags/create`, `GET /admin/tags/{id}/edit`, `POST /admin/tags/{id}`, `POST /admin/tags/{id}/delete`.
- Lookups CRUD: `cameras`, `lenses`, `films`, `developers`, `labs` con le stesse rotte `GET index/create/edit`, `POST store/update/delete` (prefisso `/admin/{lookup}`...).
- Admin API helper: `GET /admin/api/tags?q=` (autocomplete/tagging).
- Settings: `GET /admin/settings`, `POST /admin/settings`.
- Diagnostics: `GET /admin/diagnostics`.
- Commands UI: `GET /admin/commands`, `POST /admin/commands/execute`.

---

## 5) Filtri & Ordinamenti (use cases)

- **Album listing**: categoria, multi‑tag, data (`published_at`/`shoot_date`), sort manuale/cronologico.
- **Galleria album**: filtro per **processo** (analog/digital/hybrid), **pellicola**, **camera**, **lente**, **lab**, **ISO**, testo pieno (caption).  
- **Ricerca globale** (admin): titolo album, slug, tag, metadati immagine.

Query d’esempio (pseudo‑repo):

```sql
-- Albums filtrati (categoria + tag)
SELECT a.*
FROM albums a
JOIN categories c ON c.id = a.category_id
LEFT JOIN album_tag at ON at.album_id = a.id
LEFT JOIN tags t ON t.id = at.tag_id
WHERE a.is_published = 1
  AND (:category IS NULL OR c.slug = :category)
  AND (:tag IS NULL OR t.slug = :tag)
GROUP BY a.id
ORDER BY a.published_at DESC, a.sort_order ASC
LIMIT :limit OFFSET :offset;
```

```sql
-- Immagini di un album filtrate per pellicola e processo
SELECT i.*
FROM images i
LEFT JOIN films f ON f.id = i.film_id
WHERE i.album_id = :album_id
  AND (:film IS NULL OR f.name = :film OR i.custom_film = :film)
  AND (:process IS NULL OR i.process = :process)
ORDER BY i.sort_order ASC, i.id ASC;
```

---

## 6) Pipeline immagini

1. **Upload** (Uppy → `/api/upload`): validazione MIME/size, calcolo `sha1`, normalizzazione master (orientamento EXIF, strip metadata sensibili), salvataggio in `/storage/originals/{sha1}.jpg` (o .tif se vuoi archivio, opzionale).
2. **Estrazione EXIF** (ExifService): acquisisci `Make/Model, Lens, ISO, Shutter, Aperture, FocalLength, DateTimeOriginal`. Mappa automaticamente a `cameras/lenses` se matchano (fuzzy).
3. **Varianti** (CLI `images:generate` o on‑demand + cache):
   - Breakpoints: `xs 480`, `sm 768`, `md 1024`, `lg 1536`, `xl 2048`.
   - Formati: AVIF(qualità ~45–55), WebP(70–75), JPEG(82–85).
   - LQIP blur (piccola 24–40px) inline come background.
4. **Output**: `<picture>` con `srcset/sizes`, `decoding="async"`, `loading="lazy"`, `fetchpriority="high"` per cover.

Snippet Twig:

```twig
<picture>
  <source type="image/avif" srcset="{{ variants.avif|join(', ') }}" sizes="(min-width:1024px) 50vw, 90vw">
  <source type="image/webp" srcset="{{ variants.webp|join(', ') }}" sizes="(min-width:1024px) 50vw, 90vw">
  <img src="{{ variants.jpg_default }}" alt="{{ img.alt_text|e }}"
       width="{{ img.width }}" height="{{ img.height }}"
       loading="lazy" decoding="async" class="rounded">
</picture>
```

---

## 7) Frontend (B/N + dinamico)

- **Griglia Masonry‑like** con Shuffle.js (no jQuery). Inserisci card SSR e rimpiazza via AJAX per filtri.
- **Animazioni GSAP** sobrie (fade/translate, reveal on scroll).  
- **PhotoSwipe** per lightbox (captions + EXIF essenziali nella sidebar).  
- **Menu** sticky che si riduce allo scroll (GSAP ScrollTrigger).  
- **Transizioni pagina** (opzionale) con Barba.js mantenendo SSR per SEO.

JS (filtri AJAX sintesi):

```js
import Shuffle from 'shufflejs';
import { gsap } from 'gsap';

const grid = document.querySelector('.grid');
const shuffle = new Shuffle(grid, { itemSelector: '.grid-item' });

const fetchAndRender = async (params) => {
  const q = new URLSearchParams(params);
  const res = await fetch(`/api/albums?${q.toString()}`, { headers: { 'Accept':'application/json' }});
  const data = await res.json();
  grid.innerHTML = data.itemsHtml;
  shuffle.resetItems();
  gsap.from('.grid-item', { opacity: 0, y: 24, duration: 0.35, stagger: 0.02 });
};

document.querySelectorAll('[data-filter]').forEach(el => {
  el.addEventListener('click', () => {
    fetchAndRender({ category: el.dataset.category || '', tags: el.dataset.tags || '' });
  });
});
```

---

## 8) Admin UX

- **Album Editor**: titolo, slug (auto), categoria, tag (multi con autocomplete), testo (Editor.js/TinyMCE), `shoot_date`, `published_at`, `sort_order`, scelta **cover**.
- **Gestione Immagini**: drag&drop (Uppy), anteprime, **ordinamento via drag**, alt/caption inline, selettori per **camera, lente, pellicola, sviluppo, laboratorio, scanner**, campi custom e dati di scatto (ISO, tempo, apertura). Bulk edit per campi comuni.
- **Lookup** (cameras, lenses, films, developers, labs): CRUD semplice per normalizzare.
- **Validazioni**: alt obbligatorio; avviso se EXIF mancano; coerenza processo/pellicola (es. E‑6 ↔ reversal).
- **Azioni**: Pubblica/Bozza, Duplica album, Rigenera varianti, Esporta JSON.

Endpoint upload (PHP – concetto):

```php
public function upload(ServerRequestInterface $req, ResponseInterface $res) {
  $this->auth->requireAdmin();
  $files = $req->getUploadedFiles()['files'] ?? [];
  foreach ($files as $file) {
    $meta = $this->uploadService->ingest($file); // salva master, EXIF, DB, crea record image
  }
  return $this->json($res, ['ok' => true]);
}
```

---

## Piano Frontend (incrementale)
- Base layout + Tailwind: impostare shell HTML, partials Twig (`_head`, `_nav`, `_footer`), tema B/N.
- Componenti SSR: `frontend/_album_card.twig`, `frontend/_picture.twig`, griglia home con categorie/tag.
- Pagina Album: gallery SSR con PhotoSwipe, EXIF/metadata sidebar, `<picture>` responsive (variants).
- Liste Categoria/Tag: pagine SSR con griglia riuso card; breadcrumb, titoli, meta.
- Filtri AJAX: integrazione `/api/albums` con Shuffle.js; transizioni GSAP sobrie al refresh items.
- Accessibilità/SEO: alt obbligatori, focus states, JSON‑LD (Home/Album), meta OG/Twitter.
- Performance: LQIP, lazyload, `fetchpriority` per cover, code‑split Vite minimo.

Nota: per piena compatibilità DB, evitare `CONCAT()` nei filtri API; introdurre helper DB o concatenazione lato PHP nelle prossime iterazioni.

---

## 9) SEO & Accessibilità

- Titoli univoci (`<title>`), H1/H2 coerenti, breadcrumbs.
- **JSON‑LD**: `ImageGallery` su album, `CollectionPage` su liste, `BreadcrumbList`.
- OG/Twitter Cards con cover (dimensioni 1200×630).  
- **ALT** sempre richiesto, caption facoltativa. Contrasto colori AA.  
- **Sitemap** giornaliera via CLI, `robots.txt` minimale.

Esempio JSON‑LD (album):

```html
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "ImageGallery",
  "name": "{{ album.title|e }}",
  "datePublished": "{{ album.published_at|date('c') }}",
  "about": "{{ album.excerpt|striptags|e }}",
  "image": [
    {% for img in album.images|slice(0,10) -%}
    "{{ absolute_url(img.variants.lg_jpg) }}"{% if not loop.last %},{% endif %}
    {%- endfor %}
  ]
}
</script>
```

---

## 10) Performance

- **HTTP Cache**: `Cache-Control: public, max-age=31536000, immutable` per `/media`.
- ETag/Last‑Modified su pagine SSR.  
- Preload font (subset), `rel=preconnect` ove utile.  
- Lazyload ovunque, LQIP.  
- Query indicizzate, paginazione server‑side.  
- Bundle JS snello (niente jQuery), Vite code‑splitting.

---

## 11) Sicurezza

- Upload hardening: MIME sniffing (finfo), whitelist estensioni, limiti size, random path, **disabilita esecuzione** in `/uploads` e `/media` (no PHP).  
- CSRF token su POST admin, session cookie `HttpOnly`, `SameSite=Lax`, HTTPS only.  
- Rate limit login, password Argon2id/Bcrypt, lockout progressivo.  
- Output escaping lato Twig abilitato.

---

## 12) Deployment

- **Docker Compose** consigliato: `nginx + php-fpm + mysql + redis`.
- `.env` per credenziali (DB, session secret).  
- Backup giornaliero: dump DB + `/storage`.  
- `composer install --no-dev`, `vite build`, migrazioni, generazione varianti.  
- CDN opzionale per `/media`.

---

## 13) Roadmap

1. Bootstrap progetto (Slim/Twig/Vite/Tailwind, Auth, CSRF).  
2. DB e migrazioni (tabelle base + lookup + immagini).  
3. Upload/EXIF/varianti (CLI).  
4. Admin CRUD (album, immagini, tassonomie, lookup).  
5. Frontend SSR (home, album, categoria, tag).  
6. Filtri AJAX + Shuffle.js + GSAP.  
7. SEO (OG, JSON‑LD, sitemap).  
8. Performance/Cache.  
9. QA (Lighthouse, a11y) e deploy.

---

## 14) Esempi pratici

### 14.1 Filtri API — richiesta/risposta

```
GET /api/albums?category=ritratti&tags=analogico,35mm&process=analog&film=Portra%20400&page=1&sort=published_desc
Accept: application/json
```

**Risposta**

```json
{
  "itemsHtml": "<div class=\"grid-item\">...cards SSR renderizzate server...</div>",
  "pagination": { "page": 1, "pages": 5, "total": 87 }
}
```

### 14.2 Card album (Twig)

```twig
<article class="card group">
  <a href="/album/{{ a.slug }}">
    <div class="aspect-[3/2] overflow-hidden bg-neutral-200">
      {{ include('frontend/_picture.twig', { img: a.cover, variants: a.coverVariants }) }}
    </div>
    <h2 class="mt-3 text-xl font-semibold">{{ a.title }}</h2>
    <p class="text-sm text-neutral-600">
      {{ a.category.name }}{% if a.tags %} •
      {% for t in a.tags %}<span>#{{ t.name }}</span>{% if not loop.last %}, {% endif %}{% endfor %}{% endif %}
    </p>
  </a>
</article>
```

### 14.3 Lightbox con EXIF/Metadati (PhotoSwipe)

Mostra caption + lista metadati (processo, pellicola, camera/lente, ISO/tempo/diaframma, lab/scanner se presenti).

---

## 15) Migrazioni & Seed minimi

- Inserisci qualche **categoria** (Ritratti, Street, Paesaggi).
- **Tag** comuni (Analogico, 35mm, 120, B/W, C‑41, E‑6).
- **Lookup**: un paio di cam/lens/film/developer/lab per demo.

---

## 16) CLI Tasks (Symfony Console)

- `images:generate [--missing] [--force]` — genera varianti.
- `images:ingest <path>` — importa cartelle (crea album da nome cartella, tag da sottocartelle).
- `sitemap:build` — rigenera sitemap.xml.
- `user:create <email>` — crea admin e stampa password temporanea.

---

## 17) Note di Design (B/N)

- Sfondo **#fff**, testo **#111**, link **#000** underline offset.  
- Griglie con gap 16–24px, card senza bordi, hover su immagini (leggero scale 1.02).  
- H1 40/44, body 18/28.  
- Navigazione sticky che collassa su scroll (GSAP).

---

## 18) Test & Qualità

- Unit su Services/Repos critici (query, EXIF, varianti).  
- Snapshot su template principali.  
- Lighthouse target: Performance ≥95, SEO ≥100, Accessibility ≥95.

---

## 19) Estensioni future (facoltative)

- Multilingua (tabella `album_translations`).  
- Selettori geografici (luogo dello scatto).  
- Watermark opzionale solo in lightbox.  
- Esportazione **CSV/JSON** dei metadati per archivio.

---

## 20) TL;DR (per quando avrai sonno)

- Slim + Twig + PDO.  
- Tabelle: albums/cat/tag + **images** con metadati estesi + lookup (camera/lente/film/developer/lab).  
- Uppy upload → EXIF → varianti AVIF/WebP/JPEG → `<picture>` ottimizzato.  
- Filtri AJAX su album e galleria (processo, pellicola, camera, ecc.).  
- Admin semplice, bianco/nero, con drag&drop e inline edit.
