================================================================================
CIMAISE HOMEPAGE TEMPLATE CREATION GUIDE
================================================================================

This guide will help you create a custom template for the portfolio homepage
in Cimaise using your preferred LLM or by editing the prompt manually.

================================================================================
PROMPT - COPY AND PASTE THIS TEXT
================================================================================

Create a Twig template for the homepage of a photography portfolio in Cimaise CMS.

TECHNICAL REQUIREMENTS:
- Template engine: Twig
- CSS Framework: Tailwind CSS 3.x
- JavaScript: Vanilla JS or lightweight libraries
- CSP: scripts with nonce="{{ csp_nonce() }}"
- SEO: Schema.org CollectionPage
- Dark mode: include html.dark overrides or explicitly mark the template as light-only in README
- Backgrounds: keep sections transparent so the site theme controls page backgrounds

ZIP FILE STRUCTURE:

⚠️  IMPORTANT: The ZIP file must contain a folder with the template name.
    Example: my-homepage.zip must contain my-homepage/metadata.json, etc.

1. metadata.json - Configuration (⚠️ REQUIRED - upload fails without this!)
2. home.twig - Homepage template (REQUIRED)
3. partials/ - Reusable partials (optional). Example files: hero.twig, albums-grid.twig
4. styles.css - CSS (optional)
5. script.js - JavaScript (optional)
6. preview.jpg - Preview (optional)

CORRECT ZIP structure:
```text
my-homepage.zip
└── my-homepage/
    ├── metadata.json    ← REQUIRED! Upload fails without this file
    ├── home.twig        ← REQUIRED!
    ├── styles.css       (optional)
    └── README.md        (optional)
```

metadata.json FORMAT (⚠️ ALL FIELDS type, name, slug, version ARE REQUIRED):
```json
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
```

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
- album.cover_image.url
- album.cover_image.sources (avif, webp, jpg)
- album.cover_image.width
- album.cover_image.height
- album.categories
- album.tags
- album.image_count
- album.is_nsfw

{{ categories }} - Array of available categories

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
// Animations, scroll effects, etc.
</script>
```

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

1. PERFORMANCE:
   - loading="lazy" and decoding="async" on all below-the-fold images
   - Explicit width/height dimensions to avoid CLS (Cumulative Layout Shift)
   - fetchpriority="high" only on the hero image (LCP)
   - Example:
     ```html
     <img src="hero.jpg" width="1600" height="900"
          fetchpriority="high" decoding="async">
     ```

2. IMAGE OPTIMIZATION:
   - Always use <picture> with progressive formats: AVIF → WebP → JPEG
   - Set correct sizes attribute to avoid excessive downloads:
     ```html
     <picture>
       <source type="image/avif" srcset="img-400.avif 400w, img-800.avif 800w, img-1200.avif 1200w"
               sizes="(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw">
       <source type="image/webp" srcset="...">
       <img src="img-800.jpg" alt="..." loading="lazy">
     </picture>
     ```
   - Hero: limit to 1600px max (avoid 4K on mobile, wastes bandwidth)
   - Thumbnail grid: 400-800px is sufficient

3. SEO AND STRUCTURED DATA:
   - JSON-LD CollectionPage for portfolio homepage:
     ```html
     <script type="application/ld+json">
     {
       "@context": "https://schema.org",
       "@type": "CollectionPage",
       "name": "{{ site_title }}",
       "description": "{{ site_description }}",
       "mainEntity": {
         "@type": "ItemList",
         "numberOfItems": {{ albums|length }}
       }
     }
     </script>
     ```
   - Open Graph: og:image with main cover (1200x630 ideal)
   - Unique title: "Portfolio | Photographer Name"

4. ACCESSIBILITY (WCAG 2.1):
   - Descriptive alt text: "Black and white portrait" not "IMG_001"
   - Minimum contrast 4.5:1 for normal text, 3:1 for large text
   - Visible focus on all links/buttons:
     ```css
     a:focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }
     ```
   - ARIA for interactive elements without text:
     ```html
     <button aria-label="Open navigation menu">
       <svg>...</svg>
     </button>
     ```
   - Skip link for keyboard navigation:
     ```html
     <a href="#main-content" class="sr-only focus:not-sr-only">
       Skip to content
     </a>
     ```

5. MOBILE AND TOUCH:
   - Single-column layout below 640px
   - Minimum touch target 44x44px (Apple HIG) or 48x48px (Material):
     ```css
     .nav-link { min-height: 44px; padding: 12px 16px; }
     ```
   - Hover effects are non-essential (degrade gracefully)
   - Avoid position:sticky on viewport < 768px (scroll issues)
   - Swipe gestures with appropriate touch-action:
     ```css
     .carousel { touch-action: pan-x; }
     ```

6. RESPONSIBLE JAVASCRIPT:
   - No jQuery or libraries > 50KB for simple animations
   - IntersectionObserver for scroll reveal:
     ```js
     const observer = new IntersectionObserver((entries) => {
       entries.forEach(e => e.isIntersecting && e.target.classList.add('visible'));
     }, { threshold: 0.1 });
     document.querySelectorAll('.album-card').forEach(el => observer.observe(el));
     ```
   - Respect prefers-reduced-motion:
     ```js
     const prefersReduced = matchMedia('(prefers-reduced-motion: reduce)').matches;
     if (!prefersReduced) { /* animations */ }
     ```
   - Debounce on scroll/resize (16ms = 60fps):
     ```js
     let ticking = false;
     window.addEventListener('scroll', () => {
       if (!ticking) {
         requestAnimationFrame(() => { /* ... */ ticking = false; });
         ticking = true;
       }
     });
     ```

CREATE A COMPLETE TEMPLATE with all necessary files.
