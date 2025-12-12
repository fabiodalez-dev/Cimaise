# Guida Completa - photoCMS

Questa guida copre tutte le funzionalità di photoCMS: frontend, admin panel, gestione album, impostazioni, analytics e troubleshooting.

---

# PARTE 1: Funzionalità Frontend

## Home Page

### Hero Section
- Titolo sito principale
- Sottotitolo/descrizione
- Configurabile in **Admin → Settings**

### Infinite Gallery
**Caratteristiche**:
- 3 colonne desktop, 2 tablet, 1 mobile
- Scorrimento automatico verticale
- Random order (immagini shuffled ogni caricamento)
- Hover pause (mouse over ferma scroll)
- Drag verticale per controllo manuale

**Come funziona**:
- Raccoglie max 150 immagini da tutti gli album published
- Divide in 3 colonne con velocità diverse
- Loop seamless (immagini duplicate per continuità)

### Albums Carousel
**Caratteristiche**:
- Scorrimento orizzontale album recenti
- Autoplay desktop (pausa su hover)
- Drag/swipe mobile
- Click album → Apre pagina album

## Pagina Album

**URL**: `/album/{slug}`

**Elementi**:
- **Breadcrumb**: Home > Categoria > Album
- **Titolo** + **Data scatto** (se abilitata)
- **Descrizione completa** (HTML formattato)
- **Galleria immagini** (grid responsive)
- **Tag** (footer)

**Interazioni**:
- Click foto → Lightbox fullscreen
- Lightbox controls: Zoom, Prev/Next, Fullscreen, Close
- Swipe touch (mobile)
- Download (se abilitato admin)

**Password Protected**:
- Se album ha password → Mostra form
- Inserisci password → Accesso 24h (session)

## Navigazione Categorie

**URL**: `/category/{slug}`

**Visualizzazione**:
- Titolo categoria
- Descrizione (se presente)
- Grid album appartenenti
- Include sottocategorie (se gerarchia)

**Filtri**:
- Solo album published
- Ordinati per data pubblicazione (recenti first)

## Navigazione Tag

**URL**: `/tag/{slug}`

**Visualizzazione**:
- Tag name
- Count album (es. "12 album con questo tag")
- Grid album taggati

## Gallerie con Filtri Avanzati

**URL**: `/galleries`

**Filtri Disponibili** (sidebar):
- Categoria (dropdown)
- Tag (multi-select)
- Fotocamera (dropdown)
- Lente (dropdown)
- Pellicola (dropdown)
- Range date (from/to)

**Funzionamento**:
- Seleziona filtri → Click "Applica"
- Refresh AJAX (no reload pagina)
- Grid album filtrati
- Reset filtri button

**Configurazione**: Admin → Filter Settings (abilita/disabilita filtri)

## Pagina About

**URL**: `/about`

**Contenuto**:
- Testo rich HTML (configurabile admin)
- Form contatto:
  - Nome
  - Email
  - Messaggio
  - Rate-limited (5 submit / 10 min)

**Submit**:
- Email inviata a indirizzo configurato in Settings
- Redirect con messaggio success/error

## Download Immagini

**URL**: `/download/image/{id}?variant=lg&format=jpg`

**Opzioni**:
- **Originale**: Full resolution
- **Variant**: sm/md/lg/xl/xxl
- **Format**: avif/webp/jpg

**Protezione**:
- Verifica album published
- Verifica password (se album protected)
- Tracking download in analytics

---

# PARTE 2: Pannello Amministrativo

## Login

**URL**: `/admin/login`

**Credenziali**:
- Email utente admin
- Password

**Security**:
- Rate limit: 5 tentativi / 10 min
- CSRF protection
- Session timeout: 30 min inattività

## Dashboard

**Statistiche**:
- 📁 Album totali
- 🖼️ Immagini totali
- 👁️ Visualizzazioni oggi
- 📈 Trend settimanale

**Quick Actions**:
- Crea Album
- Upload Immagini
- Visualizza Sito
- Impostazioni

## Menu Principale

### Content Management
- **Albums** - Gestione album fotografici
- **Categories** - Organizzazione categorie
- **Tags** - Gestione tag
- **Media** - Libreria media

