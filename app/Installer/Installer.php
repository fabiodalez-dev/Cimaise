<?php
declare(strict_types=1);

namespace App\Installer;

use App\Support\Database;
use App\Support\Logger;

class Installer
{
    private ?Database $db = null;
    private array $config = [];
    private string $rootPath;
    private bool $envWritten = false;
    private bool $dbCreated = false;
    private ?string $createdDbPath = null;
    private bool $permissionsFixed = false;

    public function __construct(string $rootPath)
    {
        $this->rootPath = $rootPath;
    }

    public function isInstalled(): bool
    {
        if (!file_exists($this->rootPath . '/.env')) {
            return false;
        }

        $envContent = file_get_contents($this->rootPath . '/.env');
        if (empty($envContent)) {
            return false;
        }

        $lines = explode("\n", $envContent);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $this->config[trim($key)] = trim($value);
            }
        }

        try {
            $connection = $this->config['DB_CONNECTION'] ?? 'sqlite';

            if ($connection === 'sqlite') {
                $dbPath = $this->config['DB_DATABASE'] ?? $this->rootPath . '/database/database.sqlite';
                if (!str_starts_with($dbPath, '/')) {
                    $dbPath = $this->rootPath . '/' . $dbPath;
                }
                if (!file_exists($dbPath)) {
                    return false;
                }
                $this->db = new Database(database: $dbPath, isSqlite: true);
            } else {
                $this->db = new Database(
                    host: $this->config['DB_HOST'] ?? '127.0.0.1',
                    port: (int)($this->config['DB_PORT'] ?? 3306),
                    database: $this->config['DB_DATABASE'] ?? 'cimaise',
                    username: $this->config['DB_USERNAME'] ?? 'root',
                    password: $this->config['DB_PASSWORD'] ?? '',
                    charset: $this->config['DB_CHARSET'] ?? 'utf8mb4',
                    collation: $this->config['DB_COLLATION'] ?? 'utf8mb4_unicode_ci',
                );
            }

            $tables = $this->getExistingTables();
            $requiredTables = ['users', 'settings', 'templates', 'categories'];

            foreach ($requiredTables as $table) {
                if (!in_array($table, $tables)) {
                    return false;
                }
            }

            $stmt = $this->db->pdo()->query('SELECT COUNT(*) as count FROM users');
            $result = $stmt->fetch();
            return $result['count'] > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function getExistingTables(): array
    {
        try {
            if ($this->db->isSqlite()) {
                $stmt = $this->db->pdo()->query("SELECT name FROM sqlite_master WHERE type='table'");
            } else {
                $stmt = $this->db->pdo()->query('SHOW TABLES');
            }

            $tables = [];
            while ($row = $stmt->fetch()) {
                $tables[] = $this->db->isSqlite() ? $row['name'] : reset($row);
            }
            return $tables;
        } catch (\Throwable) {
            return [];
        }
    }

    public function install(array $data): bool
    {
        // Reset state tracking
        $this->envWritten = false;
        $this->dbCreated = false;
        $this->createdDbPath = null;
        $this->permissionsFixed = false;

        try {
            $this->createPermissionsFixTokenFile();
            $this->fixPermissionsOnce();
            $this->verifyRequirements($data);
            $this->setupDatabase($data);
            $this->installSchema();
            $this->runPluginInstallHooks();
            $this->createFirstUser($data);
            $this->updateSiteSettings($data);
            $this->generateFavicons($data);
            $this->createEnvFile($data);
            $this->configureHtaccess($data);
            return true;
        } catch (\Throwable $e) {
            error_log('Installation failed: ' . $e->getMessage());
            // Cleanup on failure
            $this->rollback();
            throw $e;
        }
    }

    /**
     * Rollback partial installation on failure
     */
    private function rollback(): void
    {
        // Remove .env if we created it
        if ($this->envWritten) {
            $envPath = $this->rootPath . '/.env';
            if (file_exists($envPath)) {
                @unlink($envPath);
            }
        }

        // Remove SQLite database if we created it
        if ($this->dbCreated && $this->createdDbPath && file_exists($this->createdDbPath)) {
            @unlink($this->createdDbPath);
        }

        // For MySQL, drop tables if schema was partially applied
        if ($this->db !== null && !$this->db->isSqlite()) {
            try {
                $tablesToDrop = [
                    'album_tag', 'album_category', 'album_camera', 'album_lens',
                    'album_film', 'album_developer', 'album_lab', 'album_location',
                    'image_variants', 'images', 'albums', 'users', 'settings',
                    'templates', 'categories', 'tags', 'cameras', 'lenses',
                    'films', 'developers', 'labs', 'locations', 'filter_settings',
                    'frontend_texts', 'custom_templates', 'plugin_status',
                    'plugin_analytics_custom_events', 'plugin_image_ratings',
                    'analytics_pro_events', 'analytics_pro_sessions',
                    'analytics_pro_funnels', 'analytics_pro_dimensions'
                ];
                foreach ($tablesToDrop as $table) {
                    $this->db->pdo()->exec("DROP TABLE IF EXISTS `{$table}`");
                }
            } catch (\Throwable) {
                // Ignore cleanup errors
            }
        }
    }

    private function createPermissionsFixTokenFile(): void
    {
        $tokenPath = $this->rootPath . '/storage/tmp/permissions_fix_token.txt';
        if (is_file($tokenPath)) {
            return;
        }

        $dir = dirname($tokenPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                Logger::warning('Installer: Failed to create permissions token directory', [
                    'path' => $dir,
                ], 'installer');
                return;
            }
        }

        try {
            $token = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            Logger::warning('Installer: Failed to generate permissions token', [
                'error' => $e->getMessage(),
            ], 'installer');
            return;
        }

        if (file_put_contents($tokenPath, $token) === false) {
            Logger::warning('Installer: Failed to write permissions token file', [
                'path' => $tokenPath,
            ], 'installer');
        }
    }

    private function fixPermissionsOnce(): void
    {
        if ($this->permissionsFixed) {
            return;
        }

        $marker = $this->rootPath . '/storage/tmp/permissions_fix_done';
        $dir = dirname($marker);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                Logger::warning('Installer: Failed to create permissions marker directory', [
                    'path' => $dir,
                ], 'installer');
                return;
            }
        }

        if (is_file($marker)) {
            $this->permissionsFixed = true;
            return;
        }

        $this->fixPermissions();
        $this->permissionsFixed = true;

        if (file_put_contents($marker, (string)time()) === false) {
            Logger::warning('Installer: Failed to write permissions marker file', [
                'path' => $marker,
            ], 'installer');
        }
    }

    private function verifyRequirements(array $data = []): void
    {
        $errors = $this->collectRequirementErrors($data, true);

        if (!empty($errors)) {
            throw new \RuntimeException(implode("\n", $errors));
        }
    }

    private function runPluginInstallHooks(): void
    {
        if ($this->db === null) {
            return;
        }

        $pluginsDir = $this->rootPath . '/plugins';
        if (!is_dir($pluginsDir)) {
            return;
        }

        $previousContainer = $GLOBALS['container'] ?? null;
        $GLOBALS['container'] = ['db' => $this->db];

        foreach (glob($pluginsDir . '/*', GLOB_ONLYDIR) as $pluginPath) {
            $installHook = $pluginPath . '/install.php';
            if (!file_exists($installHook)) {
                continue;
            }

            try {
                ob_start();
                $installer = require $installHook;
                ob_end_clean();

                if (is_callable($installer)) {
                    $installer($this->db);
                }
            } catch (\Throwable $e) {
                error_log('Installer: Plugin install hook failed: ' . basename($pluginPath) . ' - ' . $e->getMessage());
            }
        }

        if ($previousContainer !== null) {
            $GLOBALS['container'] = $previousContainer;
        } else {
            unset($GLOBALS['container']);
        }
    }

    /**
     * Collect requirement errors - shared logic for verification and display
     *
     * @param array $data Installation data
     * @param bool $createDirectories Whether to attempt directory creation
     * @return array List of error messages
     */
    private function collectRequirementErrors(array $data = [], bool $createDirectories = false): array
    {
        $errors = [];

        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $errors[] = 'PHP 8.2 or higher is required. Current version: ' . PHP_VERSION;
        }

        // Core required extensions
        $requiredExtensions = ['pdo', 'gd', 'mbstring', 'openssl', 'json', 'fileinfo'];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $errors[] = "Required PHP extension '{$ext}' is not installed";
            }
        }

        // Log recommended extensions (warn but don't fail)
        if ($createDirectories) {
            $recommendedExtensions = ['exif', 'curl'];
            foreach ($recommendedExtensions as $ext) {
                if (!extension_loaded($ext)) {
                    error_log("Recommended PHP extension '{$ext}' is not installed");
                }
            }
        }

        // Check database driver based on connection type
        $connection = $data['db_connection'] ?? 'sqlite';
        if ($connection === 'sqlite' && !extension_loaded('pdo_sqlite')) {
            $errors[] = 'PDO SQLite extension is required for SQLite database';
        } elseif ($connection === 'mysql' && !extension_loaded('pdo_mysql')) {
            $errors[] = 'PDO MySQL extension is required for MySQL database';
        } elseif (!extension_loaded('pdo_mysql') && !extension_loaded('pdo_sqlite')) {
            $errors[] = 'Either PDO MySQL or PDO SQLite extension is required';
        }

        // Check writable directories
        $writablePaths = [
            $this->rootPath . '/database' => 'database',
            $this->rootPath . '/storage' => 'storage',
            $this->rootPath . '/public/media' => 'public/media',
        ];

        // Add storage/originals only for installation (createDirectories mode)
        if ($createDirectories) {
            $writablePaths[$this->rootPath . '/storage/originals'] = 'storage/originals';
        }

        foreach ($writablePaths as $path => $name) {
            if ($createDirectories) {
                // Create directory if it doesn't exist
                if (!is_dir($path)) {
                    if (!@mkdir($path, 0755, true)) {
                        $errors[] = "Cannot create directory '{$name}'";
                        continue;
                    }
                }
            }
            if (!is_dir($path) || !is_writable($path)) {
                $errors[] = "Directory '{$name}' is not writable";
            }
        }

        // Check .env parent directory is writable
        if (!is_writable($this->rootPath)) {
            $errors[] = "Root directory is not writable (cannot create .env file)";
        }

        // Check disk space (minimum 100MB free) - only during installation
        if ($createDirectories) {
            $freeSpace = @disk_free_space($this->rootPath . '/storage');
            if ($freeSpace !== false && $freeSpace < 100 * 1024 * 1024) {
                $errors[] = 'Insufficient disk space. At least 100MB free space is required.';
            }
        }

        return $errors;
    }

    private function setupDatabase(array $data): void
    {
        $connection = $data['db_connection'] ?? 'sqlite';

        if ($connection === 'sqlite') {
            // Use user-provided path or default
            $dbPath = $data['sqlite_path'] ?? ($this->rootPath . '/database/database.sqlite');

            // Handle relative paths
            if (!str_starts_with($dbPath, '/')) {
                $dbPath = $this->rootPath . '/' . $dbPath;
            }

            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    throw new \RuntimeException("Cannot create database directory: {$dir}");
                }
            }
            $dbPath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($dbPath);
            if (file_exists($dbPath)) {
                unlink($dbPath);
            }
            touch($dbPath);
            $this->createdDbPath = $dbPath;
            $this->dbCreated = true;
            $this->db = new Database(database: $dbPath, isSqlite: true);
        } else {
            $host = $data['db_host'] ?? '127.0.0.1';
            $port = (int)($data['db_port'] ?? 3306);
            $database = $data['db_database'] ?? 'cimaise';
            $username = $data['db_username'] ?? 'root';
            $password = $data['db_password'] ?? '';
            // Use consistent charset/collation (utf8mb4_unicode_ci for MySQL 5.7+ compatibility)
            $charset = $data['db_charset'] ?? 'utf8mb4';
            $collation = $data['db_collation'] ?? 'utf8mb4_unicode_ci';

            // First, try to create the database if it doesn't exist
            try {
                $dsn = "mysql:host={$host};port={$port};charset={$charset}";
                $pdo = new \PDO($dsn, $username, $password, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 10,
                ]);

                // Try to create database
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET {$charset} COLLATE {$collation}");
            } catch (\PDOException $e) {
                // If we can't create, just proceed and let it fail on connection if DB doesn't exist
                error_log("Note: Could not create database (may already exist): " . $e->getMessage());
            }

            $this->db = new Database(
                host: $host,
                port: $port,
                database: $database,
                username: $username,
                password: $password,
                charset: $charset,
                collation: $collation,
            );

            // Test that we have proper privileges
            $this->testMySQLPrivileges();
        }
    }

    /**
     * Test MySQL privileges for installation
     */
    private function testMySQLPrivileges(): void
    {
        if ($this->db === null || $this->db->isSqlite()) {
            return;
        }

        $pdo = $this->db->pdo();

        try {
            // Test CREATE TABLE privilege
            $pdo->exec('CREATE TABLE IF NOT EXISTS _install_test (id INT)');
            // Test INSERT privilege
            $pdo->exec('INSERT INTO _install_test (id) VALUES (1)');
            // Test UPDATE privilege
            $pdo->exec('UPDATE _install_test SET id = 2 WHERE id = 1');
            // Test DELETE privilege
            $pdo->exec('DELETE FROM _install_test WHERE id = 2');
            // Test ALTER privilege
            $pdo->exec('ALTER TABLE _install_test ADD COLUMN test_col INT NULL');
            // Cleanup
            $pdo->exec('DROP TABLE IF EXISTS _install_test');
        } catch (\PDOException $e) {
            // Cleanup on failure
            try {
                $pdo->exec('DROP TABLE IF EXISTS _install_test');
            } catch (\Throwable) {
            }
            throw new \RuntimeException(
                "Insufficient MySQL privileges. The database user needs CREATE, ALTER, INSERT, UPDATE, DELETE privileges. Error: " . $e->getMessage()
            );
        }
    }

    private function installSchema(): void
    {
        if ($this->db === null) {
            throw new \RuntimeException('Database connection not established');
        }

        if ($this->db->isSqlite()) {
            // SQLite: build schema from SQL to keep indexes in sync with MySQL
            $schemaPath = $this->rootPath . '/database/schema.sqlite.sql';
            if (!file_exists($schemaPath)) {
                throw new \RuntimeException('SQLite schema file not found. Please ensure database/schema.sqlite.sql exists.');
            }

            $this->db->execSqlFile($schemaPath);
            $this->dbCreated = true;
        } else {
            // MySQL: execute schema file
            $schemaPath = $this->rootPath . '/database/schema.mysql.sql';

            if (!file_exists($schemaPath)) {
                throw new \RuntimeException('MySQL schema file not found. Please ensure database/schema.mysql.sql exists.');
            }

            $this->db->execSqlFile($schemaPath);
        }
    }

    private function createFirstUser(array $data): void
    {
        $stmt = $this->db->pdo()->query('SELECT COUNT(*) as count FROM users');
        $result = $stmt->fetch();
        if ($result['count'] > 0) {
            // Delete existing users for fresh install
            $this->db->pdo()->exec('DELETE FROM users');
        }

        $password = password_hash($data['admin_password'], PASSWORD_ARGON2ID);
        $createdAt = date('Y-m-d H:i:s');

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO users (email, password_hash, role, created_at, first_name, last_name, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['admin_email'],
            $password,
            'admin',
            $createdAt,
            $data['admin_name'] ?? 'Admin',
            '',
            1
        ]);
    }

    private function generateFavicons(array $data): void
    {
        // Generate favicons from uploaded logo if available
        $logoPath = $data['site_logo_path'] ?? null;

        if ($logoPath === null || $logoPath === '') {
            // No logo uploaded, skip favicon generation
            return;
        }

        try {
            $publicPath = $this->rootPath . '/public';
            $absoluteLogoPath = $publicPath . $logoPath;

            if (!file_exists($absoluteLogoPath)) {
                // Logo file doesn't exist, skip favicon generation
                error_log('Installer: Logo file not found for favicon generation: ' . $absoluteLogoPath);
                return;
            }

            $faviconService = new \App\Services\FaviconService($publicPath);
            $result = $faviconService->generateFavicons($absoluteLogoPath);

            if (!$result['success']) {
                // Log error but don't fail installation
                // FaviconService returns 'error' (singular) for early failures, 'errors' (array) for generation failures
                if (!empty($result['error'])) {
                    $errorMsg = $result['error'];
                } elseif (!empty($result['errors'])) {
                    $errorMsg = implode(', ', $result['errors']);
                } else {
                    $errorMsg = 'Unknown error';
                }
                error_log('Installer: Failed to generate favicons: ' . $errorMsg);
            }
        } catch (\Throwable $e) {
            // Log error but don't fail installation
            error_log('Installer: Exception during favicon generation: ' . $e->getMessage());
        }
    }

    private function updateSiteSettings(array $data): void
    {
        // Validate and sanitize language and date format
        $rawLanguage = (string)($data['site_language'] ?? 'en');
        $language = in_array($rawLanguage, ['en', 'it'], true) ? $rawLanguage : 'en';

        $rawAdminLanguage = (string)($data['admin_language'] ?? 'en');
        $adminLanguage = \in_array($rawAdminLanguage, ['en', 'it'], true) ? $rawAdminLanguage : 'en';

        $rawDateFormat = (string)($data['date_format'] ?? 'Y-m-d');
        $dateFormat = in_array($rawDateFormat, ['Y-m-d', 'd-m-Y'], true) ? $rawDateFormat : 'Y-m-d';

        $settings = [
            'site.title' => $data['site_title'] ?? 'Cimaise',
            'site.description' => $data['site_description'] ?? 'Professional Photography Portfolio',
            'site.copyright' => $data['site_copyright'] ?? '© {year} Photography Portfolio',
            'site.email' => $data['site_email'] ?? '',
            'site.language' => $language,
            'admin.language' => $adminLanguage,
            'date.format' => $dateFormat,
            'site.logo' => $data['site_logo_path'] ?? null,
            // Typography defaults (EB Garamond for headings, Inter for body - all local fonts)
            'typography.headings_font' => 'eb-garamond',
            'typography.headings_weight' => 600,
            'typography.body_font' => 'inter',
            'typography.body_weight' => 400,
            'typography.navigation_font' => 'inter',
            'typography.navigation_weight' => 500,
            'typography.captions_font' => 'inter',
            'typography.captions_weight' => 400,
        ];

        // Add performance settings if provided (from installer UI)
        // Checkbox sends '1' when checked, absent when unchecked
        $cacheEnabled = isset($data['cache_enabled']) && $data['cache_enabled'] === '1';
        $compressionEnabled = isset($data['compression_enabled']) && $data['compression_enabled'] === '1';

        $settings['performance.cache_enabled'] = $cacheEnabled;
        $settings['performance.compression_enabled'] = $compressionEnabled;

        // Add page settings based on selected language
        $pageSettings = $this->getPageSettingsForLanguage($language);
        $settings = array_merge($settings, $pageSettings);

        foreach ($settings as $key => $value) {
            $encodedValue = json_encode($value, JSON_UNESCAPED_SLASHES);
            $type = 'string';

            if ($this->db->isSqlite()) {
                $stmt = $this->db->pdo()->prepare(
                    'INSERT OR REPLACE INTO settings (key, value, type, created_at, updated_at) VALUES (?, ?, ?, datetime(\'now\'), datetime(\'now\'))'
                );
                $stmt->execute([$key, $encodedValue, $type]);
            } else {
                $stmt = $this->db->pdo()->prepare(
                    'INSERT INTO settings (`key`, `value`, `type`, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = NOW()'
                );
                $stmt->execute([$key, $encodedValue, $type]);
            }
        }
    }

    /**
     * Get page settings based on installation language
     * These are the DEFAULT VALUES for page content fields (not UI labels)
     */
    private function getPageSettingsForLanguage(string $language): array
    {
        $translations = [
            'en' => [
                // Home page
                'home.hero_title' => 'Portfolio',
                'home.hero_subtitle' => 'A collection of analog and digital photography exploring light, form, and the beauty of everyday moments.',
                'home.albums_title' => 'Latest Albums',
                'home.albums_subtitle' => 'Discover my recent photographic work, from analog experiments to digital explorations.',
                'home.empty_title' => 'No albums yet',
                'home.empty_text' => 'Check back soon for new work.',
                // About page
                'about.title' => 'About',
                'about.contact_title' => 'Contact',
                'about.contact_subject' => 'Portfolio',
                // Galleries page
                'galleries.title' => 'All Galleries',
                'galleries.subtitle' => 'Explore our complete collection of photography galleries',
                'galleries.filter_button_text' => 'Filters',
                'galleries.clear_filters_text' => 'Clear filters',
                'galleries.results_text' => 'galleries',
                'galleries.no_results_title' => 'No galleries found',
                'galleries.no_results_text' => 'We couldn\'t find any galleries matching your current filters. Try adjusting your search criteria or clearing all filters.',
                'galleries.view_button_text' => 'View',
            ],
            'it' => [
                // Home page
                'home.hero_title' => 'Portfolio',
                'home.hero_subtitle' => 'Una collezione di fotografia analogica e digitale che esplora la luce, la forma e la bellezza dei momenti quotidiani.',
                'home.albums_title' => 'Ultimi Album',
                'home.albums_subtitle' => 'Scopri i miei lavori fotografici più recenti, dagli esperimenti analogici alle esplorazioni digitali.',
                'home.empty_title' => 'Nessun album ancora',
                'home.empty_text' => 'Torna presto per nuovi lavori.',
                // About page
                'about.title' => 'Chi sono',
                'about.contact_title' => 'Contatti',
                'about.contact_subject' => 'Portfolio',
                // Galleries page
                'galleries.title' => 'Tutte le Gallerie',
                'galleries.subtitle' => 'Esplora la nostra collezione completa di gallerie fotografiche',
                'galleries.filter_button_text' => 'Filtri',
                'galleries.clear_filters_text' => 'Cancella filtri',
                'galleries.results_text' => 'gallerie',
                'galleries.no_results_title' => 'Nessuna galleria trovata',
                'galleries.no_results_text' => 'Non abbiamo trovato gallerie che corrispondono ai filtri selezionati. Prova a modificare i criteri di ricerca o a cancellare tutti i filtri.',
                'galleries.view_button_text' => 'Vedi',
            ],
        ];

        return $translations[$language] ?? $translations['en'];
    }

    private function createEnvFile(array $data): void
    {
        $connection = $data['db_connection'] ?? 'sqlite';

        // Auto-detect APP_URL if not provided
        $appUrl = $data['app_url'] ?? $this->detectAppUrl();

        $envContent = "APP_ENV=production\n";
        $envContent .= "APP_DEBUG=false\n";
        $envContent .= "APP_URL=" . $appUrl . "\n";
        $envContent .= "APP_TIMEZONE=UTC\n\n";

        $envContent .= "DB_CONNECTION={$connection}\n";

        if ($connection === 'sqlite') {
            // Use the actual path where we created the database
            // Convert to relative path from root for portability
            $dbPath = $this->createdDbPath ?? ($this->rootPath . '/database/database.sqlite');
            if (str_starts_with($dbPath, $this->rootPath . '/')) {
                $dbPath = substr($dbPath, strlen($this->rootPath) + 1);
            }
            $envContent .= "DB_DATABASE={$dbPath}\n";
        } else {
            $envContent .= "DB_HOST=" . ($data['db_host'] ?? '127.0.0.1') . "\n";
            $envContent .= "DB_PORT=" . ($data['db_port'] ?? '3306') . "\n";
            $envContent .= "DB_DATABASE=" . ($data['db_database'] ?? 'cimaise') . "\n";
            $envContent .= "DB_USERNAME=" . ($data['db_username'] ?? 'root') . "\n";
            $envContent .= "DB_PASSWORD=" . ($data['db_password'] ?? '') . "\n";
            // Use consistent charset/collation (utf8mb4_unicode_ci for MySQL 5.7+ compatibility)
            $envContent .= "DB_CHARSET=" . ($data['db_charset'] ?? 'utf8mb4') . "\n";
            $envContent .= "DB_COLLATION=" . ($data['db_collation'] ?? 'utf8mb4_unicode_ci') . "\n";
        }

        $sessionSecret = bin2hex(random_bytes(32));
        $envContent .= "\nSESSION_SECRET=" . $sessionSecret . "\n";
        $envContent .= "\n# Upload tuning\nFAST_UPLOAD=false\nSYNC_VARIANTS_ON_UPLOAD=true\n";

        $envFilePath = $this->rootPath . '/.env';
        if (file_put_contents($envFilePath, $envContent) === false) {
            throw new \RuntimeException('Failed to write .env file');
        }
        $this->envWritten = true;
    }

    /**
     * Configure .htaccess and router files for universal deployment
     *
     * This ensures the application works with any installation type:
     * - Root installation (DocumentRoot = public/)
     * - Subdomain (DocumentRoot = project root or public/)
     * - Subdirectory (any path)
     */
    private function configureHtaccess(array $data): void
    {
        // Get the detected or provided APP_URL
        $appUrl = $data['app_url'] ?? $this->detectAppUrl();
        $subdirectory = $this->extractSubdirectory($appUrl);

        try {
            // Always ensure universal root files exist for flexibility
            // They allow deployment without changing DocumentRoot
            $this->ensureUniversalRootFiles();

            // For subdirectory installations, also update public/.htaccess
            if ($subdirectory !== '') {
                // Validate subdirectory - only allow safe characters for Apache config
                if (!preg_match('#^[a-zA-Z0-9/_-]*$#', $subdirectory)) {
                    throw new \RuntimeException('Invalid subdirectory path: contains unsafe characters');
                }

                $this->updatePublicHtaccess($subdirectory);
                error_log("Installer: Configured .htaccess files for subdirectory: {$subdirectory}");
            } else {
                error_log("Installer: Universal root files ensured for root/subdomain installation");
            }
        } catch (\Throwable $e) {
            // Log error but don't fail installation
            error_log('Installer: Failed to configure .htaccess: ' . $e->getMessage());
        }
    }

    /**
     * Ensure universal root files exist for flexible deployment
     * These files allow the app to work when DocumentRoot points to project root
     */
    private function ensureUniversalRootFiles(): void
    {
        // Check if root index.php router exists
        $rootIndexPath = $this->rootPath . '/index.php';
        if (!file_exists($rootIndexPath)) {
            // Create a minimal router that redirects to public/index.php
            $this->createUniversalRootIndex();
        }

        // Check if root .htaccess exists
        $rootHtaccessPath = $this->rootPath . '/.htaccess';
        if (!file_exists($rootHtaccessPath)) {
            $this->createUniversalRootHtaccess();
        }
    }

    /**
     * Create universal root index.php router
     */
    private function createUniversalRootIndex(): void
    {
        $content = <<<'PHP'
<?php
declare(strict_types=1);
/**
 * Universal Router for Cimaise CMS
 * Auto-generated by installer for flexible deployment
 */

// Detect base path from SCRIPT_NAME
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$basePath = dirname($scriptName);
if ($basePath === '/' || $basePath === '\\' || $basePath === '.') {
    $basePath = '';
}

// Get request path
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$fullPath = parse_url($requestUri, PHP_URL_PATH);
if ($fullPath === '' || $fullPath === false) {
    $fullPath = '/';
}

// Remove base path to get relative path
$path = $fullPath;
if ($basePath !== '' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath));
    if ($path === '' || $path === false) {
        $path = '/';
    }
}
if (!str_starts_with($path, '/')) {
    $path = '/' . $path;
}

