================================================================================
GUIDA CREAZIONE TEMPLATE HOMEPAGE PER CIMAISE
================================================================================

Questa guida ti aiuterà a creare un template personalizzato per la homepage
del portfolio in Cimaise usando il tuo LLM preferito o adattando il prompt
manualmente.

================================================================================
PROMPT - COPIA E INCOLLA QUESTO TESTO
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

PRELOADER PRIMA VISITA (IMPORTANTE):
I layout base (_layout.twig e _layout_modern.twig) includono un preloader che mostra
il logo o il titolo del sito alla prima visita dell'utente per sessione.
Per ereditare questo preloader, il template homepage custom DEVE estendere uno dei layout base:

```twig
{# All'inizio del file home.twig #}
{% extends 'frontend/_layout.twig' %}

{% block content %}
{# Il tuo contenuto homepage personalizzato qui #}
{% endblock %}
```

Oppure per template stile moderno:
```twig
{% extends 'frontend/_layout_modern.twig' %}
```

Il preloader:
- Usa sessionStorage per mostrarsi solo alla prima visita per sessione browser
- Mostra il logo del sito (se configurato) o il titolo con le impostazioni tipografiche
- Animazione verso l'alto con easing cubic-bezier fluido
- Rispetta prefers-reduced-motion per accessibilità
- Supporta automaticamente il dark mode

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

{{ home_settings }} - Settings homepage da admin:
- home_settings.hero_title
- home_settings.hero_subtitle
- home_settings.hero_image
- home_settings.show_latest_albums
- home_settings.albums_count

================================================================================
FONTI IMMAGINI PER HOMEPAGE
================================================================================

La tua homepage può usare DUE diverse fonti di immagini a seconda del design:

OPZIONE A: SOLO FOTO COPERTINA ALBUM
------------------------------------
Usa l'array `albums` quando la homepage mostra card album con immagini di copertina.
Questo è l'approccio più semplice - non serve progressive loading.

Variabili disponibili:
- {{ albums }} - Array degli album pubblicati (default: 12)
- {{ album.cover_image }} - Oggetto immagine copertina con sources responsive

Esempio:
```twig
{% for album in albums %}
  <article class="album-card">
    <a href="{{ base_path }}/album/{{ album.slug }}">
      <picture>
        {% if album.cover_image.sources.avif|length %}
        <source type="image/avif"
                srcset="{% for src in album.cover_image.sources.avif %}{{ base_path }}{{ src }}{% if not loop.last %}, {% endif %}{% endfor %}">
        {% endif %}
        <img src="{{ base_path }}{{ album.cover_image.url }}"
             alt="{{ album.title|e }}"
             loading="lazy">
      </picture>
      <h2>{{ album.title|e }}</h2>
    </a>
  </article>
{% endfor %}
```

OPZIONE B: FOTO RANDOM DA TUTTI GLI ALBUM (con Progressive Loading)
-------------------------------------------------------------------
Usa l'array `all_images` quando la homepage mostra un mosaico/muro di foto
con immagini diverse da tutti gli album.

Variabili disponibili:
- {{ all_images }} - Batch iniziale di immagini random (dimensione iniziale variabile; dipende da configurazione/template; default = HomeImageService::DEFAULT_INITIAL_LIMIT)
- {{ has_more_images }} - Boolean: altre immagini disponibili via API
- {{ shown_image_ids }} - Array: IDs per deduplicazione
- {{ shown_album_ids }} - Array: IDs album già rappresentati

Ogni immagine in all_images ha:
- image.id - ID immagine
- image.url - URL immagine originale
- image.fallback_src - URL fallback JPG
- image.sources.avif, .webp, .jpg - Srcset responsive
- image.width, image.height - Dimensioni
- image.alt - Testo alternativo
- image.album_title, image.album_slug - Info album

Render Iniziale (SSR):
```twig
<div id="home-infinite-gallery">
  {% for image in all_images %}
    <picture data-image-id="{{ image.id }}">
      {% if image.sources.avif|length %}
      <source type="image/avif"
              srcset="{% for src in image.sources.avif %}{{ (base_path ~ src)|e('html_attr') }}{% if not loop.last %}, {% endif %}{% endfor %}"
              sizes="(min-width:1024px) 50vw, (min-width:640px) 70vw, 100vw">
      {% endif %}
      <img src="{{ (base_path ~ image.fallback_src)|e('html_attr') }}"
           alt="{{ image.alt|e }}"
           width="{{ image.width }}"
           height="{{ image.height }}"
           loading="{{ loop.index <= 6 ? 'eager' : 'lazy' }}">
    </picture>
  {% endfor %}
</div>

{# Configurazione progressive loading #}
{% if has_more_images %}
<script nonce="{{ csp_nonce() }}">
  window.homeLoaderConfig = {
    shownImageIds: {{ shown_image_ids|json_encode|raw }},
    shownAlbumIds: {{ shown_album_ids|json_encode|raw }},
    hasMore: true,
    basePath: {{ base_path|json_encode|raw }}
  };
</script>
<div id="home-load-trigger" class="h-1"></div>
{% endif %}
```

