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
- Dark mode: includi override per html.dark oppure dichiara in README che il template è solo light
- Background: mantieni trasparenti gli sfondi delle sezioni così il tema del sito decide i colori

STRUTTURA FILE ZIP:

⚠️  IMPORTANTE: Il file ZIP deve contenere una cartella con il nome del template.
    Esempio: my-homepage.zip deve contenere my-homepage/metadata.json, ecc.

1. metadata.json - Configurazione (⚠️ OBBLIGATORIO - senza questo l'upload fallisce!)
2. home.twig - Template homepage (OBBLIGATORIO)
3. partials/ - Partials riutilizzabili (opzionale). Esempio file: hero.twig, albums-grid.twig
4. styles.css - CSS (opzionale)
5. script.js - JavaScript (opzionale)
6. preview.jpg - Anteprima (opzionale)

STRUTTURA CORRETTA del file ZIP:
```text
my-homepage.zip
└── my-homepage/
    ├── metadata.json    ← OBBLIGATORIO! L'upload fallisce senza questo file
    ├── home.twig        ← OBBLIGATORIO!
    ├── styles.css       (opzionale)
    └── README.md        (opzionale)
```

FORMATO metadata.json (⚠️ TUTTI I CAMPI type, name, slug, version SONO OBBLIGATORI):
```json
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
```

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

```twig
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
```

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
