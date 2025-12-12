# API e Endpoint - photoCMS

## Panoramica

photoCMS espone due tipologie di API:

1. **API Public** (`/api/*`): Endpoint pubblici non autenticati per frontend
2. **API Admin** (`/admin/*`, `/api/admin/*`): Endpoint protetti da AuthMiddleware

Tutte le risposte sono in formato JSON o HTML (Twig templates).

---

## Autenticazione

### Public API
- **Nessuna autenticazione richiesta**
- Rate limiting applicato su alcuni endpoint (analytics tracking)

### Admin API
- **Middleware**: `AuthMiddleware`
- **Metodo**: Session-based (cookie `PHPSESSID`)
- **Login**: POST `/admin/login`
- **Verifica**: Controlla `$_SESSION['admin_id']` + query DB `users.is_active = 1`

**Esempio Login**:
```http
POST /admin/login
Content-Type: application/x-www-form-urlencoded

email=admin@example.com&password=MySecurePass&csrf=TOKEN
```

**Risposta Success**:
```http
HTTP/1.1 302 Found
Location: /admin
Set-Cookie: PHPSESSID=...
```

---

## Rate Limiting

**Implementazione**: `FileBasedRateLimitMiddleware` (storage/tmp/)

| Endpoint | Limite | Finestra |
|----------|--------|----------|
| `/admin/login` | 5 req | 600 sec (10 min) |
| `/api/analytics/track` | 60 req | 60 sec |
| `/album/{slug}/unlock` | 5 req | 600 sec |
| `/about/contact` | 5 req | 600 sec |

**Risposta quando limitato**:
```json
{
  "error": "Too many requests. Please try again later."
}
```
HTTP Status: `429 Too Many Requests`

---

## API Public Endpoints

### 1. GET `/api/albums`

**Descrizione**: Elenca tutti gli album pubblicati

**Autenticazione**: No

**Query Parameters**: Nessuno

**Risposta**:
```json
[
  {
    "id": 1,
    "title": "Urban Landscapes",
    "slug": "urban-landscapes",
    "category_id": 2,
    "category_name": "Street Photography",
    "excerpt": "A collection of urban scenes...",
    "cover_image_path": "/media/abc123_md_webp.webp",
    "published_at": "2024-01-15 10:30:00",
    "image_count": 24
  },
  ...
]
```

**Filtri**: Solo `is_published = 1`

---

### 2. GET `/api/album/{id}/images`

**Descrizione**: Recupera immagini di un album con srcset responsive

**Autenticazione**: No (verifica published)

**Path Parameters**:
- `id` (int): ID album

**Risposta**:
```json
{
  "album": {
    "id": 1,
    "title": "Urban Landscapes",
    "slug": "urban-landscapes"
  },
  "images": [
    {
      "id": 15,
      "alt_text": "Downtown at sunset",
      "caption": "Shot on film with...",
      "width": 3840,
      "height": 2160,
      "sources": {
        "avif": [
          {
            "url": "/media/hash_sm_avif.avif",
            "width": 768,
            "descriptor": "768w"
          },
          {
            "url": "/media/hash_md_avif.avif",
            "width": 1200,
            "descriptor": "1200w"
          },
          ...
        ],
        "webp": [...],
        "jpg": [...]
      },
      "exif": {
        "camera": "Canon EOS 5D Mark IV",
        "lens": "EF 24-70mm f/2.8",
        "iso": 400,
        "aperture": 5.6,
        "shutter_speed": "1/250"
      }
    },
    ...
  ]
}
```

**Note**: `sources` contiene tutti i formati e breakpoints per `<picture>` element

---

### 3. POST `/api/analytics/track`

**Descrizione**: Trackare pageview o evento

**Autenticazione**: No

**Rate Limit**: 60 req/min

**Content-Type**: `application/json`

