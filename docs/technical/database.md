# Schema Database - Cimaise

## Panoramica

Cimaise supporta due engine di database:

- **SQLite 3.x**: Database file-based, ideale per sviluppo e piccoli siti
- **MySQL 8.0+ / MariaDB 10.6+**: Database server, ideale per produzione e siti con alto traffico

Le migrazioni sono organizzate in:
- `database/migrations/sqlite/` - Schema SQLite
- `database/migrations/mysql/` - Schema MySQL

L'astrazione del database è gestita da `app/Support/Database.php` che fornisce un'interfaccia unificata tramite PDO.

---

## Convenzioni Database

### Naming
- **Tabelle**: snake_case, plurale (es. `albums`, `categories`, `image_variants`)
- **Colonne**: snake_case (es. `created_at`, `cover_image_id`, `is_published`)
- **Chiavi esterne**: `{tabella_singolare}_id` (es. `album_id`, `category_id`)
- **Junction tables**: `{tabella1}_{tabella2}` (es. `album_tag`)

### Primary Keys
- Tutte le tabelle hanno una chiave primaria `id INTEGER PRIMARY KEY AUTOINCREMENT`

### Timestamps
- **SQLite**: `TEXT DEFAULT (datetime('now'))`
- **MySQL**: `DATETIME DEFAULT CURRENT_TIMESTAMP`
- Colonne standard: `created_at`, `updated_at` (nullable)

### Foreign Keys
- **SQLite**: Richiedono `PRAGMA foreign_keys = ON` (attivato automaticamente dal Database service)
- **MySQL**: Supporto nativo

### Indici
- Creati su tutte le colonne frequently queried: `slug`, `*_id`, `published_at`, `sort_order`
- Junction tables: composite index su entrambe le FK

---

## Schema Entità-Relazione (ERD)

```
users (1) ────────────────────── (0) albums [created_by - future]
                                      │
categories (1) ──────────────── (N) albums
    │ (self-referencing)             │
    └── parent_id                    ├── (1) ─────── (N) images
                                     │                   │
tags (N) ────── (N) albums          │                   ├── (1) ── (N) image_variants
     (album_tag)                     │                   │
                                     │                   ├── (N) ── (1) cameras
templates (1) ──────────── (N) albums                   ├── (N) ── (1) lenses
                                     │                   ├── (N) ── (1) films
locations (1) ──────────── (N) albums                   ├── (N) ── (1) developers
                                     │                   └── (N) ── (1) labs
                                     │
                                     └── (1) ─────── (N) analytics_pageviews

analytics_sessions (1) ────── (N) analytics_pageviews
                  │
                  └─────────── (N) analytics_events

settings (key-value store, no relations)
filter_settings (key-value store)
seo_settings (key-value store)
social_settings (standalone)
```

---

## Tabelle Core

### 1. users

**Descrizione**: Utenti amministrativi del sistema

**Colonne**:

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `email` | TEXT/VARCHAR(255) | NO | - | Email univoca (login) |
| `password_hash` | TEXT | NO | - | Hash Argon2id della password |
| `role` | TEXT/VARCHAR(50) | NO | 'admin' | Ruolo utente ('admin', 'editor') |
| `is_active` | BOOLEAN/TINYINT | NO | 1 | Flag utente attivo |
| `first_name` | TEXT/VARCHAR(100) | YES | NULL | Nome |
| `last_name` | TEXT/VARCHAR(100) | YES | NULL | Cognome |
| `last_login` | DATETIME | YES | NULL | Timestamp ultimo login |
| `created_at` | DATETIME | NO | NOW() | Data creazione |

**Indici**:
```sql
CREATE INDEX idx_users_email ON users(email);
```

**Note**:
- Password hashe con `password_hash($password, PASSWORD_ARGON2ID)`
- Verifica con `password_verify($input, $hash)`
- `is_active = 0` impedisce login anche se password corretta

---

### 2. categories

**Descrizione**: Categorie gerarchiche per organizzare album (es. "Ritratti", "Street Photography")

**Colonne**:

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `name` | TEXT/VARCHAR(255) | NO | - | Nome categoria |
| `slug` | TEXT/VARCHAR(255) | NO | - | URL-friendly identifier (univoco) |
| `parent_id` | INTEGER | YES | NULL | FK a categories.id (self-referencing) |
| `description` | TEXT | YES | NULL | Descrizione categoria |
| `image_path` | TEXT/VARCHAR(500) | YES | NULL | Immagine di copertina categoria |
| `sort_order` | INTEGER | NO | 0 | Ordinamento manuale |
| `created_at` | DATETIME | NO | NOW() | Data creazione |

