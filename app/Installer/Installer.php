<?php
declare(strict_types=1);

namespace App\Installer;

use App\Support\Database;

class Installer
{
    private ?Database $db = null;
    private array $config = [];
    private string $rootPath;
    private bool $envWritten = false;
    private bool $dbCreated = false;
    private ?string $createdDbPath = null;

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
                    database: $this->config['DB_DATABASE'] ?? 'photocms',
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

        try {
            $this->verifyRequirements($data);
            $this->setupDatabase($data);
            $this->installSchema();
            $this->createFirstUser($data);
            $this->updateSiteSettings($data);
            $this->createEnvFile($data);
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
                    'frontend_texts'
                ];
                foreach ($tablesToDrop as $table) {
                    $this->db->pdo()->exec("DROP TABLE IF EXISTS `{$table}`");
                }
            } catch (\Throwable) {
                // Ignore cleanup errors
            }
        }
    }

    private function verifyRequirements(array $data = []): void
    {
        $errors = $this->collectRequirementErrors($data, true);

        if (!empty($errors)) {
            throw new \RuntimeException(implode("\n", $errors));
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

        if (version_compare(PHP_VERSION, '8.0.0', '<')) {
            $errors[] = 'PHP 8.0 or higher is required. Current version: ' . PHP_VERSION;
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
            $dbPath = $data['db_database'] ?? ($this->rootPath . '/database/database.sqlite');

            // Handle relative paths
            if (!str_starts_with($dbPath, '/')) {
                $dbPath = $this->rootPath . '/' . $dbPath;
            }

            // Normalize the path - handle case where directory doesn't exist yet
            $resolvedDir = realpath(dirname($dbPath));
            if ($resolvedDir === false) {
                // Directory doesn't exist yet, use original path
                $dbPath = $this->rootPath . '/database/database.sqlite';
            } else {
                $dbPath = $resolvedDir . '/' . basename($dbPath);
            }

            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0755, true)) {
                    throw new \RuntimeException("Cannot create database directory: {$dir}");
                }
            }
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
            $database = $data['db_database'] ?? 'photocms';
            $username = $data['db_username'] ?? 'root';
            $password = $data['db_password'] ?? '';
            // Use consistent charset/collation (match runtime Database class)
            $charset = $data['db_charset'] ?? 'utf8mb4';
            $collation = $data['db_collation'] ?? 'utf8mb4_0900_ai_ci';

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
            // SQLite: copy template database
            $templatePath = $this->rootPath . '/database/template.sqlite';
            $targetPath = $this->createdDbPath ?? ($this->rootPath . '/database/database.sqlite');

            if (!file_exists($templatePath)) {
                throw new \RuntimeException('SQLite template database not found. Please ensure database/template.sqlite exists.');
            }

            if (file_exists($targetPath)) {
                unlink($targetPath);
            }

            if (!copy($templatePath, $targetPath)) {
                throw new \RuntimeException('Failed to copy SQLite template database');
            }

            $this->db = new Database(database: $targetPath, isSqlite: true);
            $this->createdDbPath = $targetPath;
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

    private function updateSiteSettings(array $data): void
    {
        // Validate and sanitize language and date format
        $language = preg_replace('/[^a-z0-9_-]/i', '', (string)($data['site_language'] ?? 'en')) ?: 'en';
        $dateFormat = in_array($data['date_format'] ?? 'Y-m-d', ['Y-m-d', 'd-m-Y'], true)
            ? $data['date_format']
            : 'Y-m-d';

        $settings = [
            'site.title' => $data['site_title'] ?? 'photoCMS',
            'site.description' => $data['site_description'] ?? 'Professional Photography Portfolio',
            'site.copyright' => $data['site_copyright'] ?? '© ' . date('Y') . ' Photography Portfolio',
            'site.email' => $data['site_email'] ?? '',
            'site.language' => $language,
            'date.format' => $dateFormat,
        ];

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

    private function createEnvFile(array $data): void
    {
        $connection = $data['db_connection'] ?? 'sqlite';

        $envContent = "APP_ENV=production\n";
        $envContent .= "APP_DEBUG=false\n";
        $envContent .= "APP_URL=" . ($data['app_url'] ?? 'http://localhost') . "\n";
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
            $envContent .= "DB_DATABASE=" . ($data['db_database'] ?? 'photocms') . "\n";
            $envContent .= "DB_USERNAME=" . ($data['db_username'] ?? 'root') . "\n";
            $envContent .= "DB_PASSWORD=" . ($data['db_password'] ?? '') . "\n";
            // Use consistent charset/collation
            $envContent .= "DB_CHARSET=" . ($data['db_charset'] ?? 'utf8mb4') . "\n";
            $envContent .= "DB_COLLATION=" . ($data['db_collation'] ?? 'utf8mb4_0900_ai_ci') . "\n";
        }

        $sessionSecret = bin2hex(random_bytes(32));
        $envContent .= "\nSESSION_SECRET=" . $sessionSecret . "\n";
        $envContent .= "\n# Fast upload mode\nFAST_UPLOAD=true\n";

        $envFilePath = $this->rootPath . '/.env';
        if (file_put_contents($envFilePath, $envContent) === false) {
            throw new \RuntimeException('Failed to write .env file');
        }
        $this->envWritten = true;
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
