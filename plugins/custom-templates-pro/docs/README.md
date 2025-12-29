# Custom Templates Pro

Plugin per Cimaise che permette di caricare template personalizzati per gallerie, album e homepage con guide complete per LLM.

## 🎯 Funzionalità

- ✅ **Template Gallerie**: Crea layout personalizzati per le gallerie fotografiche degli album
- ✅ **Template Pagina Album**: Template completi della pagina album (header, metadata, galleria integrata)
- ✅ **Template Homepage**: Design personalizzati per la homepage del portfolio
- ✅ **Upload Sicuro**: Validazione completa dei file ZIP con scan malware e syntax check
- ✅ **Guide LLM**: Guide complete con prompt ottimizzati per Claude, ChatGPT e altri LLM
- ✅ **CSP Compliance**: Tutti gli script supportano Content Security Policy con nonce
- ✅ **Integrazione Seamless**: I template custom appaiono automaticamente in tutte le interfacce

## 📦 Installazione

1. Scarica il file ZIP del plugin
2. Vai su **Admin → Plugin** in Cimaise
3. Carica il file ZIP tramite l'interfaccia di upload
4. Attiva il plugin
5. Il plugin creerà automaticamente:
   - Tabella database `custom_templates`
   - Directory `plugins/custom-templates-pro/uploads/`
   - Guide LLM in `plugins/custom-templates-pro/guides/`

## 🚀 Utilizzo

### 1. Scaricare le Guide LLM

1. Vai su **Admin → Custom Templates → Guide LLM**
2. Scarica la guida per il tipo di template che vuoi creare:
   - **Gallery Template Guide**: Per gallerie fotografiche
   - **Album Page Guide**: Per pagine album complete
   - **Homepage Guide**: Per homepage personalizzate
3. Apri il file `.txt` e copia il prompt per LLM

### 2. Creare Template con un LLM

1. Apri il tuo LLM preferito (Claude, ChatGPT, Gemini, ecc.)
2. Incolla il prompt dalla guida
3. Descrivi il template che vuoi:
   - Stile visivo (minimalista, magazine, polaroid, ecc.)
   - Layout (grid, masonry, carousel, ecc.)
   - Effetti (parallax, hover animations, ecc.)
   - Colori e tipografia
4. Il LLM genererà tutti i file necessari:
   - `metadata.json`
   - `template.twig` (o `page.twig` o `home.twig`)
   - `styles.css` (opzionale)
   - `script.js` (opzionale)

### 3. Preparare il File ZIP

Crea un file ZIP con questa struttura:

```
my-template.zip
├── metadata.json          # Obbligatorio
├── template.twig          # Obbligatorio (o page.twig / home.twig)
├── styles.css             # Opzionale
├── script.js              # Opzionale
├── preview.jpg            # Opzionale (800x600px)
└── README.md              # Opzionale
```

#### Esempio `metadata.json` per Template Galleria:

```json
{
  "type": "gallery",
  "name": "Modern Grid",
  "slug": "modern-grid",
  "description": "Griglia moderna con hover effects",
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
```

### 4. Caricare il Template

1. Vai su **Admin → Custom Templates → Carica Template**
2. Seleziona il tipo di template
3. Carica il file ZIP
4. Il plugin eseguirà automaticamente:
   - Validazione dimensione (max 10 MB)
   - Validazione struttura ZIP
   - Parsing `metadata.json`
   - Syntax check Twig
   - Validazione CSS/JS
   - Scan malware pattern
   - CSP compliance check
5. Se tutto è OK, il template sarà disponibile immediatamente!

### 5. Usare i Template

#### Template Galleria:
- **Crea/Modifica Album**: Seleziona il template custom dal dropdown
- **Impostazioni Generali**: Imposta template custom come predefinito
- **Template Switcher**: I template custom appaiono nelle icone di switch

#### Template Homepage:
- **Admin → Pages → Home**: Seleziona il template custom dal dropdown

#### Template Pagina Album:
- **Impostazioni Album**: Scegli il page template custom

## 🔒 Sicurezza

Il plugin implementa multiple validazioni di sicurezza:

### Upload
- ✅ Verifica tipo file (solo `.zip`)
- ✅ Limite dimensione (10 MB)
- ✅ Validazione estensioni contenute (whitelist)

### Contenuto
- ✅ Scan pattern malware (eval, base64_decode, exec, ecc.)
- ✅ Blocco funzioni Twig pericolose (include, source, import)
- ✅ Validazione bilanciamento tag Twig
- ✅ CSS: blocco @import URL esterni, expression()
- ✅ JavaScript: blocco eval(), Function constructor

