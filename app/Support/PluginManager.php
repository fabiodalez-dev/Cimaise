<?php
declare(strict_types=1);

namespace App\Support;

use App\Support\Database;

/**
 * Plugin Manager - Sistema di gestione plugin e hooks per Cimaise
 *
 * Permette di registrare hooks e eseguire callbacks dei plugin in punti strategici dell'applicazione.
 * Supporta priorità, passaggio dati, filtri e azioni.
 */
class PluginManager
{
    private static ?self $instance = null;

    /**
     * Hooks registrati
     * @var array<string, array<array{callback: callable, priority: int, plugin: string}>>
     */
    private array $hooks = [];

    /**
     * Plugin caricati
     * @var array<string, array{name: string, version: string, enabled: bool, path: string, description: string, author: string}>
     */
    private array $plugins = [];

    /**
     * Cache risultati filtri
     * @var array<string, mixed>
     */
    private array $filterCache = [];

    /**
     * Database connection
     */
    private ?Database $db = null;

    private function __construct()
    {
        // Singleton pattern
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Imposta la connessione al database
     */
    public function setDatabase(Database $db): void
    {
        $this->db = $db;
        $this->ensurePluginTable();
    }

    /**
     * Verifica e crea la tabella plugin se non esiste
     */
    private function ensurePluginTable(): void
    {
        if (!$this->db) {
            return;
        }

        try {
            if ($this->db->isSqlite()) {
                $sql = "CREATE TABLE IF NOT EXISTS plugin_status (
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
                )";
                $this->db->pdo()->exec($sql);
                $this->db->pdo()->exec("CREATE INDEX IF NOT EXISTS idx_plugin_status_active ON plugin_status(is_active)");
            } else {
                $sql = "CREATE TABLE IF NOT EXISTS plugin_status (
                    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    slug VARCHAR(190) NOT NULL,
                    name VARCHAR(190) NOT NULL,
                    version VARCHAR(50) NOT NULL,
                    description TEXT NULL,
                    author VARCHAR(120) NULL,
                    path VARCHAR(255) NOT NULL,
                    is_active TINYINT(1) DEFAULT 1,
                    is_installed TINYINT(1) DEFAULT 1,
                    installed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY idx_plugin_status_slug (slug),
                    KEY idx_plugin_status_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
                $this->db->pdo()->exec($sql);
            }
        } catch (\Throwable $e) {
            Logger::error('PluginManager: Error creating plugin_status table', ['error' => $e->getMessage()], 'plugin');
        }
    }

    /**
     * Registra un plugin
     */
    public function registerPlugin(string $name, string $version, string $path, array $metadata = []): void
    {
        $slug = basename($path);

        $this->plugins[$slug] = [
            'slug' => $slug,
            'name' => $name,
            'version' => $version,
            'enabled' => true,
            'path' => $path,
            'description' => $metadata['description'] ?? '',
            'author' => $metadata['author'] ?? ''
        ];
    }

    /**
     * Registra un hook (action o filter)
     *
     * @param string $hookName Nome dell'hook
     * @param callable $callback Funzione da eseguire
     * @param int $priority Priorità (default 10, più basso = eseguito prima)
     * @param string $plugin Nome del plugin che registra l'hook
     */
    public function addHook(string $hookName, callable $callback, int $priority = 10, string $plugin = 'core'): void
    {
        if (!isset($this->hooks[$hookName])) {
            $this->hooks[$hookName] = [];
        }

        $this->hooks[$hookName][] = [
            'callback' => $callback,
            'priority' => $priority,
            'plugin' => $plugin
        ];

        // Ordina per priorità
        usort($this->hooks[$hookName], fn($a, $b) => $a['priority'] <=> $b['priority']);
    }

    /**
     * Alias per addHook - per actions (senza valore di ritorno)
     */
    public function addAction(string $actionName, callable $callback, int $priority = 10, string $plugin = 'core'): void
    {
        $this->addHook($actionName, $callback, $priority, $plugin);
    }

    /**
     * Alias per addHook - per filters (con valore di ritorno)
     */
    public function addFilter(string $filterName, callable $callback, int $priority = 10, string $plugin = 'core'): void
    {
        $this->addHook($filterName, $callback, $priority, $plugin);
    }

