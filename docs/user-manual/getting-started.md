# Primi Passi - Cimaise

Questa guida ti accompagna nei primi passi con Cimaise: dal primo login alla pubblicazione del tuo primo album.

---

## 1. Primo Login Admin

### Accedi al Pannello

Vai a: `https://tuosito.com/admin/login`

**Credenziali**:
- Email: Quella inserita durante installazione
- Password: Quella creata durante setup

Click **"Login"**

### Dashboard Overview

Dopo il login vedrai la **Dashboard** con:
- 📊 **Statistiche**: Album totali, immagini totali, ultimi upload
- 📈 **Analytics**: Visitatori oggi, pagine viste
- ⚡ **Quick Actions**: Crea album, Upload immagini, Impostazioni

**Menu Principale** (sidebar):
- Dashboard
- **Albums** (gestisci album)
- Categories (organizza categorie)
- Tags (gestisci tag)
- **Settings** (impostazioni)
- SEO (ottimizzazione motori ricerca)
- **Analytics** (statistiche visitatori)
- Media (libreria media)
- Equipment (fotocamere, lenti, pellicole)
- Users (gestisci utenti admin)
- Diagnostics (verifica sistema)

---

## 2. Crea il Tuo Primo Album

### Step 1: Vai a Albums

Click **"Albums"** nel menu → **"Crea Nuovo"**

### Step 2: Informazioni Base

Compila il form:

**Titolo***:
```
Il Mio Primo Album
```

**Slug** (auto-generato da titolo):
```
il-mio-primo-album
```
*URL sarà: tuosito.com/album/il-mio-primo-album*

**Categoria**:
Seleziona una categoria esistente (es. "Portfolio")

**Excerpt** (breve descrizione):
```
Una collezione delle mie foto preferite del 2024.
```

**Descrizione Completa** (editor rich-text):
```
Questo album raccoglie le mie foto preferite scattate durante
l'anno 2024, spaziando da paesaggi urbani a ritratti.

Attrezzatura utilizzata: Canon EOS 5D Mark IV, varie lenti.
```

**Data Scatto**:
Seleziona data (es. `2024-06-15`)

### Step 3: Upload Immagini

**Metodo A: Drag & Drop**

1. Trascina le tue foto nell'area "Drop files here"
2. Le immagini si caricheranno automaticamente
3. Vedrai una barra di progresso
4. Al termine: "Upload completato! 10 immagini caricate"

**Metodo B: Click per Selezionare**

1. Click "Seleziona File"
2. Scegli immagini multiple (Ctrl+Click o Shift+Click)
3. Click "Apri"
4. Upload automatico

**Formati Supportati**: JPEG, PNG, WebP, GIF

**Dimensione Max**: 50MB per file

### Step 4: Organizza Immagini

Dopo upload vedrai thumbnails delle immagini:

**Riordina** (drag-drop):
- Trascina immagini per riordinare
- L'ordine verrà salvato automaticamente

**Imposta Cover**:
- Click stella ⭐ sulla foto che vuoi come copertina
- Diventerà l'immagine rappresentativa dell'album

**Modifica Metadati**:
- Click "✏️ Modifica" su un'immagine
- Compila:
  - **Alt Text**: Descrizione per accessibilità
  - **Caption**: Didascalia foto
  - **Camera**: Seleziona (es. "Canon EOS 5D Mark IV")
  - **Lens**: Seleziona (es. "Canon EF 50mm f/1.8")
  - **ISO**: Es. `400`
  - **Aperture**: Es. `2.8`
  - **Shutter Speed**: Es. `1/250`
- Click "Salva"

**Elimina**:
- Click "🗑️ Elimina" per rimuovere una foto

### Step 5: Impostazioni Album

**Pubblicato**:
- ✅ Spunta "Pubblicato" per rendere visibile nel frontend
- ❌ Lascia deselezionato per tenere come bozza

**Password Protection** (opzionale):
- Inserisci password se vuoi proteggere l'album
- I visitatori dovranno inserirla per visualizzare

**Template**:
- Seleziona layout (se hai creato template custom)
- Default: Layout standard 3 colonne

**Mostra Data**:
- ✅ Spunta per mostrare data scatto nel frontend

### Step 6: Tag

Aggiungi tag all'album:
- Digita nome tag (es. "Ritratti", "Analogico", "B&W")
- Premi Enter
- Tag esistenti vengono suggeriti automaticamente
- Click "Aggiungi" per creare nuovo tag

### Step 7: Salva

Click **"Salva Album"**