### Configuration
- **Settings** - Impostazioni generali e immagini
- **SEO** - Ottimizzazione motori ricerca
- **Social** - Links social media
- **Filter Settings** - Config filtri galleries

### Analytics & Monitoring
- **Analytics** - Statistiche visitatori
- **Diagnostics** - Salute sistema

### Equipment & Data
- **Cameras** - Database fotocamere
- **Lenses** - Database lenti
- **Films** - Database pellicole
- **Developers** - Sviluppatori pellicola
- **Labs** - Laboratori scansione
- **Locations** - Luoghi geografici
- **Templates** - Layout album custom

### System
- **Users** - Gestione utenti admin
- **Commands** - Esecuzione comandi CLI
- **Pages** - Edit pagine statiche (About, Galleries)

---

# PARTE 3: Gestione Album Avanzata

## Creazione Album Completa

### Informazioni Base

**Campi Obbligatori**:
- **Titolo**: Nome album (es. "Urban Landscapes 2024")
- **Slug**: URL-friendly (auto da titolo, modificabile)
- **Categoria**: Seleziona da dropdown

**Campi Opzionali**:
- **Excerpt**: Breve descrizione (2-3 righe)
- **Body**: Descrizione completa HTML (TinyMCE editor)
- **Data Scatto**: Quando sono state scattate le foto
- **Location**: Dove (se configurato)
- **Template**: Layout custom (se creati template)

### Upload Immagini

**Metodi**:
1. **Drag & Drop**: Trascina file nell'area upload
2. **Click Select**: Click per aprire file browser
3. **Attach Existing**: Link immagini già nella media library

**Validazione**:
- Formati: JPEG, PNG, WebP, GIF
- Max size: 50MB per file
- Verifiche: MIME type, magic number, getimagesize

**Processo Upload**:
```
1. Client upload → /admin/albums/{id}/upload
2. Server valida file
3. Calcola SHA1 hash (deduplicazione)
4. Salva storage/originals/{hash}.{ext}
5. Estrai EXIF metadata
6. Crea preview 480px (GD)
7. INSERT database (images table)
8. Ritorna JSON con image ID
```

### Gestione Immagini Album

**Azioni Disponibili**:

**Reorder** (Drag-Drop):
- Trascina thumbnails per riordinare
- Ordine salvato automaticamente via AJAX
- Rifletterà nel frontend

**Set Cover**:
- Click stella ⭐ sulla foto
- Diventa immagine di copertina
- Mostrata in liste album, carousel, social sharing

**Edit Metadata**:
```
- Alt Text: "Bride and groom walking in vineyard"
- Caption: "Captured at golden hour in Tuscany"
- Camera: Canon EOS 5D Mark IV (select)
- Lens: Canon EF 50mm f/1.8 (select)
- Film: Kodak Portra 400 (select, solo analogico)
- ISO: 400
- Aperture: 2.8
- Shutter Speed: 1/250
- Process: digital/analog/film_scanned
```

**Delete Single**:
- Click 🗑️ Elimina
- Conferma
- Rimuove da DB + filesystem (variants incluse)

**Bulk Delete**:
- Seleziona multiple (checkbox)
- Click "Elimina Selezionate"
- Conferma
- Batch delete

**Attach Existing**:
- Click "Aggiungi da Media Library"
- Select immagini da dialog
- Link a questo album (UPDATE album_id)

### Publishing

**Stati Album**:
- **Draft** (is_published = 0): Non visibile frontend
- **Published** (is_published = 1): Visibile pubblicamente

**Publish**:
- Checkbox "Pubblicato" ON
- Salva → Sets published_at = NOW()

**Schedule Publishing** (future):
- Imposta published_at futuro
- Cron job pubblica automaticamente

**Unpublish**:
- Checkbox "Pubblicato" OFF
- Salva → Sets published_at = NULL

### Password Protection

**Setup**:
- Campo "Password Album": Inserisci password
- Salva → Hash bcrypt in password_hash column

**Comportamento Frontend**:
- Utente accede `/album/protected-album`
- Vede form password
- POST `/album/protected-album/unlock`
- Password corretta → Session 24h
- Accesso granted

**Rimuovi Password**:
- Cancella campo password
- Salva → NULL in password_hash

### Template Switching

**Default**: Layout standard 3 colonne responsive

