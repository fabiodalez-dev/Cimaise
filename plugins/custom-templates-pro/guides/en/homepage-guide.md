================================================================================
CIMAISE HOMEPAGE TEMPLATE CREATION GUIDE
================================================================================

This guide will help you create a custom template for the portfolio homepage
in Cimaise using an LLM.

================================================================================
PROMPT FOR LLM - COPY AND PASTE THIS TEXT
================================================================================

Create a Twig template for the homepage of a photography portfolio in Cimaise CMS.

TECHNICAL REQUIREMENTS:
- Template engine: Twig
- CSS Framework: Tailwind CSS 3.x
- JavaScript: Vanilla JS or lightweight libraries
- CSP: scripts with nonce="{{ csp_nonce() }}"
- SEO: Schema.org CollectionPage

ZIP FILE STRUCTURE:

⚠️  IMPORTANT: The ZIP file must contain a folder with the template name.
    Example: my-homepage.zip must contain my-homepage/metadata.json, etc.

1. metadata.json - Configuration (⚠️ REQUIRED - upload fails without this!)
2. home.twig - Homepage template (REQUIRED)
3. partials/ - Reusable partials (optional)
   - hero.twig
   - albums-grid.twig
4. styles.css - CSS (optional)
5. script.js - JavaScript (optional)
6. preview.jpg - Preview (optional)

CORRECT ZIP structure:
```
my-homepage.zip
└── my-homepage/
    ├── metadata.json    ← REQUIRED! Upload fails without this file
    ├── home.twig        ← REQUIRED!
    ├── styles.css       (optional)
    └── README.md        (optional)
```

metadata.json FORMAT (⚠️ ALL FIELDS type, name, slug, version ARE REQUIRED):
{
  "type": "homepage",
  "name": "Modern Hero Homepage",
  "slug": "modern-hero",
  "description": "Homepage with hero section and album grid",
  "version": "1.0.0",
  "author": "Your name",
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

AVAILABLE VARIABLES:

{{ site_title }} - Site title
{{ site_logo }} - Logo URL
{{ logo_type }} - 'text' or 'image'
{{ site_description }} - Site description
{{ site_tagline }} - Tagline
{{ base_path }} - Base URL

{{ albums }} - Array of albums, each album has:
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

{{ categories }} - Array of available categories
{{ featured_albums }} - Featured albums (if configured)

{{ home_settings }} - Homepage settings from admin:
  - home_settings.hero_title
  - home_settings.hero_subtitle
  - home_settings.hero_image
  - home_settings.show_latest_albums
  - home_settings.albums_count

TYPICAL LAYOUTS:

1. HERO + GRID:
   - Hero section with image/video
   - Album grid below

2. INFINITE MASONRY:
   - Infinite scroll masonry
   - Lazy loading images

3. CAROUSEL:
   - Horizontal album carousel
   - Arrow navigation

4. FULLSCREEN GALLERY:
   - Fullscreen gallery
   - Minimal navigation

RECOMMENDED HTML STRUCTURE:

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
// Animations, scroll effects, etc.
</script>

EFFECT EXAMPLES:

1. PARALLAX SCROLL:
   - Background images with parallax
   - Use IntersectionObserver

2. FADE-IN ON SCROLL:
   - Albums appear while scrolling
   - GSAP or CSS animations

3. INFINITE SCROLL:
   - Dynamic album loading
   - AJAX pagination

4. VIDEO BACKGROUND:
   - Video hero background
   - Autoplay muted loop

BEST PRACTICES:
- Performance: lazy loading, image optimization
- SEO: Schema.org, meta tags
- Accessibility: ARIA labels, keyboard navigation
- Mobile: responsive, touch-friendly

CREATE A COMPLETE TEMPLATE with all necessary files.
