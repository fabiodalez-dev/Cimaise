# Custom Fields in Cimaise

Sistema flessibile di metadati personalizzati per estendere le informazioni associate a immagini e album.

**Accesso**: Admin → Equipment → Custom Fields

---

## 📋 Indice

- [Cosa Sono i Custom Fields](#cosa-sono-i-custom-fields)
- [Campi di Sistema](#campi-di-sistema)
- [Creare Custom Fields](#creare-custom-fields)
- [Tipi di Campo](#tipi-di-campo)
- [Assegnazione Valori](#assegnazione-valori)
- [Ereditarietà Album → Immagine](#ereditarietà-album--immagine)
- [Visualizzazione Frontend](#visualizzazione-frontend)
- [Casi d'Uso](#casi-duso)

---

## Cosa Sono i Custom Fields

I **Custom Fields** permettono di aggiungere metadati personalizzati oltre a quelli standard (camera, lens, ISO, etc.).

**Esempi**:
- **Mood/Emozione**: Nostalgia, Gioia, Malinconia
- **Tecnica**: Long exposure, HDR, Focus stacking
- **Soggetto**: Ritratto, Paesaggio, Architettura
- **Cliente**: Nome cliente per gallerie private
- **Premio**: Menzioni, awards ricevuti
- **Pubblicazione**: Riviste dove l'immagine è stata pubblicata

**Sistema a 3 livelli**:

1. **Custom Field Type** (tipo di campo)
   - Es: "Mood", "Client", "Award"

2. **Custom Field Values** (valori predefiniti)
   - Es: Per "Mood" → ["Nostalgia", "Gioia", "Malinconia"]

3. **Assegnazione**
   - Album-level: Applica a tutto l'album
   - Image-level: Override per singola immagine

---

## Campi di Sistema

Cimaise include campi predefiniti per fotografi:

### Camera

- **Nome**: Camera
- **Tipo**: Select
- **Valori**: Auto-popolati da Lensfun database
- **Sistema**: ✅ (non eliminabile)
- **Lightbox**: ✅ Mostrato
- **Gallerie**: ✅ Filtrabile

**Usa per**: Filtrare per fotocamera
```
yoursite.com/galleries?camera=canon-eos-r5
```

---

### Lens

- **Nome**: Lens (Obiettivo)
- **Tipo**: Select
- **Valori**: Auto-popolati da Lensfun
- **Sistema**: ✅
- **Lightbox**: ✅
- **Gallerie**: ✅

**Usa per**: Trovare tutti scatti con un obiettivo specifico

---

### Film Stock

- **Nome**: Film
- **Tipo**: Select
- **Valori**: Kodak Portra 400, Tri-X 400, HP5+, etc.
- **Sistema**: ✅ (ma estendibile)
- **Lightbox**: ✅
- **Gallerie**: ✅

**Usa per**: Fotografi analogici
```
yoursite.com/galleries?film=portra-400
```

---

### Developer

- **Nome**: Developer (Chimiche sviluppo)
- **Tipo**: Select
- **Valori**: Rodinal, HC-110, D-76, etc.
- **Sistema**: ✅
- **Lightbox**: ✅
- **Gallerie**: ❌ (solo admin)

**Usa per**: Tracciare workflow pellicola

---

### Lab

- **Nome**: Lab (Laboratorio)
- **Tipo**: Text
- **Sistema**: ✅
- **Lightbox**: ✅
- **Gallerie**: ❌

**Usa per**: Tracking sviluppo/scan pellicole

---

### Location

- **Nome**: Location
- **Tipo**: Text
- **Sistema**: ✅
- **Lightbox**: ✅
- **Gallerie**: ✅

**Usa per**: Città, paese, luogo dello scatto
```
yoursite.com/galleries?location=kyoto
```

---

## Creare Custom Fields

### Step 1: Crea il Field Type

```
Admin → Equipment → Custom Fields → Add Field Type
```

**Form**:
- **Name** (slug): `mood` (lowercase, no spazi, solo a-z0-9-)
- **Label**: Mood / Emozione (nome visualizzato)
- **Icon**: Scegli da lista FontAwesome (es: `fa-heart`)
- **Field Type**: `select` o `text` o `multi-select`
- **Description**: Breve spiegazione (opzionale)
- **Show in Lightbox**: ☑️ Mostra nei dettagli immagine
- **Show in Gallery**: ☑️ Abilita filtro in pagina gallerie
- **Sort Order**: 100 (ordine visualizzazione, più basso = prima)

**Salva** → Field type creato

---

### Step 2: Aggiungi Valori (se Select)

Se hai scelto `select` o `multi-select`:

```
Admin → Equipment → Custom Fields → [Mood] → Manage Values
```

Aggiungi:
```
Nostalgia
Gioia
Malinconia
Serenità
Energia
```

Ogni valore può avere:
- **Value**: Nome del valore
- **Extra Data**: JSON opzionale per dati aggiuntivi
- **Sort Order**: Ordine nel dropdown

---

### Step 3: Assegna ad Album o Immagini

Vedi sezione [Assegnazione Valori](#assegnazione-valori).

---

## Tipi di Campo

### Select (Single Choice)

**Comportamento**: Dropdown, scegli 1 valore

**Esempio**: Client Name
```
Valori:
- Matrimonio Smith
- Evento Ferrari SpA
- Corporate Bianchi SRL
```

**Assegnazione**:
```
Album "Wedding 2024" → Client: "Matrimonio Smith"
```

**Visualizzazione lightbox**:
```
📁 Client: Matrimonio Smith
```

---

### Multi-Select (Multiple Choice)

**Comportamento**: Checkbox, scegli N valori

**Esempio**: Technique
```
Valori:
- Long Exposure
- HDR
- Focus Stacking
- Timelapse
- Panorama
- Black & White
```

**Assegnazione**:
```
Immagine #42 → Techniques: [Long Exposure, Black & White]
```

**Visualizzazione lightbox**:
```
🔧 Techniques: Long Exposure, Black & White
```

---

### Text (Free Input)

**Comportamento**: Campo testo libero

**Esempio**: Award / Publication
```
Assegnazione:
Immagine #101 → Award: "Winner, Sony World Photography Awards 2024"
```

**Visualizzazione lightbox**:
```
🏆 Award: Winner, Sony World Photography Awards 2024
```

**Quando usare**:
- Valori non predicibili
- Troppi valori possibili per select
- Necessità di frasi complete (non solo tag)

---

## Assegnazione Valori

### A Livello Album

```
Admin → Albums → [Select Album] → Edit → Metadata Tab
```

**Form**:
```
Mood: [Dropdown] → Nostalgia
Location: [Text] → Kyoto, Japan
Technique: [Checkboxes] → ☑️ Long Exposure, ☑️ Black & White
```

**Salva** → Tutti le immagini dell'album ereditano questi valori

---

### A Livello Immagine

#### Opzione 1: Media Library

```
Admin → Media → [Select Image] → Metadata Panel (right sidebar)
```

**Form**:
```
Custom Fields:
  Mood: [Dropdown] → Gioia
  ☑️ Override album value
```

**Override checkbox**:
- ☐ Non selezionato → Eredita da album
- ☑️ Selezionato → Usa valore specifico per questa immagine

---

#### Opzione 2: Dentro Album

```
Admin → Albums → [Album] → Gallery View → [Click Image] → Edit
```

Stesso form di Media Library.

---

### Auto-Added Values

Quando imposti **override** a livello immagine, il valore viene auto-aggiunto all'album (se non già presente).

**Esempio**:

```
Album "Landscapes 2024":
  Mood: Serenità

Immagine #42 dentro album:
  Mood: Malinconia (override)

→ Album "Landscapes 2024" valori diventa:
  Mood: [Serenità, Malinconia (auto-added)]
```

**Motivo**: Permette filtraggio album per tutti i valori presenti nelle immagini, anche se non assegnati esplicitamente all'album.

**UI Indicatore**:
```
Mood:
  ✓ Serenità (manuale)
  + Malinconia (auto-added da immagine #42)
```

---

## Ereditarietà Album → Immagine

Sistema gerarchico:

```
Album
  ├─ Metadata
  │    └─ Mood: Nostalgia
  │    └─ Location: Paris
  │
  └─ Image #1
       └─ Eredita: Mood=Nostalgia, Location=Paris

  └─ Image #2
       └─ Override: Mood=Gioia
       └─ Eredita: Location=Paris
       → Risultato finale: Mood=Gioia, Location=Paris
```

**Logica**:
1. Immagine controlla se ha valore custom field
2. Se **NO override** → usa valore album
3. Se **override** → usa valore immagine, ignora album

**Vantaggio**: Assegna metadati una volta a livello album, applica a tutte le immagini. Override solo eccezioni.

---

## Visualizzazione Frontend

### Lightbox (PhotoSwipe)

Quando visitatore apre immagine:

```
[Immagine full-screen]

[Pulsante "ℹ️ Info"]
  ↓ Click
[Panel laterale con sezioni]

📷 Equipment
   Camera: Canon EOS R5
   Lens: RF 24-70mm f/2.8

⚙️ Exposure
   ISO: 800, f/2.8, 1/250s

❤️ Mood              ← Custom Field
   Nostalgia

📍 Location          ← Custom Field
   Kyoto, Japan

🔧 Technique         ← Custom Field
   Long Exposure, Black & White
```

**Controllo visibilità**:
- Se `show_in_lightbox = 0` → NON mostrato
- Se `show_in_lightbox = 1` → Mostrato

**Ordine**:
Determinato da `sort_order` del field type.

---

### Filtri Gallerie

Se `show_in_gallery = 1`:

```
yoursite.com/galleries

[Sidebar Filters]
  Category
  Tag
  Location       ← Custom Field
    □ Kyoto
    □ Paris
    □ New York

  Mood           ← Custom Field
    □ Nostalgia
    □ Gioia
    □ Malinconia

[Apply Filters]
```

**URL generato**:
```
/galleries?location=kyoto&mood=nostalgia
```

**Shareable**: Condividi URL filtrato con clienti/amici.

---

## Casi d'Uso

### Caso 1: Wedding Photographer (Client Tracking)

**Custom Field**:
```
Name: client
Label: Client Name
Type: select
Values:
  - Wedding Smith, June 2024
  - Wedding Johnson, August 2024
  - Engagement Brown, May 2024
Show in Lightbox: NO (privacy)
Show in Gallery: NO (solo admin)
```

**Utilizzo**:
```
Admin → Albums → "Summer Weddings" → Metadata
Client: Wedding Smith, June 2024
```

**Vantaggio**: Ricerca rapida tutti album per cliente.

---

### Caso 2: Fine Art (Mood Tagging)

**Custom Field**:
```
Name: mood
Label: Mood
Type: multi-select
Values:
  - Nostalgia
  - Melancholy
  - Joy
  - Serenity
  - Energy
  - Mystery
Show in Lightbox: YES
Show in Gallery: YES
```

**Utilizzo**:
```
Album "Urban Solitude" → Mood: [Melancholy, Mystery]
Immagine #42 → Mood: [Joy] (override)
```

**Vantaggio**: Visitatori esplorano per emozione, non solo soggetto.

---

### Caso 3: Commercial (Usage Rights)

**Custom Field**:
```
Name: usage_rights
Label: Usage Rights
Type: text
Show in Lightbox: NO
Show in Gallery: NO
```

**Utilizzo**:
```
Immagine #101 → Usage Rights: "Licensed to XYZ Magazine, exclusive 6 months"
```

**Vantaggio**: Tracking licensing per protezione copyright.

---

### Caso 4: Analog Photographer (Development Notes)

**Custom Field**:
```
Name: dev_notes
Label: Development Notes
Type: text
Show in Lightbox: YES
Show in Gallery: NO
```

**Utilizzo**:
```
Immagine #50 → Dev Notes: "Rodinal 1:50, stand development 1h, slight overdevelopment in highlights"
```

**Vantaggio**: Documentazione tecnica per riproducibilità.

---

### Caso 5: Travel Photographer (Country/Region)

**Custom Field**:
```
Name: country
Label: Country
Type: select
Values:
  - Japan
  - Iceland
  - Patagonia
  - Morocco
  - Norway
  ...
Show in Lightbox: YES
Show in Gallery: YES
```

**Utilizzo**:
```
Album "East Asia 2024" → Country: Japan
```

**URL**:
```
/galleries?country=japan
→ Tutti gli album dal Giappone
```

---

### Caso 6: Award Tracking

**Custom Field**:
```
Name: awards
Label: Awards
Type: multi-select
Values:
  - Sony World Photography Awards
  - National Geographic Photo Contest
  - LensCulture Exposure
  - Hasselblad Masters
  - PDN Photo Annual
Show in Lightbox: YES
Show in Gallery: YES
```

**Utilizzo**:
```
Immagine #200 → Awards: [Sony World Photography Awards, LensCulture Exposure]
```

**Lightbox**:
```
🏆 Awards:
   Sony World Photography Awards
   LensCulture Exposure
```

---

## Icone Disponibili

Scegli icona FontAwesome per ogni custom field:

**Emozioni/Mood**:
- `fa-heart` ❤️
- `fa-smile` 😊
- `fa-sad-tear` 😢
- `fa-fire` 🔥
- `fa-star` ⭐

**Luoghi**:
- `fa-map-marker-alt` 📍
- `fa-globe` 🌍
- `fa-mountain` 🏔️
- `fa-city` 🏙️
- `fa-tree` 🌲

**Tecnica**:
- `fa-cog` ⚙️
- `fa-sliders-h` 🎛️
- `fa-magic` ✨
- `fa-palette` 🎨
- `fa-tools` 🔧

**Business**:
- `fa-user` 👤
- `fa-briefcase` 💼
- `fa-award` 🏆
- `fa-copyright` ©️
- `fa-certificate` 📜

Vedi lista completa in `app/Services/CustomFieldService.php` metodo `getAvailableIcons()`.

---

## API per Sviluppatori

### Recuperare Custom Fields di un'Immagine

```php
use App\Services\CustomFieldService;

$customFieldService = $container->get(CustomFieldService::class);
$imageId = 42;
$albumId = 10;

// Get tutti custom fields (merged album + image)
$fields = $customFieldService->getImageMetadata($imageId, $albumId);

/*
Returns:
[
  'mood' => [
    'type_name' => 'mood',
    'type_label' => 'Mood',
    'icon' => 'fa-heart',
    'show_in_lightbox' => true,
    'values' => ['Nostalgia'],
    'is_override' => false
  ],
  ...
]
*/
```

---

### Assegnare Custom Field a Immagine

```php
$imageId = 42;
$fieldTypeId = 5; // ID del field type "mood"
$values = [10, 12]; // IDs dei field values, oppure stringhe libere
$isOverride = true;

$customFieldService->setImageMetadata($imageId, $fieldTypeId, $values, $isOverride);
```

---

### Recuperare Custom Fields Album

```php
$albumId = 10;
$fields = $customFieldService->getAlbumMetadata($albumId);

/*
Returns:
[
  'mood' => [
    'type_label' => 'Mood',
    'values' => [
      ['value' => 'Nostalgia', 'auto_added' => false],
      ['value' => 'Gioia', 'auto_added' => true]  // ← auto-added da immagine
    ]
  ]
]
*/
```

---

## Best Practices

### DO ✅

- Usa **select** per valori limitati e prevedibili
- Usa **multi-select** per tag combinabili
- Usa **text** per note libere
- **Abilita lightbox** per info interessanti ai visitatori
- **Abilita gallery** per permettere esplorazione
- Usa **icone semantiche** (mood → heart, location → map-pin)

### DON'T ❌

- Non creare troppi custom fields (>10 diventa confusione)
- Non duplicare informazioni già in EXIF (es: ISO, aperture)
- Non mostrare in lightbox dati privati (client names, pricing)
- Non usare select con 100+ valori (usa text invece)

---

## Migrazione da Altri CMS

### Da WordPress

WordPress custom fields (ACF) possono essere mappati:

```
ACF Field "client_name" → Cimaise Custom Field "client"
ACF Field "mood" → Cimaise Custom Field "mood"
```

Script migrazione:
```php
// Pseudo-code
foreach ($wp_images as $wp_img) {
  $clientName = get_field('client_name', $wp_img->ID);

  // Trova field type in Cimaise
  $fieldType = $db->query("SELECT id FROM custom_field_types WHERE name='client'")->fetch();

  // Assegna valore
  $customFieldService->setImageMetadata(
    $cimaise_image_id,
    $fieldType['id'],
    [$clientName],
    false
  );
}
```

---

## Troubleshooting

### Custom Field non appare in Lightbox

**Causa**: `show_in_lightbox = 0`

**Soluzione**:
```
Admin → Equipment → Custom Fields → [Field] → Edit
☑️ Show in Lightbox
Save
```

### Filtro non funziona in Gallerie

**Causa**: `show_in_gallery = 0` o nessun valore assegnato

**Verifica**:
1. Field type ha `show_in_gallery = 1`?
2. Almeno un album ha valore assegnato?

### Valore auto-added non voluto

**Causa**: Immagine con override ha aggiunto valore all'album

**Soluzione**:
```
Admin → Albums → [Album] → Metadata
Rimuovi valore auto-added (icona cestino)
```

**Nota**: Rimuovere dall'album NON rimuove dall'immagine.

---

## Prossimi Passi

Ora che hai configurato custom fields:
- [Gestisci EXIF completi](./exif-metadati.md) per metadati automatici
- [Crea album con metadati ricchi](./album-gallerie.md)
- Usa filtri avanzati per esplorazione portfolio