**Custom Template**:
- Seleziona da dropdown "Template"
- Applica layout configurato in template settings (JSON)
- Può includere: colonne custom, spacing, lightbox options, JS libs

**Query Param Override** (testing):
```
/album/my-album?template=2
```
Applica temporaneamente template ID 2 (non salvato)

### Tags Assignment

**Add Tags**:
- Campo autocomplete
- Digita nome → Suggerisce esistenti
- Seleziona da dropdown o crea nuovo
- Multi-select
- Salva → INSERT album_tag junction

**Remove Tags**:
- Click ❌ sul tag
- Auto-save rimuove associazione

---

# PARTE 4: Impostazioni Complete

## Site Settings

**Menu: Admin → Settings → Site Settings**

**Campi**:
```
Titolo Sito: "John Doe Photography"
Descrizione: "Professional Wedding & Portrait Photographer based in NYC"
Email Contatto: "info@johndoe.com"
Copyright: "© 2024 John Doe. All rights reserved."
```

**Logo Upload**:
- Click "Choose File"
- Seleziona PNG/JPG (raccomandato PNG con trasparenza)
- Max 2MB
- Upload automatico
- Mostrato header frontend

## Image Processing Settings

**Menu: Admin → Settings → Image Processing**

### Formati Abilitati

**AVIF** ✅ (raccomandato):
- Formato moderno, alta compressione
- ~50% size vs JPEG stessa qualità
- Supporto: Chrome 85+, Firefox 93+, Safari 16+

**WebP** ✅:
- Fallback moderno
- ~30% size vs JPEG
- Supporto: Tutti browser moderni

**JPEG** ✅:
- Fallback universale
- Compatibilità 100%

**Rendering**:
```html
<picture>
  <source type="image/avif" srcset="...">
  <source type="image/webp" srcset="...">
  <img src="fallback.jpg" srcset="...">
</picture>
```
Browser sceglie automaticamente miglior formato supportato.

### Qualità Compressione

**AVIF**: `50` (range 0-100)
- Basso numero = alta compressione, leggermente loss quality
- 50 ottimale: Invisibile differenza, massive size saving

**WebP**: `75`
- Balance qualità/dimensione
- 75-80 raccomandato

**JPEG**: `85`
- Standard professionale
- 85-90 raccomandato fotografi

**Test**: Cambia valori, rigenera immagini, compara visivamente

### Breakpoints Responsive

**Predefiniti**:
```
sm:   768px  (mobile)
md:  1200px  (tablet)
lg:  1920px  (desktop Full HD)
xl:  2560px  (4K displays)
xxl: 3840px  (8K/future-proof)
```

**Custom**: Modifica se hai esigenze specifiche

**Risultato**: 5 dimensioni × 3 formati = **15 file per immagine**

### Preview Settings

**Width**: `480px` (thumbnail admin panel)
**Height**: Auto (mantiene aspect ratio)

### Generate Images

**Button**: "Genera Immagini"

**Azione**: Esegue `php bin/console images:generate`

**Processo**:
1. Query tutte immagini senza varianti (o --missing)
2. Per ogni immagine:
   - Carica originale (Imagick preferito, GD fallback)
   - Per ogni breakpoint (sm/md/lg/xl/xxl):
     - Resize proporzionale (max width = breakpoint)
     - Per ogni formato (avif/webp/jpg):
       - Converti formato
       - Comprimi con qualità impostata
       - Salva `public/media/{hash}_{variant}_{format}.{ext}`
       - INSERT image_variants (path, size_bytes, dimensions)
3. Log output

**Durata**: ~1-2 sec/immagine (dipende CPU)

**Quando eseguire**:
- Dopo upload nuove immagini
- Dopo modifica impostazioni qualità/formati
- Dopo modifica breakpoints
- Se varianti mancanti (disk cleanup)

## SEO Settings

**Menu: Admin → SEO**

### Basic SEO

**Site Title**: `John Doe Photography - Wedding Photographer NYC`
- Meta tag `<title>` homepage
- Max ~60 caratteri (SERP visibility)

**OG Site Name**: `John Doe Photography`
- Open Graph per social sharing
- Facebook, Twitter, LinkedIn

**Robots Default**: `index,follow`
- Permette indicizzazione Google
- Alternative: `noindex,nofollow` (staging)

