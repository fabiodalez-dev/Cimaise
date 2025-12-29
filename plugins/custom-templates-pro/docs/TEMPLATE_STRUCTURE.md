# Struttura Template Custom

Questa guida descrive in dettaglio la struttura richiesta per i template personalizzati in Custom Templates Pro.

## 📦 Struttura ZIP

Ogni template deve essere compresso in un file ZIP con la seguente struttura:

```
my-custom-template.zip
├── metadata.json          # Configurazione template (OBBLIGATORIO)
├── template.twig          # Template principale (OBBLIGATORIO)
│                          # OPPURE page.twig (album_page)
│                          # OPPURE home.twig (homepage)
├── styles.css             # CSS personalizzato (opzionale)
├── script.js              # JavaScript (opzionale)
├── preview.jpg            # Anteprima 800x600px (opzionale)
├── partials/              # Partials Twig (opzionale)
│   └── ...
└── README.md              # Documentazione (opzionale)
```

## 📄 metadata.json

File di configurazione JSON che descrive il template.

### Schema Generale

```json
{
  "type": "gallery|album_page|homepage",
  "name": "Nome Template",
  "slug": "nome-template",
  "description": "Descrizione del template",
  "version": "1.0.0",
  "author": "Nome Autore",
  "requires": {
    "cimaise": ">=1.0.0"
  },
  "settings": {},
  "libraries": {},
  "assets": {}
}
```

### Campi Obbligatori

| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `type` | string | Tipo template: `gallery`, `album_page`, `homepage` |
| `name` | string | Nome visualizzato (es. "Modern Grid") |
| `slug` | string | Identificatore univoco (solo lowercase, numeri, trattini) |
| `version` | string | Versione semver (es. "1.0.0") |

### Campi Opzionali

| Campo | Tipo | Descrizione |
|-------|------|-------------|
| `description` | string | Descrizione template |
| `author` | string | Nome autore |
| `requires.cimaise` | string | Versione minima Cimaise richiesta |
| `settings` | object | Configurazioni specifiche per tipo |
| `libraries` | object | Librerie JavaScript richieste |
| `assets` | object | File CSS/JS da caricare |

## 📐 Template Gallery

### metadata.json per Gallery

```json
{
  "type": "gallery",
  "name": "Modern Polaroid Grid",
  "slug": "modern-polaroid",
  "description": "Griglia con effetto polaroid e animazioni hover",
  "version": "1.0.0",
  "author": "Your Name",
  "settings": {
    "layout": "grid|masonry|dense_grid|magazine",
    "columns": {
      "desktop": 3,
      "tablet": 2,
      "mobile": 1
    },
    "gap": 20,
    "aspect_ratio": "1:1|4:3|16:9|3:2|auto",
    "style": ["rounded", "shadow", "hover_scale", "hover_fade"]
  },
  "libraries": {
    "masonry": false,
    "photoswipe": true
  },
  "assets": {
    "css": ["styles.css"],
    "js": ["script.js"]
  }
}
```

### template.twig per Gallery

File principale che renderizza la griglia di immagini.

**Requisiti minimi:**

```twig
{% for image in images %}
<div class="gallery-item">
  <a href="{{ base_path }}{{ image.lightbox_url|default(image.url) }}"
     class="pswp-link"
     data-pswp-width="{{ image.width }}"
     data-pswp-height="{{ image.height }}"
     data-pswp-caption="{{ image.caption|e('html_attr') }}">

    <picture>
      {% if image.sources.avif|length %}
      <source type="image/avif" srcset="{% for src in image.sources.avif %}{{ base_path }}{{ src }}{% if not loop.last %}, {% endif %}{% endfor %}">
      {% endif %}

      {% if image.sources.webp|length %}
      <source type="image/webp" srcset="{% for src in image.sources.webp %}{{ base_path }}{{ src }}{% if not loop.last %}, {% endif %}{% endfor %}">
      {% endif %}

      <img src="{{ base_path }}{{ image.fallback_src }}"
           alt="{{ image.alt|e }}"
           loading="lazy"
           decoding="async">
    </picture>
  </a>
</div>
{% endfor %}

<script nonce="{{ csp_nonce() }}">
window.templateSettings = {{ template_settings|json_encode|raw }};
</script>
```

**Note importanti:**
- Classe `pswp-link` è richiesta per PhotoSwipe lightbox
- Attributi `data-pswp-*` necessari per lightbox
- Usa `{{ base_path }}` per tutti gli URL
- Tag `<picture>` per supporto multi-formato
- Script con `nonce="{{ csp_nonce() }}"` per CSP

## 📄 Template Album Page

### metadata.json per Album Page

```json
{
  "type": "album_page",
  "name": "Modern Album Page",
  "slug": "modern-album-page",
  "description": "Pagina album con design moderno",
  "version": "1.0.0",
  "settings": {
    "gallery_layout": "masonry|grid",
    "show_breadcrumbs": true,
    "show_social_sharing": true,
    "show_equipment": true,
    "header_style": "centered|left|minimal"
  },
  "assets": {
    "css": ["styles.css"]
  }
}
```

### page.twig per Album Page

Template completo della pagina album.

**Struttura raccomandata:**