✅ Album creato con successo!

---

## 3. Visualizza il Frontend

### Apri il Sito

Click **"Visualizza Sito"** (icona 🌐 in alto) o vai a:
```
https://tuosito.com
```

### Home Page

Vedrai:
- **Hero Section**: Titolo sito + descrizione
- **Infinite Gallery**: Scorrimento automatico colonne immagini
- **Albums Carousel**: Album recenti con autoplay

### Naviga all'Album

**Metodo A**: Click immagine nella gallery
→ Porta all'album di appartenenza

**Metodo B**: Click album nel carousel
→ Apre album completo

**Metodo C**: URL diretto
```
https://tuosito.com/album/il-mio-primo-album
```

### Pagina Album

Vedrai:
- Titolo album
- Data scatto (se abilitata)
- Breadcrumb (Home > Categoria > Album)
- Descrizione completa
- **Galleria immagini** (grid responsive)
- Tag in fondo

**Interazioni**:
- **Click foto** → Apre lightbox (zoom, naviga, fullscreen)
- **Swipe** (mobile) → Scorri tra foto nel lightbox
- **Download** → Click icona download (se abilitato)

---

## 4. Organizza con Categorie

### Crea Categoria

**Menu Admin → Categories → Crea Nuova**

**Nome**: `Ritratti`
**Slug**: `ritratti` (auto)
**Parent**: Nessuno (categoria principale)
**Descrizione**: `Album di ritratti professionali`
**Ordinamento**: `0` (default)

Click **"Salva"**

### Crea Sottocategoria

**Crea Nuova**

**Nome**: `Ritratti Studio`
**Parent**: `Ritratti` ← Seleziona parent
**Slug**: `ritratti-studio`

Ora hai gerarchia: **Ritratti → Ritratti Studio**

### Assegna Album a Categoria

**Edit Album** → **Categoria** dropdown → Seleziona `Ritratti Studio`

Ora l'album è navigabile via:
```
tuosito.com/category/ritratti-studio
```

---

## 5. Aggiungi Tag

### Crea Tag

**Menu Admin → Tags → Crea Nuovo**

**Nome**: `Analogico`
**Slug**: `analogico` (auto)

**Nome**: `35mm`
**Slug**: `35mm`

### Assegna Tag ad Album

**Edit Album** → Campo **Tags**:
- Digita "Analogico" → Select da dropdown
- Digita "35mm" → Select
- (Oppure crea nuovo al volo)

Ora album navigabile via:
```
tuosito.com/tag/analogico
tuosito.com/tag/35mm
```

---

## 6. Personalizza Impostazioni Base

### Site Settings

**Menu Admin → Settings → Site Settings**

**Titolo Sito**: `John Doe Photography`
**Descrizione**: `Professional Wedding & Portrait Photographer`
**Email**: `info@johndoe.com`
**Copyright**: `© 2024 John Doe. All rights reserved.`

**Upload Logo**:
- Click "Choose File"
- Seleziona logo (PNG con trasparenza raccomandato)
- Upload automatico
- Verrà mostrato nell'header

### Image Processing

**Menu Admin → Settings → Image Processing**

**Formati**:
- ✅ AVIF (raccomandato, alta compressione)
- ✅ WebP (fallback moderno)
- ✅ JPEG (fallback universale)

**Qualità** (0-100):
- AVIF: `50` (bassa numero = alta compressione)
- WebP: `75`
- JPEG: `85`

**Breakpoints** (lascia default):
- sm: 768px
- md: 1200px
- lg: 1920px
- xl: 2560px
- xxl: 3840px

Click **"Salva"**

### Genera Varianti Immagini

**Importante**: Dopo aver modificato impostazioni immagini o caricato nuove foto:

**Settings → Genera Immagini**

Processo automatico:
1. Legge impostazioni (formati, qualità, breakpoints)
2. Per ogni immagine originale:
   - Ridimensiona a 5 dimensioni (sm/md/lg/xl/xxl)
   - Converte in 3 formati (avif/webp/jpg)
   - Salva in `public/media/`
3. Totale: 15 file per immagine

**Durata**: ~1-2 sec per immagine (dipende da CPU server)

**Progresso**: Vedrai barra di progresso

**Risultato**: "✅ Generate 150 image variants (10 images × 15 versions)"

---

## 7. Configura SEO Base

**Menu Admin → SEO**

**Site Title**: `John Doe Photography - Wedding & Portrait Photographer`
**OG Site Name**: `John Doe Photography` (per social sharing)
**Robots**: `index,follow` (permetti indicizzazione)
**Author Name**: `John Doe`
**Photographer Job Title**: `Professional Photographer`

