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

```text
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
  (job build-release: nessun segreto, token read-only)
        │  artifact "unsigned-release"
        ▼
  6. job sign-release (runner pulito, environment `release-signing`):
       - NIENTE composer/npm: solo ext-sodium + bin/release-sign.php
       - sha256sum -c sul sidecar
       - firma Ed25519 del pacchetto → cimaise-vX.Y.Z.zip.sig (dominio "package")
       - firma delle eventuali patch → <patch>.php.sig (dominio "patch")
       - la firma appena fatta deve verificare con la chiave pubblica COMMITTATA
       - PLUGIN_SIGNING_KEY assente ⇒ il job FALLISCE (mai release non firmate)
        │  artifact "release-signatures"
        ▼
  7. job publish-release (unico con contents: write):
       - verifica firma pacchetto contro resources/keys/plugin-signing.pub
       - creazione GitHub Release con asset:
           cimaise-vX.Y.Z.zip, .zip.sha256, .zip.sig, RELEASE_NOTES-vX.Y.Z.md
  8. VERIFICA POST-UPLOAD (regola assoluta, lezione Pinakes):
       - metadati asset via API: presenza, size, digest sha256
       - ri-download binario via API e confronto sha256 col file locale
       - presenza del sidecar .sha256
       - presenza del .sig e verifica della firma PUBBLICATA sul binario RI-SCARICATO
       - mismatch ⇒ il workflow FALLISCE rumorosamente
```

Nota: per Cimaise l'uploader degli asset è `github-actions[bot]` — è il
comportamento ATTESO (il pacchetto nasce in CI). In Pinakes lo stesso segnale
indica invece un incidente, perché lì le release si costruiscono in locale.

### ⚠️ Ogni release aggiorna ANCHE l'immagine Docker

Lo **stesso tag `vX.Y.Z`** che lancia `release.yml` fa partire in parallelo
anche [`.github/workflows/docker-publish.yml`](../.github/workflows/docker-publish.yml),
che ricostruisce e pubblica l'**immagine Docker multi-arch** (amd64 + arm64) su
Docker Hub e GHCR con i tag `X.Y.Z`, `X.Y` e `latest`. Quindi:

- **A ogni modifica/release dell'app, l'immagine Docker si aggiorna da sola** —
  non serve nessun push manuale dell'immagine. Basta taggare la release.
- **Se la modifica tocca l'ambiente di esecuzione** (una nuova estensione PHP,
  un nuovo step di build front-end, una nuova cartella scrivibile a runtime,
  un bump della versione PHP minima), **aggiorna anche il [`Dockerfile`](../Dockerfile)
  e [`docker/`](../docker)** nello stesso commit, altrimenti l'immagine resterà
  indietro rispetto al codice. La guida completa è in [`DOCKER.md`](../DOCKER.md).
- Gli utenti Docker **non** usano l'updater a runtime (vedi sotto): aggiornano
  con `docker pull` del nuovo tag. Le due strade di aggiornamento sono distinte
  ma partono dallo stesso tag git.

#### Collegamenti tra repository e registry (cross-link)

| Risorsa | URL |
|---|---|
| Repository sorgente (GitHub) | <https://github.com/fabiodalez-dev/Cimaise> |
| Immagine Docker Hub | <https://hub.docker.com/r/fabiodalez/cimaise> |
| Immagine GHCR | <https://github.com/fabiodalez-dev/Cimaise/pkgs/container/cimaise> |
| Guida Docker | [`DOCKER.md`](../DOCKER.md) |
| Workflow pubblicazione immagine | [`.github/workflows/docker-publish.yml`](../.github/workflows/docker-publish.yml) |

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
   - **verifica integrità obbligatoria** (digest) e **firma dell'editore** (vedi sotto)
5. **Backup applicativo per rollback** (`storage/tmp/cimaise_app_backup_*`):
   `app/`, `public/assets/`, `vendor/`, `database/migrations/`, `database/schema.*.sql`,
   `version.json`, `index.php`, `.htaccess`, `public/index.php`, `bin/console`
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

Il digest però arriva **dalla stessa release** dell'archivio: chi può sostituire un asset della release può sostituire anche digest e sidecar. Per questo il digest rileva corruzioni, ma non autentica l'editore. L'autenticazione è la firma (sezione seguente).

### Firma del pacchetto (publisher signature)