**Request Body**:
```json
{
  "session_id": "abc123def456...",
  "page_url": "/album/urban-landscapes",
  "page_type": "album",
  "album_id": 1,
  "page_title": "Urban Landscapes",
  "viewport_width": 1920,
  "viewport_height": 1080,
  "scroll_depth": 75,
  "time_on_page": 120,
  "event_type": "pageview" // o "download", "click", ecc.
}
```

**Risposta**:
```json
{
  "status": "tracked",
  "session_id": "abc123def456..."
}
```

**Note**:
- `session_id` generato lato client (localStorage) o server
- IP anonimizzato automaticamente (SHA256 hash)
- Geolocation e bot detection lato server

---

### 4. GET `/api/analytics/ping`

**Descrizione**: Health check endpoint

**Autenticazione**: No

**Risposta**: `204 No Content`

**Uso**: Verifica routing in subdirectory, test connessione

---

## API Frontend (HTML)

### 5. GET `/`

**Descrizione**: Home page con infinite gallery

**Risposta**: HTML (Twig template)

**Dati renderizzati**:
- Max 150 immagini random da album published
- Metadati album associati
- SEO meta tags

---

### 6. GET `/album/{slug}`

**Descrizione**: Visualizza album singolo

**Path Parameters**:
- `slug` (string): URL-friendly identifier

**Protezione Password**:
- Se `album.password_hash` presente → mostra form password
- POST `/album/{slug}/unlock` per sbloccare

**Risposta**: HTML + lightbox PhotoSwipe

**Query Parameters** (opzionali):
- `template` (int): Override template_id (es. `?template=2`)

---

### 7. POST `/album/{slug}/unlock`

**Descrizione**: Sblocca album protetto da password

**Autenticazione**: No

**Rate Limit**: 5 req/10 min

**Request Body**:
```
password=UserPassword
```

**Risposta Success**: Redirect a `/album/{slug}` con sessione attiva

**Risposta Error**: Redirect con flash message error

**Session**: `$_SESSION['album_access'][$album_id] = timestamp()` (24h TTL)

---

### 8. GET `/category/{slug}`

**Descrizione**: Visualizza album in categoria

**Path Parameters**:
- `slug` (string): Category slug

**Risposta**: HTML grid album filtrati

**Filtri**: Solo `is_published = 1` + `category_id = X` (o children)

---

### 9. GET `/tag/{slug}`

**Descrizione**: Visualizza album con tag

**Path Parameters**:
- `slug` (string): Tag slug

**Risposta**: HTML grid album

**Join**: `albums JOIN album_tag ON ... WHERE tag_id = X`

---

### 10. GET `/galleries`

**Descrizione**: Advanced filter page

**Query Parameters** (tutti opzionali):
- `category` (int): Category ID
- `tag` (int): Tag ID
- `camera` (int): Camera ID
- `lens` (int): Lens ID
- `film` (int): Film ID
- `date_from` (string): YYYY-MM-DD
- `date_to` (string): YYYY-MM-DD

**Risposta**: HTML con filtri sidebar + grid album

**AJAX Refresh**: GET `/galleries/filter` (stesso params, ritorna solo HTML grid)

---

### 11. GET `/about`

**Descrizione**: About page con form contatto

**Risposta**: HTML (content da `settings` table)

---

### 12. POST `/about/contact`

**Descrizione**: Invia email contatto

**Autenticazione**: No

**Rate Limit**: 5 req/10 min

**Request Body**:
```
name=John Doe
email=john@example.com
message=Hello...
```

**Risposta**: Redirect con flash message

**Invio Email**: `mail()` PHP o SMTP configurato

---

### 13. GET `/download/image/{id}`

**Descrizione**: Download immagine originale o variant

**Path Parameters**:
- `id` (int): Image ID

**Query Parameters**:
- `variant` (string, opzionale): `sm|md|lg|xl|xxl`
- `format` (string, opzionale): `avif|webp|jpg`

**Verifica**:
- Album pubblicato
- Se album password-protected → verifica sessione