JavaScript Progressive Loading (script.js):
```javascript
const config = window.homeLoaderConfig;
if (config?.hasMore) {
  const shownIds = new Set(config.shownImageIds);
  let shownAlbumIds = [...config.shownAlbumIds];
  let loading = false;
  let hasMore = true;

  const loadMore = async () => {
    if (loading || !hasMore) return;
    loading = true;

    const params = new URLSearchParams({
      exclude: Array.from(shownIds).join(','),
      excludeAlbums: shownAlbumIds.join(','),
      limit: 20
    });

    const res = await fetch(`${config.basePath}/api/home/gallery?${params}`);
    const data = await res.json();

    data.images.forEach(img => {
      if (shownIds.has(img.id)) return; // Salta duplicati
      shownIds.add(img.id);
      appendImageToGallery(img); // La tua funzione di append al DOM
    });

    shownAlbumIds.push(...data.newAlbumIds);
    hasMore = data.hasMore;
    loading = false;
  };

  // Carica quando trigger diventa visibile
  new IntersectionObserver(entries => {
    if (entries[0].isIntersecting) loadMore();
  }, { rootMargin: '200px' }).observe(document.getElementById('home-load-trigger'));

  // Inizia caricamento immediato
  loadMore();
}
```

Formato Risposta API:
GET /api/home/gallery?exclude=1,2,3&excludeAlbums=1,2&limit=20

Risposta:
```json
{
  "images": [
    {
      "id": 42,
      "url": "/media/photos/img.jpg",
      "fallback_src": "/media/photos/variants/img_lg.jpg",
      "sources": {
        "avif": ["/media/.../img_sm.avif 400w", ...],
        "webp": [...],
        "jpg": [...]
      },
      "width": 1600,
      "height": 1067,
      "alt": "...",
      "album_title": "...",
      "album_slug": "..."
    }
  ],
  "newAlbumIds": [3, 4, 5],
  "hasMore": true
}
```

================================================================================

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
            <source type="image/avif"
                    srcset="{% for src in album.cover_image.sources.avif %}{{ base_path }}{{ src }}{% if not loop.last %}, {% endif %}{% endfor %}"
                    sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw">
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

1. PERFORMANCE:
   - loading="lazy" e decoding="async" su tutte le immagini below-the-fold
   - Dimensioni esplicite width/height per evitare CLS (Cumulative Layout Shift)
   - fetchpriority="high" solo sull'immagine hero (LCP)
   - Esempio:
     ```html
     <img src="hero.jpg" width="1600" height="900"
          fetchpriority="high" decoding="async">
     ```

2. OTTIMIZZAZIONE IMMAGINI:
   - Usa sempre <picture> con formati progressivi: AVIF → WebP → JPEG
   - Imposta sizes corretto per evitare download eccessivi:
     ```html
     <picture>
       <source type="image/avif" srcset="img-400.avif 400w, img-800.avif 800w, img-1200.avif 1200w"
               sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw">
       <source type="image/webp" srcset="...">
       <img src="img-800.jpg" alt="..." loading="lazy">
     </picture>
     ```
   - Hero: limita a 1600px max (evita 4K su mobile, spreco banda)
   - Thumbnail grid: 400-800px sufficienti

3. SEO E STRUCTURED DATA:
   - JSON-LD CollectionPage per homepage portfolio:
     ```html
     <script type="application/ld+json">
     {
       "@context": "https://schema.org",
       "@type": "CollectionPage",
       "name": {{ site_title|json_encode|raw }},
       "description": {{ site_description|json_encode|raw }},
       "mainEntity": {
         "@type": "ItemList",
         "numberOfItems": {{ albums|length }}
       }
     }
     </script>
     ```
   - Open Graph: og:image con cover principale (1200x630 ideale)
   - Title univoco: "Portfolio | Nome Fotografo"

4. ACCESSIBILITÀ (WCAG 2.1):
   - Alt text descrittivo: "Ritratto in bianco e nero" non "IMG_001"
   - Contrasto minimo 4.5:1 per testo normale, 3:1 per testo grande
   - Focus visibile su tutti i link/pulsanti:
     ```css
     a:focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }
     ```
   - ARIA per elementi interattivi senza testo:
     ```html
     <button aria-label="Apri menu navigazione">
       <svg>...</svg>
     </button>
     ```
   - Skip link per navigazione keyboard:
     ```html
     <a href="#main-content" class="sr-only focus:not-sr-only">
       Vai al contenuto
     </a>
     ```

5. MOBILE E TOUCH:
   - Layout single-column sotto 640px
   - Target touch minimo 44x44px (Apple HIG) o 48x48px (Material):
     ```css
     .nav-link { min-height: 44px; padding: 12px 16px; }
     ```
   - Hover effects non essenziali (degradano gracefully)
   - Evita position:sticky su viewport < 768px (problemi scroll)
   - Swipe gesture con touch-action appropriato:
     ```css
     .carousel { touch-action: pan-x; }
     ```

6. JAVASCRIPT RESPONSABILE:
   - Niente jQuery o librerie > 50KB per animazioni semplici
   - IntersectionObserver per reveal on scroll:
     ```js
     const observer = new IntersectionObserver((entries) => {
       entries.forEach(e => e.isIntersecting && e.target.classList.add('visible'));
     }, { threshold: 0.1 });
     document.querySelectorAll('.album-card').forEach(el => observer.observe(el));
     ```
   - Rispetta prefers-reduced-motion:
     ```js
     const prefersReduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
     if (!prefersReduced) { /* animazioni */ }
     ```
   - Debounce su scroll/resize (16ms = 60fps):
     ```js
     let ticking = false;
     window.addEventListener('scroll', () => {
       if (!ticking) {
         requestAnimationFrame(() => { /* ... */ ticking = false; });
         ticking = true;
       }
     });
     ```

CREA UN TEMPLATE COMPLETO con tutti i file necessari.