### Path Safety
- ✅ Prevenzione path traversal (`../`)
- ✅ Sanitizzazione nomi file
- ✅ Estrazione sicura ZIP

### CSP
- ✅ Tutti gli script inline richiedono nonce
- ✅ Supporto Content Security Policy headers

## 📚 Variabili Twig Disponibili

### Template Galleria

```twig
{{ album }}                        {# Oggetto album completo #}
{{ album.title }}                  {# Titolo album #}
{{ album.excerpt }}                {# Descrizione breve #}
{{ album.body }}                   {# Descrizione HTML #}
{{ album.allow_downloads }}        {# Bool: download abilitato #}

{{ images }}                       {# Array immagini #}
{{ image.url }}                    {# URL immagine #}
{{ image.caption }}                {# Didascalia #}
{{ image.width }}, {{ image.height }} {# Dimensioni #}
{{ image.sources.avif }}           {# Srcset AVIF #}
{{ image.sources.webp }}           {# Srcset WebP #}
{{ image.sources.jpg }}            {# Srcset JPG #}
{{ image.camera_name }}            {# Nome fotocamera #}
{{ image.exif_make }}              {# EXIF make #}

{{ template_settings }}            {# Settings template #}
{{ base_path }}                    {# Base URL app #}
{{ site_title }}                   {# Titolo sito #}
{{ csp_nonce() }}                  {# Nonce CSP #}
```

Vedi `docs/VARIABLES_REFERENCE.md` per la lista completa.

## 🎨 Esempi Template

### Griglia Classica

```twig
{% for image in images %}
<div class="gallery-item">
  <a href="{{ base_path }}{{ image.url }}"
     class="pswp-link"
     data-pswp-width="{{ image.width }}"
     data-pswp-height="{{ image.height }}">
    <img src="{{ base_path }}{{ image.fallback_src }}"
         alt="{{ image.alt }}"
         loading="lazy">
  </a>
</div>
{% endfor %}
```

### Masonry con Caption

```twig
{% for image in images %}
<div class="masonry-item">
  <a href="{{ base_path }}{{ image.url }}" class="pswp-link group">
    <img src="{{ base_path }}{{ image.fallback_src }}" alt="{{ image.alt }}">
    {% if image.caption %}
    <div class="caption opacity-0 group-hover:opacity-100">
      {{ image.caption }}
    </div>
    {% endif %}
  </a>
</div>
{% endfor %}
```

## 🐛 Troubleshooting

### Template non appare nei dropdown

- Verifica che il template sia attivo (Admin → Custom Templates)
- Controlla il tipo del template (gallery, album_page, homepage)
- Ricarica la cache del browser

### Errori durante l'upload

- Verifica la dimensione del file (< 10 MB)
- Controlla la struttura ZIP (deve contenere `metadata.json`)
- Valida la sintassi del JSON
- Rimuovi caratteri speciali dai nomi file

### Template non viene renderizzato correttamente

- Verifica la sintassi Twig
- Controlla le variabili utilizzate
- Usa `{{ dump(album) }}` per debug (in sviluppo)
- Controlla i log del server

## 🔧 Sviluppo

### Struttura Plugin

```
plugins/custom-templates-pro/
├── plugin.php                     # File principale
├── install.php                    # Script installazione
├── uninstall.php                  # Script disinstallazione
├── Controllers/
│   └── CustomTemplatesController.php
├── Services/
│   ├── TemplateUploadService.php
│   ├── TemplateValidationService.php
│   ├── GuidesGeneratorService.php
│   └── TemplateIntegrationService.php
├── templates/admin/               # Template Twig admin
├── assets/                        # CSS/JS plugin
├── guides/                        # Guide LLM generate
├── uploads/                       # Templates caricati
└── docs/                          # Documentazione
```

### Hook Disponibili

Il plugin registra questi hook:

- `cimaise_init`: Inizializzazione
- `admin_sidebar_advanced`: Menu sidebar
- `available_gallery_templates`: Templates galleria
- `available_home_templates`: Templates homepage
- `available_album_page_templates`: Templates album page
- `twig_loader_paths`: Path Twig custom
- `frontend_head`: Assets frontend
- `gallery_template_path`: Risoluzione path template

## 📝 Licenza

MIT License - Vedi LICENSE file

## 👥 Supporto

- **Documentazione**: `docs/` directory
- **Issues**: GitHub Issues
- **Email**: support@cimaise.com

## 🎉 Credits

Sviluppato dal team Cimaise con ❤️
