# Guida Comandi CLI - photoCMS

## Comandi Disponibili

### 1. Database

#### Migrazioni
```bash
# Esegui tutte le migrazioni pending
php bin/console migrate

# Mostra lo stato delle migrazioni
php bin/console migrate --status
```

#### Seed (Dati di esempio)
```bash
# Popola il database con dati di esempio
php bin/console seed

# Seed specifico
php bin/console seed --file=0001_minimal.sql
```

#### Test connessione DB
```bash
# Verifica la connessione al database
php bin/console db:test
```

### 2. Utenti

#### Crea admin
```bash
# Crea un nuovo utente admin
php bin/console user:create admin@example.com

# Con password specifica
php bin/console user:create admin@example.com --password=mypassword
```

#### Aggiorna utente
```bash
# Cambia password di un utente
php bin/console user:update admin@example.com --password=newpassword
```

### 3. Immagini

#### Genera varianti
```bash
# Genera tutte le varianti mancanti per tutte le immagini
php bin/console images:generate --missing

# Rigenera forzatamente tutte le varianti
php bin/console images:generate --force

# Genera per un album specifico
php bin/console images:generate --album-id=123
```

#### Import immagini (futuro)
```bash
# Importa immagini da una cartella
php bin/console images:import /path/to/images --album="Nome Album"
```

### 4. SEO e Sitemap

#### Genera sitemap
```bash
# Genera sitemap.xml e robots.txt
php bin/console sitemap:build --base-url=https://tuodominio.com

# Solo sitemap
php bin/console sitemap:build --base-url=https://tuodominio.com --no-robots
```

### 5. Diagnostica

#### System check
```bash
# Verifica stato del sistema
php bin/console diagnostics

# Verifica con output dettagliato
php bin/console diagnostics --verbose
```

### 6. Cache e Pulizia

#### Pulisci cache (futuro)
```bash
# Pulisci cache delle immagini
php bin/console cache:clear --images

# Pulisci cache completa
php bin/console cache:clear --all
```

#### Pulizia storage
```bash
# Rimuovi file temporanei vecchi
php bin/console cleanup:temp --days=7

# Rimuovi varianti orfane
php bin/console cleanup:variants
```

## Esempi di Workflow

### Setup Iniziale
```bash
# 1. Migrazioni
php bin/console migrate

# 2. Seed dati base
php bin/console seed

# 3. Crea admin
php bin/console user:create admin@tuodominio.com

# 4. Genera sitemap
php bin/console sitemap:build --base-url=https://tuodominio.com

# 5. Verifica sistema
php bin/console diagnostics
```

### Manutenzione Quotidiana
```bash
# Genera varianti mancanti
php bin/console images:generate --missing

# Aggiorna sitemap
php bin/console sitemap:build --base-url=https://tuodominio.com

# Pulizia
php bin/console cleanup:temp --days=3
```

### Deployment
```bash
# 1. Migrazioni
php bin/console migrate

# 2. Genera tutte le varianti
php bin/console images:generate --missing

# 3. Sitemap
php bin/console sitemap:build --base-url=https://tuodominio.com

# 4. Verifica
php bin/console diagnostics
```

## Parametri Comuni

- `--verbose` o `-v`: Output dettagliato
- `--quiet` o `-q`: Nessun output
- `--force`: Forza l'esecuzione anche se ci sono warning
- `--dry-run`: Simula l'operazione senza eseguirla

## Automazione con Cron

Aggiungi al crontab per automazione:

```cron
# Genera varianti ogni ora
0 * * * * cd /path/to/photocms && php bin/console images:generate --missing

# Aggiorna sitemap ogni giorno alle 2:00
0 2 * * * cd /path/to/photocms && php bin/console sitemap:build --base-url=https://tuodominio.com

# Pulizia settimanale
0 3 * * 0 cd /path/to/photocms && php bin/console cleanup:temp --days=7
```