**Indici**:
```sql
CREATE INDEX idx_categories_slug ON categories(slug);
CREATE INDEX idx_categories_sort ON categories(sort_order);
CREATE INDEX idx_categories_parent ON categories(parent_id);
```

**Relazioni**:
- **Parent-Child**: `parent_id` → `categories.id` (gerarchia, max 2 livelli raccomandati)
- **Albums**: `categories.id` ← `albums.category_id` (1:N)

**Note**:
- Gerarchia: una categoria può avere sottocategorie (`parent_id` non NULL)
- Slug generato automaticamente da `name` (es. "Street Photography" → "street-photography")
- `sort_order` permette ordinamento custom (drag-drop in admin)

---

### 3. tags

**Descrizione**: Tag flat per categorizzazione trasversale album (es. "#Analogico", "#B&W")

**Colonne**:

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `name` | TEXT/VARCHAR(100) | NO | - | Nome tag |
| `slug` | TEXT/VARCHAR(100) | NO | - | URL-friendly identifier (univoco) |
| `created_at` | DATETIME | NO | NOW() | Data creazione |

**Indici**:
```sql
CREATE INDEX idx_tags_slug ON tags(slug);
```

**Relazioni**:
- **Albums**: Many-to-Many via `album_tag`

**Note**:
- No gerarchia (flat tags)
- Un album può avere N tag
- Un tag può essere associato a N album

---

### 4. albums

**Descrizione**: Album fotografici / Progetti / Portfolio

**Colonne**:

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `title` | TEXT/VARCHAR(255) | NO | - | Titolo album |
| `slug` | TEXT/VARCHAR(255) | NO | - | URL-friendly identifier (univoco) |
| `category_id` | INTEGER | NO | - | FK a categories.id |
| `excerpt` | TEXT | YES | NULL | Breve descrizione (excerpt) |
| `body` | TEXT | YES | NULL | Descrizione completa (HTML sanitizzato) |
| `cover_image_id` | INTEGER | YES | NULL | FK a images.id (immagine di copertina) |
| `shoot_date` | DATE | YES | NULL | Data scatto/progetto |
| `published_at` | DATETIME | YES | NULL | Data/ora pubblicazione |
| `is_published` | BOOLEAN/TINYINT | NO | 0 | Flag pubblicato (0=draft, 1=published) |
| `template_id` | INTEGER | YES | NULL | FK a templates.id (layout custom) |
| `location_id` | INTEGER | YES | NULL | FK a locations.id |
| `password_hash` | TEXT | YES | NULL | Hash bcrypt se album protetto |
| `show_date` | BOOLEAN/TINYINT | NO | 1 | Mostra data scatto nel frontend |
| `sort_order` | INTEGER | NO | 0 | Ordinamento manuale |
| `created_at` | DATETIME | NO | NOW() | Data creazione |
| `updated_at` | DATETIME | YES | NULL | Data ultimo aggiornamento |

**Indici**:
```sql
CREATE INDEX idx_albums_slug ON albums(slug);
CREATE INDEX idx_albums_category ON albums(category_id);
CREATE INDEX idx_albums_published ON albums(is_published);
CREATE INDEX idx_albums_published_at ON albums(published_at);
CREATE INDEX idx_albums_sort ON albums(sort_order);
```

**Relazioni**:
- **Category**: `category_id` → `categories.id` (N:1)
- **Cover Image**: `cover_image_id` → `images.id` (1:1, nullable)
- **Template**: `template_id` → `templates.id` (N:1, nullable)
- **Location**: `location_id` → `locations.id` (N:1, nullable)
- **Images**: `albums.id` ← `images.album_id` (1:N)
- **Tags**: Many-to-Many via `album_tag`

**Note**:
- `is_published = 0`: Album in bozza (non visibile frontend)
- `published_at`: Timestamp di pubblicazione (può essere futuro per pubblicazione programmata)
- `password_hash`: Se valorizzato, album richiede password per visualizzazione
- `show_date`: Toggle visibilità data scatto
- `sort_order`: Ordine custom nelle liste (admin può riordinare con drag-drop)

---

### 5. album_tag (Junction Table)

**Descrizione**: Relazione Many-to-Many tra album e tag

**Colonne**:

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `album_id` | INTEGER | NO | - | FK a albums.id |
| `tag_id` | INTEGER | NO | - | FK a tags.id |

**Primary Key**: `(album_id, tag_id)`

**Foreign Keys**:
```sql
FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE
FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
```

**Note**:
- `ON DELETE CASCADE`: Eliminando album o tag, rimuove automaticamente associazione
- No colonne aggiuntive (pure junction)

---

## Tabelle Media

### 6. images

**Descrizione**: Immagini/fotografie caricate negli album