### Author & Schema.org

**Author Name**: `John Doe`
**Author URL**: `https://johndoe.com`
**Organization Name**: `John Doe Photography Studio`
**Organization URL**: `https://johndoe.com`

**Photographer Job Title**: `Professional Photographer`
**Photographer Services**: `Wedding Photography, Portrait Photography`
**Photographer SameAs**: `https://instagram.com/johndoephoto`

**Output**: JSON-LD structured data per rich snippets

### Canonical URL

**Canonical Base URL**: `https://johndoe.com`
- Importante se subdirectory: `https://example.com/photos`
- Previene duplicate content SEO penalty

### Sitemap Generation

**Button**: "Genera Sitemap"

**Output Files**:
- `public/sitemap.xml` - Sitemap principale
- `public/sitemap_index.xml` - Index (se multi-sitemap)

**Include**:
- Homepage (priority 1.0)
- Album published (priority 0.8)
- Categorie (priority 0.6)
- Tag (priority 0.5)

**Formato**:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc>https://johndoe.com/</loc>
    <lastmod>2024-01-15</lastmod>
    <changefreq>weekly</changefreq>
    <priority>1.0</priority>
  </url>
  <url>
    <loc>https://johndoe.com/album/wedding-sarah-tom</loc>
    <lastmod>2024-01-10</lastmod>
    <changefreq>monthly</changefreq>
    <priority>0.8</priority>
  </url>
</urlset>
```

**Submit Google**:
1. Google Search Console
2. Sitemap → Aggiungi `https://johndoe.com/sitemap.xml`
3. Submit

**Rigenera**: Quando aggiungi/modifichi album, categorie, tag

## Social Settings

**Menu: Admin → Social**

**Platforms**:
- Instagram: URL + Handle
- Twitter: URL + Handle
- Facebook: URL
- LinkedIn: URL

**Esempio**:
```
Instagram URL: https://instagram.com/johndoephoto
Instagram Handle: @johndoephoto

Twitter URL: https://twitter.com/johndoephoto
Twitter Handle: @johndoephoto
```

**Output**: Links in footer frontend, meta tags social

## Filter Settings

**Menu: Admin → Filter Settings**

**Abilita/Disabilita** filtri in `/galleries`:

```
✅ Mostra Filtro Categoria
✅ Mostra Filtro Tag
✅ Mostra Filtro Fotocamera
✅ Mostra Filtro Lente
✅ Mostra Filtro Pellicola
✅ Mostra Filtro Range Date
❌ Mostra Filtro Geolocalizzazione (future)
```

**Preview**: Click "Preview" → Apre `/galleries` in new tab

**Reset**: Click "Reset Defaults" → Ripristina tutti ON

## Performance Settings

**Menu: Admin → Settings → Performance**

**Compression**: `On` (Gzip/Brotli)
**Pagination Limit**: `12` (album per pagina)
**Cache TTL**: `24` ore (future: query cache)

---

# PARTE 5: Analytics

## Overview Dashboard

**Menu: Admin → Analytics**

### Today Stats

**Cards**:
- 👁️ **Visitatori Oggi**: Unique visitors (IP hash based)
- 📄 **Pageviews Oggi**: Totale pagine viste
- ⏱️ **Tempo Medio**: Avg session duration
- 📉 **Bounce Rate**: % visitatori single-page

### Charts

**Sessions Trend** (Chart.js line chart):
- X-axis: Date (ultimi 30 giorni)
- Y-axis: Numero sessioni
- Tooltip: Hover mostra data + numero

**Device Breakdown** (Pie chart):
- Desktop: 65%
- Mobile: 30%
- Tablet: 5%

**Top Pages** (Bar chart):
- /album/wedding-sarah → 523 views
- / → 412 views
- /album/portraits → 305 views

**Top Countries** (Map + List):
- 🇮🇹 Italy: 234 sessions
- 🇺🇸 USA: 189 sessions
- 🇬🇧 UK: 156 sessions

## Real-Time Analytics

**Menu: Admin → Analytics → Real-Time**

**Polling**: Refresh automatico ogni 5 sec

**Active Visitors**: `12 visitatori attivi ora`
**List**:
- Page URL
- Country
- Device
- Timestamp

**Definition**: Attivo = ultimo activity < 5 min

