<?php
declare(strict_types=1);

namespace CustomTemplatesPro\Services;

class GuidesGeneratorService
{
    private string $pluginDir;

    public function __construct()
    {
        $this->pluginDir = dirname(__DIR__);
    }

    /**
     * Genera tutte le guide
     */
    public function generateAllGuides(): void
    {
        $this->generateGalleryGuide();
        $this->generateAlbumPageGuide();
        $this->generateHomepageGuide();
    }

    /**
     * Genera la guida per template gallerie
     */
    public function generateGalleryGuide(): void
    {
        $content = $this->getGalleryGuideContent();
        file_put_contents($this->pluginDir . '/guides/gallery-template-guide.txt', $content);
    }

    /**
     * Genera la guida per template pagina album
     */
    public function generateAlbumPageGuide(): void
    {
        $content = $this->getAlbumPageGuideContent();
        file_put_contents($this->pluginDir . '/guides/album-page-guide.txt', $content);
    }

    /**
     * Genera la guida per template homepage
     */
    public function generateHomepageGuide(): void
    {
        $content = $this->getHomepageGuideContent();
        file_put_contents($this->pluginDir . '/guides/homepage-guide.txt', $content);
    }

    /**
     * Contenuto guida template gallerie
     */
    private function getGalleryGuideContent(): string
    {
        return <<<'GUIDE'
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

STRUTTURA FILE ZIP RICHIESTA:
Crea i seguenti file per il template:

1. metadata.json - Configurazione template (OBBLIGATORIO)
2. template.twig - Template principale (OBBLIGATORIO)
3. styles.css - CSS personalizzato (opzionale)
4. script.js - JavaScript personalizzato (opzionale)
5. preview.jpg - Anteprima 800x600px (opzionale)
6. README.md - Documentazione (opzionale)

FORMATO metadata.json:
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

GUIDE
;
    }

    /**
     * Contenuto guida template pagina album
     */
    private function getAlbumPageGuideContent(): string
    {
        return <<<'GUIDE'
================================================================================
GUIDA CREAZIONE TEMPLATE PAGINA ALBUM COMPLETA PER CIMAISE
================================================================================

Questa guida ti aiuterà a creare un template completo per la pagina album
(header, metadata, galleria e footer) in Cimaise usando un LLM.

================================================================================
PROMPT PER LLM - COPIA E INCOLLA QUESTO TESTO
================================================================================

Crea un template Twig completo per la pagina album in Cimaise CMS, includendo
header, metadata, corpo testo, equipment e galleria fotografica integrata.

REQUISITI TECNICI:
- Template engine: Twig
- Framework CSS: Tailwind CSS 3.x
- Lightbox: PhotoSwipe 5
- SEO: Schema.org, Open Graph
- CSP: script con nonce="{{ csp_nonce() }}"

STRUTTURA FILE ZIP:
1. metadata.json - Configurazione (OBBLIGATORIO)
2. page.twig - Template pagina completa (OBBLIGATORIO)
3. gallery.twig - Template galleria (opzionale, se separato)
4. styles.css - CSS personalizzato (opzionale)
5. script.js - JavaScript (opzionale)
6. preview.jpg - Anteprima (opzionale)

FORMATO metadata.json:
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

ESEMPIO COMPLETO disponibile nella documentazione del plugin.

GUIDE
;
    }

