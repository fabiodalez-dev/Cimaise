# Modalità Manutenzione

Plugin per mettere il sito "in costruzione" mentre lavori sul portfolio.

**Tipo**: Plugin integrato
**Stato**: Incluso in Cimaise (dalla PR #25)

---

## 📋 Indice

- [Panoramica](#panoramica)
- [Attivazione](#attivazione)
- [Configurazione](#configurazione)
- [Cosa Vedono i Visitatori](#cosa-vedono-i-visitatori)
- [Admin Bypass](#admin-bypass)
- [SEO e Motori di Ricerca](#seo-e-motori-di-ricerca)
- [Personalizzazione](#personalizzazione)

---

## Panoramica

La **Modalità Manutenzione** trasforma temporaneamente il frontend in una pagina "Coming Soon" / "Under Construction", mentre tu puoi continuare a lavorare nel pannello admin.

**Quando usarla**:
- ✅ Setup iniziale del portfolio (prima di pubblicare)
- ✅ Redesign completo
- ✅ Manutenzione straordinaria (migrazione server, aggiornamenti grandi)
- ✅ Chiusura temporanea (vacanza, pausa attività)

**Quando NON usarla**:
- ❌ Aggiungere foto (non serve, fallo normalmente)
- ❌ Modifiche minori (typo, testi)
- ❌ Più di qualche settimana (SEO penalizzato)

---

## Attivazione

### Step 1: Verifica Plugin Attivo

Il plugin è già installato di default. Verifica:

```
Admin → Plugins → Maintenance Mode
Status: ● Active
```

Se inattivo:
```
[Activate Button] → Click
```

### Step 2: Abilita Modalità Manutenzione

```
Admin → Impostazioni → Maintenance Mode
☑️ Enable Maintenance Mode
→ Save Settings
```

### Step 3: Verifica

Apri una finestra incognito e vai su:
```
https://tuoportfolio.com
```

Dovresti vedere la pagina manutenzione invece dell'homepage normale.

---

## Configurazione

### Opzioni Disponibili

**Accesso**: Admin → Impostazioni → Maintenance Mode

#### Enable Maintenance Mode
**Campo**: Checkbox

- ☐ **OFF**: Sito normale, pubblico
- ☑️ **ON**: Modalità manutenzione attiva

#### Title
**Campo**: Text
**Default**: "Site Under Construction"

Titolo principale della pagina manutenzione.

**Esempi**:
```
"We'll Be Back Soon"
"Under Construction"
"Coming Soon"
"Maintenance in Progress"
"Chiuso per Ferie" (se sei in vacanza)
```

**Supporta HTML**:
```html
Site <strong>Upgrading</strong>
```

#### Message
**Campo**: Textarea
**Default**: "We are currently working on the site. Please check back soon."

Messaggio descrittivo per i visitatori.

**Esempi**:

```
We're refreshing our portfolio with new work.
Check back in a few days!
```

```
Stiamo aggiornando il sito con nuovi progetti.
Torna a trovarci presto!
```

```
Our portfolio is getting a makeover.
In the meantime, follow us on Instagram @yourhandle
```

**Supporta**:
- Multilinea (usa `\n` per a capo)
- HTML base (grassetto, corsivo)
- No Markdown

**Lunghezza**: 500 caratteri max consigliati (leggibilità)

#### Show Logo
**Campo**: Checkbox
**Default**: ☑️ ON

- ☑️ **ON**: Mostra logo del sito (da Admin → Settings → Site Logo)
- ☐ **OFF**: Nessun logo, solo titolo testo

**Nota**: Se non hai caricato logo, mostra titolo sito comunque.

#### Show Countdown
**Campo**: Checkbox
**Default**: ☑️ ON

Mostra animazione loading (puntini pulsanti + progress bar) per effetto "work in progress".

- ☑️ **ON**: Animazione visibile
- ☐ **OFF**: Pagina statica, solo testo

**Animazione**:
- 3 puntini che pulsano (stagger delay)
- Progress bar infinita (0% → 70% → 0%, loop 2s)

**Quando disabilitare**:
- Chiusura indefinita (non c'è countdown reale)
- Preferisci look minimalista

---

## Cosa Vedono i Visitatori

### Pagina Manutenzione

**Layout**:
```
┌─────────────────────────────────────┐
│                                     │
│          [LOGO or SITE NAME]        │
│                                     │
│     Site Under Construction         │  ← Title
│                                     │
│   We're refreshing our portfolio    │  ← Message
│      Check back in a few days       │
│                                     │
│         ● ● ●                       │  ← Pulsing dots (if countdown ON)
│     [████████▒▒▒▒▒▒▒▒]              │  ← Progress bar (if countdown ON)
│                                     │
│      [Admin Login Button]           │  ← Per te
│                                     │
│   © 2025 Your Portfolio Name        │  ← Footer auto
└─────────────────────────────────────┘
```

**Colori**:
- Background: `#ffffff` (bianco)
- Testo titolo: `#1a1a1a` (nero)
- Testo messaggio: `#6b7280` (grigio)
- Puntini animati: `#9ca3af` → `#1a1a1a` (fade)
- Progress bar: `#1a1a1a` su `#e5e7eb`

**Responsive**:
- Desktop: Centrato, max-width 600px
- Mobile: Padding ridotto, font più piccoli

---

### Pulsante Admin Login

Il pulsante "Admin Login" è sempre presente per permetterti di rientrare.

**Testo pulsante**:
- Se lingua sito = Inglese → "Admin Login"
- Se lingua sito = Italiano → "Accedi come Admin"
- Se lingua sito = Tedesco → "Admin-Anmeldung"
- Se lingua sito = Francese → "Connexion Admin"
- Se lingua sito = Spagnolo → "Acceso Admin"

**Link**: `https://tuosito.com/admin/login`

**Styling**:
```css
background: #1a1a1a (nero)
color: #ffffff (bianco)
padding: 0.75rem 1.5rem
border-radius: 6px
hover: background #374151 (grigio scuro)
```

---

## Admin Bypass

### Accesso Admin Durante Manutenzione

Quando manutenzione è attiva:

**✅ Puoi fare**:
- Accedere a `/admin/login`
- Usare tutto il pannello admin normalmente
- Vedere il frontend reale (se loggato)

**❌ Visitatori NON possono**:
- Vedere homepage
- Aprire album
- Navigare il portfolio

**Meccanismo**:

```php
// Ogni request frontend controlla:
if (MaintenanceModeActive && !UserIsAdmin) {
    return MaintenancePage;
}
```

**Logout admin**:
Se fai logout → vedi pagina manutenzione come visitatori.

---

### Preview Frontend Reale

Per vedere come visitatori vedranno il sito dopo che disattivi manutenzione:

**Opzione 1**: Apri finestra incognito, fai login admin
```
Incognito → tuosito.com/admin/login
→ Login
→ Visita tuosito.com
→ Frontend normale (sei admin)
```

**Opzione 2**: Disabilita temporaneamente, controlla, riabilita
```
Settings → Maintenance Mode
☐ Disable
Save → Controlla frontend → Settings
☑️ Re-enable
Save
```

---

## SEO e Motori di Ricerca

### HTTP Status Code

Quando manutenzione è attiva, Cimaise ritorna:

```
HTTP/1.1 503 Service Unavailable
Retry-After: 3600
```

**503 = Temporaneo**:
- Google capisce che il sito tornerà
- NON rimuove dal index (per qualche settimana)
- Crawler riproveranno dopo `Retry-After` (1 ora)

**Retry-After: 3600**:
- Googlebot riprova dopo 3600 secondi (1 ora)
- Evita crawling continuo inutile

---

### Meta Robots

```html
<meta name="robots" content="noindex, nofollow">
```

**noindex**: Non indicizzare questa pagina
**nofollow**: Non seguire link da questa pagina

**Risultato**: Pagina manutenzione non appare nei risultati Google.

---

### X-Robots-Tag Header

```
X-Robots-Tag: noindex, nofollow
```

Doppia protezione (meta tag + HTTP header).

---

### Impatto SEO

**Durata < 7 giorni**:
- ✅ Zero impatto
- Google comprende manutenzione temporanea

**Durata 1-4 settimane**:
- ⚠️ Possibile lieve calo ranking
- Recupero veloce dopo riattivazione

**Durata > 1 mese**:
- ❌ Rischio de-indicizzazione parziale
- Google assume sito morto
- Recupero lento (2-3 mesi)

**Best Practice**:
- **Max 2 settimane** per manutenzione
- Se serve più tempo → considera staging server
- Comunica su social che stai aggiornando

---

## Personalizzazione

### Cambiare Colori

**Via Custom CSS** (Admin → Settings → Custom CSS):

```css
/* Questa CSS applica SOLO se non sei in maintenance mode
   Per editare maintenance page, vedi sotto */
```

**Maintenance page ha file separato**:
`plugins/maintenance-mode/templates/maintenance.php`

**Editare** (richiede accesso FTP):

```php
<!-- In maintenance.php, linea ~56 -->
<style nonce="...">
  body {
    background-color: #ffffff; /* Cambia qui */
    color: #1a1a1a;
  }

  .title {
    color: #1a1a1a; /* Cambia qui */
  }
</style>
```

**Esempio dark background**:

```css
body {
  background-color: #0a0a0a;
  color: #fafafa;
}

.title {
  color: #fafafa;
}

.message {
  color: #a3a3a3;
}
```

---

### Aggiungere Immagine Background

```css
body {
  background-image: url('/media/maintenance-bg.jpg');
  background-size: cover;
  background-position: center;
}

.container {
  background: rgba(255, 255, 255, 0.95); /* Semi-trasparente */
  padding: 3rem;
  border-radius: 8px;
}
```

---

### Aggiungere Countdown Reale

Se hai data di rientro specifica:

```html
<!-- Aggiungi in maintenance.php prima di </body> -->
<script nonce="...">
const launchDate = new Date('2025-02-01T00:00:00').getTime();

function updateCountdown() {
  const now = new Date().getTime();
  const distance = launchDate - now;

  const days = Math.floor(distance / (1000 * 60 * 60 * 24));
  const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));

  document.getElementById('countdown').innerHTML =
    `Back in ${days} days and ${hours} hours`;
}

setInterval(updateCountdown, 1000);
updateCountdown();
</script>

<div id="countdown" style="font-size: 1.5rem; margin: 2rem 0;"></div>
```

---

### Link Social

Aggiungi link Instagram/Facebook per non perdere contatti:

```html
<!-- In maintenance.php, dopo messaggio -->
<div style="margin-top: 2rem;">
  <p style="color: #6b7280; font-size: 0.875rem;">
    Follow us on
    <a href="https://instagram.com/yourhandle" style="color: #1a1a1a; text-decoration: underline;">Instagram</a>
    for updates
  </p>
</div>
```

---

## Plugin Disabilitazione

Se vuoi **disabilitare completamente** il plugin (non solo manutenzione, ma rimuovere feature):

```
Admin → Plugins → Maintenance Mode → Deactivate
```

**Attenzione**: Disattivare plugin rimuove sezione settings ma **non cancella** configurazione salvata. Riattivando, settings tornano.

**Disinstallazione completa**:

1. Deactivate plugin
2. Delete plugin files:
   ```bash
   rm -rf plugins/maintenance-mode/
   ```
3. Database cleanup (opzionale):
   ```sql
   DELETE FROM settings WHERE key LIKE 'maintenance.%';
   ```

---

## Troubleshooting

### Pagina Manutenzione Non Appare

**Causa 1**: Plugin non attivo

**Soluzione**:
```
Admin → Plugins → Maintenance Mode → Activate
```

**Causa 2**: Setting non salvato

**Soluzione**:
```
Admin → Settings → Maintenance Mode
☑️ Enable Maintenance Mode
Save Settings (bottone in fondo)
```

**Causa 3**: Cache browser

**Soluzione**:
```
Ctrl + Shift + R (Windows)
Cmd + Shift + R (Mac)
```

Oppure usa incognito.

---

### Admin Non Riesce ad Accedere

**Causa**: URL login sbagliato

**Corretto**:
```
https://tuosito.com/admin/login
```

**Sbagliato**:
```
https://tuosito.com/login
https://tuosito.com/wp-admin  (questo è WordPress!)
```

---

### Visitatori Vedono Errore 503 Brutto

**Causa**: Server mostra 503 di default invece di pagina custom

**Soluzione**: Verifica che plugin sia attivo. Plugin intercetta 503 e mostra template pulito.

Se vedi HTML server grezzo tipo:
```
503 Service Unavailable

nginx/1.18.0
```

→ Plugin non sta eseguendo. Controlla logs:

```bash
tail -f storage/logs/app.log
```

---

### SEO: Google Continua a Indicizzare

**Causa**: Cache Google lenta

**Soluzione**:
- Aspetta 48-72 ore
- Usa [Google Search Console](https://search.google.com/search-console) → Request Indexing

**Verifica**:
```bash
curl -I https://tuosito.com

# Controlla header:
HTTP/1.1 503 Service Unavailable
X-Robots-Tag: noindex, nofollow
```

Se vedi `200 OK` invece di `503` → manutenzione non attiva.

---

## Best Practices

### DO ✅

- Avvisa utenti sui social prima di attivare
- Aggiungi messaggio chiaro ("Torniamo il [data]")
- Testa su incognito prima di pubblicare
- Max 2 settimane di manutenzione
- Mostra logo per brand recognition

### DON'T ❌

- Non lasciare attivo per mesi
- Non usare per "site coming soon" permanente (crea staging invece)
- Non dimenticare di disattivare dopo manutenzione!
- Non bloccare crawlers legittimi (Cimaise non lo fa, 503 è corretto)

---

## Alternative a Modalità Manutenzione

### Staging Server

Se manutenzione dura >2 settimane:

**Soluzione**:
1. Crea subdomain: `staging.tuoportfolio.com`
2. Clona Cimaise su staging
3. Lavora su staging
4. Quando pronto → deploy su produzione
5. **Zero downtime** per visitatori

**Vantaggi**:
- SEO preservato
- Clienti vedono sempre portfolio
- Tu lavori liberamente

**Svantaggio**:
- Richiede 2x server space
- Più complesso

---

### .htaccess IP Whitelist

Blocca tutti tranne il tuo IP:

```apache
# .htaccess
Order Deny,Allow
Deny from all
Allow from 203.0.113.42  # Il tuo IP
```

**Pro**: Simple
**Contro**: Se tuo IP cambia (mobile 4G), sei bloccato!

---

## Prossimi Passi

Manutenzione configurata! Ora:
- Testa attivando/disattivando
- [Configura tutte le impostazioni](./impostazioni.md) mentre sei in manutenzione
- [Carica portfolio completo](./album-gallerie.md)
- Disattiva manutenzione quando pronto per launch 🚀
