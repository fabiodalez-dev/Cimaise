# Home Pages in Cimaise

7 template professionali per la pagina principale del tuo portfolio. Cambia quando vuoi, senza perdere contenuti.

**Accesso**: Admin → Pagine → Home Page

---

## 📋 Indice

- [Panoramica Template](#panoramica-template)
- [1. Classic](#1-classic)
- [2. Modern](#2-modern)
- [3. Parallax](#3-parallax)
- [4. Gallery Wall](#4-gallery-wall)
- [5. Masonry Wall](#5-masonry-wall)
- [6. Snap Albums](#6-snap-albums)
- [Quale Template Scegliere](#quale-template-scegliere)
- [Personalizzazione](#personalizzazione)

---

## Panoramica Template

| Template | Stile | Ideale per | Scroll | Tech |
|----------|-------|------------|--------|------|
| **Classic** | Editoriale | Wedding, portrait | Vertical infinite | Masonry + hero |
| **Modern** | Minimal clean | Architecture, design | Smooth Lenis | Fixed sidebar |
| **Parallax** | Immersivo | Landscape, travel | Parallax depth | 3-col grid |
| **Gallery Wall** | Unico | Exhibition-style | Horizontal on vertical | Sticky transform |
| **Masonry Wall** | Dinamico | Street, documentary | Infinite loop | CSS columns |
| **Snap Albums** | Presentazione | Editorial, storytelling | Synchronized scroll | Split layout |

**Cambio template**:
- Zero perdita dati
- Istantaneo
- Prova tutti e scegli

---

## 1. Classic

### Descrizione

Il template **Classic** è l'approccio editoriale classico: hero drammatico all'ingresso, seguito da griglia masonry infinita dei tuoi album.

### Caratteristiche

**Hero Section**:
- Full-screen welcome
- Logo o titolo sito
- Tagline / subtitle opzionale
- Animazione intro elegante

**Album Carousel**:
- Scroll orizzontale smooth tra album in evidenza
- Thumbnail grandi con hover overlay
- Frecce navigazione
- Touch-friendly su mobile

**Masonry Grid**:
- Layout Pinterest-style
- Rispetta aspect ratio originale di ogni immagine
- Infinite scroll (caricamento automatico scrollando giù)
- Nessuna paginazione, esperienza fluida

**Configurazione**:
```
Admin → Pagine → Home Page → Classic
```

**Opzioni**:
- **Scroll Direction**: Up (default) o Down
- **Scroll Speed**: Slow (10s) → Fast (3s)
- **Hero Height**: Full viewport o 80% schermo

### Direzione Scroll Animazione

La griglia masonry ha un'animazione di scroll automatico in background che crea movimento.

**Up** (default):
- Immagini scorrono dal basso verso l'alto
- Sensazione di "salita", leggera ed elegante
- Consigliato per portfolio di matrimoni, portrait

**Down**:
- Immagini scorrono dall'alto verso il basso
- Effetto "pioggia" o "caduta"
- Più drammatico e cinematico

**Velocità**:
- **Slow (10s)**: Movimento appena percepibile, elegante
- **Medium (6s)**: Balance tra visibilità e discrezione
- **Fast (3s)**: Dinamico, cattura attenzione

**Quando usarlo**:
- Portfolio versatile, molte categorie
- Vuoi hero drammatico che introduce il brand
- Hai >20 album da mostrare
- Target: Wedding photographers, portrait studios

**Mobile**:
- Hero 100vh
- Carousel diventa scroll verticale
- Masonry 2 colonne
- Infinite scroll preservato

---

## 2. Modern

### Descrizione

**Modern** è il template minimal per chi ama le UI pulite. Sidebar fisso con filtri categoria, griglia due colonne con effetto parallasse leggero.

### Caratteristiche

**Fixed Sidebar**:
- Sempre visibile durante scroll
- Filtri categoria cliccabili
- Contatore album per categoria
- Logo e navigation

**Two-Column Grid**:
- Colonne uniformi, altezza variabile
- Parallax scroll: colonna sinistra più veloce della destra
- Effetto profondità 3D sottile

**Hover Reveals**:
- Hover su album → overlay con titolo + descrizione
- Transizione smooth
- Click per aprire album

**Mega Menu**:
- Click menu → full-screen overlay navigation
- Tipografia grande e leggibile
- Animazione fade-in elegante

**Lenis Smooth Scroll**:
- Scroll buttery 60fps
- Interpolazione lerp per movimento naturale
- Momentum scroll come macOS

**Configurazione**:
```
Admin → Pagine → Home Page → Modern
```

**Opzioni**:
- **Sidebar Width**: 20%, 25%, 30%
- **Grid Columns**: 2 (default) o 3
- **Parallax Intensity**: None, Light, Medium, Strong
- **Hover Overlay Color**: Black, White, Custom

**Quando usarlo**:
- Portfolio minimalista
- Hai categorie ben definite (<10)
- Target: Architects, designers, minimal artists
- Vuoi UI "app-like", non sito tradizionale

**Mobile**:
- Sidebar diventa hamburger menu in alto
- Grid diventa 1 colonna
- Parallax disabilitato (performance)
- Mega menu full-screen overlay

---

## 3. Parallax

### Descrizione

**Parallax** porta le tue immagini in vita con effetti di profondità 3D. Tre colonne di album che si muovono a velocità diverse durante lo scroll.

### Caratteristiche

**Three-Column Grid**:
- Desktop: 3 colonne
- Tablet: 2 colonne
- Mobile: 1 colonna

**Parallax Motion**:
- Ogni colonna ha velocità scroll diversa
- Colonna sinistra: 100% velocità (normale)
- Colonna centro: 80% velocità (lenta)
- Colonna destra: 60% velocità (molto lenta)
- Crea illusione di profondità

**Smooth Scroll**:
- Custom lerp-based smoothing
- Interpolazione: `lerp(current, target, 0.1)`
- Risultato: movimento fluido come burro

**Hover Overlays**:
- Album info appare on hover
- Categoria badge, titolo, descrizione
- Fade-in/out smooth

**Full-Screen Cards**:
- Ogni album: 400px altezza minima
- Padding generoso
- Impatto visivo drammatico

**Configurazione**:
```
Admin → Pagine → Home Page → Parallax
```

**Opzioni**:
- **Parallax Speed**: Slow, Medium, Fast
- **Column Gap**: 0px (no gap) → 40px
- **Card Height**: 300px → 600px
- **Enable Smooth Scroll**: On/Off

**Performance**:
- `will-change: transform` per GPU acceleration
- `requestAnimationFrame` per smooth 60fps
- Throttled su scroll events

**Quando usarlo**:
- Landscape photography
- Travel photography
- Vuoi "wow factor" immediato
- Target: Fotografi che vendono emozioni, non solo tecnica

**Mobile**:
- Parallax disabilitato (single column, no parallax)
- Card height ridotto (300px)
- Scroll nativo (no custom smooth scroll)

---

## 4. Gallery Wall

### Descrizione

**Gallery Wall** trasforma scroll verticale in movimento orizzontale. Come camminare lungo una galleria d'arte.

### Caratteristiche

**Sticky Container**:
- Gallery "si blocca" al viewport durante scroll
- Scrolli giù → gallery scorre lateralmente
- Quando finisce gallery → continua scroll normale

**Horizontal Motion**:
- Scroll down mapped to horizontal movement
- Smooth transformation via CSS `translateX()`
- Animazione fluida Lenis-powered

**Aspect-Aware Sizing**:
- Immagini orizzontali (landscape): larghezza 60vw
- Immagini verticali (portrait): larghezza 40vw
- Altezza proporzionale (mantiene aspect ratio)

**Hover Details**:
- Overlay con album info
- Titolo, categoria, numero foto
- Click per aprire album

**Smooth Animation**:
- Lenis smooth scroll integration
- Momentum naturale
- No scatti o jump

**Configurazione**:
```
Admin → Pagine → Home Page → Gallery Wall
```

**Opzioni**:
- **Gallery Height**: 80vh, 90vh, 100vh
- **Image Width (Landscape)**: 50vw, 60vw, 70vw
- **Image Width (Portrait)**: 30vw, 40vw, 50vw
- **Scroll Speed Multiplier**: 0.5x → 2x

**Calcolo Scroll**:
```javascript
// Scroll progress: 0 → 1
const scrollProgress = window.scrollY / maxScroll;

// Horizontal offset
const translateX = -(galleryWidth - viewportWidth) * scrollProgress;
```

**Quando usarlo**:
- Vuoi qualcosa di diverso e memorabile
- Portfolio exhibition-style
- Hai molte immagini panoramiche (landscape)
- Target: Fine art, conceptual photography

**Mobile**:
- Horizontal scroll disabilitato
- Diventa vertical grid standard
- Preserva aspect ratios

---

## 5. Masonry Wall

### Descrizione

**Masonry Wall** riempie lo schermo con puro contenuto. Colonne CSS-based masonry con infinite scroll automatico che crea loop continuo.

### Caratteristiche

**CSS Column-Based Masonry**:
- Nativo CSS `column-count` (no JavaScript)
- Performance eccellente
- Rispetta aspect ratio

**Configurable Columns**:
- **Desktop**: 2-8 colonne (default: 4)
- **Tablet**: 2-6 colonne (default: 3)
- **Mobile**: 1-4 colonne (default: 2)

**Adjustable Gaps**:
- **Horizontal gap**: 0-40px (default: 16px)
- **Vertical gap**: 0-40px (default: 16px)
- Indipendenti per controllo fine

**Infinite Scroll Loop**:
- Clona automaticamente gli album
- Quando arrivi alla fine → ricomincia dall'inizio
- Seamless, nessun indicatore di "reset"
- Esperienza truly infinite

**Fade-In Animation**:
- Album appaiono con stagger (ritardo progressivo)
- Delay: `index * 50ms`
- Transizione opacity + translateY
- Above-fold images caricano per prime (high priority)

**Responsive Priority**:
- Immagini visibili (viewport): `loading="eager"`
- Immagini below-fold: `loading="lazy"`
- Ottimizzazione automatica

**Configurazione**:
```
Admin → Pagine → Home Page → Masonry Wall
```

**Opzioni**:
- **Desktop Columns**: 2, 3, 4, 5, 6, 7, 8
- **Tablet Columns**: 2, 3, 4, 5, 6
- **Mobile Columns**: 1, 2, 3, 4
- **Horizontal Gap**: 0px → 40px
- **Vertical Gap**: 0px → 40px
- **Enable Infinite Loop**: On/Off
- **Clone Multiplier**: 1x, 2x, 3x (quante volte clonare per loop)

**Quando usarlo**:
- Hai MOLTE immagini (50+, 100+)
- Vuoi immersione totale, zero distrazione
- Street photography, documentary, high-volume
- Target: Fotoreporter, street photographers

**Mobile**:
- 1-2 colonne (configurabile)
- Infinite loop preservato
- Gap ridotto per max contenuto

---

## 6. Snap Albums

### Descrizione

**Snap Albums** è presentazione mode: split screen con album info sulla sinistra e cover image sulla destra. Scroll sincronizzato tra le due metà.

### Caratteristiche

**Split Layout**:
- **Desktop**: 45% info panel | 55% cover images
- **Tablet**: 50% | 50%
- **Mobile**: Stacked vertical (info sopra, image sotto)

**Scroll Sync**:
- Colonna sinistra e destra scrollano insieme
- Perfettamente allineate
- Un album = una "slide"

**Album Details Panel**:
- Titolo album (grande, bold)
- Anno pubblicazione
- Descrizione (3-4 righe)
- Photo count badge
- Categoria badge

**Dot Indicators**:
- Navigazione visuale tra album
- Dot attivo highlightato
- Click dot → scroll to album
- Auto-update durante scroll

**Full-Height Cards**:
- Ogni album occupa 100vh (full viewport height)
- Snap scrolling: quando scrolli, si blocca su prossimo album
- Comportamento slide-like

**Mobile Optimized**:
- Stacked vertical cards
- Info on top, image below
- Snap scroll preservato
- Touch-friendly

**Configurazione**:
```
Admin → Pagine → Home Page → Snap Albums
```

**Opzioni**:
- **Split Ratio**: 40/60, 45/55, 50/50
- **Enable Snap Scroll**: On/Off
- **Show Dot Navigation**: On/Off
- **Description Lines**: 2, 3, 4, 5
- **Info Panel Background**: Transparent, White, Dark

**Snap Scroll CSS**:
```css
scroll-snap-type: y mandatory;
scroll-snap-align: start;
```

Browser support: 95%+ (tutti moderni)

**Quando usarlo**:
- Portfolio project-based (ogni album è un progetto)
- Vuoi storytelling, non semplice galleria
- Hai <30 album (snap scroll diventa tedioso con troppi)
- Target: Editorial photographers, conceptual work

**Mobile**:
- Vertical stack
- 100vh cards
- Snap scroll opzionale (alcune persone lo disabilitano su mobile)

---

## Quale Template Scegliere

### Per Tipo di Fotografia

| Genere | Template Consigliato | Alternativa |
|--------|---------------------|-------------|
| **Wedding** | Classic | Modern |
| **Portrait** | Classic, Modern | Snap Albums |
| **Landscape** | Parallax | Gallery Wall |
| **Architecture** | Modern | Masonry Wall |
| **Street** | Masonry Wall | Modern |
| **Fashion** | Modern | Snap Albums |
| **Documentary** | Masonry Wall | Parallax |
| **Fine Art** | Gallery Wall | Parallax |
| **Travel** | Parallax | Masonry Wall |
| **Editorial** | Snap Albums | Classic |

---

### Per Numero di Album

| Album Count | Template | Motivo |
|-------------|----------|--------|
| **< 10** | Modern, Snap Albums | Pochi album → mostrarli bene |
| **10-30** | Classic, Parallax | Range medio, versatile |
| **30-50** | Masonry Wall, Gallery Wall | Molti album → immersione |
| **50+** | Masonry Wall + Infinite Loop | Massima densità |

---

### Per Stile Brand

| Brand Style | Template |
|-------------|----------|
| **Minimal / Clean** | Modern |
| **Editorial / Magazine** | Classic, Snap Albums |
| **Bold / Dramatic** | Parallax, Gallery Wall |
| **High Volume** | Masonry Wall |
| **Storytelling** | Snap Albums |

---

### Test A/B

Non sei sicuro? Prova 2-3 template per 1 settimana ciascuno:

**Settimana 1**: Modern
**Settimana 2**: Parallax
**Settimana 3**: Classic

Poi controlla analytics:
- Bounce rate (quale trattiene meglio?)
- Time on page (quale coinvolge di più?)
- Pages per session (quale spinge a esplorare?)
- Click su album (quale converte meglio?)

---

## Personalizzazione

### Via Admin UI

Ogni template ha opzioni specifiche in:
```
Admin → Pagine → Home Page → [Template Name] → Opzioni
```

**Esempio Modern**:
- Sidebar width: cursore 20% → 30%
- Parallax intensity: None, Light, Medium, Strong
- Grid columns: 2 o 3

**Salva e Preview**: Le modifiche sono immediatamente visibili.

---

### Via Custom CSS

Per personalizzazioni avanzate:

**Admin → Impostazioni → Custom CSS**

#### Classic - Cambia hero background

```css
/* Sfumatura invece di colore solido */
.home-classic .hero-section {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

/* Immagine di sfondo */
.home-classic .hero-section {
  background-image: url('/media/hero-bg.jpg');
  background-size: cover;
  background-position: center;
}

/* Overlay scuro su hero image */
.home-classic .hero-section::before {
  content: '';
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
}
```

#### Modern - Cambiacolori sidebar

```css
/* Sidebar scura con testo chiaro */
.home-modern .sidebar {
  background: #1a1a1a;
  color: #fafafa;
}

.home-modern .sidebar a {
  color: #fafafa;
}

.home-modern .sidebar a:hover {
  color: #3b82f6; /* blu accent */
}
```

#### Parallax - Aumenta gap tra colonne

```css
.home-parallax .grid {
  gap: 2rem; /* default: 1rem */
}
```

#### Gallery Wall - Cambia altezza gallery

```css
.home-gallery-wall .gallery-container {
  height: 100vh; /* default: 90vh */
}
```

#### Masonry Wall - Bordi arrotondati

```css
.home-masonry .album-card {
  border-radius: 12px;
  overflow: hidden;
}

.home-masonry .album-card img {
  border-radius: 12px;
}
```

#### Snap Albums - Info panel trasparente

```css
.home-snap .info-panel {
  background: transparent;
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
}
```

---

### Aggiungere Elementi Custom

#### Logo Custom nel Hero (Classic)

```html
<!-- In Custom CSS (se supporta HTML) -->
<div class="custom-hero-logo">
  <img src="/media/logo-hero.svg" alt="Logo">
</div>

<style>
.custom-hero-logo {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  z-index: 10;
}

.custom-hero-logo img {
  max-width: 300px;
  filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1));
}
</style>
```

#### Tagline Animata (Classic)

```css
.hero-tagline {
  animation: fadeInUp 1s ease-out 0.5s both;
}

@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
```

#### Scroll Indicator (Snap Albums)

```html
<div class="scroll-indicator">
  <span>Scroll</span>
  <svg><!-- freccia giù --></svg>
</div>

<style>
.scroll-indicator {
  position: fixed;
  bottom: 2rem;
  left: 50%;
  transform: translateX(-50%);
  animation: bounce 2s infinite;
}

@keyframes bounce {
  0%, 100% { transform: translateX(-50%) translateY(0); }
  50% { transform: translateX(-50%) translateY(-10px); }
}
</style>
```

---

## Performance e Ottimizzazione

### Lazy Loading

Tutti i template implementano lazy loading automatico:

```html
<img loading="lazy" ... >
```

**Above-fold images** (primi visibili):
```html
<img loading="eager" fetchpriority="high" ... >
```

**Risultato**: Caricamento iniziale velocissimo, immagini caricano on-demand durante scroll.

---

### Infinite Scroll Debouncing

Per Masonry Wall con infinite loop:

- Throttle scroll events: max 1 controllo ogni 100ms
- Prevent re-clone durante scroll rapido
- `requestAnimationFrame` per smooth update

**Performance**: 60fps anche con 200+ immagini.

---

### Parallax GPU Acceleration

```css
.parallax-element {
  will-change: transform;
  transform: translateZ(0); /* Force GPU */
}
```

**Trade-off**:
- Pro: Smooth 60fps animation
- Contro: Maggior consumo batteria mobile

**Mitigazione**: Disabilita parallax su mobile (già implementato).

---

### Smooth Scroll Performance

**Modern e Parallax** usano Lenis smooth scroll:

```javascript
// Configurazione ottimizzata
new Lenis({
  duration: 1.2,
  easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
  smoothWheel: true,
  wheelMultiplier: 1,
  touchMultiplier: 2,
});
```

**Fallback**: Su browser vecchi (IE11), usa scroll nativo.

---

## Mobile Considerations

### Responsive Breakpoints

```css
/* Desktop */
@media (min-width: 1024px) {
  /* Template full-featured */
}

/* Tablet */
@media (min-width: 768px) and (max-width: 1023px) {
  /* Simplified, riduzione colonne */
}

/* Mobile */
@media (max-width: 767px) {
  /* Stack vertical, no parallax */
}
```

---

### Touch Gestures

**Gallery Wall** horizontal scroll:
- Desktop: Scroll wheel verticale → movimento orizzontale
- Mobile/Tablet: Swipe orizzontale nativo

**Snap Albums**:
- Desktop: Scroll snap verticale
- Mobile: Touch-friendly snap scroll

---

### Performance su Mobile

**Auto-disabilitati su mobile**:
- Parallax effects
- Smooth scroll custom (usa scroll nativo)
- Heavy animations

**Motivo**: Risparmio batteria, evitare jank.

**Implementazione**:
```javascript
const isMobile = window.innerWidth < 768;
if (!isMobile) {
  enableParallax();
  enableSmoothScroll();
}
```

---

## Prossimi Passi

Home page configurata! Ora:
- [Scegli template per le gallerie](./template-gallerie.md)
- [Carica i tuoi album](./album-gallerie.md)
- [Configura tipografia](./tipografia.md) per matching perfetto
- [Abilita dark mode](./dark-mode.md) e testa su entrambi i temi
