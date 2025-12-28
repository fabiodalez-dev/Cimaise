# Impostazioni di Cimaise

Guida completa a tutte le configurazioni disponibili nel pannello di amministrazione.

Accesso: **Admin → Impostazioni**

---

## 📋 Indice

- [Identità del Sito](#identità-del-sito)
- [Frontend e Aspetto](#frontend-e-aspetto)
- [Ottimizzazione Immagini](#ottimizzazione-immagini)
- [Template e Gallerie](#template-e-gallerie)
- [Privacy e Cookie](#privacy-e-cookie)
- [Navigazione](#navigazione)
- [Performance](#performance)
- [Strumenti Avanzati](#strumenti-avanzati)

---

## Identità del Sito

### Titolo Sito
**Campo**: `site.title`

Il nome del tuo portfolio. Appare in:
- Browser tab (tag `<title>`)
- Header del sito
- Footer copyright
- Meta tag per SEO e social sharing

**Esempio**: `Marco Rossi Photography`

---

### Descrizione Sito
**Campo**: `site.description`

Breve descrizione del tuo lavoro (1-2 frasi, max 160 caratteri).

**Utilizzo**:
- Meta description per Google
- Open Graph description per Facebook/LinkedIn
- Twitter Card description

**Esempio**: `Portfolio di fotografia di paesaggio e architettura. Immagini dal Giappone, Islanda e Patagonia.`

**Tip SEO**: Includi 2-3 parole chiave rilevanti ma scrivi per umani, non per motori di ricerca.

---

### Logo

**Modalità**:
1. **Logo come Immagine** (consigliato per brand professionali)
2. **Logo come Testo** (minimalista, veloce da caricare)

#### Logo Immagine

**Formati supportati**: PNG, JPG, SVG, WebP
**Dimensioni consigliate**: 200-400px larghezza, altezza proporzionale
**Peso max**: 2MB

**Best practice**:
- **PNG trasparente** per logo su sfondo variabile
- **SVG** per qualità perfetta su schermi Retina
- **Aspect ratio**: orizzontale (3:1 o 4:1) funziona meglio nell'header

**Generazione automatica favicon**: Una volta caricato il logo, clicca "Genera Favicon" per creare automaticamente:
- `favicon.ico` (16x16, 32x32)
- `favicon-16x16.png`
- `favicon-32x32.png`
- `favicon-96x96.png`
- `apple-touch-icon.png` (180x180)
- `android-chrome-192x192.png`
- `android-chrome-512x512.png`

#### Logo Testo

Se scegli logo testuale:
1. Il titolo del sito appare come logo
2. Puoi caricare un'immagine separata per le favicon (icona piccola per browser tab)

**Font logo testo**: Utilizza il font impostato per "Headings" nelle [impostazioni tipografia](./tipografia.md).

---

### Email del Sito
**Campo**: `site.email`

Email dove ricevere:
- Messaggi dal form di contatto
- Notifiche amministrative (se abilitate nei plugin)

**Formato**: Validazione automatica formato email

---

### Copyright
**Campo**: `site.copyright`

Testo visualizzato nel footer di ogni pagina.

**Placeholder speciali**:
- `{year}` → Anno corrente (aggiornato automaticamente ogni 1° gennaio)

**Esempi**:
```
© {year} Marco Rossi Photography
© {year} - Tutti i diritti riservati
Copyright {year} · Marco Rossi
```

**Output** (se anno corrente è 2025):
```
© 2025 Marco Rossi Photography
```

---

## Frontend e Aspetto

### Dark Mode
**Campo**: `frontend.dark_mode`
**Tipo**: Checkbox (On/Off)

Abilita il toggle dark mode nel frontend.

**Quando è attivo**:
- Appare un pulsante sole/luna nell'header
- I visitatori possono passare tra tema chiaro e scuro
- La preferenza viene salvata in `localStorage` (persistente)

**Colori dark mode**:
- Background: `#0a0a0a` (quasi nero)
- Testo: `#fafafa` (quasi bianco)
- Grigi: `#171717`, `#262626`, `#404040`
- Transizione: 0.3s smooth

**Pagine interessate**:
- ✅ Tutte le home page (7 template)
- ✅ Gallerie e album
- ✅ Pagina login
- ❌ Pannello admin (sempre chiaro per leggibilità)

**Vedi documentazione completa**: [Dark Mode](./dark-mode.md)

---

### Custom CSS
**Campo**: `frontend.custom_css`
**Tipo**: Textarea (max 50.000 caratteri)

Aggiungi CSS personalizzato per fine-tuning del design.

**Cosa puoi fare**:
- Override colori brand
- Modificare spacing e padding
- Aggiungere animazioni custom
- Cambiare border-radius
- Nascondere elementi specifici

**Sicurezza**:
Il CSS viene automaticamente sanitizzato per prevenire XSS:
- ❌ Tag `<script>` e `<style>` rimossi
- ❌ `javascript:` e `data:` URI bloccati in `url()`
- ❌ `@import` bloccato (no caricamento CSS esterni)
- ❌ `expression()` e `behavior:` bloccati (vecchie vulnerabilità IE)

**Esempi d'uso**:

```css
/* Override colore primario */
:root {
  --primary-color: #2563eb; /* blu brand */
  --accent-color: #f59e0b;  /* oro accent */
}

/* Aumenta border-radius per look più morbido */
.album-card,
.btn,
.form-control {
  border-radius: 12px !important;
}

/* Nascondi data pubblicazione negli album */
.album-date {
  display: none;
}

/* Aggiungi sfumatura all'header */
.site-header {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

/* Custom hover per album cards */
.album-card:hover {
  transform: translateY(-8px);
  box-shadow: 0 20px 40px rgba(0,0,0,0.2);
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
```

**Tip**: Usa `!important` solo quando necessario per override specifici. Il CSS custom viene caricato dopo i CSS base, quindi ha già priorità naturale.

**Best practice**:
- Testa su mobile, tablet e desktop
- Verifica compatibilità con dark mode
- Usa variabili CSS per manutenibilità
- Commenta sezioni complesse

---

### Disabilita Click Destro
**Campo**: `frontend.disable_right_click`
**Tipo**: Checkbox

Previene click destro sulle immagini per scoraggiare download non autorizzati.

**⚠️ Nota importante**: Questa è **protezione leggera**, non sicurezza reale.

**Cosa fa**:
- Disabilita menu contestuale su immagini (`contextmenu` event)
- Previene drag & drop immagini

**Cosa NON fa**:
- ❌ Non previene screenshot
- ❌ Non previene "Visualizza sorgente pagina"
- ❌ Non previene download via DevTools
- ❌ Non previene estensioni browser

**Quando usarlo**:
- ✅ Portfolio pubblico per scoraggiare utenti occasionali
- ❌ **NON** per proteggere immagini sensibili (usa album con password invece)

**Alternativa migliore per protezione reale**:
- Usa [Album Protetti da Password](./album-password.md)
- Aggiungi watermark con metadata
- Limita risoluzione pubblica (max 1920px)

---

### NSFW - Avviso Globale
**Campo**: `privacy.nsfw_global_warning`
**Tipo**: Checkbox

Mostra un age gate globale all'ingresso del sito se hai contenuti NSFW.

**Comportamento**:
- Prima visita → Age gate "Conferma di avere 18+ anni"
- Dopo conferma → Accesso a tutto il sito
- Preferenza salvata in sessione (24 ore)

**Quando usarlo**:
- La maggioranza del tuo portfolio è NSFW
- Vuoi protezione globale invece che per singolo album

**Alternativa**:
Marca singoli album come NSFW invece di tutto il sito. Vedi [Contenuti NSFW](./nsfw.md).

---

## Ottimizzazione Immagini

Quando carichi un'immagine, Cimaise genera automaticamente **varianti ottimizzate** in 3 formati e 5 dimensioni.

### Formati Abilitati
**Campo**: `image.formats`

- ☑️ **AVIF** (default: ON) - Formato moderno, miglior compressione (-40% vs WebP)
- ☑️ **WebP** (default: ON) - Supporto universale, ottimo compromesso
- ☑️ **JPEG** (default: ON) - Fallback per browser vecchi

**Consiglio**: Lascia tutti attivi. Il browser sceglierà automaticamente il migliore supportato.

**Disabilita AVIF se**:
- Il tuo hosting non supporta MIME type `image/avif`
- Generi troppo spazio disco (AVIF è più lento da generare)

---

### Qualità per Formato
**Campo**: `image.quality`

Cursori da 1 a 100 per ogni formato.

| Formato | Default | Range Consigliato | Note |
|---------|---------|-------------------|------|
| **AVIF** | 50 | 40-70 | Compressione eccellente anche a bassa qualità |
| **WebP** | 75 | 70-85 | Bilanciamento qualità/dimensione |
| **JPEG** | 85 | 80-90 | Qualità visiva eccellente |

**Esempi**:

| Impostazione | Caso d'uso |
|--------------|-----------|
| AVIF 40, WebP 70, JPEG 80 | Massima velocità (gallerie molto grandi) |
| AVIF 50, WebP 75, JPEG 85 | **Default bilanciato** (consigliato) |
| AVIF 70, WebP 85, JPEG 90 | Massima qualità (stampe, fotografi professionisti) |

**Test consigliato**:
1. Carica 5-10 foto rappresentative
2. Prova impostazioni diverse
3. Verifica dimensioni file e qualità visiva
4. Applica a tutto il sito

---

### Breakpoint Dimensioni
**Campo**: `image.breakpoints`

Dimensioni (in px) delle varianti generate. Cimaise usa `<picture>` con `srcset` per servire la dimensione ottimale.

| Breakpoint | Default | Min | Max | Dispositivo tipico |
|------------|---------|-----|-----|--------------------|
| **sm**  | 768px  | 100 | 2000 | Mobile portrait |
| **md**  | 1200px | 100 | 2000 | Tablet, mobile landscape |
| **lg**  | 1920px | 100 | 3000 | Desktop Full HD |
| **xl**  | 2560px | 100 | 4000 | Desktop 2K |
| **xxl** | 3840px | 100 | 5000 | Desktop 4K, Retina |

**Validazione**: I valori devono essere in ordine crescente (`sm < md < lg < xl < xxl`).

**Esempio personalizzato per sito minimalista**:
```
sm:  600px   (mobile)
md:  1024px  (tablet)
lg:  1600px  (desktop)
xl:  2400px  (retina)
xxl: 3200px  (4K)
```

**Risparmio spazio disco**:
- Riduci `xxl` se non hai visitatori con schermi 4K (controlla Google Analytics)
- Riduci `xl` se il 90% del traffico è mobile

---

### Dimensione Anteprima Thumbnail
**Campo**: `image.preview`

Dimensione delle thumbnail nella media library admin.

- **Width**: Default 480px
- **Height**: Auto (mantiene aspect ratio)

**Range**: 64px - 1200px

**Consiglio**: Lascia 480px a meno che non hai schermi molto grandi in admin.

---

### Generazione Asincrona Varianti
**Campo**: `image.variants_async`
**Tipo**: Checkbox (default: ON)

**ON** (consigliato):
- Caricamento immediato → salva originale → redirect a media library
- Varianti generate in background job
- Upload più veloce (no timeout)

**OFF**:
- Caricamento sincrono → genera tutte varianti → redirect
- Rischio timeout su file grandi (>10MB)
- Ma sai subito se ci sono errori

**Quando disabilitare**:
- Server molto veloce con timeout alti (5+ minuti)
- Vuoi debugging immediato di problemi generazione

**Rigenera varianti manualmente**:
```bash
php bin/console images:generate --missing
```

Oppure dal pannello Admin → Impostazioni → pulsante "Rigenera Varianti Mancanti".

---

## Template e Gallerie

### Template Gallerie Default
**Campo**: `gallery.default_template_id`

Scegli quale template applicare automaticamente ai nuovi album.

**Template disponibili**:
1. **Classic Grid** - Griglia uniforme, thumbnails quadrate
2. **Masonry** - Griglia Pinterest-style, aspect ratio variabili
3. **Masonry Full** - Immagini full size, no crop
4. **Magazine** - 3 colonne animate con scroll
5. **Magazine + Cover** - Hero cover + magazine scroll
6. **Slideshow** - Presentazione full-screen

**Default**: Nessuno (scegli manualmente per ogni album)

**Quando impostarlo**:
- Hai uno stile preferito per tutto il portfolio
- Vuoi consistenza visiva

**Override**: Ogni album può cambiare template individualmente da Admin → Album → Modifica.

Vedi [Template Gallerie](./template-gallerie.md) per dettagli su ogni template.

---

### Template Pagina Gallerie
**Campo**: `gallery.page_template`

Layout della pagina `/galleries` (archivio album).

**Opzioni**:
- **classic** - Griglia cards con sidebar filtri (default)
- **hero** - Hero image + griglia sotto
- **magazine** - Layout editoriale con grande preview

**Preview**: Visita `/galleries` dopo aver cambiato per vedere differenza.

---

## Privacy e Cookie

### Cookie Banner
**Campo**: `privacy.cookie_banner_enabled`
**Tipo**: Checkbox (default: ON)

Mostra banner GDPR-compliant al primo accesso.

**Integrazione**: Cimaise si integra con [Silktide Cookie Consent](https://www.silktide.com/cookieconsent) per gestione cookie.

**Opzioni**:
- `cookie_banner.show_analytics` - Categoria "Analytics"
- `cookie_banner.show_marketing` - Categoria "Marketing"

**JavaScript Personalizzato per Categoria**:
- `privacy.custom_js_essential` - Sempre caricato
- `privacy.custom_js_analytics` - Solo se utente accetta analytics
- `privacy.custom_js_marketing` - Solo se utente accetta marketing

**Esempio Google Analytics**:
```javascript
// In: custom_js_analytics
(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-XXXXXXX');
```

**Importante**: Non inserire script di tracking in "Essential" a meno che non siano strettamente necessari (es. autenticazione).

---

### reCAPTCHA v3
**Campi**:
- `recaptcha.site_key`
- `recaptcha.secret_key`
- `recaptcha.enabled`

Proteggi il form di contatto da spam.

**Setup**:
1. Vai su [Google reCAPTCHA Admin](https://www.google.com/recaptcha/admin)
2. Crea nuovo sito → Tipo: **reCAPTCHA v3**
3. Aggiungi il tuo dominio
4. Copia Site Key e Secret Key
5. Incollale nelle impostazioni
6. Abilita checkbox

**Validazione**: Le chiavi devono essere alfanumeriche (pattern: `[A-Za-z0-9_-]+`).

**Sicurezza**: La Secret Key non viene mai esposta al frontend. Solo Site Key è pubblica.

**Testing**: Invia un messaggio di test dal form contatti e verifica ricezione email.

---

## Navigazione

### Mostra Tag nel Menu
**Campo**: `navigation.show_tags_in_header`
**Tipo**: Checkbox (default: OFF)

Aggiunge voce "Tags" nel menu principale accanto a "Categories".

**Quando abilitare**:
- Usi tag come classificazione primaria (es. emozioni: "nostalgia", "gioia", "malinconia")
- Hai <20 tag (altrimenti dropdown diventa troppo grande)

**Quando NON abilitare**:
- Hai molti tag (50+) → usa filtri nella pagina gallerie invece
- Tag sono solo per organizzazione interna

---

## Performance

### Compressione Output
**Campo**: `performance.compression`
**Tipo**: Checkbox (default: ON)

Abilita gzip/deflate compression per HTML, CSS, JS.

**Risparmio**: ~60-80% dimensioni pagina

**Lascia ON** a meno che:
- Il tuo server/CDN fa già compressione (es. Cloudflare)
- Vuoi debugging più semplice in sviluppo

**Verifica compressione attiva**:
```bash
curl -H "Accept-Encoding: gzip" -I https://tuosito.com
# Cerca header: Content-Encoding: gzip
```

---

### Limite Paginazione
**Campo**: `pagination.limit`

Numero di elementi per pagina nelle liste.

**Default**: 12
**Range**: 1-100

**Applica a**:
- Gallerie archive (`/galleries`)
- Media library admin
- Liste album in admin

**Consiglio**:
- **12**: Multiplo di 3, 4, 6 → griglia pulita
- **20**: Se hai molti album e vuoi ridurre click
- **8-10**: Mobile-first, scrolling ridotto

---

### TTL Cache
**Campo**: `cache.ttl`

Time To Live per cache in **ore**.

**Default**: 24 ore
**Range**: 1-168 ore (1 settimana)

**Cosa viene cachato**:
- Lensfun database (fotocamere/obiettivi)
- Traduzioni compilate
- Template compilati Twig
- Query database frequenti

**Quando ridurre** (6-12 ore):
- Sviluppo attivo
- Modifichi spesso traduzioni

**Quando aumentare** (72-168 ore):
- Sito stabile in produzione
- Vuoi massime performance

**Clear cache manuale**:
```bash
rm -rf storage/cache/*
```

---

## Strumenti Avanzati

### Debug Logs
**Campo**: `admin.debug_logs`
**Tipo**: Checkbox (default: OFF)

Abilita logging verboso in `storage/logs/`.

**Log files creati**:
- `app.log` - Eventi applicazione generale
- `upload.log` - Upload e processamento immagini
- `exif.log` - Estrazione EXIF
- `errors.log` - Errori e eccezioni

**Formato log**:
```
[2025-01-15 14:32:18] app.INFO: Album created {"id":42,"title":"Kyoto Winter"}
[2025-01-15 14:32:19] upload.WARNING: EXIF extraction failed {"path":"/tmp/img.jpg"}
```

**⚠️ Attenzione**:
- I log possono crescere rapidamente
- Disabilita in produzione dopo debugging
- Possono contenere informazioni sensibili

**Visualizza log**:
```bash
tail -f storage/logs/app.log
```

Oppure in Admin → Impostazioni → sezione "Debug Logs" (se abilitato).

---

### Aggiornamento Database Lensfun
**Azione**: Pulsante "Aggiorna Database Lensfun"

Lensfun è un database open-source con:
- 1.000+ fotocamere
- 1.300+ obiettivi

Usato per autocomplete quando assegni camera/lens a immagini.

**Quando aggiornare**:
- Nuove fotocamere uscite (es. Sony A7 V)
- Autocomplete non trova la tua fotocamera

**Processo**:
1. Download XML da [Lensfun GitHub](https://github.com/lensfun/lensfun)
2. Parse e import in cache locale
3. Disponibile per autocomplete

**Durata**: 10-30 secondi

**Frequenza consigliata**: Ogni 3-6 mesi

---

### Rigenera Varianti Immagini
**Azione**: Pulsante "Rigenera Varianti Mancanti"

**Quando usare**:
- Hai cambiato impostazioni qualità/formati
- Alcuni upload hanno fallito generazione
- Hai aggiunto AVIF dopo aver già caricato foto

**Cosa fa**:
- Scansiona tutte le immagini nel database
- Identifica varianti mancanti
- Genera solo quelle mancanti (non rigenera tutto)

**Processo in background**: Non blocca il browser, puoi continuare a lavorare.

**Monitoraggio**:
```bash
tail -f storage/logs/upload.log
```

**Rigenera TUTTO** (CLI, forzato):
```bash
php bin/console images:generate --force
```

---

### Generazione Favicon da Logo
**Azione**: Pulsante "Genera Favicon da Logo"

**Prerequisito**: Devi aver caricato un logo immagine.

**Output**:
- `favicon.ico`
- `favicon-16x16.png`
- `favicon-32x32.png`
- `favicon-96x96.png`
- `apple-touch-icon.png` (180x180)
- `android-chrome-192x192.png`
- `android-chrome-512x512.png`

**Libreria usata**: GD o ImageMagick (se disponibile)

**Formato logo ideale**:
- Quadrato (1:1 aspect ratio)
- Min 512x512px
- Sfondo trasparente (PNG)
- Design semplice e riconoscibile anche piccolo

**Errori comuni**:
- Logo troppo piccolo (<256px) → risultato pixelato
- Logo rettangolare → viene croppato al centro
- Logo con dettagli fini → illeggibili a 16x16px

**Tip**: Crea un'icona semplificata specifica per favicon se il tuo logo è complesso.

---

## Salvataggio e Conferme

Il pulsante **"Salva Impostazioni"** in fondo alla pagina applica tutte le modifiche.

**Feedback**:
- ✅ Verde: "Impostazioni salvate con successo"
- ❌ Rosso: Errore (es. breakpoints non in ordine crescente)
- ⚠️ Giallo: Warning (es. favicon generati parzialmente)

**Best practice**:
1. Modifica una sezione alla volta
2. Salva
3. Testa sul frontend
4. Passa alla sezione successiva

**Rollback**: Non c'è undo automatico. Annota valori precedenti prima di cambiamenti grandi.

---

## Impostazioni Avanzate (File di Configurazione)

Alcune impostazioni sono disponibili solo editando `config/app.php`:

```php
return [
    // Max dimensione upload (oltre a php.ini)
    'upload_max_size' => 100 * 1024 * 1024, // 100MB

    // Session timeout (minuti)
    'session_lifetime' => 120,

    // Timezone
    'timezone' => 'Europe/Rome',

    // Locale
    'locale' => 'it_IT',
];
```

**⚠️ Richiede conoscenza PHP**. Modifica solo se sei sicuro.

---

## Prossimi Passi

Ora che hai configurato le impostazioni base:
- [Personalizza la tipografia](./tipografia.md)
- [Attiva il dark mode](./dark-mode.md)
- [Crea il tuo primo album](./album-gallerie.md)
- [Configura EXIF e metadati](./exif-metadati.md)