**Colonne**:

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `album_id` | INTEGER | NO | - | FK a albums.id |
| `original_path` | TEXT/VARCHAR(500) | NO | - | Percorso file originale in storage/ |
| `file_hash` | TEXT/VARCHAR(64) | NO | - | SHA1 hash (deduplicazione) |
| `width` | INTEGER | NO | - | Larghezza originale (px) |
| `height` | INTEGER | NO | - | Altezza originale (px) |
| `mime` | TEXT/VARCHAR(100) | NO | - | MIME type (image/jpeg, image/png) |
| `alt_text` | TEXT/VARCHAR(255) | YES | NULL | Testo alternativo (accessibilità) |
| `caption` | TEXT | YES | NULL | Didascalia immagine |
| `exif` | TEXT | YES | NULL | Metadati EXIF (JSON serializzato) |
| **Equipment** ||||
| `camera_id` | INTEGER | YES | NULL | FK a cameras.id |
| `lens_id` | INTEGER | YES | NULL | FK a lenses.id |
| `film_id` | INTEGER | YES | NULL | FK a films.id |
| `developer_id` | INTEGER | YES | NULL | FK a developers.id |
| `lab_id` | INTEGER | YES | NULL | FK a labs.id |
| **Custom Equipment** ||||
| `custom_camera` | TEXT/VARCHAR(255) | YES | NULL | Fotocamera custom (se non in lookup) |
| `custom_lens` | TEXT/VARCHAR(255) | YES | NULL | Lente custom |
| `custom_film` | TEXT/VARCHAR(255) | YES | NULL | Pellicola custom |
| `custom_development` | TEXT/VARCHAR(255) | YES | NULL | Sviluppo custom |
| `custom_lab` | TEXT/VARCHAR(255) | YES | NULL | Laboratorio custom |
| `custom_scanner` | TEXT/VARCHAR(255) | YES | NULL | Scanner utilizzato |
| `scan_resolution_dpi` | INTEGER | YES | NULL | Risoluzione scansione (DPI) |
| `scan_bit_depth` | INTEGER | YES | NULL | Profondità bit scansione |
| `process` | TEXT/VARCHAR(50) | NO | 'digital' | Processo ('digital', 'analog', 'film_scanned') |
| `development_date` | DATE | YES | NULL | Data sviluppo |
| **EXIF Standard** ||||
| `iso` | INTEGER | YES | NULL | Sensibilità ISO |
| `shutter_speed` | TEXT/VARCHAR(50) | YES | NULL | Tempo esposizione (es. "1/1000") |
| `aperture` | REAL/DECIMAL(3,1) | YES | NULL | Apertura diaframma (es. 2.8) |
| **Meta** ||||
| `sort_order` | INTEGER | NO | 0 | Ordinamento nell'album |
| `created_at` | DATETIME | NO | NOW() | Data upload |

**Indici**:
```sql
CREATE INDEX idx_images_album ON images(album_id);
CREATE INDEX idx_images_sort ON images(sort_order);
CREATE INDEX idx_images_hash ON images(file_hash);
CREATE INDEX idx_images_camera ON images(camera_id);
CREATE INDEX idx_images_lens ON images(lens_id);
CREATE INDEX idx_images_film ON images(film_id);
CREATE INDEX idx_images_process ON images(process);
CREATE INDEX idx_images_iso ON images(iso);
```

**Relazioni**:
- **Album**: `album_id` → `albums.id` (N:1, CASCADE DELETE)
- **Equipment**: FK a lookup tables (cameras, lenses, films, developers, labs)
- **Variants**: `images.id` ← `image_variants.image_id` (1:N)

**Note**:
- `original_path`: Path relativo a `storage/originals/` (es. `abc123def456.jpg`)
- `file_hash`: SHA1 del file originale, usato per deduplicazione (stesso hash = stesso file)
- `exif`: JSON completo estratto con `exif_read_data()` (GPS, camera make/model, lens, ecc.)
- **Equipment**: Lookup ID (se selezionato) OR custom text (se inserito manualmente)
- `process`: Workflow fotografico (digitale, analogico puro, analogico scansionato)
- `sort_order`: Ordine di visualizzazione nell'album (drag-drop in admin)

---

### 7. image_variants

**Descrizione**: Versioni responsive/ottimizzate delle immagini (AVIF/WebP/JPEG)

**Colonne**:

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `image_id` | INTEGER | NO | - | FK a images.id |
| `variant` | TEXT/VARCHAR(10) | NO | - | Breakpoint ('sm', 'md', 'lg', 'xl', 'xxl') |
| `format` | TEXT/VARCHAR(10) | NO | - | Formato ('avif', 'webp', 'jpg') |
| `path` | TEXT/VARCHAR(500) | NO | - | Percorso file in public/media/ |
| `width` | INTEGER | NO | - | Larghezza (px) |
| `height` | INTEGER | NO | - | Altezza (px) |
| `size_bytes` | INTEGER | NO | - | Dimensione file (bytes) |

