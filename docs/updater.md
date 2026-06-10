# Sistema di aggiornamento (Updater)

Cimaise si aggiorna direttamente dal pannello admin (**Admin → Updates**) scaricando
il pacchetto di release pubblicato su GitHub. Questo documento descrive l'intera
filiera: come nasce una release, come viene verificata, come l'updater la installa
a runtime e quali regole non vanno mai violate.

---

## Architettura

| Componente | Ruolo |
|---|---|
| `app/Support/Updater.php` | Logica core: check, download, verifica integrità, backup, install, migrazioni, rollback |
| `app/Controllers/Admin/UpdateController.php` | Endpoint admin (`/admin/updates/*`) |
| `app/Views/admin/updates.twig` | UI admin |
| `bin/build-release.sh` | Build del pacchetto + verifica profonda dello ZIP |
| `.rsync-filter` | Whitelist dei file che entrano nel pacchetto |
| `.github/workflows/release.yml` | Pipeline di release (tag → build → publish → verifica post-upload) |
| `public/index.php` | Serve il 503 di manutenzione durante l'update |
| `storage/.maintenance` | Flag di manutenzione (JSON con timestamp) |
| `storage/backups/update_*` | Backup database pre-update |
| `storage/tmp/cimaise_update_*` | Estrazione temporanea del pacchetto |
| `storage/tmp/cimaise_app_backup_*` | Backup file applicativi per rollback atomico |

---

## Flusso di release (GitHub Actions)

Gli aggiornamenti **partono da GitHub**: il pacchetto viene costruito in CI, mai a mano.

```
git tag v1.5.0 && git push --tags
        │
        ▼
.github/workflows/release.yml
  1. checkout + PHP 8.1 + Node 18
  2. composer install --no-dev --optimize-autoloader   ← vendor di PRODUZIONE
  3. npm ci && npm run build                           ← asset frontend compilati
  4. verifica version.json == tag
  5. bin/build-release.sh --skip-build
       a. rsync con .rsync-filter (whitelist)
       b. verifica contenuti directory pacchetto
       c. zip + sidecar .sha256 (formato shasum "<hex>  <file>")
       d. verify_zip_package: estrazione + verifica profonda dello ZIP FINALE
  6. creazione GitHub Release con asset:
       cimaise-vX.Y.Z.zip, cimaise-vX.Y.Z.zip.sha256, RELEASE_NOTES-vX.Y.Z.md
  7. VERIFICA POST-UPLOAD (regola assoluta, lezione Pinakes):
       - metadati asset via API: presenza, size, digest sha256
       - ri-download binario via API e confronto sha256 col file locale
       - presenza del sidecar .sha256
       - mismatch ⇒ il workflow FALLISCE rumorosamente
```

Nota: per Cimaise l'uploader degli asset è `github-actions[bot]` — è il
comportamento ATTESO (il pacchetto nasce in CI). In Pinakes lo stesso segnale
indica invece un incidente, perché lì le release si costruiscono in locale.

### Cosa verifica `verify_zip_package` (Step 5.5 adattato da Pinakes)

Sullo ZIP finale, estratto in una dir temporanea:

| Check | Cosa previene |
|---|---|
| `version.json` presente e `.version` == versione release | deploy della versione sbagliata |
| File richiesti: `index.php`, `public/index.php`, `app/Support/Updater.php`, `vendor/autoload.php`, `bin/console`, `database/schema.{sqlite,mysql}.sql`, `database/template.sqlite`, `public/assets/.vite/manifest.json`, `.htaccess`, `public/.htaccess`, `.env.example` | installazione che non parte |
| `vendor/composer/autoload_*` con ZERO riferimenti a phpstan/phpunit | fatal error in produzione (dipendenze dev trapelate) |
| Directory vietate assenti: `tests/`, `.github/`, `node_modules/`, `.git/`, `docs/`, `scripts/`, `vendor/bin/`, `vendor/phpstan/`, `vendor/phpunit/` | file di sviluppo in produzione |
| Nessun `.env`, nessun contenuto in `storage/logs` e `public/media` oltre lo scheletro, nessun `database.sqlite` dev | leak di segreti / dati utente / db di sviluppo |
| **Nessun symlink nello ZIP** | ZipArchive estrae i symlink come file spazzatura da pochi byte (lezione Pinakes) |
| **Nessuna migrazione con versione > versione di release** | migrazioni saltate in silenzio (vedi sotto) |
| Size 8–100 MB (riferimento: v1.4.0 ≈ 13 MB compressi, 38 MB estratti) | pacchetto troncato o gonfiato da leak |

Qualsiasi check fallito ⇒ lo ZIP viene **cancellato** e la build abortisce.

### Contenuto del pacchetto (`.rsync-filter`)

Whitelist esplicita; tutto ciò che non è incluso viene scartato. Path runtime
scoperti via grep e che NON vanno mai esclusi:

- `bin/console` — invocato da CommandsController, CacheController, SettingsController, BlurGenerationJob, InitCommand
- `bin/dev/seed_demo_data.php` — usato dal plugin bundled `demo-mode`
- `resources/keys/` — chiave pubblica per la firma dei plugin (`App\Support\PluginSignature`)
- `storage/lensfun/*.xml` + `storage/cache/lensfun.json` — dati correzione lenti
- `storage/translations/*.json` — seed traduzioni
- `database/template.sqlite` — template dell'installer
- `public/assets/.vite/manifest.json` — risoluzione asset di `ViteTwigExtension`

Esclusi (tra gli altri): sorgenti `resources/{css,js}` (compilati da Vite),
`public/media/*` e `storage/*` (dati utente, solo scheletro), sitemap e favicon
generate per-installazione, `database/database.sqlite`, tutta la toolchain di build.

---

## Flusso di aggiornamento a runtime

`Updater::performUpdate($targetVersion)`:

1. **Lock** (`storage/cache/update.lock`, `flock` non bloccante) — un solo update alla volta
2. **Maintenance mode ON** — scrive `storage/.maintenance` (JSON `{time, message}`)
3. **Backup database** → `storage/backups/update_<timestamp>/database.sql` (SQLite o MySQL)
4. **Download**:
   - la release DEVE avere l'asset `cimaise-*.zip`. **Niente fallback allo zipball**:
     lo zipball è il solo albero git, senza `vendor/` né asset compilati — installarlo
     bricka il sito. Se l'asset manca: `RuntimeException` con messaggio chiaro
   - **verifica integrità obbligatoria** (vedi sotto)
5. **Backup applicativo per rollback** (`storage/tmp/cimaise_app_backup_*`):
   `app/`, `public/assets/`, `vendor/`, `database/migrations/`, `database/schema.*.sql`,
   `version.json`, `index.php`, `.htaccess`, `public/index.php`
6. **Copia file** rispettando i path preservati (vedi tabella) + pulizia orfani in `app/` e `public/assets/`
7. **Migrazioni database** (vedi regole)
8. **Permessi** + `opcache_reset()`
9. **Cleanup** + maintenance mode OFF (anche in caso di errore, via `finally` e shutdown handler)
10. In caso di errore: **rollback automatico** dal backup applicativo

### Regole di integrità (download)

Il pacchetto scaricato deve corrispondere a uno sha256 pubblicato, nell'ordine:

1. campo **`digest`** dell'asset GitHub (`sha256:<hex>`, confronto con `hash_equals`)
2. in assenza del digest, l'asset sidecar **`<asset>.zip.sha256`** (primo token esadecimale da 64 char)
3. se non esiste NESSUNA delle due fonti ⇒ **l'update viene rifiutato** (mai installare non verificato)

La verifica TLS dei certificati è sempre attiva, senza fallback insecure.

### Path preservati durante l'update

| Path | Motivo |
|---|---|
| `.env` | configurazione/segreti dell'installazione |
| `storage/` (catch-all) + voci granulari (`originals`, `backups`, `cache`, `logs`, `tmp`, `translations`) | tutti dati runtime/utente |
| `public/media` | upload utente |
| `public/.htaccess`, `public/robots.txt` | personalizzazioni server |
| `public/favicon*`, `public/apple-touch-icon.png`, `public/android-chrome-*`, `public/icon-*`, `public/site.webmanifest` | icone generate dal logo caricato |
| `public/sitemap.xml`, `public/sitemap_index.xml` | sitemap generate per-installazione |
| `database/database.sqlite`, `-wal`, `-shm` | il database! |
| `CLAUDE.md` | note locali |

Nota sul funzionamento: `copyDirectory()` salta un path preservato **solo se il
target esiste già** — gli scheletri (`.gitkeep`, directory mancanti) del pacchetto
vengono comunque creati su installazioni che ne fossero prive.

### Configurazione via environment

| Variabile | Default | Uso |
|---|---|---|
| `UPDATER_API_BASE` | `https://api.github.com` | base URL API (test/mirror) |
| `UPDATER_REPO` | `fabiodalez-dev/cimaise` | repo `owner/name` |
| `UPDATER_GITHUB_TOKEN` | _(vuoto)_ | bearer token opzionale; su 401/403 ritenta automaticamente senza token |
| `UPDATER_ALLOW_PRERELEASE` | _(off)_ | `1/true/yes/on` abilita il canale RC |
| `UPDATER_CHANNEL` | `stable` | qualsiasi valore ≠ `stable` abilita il canale RC |

---

## Migrazioni database

- Naming: `database/migrations/migrate_<versione>_{sqlite,mysql}.sql` (entrambe le varianti SEMPRE)
- Il runner esegue solo le migrazioni con `fromVersion < v <= toVersion`
  (`version_compare`), in **ordine semantico di versione** (`usort` +
  `version_compare`, non ordine lessicografico: `1.10.0` viene DOPO `1.2.0`)
- Idempotenza: tabella `migrations` (versioni già eseguite saltate) + errori
  ignorabili ("table already exists", "duplicate column")

### ⚠️ La trappola `version_compare` (regola dura da Pinakes)

**Ogni migrazione deve avere versione ≤ versione di release.**

```php
// Release 1.4.9 che contiene migrate_1.5.0_sqlite.sql:
version_compare('1.5.0', '1.4.9', '<=')  // false → migrazione SALTATA IN SILENZIO
```

