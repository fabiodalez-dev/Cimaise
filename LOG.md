# Changelog e Istruzioni di Test (photoCMS)

Data: 2025-08-21 (UTC)

## Cosa è stato implementato
- AGENTS.md con linee guida contributori.
- Bootstrap ambiente: `.env.example`, `.env`, `composer.json`, CLI (`bin/console`), migrazioni DB core (0001–0003), lookup e immagini, seed minimi.
- Servizi DB/console: `Database`, comandi `db:test`, `db:migrate`, `db:seed`, `user:create`.
- Backend Slim 4 + Twig: routing base, login/logout admin, dashboard, layout Bootstrap (navbar + sidebar), pagine CRUD (albums, categories, tags) con CSRF, Auth e flash.
- Sicurezza: `CsrfMiddleware`, `AuthMiddleware`, `FlashMiddleware` (espone `csrf`), pagine errori Twig (404/500).
- Gestione Albums:
  - Tag multipli con Tom Select (ricerca), sync tabella `album_tag`.
  - Griglia immagini con SortableJS (riordino via AJAX) e azione “Cover”.
  - Protezione: verifica appartenenza immagine → album per cover/reorder.
  - Listing con mini-thumb cover (variant `sm` se disponibile, fallback originale).
- Upload immagini (Uppy): endpoint `/admin/albums/{id}/upload`, `UploadService` con validazione MIME, salvataggio in `storage/originals/` e generazione preview JPG 480px in `public/media/` + record `image_variants`.
- Settings: tabella `settings`, `SettingsService`, pagina `/admin/settings` per formati (AVIF/WebP/JPEG), qualità, breakpoints JSON e preview width.
- API admin: `/admin/api/tags?q=` per autocompletamento asincrono.

## Come testare
1) Dipendenze: `composer install`.
2) DB: `php bin/console db:migrate && php bin/console db:seed`.
3) Utente: `php bin/console user:create admin@example.com`.
4) Avvio: `php -S 127.0.0.1:8000 -t public`.
5) Login: `http://127.0.0.1:8000/admin/login`.
6) Categorie/Tag: crea da sidebar, verifica CRUD e flash.
7) Albums:
   - Crea album (scegli categoria, tag multipli).
   - Modifica album: usa pulsante “Carica immagini…” (Uppy) per upload; trascina per riordinare; imposta “Cover”.
   - Verifica che l’ordine persista e che la cover appaia nel listing.
8) Settings: `http://127.0.0.1:8000/admin/settings` → modifica formati/qualità/breakpoints; salva e verifica flash.

Note: Generazione immagini usa GD e produce solo preview JPG 480px (estensioni accettate: JPG/PNG). Pipeline AVIF/WebP e varianti complete verranno estese in seguito.