**Unique Constraint**: `(image_id, variant, format)`

**Foreign Keys**:
```sql
FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
```

**Note**:
- **Variants (breakpoints)**:
  - `sm`: 768px (mobile)
  - `md`: 1200px (tablet)
  - `lg`: 1920px (desktop)
  - `xl`: 2560px (4K)
  - `xxl`: 3840px (8K)
- **Formats**:
  - `avif`: Formato moderno, alta compressione (~50% qualità)
  - `webp`: Fallback moderno (~75% qualità)
  - `jpg`: Fallback universale (~85% qualità)
- `path`: Naming convention `{hash}_{variant}_{format}.{ext}` (es. `abc123_lg_avif.avif`)
- Generati tramite comando CLI `php bin/console images:generate`
- Serviti da `public/media/` con cache headers (1 anno)

---

## Tabelle Lookup (Equipment)

Queste tabelle contengono dati di riferimento per fotocamere, lenti, pellicole, ecc.

### 8. cameras

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `make` | TEXT/VARCHAR(100) | NO | - | Marca (es. "Canon", "Nikon") |
| `model` | TEXT/VARCHAR(100) | NO | - | Modello (es. "EOS 5D Mark IV") |
| `created_at` | DATETIME | NO | NOW() | Data creazione |

### 9. lenses

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `make` | TEXT/VARCHAR(100) | NO | - | Marca (es. "Canon", "Sigma") |
| `model` | TEXT/VARCHAR(100) | NO | - | Modello (es. "EF 50mm f/1.8") |
| `created_at` | DATETIME | NO | NOW() | Data creazione |

### 10. films

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `brand` | TEXT/VARCHAR(100) | NO | - | Marca (es. "Kodak", "Fujifilm") |
| `name` | TEXT/VARCHAR(100) | NO | - | Nome (es. "Portra 400", "HP5 Plus") |
| `created_at` | DATETIME | NO | NOW() | Data creazione |

### 11. developers

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `brand` | TEXT/VARCHAR(100) | NO | - | Marca sviluppatore |
| `name` | TEXT/VARCHAR(100) | NO | - | Nome prodotto |
| `created_at` | DATETIME | NO | NOW() | Data creazione |

### 12. labs

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `name` | TEXT/VARCHAR(255) | NO | - | Nome laboratorio |
| `city` | TEXT/VARCHAR(100) | YES | NULL | Città |
| `country` | TEXT/VARCHAR(100) | YES | NULL | Paese |
| `created_at` | DATETIME | NO | NOW() | Data creazione |

**Note su Lookup Tables**:
- Gestite da admin in CRUD dedicati (`/admin/cameras`, `/admin/lenses`, ecc.)
- Se valore non presente, utente può inserire in `custom_*` field
- Usate in filtri avanzati (`/galleries`)

---

## Tabelle Geografiche

### 13. locations

**Descrizione**: Luoghi geografici associabili ad album

**Colonne**:

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `name` | TEXT/VARCHAR(255) | NO | - | Nome località |
| `slug` | TEXT/VARCHAR(255) | NO | - | URL-friendly identifier (univoco) |
| `country` | TEXT/VARCHAR(100) | YES | NULL | Paese |
| `city` | TEXT/VARCHAR(100) | YES | NULL | Città |
| `latitude` | REAL/DECIMAL(10,8) | YES | NULL | Latitudine |
| `longitude` | REAL/DECIMAL(11,8) | YES | NULL | Longitudine |
| `description` | TEXT | YES | NULL | Descrizione |
| `created_at` | DATETIME | NO | NOW() | Data creazione |

**Indici**:
```sql
CREATE INDEX idx_locations_slug ON locations(slug);
CREATE INDEX idx_locations_country ON locations(country);
```

**Relazioni**:
- **Albums**: `locations.id` ← `albums.location_id` (1:N)

---

## Tabelle Configurazione

### 14. settings

**Descrizione**: Key-value store per configurazioni globali applicazione

**Colonne**:

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `key` | TEXT/VARCHAR(255) | NO | - | Chiave setting (univoca) |
| `value` | TEXT | NO | - | Valore (JSON serializzato) |
| `type` | TEXT/VARCHAR(50) | NO | - | Tipo dato ('string', 'number', 'boolean', 'array') |
| `created_at` | DATETIME | NO | NOW() | Data creazione |
| `updated_at` | DATETIME | YES | NULL | Data ultimo aggiornamento |

**Unique Constraint**: `key`

**Esempi di Setting**:

