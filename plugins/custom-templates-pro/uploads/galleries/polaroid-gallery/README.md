# Polaroid Gallery Template

Template per galleria fotografica con effetto foto istantanea polaroid.

## ⚠️ Struttura del Template (IMPORTANTE)

Il file ZIP deve contenere una **cartella** con il nome del template:

```text
polaroid-gallery.zip
└── polaroid-gallery/
    ├── metadata.json    ← OBBLIGATORIO!
    ├── template.twig    ← OBBLIGATORIO!
    ├── styles.css
    └── README.md
```

### Campi obbligatori in metadata.json

| Campo | Descrizione | Esempio |
|-------|-------------|---------|
| `type` | Tipo template | `"gallery"` |
| `name` | Nome visualizzato | `"Polaroid Gallery"` |
| `slug` | Identificatore URL | `"polaroid-gallery"` |
| `version` | Versione semver | `"1.0.0"` |

**Senza questi campi l'upload fallirà!**

## Caratteristiche

- ✅ Griglia responsive (4 colonne desktop, 3 tablet, 2 mobile)
- ✅ Rotazione casuale di ogni foto (-3° a +3°)
- ✅ Effetto ombra realistico
- ✅ Hover: raddrizza la foto e ingrandisce
- ✅ Didascalia stile macchina da scrivere
- ✅ Pulsante download (se abilitato)
- ✅ Integrazione PhotoSwipe lightbox
- ✅ Supporto dark mode

## Installazione

1. Comprimi questa directory in un file ZIP
2. Carica tramite Admin → Custom Templates → Carica Template
3. Seleziona "Template Galleria"
4. Upload completo!

## Personalizzazione

Puoi modificare:
- **Colonne**: Cambia `columns` in metadata.json
- **Gap**: Modifica `gap` per spaziatura
- **Rotazioni**: Modifica array `[-3, -2, -1, 0, 1, 2, 3]` in template.twig
- **Colori**: Modifica CSS in styles.css

## Preview

Template ideale per portfolio fotografici vintage o con stile analogico.
