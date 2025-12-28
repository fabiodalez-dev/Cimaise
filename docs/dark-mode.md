# Dark Mode in Cimaise

Modalità scura completa per il frontend con toggle per i visitatori.

**Accesso**: Admin → Impostazioni → Frontend → Dark Mode

---

## 📋 Indice

- [Attivazione](#attivazione)
- [Come Funziona](#come-funziona)
- [Colori e Palette](#colori-e-palette)
- [Pagine Supportate](#pagine-supportate)
- [Comportamento Utente](#comportamento-utente)
- [Personalizzazione](#personalizzazione)
- [Accessibilità](#accessibilità)

---

## Attivazione

### Passo 1: Abilita Dark Mode

```
Admin → Impostazioni → Frontend → Dark Mode
☑️ Abilita Dark Mode
→ Salva Impostazioni
```

### Passo 2: Verifica il Toggle

Visita il frontend del tuo portfolio. Nell'header apparirà un pulsante:

- **☀️ Icona Sole** = Modalità chiara attiva (click per passare a scura)
- **🌙 Icona Luna** = Modalità scura attiva (click per tornare a chiara)

**Posizione toggle**:
- Desktop: Angolo in alto a destra, accanto al menu
- Mobile: Nel menu hamburger

---

## Come Funziona

### Toggle Icon Animation

Click sull'icona attiva una transizione smooth (0.3s):
- Colori invertiti gradualmente
- Icona ruota e cambia sole ↔ luna
- Nessun flash o blink

### Persistenza Preferenza

La scelta dell'utente viene salvata in:
- **localStorage** del browser
- Chiave: `darkMode`
- Valori: `"enabled"` | `"disabled"`

**Risultato**:
- L'utente sceglie dark mode → preferenza ricordata per sempre
- Anche chiudendo il browser
- Anche tornando dopo mesi
- Anche su pagine diverse del portfolio

**Reset preferenza**:
L'utente può solo cambiare manualmente il toggle. Non c'è scadenza automatica.

---

## Colori e Palette

### Light Mode (Default)

| Elemento | Colore | Codice | Note |
|----------|--------|--------|------|
| Background principale | Bianco | `#ffffff` | Pagina, cards |
| Testo primario | Nero | `#1a1a1a` | Headings, body |
| Testo secondario | Grigio scuro | `#4b5563` | Captions |
| Border | Grigio chiaro | `#e5e7eb` | Dividers, cards |
| Accent | Variabile | Custom | Dipende dal tema |

### Dark Mode

| Elemento | Colore | Codice | Note |
|----------|--------|--------|------|
| Background principale | Quasi nero | `#0a0a0a` | Pagina base |
| Background elevato | Grigio molto scuro | `#171717` | Cards, modali |
| Testo primario | Quasi bianco | `#fafafa` | Headings, body |
| Testo secondario | Grigio medio | `#a3a3a3` | Captions |
| Border | Grigio scuro | `#262626` | Dividers |
| Accent | Più chiaro | Auto-adjusted | Maggior contrasto |

### Semantica Colori Preservata

Alcuni colori mantengono significato semantico e **non vengono invertiti**:

**Colori Categoria/Tag** (badges):
- Se in light mode un badge è blu (`bg-blue-500`)
- In dark mode resta blu, ma con tonalità più chiara per contrasto (`bg-blue-400`)

**Colori di Stato**:
- ✅ Success: Verde in entrambi i modi
- ❌ Error: Rosso in entrambi i modi
- ⚠️ Warning: Giallo/arancione in entrambi i modi

**Immagini**:
- Le foto **NON cambiano** in dark mode
- Nessun filtro applicato alle gallerie
- Solo UI intorno alle immagini diventa scura

---

## Pagine Supportate

### ✅ Frontend Completo

Dark mode funziona su tutte le pagine pubbliche:

**Home Pages** (7 template):
- ✅ Classic (horizontal & vertical)
- ✅ Modern
- ✅ Parallax
- ✅ Gallery Wall
- ✅ Masonry Wall
- ✅ Snap Albums

**Gallerie**:
- ✅ Archive galleries (`/galleries`)
- ✅ Category pages (`/category/...`)
- ✅ Tag pages (`/tag/...`)

**Album** (6 template):
- ✅ Classic Grid
- ✅ Masonry
- ✅ Masonry Full
- ✅ Magazine
- ✅ Magazine + Cover
- ✅ Slideshow

**Lightbox**:
- ✅ PhotoSwipe (visualizzazione full-screen)
- Background scuro
- UI controls adattati

**Pagine Statiche**:
- ✅ About
- ✅ Contact

**Login**:
- ✅ Pagina `/admin/login`
- Prima impressione coerente con il resto

---

### ❌ Pannello Admin (Sempre Chiaro)

Il pannello amministratore **NON ha dark mode** intenzionalmente:

**Motivi**:
- Leggibilità form complessi
- Standard UI per produttività
- Evitare affaticamento durante editing lungo
- Consistenza con tool professionali (es. WordPress admin)

Se desideri dark mode admin, richiesta feature su [GitHub Issues](https://github.com/yourusername/cimaise/issues).

---

## Comportamento Utente

### Scenario 1: Primo Visitatore

```
1. Utente arriva sul sito
   → Light mode (default)

2. Utente clicca toggle dark mode
   → Passa a dark mode
   → localStorage: darkMode = "enabled"

3. Utente naviga tra le pagine
   → Dark mode rimane attivo

4. Utente chiude browser e torna dopo 3 giorni
   → Dark mode ancora attivo (preferenza ricordata)
```

---

### Scenario 2: Preferenza Sistema Operativo (Future)

**Attualmente**: Cimaise usa light mode di default per tutti.

**Roadmap futura** (v2.0):
```javascript
// Rilevamento preferenza OS
if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
  // Attiva dark mode se utente non ha preferenza salvata
}
```

Feature tracker: [Issue #XX](https://github.com/yourusername/cimaise/issues)

---

### Scenario 3: Condivisione Link

```
Fotografo invia link album a cliente:
https://portfolio.com/album/wedding-smith

Cliente apre link:
→ Light mode (default)
→ Cliente può scegliere dark mode se preferisce
```

**Nota**: Non puoi forzare dark mode per link condivisi. Ogni utente ha la propria preferenza.

---

## Personalizzazione

### Cambiare Colori Dark Mode

**Via Custom CSS** (Admin → Impostazioni → Custom CSS):

```css
/* Override colori dark mode */
body.dark-mode {
  --dark-bg: #0d1117 !important;        /* Sfondo GitHub-style */
  --dark-bg-elevated: #161b22 !important; /* Cards */
  --dark-text: #c9d1d9 !important;      /* Testo */
  --dark-border: #30363d !important;    /* Bordi */
}
```

**Palette alternative**:

#### **True Black (OLED)**
Ottimo per schermi OLED (risparmio batteria):

```css
body.dark-mode {
  --dark-bg: #000000 !important;        /* Nero puro */
  --dark-bg-elevated: #121212 !important;
  --dark-text: #ffffff !important;
}
```

#### **Warm Dark**
Tonalità più calda, meno affaticamento:

```css
body.dark-mode {
  --dark-bg: #1c1917 !important;        /* Marrone scurissimo */
  --dark-bg-elevated: #292524 !important;
  --dark-text: #fafaf9 !important;
  --dark-border: #44403c !important;
}
```

#### **Blue Dark**
Ispirato a GitHub/Twitter dark:

```css
body.dark-mode {
  --dark-bg: #0d1117 !important;
  --dark-bg-elevated: #161b22 !important;
  --dark-text: #c9d1d9 !important;
  --dark-accent: #58a6ff !important;    /* Link blu */
}
```

---

### Disabilitare Dark Mode per Specifici Elementi

```css
/* Forza sempre chiaro per determinati elementi */
.force-light {
  background: white !important;
  color: black !important;
}

body.dark-mode .force-light {
  background: white !important;
  color: black !important;
}

/* Esempio: logo sempre su sfondo bianco */
body.dark-mode .site-logo-container {
  background: white;
  padding: 0.5rem;
  border-radius: 4px;
}
```

---

### Cambio Durata Transizione

Default: `0.3s`

```css
/* Transizione più veloce (snappy) */
body {
  transition: background-color 0.15s ease, color 0.15s ease !important;
}

/* Transizione più lenta (smooth) */
body {
  transition: background-color 0.6s ease, color 0.6s ease !important;
}

/* No transizione (cambio istantaneo) */
body {
  transition: none !important;
}
```

---

### Nascondere il Toggle Dark Mode

Se vuoi abilitare dark mode ma **senza** dare scelta agli utenti:

```css
/* Nascondi toggle */
.dark-mode-toggle {
  display: none !important;
}
```

Poi forza dark mode via JavaScript in Custom CSS:

```html
<script>
  // Forza sempre dark mode
  document.body.classList.add('dark-mode');
  localStorage.setItem('darkMode', 'enabled');
</script>
```

**Caso d'uso**: Portfolio monocromatico scuro, esperienza controllata.

---

## Accessibilità

### Contrasto WCAG

Cimaise dark mode rispetta **WCAG AA** (4.5:1 per testo normale):

| Combinazione | Contrasto | Standard |
|--------------|-----------|----------|
| `#fafafa` su `#0a0a0a` | 19.8:1 | ✅ AAA |
| `#a3a3a3` su `#171717` | 7.2:1 | ✅ AAA |
| `#737373` su `#0a0a0a` | 5.4:1 | ✅ AA |

**Test manuale**:
- [WebAIM Contrast Checker](https://webaim.org/resources/contrastchecker/)
- Chrome DevTools → Lighthouse → Accessibility

---

### prefers-color-scheme (Auto)

**Stato attuale**: Cimaise non usa `prefers-color-scheme` automaticamente.

**Motivo**: Controllo esplicito dell'utente > inferenza OS.

**Se vuoi implementarlo**:

```javascript
// In Custom CSS/JS
(function() {
  // Solo se utente non ha preferenza salvata
  if (!localStorage.getItem('darkMode')) {
    if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
      document.body.classList.add('dark-mode');
    }
  }
})();
```

Aggiungilo in **Admin → Settings → Custom CSS** (se supporta `<script>`).

---

### Riduzione Movimento (prefers-reduced-motion)

Cimaise rispetta automaticamente:

```css
@media (prefers-reduced-motion: reduce) {
  body, * {
    transition: none !important;
    animation: none !important;
  }
}
```

Utenti con:
- Motion sickness
- Vestibular disorders
- Impostazione OS "Riduci movimento"

Vedono cambio dark mode istantaneo senza transizione.

---

## Testing e Debug

### Testa Dark Mode

**Da Visitatore**:
1. Apri portfolio in incognito (no preferenze salvate)
2. Click toggle dark mode
3. Naviga tra le pagine → verifica persistenza
4. Chiudi e riapri browser → verifica localStorage

**Forza Dark Mode (DevTools)**:
```javascript
// Console browser
document.body.classList.add('dark-mode');
localStorage.setItem('darkMode', 'enabled');
```

**Reset Preferenza**:
```javascript
// Console browser
localStorage.removeItem('darkMode');
location.reload();
```

---

### Problemi Comuni

#### Toggle Non Appare

**Causa**: Dark mode non abilitato in Admin

**Soluzione**:
```
Admin → Impostazioni → Frontend → Dark Mode
☑️ Abilita
Salva Impostazioni
```

Svuota cache browser: `Ctrl + Shift + R` (Windows) o `Cmd + Shift + R` (Mac)

---

#### Preferenza Non Persiste

**Causa**: localStorage bloccato (privacy mode estrema o estensioni)

**Debug**:
```javascript
// Console
console.log(localStorage.getItem('darkMode'));
// Se ritorna null dopo aver attivato dark mode → problema localStorage
```

**Soluzioni**:
- Disabilita modalità privacy/incognito
- Disabilita estensioni anti-tracking aggressive
- Usa cookie fallback (richiede modifica codice)

---

#### Alcuni Elementi Restano Chiari

**Causa**: CSS custom override con `!important` o selettori molto specifici

**Debug**:
```
DevTools → Ispeziona elemento → Styles tab
Cerca regole con maggiore specificità
```

**Soluzione**:
```css
/* Nel Custom CSS, aumenta specificità */
body.dark-mode .mio-elemento {
  background: var(--dark-bg) !important;
  color: var(--dark-text) !important;
}
```

---

#### Immagini Troppo Luminose

**Non è un bug**: Le foto NON dovrebbero cambiare tonalità in dark mode.

Se vuoi applicare overlay scuro alle immagini:

```css
/* Overlay semi-trasparente su immagini in dark mode */
body.dark-mode .album-image img {
  filter: brightness(0.9);
}

/* Più aggressivo */
body.dark-mode .album-image img {
  filter: brightness(0.8) contrast(1.1);
}
```

**⚠️ Sconsigliato**: Altera la visione delle tue foto.

---

## Statistiche e Analytics

### Traccia Utilizzo Dark Mode

**Con Analytics Plugin**:

```javascript
// In Custom CSS/JS
document.querySelector('.dark-mode-toggle').addEventListener('click', function() {
  const isDark = document.body.classList.contains('dark-mode');

  // Invia a tuo sistema analytics
  if (window.analytics) {
    analytics.track('Dark Mode Toggle', {
      mode: isDark ? 'dark' : 'light'
    });
  }
});
```

**Domande da rispondere**:
- Quale % visitatori usa dark mode?
- C'è correlazione con orario (sera = più dark mode)?
- Utenti mobile vs desktop preferenze?

**Decisioni basate su dati**:
- Se <5% usa dark mode → considera rimuovere (meno manutenzione)
- Se >50% usa dark mode → considera renderlo default
- Se pattern temporali evidenti → auto-switch basato su orario?

---

## Best Practices

### DO ✅

- Testa dark mode su **tutti** i template home/gallery che usi
- Verifica contrasto con [WebAIM tool](https://webaim.org/resources/contrastchecker/)
- Testa su schermi OLED (smartphone) e LCD (desktop)
- Mantieni foto originali senza filtri
- Usa transizioni smooth (<0.5s)

### DON'T ❌

- Non forzare dark mode senza toggle (toglie controllo utente)
- Non applicare `filter: invert()` su tutto (risulta orribile)
- Non usare grigio medio (#808080) su sfondo scuro (basso contrasto)
- Non dimenticare stati hover/focus in dark mode
- Non cambiare colori brand/categoria in dark mode

---

## Roadmap Future Features

Pianificato per versioni future:

- [ ] **Auto dark mode basato su orario** (sunset → dark, sunrise → light)
- [ ] **Sync con prefers-color-scheme** OS
- [ ] **Modalità "High Contrast"** per ipovedenti
- [ ] **Dark mode admin panel** (opzionale)
- [ ] **Preset palette** (GitHub Dark, Dracula, Nord, etc.)

Vota feature su [GitHub Discussions](https://github.com/yourusername/cimaise/discussions).

---

## FAQ

### Dark mode impatta le performance?

**No**. Cambio colori via CSS è istantaneo. Zero impatto su caricamento pagina.

### I motori di ricerca vedono dark o light mode?

**Light mode**. Google/Bing crawler non eseguono JavaScript interattivo, quindi vedono sempre versione default chiara.

### Posso avere dark mode di default?

Sì, via Custom JavaScript:

```javascript
// Forza dark mode come default
if (!localStorage.getItem('darkMode')) {
  document.body.classList.add('dark-mode');
  localStorage.setItem('darkMode', 'enabled');
}
```

### Dark mode funziona con Custom CSS?

Sì, ma testa accuratamente. Il tuo CSS custom potrebbe override colori dark mode.

**Soluzione**: Usa variabili CSS invece di valori hard-coded:

```css
/* ❌ Problematico */
.my-card {
  background: white;
  color: black;
}

/* ✅ Corretto */
.my-card {
  background: var(--bg-color);
  color: var(--text-color);
}
```

---

## Prossimi Passi

Dark mode configurato! Ora:
- [Personalizza con Custom CSS](./custom-css.md) per affinare colori
- [Configura le Home Pages](./home-pages.md) e testa in entrambe le modalità
- [Imposta tipografia](./tipografia.md) (i font si adattano automaticamente)
