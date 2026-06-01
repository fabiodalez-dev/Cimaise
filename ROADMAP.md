# Cimaise — Roadmap

Self-hosted, nessun servizio esterno. Ogni voce è verificata leggendo il codice reale (niente inferenze).

---

## 🛡️ Audit completo — sicurezza + logica (2026-06-01)

Revisione multi-agente dell'intero codebase: 46 finding confermati in modo
avversariale (23 refutati), corretti in 6 batch prioritari, ognuno testato e
mergiato. Ogni fix è stato verificato end-to-end contro l'ambiente reale
(Apache + MySQL), non solo con il linter.

| PR | Batch | Tema |
|----|-------|------|
| #64 | 1 | Sicurezza: download gating (`allow_downloads`/`is_published`), niente TLS-verify disabilitato, sanitizzazione slug plugin |
| #65 | 2 | Portabilità MySQL 9 (`VALUES()` rimosso → row-alias `AS src`) |
| #66 | — | Race del template switcher (bug riportato: cambio rapido 1004→2) — `AbortController` + token |
| #67 | 3 | Cache warm per tutti i template home + conteggio immagini home corretto |
| #68 | 4 | EXIF/orientamento (transpose/transverse), guardie GPS, limiti Imagick, cleanup tmp upload |
| #69 | 5 | Logica admin/CLI, JSON-LD `urlTemplate` via `json_encode`, rate-limiter senza buffering del body |
| #70 | 6 | Hardening: `Cache-Control: private` su media protetti, anti zip-slip/symlink negli upload, blocco seed per utente demo, lockdown installer |

**Punti salienti hardened** (non rifare — già coperti):
- Media protetti serviti con `Cache-Control: private` (no cache condivise/CDN per album password)
- ZIP plugin/template rifiutati se contengono path assoluti, `..` o symlink (anti zip-slip)
- `installer.php` si auto-disabilita: redirect + regola Apache `403` post-install
- Rate-limiter robusto: rileva l'esito da header interno, non dal testo i18n della pagina; nessun buffering del body sulle route non-login
- JSON-LD (`SearchAction urlTemplate`, ecc.) sempre via `json_encode|raw`
- `published_at` preservato in re-pubblicazione; `seed:demo-albums` chiede conferma prima del wipe
- Parità schema SQLite/MySQL (es. `CHECK(rating 0–5)` su image-rating)

Falsi positivi verificati e **lasciati intatti a ragione**: race del gate nel
rate-limiter (write path già atomico via `flock`), recovery CSRF (codice vivo,
non morto), invalidazione cache Twig globals (già presente), settimana ISO
analytics (già coerente su entrambi i DB), `INNER JOIN categories` (impossibile
orfano grazie a `NOT NULL` + `ON DELETE RESTRICT`), slug TOCTOU (integrità
garantita dal vincolo `UNIQUE`).

---

## ✅ GIÀ PRESENTI — NON rifare (erano falsi gap)
- **Slideshow** album (Swiper) + layout `fullscreen` — `gallery_hero.twig`
- **Lightbox PhotoSwipe**: EXIF/caption, shortcut tastiera (frecce/Esc), zoom/pan — `_gallery_runtime.twig`, `_caption.twig`
- **Filtro gallery** per camera / film / lens (+ iso/aperture) — `FilterSettingsController`
- **OG image** — `_layout.twig`
- **JSON-LD** Breadcrumb + CollectionPage — `_breadcrumbs.twig`, `galleries.twig`
- **Image sitemap** (macro) — `_image_macros.twig`
- **Bulk delete** immagini — `AlbumsController::bulkDeleteImages`
- **Varianti deferite** (scan giornaliero + fastcgi) — `VariantMaintenanceService`
- **LQIP** placeholder — presente
- **Header `immutable`** sulle immagini — presente (visibilità `public`/`private` a seconda dell'accesso)

## 🟡 PARZIALI — base presente, manca un pezzo specifico
- **Deep-link foto**: c'è `history.replaceState` nel runtime → verificare se copre la singola foto
- **JSON-LD per-foto**: manca `ImageObject`/`Photograph` (c'è solo Breadcrumb/CollectionPage)
- **llms.txt**: assente (l'image sitemap invece c'è)
- **Bulk**: manca tag / sposta / reorder multipli (c'è solo delete)
- **Pipeline cache/varianti**: manca `sizes` JS reali e cleanup orfani dedicato (header `immutable` già presente)

## 🔴 GAP REALI — qui si costruisce (priorità)
1. **Ricerca full-text** contenuti (SQLite FTS5 / MySQL FULLTEXT) — assente (c'è solo autocomplete Lensfun per EXIF)
2. **Collezioni curate** cross-album — assente
3. **RSS/Atom** feed — assente
4. **Preload LCP** per home — assente (emerso dall'analisi fluidità) · *rapido*
5. **Preferiti visitatore** (localStorage, no login) — assente
6. **Story / sequenza narrativa** (capitoli foto+testo) — assente
7. **Link condivisione a scadenza** (album password) — assente
8. **Backup/export-import** firmato (Ed25519 via `PluginSignature`) — assente
9. **oEmbed** — assente
10. **Dominant color** placeholder — assente (hai LQIP blur, non la tinta unita)
11. **ImageObject/Photograph JSON-LD per-foto** — completa il SEO esistente
12. **Bulk tag/move/reorder** — estende il bulk-delete esistente
13. **`sizes` JS reali + cleanup orfani** — rifiniture pipeline immagini

## ⚠️ NON è un gap (solo upgrade opzionale)
- **Varianti async event-driven**: la generazione è GIÀ differita (`VariantMaintenanceService`
  giornaliero + `fastcgi_finish_request`). Un job-queue sarebbe solo un *miglioramento*
  (event-driven invece di scan giornaliero), NON una feature mancante.

## Cron
Se si introdurranno nuovi task schedulati (cleanup orfani, ecc.): **UN solo cron** via dispatcher
`scheduler:run` (da creare). Oggi `VariantMaintenanceService` gira via cron/fastcgi.

## Ordine consigliato
1 (ricerca) → 2 (collezioni) → 3 (RSS) → 4 (preload LCP, rapido) → poi gli altri gap reali.

## Note per ogni fase con DB
Doppio schema SQLite+MySQL via helper `Database`; rigenera `template.sqlite`; svuota `query_cache`.
Branch dedicato + test (PHPUnit + e2e Playwright) + build.
