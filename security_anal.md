Rischi Prioritari

- Installer pubblici accessibili:
    - File: public/installer.php, public/simple-install.php, public/
repair_install.php, duplicato public/assets/installer.php.
    - Rischio: durante produzione, l’installer rimane raggiungibile (anche se prova
a fare redirect quando rileva installazione). Meglio rimuoverli o negarli dal web
server.
    - Azione: usa bin/cleanup_leftovers.sh --apply --remove-installers oppure blocca
via vhost.
- XSS su contenuti HTML “raw”:
    - File: app/Views/frontend/album.twig ({{ album.body|raw }}), app/Views/frontend/
gallery.twig ({{ album.body|raw }}), app/Views/frontend/about.twig (about_text|raw).
    - Stato attuale: TinyMCE client-side limita tag/attributi, ma non è una
sanitizzazione server-side. Se il contenuto proviene dall’admin è “trusted”, ma una
doppia protezione server-side è consigliata.
    - Azione: sanificare lato server (es. whitelist con HTML Purifier) oppure rendere
“raw” solo un sottoinsieme (p, a[href|rel|target], strong/em, ul/ol/li, blockquote,
h2/h3/h4, hr). Mantieni Twig autoescaping ovunque, usa |raw con prudenza.
- DOM XSS in JS (gallerie):
    - File: app/Views/frontend/galleries.twig funzione generateGalleryCard().
    - Rischio: inserisce album.title, album.excerpt, coverImage.alt_text direttamente
in template string senza escape. Anche se i dati provengono dall’admin, è
buona prassi escapare: textContent per testi, costruzione DOM (no innerHTML) o
sanitizzazione.
    - Azione: sostituire concatenazioni HTML con creazione nodi
(document.createElement) e assegnare textContent, oppure sanificare i dati lato
server per quell’API (whitelist).
- CSP permissiva (unsafe-inline):
    - File: app/Middlewares/SecurityHeadersMiddleware.php.
    - Stato: script-src e style-src includono 'unsafe-inline' e CDN — necessario per
gli inline script attuali, ma indebolisce la protezione XSS.
    - Azione: roadmap per migrare script inline a file bundlati (Vite) + CSP con
nonce o senza unsafe-inline; whitelist font/CSS minimi.

Superficie d’attacco: Esito

- SQL Injection: buono
    - Prepared ovunque per input utente (es. AlbumsController, GalleriesController
con placeholders dinamici per IN-clause). Le ->query() dirette guardano dati statici
(count/list) → OK.
- Upload Hardening: buono
    - File: app/Services/UploadService.php
    - Controlli: finfo MIME, magic numbers, limiti dimensioni, lettura dimensioni,
normalizzazione orientamento, path deterministici in /storage/originals (hash),
varianti in /public/media.
    - Azione: aggiungere .htaccess in /public/media per disabilitare esecuzione PHP
(difesa in profondità). Es: php_flag engine off o RemoveHandler .php (Apache); per
Nginx bloccare *.php in quella dir.
- Download sicuri: buono
    - File: app/Controllers/Frontend/DownloadController.php
    - Prevenzione traversal (normalizza, realpath, verifica prefisso storage/),
controllo MIME, filename sanitizzato, header sicuri. OK.
- CSRF: buono
    - File: app/Middlewares/CsrfMiddleware.php
    - POST/PUT/PATCH/DELETE validati via csrf o X-CSRF-Token. Eccezioni solo per
login (gestito in controller) e POST /api/analytics/track (accettabile).
- Autenticazione/Admin: buono
    - File: app/Middlewares/AuthMiddleware.php, app/Controllers/Admin/
AuthController.php
    - session_regenerate_id al login, ruolo admin, is_active, redirect forzati se non
autenticati. Logout POST + CSRF.
    - Rate limiting login: app/Middlewares/RateLimitMiddleware.php (sessione/IP) —
utile, ma fragile (body match “Credenziali non valide”).
    - Password hashing: password_hash(PASSWORD_DEFAULT) in app; CLI usa
PASSWORD_ARGON2ID. Bene.
- Header di sicurezza: discreto
    - HSTS, nosniff, DENY frame, Referrer-Policy, Permissions-Policy, COOP. CSP
presente ma con unsafe-inline.
    - Nota: HSTS sempre attivo; in ambienti senza HTTPS dietro proxy, valutare
condizionale.
- Session cookie: buono
    - HttpOnly, SameSite=Lax, Secure in produzione (APP_DEBUG=false).
- API pubbliche:
    - /api/analytics/track senza CSRF — accetta JSON eventi/pageviews; persistono
stringhe (page_url/title). UI admin usa Twig auto-escape; mitigato. Potresti
aggiungere rate limiting (IP) dedicato qui.

Misconfigurazioni / Hardening

- Disabilita esecuzione PHP/CGI in directory statiche:
    - public/media/, eventuale public/uploads/ → .htaccess/Nginx rules.
- Rimuovere asset superflui e build sources pubblici:
    - Già gestibile con bin/cleanup_leftovers.sh --apply. Mantieni solo i bundle
minificati per PhotoSwipe.
- Consolidare installer:
    - Lascia solo le route Slim (/install), elimina file standalone in public/ e
duplicati in public/assets/.
- Sanitizzazione server-side dei campi HTML:
    - Per album.body, testi “about”, eventuali campi descrizione renderizzati |
raw: applica whitelist server-side in save/update (DRY in un servizio tipo
SanitizerService).
- CSP rafforzata (roadmap):
    - Migrare inline script a bundle Vite; passare a CSP con nonce
(Content-Security-Policy: script-src 'self' 'nonce-...'; style-src 'self'
'nonce-...') e rimuovere unsafe-inline.
- Rate limit robusto:
    - Sposta da sessione a store centralizzato (Redis) e regole per-route/IP con
finestra mobile. Aggiungi al tracking endpoint.
- Validazioni input:
    - Nei controller admin già presenti trim/length check (es. profilo). Estenderli
alle slug, titoli (limite a 200 char), URL SEO (validate URL).

Punti Verificati (OK)

- Prepared statements e binding parametrici anche con filtri multipli (IN
placeholders).
- Hashing password, rigenerazione session, enforcement ruolo admin.
- Download stream con header sicuri e filename ripulito.
- Upload con MIME sniffing + magic number e controllo dimensioni.
- Session cookie sicuri e security headers di base.

Azioni consigliate (in ordine)

- Critico:
    - Rimuovi/Blocca installer pubblici in produzione.
    - Aggiungi .htaccess (o config server) per negare esecuzione in public/media.
    - Sanifica lato server i contenuti che rendi con |raw (album/about).
- Importante:
    - Escapare i contenuti nelle card generate in JS (galleries) con textContent/DOM
API o sanificazione dati JSON.
    - Hardening CSP (roadmap senza unsafe-inline).
    - Rate limit più robusto per /admin/login e /api/analytics/track.
- Manutenzione:
    - Usa il cleanup script per rimuovere asset demo/source e file duplicati.
    - Verifica che tutti i form admin includano il token csrf (già diffuso).

Se vuoi, posso:

- Aggiungere .htaccess in public/media per bloccare PHP.
- Integrare una sanitizzazione server-side per album.body/about_text.
- Rifattorizzare generateGalleryCard() per usare textContent e URL con
window.basePath.