    /**
     * Esegue un'azione (hook senza valore di ritorno)
     *
     * @param string $actionName Nome dell'action
     * @param mixed ...$args Argomenti da passare ai callback
     */
    public function doAction(string $actionName, ...$args): void
    {
        if (!isset($this->hooks[$actionName])) {
            return;
        }

        foreach ($this->hooks[$actionName] as $hook) {
            try {
                call_user_func_array($hook['callback'], $args);
            } catch (\Throwable $e) {
                Logger::error("PluginManager: Error in hook '{$actionName}'", ['error' => $e->getMessage(), 'plugin' => $hook['plugin']], 'plugin');
            }
        }
    }

    /**
     * Applica un filtro (hook con valore di ritorno)
     *
     * @param string $filterName Nome del filter
     * @param mixed $value Valore da filtrare
     * @param mixed ...$args Argomenti aggiuntivi
     * @return mixed Valore filtrato
     */
    public function applyFilter(string $filterName, $value, ...$args): mixed
    {
        if (!isset($this->hooks[$filterName])) {
            return $value;
        }

        // Check cache
        $cacheKey = $filterName . '_' . serialize([$value, ...$args]);
        if (isset($this->filterCache[$cacheKey])) {
            return $this->filterCache[$cacheKey];
        }

        $filteredValue = $value;

        foreach ($this->hooks[$filterName] as $hook) {
            try {
                $filteredValue = call_user_func_array($hook['callback'], [$filteredValue, ...$args]);
            } catch (\Throwable $e) {
                Logger::error("PluginManager: Error in filter '{$filterName}'", ['error' => $e->getMessage(), 'plugin' => $hook['plugin']], 'plugin');
            }
        }

        // Cache result
        $this->filterCache[$cacheKey] = $filteredValue;

        return $filteredValue;
    }