```json
// Image settings
{
  "key": "image.formats",
  "value": "{\"avif\": true, \"webp\": true, \"jpg\": true}",
  "type": "array"
}
{
  "key": "image.quality",
  "value": "{\"avif\": 50, \"webp\": 75, \"jpg\": 85}",
  "type": "array"
}
{
  "key": "image.breakpoints",
  "value": "{\"sm\": 768, \"md\": 1200, \"lg\": 1920, \"xl\": 2560, \"xxl\": 3840}",
  "type": "array"
}

// Site settings
{
  "key": "site.title",
  "value": "\"My Photography Portfolio\"",
  "type": "string"
}
{
  "key": "site.email",
  "value": "\"info@example.com\"",
  "type": "string"
}
{
  "key": "pagination.limit",
  "value": "12",
  "type": "number"
}
```

**Gestito da**: `SettingsService` con caching in-memory

---

### 15. filter_settings

**Descrizione**: Configurazione filtri pagina `/galleries`

**Colonne**:

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `setting_key` | TEXT/VARCHAR(255) | NO | - | Chiave (univoca) |
| `setting_value` | TEXT | NO | - | Valore (JSON) |
| `description` | TEXT | YES | NULL | Descrizione |
| `updated_at` | DATETIME | YES | NULL | Data aggiornamento |

**Unique Constraint**: `setting_key`

**Esempi**:
```sql
('galleries.filters.show_category', 'true', 'Enable category filter')
('galleries.filters.show_tag', 'true', 'Enable tag filter')
('galleries.filters.show_camera', 'true', 'Enable camera filter')
('galleries.filters.show_lens', 'true', 'Enable lens filter')
('galleries.filters.show_film', 'true', 'Enable film filter')
('galleries.filters.show_date_range', 'true', 'Enable date range filter')
```

---

### 16. seo_settings

**Descrizione**: Configurazione SEO e meta tags

**Colonne**:

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `setting_key` | TEXT/VARCHAR(255) | NO | - | Chiave (univoca) |
| `setting_value` | TEXT | YES | NULL | Valore |
| `description` | TEXT | YES | NULL | Descrizione |
| `updated_at` | DATETIME | YES | NULL | Data aggiornamento |

**Esempi**:
```sql
('seo.site_title', 'Photography Portfolio', 'Site title for SEO')
('seo.og_site_name', 'Photography Portfolio', 'Open Graph site name')
('seo.robots_default', 'index,follow', 'Default robots meta tag')
('seo.canonical_base_url', 'https://example.com', 'Canonical URL base')
('seo.author_name', 'John Doe', 'Author/photographer name')
('seo.photographer_job_title', 'Professional Photographer', 'Job title for Schema.org')
```

---

### 17. social_settings

**Descrizione**: Links social media

**Colonne**:

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `platform` | TEXT/VARCHAR(50) | NO | - | Piattaforma ('instagram', 'twitter', 'facebook', 'linkedin') |
| `url` | TEXT/VARCHAR(500) | YES | NULL | URL profilo completo |
| `handle` | TEXT/VARCHAR(100) | YES | NULL | Username/handle |
| `created_at` | DATETIME | NO | NOW() | Data creazione |

---

## Tabelle Templates

### 18. templates

**Descrizione**: Layout personalizzati per album

**Colonne**:

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `name` | TEXT/VARCHAR(255) | NO | - | Nome template |
| `slug` | TEXT/VARCHAR(255) | NO | - | Identifier (univoco) |
| `description` | TEXT | YES | NULL | Descrizione |
| `settings` | TEXT | YES | NULL | JSON config (es. columns) |
| `libs` | TEXT | YES | NULL | JSON librerie JS aggiuntive |
| `created_at` | DATETIME | NO | NOW() | Data creazione |

**Unique Constraint**: `slug`

**Esempio settings**:
```json
{
  "columns": {
    "desktop": 3,
    "tablet": 2,
    "mobile": 1
  },
  "spacing": "md",
  "lightbox": true
}
```

**Relazioni**:
- **Albums**: `templates.id` ← `albums.template_id` (1:N, nullable)

---

## Tabelle Analytics

### 19. analytics_sessions

**Descrizione**: Sessioni visitatori (tracking anonimizzato)

