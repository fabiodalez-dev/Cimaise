# Performance Codex — piano di velocizzazione (2026-01-10)

Obiettivo: ridurre TTFB e tempo di interazione della home e delle transizioni pagina (album/gallerie) al minimo, massimizzando cache (Twig/JSON/PHP/browser), parallelizzando il caricamento immagini e rimandando ogni lavoro non critico. Nota: il CMS deve restare compatibile sia con SQLite che MySQL.

## Stato attuale (cosa rallenta)
- Page cache: `PageCacheService` è usato solo dalla home (`app/Controllers/Frontend/PageController.php:213`), ma i cache warmers generano anche `galleries` e `album:*` che non vengono mai letti. Risultato: `/galleries` e `/album/{slug}` sono sempre “cache cold” e ricomputano tutto.
- Auto-warm: `app/Controllers/Admin/AlbumsController.php` chiama `CacheWarmService::warmAlbum()`; assicurarsi che il wrapper richiami `buildAlbumCache()` e che le eccezioni non blocchino il warm.
- Query volume home: `HomeImageService::getInitialImages()` e `getMoreImages()` estraggono sempre `MAX_FETCH_LIMIT` (500 righe) anche quando servono 12–20 immagini; per la home masonry `getAllImages()` senza limite carica *tutte* le immagini e tutte le varianti, poi le duplica nel DOM. È il principale motivo di lentezza iniziale.
- JS costo fisso: `resources/js/smooth-scroll.js` inizializza Lenis su ogni pagina con MutationObserver, recalc ogni 500ms per 10s e RAF permanente; `home-gallery.js` + `home.js` partono subito invece di aspettare idle/visibilità; il carousel (`albums-carousel.js`) gira via `requestAnimationFrame` continuo. Su macchine lente/ mobile il main thread è occupato appena dopo il first paint.
- Cache HTTP: `CacheMiddleware` genera ETag hashando il body fino a 256KB se manca il digest dal page cache; senza page cache su galleries/album il hashing pesa su ogni richiesta. Statici hanno cache 1y ma senza filename versioning (Vite `manifest:false`, nomi stabili) → rischio hard-refresh necessario dopo deploy.
- Pipeline immagini: le query alle varianti sono batched ma, con dataset grandi, l’IN su `image_variants` per centinaia/migliaia di id peggiora TTFB; nessun cap sul numero di immagini/varianti iniettate in home masonry → DOM enorme.

## P0 (bloccanti) — prima ondata
1) **Page cache end-to-end**
   - File: `app/Controllers/Frontend/GalleriesController.php`, `app/Controllers/Frontend/PageController.php` (metodo `album`), `app/Services/PageCacheService.php`.
   - Azione: usare `PageCacheService` come per la home: cache hit → render immediato; cache stale → serve stale e avvia rigenerazione async; miss → genera e salva con `setWithTags`. Reimpiegare i payload già costruiti da `CacheWarmService` (stesso shape).
   - Risultato: `/galleries` e `/album/{slug}` tornano a TTFB basso e ETag veloci (hash dal DB, niente hashing del body).

2) **Fix warm automatico**
   - File: `app/Controllers/Admin/AlbumsController.php`, `app/Services/CacheWarmService.php`.
   - Azione: sostituire `warmAlbum` con `buildAlbumCache` o aggiungere un wrapper `warmAlbum(string $slug)` che richiama `buildAlbumCache`. Garantire che `cache.auto_warm` richiami anche `buildHomeCache()/buildGalleriesCache()` dopo update.
   - Risultato: dopo edit/publish gli utenti anonimi ricevono cache calde; meno rigenerazioni on-request.

3) **Ridurre query e payload iniziali home**
   - File: `app/Services/HomeImageService.php`, `app/Controllers/Frontend/PageController.php`, `app/Services/CacheWarmService.php`.
   - Azioni:
     - Limitare il SELECT iniziale a `min(limit*3, 120)` anziché 500 per `getInitialImages`/`getMoreImages` (manteniamo diversità album ma tagliamo IO e memoria).
     - Per masonry: imporre limite hard (es. 120–160 immagini) e attivare progressive load via `/api/home/gallery?mode=masonry`, duplicando client-side solo se serve loop visivo. Non caricare tutte le varianti: prendere solo largest jpg + 2 taglie webp/avif.
     - In `processImageSourcesBatch` filtrare varianti non necessarie (escludere blur, limitare per formato a 3 taglie) quando la pagina è “home-masonry”.
   - Risultato: meno query, meno dati serializzati in cache, DOM più piccolo → first paint rapido.

4) **Gating JavaScript costoso**
   - File: `resources/js/smooth-scroll.js`, `resources/js/home.js`, `resources/js/home-gallery.js`, `resources/js/albums-carousel.js`.
   - Azioni:
     - Inizializzare Lenis solo se `prefers-reduced-motion` è off, pagina non admin, e il body ha attributo di opt-in (es. `data-smooth-scroll="1"`). Spostare init in `requestIdleCallback` o `setTimeout(0)` post first paint; rimuovere recalc interval di 10s o portarlo a 2s con max 3 tick.
     - In `home.js` e `home-gallery.js`, avviare animazioni/observer solo quando la sezione è nel viewport (IntersectionObserver) e split-chunk Lenis/GSAP con import dinamico.
     - Per il carousel, mettere autoplay dietro `requestIdleCallback` e sospenderlo quando la scheda è in background (`visibilitychange`).
   - Risultato: main thread libero durante il first load; interazioni immediate.