    /**
     * Rimuove un hook
     */
    public function removeHook(string $hookName, callable $callback): bool
    {
        if (!isset($this->hooks[$hookName])) {
            return false;
        }

        foreach ($this->hooks[$hookName] as $index => $hook) {
            if ($hook['callback'] === $callback) {
                unset($this->hooks[$hookName][$index]);
                $this->hooks[$hookName] = array_values($this->hooks[$hookName]);
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se un hook è stato registrato
     */
    public function hasHook(string $hookName): bool
    {
        return isset($this->hooks[$hookName]) && count($this->hooks[$hookName]) > 0;
    }

    /**
     * Ottiene tutti gli hooks registrati per un nome
     */
    public function getHooks(string $hookName): array
    {
        return $this->hooks[$hookName] ?? [];
    }

    /**
     * Ottiene tutti i plugin registrati
     */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    /**
     * Abilita/disabilita un plugin
     */
    public function togglePlugin(string $pluginName, bool $enabled): void
    {
        if (isset($this->plugins[$pluginName])) {
            $this->plugins[$pluginName]['enabled'] = $enabled;
        }
    }

    /**
     * Carica tutti i plugin dalla directory plugins/ (solo quelli attivi)
     */
    public function loadPlugins(string $pluginsDir): void
    {
        if (!is_dir($pluginsDir)) {
            return;
        }

        $plugins = glob($pluginsDir . '/*', GLOB_ONLYDIR);

        foreach ($plugins as $pluginPath) {
            $pluginFile = $pluginPath . '/plugin.php';

            if (file_exists($pluginFile)) {
                $slug = basename($pluginPath);

                // Leggi metadata plugin
                $metadata = $this->parsePluginMetadata($pluginFile);

                // Verifica se il plugin è attivo nel database
                if ($this->db && !$this->isPluginActive($slug)) {
                    continue; // Skip plugin non attivi
                }

                $this->registerPlugin(
                    $metadata['name'] ?? $slug,
                    $metadata['version'] ?? '1.0.0',
                    $pluginPath,
                    $metadata
                );

                // Carica il plugin
                try {
                    require_once $pluginFile;
                } catch (\Throwable $e) {
                    Logger::error("PluginManager: Error loading plugin", ['slug' => $slug, 'error' => $e->getMessage()], 'plugin');
                }
            }
        }
    }

    /**
     * Estrae metadata da commento header del file plugin
     */
    private function parsePluginMetadata(string $file): array
    {
        $content = file_get_contents($file);
        $metadata = [];

        // Cerca header comment block
        if (preg_match('|Plugin Name:(.*)$|mi', $content, $match)) {
            $metadata['name'] = trim($match[1]);
        }
        if (preg_match('|Version:(.*)$|mi', $content, $match)) {
            $metadata['version'] = trim($match[1]);
        }
        if (preg_match('|Description:(.*)$|mi', $content, $match)) {
            $metadata['description'] = trim($match[1]);
        }
        if (preg_match('|Author:(.*)$|mi', $content, $match)) {
            $metadata['author'] = trim($match[1]);
        }

        return $metadata;
    }

    /**
     * Pulisce la cache dei filtri
     */
    public function clearFilterCache(): void
    {
        $this->filterCache = [];
    }

    /**
     * Ottiene tutti i plugin disponibili nella directory (installati e non)
     */
    public function getAllAvailablePlugins(string $pluginsDir): array
    {
        $available = [];

        if (!is_dir($pluginsDir)) {
            return $available;
        }

        $plugins = glob($pluginsDir . '/*', GLOB_ONLYDIR);

        foreach ($plugins as $pluginPath) {
            $pluginFile = $pluginPath . '/plugin.php';

            if (file_exists($pluginFile)) {
                $slug = basename($pluginPath);
                $metadata = $this->parsePluginMetadata($pluginFile);

                // Get status from database
                $dbStatus = $this->getPluginStatus($slug);

                $available[$slug] = [
                    'slug' => $slug,
                    'name' => $metadata['name'] ?? $slug,
                    'version' => $metadata['version'] ?? '1.0.0',
                    'description' => $metadata['description'] ?? '',
                    'author' => $metadata['author'] ?? '',
                    'path' => $pluginPath,
                    'is_installed' => $dbStatus['is_installed'] ?? false,
                    'is_active' => $dbStatus['is_active'] ?? false,
                    'installed_at' => $dbStatus['installed_at'] ?? null,
                ];
            }
        }

        return $available;
    }

    /**
     * Ottiene lo stato di un plugin dal database
     */
    public function getPluginStatus(string $slug): ?array
    {
        if (!$this->db) {
            return null;
        }

        try {
            $stmt = $this->db->pdo()->prepare('SELECT * FROM plugin_status WHERE slug = ?');
            $stmt->execute([$slug]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($result) {
                return [
                    'is_installed' => (bool)$result['is_installed'],
                    'is_active' => (bool)$result['is_active'],
                    'installed_at' => $result['installed_at'],
                    'version' => $result['version']
                ];
            }
        } catch (\Throwable $e) {
            Logger::warning('PluginManager: Error getting plugin status', ['error' => $e->getMessage()], 'plugin');
        }

        return null;
    }

    /**
     * Verifica se un plugin è attivo
     */
    public function isPluginActive(string $slug): bool
    {
        if (!$this->db) {
            return true; // Se non c'è DB, carica tutti i plugin
        }

        $status = $this->getPluginStatus($slug);
        return $status && $status['is_active'] && $status['is_installed'];
    }

    /**
     * Installa un plugin
     */
    public function installPlugin(string $slug, string $pluginsDir): array
    {
        if (!$this->db) {
            return ['success' => false, 'message' => 'Database not available'];
        }

        $pluginPath = $pluginsDir . '/' . $slug;
        $pluginFile = $pluginPath . '/plugin.php';

        if (!file_exists($pluginFile)) {
            return ['success' => false, 'message' => 'Plugin not found'];
        }

        try {
            // Read metadata
            $metadata = $this->parsePluginMetadata($pluginFile);

            // Check if already installed
            $existing = $this->getPluginStatus($slug);

            if ($existing && $existing['is_installed']) {
                return ['success' => false, 'message' => 'Plugin already installed'];
            }

            // Execute install hook if present
            $installHook = $pluginPath . '/install.php';
            if (file_exists($installHook)) {
                require_once $installHook;
            }

            // Save to database (MySQL compatible)
            $replaceKw = $this->db->replaceKeyword();
            $nowExpr = $this->db->nowExpression();
            $stmt = $this->db->pdo()->prepare("
                {$replaceKw} INTO plugin_status
                (slug, name, version, description, author, path, is_active, is_installed, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 1, 1, {$nowExpr})
            ");

            $stmt->execute([
                $slug,
                $metadata['name'] ?? $slug,
                $metadata['version'] ?? '1.0.0',
                $metadata['description'] ?? '',
                $metadata['author'] ?? '',
                $pluginPath
            ]);

            return ['success' => true, 'message' => 'Plugin installed successfully'];
        } catch (\Throwable $e) {
            Logger::error('PluginManager: Error installing plugin', ['slug' => $slug, 'error' => $e->getMessage()], 'plugin');
            return ['success' => false, 'message' => 'Error during installation: ' . $e->getMessage()];
        }
    }

    /**
     * Uninstall a plugin
     */
    public function uninstallPlugin(string $slug, string $pluginsDir): array
    {
        if (!$this->db) {
            return ['success' => false, 'message' => 'Database not available'];
        }

        try {
            $pluginPath = $pluginsDir . '/' . $slug;

            // Execute uninstall hook if present
            $uninstallHook = $pluginPath . '/uninstall.php';
            if (file_exists($uninstallHook)) {
                require_once $uninstallHook;
            }

            // Remove from database
            $stmt = $this->db->pdo()->prepare('DELETE FROM plugin_status WHERE slug = ?');
            $stmt->execute([$slug]);

            return ['success' => true, 'message' => 'Plugin uninstalled successfully'];
        } catch (\Throwable $e) {
            Logger::error('PluginManager: Error uninstalling plugin', ['slug' => $slug, 'error' => $e->getMessage()], 'plugin');
            return ['success' => false, 'message' => 'Error during uninstallation: ' . $e->getMessage()];
        }
    }

    /**
     * Activate a plugin
     */
    public function activatePlugin(string $slug): array
    {
        if (!$this->db) {
            return ['success' => false, 'message' => 'Database not available'];
        }

        try {
            $status = $this->getPluginStatus($slug);

            if (!$status || !$status['is_installed']) {
                return ['success' => false, 'message' => 'Plugin not installed'];
            }

            $stmt = $this->db->pdo()->prepare('
                UPDATE plugin_status
                SET is_active = 1, updated_at = CURRENT_TIMESTAMP
                WHERE slug = ?
            ');
            $stmt->execute([$slug]);

            return ['success' => true, 'message' => 'Plugin activated successfully'];
        } catch (\Throwable $e) {
            Logger::error('PluginManager: Error activating plugin', ['slug' => $slug, 'error' => $e->getMessage()], 'plugin');
            return ['success' => false, 'message' => 'Error during activation: ' . $e->getMessage()];
        }
    }

    /**
     * Deactivate a plugin
     */
    public function deactivatePlugin(string $slug): array
    {
        if (!$this->db) {
            return ['success' => false, 'message' => 'Database not available'];
        }

        try {
            $stmt = $this->db->pdo()->prepare('
                UPDATE plugin_status
                SET is_active = 0, updated_at = CURRENT_TIMESTAMP
                WHERE slug = ?
            ');
            $stmt->execute([$slug]);

            return ['success' => true, 'message' => 'Plugin deactivated successfully'];
        } catch (\Throwable $e) {
            Logger::error('PluginManager: Error deactivating plugin', ['slug' => $slug, 'error' => $e->getMessage()], 'plugin');
            return ['success' => false, 'message' => 'Error during deactivation: ' . $e->getMessage()];
        }
    }

    /**
     * Get all installed plugins
     */
    public function getInstalledPlugins(): array
    {
        if (!$this->db) {
            return [];
        }

        try {
            $stmt = $this->db->pdo()->query('
                SELECT * FROM plugin_status
                WHERE is_installed = 1
                ORDER BY name
            ');
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Logger::warning('PluginManager: Error getting installed plugins', ['error' => $e->getMessage()], 'plugin');
            return [];
        }
    }

    /**
     * Debug: Ottiene statistiche hooks
     */
    public function getStats(): array
    {
        $totalHooks = count($this->hooks);
        $totalCallbacks = array_sum(array_map('count', $this->hooks));

        return [
            'total_hooks' => $totalHooks,
            'total_callbacks' => $totalCallbacks,
            'hooks_list' => array_keys($this->hooks),
            'plugins_count' => count($this->plugins),
            'cache_entries' => count($this->filterCache)
        ];
    }
}
