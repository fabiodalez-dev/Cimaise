================================================================================
CIMAISE GALLERY TEMPLATE CREATION GUIDE
================================================================================

This guide will help you create a custom template for album galleries
in Cimaise using an LLM (Large Language Model) like Claude, ChatGPT,
or other AI assistants.

================================================================================
PROMPT FOR LLM - COPY AND PASTE THIS TEXT
================================================================================

Create a custom Twig template for a photo gallery in Cimaise CMS.

TECHNICAL REQUIREMENTS:
- Template engine: Twig
- CSS Framework: Tailwind CSS 3.x (already included)
- Lightbox: PhotoSwipe 5 (already included, automatically initialized)
- Responsive: mobile-first
- Image formats: AVIF, WebP, JPG (with automatic fallback)
- CSP: all inline scripts must use nonce="{{ csp_nonce() }}"

REQUIRED ZIP FILE STRUCTURE:

⚠️  IMPORTANT: The ZIP file must contain a folder with the template name.
    Example: for a template "my-gallery", the file my-gallery.zip must contain
    a my-gallery/ folder with all files inside.

Create the following files for the template:

1. metadata.json - Template configuration (⚠️ REQUIRED - upload fails without this!)
2. template.twig - Main template (REQUIRED)
3. styles.css - Custom CSS (optional)
4. script.js - Custom JavaScript (optional)
5. preview.jpg - Preview 800x600px (optional)
6. README.md - Documentation (optional)

CORRECT ZIP structure:
```text
my-gallery.zip
└── my-gallery/
    ├── metadata.json    ← REQUIRED! Upload fails without this file
    ├── template.twig    ← REQUIRED!
    ├── styles.css       (optional)
    ├── script.js        (optional)
    ├── preview.jpg      (optional)
    └── README.md        (optional)
```

metadata.json FORMAT (⚠️ ALL FIELDS type, name, slug, version ARE REQUIRED):
```json
{
  "type": "gallery",
  "name": "Template Name",
  "slug": "template-name",
  "description": "Template description",
  "version": "1.0.0",
  "author": "Your name",
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

AVAILABLE TWIG VARIABLES:

{{ album }} - Album object with:
- album.id (int)
- album.title (string)
- album.slug (string)
- album.excerpt (string) - Short description
- album.body (string) - Full HTML description
- album.shoot_date (string) - Date in YYYY-MM-DD format
- album.categories (array) - Array of categories
- album.tags (array) - Array of tags
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

{{ images }} - Array of images, each image has:
- image.id (int)
- image.url (string) - Image URL
- image.lightbox_url (string) - URL for lightbox
- image.fallback_src (string) - JPG fallback
- image.width (int) - Original width
- image.height (int) - Original height
- image.caption (string) - Caption
- image.alt (string) - Alt text
- image.sort_order (int)

- image.sources (object) - Srcset for formats:
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

- image.custom_fields (array) - Custom fields

{{ template_settings }} - Template JSON settings:
- template_settings.template_slug (string)
- template_settings.layout (string)
- template_settings.columns (object)
- template_settings.gap (object)
- template_settings.aspect_ratio (string)
- template_settings.style (object)

{{ base_path }} - Application base path
{{ site_title }} - Site title
{{ csp_nonce() }} - Function to generate CSP nonce

AVAILABLE TWIG FUNCTIONS:
- {{ trans('key') }} - i18n translation
- {{ image.caption|e }} - HTML escape
- {{ content|safe_html }} - Sanitized HTML
- {{ date|date_format }} - Date formatting

RECOMMENDED HTML STRUCTURE for template.twig:

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
// Custom JavaScript
window.templateSettings = {{ template_settings|json_encode|raw }};
</script>
```

BEST PRACTICES:

1. RESPONSIVE: Use Tailwind breakpoints (sm:, md:, lg:, xl:, 2xl:)
2. PERFORMANCE: Use lazy loading for images
3. ACCESSIBILITY: Include alt text and ARIA attributes
4. LIGHTBOX: Use .pswp-link class and data-pswp-* attributes
5. CSP: ALWAYS use nonce="{{ csp_nonce() }}" for inline scripts
6. DOWNLOAD: Check album.allow_downloads before showing download button
7. CSS: Prefer Tailwind CSS, use custom CSS only if necessary
8. JS: Avoid heavy external libraries, use vanilla JS

LAYOUT EXAMPLES:

1. CLASSIC GRID:
- CSS Grid with fixed columns
- Uniform aspect ratio
- Customizable gap

2. MASONRY:
- Pinterest-style cascade layout
- Variable heights
- Uses Masonry.js library (already included)

3. MAGAZINE:
- Editorial layout
- Mix of image sizes
- Parallax effects

4. POLAROID:
- Instant photo effect
- Random rotation
- Shadow and border

CREATE A COMPLETE TEMPLATE with all necessary files.

================================================================================
COMPLETE EXAMPLE: CLASSIC GRID TEMPLATE
================================================================================

--- metadata.json ---
```text
{
  "type": "gallery",
  "name": "Classic Grid Gallery",
  "slug": "classic-grid",
  "description": "Classic photo grid with uniform aspect ratio",
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
FINAL INSTRUCTIONS
================================================================================

1. Create all required files
2. Compress into a ZIP file with the correct structure
3. Upload the ZIP through the Custom Templates Pro plugin
4. The template will be immediately available in:
- Album create/edit page
- General settings (default template)
- Template switcher on album page

For questions or support, consult the Cimaise documentation.