**Risposta**: File download (Content-Disposition: attachment)

**Analytics**: Traccia evento download

**Esempio**:
```
GET /download/image/123?variant=lg&format=jpg
```

---

## API Admin Endpoints

**Tutte le route `/admin/*` richiedono autenticazione tramite `AuthMiddleware`**

### Auth Endpoints

#### POST `/admin/login`

**Descrizione**: Login admin

**Rate Limit**: 5 req/10 min

**Request Body**:
```
email=admin@example.com
password=MyPassword
csrf=TOKEN
```

**Risposta**:
- Success: `302 /admin`
- Fail: `302 /admin/login` con error flash

**Logica**:
1. Verifica CSRF
2. Query `users WHERE email = ?`
3. `password_verify($input, $user['password_hash'])`
4. Verifica `is_active = 1`
5. `session_regenerate_id(true)`
6. Set session vars
7. Update `last_login`

---

#### POST `/admin/logout`

**Descrizione**: Logout admin

**Risposta**: `302 /admin/login` + `session_destroy()`

---

#### POST `/admin/profile/update`

**Descrizione**: Aggiorna profilo utente (nome, email)

**Request Body**:
```
first_name=John
last_name=Doe
email=john@example.com
```

---

#### POST `/admin/profile/password`

**Descrizione**: Cambia password

**Request Body**:
```
current_password=OldPass
new_password=NewPass
confirm_password=NewPass
```

**Validazione**:
- Verify current password
- Match new + confirm
- Min length 8 chars
- Hash con Argon2id

---

### Albums CRUD

#### GET `/admin/albums`

**Descrizione**: Lista album (con paginazione)

**Query Parameters**:
- `page` (int, default 1)
- `limit` (int, default 10)

**Risposta**: HTML table con album, cover, status, actions

---

#### GET `/admin/albums/create`

**Descrizione**: Form creazione album

**Risposta**: HTML form

---

#### POST `/admin/albums`

**Descrizione**: Salva nuovo album

**Request Body** (multipart/form-data):
```
title=My New Album
slug=my-new-album
category_id=2
excerpt=Short description
body=<p>Full HTML description</p>
shoot_date=2024-01-15
is_published=1
password=OptionalPassword
template_id=1
```

**File Upload** (opzionale):
- `images[]`: Multiple images

**Validazione**:
- `title` required
- `slug` unique
- `category_id` exists

**Risposta**: `302 /admin/albums/{id}/edit`

---

#### GET `/admin/albums/{id}/edit`

**Descrizione**: Form modifica album

**Path Parameters**:
- `id` (int): Album ID

**Risposta**: HTML form + gallery immagini

---

#### POST `/admin/albums/{id}`

**Descrizione**: Aggiorna album

**Request Body**: Come POST `/admin/albums`

**Risposta**: `302 /admin/albums/{id}/edit` con success flash

---

#### POST `/admin/albums/{id}/delete`

**Descrizione**: Elimina album

**Cascading**: Elimina anche tutte le immagini associate (`ON DELETE CASCADE`)

**Risposta**: `302 /admin/albums`

---

#### POST `/admin/albums/{id}/publish`

**Descrizione**: Pubblica album

**Logica**:
```sql
UPDATE albums
SET is_published = 1,
    published_at = NOW()
WHERE id = ?
```

---

#### POST `/admin/albums/{id}/unpublish`

**Descrizione**: Nascondi album

**Logica**:
```sql
UPDATE albums
SET is_published = 0,
    published_at = NULL
WHERE id = ?
```

---

### Albums - Gestione Immagini

#### POST `/admin/albums/{id}/upload`

**Descrizione**: Upload immagini multiple

**Content-Type**: `multipart/form-data`

**Request**:
```
files[]: File1.jpg
files[]: File2.jpg
...
```