```twig
<div class="album-page">
  <!-- Breadcrumbs -->
  <nav class="breadcrumbs">...</nav>

  <!-- Header Album -->
  <header class="album-header">
    <!-- Categorie e Tag -->
    {% for cat in album.categories %}
      <a href="{{ base_path }}/category/{{ cat.slug }}">{{ cat.name }}</a>
    {% endfor %}

    <!-- Titolo -->
    <h1>{{ album.title|e }}</h1>

    <!-- Metadata -->
    {% if album.shoot_date %}
      <time>{{ album.shoot_date|date_format }}</time>
    {% endif %}

    <!-- Excerpt -->
    <p>{{ album.excerpt|e }}</p>
  </header>

  <!-- Body -->
  {% if album.body %}
    <div class="album-body">
      {{ album.body|safe_html }}
    </div>
  {% endif %}

  <!-- Equipment -->
  {% if album.equipment.cameras|length > 0 %}
    <div class="equipment-section">
      <h3>{{ trans('album.equipment') }}</h3>
      <!-- Equipment items -->
    </div>
  {% endif %}

  <!-- Template Switcher -->
  {% if available_templates|length > 0 %}
    <div id="template-switcher">
      <!-- Switcher buttons -->
    </div>
  {% endif %}

  <!-- Galleria -->
  <div id="gallery-container">
    <!-- Include gallery template o inline -->
  </div>
</div>
```

## 🏠 Template Homepage

### metadata.json per Homepage

```json
{
  "type": "homepage",
  "name": "Hero + Masonry Homepage",
  "slug": "hero-masonry",
  "description": "Homepage con hero section e masonry infinito",
  "version": "1.0.0",
  "settings": {
    "layout": "hero_grid|fullscreen|carousel|masonry",
    "show_hero": true,
    "hero_height": "full|half|auto",
    "albums_per_page": 12,
    "grid_columns": {
      "desktop": 3,
      "tablet": 2,
      "mobile": 1
    },
    "enable_infinite_scroll": false
  },
  "assets": {
    "css": ["styles.css"],
    "js": ["script.js"]
  }
}
```

### home.twig per Homepage

Template homepage completo.

**Struttura raccomandata:**

```twig
<!-- Hero Section -->
<section class="hero">
  <div class="hero-content">
    <h1>{{ site_title|e }}</h1>
    <p>{{ site_tagline|e }}</p>
  </div>
</section>

<!-- Albums Grid -->
<section class="albums-section">
  <div class="albums-grid">
    {% for album in albums %}
    <article class="album-card">
      <a href="{{ base_path }}/album/{{ album.slug }}">
        <!-- Cover Image -->
        <div class="album-cover">
          <img src="{{ base_path }}{{ album.cover_image.url }}"
               alt="{{ album.title|e }}"
               loading="lazy">
        </div>

        <!-- Info -->
        <h2>{{ album.title|e }}</h2>
        <p>{{ album.excerpt|e }}</p>

        <!-- Categories -->
        {% for cat in album.categories %}
          <span class="category">{{ cat.name }}</span>
        {% endfor %}
      </a>
    </article>
    {% endfor %}
  </div>
</section>
```

## 🎨 CSS Personalizzato

Il file `styles.css` può contenere CSS personalizzato.

**Best practices:**

```css
/* Usa namespace per evitare conflitti */
.my-template-gallery {
  /* Stili */
}

/* Usa variabili CSS */
:root {
  --my-template-gap: 20px;
  --my-template-radius: 8px;
}

/* Responsive con breakpoints Tailwind */
@media (min-width: 768px) {
  /* Tablet */
}

@media (min-width: 1024px) {
  /* Desktop */
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
  /* Dark styles */
}

/* NO @import URL esterni (bloccato per sicurezza) */
/* @import url('https://...'); ❌ */
```

## 📜 JavaScript Personalizzato

Il file `script.js` può contenere JavaScript.

**Best practices:**

```javascript
// Usa IIFE per evitare conflitti
(function() {
  'use strict';

  // Accedi a template settings
  const settings = window.templateSettings;

  // Inizializzazione
  function init() {
    // Setup codice
  }

  // DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
```

**Restrizioni sicurezza:**
- ❌ `eval()` non consentito
- ❌ `Function()` constructor non consentito
- ❌ `new Function()` non consentito
- ✅ Vanilla JS consentito
- ✅ Librerie leggere OK

## 🖼️ Preview Image

File opzionale `preview.jpg` o `preview.png`:

- **Dimensioni consigliate**: 800x600px
- **Formato**: JPG o PNG
- **Peso max**: 200 KB
- **Contenuto**: Screenshot rappresentativo del template

## ✅ Validazione

Il plugin valida automaticamente:

1. **Struttura ZIP**: presenza file obbligatori
2. **metadata.json**: JSON valido, campi richiesti
3. **Template Twig**: sintassi valida, tag bilanciati
4. **CSS**: no @import URL, no expression()
5. **JavaScript**: no eval(), no malware patterns
6. **Path**: no path traversal (`../`)
7. **Dimensioni**: max 10 MB
8. **Estensioni**: solo whitelist consentite

## 🔧 Debugging

### Errori Comuni

**"File obbligatorio mancante: metadata.json"**
- Assicurati che `metadata.json` sia nella root del ZIP

**"Tipo template non corretto"**
- Verifica che `type` in metadata.json corrisponda al tipo selezionato

**"Slug deve contenere solo lettere minuscole"**
- Usa solo `a-z`, `0-9`, `-` nello slug

**"Tag Twig non bilanciati"**
- Controlla che ogni `{{` abbia un `}}`
- Controlla che ogni `{%` abbia un `%}`

**"Pattern sospetto rilevato"**
- Rimuovi codice potenzialmente pericoloso (eval, exec, ecc.)

## 📚 Risorse

- **Variabili Twig**: Vedi `VARIABLES_REFERENCE.md`
- **Guide LLM**: Scarica da Admin → Custom Templates → Guide
- **Esempi**: Vedi template core in `app/Views/frontend/`

---

**Note**: Questa struttura è soggetta a evoluzione. Verifica sempre la documentazione aggiornata.
