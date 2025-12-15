# Plugin Management Guide - Cimaise

Guida completa alla gestione dei plugin in Cimaise

## 📋 Indice
- [Introduzione](#introduzione)
- [Interfaccia Admin](#interfaccia-admin)
- [Gestione Plugin](#gestione-plugin)
- [Sviluppo Plugin](#sviluppo-plugin)
- [Plugin Inclusi](#plugin-inclusi)

## Introduzione

Cimaise include un sistema completo di gestione plugin che permette di estendere le funzionalità dell'applicazione in modo modulare e sicuro.

### Caratteristiche Principali

✅ **Gestione Completa** - Install, Uninstall, Activate, Deactivate
✅ **Database Persistence** - Stato plugin salvato nel database
✅ **UI Admin Integrata** - Interfaccia grafica per gestione plugin
✅ **60 Hooks Disponibili** - Per estendere ogni aspetto dell'app
✅ **Auto-loading** - Caricamento automatico plugin attivi
✅ **Isolamento Errori** - Gli errori dei plugin non crashano l'app
✅ **Metadata Support** - Nome, versione, autore, descrizione

## Interfaccia Admin

### Accesso alla Pagina Plugin

1. Login in Admin (`/admin`)
2. Click su **Plugin** nella sidebar (sezione SYSTEM)
3. Visualizza tutti i plugin disponibili

### Pagina Plugin

La pagina plugin mostra:

- **Lista Plugin Disponibili** - Tutti i plugin nella directory `plugins/`
- **Stato Plugin** - Non Installato, Disattivato, Attivo
- **Informazioni Plugin** - Nome, versione, autore, descrizione
- **Statistiche Sistema** - Hooks registrati, callbacks totali, cache entries
- **Azioni Disponibili** - Install, Uninstall, Activate, Deactivate

### Stati Plugin

#### Non Installato (Grigio)
- Plugin presente nella directory ma non installato
- **Azione disponibile**: Installa

#### Disattivato (Giallo)
- Plugin installato ma non attivo
- Non viene caricato all'avvio
- **Azioni disponibili**: Attiva, Disinstalla

#### Attivo (Verde)
- Plugin installato e attivo
- Caricato ad ogni avvio
- Hooks registrati ed eseguiti
- **Azioni disponibili**: Disattiva, Disinstalla

## Gestione Plugin

### Installare un Plugin

1. Vai in **Admin > Plugin**
2. Trova il plugin nella lista
3. Click su **Installa**
4. Il sistema:
   - Esegue `install.php` se presente
   - Crea record nel database
   - Imposta stato: installato e attivo

### Disinstallare un Plugin

1. Vai in **Admin > Plugin**
2. Find il plugin (deve essere disattivato prima)
3. Click su **Disattiva** (se attivo)
4. Click su **Disinstalla**
5. Conferma l'azione
6. Il sistema:
   - Esegue `uninstall.php` se presente
   - Rimuove record dal database
   - ⚠️ **Attenzione**: Azione irreversibile!

### Attivare/Disattivare un Plugin

**Attivare**:
1. Plugin deve essere installato
2. Click su **Attiva**
3. Plugin verrà caricato al prossimo refresh

**Disattivare**:
1. Plugin deve essere attivo
2. Click su **Disattiva**
3. Plugin non verrà più caricato

## Sviluppo Plugin

### Struttura Base Plugin

```
plugins/
  └── my-plugin/
      ├── plugin.php          # File principale (obbligatorio)
      ├── README.md           # Documentazione
      ├── install.php         # Script installazione (opzionale)
      ├── uninstall.php       # Script disinstallazione (opzionale)
      └── src/                # Classi PHP (opzionale)
          └── MyClass.php
```

### File `plugin.php`

```php
<?php
/**
 * Plugin Name: My Awesome Plugin
 * Version: 1.0.0
 * Description: Descrizione del plugin
 * Author: Your Name
 */

use App\Support\Hooks;

class MyAwesomePlugin
{
    public function __construct()
    {
        // Registra hooks
        Hooks::addAction('cimaise_init', [$this, 'init'], 10, 'my-plugin');
        Hooks::addFilter('admin_menu_items', [$this, 'addMenuItem'], 10, 'my-plugin');
    }

    public function init($db, $pluginManager)
    {
        // Inizializzazione plugin
    }

    public function addMenuItem(array $items): array
    {
        $items[] = [
            'title' => 'My Plugin',
            'url' => '/admin/my-plugin',
            'icon' => '🔌'
        ];
        return $items;
    }
}

new MyAwesomePlugin();
```

### Script Installazione/Disinstallazione

**install.php**:
```php
<?php
// Eseguito quando il plugin viene installato
$db = \App\Support\Database::getInstance();

// Crea tabelle
$db->pdo()->exec("
    CREATE TABLE IF NOT EXISTS my_plugin_table (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        data TEXT
    )
");

echo "Plugin installed successfully!\n";
```

**uninstall.php**:
```php
<?php
// Eseguito quando il plugin viene disinstallato
$db = \App\Support\Database::getInstance();

// Elimina tabelle
$db->pdo()->exec("DROP TABLE IF EXISTS my_plugin_table");

echo "Plugin uninstalled successfully!\n";
```

## Plugin Inclusi

### 1. Hello Cimaise (Basic Example)
Plugin dimostrativo base che mostra:
- Registrazione hooks
- Aggiunta menu admin
- Settings tab
- Log eventi

**File**: `plugins/hello-cimaise/`

### 2. Analytics Logger (Advanced Example)
Plugin avanzato per analytics con:
- Tabella database custom
- Event tracking
- Dashboard widget
- Device fingerprinting

**File**: `plugins/analytics-logger/`

### 3. Image Rating (Production Example)
Sistema rating 5 stelle completo:
- Service class separata
- CRUD operations completo
- Admin UI integration
- Bulk operations
- Statistiche

**File**: `plugins/image-rating/`

### 4. Cimaise Analytics Pro (Professional)
Sistema analytics professionale completo con:
- Tracking avanzato eventi
- Dashboard interattiva
- Real-time monitoring
- Funnel analysis
- Device & Browser analytics
- Export CSV
- User journey tracking
- Session management

**File**: `plugins/cimaise-analytics-pro/`

**Caratteristiche**:
- 📊 Dashboard real-time
- 📈 Report personalizzabili
- 🔍 Funnel analysis
- 📱 Device/Browser detection
- 💾 Export dati CSV
- 🎯 Custom events tracking
- 📉 Session analytics
- 🗺️ User journey mapping

**Vedi**: `plugins/cimaise-analytics-pro/README.md` per documentazione completa

## API Plugin Manager

### Metodi Disponibili

```php
use App\Support\PluginManager;

$pm = PluginManager::getInstance();

// Get all available plugins
$plugins = $pm->getAllAvailablePlugins('/path/to/plugins');

// Install plugin
$result = $pm->installPlugin('plugin-slug', '/path/to/plugins');

// Uninstall plugin
$result = $pm->uninstallPlugin('plugin-slug', '/path/to/plugins');

// Activate plugin
$result = $pm->activatePlugin('plugin-slug');

// Deactivate plugin
$result = $pm->deactivatePlugin('plugin-slug');

// Check if plugin is active
$isActive = $pm->isPluginActive('plugin-slug');

// Get plugin status
$status = $pm->getPluginStatus('plugin-slug');

// Get statistics
$stats = $pm->getStats();
```

### Database Table: `plugin_status`

```sql
CREATE TABLE plugin_status (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    version TEXT NOT NULL,
    description TEXT,
    author TEXT,
    path TEXT NOT NULL,
    is_active INTEGER DEFAULT 1,
    is_installed INTEGER DEFAULT 1,
    installed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

## Hooks System

### 60 Hooks Disponibili

Il sistema plugin offre 60 hooks organizzati in 9 categorie:

1. **User Management** (8 hooks)
2. **Album Management** (10 hooks)
3. **Image Processing** (8 hooks)
4. **Frontend Display** (8 hooks)
5. **Admin Panel** (8 hooks)
6. **Settings & Configuration** (6 hooks)
7. **Analytics** (5 hooks)
8. **Security & Authentication** (4 hooks)
9. **Database Operations** (3 hooks)

**Vedi**: `HOOKS_REFERENCE.md` per la lista completa

### Utilizzo Hooks

**Actions** (nessun valore di ritorno):
```php
use App\Support\Hooks;

// Registra action
Hooks::addAction('album_created', function($albumId, $albumData) {
    // Fai qualcosa quando un album viene creato
    error_log("Album created: " . $albumData['title']);
}, 10, 'my-plugin');

// Esegui action
Hooks::doAction('album_created', $albumId, $albumData);
```

**Filters** (modifica e ritorna valore):
```php
// Registra filter
Hooks::addFilter('album_title', function($title, $albumId) {
    return strtoupper($title);
}, 10, 'my-plugin');

// Applica filter
$title = Hooks::applyFilter('album_title', $originalTitle, $albumId);
```

## Best Practices

### Performance
- ✅ Usa priorità appropriate (1-100, default 10)
- ✅ Evita operazioni pesanti negli hooks frequenti
- ✅ Cache risultati quando possibile
- ✅ Cleanup dati vecchi regolarmente

### Security
- ✅ Valida tutti gli input
- ✅ Usa prepared statements per query DB
- ✅ Sanitize output
- ✅ Verifica permessi utente

### Code Quality
- ✅ Segui PSR-12 coding standards
- ✅ Usa strict types (`declare(strict_types=1)`)
- ✅ Documenta il codice
- ✅ Gestisci errori con try-catch

### Database
- ✅ Usa prefisso tabelle custom (es: `plugin_myplugin_*`)
- ✅ Crea indici su colonne usate in WHERE/JOIN
- ✅ Pulisci tabelle in `uninstall.php`
- ✅ Usa transazioni per operazioni multiple

## Troubleshooting

### Plugin Non Si Carica

**Problema**: Plugin installato ma non funziona

**Soluzioni**:
1. Verifica che sia **Attivo** in Admin > Plugin
2. Controlla log errori PHP
3. Verifica sintassi `plugin.php`
4. Controlla che il plugin sia nella directory `plugins/`

### Errore Durante Installazione

**Problema**: Errore quando si clicca su Installa

**Soluzioni**:
1. Verifica permessi directory `plugins/`
2. Controlla `install.php` per errori
3. Verifica connessione database
4. Guarda log errori

### Plugin Causa Errori

**Problema**: L'app si comporta in modo anomalo con plugin attivo

**Soluzioni**:
1. Disattiva il plugin
2. Controlla log errori
3. Debugga il codice del plugin
4. Verifica conflitti con altri plugin

### Database Locked

**Problema**: Errore "database is locked"

**Soluzioni**:
1. SQLite ha limitazioni concorrenza
2. Riduci operazioni DB nel plugin
3. Considera MySQL per production
4. Usa indici appropriati

## Risorse

### Documentazione
- **Hooks Reference**: `HOOKS_REFERENCE.md` - Tutti i 60 hooks
- **Plugin Development**: `PLUGIN_DEVELOPMENT.md` - Guida sviluppo
- **Plugin Ideas**: `PLUGINS_AND_FEATURES_IDEAS.md` - 25 idee plugin + 25 features

### Plugin di Esempio
- **hello-cimaise** - Basic example
- **analytics-logger** - Advanced example
- **image-rating** - Production example
- **cimaise-analytics-pro** - Professional example

### Community
- GitHub Issues - Bug reports
- GitHub Discussions - Q&A
- Pull Requests - Contributi

---

**Made with ❤️ for Cimaise**