**Processo**:
1. Validazione file (MIME, magic number, size)
2. Hash SHA1 (deduplicazione)
3. Salva in `storage/originals/{hash}.{ext}`
4. Estrai EXIF
5. Crea preview (GD, 480px)
6. INSERT `images`
7. Ritorna JSON con IDs creati

**Risposta**:
```json
{
  "success": true,
  "uploaded": [
    {"id": 123, "filename": "File1.jpg"},
    {"id": 124, "filename": "File2.jpg"}
  ]
}
```

---

#### POST `/admin/albums/{id}/cover/{imageId}`

**Descrizione**: Imposta immagine di copertina

**Logica**:
```sql
UPDATE albums
SET cover_image_id = ?
WHERE id = ?
```

---

#### POST `/admin/albums/{id}/images/reorder`

**Descrizione**: Riordina immagini (drag-drop)

**Request Body**:
```json
{
  "order": [15, 23, 18, 42, ...]
}
```

**Logica**: Aggiorna `sort_order` per ogni ID

---

#### POST `/admin/albums/{id}/images/{imageId}/update`

**Descrizione**: Aggiorna metadati immagine

**Request Body**:
```
alt_text=Sunset in downtown
caption=Shot on Kodak Portra 400
camera_id=5
lens_id=12
film_id=3
iso=400
aperture=2.8
shutter_speed=1/250
process=film_scanned
```

---

#### POST `/admin/albums/{id}/images/{imageId}/delete`

**Descrizione**: Elimina singola immagine

**Cascading**: Elimina anche `image_variants`

**Fisica**: Rimuove file da `storage/originals/` e `public/media/`

---

#### POST `/admin/albums/{id}/images/bulk-delete`

**Descrizione**: Elimina multiple immagini

**Request Body**:
```json
{
  "image_ids": [15, 23, 42]
}
```

---

#### POST `/admin/albums/{id}/images/attach`

**Descrizione**: Associa immagini esistenti (media library)

**Request Body**:
```json
{
  "image_ids": [99, 105]
}
```

**Logica**: UPDATE `images` SET `album_id = ?`

---

#### POST `/admin/albums/{id}/tags`

**Descrizione**: Aggiorna tag album

**Request Body**:
```json
{
  "tag_ids": [1, 5, 8]
}
```

**Logica**:
1. DELETE FROM `album_tag` WHERE album_id = ?
2. INSERT INTO `album_tag` nuove associazioni

---

### Categories CRUD

#### GET `/admin/categories`
Lista categorie (gerarchia visualizzata)

#### POST `/admin/categories/reorder`
Riordina categorie (drag-drop)

#### POST `/admin/categories`
Crea nuova categoria

**Request Body**:
```
name=Street Photography
parent_id=null
description=Urban scenes...
```

#### POST `/admin/categories/{id}`
Aggiorna categoria

#### POST `/admin/categories/{id}/delete`
Elimina categoria

**Verifica**: No album associati (o reassign)

---

### Tags CRUD

Pattern standard CRUD:
- GET `/admin/tags` - List
- POST `/admin/tags` - Create
- POST `/admin/tags/{id}` - Update
- POST `/admin/tags/{id}/delete` - Delete

---

### Equipment CRUD

**Tabelle**: cameras, lenses, films, developers, labs

**Pattern identico per tutte**:
- GET `/admin/{entity}` - List
- GET `/admin/{entity}/create` - Form
- POST `/admin/{entity}` - Store
- GET `/admin/{entity}/{id}/edit` - Form
- POST `/admin/{entity}/{id}` - Update
- POST `/admin/{entity}/{id}/delete` - Delete

**Esempio Cameras**:

POST `/admin/cameras`:
```
make=Canon
model=EOS 5D Mark IV
```

---

### Settings

#### GET `/admin/settings`

**Risposta**: HTML form con settings raggruppati:
- Image Processing
- Site Info
- Performance

---

#### POST `/admin/settings`