Nessun errore, nessun warning: semplicemente funzionalità mancanti su tutti gli
upgrade. Se una release ha più migrazioni, vanno fuse in un unico file con la
versione della release. `verify_zip_package` blocca la build se trova una
migrazione con versione maggiore della release.

Caso RC: una prerelease ordina **sotto** la sua versione finale
(`1.5.0-rc.1 < 1.5.0`), quindi una migrazione `migrate_1.5.0_*.sql` NON gira
installando la `1.5.0-rc.1`. Se la migrazione deve girare nell'RC, va versionata
al tag RC (es. `migrate_1.5.0-rc.1_sqlite.sql`).

---

## Maintenance mode

- `performUpdate()` scrive `storage/.maintenance`; `public/index.php` lo controlla
  **prima del bootstrap pesante** (vendor/Twig possono essere a metà sostituzione)
  e risponde **503 + `Retry-After: 120`** con una pagina HTML statica minimale
  (niente Twig, niente DB), `Cache-Control: no-store`, `noindex`
- **Esenzione**: i path `/admin/updates*` non vengono bloccati, così l'admin che
  ha lanciato l'update può completare `POST /admin/updates/perform` e usare
  l'endpoint di emergenza
- **Auto-scadenza 30 minuti**: `Updater::checkStaleMaintenanceMode()` rimuove un
  flag più vecchio di 30 minuti (update crashato ≠ sito bloccato per sempre);
  `public/index.php` ha anche un fallback inline nel caso la classe stessa sia
  inutilizzabile a metà update
- **Sblocco di emergenza**: bottone "Clear maintenance" in Admin → Updates
  (`POST /admin/updates/maintenance/clear`) oppure `rm storage/.maintenance` via SSH

---

## Canale Release Candidate / prerelease (solo sviluppatori)

`GET /releases/latest` di GitHub esclude nativamente draft e prerelease: per
nascondere una RC agli utenti basta pubblicarla come **prerelease** (qualsiasi
versione SemVer con trattino, es. `1.5.0-rc.1`, va taggata `--prerelease`).

Opt-in per singola installazione, **solo via environment** (niente UI, di proposito):

```dotenv
UPDATER_ALLOW_PRERELEASE=1   # 1 / true / yes / on
# — oppure —
UPDATER_CHANNEL=rc           # qualsiasi valore diverso da "stable"
```

Con il canale attivo `getLatestRelease()` scorre la lista release (max 15, più
recente prima) e prende la prima non-draft, prerelease incluse; anche il changelog
(`getAllReleases`) smette di filtrarle. Con il canale spento le prerelease sono
invisibili sia al check sia al changelog. Tutta la filiera di verifica (build,
post-upload, integrità a runtime) è identica: una RC è un pacchetto vero.

Regole di versioning RC:
- formato `X.Y.Z-rc.N`; `version.json` porta la stessa stringa
- `version_compare` ordina correttamente: `1.5.0-rc.1 > 1.4.9` e `1.5.0-rc.1 < 1.5.0`
  (quindi l'upgrade RC → finale viene offerto normalmente)
- una RC non è mai `--latest`; una stabile non riusa mai il numero di una RC

---

## Lezioni da Pinakes (regole dure)

1. **SEMPRE verificare lo ZIP caricato — zero eccezioni.** Una release "creata"
   non è una release verificata: controllare via API presenza asset, size, sha256,
   e ri-scaricare il binario. In Cimaise lo fa il workflow (step post-upload), che
   fallisce rumorosamente su qualsiasi mismatch. Storia: tutte le release Cimaise
   fino alla v1.4.0 risultavano pubblicate ma avevano **zero asset** perché il
   packaging falliva (`.rsync-filter` mancante) e nessuno verificava.
2. **Mai installare lo zipball/l'albero git.** Niente `vendor/` di produzione,
   niente asset compilati ⇒ sito brickato. Per questo il fallback zipball è stato
   rimosso dall'updater.
3. **Mai dipendenze dev nel vendor pubblicato.** Il "disastro PHPStan": autoloader
   con riferimenti a phpstan ma senza `vendor/phpstan/` ⇒ fatal 500 ovunque.
   Doppio guardrail: `composer install --no-dev` in CI + grep sugli
   `autoload_*.php` nello ZIP.
4. **Mai symlink nello ZIP.** ZipArchive li estrae come file di testo spazzatura.
5. **Versione migrazione ≤ versione release**, sempre (vedi sopra).
6. **Mai esportare nulla che non serva in `public/`** e mai contenuto utente nel
   pacchetto (media, sitemap, favicon generate, database).
7. **Il flag di manutenzione deve auto-scadere** e deve esistere una via di
   sblocco d'emergenza.
8. **I check file-presence non catturano i bug runtime** (lezione v0.5.4 Pinakes:
   SQL con placeholder mancante passò tutte le verifiche). Prima di annunciare una
   release importante, fare uno smoke test reale: installazione pulita dallo ZIP
   in un ambiente scratch + login admin + `/admin/plugins`.