Dopo il controllo del digest, e **prima** di scrivere o estrarre qualsiasi cosa, l'updater scarica `cimaise-vX.Y.Z.zip.sig` e verifica una firma Ed25519 fatta con la chiave offline del progetto, contro la chiave pubblica **spedita con l'installazione** (`resources/keys/plugin-signing.pub`, o l'override `PLUGIN_SIGNING_PUBKEY`). Il messaggio firmato è legato a versione e hash del pacchetto:

```text
"cimaise-signature-v1" \0 "package" \0 <versione senza v> \0 <sha256 hex dello zip>
```

Così una firma valida per la 1.5.0 non vale per la 1.5.1, e una firma di plugin o di patch (vedi "Separazione dei domini") non vale mai come firma di pacchetto. Firma assente, illeggibile o non valida ⇒ `RuntimeException`, update annullato, niente estratto.

#### Transizione (staged rollout)

Le release già pubblicate (fino alla 1.4.29) non hanno `.sig`. La regola sta in `Updater::PACKAGE_SIGNATURE_REQUIRED_FROM` (oggi `1.4.30`, deve coincidere con la prima versione taggata con il nuovo `release.yml`):

| Installata | Target | Comportamento |
|---|---|---|
| qualsiasi | ≥ 1.4.30 (anche `1.4.30-rc.N`) | firma **obbligatoria** |
| ≥ 1.4.30 | qualsiasi, anche < 1.4.30 | firma **obbligatoria** (ratchet: un'installazione che verifica le firme non torna mai al solo digest, quindi un downgrade verso una vecchia release con asset ri-caricato viene rifiutato) |
| < 1.4.30 | < 1.4.30 | percorso legacy: solo digest, con WARNING nel log (succede solo in alberi dev/test) |

Se una release pubblica un `.sig`, la firma viene verificata **sempre** quando c'è una chiave pubblica, anche sul percorso legacy: una firma non valida non viene mai ignorata.

Limite intrinseco: il controllo lo esegue il codice **già installato**. Un'installazione 1.4.29 che si aggiorna alla 1.4.30 usa ancora l'updater della 1.4.29 (solo digest); dalla 1.4.30 in poi ogni update successivo è verificato.

#### Separazione dei domini

La stessa chiave firma tre cose diverse; nessuna firma viene mai fatta sui byte grezzi, ma su un messaggio con lo scopo dentro (`App\Support\PluginSignature`):

| Scopo | Campi firmati | Chi firma | Chi verifica |
|---|---|---|---|
| `plugin` | sha256 dello zip | `plugin:sign` (default), `sign-plugin.yml` | `PluginsController::upload` |
| `patch` | nome asset, versione, sha256 | job `sign-release` | `Updater::fetchSignedReleaseAsset` |
| `package` | versione, sha256 | job `sign-release` | `Updater::downloadUpdate` |

Le primitive raw (`sign`/`verify` su byte arbitrari) sono private: non esiste più un modo di verificare una firma non incorniciata. `sign-plugin.yml` esclude esplicitamente `cimaise-v*.zip` (lo firma `release.yml` nel dominio `package`; ri-firmarlo con `--clobber` sovrascriverebbe il `.sig` e romperebbe gli update).

Comandi:

```bash
php bin/console plugin:sign plugin.zip
```

```bash
php bin/console plugin:sign cimaise-v1.5.0.zip --type package --release 1.5.0
```

```bash
php bin/console plugin:sign release-patches/post-install-patch.php --type patch --release 1.5.0
```

In CI si usa `bin/release-sign.php` (stessi messaggi, zero dipendenze Composer, chiave solo da `PLUGIN_SIGNING_KEY`), non incluso nel pacchetto.

#### Configurazione lato GitHub (a cura del maintainer)

- Creare l'environment **`release-signing`** (Settings → Environments), spostarci il segreto `PLUGIN_SIGNING_KEY`, limitarlo ai tag `v*` e, se si vuole, aggiungere reviewer obbligatori. Finché l'environment non ha regole, il job usa il segreto di repository e chiunque possa pushare un tag `v*` fa firmare quello che ha taggato.
- Tenere i permessi di scrittura sugli asset delle release ai soli maintainer: `sign-plugin.yml` firma (nel dominio `plugin`) ogni `*.zip` caricato su una release.
- Opzionale: chiavi distinte per plugin e pacchetti. Con la separazione dei domini non è necessario per la sicurezza crittografica, ma limita l'impatto se una delle due chiavi viene esposta.

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
| `UPDATER_GITHUB_TOKEN` | _(vuoto)_ | bearer token opzionale; inviato SOLO alle richieste verso l'host API (i download degli asset/sidecar vanno anonimi: i redirect verso i CDN leakerebbero il token); su 401/403 ritenta automaticamente senza token |
| `UPDATER_ALLOW_PRERELEASE` | _(off)_ | `1/true/yes/on` abilita il canale RC |
| `UPDATER_CHANNEL` | `stable` | qualsiasi valore ≠ `stable` abilita il canale RC |
| `PLUGIN_SIGNING_PUBKEY` | _(vuoto → file `resources/keys/plugin-signing.pub`)_ | chiave pubblica Ed25519 (base64) per firme di plugin, patch e pacchetto; un valore non valido disabilita la verifica, e allora gli update che richiedono la firma vengono rifiutati |

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
  **prima di `require vendor/autoload.php`** (durante l'update `vendor/` è a metà
  sostituzione: una richiesta concorrente non deve mai toccare l'autoloader) con
  codice self-contained senza dipendenze, e risponde **503 + `Retry-After: 120`**
  con una pagina HTML statica minimale (niente Twig, niente DB),
  `Cache-Control: no-store`, `X-Robots-Tag: noindex`
- **Esenzione**: i path `/admin/updates*` non vengono bloccati, così l'admin che
  ha lanciato l'update può completare `POST /admin/updates/perform` e usare
  l'endpoint di emergenza
- **Auto-scadenza 30 minuti**: un flag più vecchio di 30 minuti viene rimosso
  (update crashato ≠ sito bloccato per sempre). `public/index.php` replica inline
  la semantica di `Updater::checkStaleMaintenanceMode()` senza caricare la classe
  (che potrebbe essere a metà sostituzione)
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
