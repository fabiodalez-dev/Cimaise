# Riferimento Variabili Twig

Questa guida elenca tutte le variabili Twig disponibili nei template personalizzati di Cimaise.

## 🎨 Template Gallerie

Variabili disponibili in `template.twig` per template di tipo `gallery`.

### Oggetto `album`

Contiene tutte le informazioni dell'album corrente.

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `album.id` | int | ID univoco album |
| `album.title` | string | Titolo album |
| `album.slug` | string | Slug URL-friendly |
| `album.excerpt` | string | Descrizione breve |
| `album.body` | string | Descrizione completa (HTML) |
| `album.shoot_date` | string | Data scatto (YYYY-MM-DD) |
| `album.is_published` | bool | Album pubblicato |
| `album.is_nsfw` | bool | Contenuto NSFW |
| `album.allow_downloads` | bool | Download immagini abilitato |
| `album.allow_template_switch` | bool | Template switcher abilitato |
| `album.custom_cameras` | string | Fotocamere custom (testo libero) |
| `album.custom_lenses` | string | Obiettivi custom (testo libero) |
| `album.custom_films` | string | Pellicole custom (testo libero) |

### Relazioni `album`

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `album.categories` | array | Array di categorie |
| `album.categories[].id` | int | ID categoria |
| `album.categories[].name` | string | Nome categoria |
| `album.categories[].slug` | string | Slug categoria |
| `album.tags` | array | Array di tag |
| `album.tags[].id` | int | ID tag |
| `album.tags[].name` | string | Nome tag |
| `album.tags[].slug` | string | Slug tag |
| `album.cover_image` | object | Immagine di copertina |

### Equipment `album`

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `album.equipment.cameras` | array | Lista fotocamere usate |
| `album.equipment.lenses` | array | Lista obiettivi usati |
| `album.equipment.film` | array | Lista pellicole usate |
| `album.equipment.developers` | array | Lista sviluppi usati |
| `album.equipment.labs` | array | Lista laboratori usati |
| `album.equipment.locations` | array | Lista location |

Esempio pellicola:
```twig
{% for film in album.equipment.film %}
  {{ film.name }}       {# es. "Kodak Portra 400" #}
  {{ film.iso }}        {# es. "400" #}
  {{ film.format }}     {# es. "35mm" #}
{% endfor %}
```

### Array `images`

Contiene tutte le immagini dell'album.

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `images` | array | Array di oggetti immagine |

#### Oggetto Immagine

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `image.id` | int | ID immagine |
| `image.url` | string | URL immagine originale |
| `image.lightbox_url` | string | URL per lightbox |
| `image.fallback_src` | string | URL fallback JPG |
| `image.width` | int | Larghezza originale |
| `image.height` | int | Altezza originale |
| `image.caption` | string | Didascalia |
| `image.alt` | string | Testo alternativo |
| `image.sort_order` | int | Ordine visualizzazione |

#### Sources Responsive

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `image.sources.avif` | array | Array srcset AVIF |
| `image.sources.webp` | array | Array srcset WebP |
| `image.sources.jpg` | array | Array srcset JPG |

Esempio usage:
```twig
<source type="image/avif" srcset="{% for src in image.sources.avif %}{{ base_path }}{{ src }}{% if not loop.last %}, {% endif %}{% endfor %}">
```

#### Equipment Immagine

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `image.camera_name` | string | Nome fotocamera (da DB) |
| `image.custom_camera` | string | Fotocamera custom (testo libero) |
| `image.lens_name` | string | Nome obiettivo (da DB) |
| `image.custom_lens` | string | Obiettivo custom |
| `image.film_name` | string | Nome pellicola (da DB) |
| `image.film_display` | string | Pellicola formattata |
| `image.custom_film` | string | Pellicola custom |
| `image.developer_name` | string | Nome sviluppo |
| `image.lab_name` | string | Nome laboratorio |
| `image.location_name` | string | Nome location |

Fallback pattern:
```twig
{{ image.camera_name|default(image.custom_camera)|default('') }}
```

#### Dati EXIF

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `image.iso` | int | ISO |
| `image.shutter_speed` | string | Tempo esposizione (es. "1/250") |
| `image.aperture` | string | Apertura (es. "f/2.8") |
| `image.focal_length` | string | Lunghezza focale (es. "50mm") |
| `image.exif_make` | string | Produttore camera (EXIF) |
| `image.exif_model` | string | Modello camera (EXIF) |
| `image.exif_lens_model` | string | Modello obiettivo (EXIF) |
| `image.gps_lat` | float | Latitudine GPS |
| `image.gps_lng` | float | Longitudine GPS |
| `image.date_original` | string | Data scatto originale |
| `image.artist` | string | Artista (EXIF) |
| `image.copyright` | string | Copyright (EXIF) |