Click **"Salva"**

**Genera Sitemap**:

Click **"Genera Sitemap"**

Crea:
- `/sitemap.xml` (sitemap principale)
- `/sitemap_index.xml` (index, se multi-sitemap)

Include:
- Home page
- Album published
- Categorie
- Tag

**Verifica sitemap**:
```
https://tuosito.com/sitemap.xml
```

**Submit a Google** (raccomandato):
1. Vai a Google Search Console
2. Aggiungi proprietà (tuosito.com)
3. Verifica proprietà
4. Sitemap → Aggiungi `https://tuosito.com/sitemap.xml`
5. Submit

---

## 8. Verifica Analytics

**Menu Admin → Analytics**

Vedrai:
- **Visitatori Oggi**: Numero visitatori unici oggi
- **Pageviews Oggi**: Totale pagine viste
- **Grafico Sessioni**: Trend ultimi 30 giorni
- **Top Pages**: Pagine più viste
- **Top Countries**: Paesi visitatori
- **Device Breakdown**: Desktop/Mobile/Tablet

**Real-Time**:

Click **"Real-Time"** → Vedi visitatori attivi in questo momento

**Note**: Analytics inizia a tracciare DOPO installazione. Dati demo non presenti.

---

## 9. Workflow Tipico

Ecco un workflow comune per gestire il portfolio:

### Nuovo Progetto Fotografico

1. **Seleziona foto** sul computer
2. **Login Admin**
3. **Crea Album Nuovo**
   - Titolo: "Wedding - Sarah & Tom"
   - Categoria: "Weddings"
   - Tags: "2024", "Matrimonio", "Outdoor"
4. **Upload foto** (drag-drop)
5. **Seleziona cover** (miglior foto)
6. **Scrivi descrizione** (racconta il progetto)
7. **Lascia non pubblicato** (bozza)
8. **Aggiungi metadati** a foto selezionate (camera, lens)
9. **Preview** (apri URL album)
10. **Pubblica** quando pronto

### Aggiornamento Portfolio

1. **Revisiona album vecchi** (elimina progetti datati)
2. **Riordina album** (drag-drop in lista album)
3. **Aggiorna categorie** (rinomina, riorganizza)
4. **Pulisci tag** (merge duplicati, elimina non usati)
5. **Rigenera sitemap** (dopo modifiche)

### Monitoraggio Performance

1. **Weekly**: Check Analytics → Top Albums
2. **Identifica** album più visti
3. **Analizza** engagement (tempo medio, bounce rate)
4. **Ottimizza** titoli/descrizioni album low-performing
5. **Condividi** sui social album top-performing

---

## 10. Shortcuts & Tips

### Shortcuts Tastiera (Admin)

- `Ctrl+S` / `Cmd+S`: Salva form (in editor album/settings)
- `Esc`: Chiudi modal/lightbox
- Frecce `← →`: Naviga tra immagini in lightbox

### Best Practices

**Naming**:
- ✅ Titoli descrittivi: "Wedding Portfolio 2024"
- ❌ Evita: "Album 1", "Test", "asdfgh"

**Descrizioni**:
- ✅ Racconta storia: "Captured during golden hour in Tuscany..."
- ❌ Evita troppo corte: "Photos"

**Organizzazione**:
- ✅ Usa categorie per TIPO (Weddings, Portraits, Landscapes)
- ✅ Usa tag per ATTRIBUTI (#Analogico, #35mm, #B&W)

**Immagini**:
- ✅ Seleziona solo le migliori (quality over quantity)
- ✅ Max 30-50 foto per album (attenzione span visitatori)
- ✅ Cover accattivante (prima impressione)

**SEO**:
- ✅ Alt text descrittivo: "Bride and groom walking in vineyard sunset"
- ❌ Evita: "IMG_1234", "photo"

---

## 11. Prossimi Passi

Ora che hai creato il primo album, esplora:

- **[Gestione Album](./albums-management.md)** - Funzioni avanzate
- **[Frontend Features](./frontend-features.md)** - Tutte le funzionalità pubbliche
- **[Admin Panel](./admin-panel.md)** - Panoramica completa admin
- **[Settings](./settings.md)** - Tutte le impostazioni disponibili
- **[Analytics](./analytics.md)** - Analisi approfondita statistiche

Buon lavoro! 📸

---

**Versione**: 1.0.0
**Ultimo aggiornamento**: 17 Novembre 2025