5) **Versioning asset per cache lunga**
   - File: `vite.config.js`, `app/Views/frontend/_layout.twig` (include CSS/JS), `public/index.php` (preload).
   - Azione: attivare manifest e filename hashed (`entryFileNames: 'js/[name]-[hash].js'`, `assetFileNames: '[name]-[hash][extname]'`) + `link rel=preload`/`modulepreload` usando il manifest. Senza hash la cache 1y richiede hard-refresh.
   - Risultato: si può usare `Cache-Control: immutable` senza rischio di asset vecchi; migliore cache hit rate su navigazioni successive.

## P1 (seconda ondata)
1) **Ottimizzare generazione varianti e lettura**
   - File: `app/Services/CacheWarmService.php`, `app/Controllers/Frontend/PageController.php`, `app/Controllers/Frontend/GalleriesController.php`.
   - Azione: memorizzare nei payload cache solo le varianti necessarie (lg/md/sm per webp/avif/jpg) + `fallback_src`; evitare di serializzare colonne non usate (es. `exif`, `caption` se non mostrato). Per album/galleries, valutare caching server-side di `<picture>` renderizzato (snapshot Twig) per le cover.

2) **Query cache e lookup**
   - File: `app/Controllers/Frontend/GalleriesController.php` (`getFilterOptions`), `app/Services/NavigationService.php`.
   - Azione: usare `QueryCache`/APCu per opzioni filtro e categorie (già presente ma verificare TTL=300s); fallback file cache va invalidato con tag `cache_tags` quando cambiano tassonomie.

3) **HTTP cache & compressione**
   - File: `app/Middlewares/CacheMiddleware.php`, `app/Middlewares/CompressionMiddleware.php`.
   - Azione: con page cache attivo, ETag deve provenire da `PageCacheService::getHash()` (già previsto) → evitare hashing del body. Forzare `performance.compression_enabled=true` e preferire brotli se disponibile. Per HTML, considerare `stale-while-revalidate` via header aggiuntivo.

4) **SQLite/MySQL tuning**
   - SQLite: già in WAL; aggiungere `PRAGMA synchronous=NORMAL`, `PRAGMA temp_store=MEMORY`, `PRAGMA cache_size=-8000` in `App\Support\Database` per ridurre lock e IO.
   - MySQL: assicurare indici `albums(is_published, published_at, sort_order)`, `images(album_id, sort_order, id)`, `image_variants(image_id, width)`, `cache_tags(tag)`. Valutare `innodb_buffer_pool_size` adeguato e `query_cache` disabilitata (usiamo lato app).

5) **Navigazione e bfcache**
   - File: `app/Views/frontend/_layout.twig`, `app/Views/frontend/_gallery_runtime.twig`.
   - Azione: assicurare che i listener JS usino `pageshow`/`pagehide` correttamente per non bloccare bfcache; smontare Lenis/GSAP su `pagehide` e riattivarli lazy su `pageshow`.

## Checklist cache esistenti (da verificare)
- Twig cache: `storage/cache/twig` attiva, `auto_reload` disattivata in prod. Verificare permessi e spazio.
- Twig globals: `App\Services\TwigGlobalsCache` (APCu/file, TTL 300s) riduce le 50 query di settings → assicurarsi che `apcu.enabled=1` in PHP-FPM.
- Query cache: `storage/tmp/query_cache` fallback quando APCu manca; svuotabile da /admin/cache (tipo “query”).
- Page cache: preferire backend `database` (più veloce, compressione gzip) con `cache.pages_ttl` ≥ 3600. Tabelle: `page_cache`, `cache_tags` con indici in `database/schema.mysql.sql` e `database/schema.sqlite.sql`.
- JSON/file cache: opzioni filtro gallerie (`storage/cache/filter_options.cache`, TTL 300s) → invalidare quando cambiano categorie/tag.
- Browser cache: `CacheMiddleware` imposta statici 1y, media 1d, HTML 5m; dopo versioning asset si può spingere HTML a 15m con ETag.

## Test e validazione
- Profilare TTFB con `DEBUG_PERFORMANCE=1` e `DEBUG_SQL=1` (appende a logger). Target: home <80ms cached, <250ms cold con 100 album/1000 immagini.
- Contare query home/galleries: dopo il cap delle immagini aspettarsi <8 query (settings, nav, albums, images+variants). Se >12, investigare.
- Lighthouse: Performance ≥95, CLS <0.02, LCP <1.8s (desktop) con rete “Fast 3G”.
- Verificare bfcache (Chrome DevTools > Application > Back/Forward Cache) non invalidato da event listeners persistenti.

## Priorità implementazione
1. Wiring page cache + fix warm album (P0).
2. Riduzione query/payload home (P0).
3. Gating JS costoso + idle start (P0/P1).
4. Asset versioning + preload via manifest (P0/P1).
5. Tuning DB + cache invalidation/tagging (P1).

Applicando i punti P0 si dovrebbe vedere un miglioramento immediato di: TTFB -60/70% su home/gallerie, riduzione di ~10–20MB di payload iniziale per home masonry e thread principale libero nei primi 1–2 secondi.