**Colonne**:

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `session_id` | VARCHAR(64) | NO | - | ID sessione univoco (generato) |
| `ip_hash` | VARCHAR(64) | NO | - | Hash SHA256 IP (privacy) |
| `user_agent` | TEXT | YES | NULL | User-Agent string |
| **Browser/Device** ||||
| `browser` | VARCHAR(100) | YES | NULL | Nome browser (es. "Chrome") |
| `browser_version` | VARCHAR(50) | YES | NULL | Versione browser |
| `platform` | VARCHAR(100) | YES | NULL | OS (es. "Windows", "macOS") |
| `device_type` | VARCHAR(50) | YES | NULL | Tipo ('desktop', 'mobile', 'tablet') |
| `screen_resolution` | VARCHAR(20) | YES | NULL | Risoluzione (es. "1920x1080") |
| **Geographic** ||||
| `country_code` | VARCHAR(2) | YES | NULL | Codice paese ISO (es. "IT") |
| `region` | VARCHAR(100) | YES | NULL | Regione/Stato |
| `city` | VARCHAR(100) | YES | NULL | Città |
| **Referral** ||||
| `referrer_domain` | VARCHAR(255) | YES | NULL | Dominio referrer |
| `referrer_url` | TEXT | YES | NULL | URL referrer completo |
| `landing_page` | TEXT | YES | NULL | Prima pagina visitata |
| **Metrics** ||||
| `started_at` | DATETIME | NO | NOW() | Inizio sessione |
| `last_activity` | DATETIME | NO | NOW() | Ultima attività |
| `page_views` | INTEGER | NO | 0 | Numero pageviews |
| `duration` | INTEGER | NO | 0 | Durata totale (secondi) |
| `is_bot` | BOOLEAN/TINYINT | NO | 0 | Flag bot detected |

**Unique Constraint**: `session_id`

**Indici**:
```sql
CREATE INDEX idx_analytics_sessions_session_id ON analytics_sessions(session_id);
CREATE INDEX idx_analytics_sessions_started_at ON analytics_sessions(started_at);
CREATE INDEX idx_analytics_sessions_country ON analytics_sessions(country_code);
CREATE INDEX idx_analytics_sessions_device ON analytics_sessions(device_type);
```

**Note**:
- `session_id`: Generato lato client (cookie) o server
- `ip_hash`: `hash('sha256', $ip . $salt)` per privacy (GDPR compliant)
- Geolocation via GeoIP2 library (IP → country/city)
- Bot detection via User-Agent patterns

---

### 20. analytics_pageviews

**Descrizione**: Singole visualizzazioni di pagina

**Colonne**:

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `session_id` | VARCHAR(64) | NO | - | FK a analytics_sessions |
| `page_url` | TEXT | NO | - | URL pagina visualizzata |
| `page_title` | VARCHAR(255) | YES | NULL | Titolo pagina |
| `page_type` | VARCHAR(50) | YES | NULL | Tipo ('home', 'album', 'category', 'tag') |
| **Entity References** ||||
| `album_id` | INTEGER | YES | NULL | FK a albums.id (se page_type=album) |
| `category_id` | INTEGER | YES | NULL | FK a categories.id |
| `tag_id` | INTEGER | YES | NULL | FK a tags.id |
| **Performance** ||||
| `load_time` | INTEGER | YES | NULL | Tempo caricamento (ms) |
| `viewport_width` | INTEGER | YES | NULL | Larghezza viewport (px) |
| `viewport_height` | INTEGER | YES | NULL | Altezza viewport (px) |
| `scroll_depth` | INTEGER | NO | 0 | Profondità scroll (%) |
| `time_on_page` | INTEGER | NO | 0 | Tempo sulla pagina (secondi) |
| **Meta** ||||
| `viewed_at` | DATETIME | NO | NOW() | Timestamp visualizzazione |

**Foreign Keys**:
```sql
FOREIGN KEY (session_id) REFERENCES analytics_sessions(session_id) ON DELETE CASCADE
```

**Indici**:
```sql
CREATE INDEX idx_analytics_pageviews_session_id ON analytics_pageviews(session_id);
CREATE INDEX idx_analytics_pageviews_viewed_at ON analytics_pageviews(viewed_at);
CREATE INDEX idx_analytics_pageviews_page_type ON analytics_pageviews(page_type);
CREATE INDEX idx_analytics_pageviews_album_id ON analytics_pageviews(album_id);
```

**Note**:
- Trackato via POST `/api/analytics/track` (JavaScript beacon)
- `scroll_depth`: Percentuale massima scroll (0-100)
- `time_on_page`: Calcolato lato client (time difference tra pageviews)

---

### 21. analytics_events

**Descrizione**: Eventi custom (download, click, search, ecc.)

**Colonne**:

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `session_id` | VARCHAR(64) | NO | - | FK a analytics_sessions |
| `event_type` | VARCHAR(50) | NO | - | Tipo evento ('download', 'click', 'search') |
| `event_category` | VARCHAR(100) | YES | NULL | Categoria |
| `event_action` | VARCHAR(100) | YES | NULL | Azione |
| `event_label` | VARCHAR(255) | YES | NULL | Label descrittiva |
| `event_value` | INTEGER | YES | NULL | Valore numerico (opzionale) |
| `page_url` | TEXT | YES | NULL | URL pagina dove è avvenuto evento |
| `album_id` | INTEGER | YES | NULL | FK album (se correlato) |
| `image_id` | INTEGER | YES | NULL | FK image (se correlato) |
| `occurred_at` | DATETIME | NO | NOW() | Timestamp evento |