#### Custom Fields

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `image.custom_fields` | array | Campi personalizzati |

### Oggetto `template_settings`

Contiene le impostazioni del template corrente.

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `template_settings.template_slug` | string | Slug template |
| `template_settings.layout` | string | Tipo layout (grid, masonry, ecc.) |
| `template_settings.columns` | object | Colonne responsive |
| `template_settings.columns.desktop` | int | Colonne desktop |
| `template_settings.columns.tablet` | int | Colonne tablet |
| `template_settings.columns.mobile` | int | Colonne mobile |
| `template_settings.gap` | object | Spaziatura |
| `template_settings.gap.horizontal` | int | Gap orizzontale (px) |
| `template_settings.gap.vertical` | int | Gap verticale (px) |
| `template_settings.aspect_ratio` | string | Aspect ratio (1:1, 4:3, ecc.) |
| `template_settings.style` | object | Stili applicati |
| `template_settings.style.rounded` | bool | Bordi arrotondati |
| `template_settings.style.shadow` | bool | Ombra |
| `template_settings.style.hover_scale` | bool | Zoom hover |
| `template_settings.style.hover_fade` | bool | Fade hover |

### Variabili Globali

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `base_path` | string | Base URL applicazione |
| `site_title` | string | Titolo sito |
| `album_ref` | string | Reference album per AJAX |
| `current_template_id` | int | ID template corrente |
| `available_templates` | array | Template disponibili (per switcher) |

---

## 📄 Template Pagina Album

Variabili disponibili in `page.twig` per template di tipo `album_page`.

Tutte le variabili di **Template Gallerie** più:

### Variabili Aggiuntive

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `site_logo` | string | URL logo sito |
| `logo_type` | string | Tipo logo ('text' o 'image') |
| `available_templates` | array | Template switcher disponibili |
| `album_custom_fields` | array | Custom fields album-level |

### Custom Fields Album

```twig
{% for typeId, field in album_custom_fields %}
  {{ field.show_in_gallery }}    {# bool: mostra in galleria #}
  {{ field.values }}              {# array: valori campo #}
  {{ field.icon }}                {# string: icona FontAwesome #}
{% endfor %}
```

---

## 🏠 Template Homepage

Variabili disponibili in `home.twig` per template di tipo `homepage`.

### Oggetto `site`

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `site_title` | string | Titolo sito |
| `site_logo` | string | URL logo |
| `logo_type` | string | 'text' o 'image' |
| `site_description` | string | Descrizione sito |
| `site_tagline` | string | Tagline |

### Array `albums`

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `albums` | array | Lista album pubblicati |

#### Oggetto Album

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `album.id` | int | ID album |
| `album.title` | string | Titolo |
| `album.slug` | string | Slug URL |
| `album.excerpt` | string | Descrizione breve |
| `album.shoot_date` | string | Data scatto |
| `album.image_count` | int | Numero immagini |
| `album.is_nsfw` | bool | Contenuto NSFW |
| `album.cover_image` | object | Immagine copertina |
| `album.categories` | array | Categorie album |
| `album.tags` | array | Tag album |

#### Cover Image

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `album.cover_image.url` | string | URL immagine |
| `album.cover_image.sources` | object | Srcset formati |
| `album.cover_image.sources.avif` | array | AVIF srcset |
| `album.cover_image.sources.webp` | array | WebP srcset |
| `album.cover_image.sources.jpg` | array | JPG srcset |
| `album.cover_image.width` | int | Larghezza |
| `album.cover_image.height` | int | Altezza |

### Array `categories`

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `categories` | array | Tutte le categorie disponibili |
| `categories[].id` | int | ID categoria |
| `categories[].name` | string | Nome |
| `categories[].slug` | string | Slug |
| `categories[].image_path` | string | Immagine categoria |

### Settings Homepage

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `home_settings.hero_title` | string | Titolo hero |
| `home_settings.hero_subtitle` | string | Sottotitolo hero |
| `home_settings.hero_image` | string | Immagine hero |
| `home_settings.show_latest_albums` | bool | Mostra ultimi album |
| `home_settings.albums_count` | int | Numero album da mostrare |

### Variabili Globali

| Variabile | Tipo | Descrizione |
|-----------|------|-------------|
| `base_path` | string | Base URL |
| `canonical_url` | string | URL canonico |

---

## 🛠️ Funzioni Twig

