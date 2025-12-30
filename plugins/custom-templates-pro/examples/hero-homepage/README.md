# Hero Homepage Template

Homepage con grande hero section fullscreen e griglia album moderna.

## ⚠️ Struttura del Template (IMPORTANTE)

Il file ZIP deve contenere una **cartella** con il nome del template:

```
hero-homepage.zip
└── hero-homepage/
    ├── metadata.json    ← OBBLIGATORIO!
    ├── home.twig        ← OBBLIGATORIO!
    ├── styles.css
    ├── script.js
    └── README.md
```

### Campi obbligatori in metadata.json

| Campo | Descrizione | Esempio |
|-------|-------------|---------|
| `type` | Tipo template | `"homepage"` |
| `name` | Nome visualizzato | `"Hero Homepage"` |
| `slug` | Identificatore URL | `"hero-homepage"` |
| `version` | Versione semver | `"1.0.0"` |

**Senza questi campi l'upload fallirà!**

## Caratteristiche

- ✅ Hero section fullscreen con gradiente
- ✅ Effetto parallax allo scroll
- ✅ Animazioni fade-in sequenziali
- ✅ Griglia responsive 3 colonne
- ✅ Overlay hover con info album
- ✅ Smooth scroll al click su "Scroll to explore"
- ✅ Dark mode support
- ✅ Performance ottimizzate

## Cosa Include

- Hero section con titolo sito e descrizione
- Animazione scroll indicator
- Griglia album con hover overlay
- Categorie album
- Meta info (data, numero foto)

## Installazione

1. Comprimi questa directory in un file ZIP
2. Carica tramite Admin → Custom Templates → Carica Template
3. Seleziona "Template Homepage"
4. Upload completo!

## Personalizzazione

Puoi modificare:
- **Gradiente hero**: Cambia colors in `.hero-section` background
- **Altezza hero**: Modifica `height: 100vh`
- **Colonne griglia**: Cambia `grid-template-columns`
- **Animazioni**: Personalizza durate e delay

## Effetti JavaScript

Lo script include:
- Smooth scroll al click
- Parallax effect per hero section
- Intersection Observer per animazioni

## Note

Ideale per portfolio fotografici che vogliono un impatto visivo forte con la hero section.
Gradient colors possono essere personalizzati per adattarsi al brand.