## Analytics Per Album

**Menu: Admin → Analytics → Albums**

**Table**:
| Album | Pageviews | Unique Visitors | Avg Time | Downloads |
|-------|-----------|-----------------|----------|-----------|
| Wedding Sarah | 523 | 412 | 3m 45s | 23 |
| Portraits 2024 | 305 | 267 | 2m 12s | 8 |

**Sort**: Click colonna header

**Filter**: Search box, date range

## Export Data

**Menu: Admin → Analytics → Export**

**Options**:
- **Date Range**: From/To
- **Data Type**: Sessions, Pageviews, Events
- **Format**: CSV

**Click Export** → Download file

**Columns** (esempio Sessions CSV):
```csv
session_id,ip_hash,country,device_type,started_at,page_views,duration
abc123,sha256hash,IT,desktop,2024-01-15 10:30:00,5,245
```

## Analytics Settings

**Menu: Admin → Analytics → Settings**

**Tracking**:
```
✅ Analytics Enabled
✅ IP Anonymization (GDPR)
✅ Geolocation Tracking
✅ Bot Detection
✅ Real-Time Tracking
```

**Data Retention**: `365` giorni
- Vecchi dati auto-deleted

**Session Timeout**: `30` minuti
- Inattività > 30 min = nuova sessione

**Export Enabled**: `On`

## Cleanup Data

**Menu: Admin → Analytics → Cleanup**

**Manuale**:
- Seleziona giorni (es. 90)
- Click "Elimina Dati Vecchi"
- DELETE FROM analytics_* WHERE created_at < 90 days ago

**Auto** (cron raccomandato):
```bash
php bin/console analytics:cleanup --days 365
```

## Privacy & GDPR

**Compliance**:
- ✅ IP anonimizzati (SHA256 hash + salt)
- ✅ No cookie tracking (fingerprinting session)
- ✅ Data retention configurabile
- ✅ Export dati (diritto portabilità)
- ✅ Cleanup data (diritto oblio)

**Cookie Banner**: Implementare frontend (non incluso core)

---

# PARTE 6: Equipment Management

## Cameras Database

**Menu: Admin → Cameras**

**CRUD**:
- **List**: Tabella fotocamere
- **Create**: Aggiungi nuova
  - Make: "Canon"
  - Model: "EOS 5D Mark IV"
- **Edit**: Modifica esistente
- **Delete**: Rimuovi (solo se non usata in immagini)

**Uso**: Dropdown in edit immagine metadati

## Lenses Database

**Menu: Admin → Lenses**

**CRUD**: Identico a Cameras
- Make: "Canon"
- Model: "EF 50mm f/1.8 STM"

## Films Database

**Menu: Admin → Films**

**CRUD**:
- Brand: "Kodak"
- Name: "Portra 400"

**Tip**: Aggiungi pellicole usate frequentemente

## Developers Database

**Menu: Admin → Developers**

**CRUD**:
- Brand: "Ilford"
- Name: "Ilfosol 3"

## Labs Database

**Menu: Admin → Labs**

**CRUD**:
- Name: "Carmencita Film Lab"
- City: "Valencia"
- Country: "Spain"

**Uso**: Tracking laboratorio per scansioni

## Custom Fields

Se camera/lens/film non in database:

**Edit Immagine** → Campo custom:
- **Custom Camera**: "Leica M6"
- **Custom Lens**: "Summicron 50mm f/2"
- **Custom Film**: "Cinestill 800T"

Database lookup ha priorità, custom come fallback.

---

# PARTE 7: Troubleshooting

## Problemi Comuni

### 1. Login Failed

**Sintomo**: "Email o password errati"

**Cause**:
- Credenziali sbagliate
- User inattivo (is_active = 0)
- Rate limit (troppi tentativi)

**Soluzioni**:
- Verifica credenziali (case-sensitive)
- Attendi 10 min se rate-limited
- Reset password via CLI:
```bash
php bin/console user:update --email=admin@example.com --password=NewPassword
```

### 2. Immagini Non Visualizzate

**Sintomo**: Placeholder o 404 su immagini

**Cause**:
- Varianti non generate
- Permessi storage/media
- Path errato

