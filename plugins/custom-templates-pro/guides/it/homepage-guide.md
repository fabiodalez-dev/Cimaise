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
