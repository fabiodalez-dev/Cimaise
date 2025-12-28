# EXIF e Metadati in Cimaise

Sistema completo di estrazione, gestione e visualizzazione metadati fotografici.

---

## 📋 Indice

- [Panoramica](#panoramica)
- [Estrazione Automatica EXIF](#estrazione-automatica-exif)
- [Metadati Supportati](#metadati-supportati)
- [Gestione Attrezzatura](#gestione-attrezzatura)
- [Database Lensfun](#database-lensfun)
- [Visualizzazione Lightbox](#visualizzazione-lightbox)
- [EXIF Writing](#exif-writing)
- [Workflow Fotografia Analogica](#workflow-fotografia-analogica)

---

## Panoramica

Cimaise estrae automaticamente metadati EXIF da ogni immagine caricata e li organizza per ricerca e visualizzazione.

**Libreria usata**: [PEL (PHP EXIF Library)](https://github.com/pel/pel) + `exif_read_data()` nativo

**Doppio sistema**:
1. **PEL** (puro PHP) → estrazione completa, no dipendenze
2. **exif_read_data()** (se disponibile) → fallback per tag che PEL potrebbe mancare

**Risultato**: Massima compatibilità con fotocamere digitali, scanner, smartphone.

---

## Estrazione Automatica EXIF

### Al Caricamento

Quando carichi un'immagine, Cimaise:

1. **Salva originale** in `storage/originals/`
2. **Estrae EXIF** con PEL + exif_read_data
3. **Normalizza orientamento** (ruota immagine se necessario)
4. **Mappa a lookup tables** (camera, lens)
5. **Salva in database** per ricerca veloce

### Campi Estratti Automaticamente

#### Fotocamera

```
Make:  Canon
Model: Canon EOS 5D Mark IV
```

→ Auto-crea/trova nella tabella `cameras`

#### Obiettivo

```
LensModel: EF 24-70mm f/2.8L II USM
```

→ Auto-crea/trova nella tabella `lenses`

#### Impostazioni Esposizione

| Campo EXIF | Valore Esempio | Salvato come |
|------------|----------------|--------------|
| ExposureTime | 1/250 | `shutter_speed: "1/250s"` |
| FNumber | 2.8 | `aperture: "f/2.8"` |
| ISOSpeedRatings | 800 | `iso: 800` |
| FocalLength | 50mm | `focal_length: 50.0` |
| FocalLengthIn35mm | 75mm | `focal_length_35mm: 75` |

#### Metadati Avanzati

```
ExposureProgram: 1 (Manual)
MeteringMode: 5 (Multi-segment)
WhiteBalance: 0 (Auto)
Flash: 0 (No flash)
ColorSpace: 1 (sRGB)
DateTimeOriginal: 2025:01:15 14:32:18
```

#### GPS (se presente)

```
GPSLatitude: 45.4642° N
GPSLongitude: 9.1900° E
```

→ Coordinate decimali: `gps_lat: 45.4642, gps_lng: 9.19`

#### Autore e Copyright

```
Artist: Marco Rossi
Copyright: © 2025 Marco Rossi Photography
Software: Adobe Lightroom Classic 13.1
```

---

## Metadati Supportati

### Tabella Database `images`

```sql
-- Camera e Lens (lookup IDs)
camera_id INTEGER
lens_id INTEGER

-- Impostazioni Scatto
iso INTEGER
shutter_speed TEXT      -- "1/250s", "2s"
aperture TEXT           -- "f/2.8", "f/16"
focal_length REAL       -- 50.0 (mm)

-- EXIF Dettagliato
exif_make TEXT          -- "Canon"
exif_model TEXT         -- "EOS 5D Mark IV"
exif_lens_model TEXT    -- "EF 24-70mm f/2.8L II USM"
exposure_bias REAL      -- +0.7 (EV)
exposure_program INT    -- 1=Manual, 2=Program, 3=Aperture Priority...
metering_mode INT       -- 5=Multi-segment, 3=Spot...
flash INT               -- 0=No Flash, 1=Flash Fired...
white_balance INT       -- 0=Auto, 1=Manual
color_space INT         -- 1=sRGB, 2=Adobe RGB
date_original TEXT      -- "2025-01-15 14:32:18"

-- Elaborazione
contrast INT            -- 0=Normal, 1=Low, 2=High
saturation INT
sharpness INT
scene_capture_type INT  -- 0=Standard, 1=Landscape, 2=Portrait...
light_source INT        -- 1=Daylight, 3=Tungsten, 4=Flash...

-- GPS
gps_lat REAL
gps_lng REAL

-- Metadata Autore
artist TEXT
copyright TEXT
software TEXT           -- Software di post-produzione

-- JSON esteso (per futuri campi)
exif_extended TEXT      -- JSON con campi non mappati
```

---

## Gestione Attrezzatura

### Fotocamere

**Tabella**: `cameras`

```sql
CREATE TABLE cameras (
    id INTEGER PRIMARY KEY,
    make TEXT NOT NULL,       -- "Canon", "Nikon", "Fuji"
    model TEXT NOT NULL,      -- "EOS R5", "Z9", "X-T5"
    camera_type TEXT,         -- "dslr", "mirrorless", "film", "medium_format"
    created_at DATETIME
);
```

**Auto-create on upload**:
- Fotocamera nuova rilevata → Auto-crea riga in `cameras`
- Fuzzy matching: "Canon EOS-1D X Mark III" = "Canon EOS 1D X Mark III"

**Normalizzazione brand**:
```
"Nikon Corporation" → "Nikon"
"Canon Inc." → "Canon"
"Sony Corporation" → "Sony"
```

---

### Obiettivi

**Tabella**: `lenses`

```sql
CREATE TABLE lenses (
    id INTEGER PRIMARY KEY,
    brand TEXT NOT NULL,      -- "Canon", "Sigma", "Tamron"
    model TEXT NOT NULL,      -- "EF 50mm f/1.4 USM"
    mount TEXT,               -- "Canon EF", "Nikon F", "Sony E"
    min_focal_length REAL,    -- 24 (per zoom 24-70mm)
    max_focal_length REAL,    -- 70
    max_aperture TEXT,        -- "f/2.8"
    created_at DATETIME
);
```

**Parsing automatico**:
```
Input EXIF: "Canon EF 24-70mm f/2.8L II USM"

Parsed:
brand: "Canon"
model: "EF 24-70mm f/2.8L II USM"
min_focal_length: 24
max_focal_length: 70
max_aperture: "f/2.8"
```

**Pattern riconosciuti**:
```regex
/^(Canon|Nikon|Sony|Sigma|Tamron|Tokina|Zeiss|Leica)\s+(.+)$/
```

Se brand non riconosciuto → `brand: "Unknown"`, `model: [stringa completa]`

---

### Pellicole (Film Stocks)

**Tabella**: `films`

```sql
CREATE TABLE films (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,           -- "Kodak Portra 400"
    brand TEXT,                   -- "Kodak"
    film_type TEXT,               -- "color_negative", "bw_negative", "slide"
    iso_rating INTEGER,           -- 400
    format TEXT,                  -- "35mm", "120", "4x5", "8x10"
    created_at DATETIME
);
```

**Valori pre-popolati**:
```
- Kodak Portra 160, 400, 800
- Kodak Tri-X 400
- Kodak Ektar 100
- Ilford HP5+ 400
- Ilford Delta 3200
- Fujifilm Pro 400H
- Fujifilm Velvia 50, 100
- Cinestill 800T
```

**Gestione**:
```
Admin → Equipment → Films → Add Film
```

**Assegnazione**:
```
Admin → Media → [Select Image] → Metadata Panel
Film Stock: [Dropdown con autocomplete]
```

---

### Sviluppo e Laboratori

Per fotografi analogici:

**Developers** (chimiche sviluppo):
```
- Rodinal (1+25, 1+50)
- HC-110 (Dilution B)
- XTOL (stock, 1+1)
- D-76
- Ilfosol 3
```

**Labs** (laboratori di sviluppo):
```
- Nome laboratorio
- Città
- Note (specializzazione, qualità, prezzi)
```

---

## Database Lensfun

Cimaise integra [Lensfun](https://lensfun.github.io/), database open-source con:
- **1.000+ fotocamere**
- **1.300+ obiettivi**

### Funzionalità

**Autocomplete**:
Quando digiti camera o lens nella media library, autocomplete suggerisce modelli dal database Lensfun.

**Esempio**:
```
Digiti: "Canon 5D"
Suggerimenti:
- Canon EOS 5D
- Canon EOS 5D Mark II
- Canon EOS 5D Mark III
- Canon EOS 5D Mark IV
- Canon EOS 5DS
- Canon EOS 5DS R
```

**Auto-fill focal lengths**:
Selezioni obiettivo → campi focal length si popolano automaticamente:
```
Lens: "Canon EF 24-70mm f/2.8L II USM"
→ Min Focal: 24mm
→ Max Focal: 70mm
→ Max Aperture: f/2.8
```

### Aggiornamento Database

Il database Lensfun viene aggiornato regolarmente con nuovi modelli.

**Update manuale**:
```
Admin → Settings → Lensfun Database → Update
```

**Processo**:
1. Download XML files da [Lensfun GitHub](https://github.com/lensfun/lensfun)
2. Parse 60+ file XML (categorie: DSLR, mirrorless, film, compact...)
3. Import in `storage/cache/lensfun.json`
4. Disponibile per autocomplete

**Durata**: ~10-30 secondi

**Frequenza consigliata**: Ogni 3-6 mesi (quando escono nuove fotocamere/obiettivi)

**File scaricati**:
```
slr-canon.xml, slr-nikon.xml, slr-sony.xml
mil-fujifilm.xml, mil-sony.xml, mil-canon.xml
rf-leica.xml
compact-*.xml
...
```

---

## Visualizzazione Lightbox

Quando un visitatore apre un'immagine in lightbox (PhotoSwipe), può vedere i metadati se abilitati.

### Abilitazione

```
Admin → Settings → Lightbox → Show EXIF Data
☑️ Enable
```

### Dati Mostrati

La visualizzazione è organizzata in **sezioni** per leggibilità:

#### 📷 Equipment

```
Camera: Canon EOS R5
Lens: RF 24-70mm f/2.8L IS USM
```

#### ⚙️ Exposure

```
Shutter: 1/250s
Aperture: f/2.8
ISO: 800
Focal Length: 50mm (75mm eq.)
Exp. Comp.: +0.7 EV
```

#### 🎛️ Mode

```
Program: Aperture Priority
Metering: Multi-segment
Exp. Mode: Auto
```

#### 📅 Details

```
Date: 15 Jan 2025, 14:32
Flash: No
White Balance: Auto
Light Source: Daylight
Scene: Standard
```

#### 🌍 Location (se GPS presente)

```
Coordinates: 45.4642, 9.1900
Map: [Link a Google Maps]
```

#### ℹ️ Info (se presenti)

```
Software: Adobe Lightroom Classic 13.1
Artist: Marco Rossi
Copyright: © 2025 Marco Rossi Photography
```

### Styling

I dati sono presentati in un pannello scorrevole:
- Icone FontAwesome per ogni campo
- Organizzazione a sezioni collassabili
- Design responsive (mobile-friendly)
- Dark mode compatible

### On/Off Toggle

I visitatori possono:
- Aprire pannello EXIF: click su icona "ℹ️ Info"
- Chiudere: click su X o fuori dal pannello
- Preferenza NON salvata (ogni immagine ricomincia chiuso)

---

## EXIF Writing

Cimaise può **scrivere** EXIF sui file, non solo leggerli.

### Quando si usa

**Caso d'uso 1**: Aggiornare EXIF dopo modifica metadati in admin

```
Admin → Media → [Image] → Edit Metadata
→ Cambi Camera, Lens, ISO...
→ [Checkbox] "Update EXIF in file"
→ Save
```

**Caso d'uso 2**: Aggiungere metadati a scansioni pellicola

Scansioni di negativo spesso hanno EXIF minimo o nullo. Puoi aggiungere:
- Film stock
- Camera usata
- Data scatto
- Sviluppo e lab

E scrivere nei file JPEG.

### Tag Scrivibili

**IFD0** (Image File Directory):
```
Make
Model
Software
Artist
Copyright
Orientation
```

**EXIF Sub-IFD**:
```
LensModel (tag 0xA434)
FocalLength
ExposureBiasValue
Flash
WhiteBalance
ExposureProgram
MeteringMode
ExposureMode
DateTimeOriginal
ColorSpace
Contrast, Saturation, Sharpness
SceneCaptureType
LightSource
```

**GPS Sub-IFD**:
```
GPSVersionID
GPSLatitude, GPSLatitudeRef
GPSLongitude, GPSLongitudeRef
```

### Propagazione a Varianti

Quando aggiorni EXIF:
```
☑️ Update original
☑️ Update all JPEG variants (sm, md, lg, xl, xxl)
☐ Skip AVIF/WebP (non supportano EXIF completo)
```

**Nota**: WebP e AVIF supportano EXIF limitato. Cimaise scrive solo su JPEG per compatibilità massima.

### Limitazioni

**Tag non supportati da PEL**:
Alcuni tag proprietari (es. MakerNotes Canon) non sono scrivibili.

**Warning system**:
Se un tag fallisce scrittura, Cimaise:
- Log warning in `storage/logs/upload.log`
- Continua con altri tag
- Ritorna array warnings all'utente

**Esempio warning**:
```
"Could not write LensModel tag (not supported by library)"
```

Soluzione: LensModel andrà nel database ma non nel file EXIF.

---

## Workflow Fotografia Analogica

Cimaise ha supporto dedicato per fotografi che scannerizzano pellicola.

### Step 1: Scansiona il Negativo

Scanner o lab ti danno TIFF/JPEG:
```
scan_001.tif
scan_002.tif
...
```

**EXIF presente**: Molto limitato (scanner info, data scan)
**EXIF mancante**: Camera, film, lens, data scatto

### Step 2: Carica su Cimaise

```
Admin → Media → Upload
→ Drag & drop scan_001.tif
```

Cimaise:
- Salva originale
- Estrae EXIF (poco o nulla)
- Genera varianti

### Step 3: Aggiungi Metadati Manuali

```
Admin → Media → [Select scan_001] → Metadata Panel
```

Compila:
- **Camera**: Hasselblad 500C/M (autocomplete da Lensfun)
- **Lens**: Carl Zeiss Planar 80mm f/2.8
- **Film Stock**: Kodak Portra 400
- **ISO**: 400 (rating pellicola)
- **Developer**: Rodinal 1:50
- **Lab**: Camera Oscura Lab, Milano
- **Date Original**: 2024-08-15 (data scatto stimata)

### Step 4: Scrivi EXIF nel File

```
☑️ Update EXIF in file
→ Save
```

Ora il file JPEG ha:
```
Make: Hasselblad
Model: 500C/M
LensModel: Carl Zeiss Planar 80mm f/2.8
ISOSpeedRatings: 400
DateTimeOriginal: 2024:08:15 12:00:00
Artist: [Il tuo nome]
Software: Cimaise CMS
```

### Step 5: Organizza per Film/Camera

**Filtri gallerie**:
```
yoursite.com/galleries?film=portra-400
→ Tutti scatti con Portra 400

yoursite.com/galleries?camera=hasselblad-500cm
→ Tutti scatti con Hasselblad
```

**Custom Fields** (avanzato):
```
Film Format: 120
Development: Stand development, 1 hour
Push/Pull: +1 stop push
Notes: Expired 2018, refrigerato
```

Vedi [Custom Fields](./custom-fields.md) per dettagli.

---

## Best Practices

### Per Fotografi Digitali

✅ **Fai**:
- Imposta Artist e Copyright nella fotocamera
- Usa profilo colore sRGB per web (o converti in post)
- Verifica GPS privacy (rimuovi coordinate da foto sensibili)

❌ **Non fare**:
- Strip EXIF in Lightroom prima di export (Cimaise lo usa!)
- Caricare file con EXIF vuoto (ricerca e metadati non funzionano)

### Per Fotografi Analogici

✅ **Fai**:
- Tieni registro scatti (film log) per data accuracy
- Aggiungi metadati subito dopo scan (prima di dimenticare)
- Usa [Custom Fields](./custom-fields.md) per note di sviluppo

❌ **Non fare**:
- Lasciare EXIF vuoto (rendimandi inutili)
- Fidarsi di data scan come "data scatto" (sono diverse!)

### Privacy GPS

**Problema**: Coordinate GPS rivelano dove abiti (se scatti da casa).

**Soluzioni**:

**1. Rimuovi GPS prima di caricare**:
```bash
exiftool -gps:all= foto.jpg
```

**2. Strip GPS in Lightroom**:
```
Export → Metadata → Remove Location Info
```

**3. Disabilita GPS nella fotocamera** per scatti privati.

**Cimaise non pubblica GPS di default**, ma è visibile in admin. Se vuoi mostrare location pubblica (paesaggi), abilita in album settings.

---

## Troubleshooting

### EXIF non estratto

**Causa**: Formato file non supportato o EXIF corrotto

**Verifica**:
```bash
# CLI
exiftool foto.jpg

# Se ritorna errore → file corrotto
```

**Soluzione**:
- Ri-esporta da Lightroom con EXIF completo
- Usa formato JPEG invece di WebP/AVIF per upload

### Camera/Lens non trovati

**Causa**: Modello non in Lensfun database o nome non standard

**Soluzione**:
1. Aggiungi manualmente: Admin → Equipment → Add Camera/Lens
2. Aggiorna Lensfun: Admin → Settings → Update Lensfun Database
3. Se modello è nuovissimo (2025 Q1), contribuisci a [Lensfun GitHub](https://github.com/lensfun/lensfun)

### EXIF Writing fallisce

**Causa**: File non scrivibile, permessi, o formato non supportato

**Verifica permessi**:
```bash
ls -la storage/originals/
chmod 644 storage/originals/*.jpg
```

**Log**:
```bash
tail -f storage/logs/upload.log
```

Cerca errori tipo:
```
"Failed to write EXIF to /path/to/file.jpg: Permission denied"
```

---

## API per Sviluppatori

Se vuoi estendere sistema EXIF via plugin:

```php
use App\Services\ExifService;

$exifService = $container->get(ExifService::class);

// Estrai EXIF
$meta = $exifService->extract('/path/to/image.jpg');
// Returns: ['Make' => 'Canon', 'Model' => 'EOS R5', ...]

// Scrivi EXIF
$data = [
    'exif_make' => 'Hasselblad',
    'exif_model' => '500C/M',
    'artist' => 'John Doe',
    'copyright' => '© 2025 John Doe',
    'focal_length' => 80.0,
    'gps_lat' => 45.4642,
    'gps_lng' => 9.19,
];
$success = $exifService->writeToJpeg('/path/to/file.jpg', $data);

// Formattazione per lightbox
$formatted = $exifService->extractForLightbox('/path/to/file.jpg');
// Returns: ['sections' => [['title' => 'Equipment', 'items' => [...]]]]
```

Vedi `app/Services/ExifService.php` per tutti i metodi disponibili.

---

## Prossimi Passi

Ora che comprendi il sistema EXIF:
- [Aggiungi Custom Fields](./custom-fields.md) per metadati estesi
- [Configura filtri gallerie](./album-gallerie.md#filtri) per ricerca per attrezzatura
- [Workflow pellicola](./workflow-film.md) per gestione completa analog