**Soluzioni**:
```bash
# Genera varianti
php bin/console images:generate

# Fix permessi
chmod -R 775 storage/ public/media/

# Check esistenza file
ls -la public/media/
```

### 3. Upload Failed

**Sintomo**: "Upload error" o timeout

**Cause**:
- File troppo grande
- Formato non supportato
- Permessi storage
- PHP limits

**Soluzioni**:
```bash
# Check permessi
ls -la storage/originals/
chmod 775 storage/originals/

# Aumenta PHP limits
# php.ini:
upload_max_filesize = 50M
post_max_size = 50M
max_execution_time = 300
memory_limit = 256M

# Restart server
sudo systemctl restart apache2
```

### 4. 500 Internal Server Error

**Sintomo**: Pagina bianca o "500 Error"

**Diagnosi**:
```bash
# Check error log
tail -f /var/log/apache2/error.log
# o
tail -f /var/log/php_errors.log

# Enable debug temporaneamente
# .env:
APP_DEBUG=true
```

**Cause comuni**:
- Syntax error PHP
- Missing dependency
- Database connection failed
- Permessi file

### 5. Slow Performance

**Sintomo**: Sito lento

**Diagnosi**:
```bash
# Check server resources
top
df -h  # Disk space
```

**Ottimizzazioni**:
```bash
# Enable OPcache
# php.ini:
opcache.enable=1

# Compress images (ridurre qualità settings)
# Admin → Settings → Image Quality → 70

# CDN (future)

# Database indexes
# Già creati da migrations, verifica:
SHOW INDEX FROM albums;
```

### 6. Analytics Non Tracciando

**Sintomo**: Dati analytics vuoti

**Cause**:
- Analytics disabled
- JavaScript bloccato (AdBlock)
- Rate limit

**Soluzioni**:
```bash
# Check settings
Admin → Analytics → Settings → Analytics Enabled ✅

# Check browser console (F12)
# Cerca errori POST /api/analytics/track

# Whitelist in AdBlock
```

### 7. Sitemap 404

**Sintomo**: `/sitemap.xml` non trovato

**Causa**: Non generato

**Soluzione**:
```bash
# Admin
Admin → SEO → Genera Sitemap

# o CLI
php bin/console sitemap:generate

# Verifica esistenza
ls -la public/sitemap.xml
```

### 8. Email Form Contatto Non Inviate

**Cause**:
- Mail server non configurato
- Rate limit
- SMTP credenziali errate

**Soluzioni**:
```bash
# Verifica PHP mail()
php -r "mail('test@test.com', 'Test', 'Body');"

# Se fail, configura SMTP
# Installa PHPMailer (future)

# Check rate limit
# Max 5 submit / 10 min
```

## Diagnostica Sistema

**Menu: Admin → Diagnostics**

**Verifica**:
- ✅ Database Connection: OK
- ✅ PHP Version: 8.2.5
- ✅ GD Extension: Installed
- ⚠️ Imagick: Not installed (raccomandato ma opzionale)
- ✅ Storage Writable: Yes
- ✅ Disk Space: 45GB available
- ✅ Memory Limit: 256M

**Color Codes**:
- 🟢 Green: OK
- 🟡 Yellow: Warning (non critico)
- 🔴 Red: Error (azione richiesta)

## Logs

**Locations**:
```bash
# Apache
/var/log/apache2/photocms_error.log

# Nginx
/var/log/nginx/error.log

# PHP
/var/log/php_errors.log

# Application (future)
storage/logs/app.log
```

**Monitoring**:
```bash
# Tail real-time
tail -f /var/log/apache2/photocms_error.log

# Cerca errori recenti
grep "500" /var/log/apache2/photocms_error.log | tail -20
```

## Ottenere Supporto

Se non riesci a risolvere:

1. **Check Documentazione**: Questa guida + docs technical
2. **Search Issues**: GitHub repository issues
3. **Forum Community**: [link se disponibile]
4. **Email Support**: [email supporto]

**Quando chiedi aiuto, includi**:
- Versione photoCMS
- PHP version (`php -v`)
- Error message completo
- Steps per riprodurre
- Screenshot (se UI issue)

---

**Versione Guida**: 1.0.0
**Ultimo Aggiornamento**: 17 Novembre 2025

✅ **Complimenti!** Ora conosci tutte le funzionalità di photoCMS! 📸