**Request Body**:
```
image.formats[avif]=1
image.formats[webp]=1
image.quality[avif]=50
image.quality[webp]=75
image.breakpoints[sm]=768
image.breakpoints[md]=1200
...
site.title=My Portfolio
site.email=info@example.com
```

**Logica**: `SettingsService::set($key, $value)` per ogni campo

---

#### POST `/admin/settings/generate-images`

**Descrizione**: Esegue batch generation variants

**Processo**:
1. Esegue comando CLI `images:generate` in background
2. Ritorna immediate response con job ID
3. Progress trackabile via polling (future)

**Risposta**:
```json
{
  "status": "started",
  "message": "Image generation started in background"
}
```

---

### SEO Settings

#### POST `/admin/seo`

**Request Body**:
```
seo.site_title=My Photography
seo.og_site_name=John Doe Photography
seo.robots_default=index,follow
seo.author_name=John Doe
seo.photographer_job_title=Professional Photographer
```

---

#### POST `/admin/seo/sitemap`

**Descrizione**: Genera sitemap XML

**Output**:
- `public/sitemap.xml`
- `public/sitemap_index.xml`

**Include**:
- Home
- Album published
- Categories
- Tags

**Risposta**: Redirect con success flash

---

### Analytics

#### GET `/admin/analytics`

**Descrizione**: Dashboard analytics

**Risposta**: HTML con grafici (Chart.js)

**Dati visualizzati**:
- Sessions trend (ultimi 30 giorni)
- Top pages
- Top countries
- Device breakdown

---

#### GET `/admin/analytics/realtime`

**Descrizione**: Real-time visitors

**Polling**: JavaScript refresh ogni 5 sec

**Dati**:
- Active visitors (last 5 min)
- Current pages being viewed
- Geographic heatmap

---

#### GET `/admin/analytics/albums`

**Descrizione**: Stats per album

**Risposta**: HTML table ordinabile

**Colonne**:
- Album title
- Pageviews
- Unique visitors
- Avg time on page
- Downloads

---

#### GET `/admin/analytics/export`

**Descrizione**: Export dati CSV

**Query Parameters**:
- `date_from` (string): YYYY-MM-DD
- `date_to` (string): YYYY-MM-DD
- `type` (string): `sessions|pageviews|events`

**Risposta**: File CSV download

---

#### POST `/admin/analytics/cleanup`

**Descrizione**: Elimina dati vecchi

**Request Body**:
```
days=90
```

**Logica**: DELETE FROM analytics_* WHERE created_at < NOW() - INTERVAL 90 DAY

---

#### GET `/api/admin/analytics/charts`

**Descrizione**: Dati JSON per grafici

**Autenticazione**: AuthMiddleware

**Query Parameters**:
- `period` (string): `7days|30days|90days|year`

**Risposta**:
```json
{
  "sessions": {
    "labels": ["2024-01-01", "2024-01-02", ...],
    "data": [45, 52, 38, ...]
  },
  "pageviews": {
    "labels": [...],
    "data": [...]
  },
  "top_pages": [
    {"url": "/album/landscapes", "views": 523},
    ...
  ]
}
```

---

#### GET `/api/admin/analytics/realtime`

**Descrizione**: Real-time data JSON

**Autenticazione**: AuthMiddleware

**Risposta**:
```json
{
  "active_visitors": 12,
  "current_pages": [
    {"url": "/", "count": 5},
    {"url": "/album/urban", "count": 3}
  ]
}
```

---

### Diagnostics

#### GET `/admin/diagnostics`

**Descrizione**: System health check

**Risposta**: HTML report con:

**Database**:
- Connection status
- Driver (SQLite/MySQL)
- Version

**PHP**:
- Version
- Extensions (GD, Imagick, EXIF)
- Memory limit
- Max upload size

**Storage**:
- `storage/originals/` writable
- `storage/tmp/` writable
- `public/media/` writable
- Disk space available

**Security**:
- `disable_functions` check
- `open_basedir` status

**Color-coded**: Green (OK), Yellow (Warning), Red (Error)

