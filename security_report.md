# 🛡️ REPORT SICUREZZA COMPLETO - photoCMS

## 🔍 ANALISI ATTUALE VS RACCOMANDAZIONI PRECEDENTI

### ✅ MISURE GIÀ IMPLEMENTATE CORRETTAMENTE

Secondo il file `security_anal.md`, le seguenti misure sono state già implementate con successo:

1. **SQL Injection Prevention**: Tutte le query usano prepared statements
2. **Upload Hardening**: Validazione MIME + magic numbers + limiti dimensioni
3. **Download Security**: Prevenzione path traversal + MIME check + sanitizzazione
4. **CSRF Protection**: Token validati su tutti i metodi modificanti
5. **Autenticazione Admin**: Session regeneration + role enforcement + secure hashing
6. **Session Security**: HttpOnly, SameSite=Lax, Secure in produzione
7. **Security Headers Base**: HSTS, nosniff, X-Frame-Options, Referrer-Policy
8. **Rate Limiting Login**: Implementato (anche se migliorabile)

### ⚠️ MISURE ANCORA DA IMPLEMENTARE

## 🔴 VULNERABILITÀ CRITICHE RESIDUE

### 1. FILE INSTALLER PUBBLICI ACCESSIBILI

**Stato**: ❌ NON RISOLTO
**Rischio**: Reinstallazione completa dell'app → perdita dati e nuovo accesso admin

**File ancora presenti**:
```
/public/installer.php
/public/simple-install.php  
/public/repair_install.php
/public/assets/installer.php (DUPLICATO)
```

**Azione Richiesta**: 
```bash
bin/cleanup_leftovers.sh --apply --remove-installers
```

---

### 2. XSS SUI CONTENUTI HTML "RAW"

**Stato**: ❌ NON RISOLTO
**Rischio**: Codice JavaScript eseguito nei contenuti admin

**Template ancora vulnerabili**:
- `frontend/album.twig`: `{{ album.body|raw }}`
- `frontend/gallery.twig`: `{{ album.body|raw }}`  
- `frontend/about.twig`: `{{ about_text|raw }}`
- `frontend/gallery_magazine.twig`: `{{ album.body|raw }}`
- `frontend/gallery_hero.twig`: `{{ album.body|raw }}`

**Azione Richiesta**: 
- Implementare sanitizzazione server-side con whitelist HTML
- Consentire solo: p, a[href|rel|target], strong, em, ul, ol, li, blockquote, h2-h4, hr

---

### 3. XSS DOM NELLE CARD JAVASCRIPT

**Stato**: ❌ NON RISOLTO
**Rischio**: XSS attraverso manipolazione DOM dinamico

**File**: `frontend/galleries.twig`
**Funzione**: `generateGalleryCard()`

**Vulnerabilità critica**:
```javascript
function generateGalleryCard(album) {
    return `
        <h3>${album.title}</h3>  // ⚠️ XSS RISK!
        <p>${album.excerpt}</p>  // ⚠️ XSS RISK!
        <img alt="${album.cover_image.alt_text || album.title}"> // ⚠️ XSS RISK!
    `;
}
```

**Azione Richiesta**:
- Rifattorizzare con `document.createElement()` + `textContent`
- Oppure sanificare dati server-side prima dell'invio JSON

---

### 4. CSP PERMISSIVA CON UNSAFE-INLINE

**Stato**: ❌ NON RISOLTO
**Rischio**: Molteplici vettori XSS possibili

**CSP attuale problematica**:
```php
$csp = "default-src 'self'; 
        img-src 'self' data: blob:;
        script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com;
        style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com http://cdnjs.cloudflare.com https://fonts.googleapis.com;
        font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com data:;
        connect-src 'self';
        object-src 'none';
        base-uri 'self';
        form-action 'self';
        frame-ancestors 'none'";
```

**Problemi identificati**:
1. `'unsafe-inline'` in script-src e style-src
2. CDN esterni senza integrità contenuto (SRI)
3. HTTP misto: `http://cdnjs.cloudflare.com` (deve essere HTTPS)

**Azione Richiesta**:
- Roadmap per migrare script inline a file bundlati
- CSP con nonce/hash al posto di unsafe-inline

---

## 🟡 VULNERABILITÀ MEDIE

### 5. RATE LIMITING FRAGILE

**Stato**: ⚠️ PARZIALMENTE IMPLEMENTATO
**Rischio**: Facilmente bypassabile con cambio sessione/IP

**Problemi attuali**:
- Basato su sessione → restart server resetta conteggi
- Basato su string matching ("Credenziali non valide") → fragile
- Non persistente → vulnerabile a flood

**Miglioramento suggerito**:
- Usare Redis/DB persistente con chiavi IP-based
- Finestra mobile con sliding window algorithm

---

### 6. HEADER DI SICUREZZA MANCANTI

**Stato**: ⚠️ NON COMPLETO
**Miglioramenti suggeriti**:
- Aggiungere `X-Permitted-Cross-Domain-Policies: none`
- Aggiungere `Expect-CT: enforce, max-age=30`
- Correggere `http://cdnjs.cloudflare.com` a `https://`

---

## 🟢 MISURE GIÀ ADEGUATE

### ✅ MEDIA DIRECTORY PROTECTION
File `.htaccess` in `/public/media/` disabilita correttamente l'esecuzione PHP

### ✅ CLEANUP SCRIPT DISPONIBILE
Lo script `bin/cleanup_leftovers.sh` è pronto per rimuovere file superflui

### ✅ SECURITY HEADERS BASE
Headers essenziali già implementati correttamente

---

## 📋 PIANO D'AZIONE PRIORITARIO

### 🔴 CRITICO (DA RISOLVERE IMMEDIATAMENTE)

1. **Rimuovi installer pubblici**:
   ```bash
   bin/cleanup_leftovers.sh --apply --remove-installers
   ```

2. **Fix XSS DOM nelle gallery cards**:
   - Rifattorizzare `generateGalleryCard()` con `textContent`
   - Oppure sanificare dati server-side

3. **Sanifica contenuti HTML raw**:
   - Aggiungere whitelist HTML per tutti i campi `|raw`
   - Consentire solo tag sicuri: p, a, strong, em, ul/ol/li, etc.

### 🟠 IMPORTANTE (DA RISOLVERE A BREVE)

4. **Rafforza CSP**:
   - Rimuovi `'unsafe-inline'` dove possibile
   - Usa nonce per script inline necessari
   - Correggi HTTP a HTTPS nei CDNs

5. **Migliora rate limiting**:
   - Implementa persistenza Redis/IP-based
   - Aggiungi rate limit per `/api/analytics/track`

### 🟡 MANUTENZIONE

6. **Completa header security**:
   - Aggiungi `X-Permitted-Cross-Domain-Policies`
   - Aggiungi `Expect-CT`

7. **Verifica protezione storage**:
   - Aggiungi `.htaccess` in `/storage/` directory

---

## 🛡️ CONCLUSIONE

L'applicazione presenta una **solida base di sicurezza infrastrutturale**, ma soffre di **criticità XSS residue** che rappresentano i maggiori rischi per gli utenti finali. 

Le vulnerabilità più urgenti sono:
1. **XSS DOM nelle card dinamiche** - rischio immediato per visitatori
2. **Installer pubblici accessibili** - rischio critico per proprietario sito
3. **CSP permissiva** - facilita exploit XSS

**Priorità assoluta**: Risolvere XSS e rimuovere installer pubblici entro 24-48 ore.