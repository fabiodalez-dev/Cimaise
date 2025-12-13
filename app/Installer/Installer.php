<?php
declare(strict_types=1);

namespace App\Installer;

use App\Support\Database;

class Installer
{
    private Database $db;
    private array $config = [];
    private string $rootPath;

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
        try {
            $this->verifyRequirements();
            $this->setupDatabase($data);
            $this->installSchema();
            $this->createFirstUser($data);
            $this->updateSiteSettings($data);
            $this->createEnvFile($data);
            return true;
        } catch (\Throwable $e) {
            error_log('Installation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function verifyRequirements(): void
    {
        $errors = [];

        if (version_compare(PHP_VERSION, '8.0.0', '<')) {
            $errors[] = 'PHP 8.0 or higher is required';
        }

        $requiredExtensions = ['pdo', 'gd', 'mbstring', 'openssl'];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $errors[] = "PHP extension '{$ext}' is not installed";
            }
        }

        if (!extension_loaded('pdo_mysql') && !extension_loaded('pdo_sqlite')) {
            $errors[] = 'Either PDO MySQL or PDO SQLite extension is required';
        }

        $writablePaths = [
            $this->rootPath . '/database',
            $this->rootPath . '/storage',
            $this->rootPath . '/public/media'
        ];

        foreach ($writablePaths as $path) {
            $dir = is_dir($path) ? $path : dirname($path);
            if (!is_writable($dir)) {
                $errors[] = "Directory '{$dir}' is not writable";
            }
        }

        if (!empty($errors)) {
            throw new RuntimeException(implode("\n", $errors));
        }
    }

    private function setupDatabase(array $data): void
    {
        $connection = $data['db_connection'] ?? 'sqlite';

        if ($connection === 'sqlite') {
            $dbPath = $this->rootPath . '/database/database.sqlite';
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            if (file_exists($dbPath)) {
                unlink($dbPath);
            }
            touch($dbPath);
            $this->db = new Database(database: $dbPath, isSqlite: true);
        } else {
            $this->db = new Database(
                host: $data['db_host'] ?? '127.0.0.1',
                port: (int)($data['db_port'] ?? 3306),
                database: $data['db_database'] ?? 'photocms',
                username: $data['db_username'] ?? 'root',
                password: $data['db_password'] ?? '',
                charset: $data['db_charset'] ?? 'utf8mb4',
                collation: $data['db_collation'] ?? 'utf8mb4_unicode_ci',
            );
        }
    }

    private function installSchema(): void
    {
        if ($this->db->isSqlite()) {
            // SQLite: copy template database
            $templatePath = $this->rootPath . '/database/template.sqlite';
            $targetPath = $this->rootPath . '/database/database.sqlite';

            if (!file_exists($templatePath)) {
                throw new RuntimeException('SQLite template database not found');
            }

            if (file_exists($targetPath)) {
                unlink($targetPath);
            }

            if (!copy($templatePath, $targetPath)) {
                throw new RuntimeException('Failed to copy SQLite template database');
            }

            $this->db = new Database(database: $targetPath, isSqlite: true);
        } else {
            // MySQL: execute schema file
            $schemaPath = $this->rootPath . '/database/schema.mysql.sql';

            if (!file_exists($schemaPath)) {
                throw new RuntimeException('MySQL schema file not found');
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

        $password = password_hash($data['admin_password'], PASSWORD_DEFAULT);
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
        $settings = [
            'site.title' => $data['site_title'] ?? 'photoCMS',
            'site.description' => $data['site_description'] ?? 'Professional Photography Portfolio',
            'site.copyright' => $data['site_copyright'] ?? '© ' . date('Y') . ' Photography Portfolio',
            'site.email' => $data['site_email'] ?? '',
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
            $envContent .= "DB_DATABASE=database/database.sqlite\n";
        } else {
            $envContent .= "DB_HOST=" . ($data['db_host'] ?? '127.0.0.1') . "\n";
            $envContent .= "DB_PORT=" . ($data['db_port'] ?? '3306') . "\n";
            $envContent .= "DB_DATABASE=" . ($data['db_database'] ?? 'photocms') . "\n";
            $envContent .= "DB_USERNAME=" . ($data['db_username'] ?? 'root') . "\n";
            $envContent .= "DB_PASSWORD=" . ($data['db_password'] ?? '') . "\n";
            $envContent .= "DB_CHARSET=" . ($data['db_charset'] ?? 'utf8mb4') . "\n";
            $envContent .= "DB_COLLATION=" . ($data['db_collation'] ?? 'utf8mb4_unicode_ci') . "\n";
        }

        $sessionSecret = bin2hex(random_bytes(32));
        $envContent .= "\nSESSION_SECRET=" . $sessionSecret . "\n";
        $envContent .= "\n# Fast upload mode\nFAST_UPLOAD=true\n";

        $envFilePath = $this->rootPath . '/.env';
        if (file_put_contents($envFilePath, $envContent) === false) {
            throw new RuntimeException('Failed to write .env file');
        }
    }
}
