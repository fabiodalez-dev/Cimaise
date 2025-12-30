================================================================================
CIMAISE FULL ALBUM PAGE TEMPLATE CREATION GUIDE
================================================================================

This guide will help you create a complete template for the album page
(header, metadata, gallery and footer) in Cimaise using an LLM.

================================================================================
PROMPT FOR LLM - COPY AND PASTE THIS TEXT
================================================================================

Create a complete Twig template for the album page in Cimaise CMS, including
header, metadata, body text, equipment and integrated photo gallery.

TECHNICAL REQUIREMENTS:
- Template engine: Twig
- CSS Framework: Tailwind CSS 3.x
- Lightbox: PhotoSwipe 5
- SEO: Schema.org, Open Graph
- CSP: scripts with nonce="{{ csp_nonce() }}"

ZIP FILE STRUCTURE:

⚠️  IMPORTANT: The ZIP file must contain a folder with the template name.
    Example: my-album-page.zip must contain my-album-page/metadata.json, etc.

1. metadata.json - Configuration (⚠️ REQUIRED - upload fails without this!)
2. page.twig - Complete page template (REQUIRED)
3. gallery.twig - Gallery template (optional, if separate)
4. styles.css - Custom CSS (optional)
5. script.js - JavaScript (optional)
6. preview.jpg - Preview (optional)

CORRECT ZIP structure:
```
my-album-page.zip
└── my-album-page/
    ├── metadata.json    ← REQUIRED! Upload fails without this file
    ├── page.twig        ← REQUIRED!
    ├── styles.css       (optional)
    └── README.md        (optional)
```

metadata.json FORMAT (⚠️ ALL FIELDS type, name, slug, version ARE REQUIRED):
{
  "type": "album_page",
  "name": "Modern Album Page",
  "slug": "modern-album-page",
  "description": "Album page with modern design",
  "version": "1.0.0",
  "author": "Your name",
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

AVAILABLE VARIABLES:
See the gallery template guide for the complete list of {{ album }} and {{ images }} variables.

Additional variables for complete page:
- {{ site_title }} - Site title
- {{ site_logo }} - Logo URL
- {{ logo_type }} - 'text' or 'image'
- {{ base_path }} - Base URL
- {{ available_templates }} - Available gallery templates for switcher
- {{ current_template_id }} - Current template ID
- {{ album_ref }} - Album reference for AJAX

FUNCTIONS AND MACROS:
- {% import 'frontend/_seo_macros.twig' as Seo %}
- {% import 'frontend/_caption.twig' as Caption %}
- {{ trans('key') }} - Translation
- {{ csp_nonce() }} - CSP nonce

RECOMMENDED HTML STRUCTURE:

<div class="max-w-7xl mx-auto px-4 py-8">
  <!-- Breadcrumbs -->
  <nav class="mb-6">
    <a href="{{ base_path }}/">Home</a> /
    <span>{{ album.title }}</span>
  </nav>

  <!-- Album Header -->
  <header class="text-center mb-12">
    <!-- Categories and Tags -->
    {% if album.categories|length > 0 %}
    <div class="flex gap-2 justify-center mb-4">
      {% for cat in album.categories %}
      <a href="{{ base_path }}/category/{{ cat.slug }}" class="px-3 py-1 rounded-full bg-neutral-100">
        {{ cat.name }}
      </a>
      {% endfor %}
    </div>
    {% endif %}

    <!-- Title -->
    <h1 class="text-4xl font-light mb-6">{{ album.title|e }}</h1>

    <!-- Metadata: Date and Location -->
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
        <!-- Template icon -->
      </button>
      {% endfor %}
    </div>
  </div>
  {% endif %}

  <!-- Gallery -->
  <div id="gallery-container">
    <!-- Include gallery template or inline -->
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
// Template initialization
</script>

Complete example available in the plugin documentation.