    /**
     * Contenuto guida template homepage
     */
    private function getHomepageGuideContent(): string
    {
        return <<<'GUIDE'
================================================================================
GUIDA CREAZIONE TEMPLATE HOMEPAGE PER CIMAISE
================================================================================

Questa guida ti aiuterà a creare un template personalizzato per la homepage
del portfolio in Cimaise usando un LLM.

================================================================================
PROMPT PER LLM - COPIA E INCOLLA QUESTO TESTO
================================================================================

Crea un template Twig per la homepage di un portfolio fotografico in Cimaise CMS.

REQUISITI TECNICI:
- Template engine: Twig
- Framework CSS: Tailwind CSS 3.x
- JavaScript: Vanilla JS o librerie leggere
- CSP: script con nonce="{{ csp_nonce() }}"
- SEO: Schema.org CollectionPage

STRUTTURA FILE ZIP:
1. metadata.json - Configurazione (OBBLIGATORIO)
2. home.twig - Template homepage (OBBLIGATORIO)
3. partials/ - Partials riutilizzabili (opzionale)
   - hero.twig
   - albums-grid.twig
4. styles.css - CSS (opzionale)
5. script.js - JavaScript (opzionale)
6. preview.jpg - Anteprima (opzionale)

FORMATO metadata.json:
{
  "type": "homepage",
  "name": "Modern Hero Homepage",
  "slug": "modern-hero",
  "description": "Homepage con hero section e griglia album",
  "version": "1.0.0",
  "author": "Il tuo nome",
  "settings": {
    "layout": "hero_grid",
    "show_hero": true,
    "albums_per_page": 12,
    "grid_columns": {"desktop": 3, "tablet": 2, "mobile": 1}
  },
  "assets": {
    "css": ["styles.css"],
    "js": ["script.js"]
  }
}

VARIABILI DISPONIBILI:

{{ site_title }} - Titolo sito
{{ site_logo }} - URL logo
{{ logo_type }} - 'text' o 'image'
{{ site_description }} - Descrizione sito
{{ site_tagline }} - Tagline
{{ base_path }} - Base URL

{{ albums }} - Array di album, ogni album ha:
  - album.id
  - album.title
  - album.slug
  - album.excerpt
  - album.shoot_date
  - album.cover_image:
      - album.cover_image.url
      - album.cover_image.sources (avif, webp, jpg)
      - album.cover_image.width
      - album.cover_image.height
  - album.categories
  - album.tags
  - album.image_count
  - album.is_nsfw

{{ categories }} - Array categorie disponibili
{{ featured_albums }} - Album in evidenza (se configurato)

{{ home_settings }} - Settings homepage da admin:
  - home_settings.hero_title
  - home_settings.hero_subtitle
  - home_settings.hero_image
  - home_settings.show_latest_albums
  - home_settings.albums_count

LAYOUT TIPICI:

1. HERO + GRID:
   - Hero section con immagine/video
   - Griglia album sotto

2. MASONRY INFINITO:
   - Masonry scroll infinito
   - Lazy loading immagini

3. CAROUSEL:
   - Carousel orizzontale album
   - Navigazione frecce

4. FULLSCREEN GALLERY:
   - Galleria fullscreen
   - Navigazione minimale

STRUTTURA HTML RACCOMANDATA:

<!-- Hero Section -->
<section class="hero min-h-screen flex items-center justify-center bg-black text-white">
  <div class="text-center">
    <h1 class="text-6xl font-light mb-4">{{ site_title|e }}</h1>
    <p class="text-xl text-neutral-400">{{ site_tagline|e }}</p>
  </div>
</section>

<!-- Albums Grid -->
<section class="albums-grid max-w-7xl mx-auto px-4 py-16">
  <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
    {% for album in albums %}
    <article class="album-card group">
      <a href="{{ base_path }}/album/{{ album.slug }}" class="block">
        <div class="aspect-square overflow-hidden rounded-lg mb-4">
          <picture>
            {% if album.cover_image.sources.avif|length %}
            <source type="image/avif" srcset="{{ base_path }}{{ album.cover_image.sources.avif[0] }}">
            {% endif %}
            <img src="{{ base_path }}{{ album.cover_image.url }}"
                 alt="{{ album.title|e }}"
                 class="w-full h-full object-cover transition-transform group-hover:scale-105"
                 loading="lazy">
          </picture>
        </div>

        <h2 class="text-xl font-light mb-2">{{ album.title|e }}</h2>
        <p class="text-sm text-neutral-600">{{ album.excerpt|e }}</p>

        <div class="flex gap-2 mt-3">
          {% for cat in album.categories %}
          <span class="text-xs px-2 py-1 bg-neutral-100 rounded">{{ cat.name }}</span>
          {% endfor %}
        </div>
      </a>
    </article>
    {% endfor %}
  </div>
</section>

<script nonce="{{ csp_nonce() }}">
// Animazioni, scroll effects, etc.
</script>

ESEMPI DI EFFETTI:

1. PARALLAX SCROLL:
   - Background images con parallax
   - Usa IntersectionObserver

2. FADE-IN ON SCROLL:
   - Album appaiono scrollando
   - Animazioni GSAP o CSS

3. INFINITE SCROLL:
   - Caricamento dinamico album
   - AJAX pagination

4. VIDEO BACKGROUND:
   - Video hero background
   - Autoplay muted loop

BEST PRACTICES:
- Performance: lazy loading, image optimization
- SEO: Schema.org, meta tags
- Accessibility: ARIA labels, keyboard navigation
- Mobile: responsive, touch-friendly

CREA UN TEMPLATE COMPLETO con tutti i file necessari.

GUIDE
;
    }

    /**
     * Ottiene il percorso di una guida
     */
    public function getGuidePath(string $type): string
    {
        $filename = match ($type) {
            'gallery' => 'gallery-template-guide.txt',
            'album_page' => 'album-page-guide.txt',
            'homepage' => 'homepage-guide.txt',
            default => throw new \InvalidArgumentException("Invalid guide type: {$type}")
        };

        return $this->pluginDir . '/guides/' . $filename;
    }

    /**
     * Verifica se le guide esistono
     */
    public function guidesExist(): bool
    {
        return file_exists($this->getGuidePath('gallery'))
            && file_exists($this->getGuidePath('album_page'))
            && file_exists($this->getGuidePath('homepage'));
    }
}