### Funzioni Disponibili

| Funzione | Descrizione | Esempio |
|----------|-------------|---------|
| `trans('key')` | Traduzione i18n | `{{ trans('nav.home') }}` |
| `date_format` | Formatta data | `{{ album.shoot_date\|date_format }}` |
| `safe_html` | HTML sanitizzato | `{{ album.body\|safe_html }}` |
| `e` | Escape HTML | `{{ album.title\|e }}` |
| `e('html_attr')` | Escape attributo | `{{ image.caption\|e('html_attr') }}` |
| `json_encode` | JSON encode | `{{ settings\|json_encode }}` |
| `csp_nonce()` | Nonce CSP | `<script nonce="{{ csp_nonce() }}">` |

### Filtri Disponibili

| Filtro | Descrizione | Esempio |
|--------|-------------|---------|
| `\|default(val)` | Valore default | `{{ var\|default('N/A') }}` |
| `\|length` | Lunghezza array/string | `{% if images\|length > 0 %}` |
| `\|upper` | Maiuscole | `{{ text\|upper }}` |
| `\|lower` | Minuscole | `{{ text\|lower }}` |
| `\|capitalize` | Capitalizza | `{{ text\|capitalize }}` |
| `\|slice(start, len)` | Slice array | `{{ images\|slice(0, 3) }}` |
| `\|split(sep)` | Split string | `{{ path\|split('/') }}` |
| `\|join(sep)` | Join array | `{{ items\|join(', ') }}` |
| `\|round` | Arrotonda numero | `{{ num\|round }}` |

### Test Disponibili

| Test | Descrizione | Esempio |
|------|-------------|---------|
| `is defined` | Variabile definita | `{% if var is defined %}` |
| `is null` | Variabile null | `{% if var is null %}` |
| `is empty` | Variabile vuota | `{% if var is empty %}` |

---

## ⚠️ Restrizioni Sicurezza

Per sicurezza, alcune funzioni Twig sono **bloccate**:

- ❌ `include()` - Non consentito
- ❌ `source()` - Non consentito
- ❌ `import()` - Non consentito
- ❌ `attribute()` - Non consentito
- ❌ `_self` - Non consentito

Usa invece:
- ✅ Variabili dirette
- ✅ Filtri sicuri
- ✅ Funzioni whitelisted

---

## 💡 Esempi Pratici

### Esempio 1: Loop Immagini con Caption

```twig
{% for image in images %}
<div class="image-item">
  <img src="{{ base_path }}{{ image.url }}" alt="{{ image.alt|e }}">
  {% if image.caption %}
    <p class="caption">{{ image.caption|e }}</p>
  {% endif %}
</div>
{% endfor %}
```

### Esempio 2: Equipment con Fallback

```twig
{% for image in images %}
  {% set camera = image.camera_name|default(image.custom_camera)|default('N/A') %}
  <div data-camera="{{ camera|e }}">
    <!-- Immagine -->
  </div>
{% endfor %}
```

### Esempio 3: Responsive Columns

```twig
{% set cols = template_settings.columns|default({desktop: 3, tablet: 2, mobile: 1}) %}
<div class="grid" style="--cols-desktop: {{ cols.desktop }}; --cols-tablet: {{ cols.tablet }}; --cols-mobile: {{ cols.mobile }};">
  <!-- Items -->
</div>
```

### Esempio 4: Conditional Downloads

```twig
{% if album.allow_downloads %}
  <button data-action="download-image" data-download-url="{{ base_path }}/download/image/{{ image.id }}">
    <i class="fa-solid fa-download"></i>
  </button>
{% endif %}
```

### Esempio 5: Categories Loop

```twig
{% if album.categories|length > 0 %}
  <div class="categories">
    {% for cat in album.categories %}
      <a href="{{ base_path }}/category/{{ cat.slug }}">{{ cat.name }}</a>
    {% endfor %}
  </div>
{% endif %}
```

---

## 📚 Macro Utility

> ⚠️ **Nota**: Le macro sono disponibili solo nei template di sistema, non nei template personalizzati caricati tramite Custom Templates Pro.

### Import Macro SEO

```twig
{% import 'frontend/_seo_macros.twig' as Seo %}
{% set seo_title = Seo.seo_title(image, album, site_title)|trim %}
```

### Import Macro Caption

```twig
{% import 'frontend/_caption.twig' as Caption %}
{% set full_caption = Caption.build(image) %}
```

---

**Note**: Le variabili disponibili possono variare in base alla versione di Cimaise. Consulta sempre la documentazione aggiornata.