**Foreign Keys**:
```sql
FOREIGN KEY (session_id) REFERENCES analytics_sessions(session_id) ON DELETE CASCADE
```

**Indici**:
```sql
CREATE INDEX idx_analytics_events_session_id ON analytics_events(session_id);
CREATE INDEX idx_analytics_events_type ON analytics_events(event_type);
CREATE INDEX idx_analytics_events_occurred_at ON analytics_events(occurred_at);
```

**Esempi Eventi**:
```sql
-- Download immagine
{
  event_type: 'download',
  event_category: 'image',
  event_action: 'click',
  event_label: 'image_123.jpg',
  album_id: 5,
  image_id: 123
}

-- Search
{
  event_type: 'search',
  event_category: 'gallery',
  event_action: 'filter',
  event_label: 'category:portraits,tag:analog'
}
```

---

### 22. analytics_daily_summary

**Descrizione**: Aggregazioni giornaliere pre-computate (performance)

**Colonne**:

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `date` | DATE | NO | - | Data (univoca) |
| `total_sessions` | INTEGER | NO | 0 | Sessioni totali |
| `total_pageviews` | INTEGER | NO | 0 | Pageviews totali |
| `unique_visitors` | INTEGER | NO | 0 | Visitatori unici (IP hash distinti) |
| `bounce_rate` | DECIMAL(5,2) | NO | 0.00 | Bounce rate (%) |
| `avg_session_duration` | INTEGER | NO | 0 | Durata media sessione (secondi) |
| **Top Lists (JSON)** ||||
| `top_pages` | TEXT | YES | NULL | JSON array top 10 pagine |
| `top_countries` | TEXT | YES | NULL | JSON array top paesi |
| `top_browsers` | TEXT | YES | NULL | JSON array top browser |
| `top_albums` | TEXT | YES | NULL | JSON array top album |
| **Meta** ||||
| `created_at` | DATETIME | NO | NOW() | Data creazione record |

**Unique Constraint**: `date`

**Indici**:
```sql
CREATE INDEX idx_analytics_daily_summary_date ON analytics_daily_summary(date);
```

**Note**:
- Generato tramite comando CLI `php bin/console analytics:summarize`
- Eseguibile in cron job giornaliero (es. 02:00 AM)
- Riduce query load per dashboard analytics
- JSON arrays esempio:
```json
{
  "top_pages": [
    {"url": "/album/landscapes", "views": 523},
    {"url": "/", "views": 412}
  ],
  "top_countries": [
    {"code": "IT", "name": "Italy", "sessions": 234},
    {"code": "US", "name": "USA", "sessions": 189}
  ]
}
```

---

### 23. analytics_settings

**Descrizione**: Configurazione analytics

**Colonne**:

| Colonna | Tipo | Nullable | Default | Descrizione |
|---------|------|----------|---------|-------------|
| `id` | INTEGER | NO | AUTO | Chiave primaria |
| `setting_key` | VARCHAR(255) | NO | - | Chiave (univoca) |
| `setting_value` | TEXT | YES | NULL | Valore |
| `description` | TEXT | YES | NULL | Descrizione |
| `updated_at` | DATETIME | YES | NULL | Data aggiornamento |

**Default Settings**:
```sql
('analytics_enabled', 'true', 'Enable/disable analytics tracking')
('ip_anonymization', 'true', 'Anonymize IP addresses for privacy')
('data_retention_days', '365', 'Number of days to keep detailed analytics data')
('real_time_enabled', 'true', 'Enable real-time visitor tracking')
('geolocation_enabled', 'true', 'Enable geographic data collection')
('bot_detection_enabled', 'true', 'Filter out bot traffic')
('session_timeout_minutes', '30', 'Session timeout in minutes')
('export_enabled', 'true', 'Allow data export functionality')
```

---

## Migrazioni

### Struttura Migrazioni

Le migrazioni sono file SQL numerati sequenzialmente:

**SQLite**: `database/migrations/sqlite/NNNN_description.sql`
**MySQL**: `database/migrations/mysql/NNNN_description.sql`

### Ordine Esecuzione