// Security: Block sensitive paths
$blockedPatterns = ['/^\/\./', '/\.sqlite$/i', '/\.log$/i', '/^\/vendor\//i',
    '/^\/storage\//i', '/^\/app\//i', '/^\/database\//i', '/^\/config\//i', '/^\/bin\//i'];
foreach ($blockedPatterns as $pattern) {
    if (preg_match($pattern, $path)) {
        http_response_code(403);
        exit('403 Forbidden');
    }
}

// MIME types for static files
$mimeTypes = [
    'js' => 'application/javascript', 'css' => 'text/css', 'json' => 'application/json',
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'avif' => 'image/avif', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
    'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'webmanifest' => 'application/manifest+json',
];

// Check for static file in public/ (except /media/* which needs PHP routing)
if (!preg_match('/^\/media\//', $path)) {
    $publicFile = __DIR__ . '/public' . $path;
    $realPath = realpath($publicFile);
    $publicDir = realpath(__DIR__ . '/public');

    if ($realPath !== false && $publicDir !== false && str_starts_with($realPath, $publicDir) && is_file($realPath)) {
        $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        if (isset($mimeTypes[$ext])) {
            header('Content-Type: ' . $mimeTypes[$ext]);
            header('Cache-Control: public, max-age=31536000, immutable');
            readfile($realPath);
            exit;
        }
    }
}

