================================================================================
GUIDA CREAZIONE TEMPLATE GALLERIA PER CIMAISE
================================================================================

Questa guida ti aiuterà a creare un template personalizzato per le gallerie
degli album in Cimaise usando un LLM (Large Language Model) come Claude,
ChatGPT o altri assistenti AI.

================================================================================
PROMPT PER LLM - COPIA E INCOLLA QUESTO TESTO
================================================================================

Crea un template Twig personalizzato per una galleria fotografica in Cimaise CMS.

REQUISITI TECNICI:
- Template engine: Twig
- Framework CSS: Tailwind CSS 3.x (già incluso)
- Lightbox: PhotoSwipe 5 (già incluso, inizializzato automaticamente)
- Responsive: mobile-first
- Formato immagini: AVIF, WebP, JPG (con fallback automatico)
- CSP: tutti gli script inline devono usare nonce="{{ csp_nonce() }}"
- Dark mode: includi override per html.dark oppure dichiara in README che il template è solo light
- Background: lascia trasparente lo sfondo della galleria, il colore lo decide la pagina

STRUTTURA FILE ZIP RICHIESTA:

⚠️  IMPORTANTE: Il file ZIP deve contenere una cartella con il nome del template.
    Esempio: per un template "my-gallery", il file my-gallery.zip deve contenere
    una cartella my-gallery/ con tutti i file all'interno.

Crea i seguenti file per il template:

1. metadata.json - Configurazione template (⚠️ OBBLIGATORIO - senza questo l'upload fallisce!)
2. template.twig - Template principale (OBBLIGATORIO)
3. styles.css - CSS personalizzato (opzionale)
4. script.js - JavaScript personalizzato (opzionale)
5. preview.jpg - Anteprima 800x600px (opzionale)
6. README.md - Documentazione (opzionale)

STRUTTURA CORRETTA del file ZIP:
```text
my-gallery.zip
└── my-gallery/
    ├── metadata.json    ← OBBLIGATORIO! L'upload fallisce senza questo file
    ├── template.twig    ← OBBLIGATORIO!
    ├── styles.css       (opzionale)
    ├── script.js        (opzionale)
    ├── preview.jpg      (opzionale)
    └── README.md        (opzionale)
```

FORMATO metadata.json (⚠️ TUTTI I CAMPI type, name, slug, version SONO OBBLIGATORI):
```json
{
  "type": "gallery",
  "name": "Nome Template",
  "slug": "nome-template",
  "description": "Descrizione del template",
  "version": "1.0.0",
  "author": "Il tuo nome",
  "requires": {
    "cimaise": ">=1.0.0"
  },
  "settings": {
    "layout": "grid",
    "columns": {"desktop": 3, "tablet": 2, "mobile": 1},
    "gap": 20,
    "aspect_ratio": "1:1",
    "style": ["rounded", "shadow", "hover_scale"]
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

VARIABILI TWIG DISPONIBILI:

{{ album }} - Oggetto album con:
- album.id (int)
- album.title (string)
- album.slug (string)
- album.excerpt (string) - Breve descrizione
- album.body (string) - Descrizione HTML completa
- album.shoot_date (string) - Data formato YYYY-MM-DD
- album.categories (array) - Array di categorie
- album.tags (array) - Array di tag
- album.cover_image (object)
- album.is_nsfw (bool)
- album.allow_downloads (bool)
- album.allow_template_switch (bool)
- album.custom_cameras (string)
- album.custom_lenses (string)
- album.custom_films (string)
- album.equipment (object):
- album.equipment.cameras (array)
- album.equipment.lenses (array)
- album.equipment.film (array)
- album.equipment.developers (array)
- album.equipment.labs (array)
- album.equipment.locations (array)

{{ images }} - Array di immagini, ogni immagine ha:
- image.id (int)
- image.url (string) - URL immagine
- image.lightbox_url (string) - URL per lightbox
- image.fallback_src (string) - Fallback JPG
- image.width (int) - Larghezza originale
- image.height (int) - Altezza originale
- image.caption (string) - Didascalia
- image.alt (string) - Testo alternativo
- image.sort_order (int)

- image.sources (object) - Srcset per formati:
- image.sources.avif (array)
- image.sources.webp (array)
- image.sources.jpg (array)

- image.camera_name (string)
- image.custom_camera (string)
- image.lens_name (string)
- image.custom_lens (string)
- image.film_name (string)
- image.film_display (string)
- image.custom_film (string)
- image.developer_name (string)
- image.lab_name (string)
- image.location_name (string)

- image.iso (int)
- image.shutter_speed (string)
- image.aperture (string)
- image.focal_length (string)

- image.exif_make (string)
- image.exif_model (string)
- image.exif_lens_model (string)
- image.gps_lat (float)
- image.gps_lng (float)
- image.date_original (string)
- image.artist (string)
- image.copyright (string)

- image.custom_fields (array) - Campi personalizzati

{{ template_settings }} - Settings JSON del template:
- template_settings.template_slug (string)
- template_settings.layout (string)
- template_settings.columns (object)
- template_settings.gap (object)
- template_settings.aspect_ratio (string)
- template_settings.style (object)

{{ base_path }} - Base path dell'applicazione
{{ site_title }} - Titolo del sito
{{ csp_nonce() }} - Funzione per generare nonce CSP

FUNZIONI TWIG DISPONIBILI:
- {{ trans('chiave') }} - Traduzione i18n
- {{ image.caption|e }} - Escape HTML
- {{ content|safe_html }} - HTML sanitizzato
- {{ date|date_format }} - Formattazione data

STRUTTURA HTML RACCOMANDATA per template.twig:

```twig
{% for image in images %}
<div class="gallery-item">
  <a href="{{ image.lightbox_url }}"
     class="pswp-link gallery-link"
     data-image-id="{{ image.id }}"
     data-pswp-width="{{ image.width }}"
     data-pswp-height="{{ image.height }}"
     data-pswp-caption="{{ image.caption|e('html_attr') }}"
     data-title="{{ image.caption|e }}"
     data-camera="{{ image.camera_name|default(image.custom_camera)|default('')|e }}"
     data-lens="{{ image.lens_name|default(image.custom_lens)|default('')|e }}">

    <picture>
      {% if image.sources.avif|length %}
      <source type="image/avif" srcset="{% for src in image.sources.avif %}{{ base_path }}{{ src }}{% if not loop.last %}, {% endif %}{% endfor %}">
      {% endif %}

      {% if image.sources.webp|length %}
      <source type="image/webp" srcset="{% for src in image.sources.webp %}{{ base_path }}{{ src }}{% if not loop.last %}, {% endif %}{% endfor %}">
      {% endif %}

      {% if image.sources.jpg|length %}
      <source type="image/jpeg" srcset="{% for src in image.sources.jpg %}{{ base_path }}{{ src }}{% if not loop.last %}, {% endif %}{% endfor %}">
      {% endif %}

      <img src="{{ base_path }}{{ image.fallback_src }}"
           alt="{{ image.alt|e }}"
           loading="lazy"
           decoding="async">
    </picture>

    {% if album.allow_downloads %}
    <button type="button" data-action="download-image" data-download-url="{{ base_path }}/download/image/{{ image.id }}">
      <i class="fa-solid fa-arrow-down"></i>
    </button>
    {% endif %}
  </a>
</div>
{% endfor %}

<script nonce="{{ csp_nonce() }}">
// JavaScript personalizzato
window.templateSettings = {{ template_settings|json_encode|raw }};
</script>
```

BEST PRACTICES:

1. RESPONSIVE: Usa Tailwind breakpoints (sm:, md:, lg:, xl:, 2xl:)
2. PERFORMANCE: Usa lazy loading per immagini
3. ACCESSIBILITY: Includi alt text e attributi ARIA
4. LIGHTBOX: Usa classe .pswp-link e attributi data-pswp-*
5. CSP: SEMPRE usa nonce="{{ csp_nonce() }}" per script inline
6. DOWNLOAD: Controlla album.allow_downloads prima di mostrare pulsante
7. CSS: Preferisci Tailwind CSS, usa CSS custom solo se necessario
8. JS: Evita librerie esterne pesanti, usa vanilla JS

ESEMPI DI LAYOUT:

1. GRID CLASSICA:
- CSS Grid con colonne fisse
- Aspect ratio uniforme
- Gap personalizzabile

2. MASONRY:
- Layout a cascata tipo Pinterest
- Altezze variabili
- Usa libreria Masonry.js (già inclusa)

3. MAGAZINE:
- Layout editoriale
- Mix di dimensioni immagini
- Effetti parallax

4. POLAROID:
- Effetto foto istantanea
- Rotazione casuale
- Ombra e bordo

CREA UN TEMPLATE COMPLETO con tutti i file necessari.

================================================================================
ESEMPIO COMPLETO: TEMPLATE GRID CLASSICA
================================================================================

--- metadata.json ---
```text
{
  "type": "gallery",
  "name": "Classic Grid Gallery",
  "slug": "classic-grid",
  "description": "Griglia fotografica classica con aspect ratio uniforme",
  "version": "1.0.0",
  "author": "Cimaise",
  "settings": {
    "layout": "grid",
    "columns": {"desktop": 3, "tablet": 2, "mobile": 1},
    "gap": 20,
    "aspect_ratio": "1:1"
  },
  "libraries": {
    "photoswipe": true
  },
  "assets": {
    "css": ["styles.css"]
  }
}

--- template.twig ---
{% set cols = template_settings.columns|default({desktop: 3, tablet: 2, mobile: 1}) %}
{% set gap = template_settings.gap|default(20) %}

<div class="gallery-grid" style="--gap: {{ gap }}px; --cols-mobile: {{ cols.mobile }}; --cols-tablet: {{ cols.tablet }}; --cols-desktop: {{ cols.desktop }};">
  {% for image in images %}
  <div class="gallery-item">
    <a href="{{ base_path }}{{ image.lightbox_url|default(image.url) }}"
       class="pswp-link gallery-link block aspect-square overflow-hidden rounded-lg shadow-md hover:shadow-xl transition-shadow group"
       data-image-id="{{ image.id }}"
       data-pswp-width="{{ image.width }}"
       data-pswp-height="{{ image.height }}"
       data-pswp-caption="{{ image.caption|e('html_attr') }}">

      <picture>
        {% if image.sources.avif|length %}
        <source type="image/avif" srcset="{% for src in image.sources.avif %}{{ base_path }}{{ src|split(' ')|first }} {{ src|split(' ')|slice(1)|join(' ') }}{% if not loop.last %}, {% endif %}{% endfor %}">
        {% endif %}

        {% if image.sources.webp|length %}
        <source type="image/webp" srcset="{% for src in image.sources.webp %}{{ base_path }}{{ src|split(' ')|first }} {{ src|split(' ')|slice(1)|join(' ') }}{% if not loop.last %}, {% endif %}{% endfor %}">
        {% endif %}

        <img src="{{ base_path }}{{ image.fallback_src }}"
             alt="{{ image.alt|e }}"
             class="w-full h-full object-cover transition-transform group-hover:scale-105"
             loading="lazy">
      </picture>

      {% if image.caption %}
      <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 p-4 text-white text-sm opacity-0 group-hover:opacity-100 transition-opacity">
        {{ image.caption|e }}
      </div>
      {% endif %}
    </a>
  </div>
  {% endfor %}
</div>

<script nonce="{{ csp_nonce() }}">
window.templateSettings = {{ template_settings|json_encode|raw }};
</script>

--- styles.css ---
.gallery-grid {
  display: grid;
  grid-template-columns: repeat(var(--cols-mobile), 1fr);
  gap: var(--gap);
  padding: 1rem;
}

@media (min-width: 768px) {
  .gallery-grid {
    grid-template-columns: repeat(var(--cols-tablet), 1fr);
  }
}

@media (min-width: 1024px) {
  .gallery-grid {
    grid-template-columns: repeat(var(--cols-desktop), 1fr);
  }
}

.gallery-item {
  position: relative;
}
```

================================================================================
ISTRUZIONI FINALI
================================================================================

1. Crea tutti i file richiesti
2. Comprimi in un file ZIP con la struttura corretta
3. Carica il ZIP tramite il plugin Custom Templates Pro
4. Il template sarà disponibile immediatamente in:
- Pagina creazione/modifica album
- Impostazioni generali (template predefinito)
- Template switcher nella pagina album

Per domande o supporto, consulta la documentazione di Cimaise.