1. `0001_init_core.sql` - Tabelle base (users, categories, tags, albums)
2. `0002_lookups.sql` - Equipment tables (cameras, lenses, films, developers, labs)
3. `0003_images.sql` - images + image_variants
4. `0004_settings.sql` - settings
5. `0005_categories_hierarchy.sql` - parent_id, description
6. `0006_album_categories.sql` - category_id
7. `0007_album_show_date.sql` - show_date field
8. `0008_templates.sql` - templates table
9. `0009_responsive_columns.sql` - (deprecato/merged)
10. `0010_album_equipment.sql` - camera_id, lens_id, ecc.
11. `0011_album_protection.sql` - password_hash
12. `0012_locations.sql` - locations table
13. `0013_location_relationships.sql` - album.location_id
14. `0014_categories_image_path.sql` - categories.image_path
15. `0015_users_enhancement.sql` - first_name, last_name, is_active, last_login
16. `0016_filter_settings.sql` - filter_settings table
17. `0017_analytics_tables.sql` (SQLite) / `0011_analytics_tables.sql` (MySQL)
18. `0018_seo_settings.sql` - seo_settings table
19. `0019_social_settings.sql` - social_settings table

### Eseguire Migrazioni

```bash
# Esegui tutte le migrazioni
php bin/console migrate

# Reset + migrate (ATTENZIONE: elimina dati!)
php bin/console migrate --fresh

# Seed dati demo
php bin/console seed
```

---

## Query Comuni

### Fetch Album con Cover Image

```sql
SELECT
    a.*,
    c.name as category_name,
    c.slug as category_slug,
    i.original_path as cover_path
FROM albums a
INNER JOIN categories c ON a.category_id = c.id
LEFT JOIN images i ON a.cover_image_id = i.id
WHERE a.is_published = 1
ORDER BY a.published_at DESC
LIMIT 12;
```

### Fetch Immagini Album con Variants

```sql
SELECT
    i.*,
    GROUP_CONCAT(
        DISTINCT CASE WHEN iv.format = 'avif'
        THEN iv.path || ' ' || iv.width || 'w'
        END, ', '
    ) as avif_srcset,
    GROUP_CONCAT(
        DISTINCT CASE WHEN iv.format = 'webp'
        THEN iv.path || ' ' || iv.width || 'w'
        END, ', '
    ) as webp_srcset,
    GROUP_CONCAT(
        DISTINCT CASE WHEN iv.format = 'jpg'
        THEN iv.path || ' ' || iv.width || 'w'
        END, ', '
    ) as jpg_srcset
FROM images i
LEFT JOIN image_variants iv ON i.id = iv.image_id
WHERE i.album_id = ?
GROUP BY i.id
ORDER BY i.sort_order;
```

### Top Album per Pageviews

```sql
SELECT
    a.id,
    a.title,
    a.slug,
    COUNT(ap.id) as views
FROM albums a
INNER JOIN analytics_pageviews ap ON a.id = ap.album_id
WHERE ap.viewed_at >= datetime('now', '-30 days')
GROUP BY a.id
ORDER BY views DESC
LIMIT 10;
```

### Sessioni Attive (Real-time)

```sql
SELECT COUNT(*) as active_visitors
FROM analytics_sessions
WHERE last_activity >= datetime('now', '-5 minutes')
  AND is_bot = 0;
```

---

## Best Practices

### Performance

1. **Indici**: Crea indici su colonne frequently queried
2. **LIMIT**: Usa sempre LIMIT su query che possono ritornare molti risultati
3. **Prepared Statements**: SEMPRE (sicurezza + performance)
4. **Eager Loading**: Usa JOIN per evitare N+1 queries
5. **Aggregazioni**: Pre-computa stats in `analytics_daily_summary`

### Sicurezza

1. **Prepared Statements**: OBBLIGATORIO, no string concatenation
2. **Foreign Keys**: Abilita SEMPRE (SQLite: `PRAGMA foreign_keys = ON`)
3. **ON DELETE CASCADE**: Usa dove appropriato (junction tables, images)
4. **Password Hashing**: Argon2id per users, bcrypt per album protection
5. **IP Anonymization**: Hash IP in analytics (GDPR)

### Manutenzione

1. **Cleanup Analytics**: Elimina dati vecchi periodicamente
   ```bash
   php bin/console analytics:cleanup --days 365
   ```

2. **Vacuum (SQLite)**: Ottimizza spazio disco
   ```sql
   VACUUM;
   ```

3. **Optimize Tables (MySQL)**:
   ```sql
   OPTIMIZE TABLE analytics_pageviews, analytics_sessions;
   ```

4. **Backup**: Backup giornalieri automatizzati (DB + storage/originals/)

---

## Prossimi Passi

- **[API e Endpoint](./api.md)**: Come interrogare il database via API
- **[Sicurezza](./security.md)**: SQL Injection prevention, best practices
- **[Guida Sviluppo](./development.md)**: Testing, seeding, fixture
- **[Deployment](./deployment.md)**: Setup produzione, ottimizzazioni

---

**Ultimo aggiornamento**: 17 Novembre 2025
**Versione**: 1.0.0