// Route through PHP application
chdir(__DIR__ . '/public');
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/public/index.php';
$_SERVER['SCRIPT_NAME'] = $basePath . '/index.php';
$_SERVER['PHP_SELF'] = $basePath . '/index.php';
require __DIR__ . '/public/index.php';
PHP;

        $targetPath = $this->rootPath . '/index.php';
        if (file_put_contents($targetPath, $content) === false) {
            throw new \RuntimeException('Failed to create root index.php');
        }
    }

    /**
     * Create universal root .htaccess
     */
    private function createUniversalRootHtaccess(): void
    {
        $content = <<<'HTACCESS'
# Universal .htaccess for Cimaise CMS
# Works with: subdomain, subdirectory, or root installation

<IfModule mod_rewrite.c>
    RewriteEngine On

    # Block sensitive files
    RewriteRule ^\.env$ - [F,L]
    RewriteRule \.sqlite$ - [F,L]
    RewriteRule \.log$ - [F,L]
    RewriteRule ^composer\.(json|lock)$ - [F,L]

    # Block sensitive directories
    RewriteRule ^vendor/ - [F,L]
    RewriteRule ^storage/ - [F,L]
    RewriteRule ^app/ - [F,L]
    RewriteRule ^database/ - [F,L]
    RewriteRule ^bin/ - [F,L]

    # Route everything through index.php
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>

<IfModule !mod_rewrite.c>
    DirectoryIndex index.php
    ErrorDocument 404 /index.php
</IfModule>

<FilesMatch "^\.|\.(?:env|sqlite|log)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
</FilesMatch>

Options -Indexes
AddDefaultCharset UTF-8
HTACCESS;

        $targetPath = $this->rootPath . '/.htaccess';
        if (file_put_contents($targetPath, $content) === false) {
            throw new \RuntimeException('Failed to create root .htaccess');
        }
    }

    /**
     * Auto-detect APP_URL based on current request
     *
     * Works with any installation type:
     * - Root installation: example.com/ → https://example.com
     * - Subdomain: photos.example.com/ → https://photos.example.com
     * - Subdirectory: example.com/portfolio/ → https://example.com/portfolio
     *
     * Also handles access through root index.php router or public/index.php directly
     */
    private function detectAppUrl(): string
    {
        // Detect protocol
        $protocol = 'http';
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            $protocol = 'https';
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            $protocol = 'https';
        } elseif (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https') {
            $protocol = 'https';
        }

        // Get host
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        // Remove port from host if standard
        $host = preg_replace('/:(80|443)$/', '', $host);

        // Detect base path from SCRIPT_NAME
        // Examples:
        //   Root/subdomain via public/: /public/index.php → basePath = ""
        //   Root/subdomain via root router: /index.php → basePath = ""
        //   Subdirectory via public/: /portfolio/public/index.php → basePath = "/portfolio"
        //   Subdirectory via root router: /portfolio/index.php → basePath = "/portfolio"
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $basePath = dirname($scriptName);

        // Normalize base path
        if ($basePath === '/' || $basePath === '\\' || $basePath === '.') {
            $basePath = '';
        }

        // Remove /public from the path if present (document root pointing to public/)
        if (str_ends_with($basePath, '/public')) {
            $basePath = substr($basePath, 0, -7);
        }

        // Build the URL
        $url = $protocol . '://' . $host;
        if ($basePath !== '') {
            $url .= $basePath;
        }

        return $url;
    }

    /**
     * Extract subdirectory path from APP_URL
     */
    private function extractSubdirectory(string $appUrl): string
    {
        if (empty($appUrl)) {
            return '';
        }

        $parsed = parse_url($appUrl);
        $path = $parsed['path'] ?? '';

        // Remove trailing slash and return
        return rtrim($path, '/');
    }

    /**
     * Update public/.htaccess with correct RewriteBase for subdirectory installations
     */
    private function updatePublicHtaccess(string $subdirectory): void
    {
        $htaccessPath = $this->rootPath . '/public/.htaccess';

        if (!file_exists($htaccessPath)) {
            return;
        }

        $content = file_get_contents($htaccessPath);
        if ($content === false) {
            return;
        }

        // Replace the commented RewriteBase line with the correct one
        $pattern = '/# RewriteBase is auto-detected.*?\n\s*# RewriteBase \/your-subdirectory\//s';
        $replacement = "# Subdirectory installation (auto-configured)\n  RewriteBase {$subdirectory}/public/";

        $newContent = preg_replace($pattern, $replacement, $content);

        // Defensive: preg_replace returns null on error
        if ($newContent === null) {
            error_log('Installer: preg_replace failed in updatePublicHtaccess');
            return;
        }

        // If pattern didn't match, try simpler replacement
        if ($newContent === $content) {
            $pattern = '/# RewriteBase \/your-subdirectory\//';
            $replacement = "RewriteBase {$subdirectory}/public/";
            $result = preg_replace($pattern, $replacement, $content);
            // Only update if preg_replace succeeded
            if ($result !== null) {
                $newContent = $result;
            }
        }

        if ($newContent !== $content && $newContent !== null) {
            if (file_put_contents($htaccessPath, $newContent) === false) {
                throw new \RuntimeException('Failed to write public/.htaccess file');
            }
        }
    }

    /**
     * Fix file and directory permissions after installation
     *
     * Sets appropriate permissions for:
     * - Directories: 755 (general) or 775 (writable)
     * - Files: 644 (general) or 664 (writable like .env, sqlite)
     */
    private function fixPermissions(): void
    {
        // Directories that need to be writable (775)
        $writableDirs = [
            'storage',
            'storage/cache',
            'storage/logs',
            'storage/tmp',
            'storage/translations',
            'storage/originals',
            'database',
            'public/media',
        ];

        // Files that need to be writable (664)
        $writableFiles = [
            '.env',
            'database/database.sqlite',
        ];

        // Create required directories if missing
        $requiredDirs = [
            'storage/cache',
            'storage/logs',
            'storage/tmp',
            'storage/translations',
            'storage/originals',
            'public/media',
        ];

        foreach ($requiredDirs as $dir) {
            $fullPath = $this->rootPath . '/' . $dir;
            if (!is_dir($fullPath)) {
                $created = mkdir($fullPath, 0775, true);
                if (!$created && !is_dir($fullPath)) {
                    Logger::warning('Installer: Failed to create directory', [
                        'path' => $fullPath,
                        'perm' => sprintf('%o', 0775),
                    ], 'installer');
                }
            }
        }

        // Fix permissions recursively
        $this->fixPermissionsRecursive(
            $this->rootPath,
            $writableDirs,
            $writableFiles,
            0,
            10
        );
    }

    /**
     * Recursively fix permissions for a path
     */
    private function fixPermissionsRecursive(
        string $path,
        array $writableDirs,
        array $writableFiles,
        int $depth = 0,
        int $maxDepth = 10
    ): void {
        if (!file_exists($path)) {
            return;
        }

        $relativePath = $path === $this->rootPath
            ? ''
            : ltrim(str_replace($this->rootPath, '', $path), '/');

        if (is_dir($path)) {
            if ($depth > $maxDepth) {
                Logger::warning('Installer: Permission scan depth limit reached', [
                    'path' => $relativePath,
                    'max_depth' => $maxDepth,
                ], 'installer');
                return;
            }

            // Check if this directory should be writable
            $isWritable = false;
            foreach ($writableDirs as $dir) {
                if ($relativePath === $dir || str_starts_with($relativePath, $dir . '/')) {
                    $isWritable = true;
                    break;
                }
            }

            $targetPerm = $isWritable ? 0775 : 0755;
            if (!chmod($path, $targetPerm)) {
                Logger::warning('Installer: Failed to set directory permissions', [
                    'path' => $path,
                    'perm' => sprintf('%o', $targetPerm),
                ], 'installer');
            }

            // Skip heavy directories
            if ($relativePath === 'vendor' || str_starts_with($relativePath, 'storage/originals')) {
                return;
            }

            // Process contents
            $items = scandir($path);
            if ($items === false) {
                Logger::warning('Installer: Failed to scan directory', [
                    'path' => $path,
                ], 'installer');
                return;
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $this->fixPermissionsRecursive(
                    $path . '/' . $item,
                    $writableDirs,
                    $writableFiles,
                    $depth + 1,
                    $maxDepth
                );
            }
        } else {
            // It's a file
            $isWritable = in_array($relativePath, $writableFiles, true);

            // SQLite files in database/ should be writable
            if (str_starts_with($relativePath, 'database/') &&
                pathinfo($path, PATHINFO_EXTENSION) === 'sqlite') {
                $isWritable = true;
            }

            // Log files should be writable
            if (pathinfo($path, PATHINFO_EXTENSION) === 'log') {
                $isWritable = true;
            }

            $targetPerm = $isWritable ? 0664 : 0644;
            if (!chmod($path, $targetPerm)) {
                Logger::warning('Installer: Failed to set file permissions', [
                    'path' => $path,
                    'perm' => sprintf('%o', $targetPerm),
                ], 'installer');
            }
        }
    }

    /**
     * Get installation errors for display (public for use by controller)
     */
    public function getRequirementsErrors(array $data = []): array
    {
        return $this->collectRequirementErrors($data, false);
    }

    /**
     * Get installation warnings for display
     */
    public function getRequirementsWarnings(): array
    {
        $warnings = [];

        $recommendedExtensions = ['exif', 'curl', 'imagick'];
        foreach ($recommendedExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $warnings[] = "Recommended PHP extension '{$ext}' is not installed (optional but improves functionality)";
            }
        }

        $freeSpace = @disk_free_space($this->rootPath . '/storage');
        if ($freeSpace !== false && $freeSpace < 500 * 1024 * 1024) {
            $warnings[] = 'Low disk space. Consider having at least 500MB free for storing images.';
        }

        return $warnings;
    }
}
