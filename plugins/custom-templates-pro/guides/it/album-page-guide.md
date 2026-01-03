================================================================================
GUIDA CREAZIONE TEMPLATE PAGINA ALBUM COMPLETA PER CIMAISE
================================================================================

Questa guida ti aiuterà a creare un template completo per la pagina album
(header, metadata, galleria e footer) in Cimaise usando il tuo LLM preferito
o adattando il prompt manualmente.

================================================================================
PROMPT - COPIA E INCOLLA QUESTO TESTO
================================================================================

Crea un template Twig completo per la pagina album in Cimaise CMS, includendo
header, metadata, corpo testo, equipment e galleria fotografica integrata.

REQUISITI TECNICI:
- Template engine: Twig
- Framework CSS: Tailwind CSS 3.x
- Lightbox: PhotoSwipe 5
- SEO: Schema.org, Open Graph
- CSP: script con nonce="{{ csp_nonce() }}"
- Dark mode: includi override per html.dark oppure dichiara in README che il template è solo light
- Background: mantieni trasparenti gli sfondi di pagina/galleria così il tema del sito decide i colori

STRUTTURA FILE ZIP:

⚠️  IMPORTANTE: Il file ZIP deve contenere una cartella con il nome del template.
    Esempio: my-album-page.zip deve contenere my-album-page/metadata.json, ecc.

1. metadata.json - Configurazione (⚠️ OBBLIGATORIO - senza questo l'upload fallisce!)
2. page.twig - Template pagina completa (OBBLIGATORIO)
3. gallery.twig - Template galleria (opzionale, se separato)
4. styles.css - CSS personalizzato (opzionale)
5. script.js - JavaScript (opzionale)
6. preview.jpg - Anteprima (opzionale)

STRUTTURA CORRETTA del file ZIP:
```text
my-album-page.zip
└── my-album-page/
    ├── metadata.json    ← OBBLIGATORIO! L'upload fallisce senza questo file
    ├── page.twig        ← OBBLIGATORIO!
    ├── styles.css       (opzionale)
    └── README.md        (opzionale)
```

FORMATO metadata.json (⚠️ TUTTI I CAMPI type, name, slug, version SONO OBBLIGATORI):
```json
{
  "type": "album_page",
  "name": "Modern Album Page",
  "slug": "modern-album-page",
  "description": "Pagina album con design moderno",
  "version": "1.0.0",
  "author": "Il tuo nome",
  "settings": {
    "gallery_layout": "masonry",
    "show_breadcrumbs": true,
    "show_social_sharing": true,
    "show_equipment": true
  },
  "assets": {
    "css": ["styles.css"],
    "js": ["script.js"]
  }
}
```

VARIABILI DISPONIBILI:
Vedi guida template galleria per lista completa variabili {{ album }} e {{ images }}.

Variabili aggiuntive per pagina completa:
- {{ site_title }} - Titolo sito
- {{ site_logo }} - URL logo
- {{ logo_type }} - 'text' o 'image'
- {{ base_path }} - Base URL
- {{ available_templates }} - Template gallerie disponibili per switcher
- {{ current_template_id }} - ID template corrente
- {{ album_ref }} - Reference album per AJAX

FUNZIONI E MACRO:
- {% import 'frontend/_seo_macros.twig' as Seo %}
- {% import 'frontend/_caption.twig' as Caption %}
- {{ trans('chiave') }} - Traduzione
- {{ csp_nonce() }} - Nonce CSP

STRUTTURA HTML RACCOMANDATA:

```twig
<div class="max-w-7xl mx-auto px-4 py-8">
  <!-- Breadcrumbs -->
  <nav class="mb-6">
    <a href="{{ base_path }}/">Home</a> /
    <span>{{ album.title }}</span>
  </nav>

  <!-- Header Album -->
  <header class="text-center mb-12">
    <!-- Categorie e Tag -->
    {% if album.categories|length > 0 %}
    <div class="flex gap-2 justify-center mb-4">
      {% for cat in album.categories %}
      <a href="{{ base_path }}/category/{{ cat.slug }}" class="px-3 py-1 rounded-full bg-neutral-100">
        {{ cat.name }}
      </a>
      {% endfor %}
    </div>
    {% endif %}

    <!-- Titolo -->
    <h1 class="text-4xl font-light mb-6">{{ album.title|e }}</h1>

    <!-- Metadata: Data e Location -->
    {% if album.shoot_date or album.equipment.locations|length > 0 %}
    <div class="flex gap-6 justify-center text-neutral-600">
      {% if album.shoot_date %}
      <div>
        <i class="fas fa-calendar"></i>
        <time>{{ album.shoot_date|date_format }}</time>
      </div>
      {% endif %}

      {% if album.equipment.locations|length > 0 %}
      <div>
        <i class="fas fa-map-marker"></i>
        {{ album.equipment.locations|join(', ') }}
      </div>
      {% endif %}
    </div>
    {% endif %}

    <!-- Excerpt -->
    <p class="text-lg text-neutral-600 mt-6">{{ album.excerpt|e }}</p>
  </header>

  <!-- Body -->
  {% if album.body %}
  <div class="prose max-w-3xl mx-auto mb-12">
    {{ album.body|safe_html }}
  </div>
  {% endif %}

  <!-- Equipment Section -->
  {% if album.equipment.cameras|length > 0 or album.equipment.lenses|length > 0 %}
  <div class="bg-neutral-50 rounded-lg p-6 mb-8">
    <h3 class="text-sm font-medium mb-3">{{ trans('album.equipment') }}</h3>
    <div class="grid md:grid-cols-5 gap-4 text-xs">
      {% if album.equipment.cameras|length > 0 %}
      <div>
        <i class="fas fa-camera mb-2"></i>
        {% for camera in album.equipment.cameras %}
        <div>{{ camera }}</div>
        {% endfor %}
      </div>
      {% endif %}

      {% if album.equipment.lenses|length > 0 %}
      <div>
        <i class="fas fa-dot-circle mb-2"></i>
        {% for lens in album.equipment.lenses %}
        <div>{{ lens }}</div>
        {% endfor %}
      </div>
      {% endif %}
    </div>
  </div>
  {% endif %}

  <!-- Template Switcher -->
  {% if available_templates|length > 0 %}
  <div class="flex justify-end mb-4">
    <div id="template-switcher" class="flex gap-1 bg-white border rounded p-1">
      {% for template in available_templates %}
      <button class="tpl-switch px-2 py-1 {{ template.id == current_template_id ? 'bg-black text-white' : '' }}"
              data-template-id="{{ template.id }}"
              data-album-ref="{{ album_ref }}">
        <!-- Icona template -->
      </button>
      {% endfor %}
    </div>
  </div>
  {% endif %}

  <!-- Galleria -->
  <div id="gallery-container">
    <!-- Includi template galleria o inline -->
    {% for image in images %}
    <div class="gallery-item">
      <a href="{{ base_path }}{{ image.lightbox_url }}"
         class="pswp-link"
         data-pswp-width="{{ image.width }}"
         data-pswp-height="{{ image.height }}">
        <img src="{{ base_path }}{{ image.fallback_src }}" alt="{{ image.alt|e }}" loading="lazy">
      </a>
    </div>
    {% endfor %}
  </div>
</div>

<script nonce="{{ csp_nonce() }}">
// Inizializzazione template
</script>
```

ESEMPIO COMPLETO disponibile nella documentazione del plugin.
