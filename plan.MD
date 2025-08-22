# Piano di Lavoro — photoCMS

## Backlog (Prossime attività)
- [ ] CRUD Lookup: Cameras, Lenses, Films, Developers, Labs (liste + create/edit/delete + validazioni)
- [ ] ExifService: mapping avanzato (fuzzy) e normalizzazione orientamento
- [ ] API Frontend: `GET /api/albums` (cards HTML + pagination) e `GET /api/album/{id}/images`
- [ ] Pagine SSR Frontend: Home, Album, Categoria, Tag con `<picture>` e varianti
- [ ] Sitemap: CLI `sitemap:build` + `robots.txt` + ETag/Last‑Modified
- [ ] Security hardening: rate limiting robusto su login (IP + slowdowns), CSP affinata
- [ ] Admin: flash component e messaggi campo‑specifici (validazioni server)
- [ ] Diagnostics view (pagina admin) con stato estensioni/permessi

## Doing
- [ ] N/D

## Done (recenti)
- [x] Upload immagini (Uppy CDN) con anteprima live
- [x] Riordino immagini (SortableJS) + set Cover (AJAX)
- [x] Varianti immagini: CLI `images:generate` (Imagick/GD) e settings
- [x] Tag album: multi‑select async + salvataggio live
- [x] Security headers middleware + rate limit login
- [x] Settings pagina con formati/qualità/breakpoints
- [x] Root routing fix: redirect a `/admin/login` e bootstrap da root

## Log
- 2025-08-21 19:45 UTC — Aggiunti Upload/Varianti/Tag async/Settings/Redirect root/Headers/RateLimit

