# Cimaise — CMS Portfolio per Fotografi (Open Source)

Ciao a tutti!

Voglio presentarvi **Cimaise**, un CMS che ho sviluppato specificamente per noi fotografi. Dopo anni di frustrazione con WordPress, Squarespace e altre piattaforme generiche, ho deciso di creare qualcosa che parlasse la nostra lingua.

## Perche l'ho creato

I CMS generici non capiscono la fotografia. Non sanno cosa sia un rullino 120, non distinguono un'ottica da un corpo macchina, e le gallerie sono sempre un compromesso. Cimaise e diverso: **e stato pensato da un fotografo, per i fotografi**.

## Caratteristiche principali

### Gestione Equipaggiamento Completa
- Cataloga le tue fotocamere, ottiche, pellicole, sviluppatori e laboratori
- Associa l'equipaggiamento ad ogni album
- Filtra le gallerie per attrezzatura usata
- Supporto completo per fotografia analogica (35mm, 120, 4x5)
- **Database Lensfun integrato**: Autocomplete EXIF con database di fotocamere e obiettivi da lensfun.github.io, aggiornabile con un click

### Ottimizzazione Immagini Automatica
- Carica una volta, il sistema genera 5 dimensioni x 3 formati (AVIF, WebP, JPEG)
- Qualita configurabile per formato (AVIF: 50%, WebP: 75%, JPEG: 85%)
- Elementi `<picture>` automatici con fallback
- LQIP (Low-Quality Image Placeholders) per caricamento progressivo
- Generazione varianti in background per upload veloce

### 6 Template Home Page
- Classic (masonry + scroll infinito)
- Modern (sidebar fissa + griglia)
- Gallery Wall
- Parallax
- Pure Masonry
- Snap Scroll

### 4 Template Galleria
- Griglia classica
- Masonry
- Magazine (3 colonne animate)
- Magazine + Cover

### Protezione Contenuti
- Gallerie protette da password (per clienti)
- Modalita NSFW con age gate (per nudo artistico/boudoir)
- Combinabili insieme per massima protezione
- Blur automatico delle anteprime per contenuti protetti

### SEO Ottimizzato
- Rendering server-side (no JavaScript necessario per il contenuto)
- Schema.org completo (ImageGallery, BreadcrumbList, LocalBusiness)
- Open Graph e Twitter Cards
- Sitemap XML automatica
- Meta tag personalizzabili per ogni album

### Filtri Avanzati
- Filtra per categoria, tag, fotocamera, ottica, pellicola
- Filtro per location e anno
- Ricerca full-text
- URL condivisibili con filtri attivi

### Dark Mode
- Toggle con un click
- Transizioni fluide
- Perfetto per mostrare il lavoro di sera

### Altre caratteristiche
- Multilingua (italiano/inglese inclusi)
- PWA con supporto offline
- Drag & drop per riordinare album e immagini
- Upload in batch (100+ immagini)
- GDPR compliant (cookie consent, font locali)
- reCAPTCHA v3 per il form contatti
- Backup automatici prima degli aggiornamenti
- Gestione cache avanzata con LQIP integrato
- Typography customization con font pairs curati

## Stack Tecnico

- **Backend**: PHP 8.2+, Slim 4, Twig
- **Database**: SQLite (default con WAL mode) o MySQL
- **Frontend**: Tailwind CSS, Vite, PhotoSwipe
- **Self-hosted**: Nessun costo mensile, nessun vendor lock-in

## Requisiti

- PHP 8.2+ con estensioni GD/Imagick
- 100MB spazio disco minimo
- Qualsiasi hosting con PHP (anche shared hosting economici)

## Open Source

Il progetto e **completamente open source** sotto licenza MIT. Potete usarlo, modificarlo, contribuire. Nessun abbonamento, nessuna limitazione.

---

Se siete stanchi di piattaforme che vi trattano come blogger invece che come fotografi, date un'occhiata a Cimaise. Saro felice di rispondere a domande e ricevere feedback!

**Link**: [inserire link GitHub]