---

### Commands

#### GET `/admin/commands`

**Descrizione**: Lista comandi CLI eseguibili

**Risposta**: HTML lista comandi disponibili

---

#### POST `/admin/commands/execute`

**Descrizione**: Esegue comando CLI

**Request Body**:
```json
{
  "command": "images:generate",
  "args": ["--missing"]
}
```

**Processo**:
1. Valida comando whitelist
2. Esegue `exec("php bin/console {command} {args}")`
3. Cattura output

**Risposta**:
```json
{
  "success": true,
  "output": "Generated 15 images\nDone."
}
```

---

## Gestione Errori

### HTTP Status Codes

| Status | Significato | Uso |
|--------|-------------|-----|
| 200 | OK | Request successo |
| 201 | Created | Risorsa creata |
| 204 | No Content | Success senza body |
| 302 | Found | Redirect (POST success) |
| 400 | Bad Request | Validazione fallita |
| 401 | Unauthorized | No autenticazione |
| 403 | Forbidden | No permessi |
| 404 | Not Found | Risorsa non esiste |
| 422 | Unprocessable Entity | Validazione semantic |
| 429 | Too Many Requests | Rate limit exceeded |
| 500 | Internal Server Error | Errore server |

### Error Response Format (JSON)

```json
{
  "error": true,
  "message": "Validation failed",
  "errors": {
    "email": ["Invalid email format"],
    "password": ["Password too short"]
  }
}
```

### Error Pages (HTML)

- 404: `app/Views/errors/404.twig`
- 500: `app/Views/errors/500.twig`

---

## Versioning

**Attuale**: v1 (no explicit versioning in URL)

**Future**: `/api/v2/*` quando breaking changes

---

## CORS

**Default**: Same-origin only

**Headers** (se CORS abilitato future):
```
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, POST, PUT, DELETE
Access-Control-Allow-Headers: Content-Type, Authorization
```

---

## Best Practices per Consumatori API

### 1. Autenticazione Admin

```javascript
// Login
const login = async (email, password) => {
  const response = await fetch('/admin/login', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams({email, password, csrf: getCsrfToken()}),
    credentials: 'include' // Importante per session cookie
  });
  return response.ok;
};

// API call autenticata
const fetchAlbums = async () => {
  const response = await fetch('/admin/api/albums', {
    credentials: 'include' // Include session cookie
  });
  return await response.json();
};
```

### 2. Upload Immagini

```javascript
const uploadImages = async (albumId, files) => {
  const formData = new FormData();
  files.forEach(file => formData.append('files[]', file));

  const response = await fetch(`/admin/albums/${albumId}/upload`, {
    method: 'POST',
    body: formData,
    credentials: 'include'
  });

  return await response.json();
};
```

### 3. Tracking Analytics

```javascript
const trackPageview = async (data) => {
  await fetch('/api/analytics/track', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      session_id: getSessionId(),
      page_url: window.location.pathname,
      page_type: 'album',
      album_id: data.albumId,
      viewport_width: window.innerWidth,
      viewport_height: window.innerHeight,
      ...data
    })
  });
};
```

### 4. Handle Rate Limiting

```javascript
const fetchWithRetry = async (url, options, maxRetries = 3) => {
  for (let i = 0; i < maxRetries; i++) {
    const response = await fetch(url, options);

    if (response.status !== 429) {
      return response;
    }

    // Exponential backoff
    await new Promise(resolve => setTimeout(resolve, Math.pow(2, i) * 1000));
  }

  throw new Error('Rate limit exceeded');
};
```

---

## Prossimi Passi

- **[Sicurezza](./security.md)**: CSRF, XSS, SQL Injection prevention
- **[Guida Sviluppo](./development.md)**: Setup locale, testing API
- **[Database](./database.md)**: Schema completo per query custom

---

**Ultimo aggiornamento**: 17 Novembre 2025
**Versione**: 1.0.0
