<?php
declare(strict_types=1);

namespace App\Installer;

use App\Support\Database;
use PDO;
use RuntimeException;

class Installer
{
    private Database $db;
    private array $config = [];
    private string $rootPath;
    
    public function __construct(string $rootPath)
    {
        $this->rootPath = $rootPath;
    }
    
    /**
     * Check if the application is already installed
     */
    public function isInstalled(): bool
    {
        // Check if .env file exists (not .env.example)
        if (!file_exists($this->rootPath . '/.env')) {
            return false;
        }
        
        // Load environment variables from .env
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
        
        // Check if database connection can be established
        try {
            $connection = $this->config['DB_CONNECTION'] ?? 'sqlite';
            
            if ($connection === 'sqlite') {
                $dbPath = $this->config['DB_DATABASE'] ?? $this->rootPath . '/database/database.sqlite';
                
                // If the path is relative, make it absolute relative to project root
                if (!str_starts_with($dbPath, '/')) {
                    $dbPath = $this->rootPath . '/' . $dbPath;
                }
                
                if (!file_exists($dbPath)) {
                    return false;
                }
                
                $this->db = new Database(database: $dbPath, isSqlite: true);
            } else {
                // MySQL connection
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
            
            // Check if essential tables exist
            $tables = $this->getExistingTables();
            $requiredTables = ['users', 'settings', 'templates', 'categories'];
            
            foreach ($requiredTables as $table) {
                if (!in_array($table, $tables)) {
                    return false;
                }
            }
            
            // Check if there are any users
            $stmt = $this->db->pdo()->query('SELECT COUNT(*) as count FROM users');
            $result = $stmt->fetch();
            if ($result['count'] == 0) {
                return false;
            }
            
            return true;
        } catch (\Throwable $e) {
            // If we can't connect to the database, it's not installed
            return false;
        }
    }
    
    /**
     * Get list of existing tables
     */
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
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    /**
     * Run the installation process
     */
    public function install(array $data): bool
    {
        try {
            error_log('Installer: Starting installation process with data: ' . print_r($data, true));
            
            // 1. Verify requirements
            error_log('Installer: Verifying requirements');
            $this->verifyRequirements();
            error_log('Installer: Requirements verified');
            
            // 2. Create database connection
            error_log('Installer: Setting up database');
            $this->setupDatabase($data);
            error_log('Installer: Database setup completed');
            
            // 3. Run migrations
            error_log('Installer: Running migrations');
            $templateCopied = $this->runMigrations();
            error_log('Installer: Migrations completed');
            
            // 4. Seed default data (only if template wasn't copied)
            if (!$templateCopied) {
                error_log('Installer: Seeding data');
                $this->seedData();
                error_log('Installer: Seeding completed');
            } else {
                error_log('Installer: Skipping seeding - template database already contains all data');
            }
            
            // 5. Create first user
            error_log('Installer: Creating first user');
            $this->createFirstUser($data);
            error_log('Installer: First user created');
            
            // 6. Set application settings
            error_log('Installer: Setting application settings');
            $this->setApplicationSettings($data);
            error_log('Installer: Application settings set');
            
            // 7. Create .env file
            error_log('Installer: Creating .env file');
            $this->createEnvFile($data);
            error_log('Installer: .env file created');
            
            error_log('Installer: Installation process completed successfully');
            return true;
        } catch (\Throwable $e) {
            error_log('Installation failed: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            throw $e;
        }
    }
    
    /**
     * Verify system requirements
     */
    private function verifyRequirements(): array
    {
        error_log('Installer: Checking system requirements');
        $errors = [];
        
        // Check PHP version
        if (version_compare(PHP_VERSION, '8.0.0', '<')) {
            $errors[] = 'PHP 8.0 or higher is required. Current version: ' . PHP_VERSION;
            error_log('Installer: PHP version error: ' . $errors[count($errors)-1]);
        }
        
        // Check required extensions
        $requiredExtensions = ['pdo', 'gd', 'mbstring', 'openssl'];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $errors[] = "Required PHP extension '{$ext}' is not installed";
                error_log('Installer: Extension error: ' . $errors[count($errors)-1]);
            }
        }
        
        // Check if either PDO MySQL or PDO SQLite is available
        if (!extension_loaded('pdo_mysql') && !extension_loaded('pdo_sqlite')) {
            $errors[] = 'Either PDO MySQL or PDO SQLite extension is required';
            error_log('Installer: Database extension error: ' . $errors[count($errors)-1]);
        }
        
        // Check write permissions
        $writablePaths = [
            $this->rootPath . '/.env',
            $this->rootPath . '/database',
            $this->rootPath . '/storage',
            $this->rootPath . '/public/media'
        ];
        
        foreach ($writablePaths as $path) {
            $dir = dirname($path);
            if (!is_writable($dir)) {
                $errors[] = "Directory '" . $dir . "' is not writable";
                error_log('Installer: Permission error: ' . $errors[count($errors)-1]);
            } else {
                error_log('Installer: Directory ' . $dir . ' is writable');
            }
        }
        
        error_log('Installer: Requirements check completed. Errors found: ' . count($errors));
        if (!empty($errors)) {
            error_log('Installer: Requirement errors: ' . print_r($errors, true));
        }
        
        return $errors;
    }
    
    /**
     * Setup database connection
     */
    private function setupDatabase(array $data): void
    {
        error_log('Installer: Setting up database with data: ' . print_r($data, true));
        $connection = $data['db_connection'] ?? 'sqlite';
        error_log('Installer: Database connection type: ' . $connection);
        
        if ($connection === 'sqlite') {
            // Use absolute path for SQLite database
            $dbPath = $this->rootPath . '/database/database.sqlite';
            error_log('Installer: SQLite database path: ' . $dbPath);
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                error_log('Installer: Creating directory: ' . $dir);
                mkdir($dir, 0755, true);
            }
            
            // Ensure the database file exists (create empty file if needed)
            if (!file_exists($dbPath)) {
                error_log('Installer: Creating empty database file: ' . $dbPath);
                touch($dbPath);
            }
            
            error_log('Installer: Creating Database instance for SQLite');
            $this->db = new Database(database: $dbPath, isSqlite: true);
        } else {
            // MySQL connection
            error_log('Installer: Creating Database instance for MySQL');
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
        error_log('Installer: Database setup completed');
    }
    
    /**
     * Run database migrations
     */
    private function runMigrations(): bool
    {
        error_log('Installer: Setting up database');
        
        if ($this->db->isSqlite()) {
            // For SQLite, copy the template database
            $templatePath = $this->rootPath . '/database/template.sqlite';
            $targetPath = $this->rootPath . '/database/database.sqlite';
            
            if (file_exists($templatePath)) {
                error_log('Installer: Template database found at: ' . $templatePath);
                error_log('Installer: Template database size: ' . filesize($templatePath) . ' bytes');
                
                // Remove existing empty database file if it exists
                if (file_exists($targetPath)) {
                    error_log('Installer: Removing existing empty database file');
                    unlink($targetPath);
                }
                
                error_log('Installer: Copying template database from: ' . $templatePath . ' to: ' . $targetPath);
                if (copy($templatePath, $targetPath)) {
                    error_log('Installer: Template database copied successfully');
                    error_log('Installer: Copied database size: ' . filesize($targetPath) . ' bytes');
                    
                    // Verify the copy was successful
                    if (filesize($targetPath) > 0) {
                        // Reconnect to the new database
                        $this->db = new Database(database: $targetPath, isSqlite: true);
                        
                        // Test connection
                        try {
                            $stmt = $this->db->pdo()->query("SELECT name FROM sqlite_master WHERE type='table' LIMIT 1");
                            error_log('Installer: Database connection test successful');
                            return true; // Template copied successfully
                        } catch (\Throwable $e) {
                            error_log('Installer: Database connection test failed: ' . $e->getMessage());
                            // Fall through to migrations
                        }
                    } else {
                        error_log('Installer: Copied database is empty, falling back to migrations');
                    }
                } else {
                    error_log('Installer: Failed to copy template database, falling back to migrations');
                }
            } else {
                error_log('Installer: Template database not found at: ' . $templatePath . ', running migrations');
            }
        }
        
        // Fallback to original migration system for MySQL or if template copy fails
        $this->runLegacyMigrations();
        return false; // Used migrations, not template
    }
    
    private function runLegacyMigrations(): void
    {
        error_log('Installer: Running legacy migrations');
        
        $migrationDir = $this->db->isSqlite() 
            ? $this->rootPath . '/database/migrations/sqlite'
            : $this->rootPath . '/database/migrations';
        error_log('Installer: Migration directory: ' . $migrationDir);
            
        if (!is_dir($migrationDir)) {
            error_log('Installer: Migration directory not found');
            throw new RuntimeException("Migration directory not found: {$migrationDir}");
        }
        
        $migrations = glob($migrationDir . '/*.sql');
        error_log('Installer: Found migrations: ' . print_r($migrations, true));
        sort($migrations);
        
        foreach ($migrations as $migration) {
            error_log('Installer: Executing migration: ' . $migration);
            try {
                $this->db->execSqlFile($migration);
                error_log('Installer: Migration executed successfully: ' . $migration);
            } catch (\Throwable $e) {
                error_log('Installer: Migration failed: ' . $migration . ' - Error: ' . $e->getMessage());
                // Skip duplicate column errors for MySQL
                if (!$this->db->isMySQL() || strpos($e->getMessage(), 'Duplicate column name') === false) {
                    throw new RuntimeException("Failed to execute migration {$migration}: " . $e->getMessage());
                }
            }
        }
        error_log('Installer: All migrations completed');
    }
    
    /**
     * Seed default data
     */
    private function seedData(): void
    {
        error_log('Installer: Seeding data');
        $seedDir = $this->db->isSqlite() 
            ? $this->rootPath . '/database/seeds/sqlite'
            : $this->rootPath . '/database/seeds';
        error_log('Installer: Seed directory: ' . $seedDir);
            
        if (!is_dir($seedDir)) {
            error_log('Installer: Seed directory not found');
            throw new RuntimeException("Seed directory not found: {$seedDir}");
        }
        
        // Run templates seed first
        $templatesSeed = $seedDir . '/0003_templates.sql';
        error_log('Installer: Templates seed file: ' . $templatesSeed);
        if (file_exists($templatesSeed)) {
            error_log('Installer: Executing templates seed');
            $this->db->execSqlFile($templatesSeed);
            error_log('Installer: Templates seed executed successfully');
        } else {
            error_log('Installer: Templates seed file not found');
        }
        
        // Run other essential seeds
        $essentialSeeds = [
            '0001_minimal.sql',
            '0002_lookups.sql'
        ];
        
        foreach ($essentialSeeds as $seedFile) {
            $seedPath = $seedDir . '/' . $seedFile;
            error_log('Installer: Essential seed file: ' . $seedPath);
            if (file_exists($seedPath)) {
                error_log('Installer: Executing essential seed: ' . $seedFile);
                $this->db->execSqlFile($seedPath);
                error_log('Installer: Essential seed executed successfully: ' . $seedFile);
            } else {
                error_log('Installer: Essential seed file not found: ' . $seedFile);
            }
        }
        error_log('Installer: All seeding completed');
    }
    
    /**
     * Create the first admin user
     */
    private function createFirstUser(array $data): void
    {
        error_log('Installer: Creating first user with data: ' . print_r($data, true));
        
        // Check if users already exist
        $stmt = $this->db->pdo()->query('SELECT COUNT(*) as count FROM users');
        $result = $stmt->fetch();
        if ($result['count'] > 0) {
            error_log('Installer: Users already exist, skipping user creation');
            return;
        }
        
        $password = password_hash($data['admin_password'], PASSWORD_DEFAULT);
        error_log('Installer: Password hashed');
        $createdAt = date('Y-m-d H:i:s');
        error_log('Installer: Created at: ' . $createdAt);
        
        if ($this->db->isSqlite()) {
            error_log('Installer: Inserting user into SQLite database');
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO users (email, password_hash, role, created_at, first_name, last_name, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $result = $stmt->execute([
                $data['admin_email'],
                $password,
                'admin',
                $createdAt,
                $data['admin_name'] ?? 'Admin',
                '',
                1
            ]);
            error_log('Installer: User insertion result: ' . ($result ? 'true' : 'false'));
        } else {
            error_log('Installer: Inserting user into MySQL database');
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO users (email, password_hash, role, created_at, first_name, last_name, is_active) VALUES (:email, :password, :role, :created_at, :first_name, :last_name, :is_active)'
            );
            $result = $stmt->execute([
                ':email' => $data['admin_email'],
                ':password' => $password,
                ':role' => 'admin',
                ':created_at' => $createdAt,
                ':first_name' => $data['admin_name'] ?? 'Admin',
                ':last_name' => '',
                ':is_active' => 1
            ]);
            error_log('Installer: User insertion result: ' . ($result ? 'true' : 'false'));
        }
        error_log('Installer: First user created successfully');
    }
    
    /**
     * Set application settings
     */
    private function setApplicationSettings(array $data): void
    {
        error_log('Installer: Setting application settings with data: ' . print_r($data, true));
        $settings = [
            'site.title' => $data['site_title'] ?? 'photoCMS',
            'site.description' => $data['site_description'] ?? 'Professional Photography Portfolio',
            'site.copyright' => $data['site_copyright'] ?? '© ' . date('Y') . ' Photography Portfolio',
            'site.email' => $data['site_email'] ?? '',
            'gallery.default_template_id' => null,
            'image.formats' => ['avif' => true, 'webp' => true, 'jpg' => true],
            'image.quality' => ['avif' => 50, 'webp' => 75, 'jpg' => 85],
            'image.breakpoints' => ['sm' => 768, 'md' => 1200, 'lg' => 1920, 'xl' => 2560, 'xxl' => 3840],
            'image.preview' => ['width' => 480, 'height' => null],
            'performance.compression' => true,
            'pagination.limit' => 12,
            'cache.ttl' => 24,
        ];
        
        foreach ($settings as $key => $value) {
            error_log('Installer: Setting key: ' . $key . ' with value: ' . print_r($value, true));
            $encodedValue = json_encode($value, JSON_UNESCAPED_SLASHES);
            $type = is_null($value) ? 'null' : (is_bool($value) ? 'boolean' : (is_numeric($value) ? 'number' : 'string'));
            error_log('Installer: Encoded value: ' . $encodedValue . ', type: ' . $type);
            
            if ($this->db->isSqlite()) {
                error_log('Installer: Inserting setting into SQLite database');
                $stmt = $this->db->pdo()->prepare(
                    'INSERT OR REPLACE INTO settings (key, value, type, created_at, updated_at) VALUES (?, ?, ?, datetime(\'now\'), datetime(\'now\'))'
                );
                $result = $stmt->execute([$key, $encodedValue, $type]);
                error_log('Installer: Setting insertion result: ' . ($result ? 'true' : 'false'));
            } else {
                error_log('Installer: Inserting setting into MySQL database');
                $stmt = $this->db->pdo()->prepare(
                    'INSERT INTO settings (key, value, type, created_at, updated_at) VALUES (:key, :value, :type, NOW(), NOW()) ON DUPLICATE KEY UPDATE value = :value2, type = :type2, updated_at = NOW()'
                );
                $result = $stmt->execute([
                    ':key' => $key,
                    ':value' => $encodedValue,
                    ':type' => $type,
                    ':value2' => $encodedValue,
                    ':type2' => $type
                ]);
                error_log('Installer: Setting insertion result: ' . ($result ? 'true' : 'false'));
            }
        }
        
        // Create the first category
        error_log('Installer: Creating first category');
        if ($this->db->isSqlite()) {
            error_log('Installer: Inserting category into SQLite database');
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO categories (name, slug, sort_order, created_at) VALUES (?, ?, ?, datetime(\'now\'))'
            );
            $result = $stmt->execute(['Foto', 'foto', 0]);
            error_log('Installer: Category insertion result: ' . ($result ? 'true' : 'false'));
        } else {
            error_log('Installer: Inserting category into MySQL database');
            $stmt = $this->db->pdo()->prepare(
                'INSERT INTO categories (name, slug, sort_order, created_at) VALUES (:name, :slug, :sort_order, NOW())'
            );
            $result = $stmt->execute([
                ':name' => 'Foto',
                ':slug' => 'foto',
                ':sort_order' => 0
            ]);
            error_log('Installer: Category insertion result: ' . ($result ? 'true' : 'false'));
        }
        error_log('Installer: Application settings set successfully');
    }
    
    /**
     * Create .env file
     */
    private function createEnvFile(array $data): void
    {
        error_log('Installer: Creating .env file with data: ' . print_r($data, true));
        $connection = $data['db_connection'] ?? 'sqlite';
        error_log('Installer: Database connection type: ' . $connection);
        
        $envContent = "APP_ENV=production\n";
        $envContent .= "APP_DEBUG=false\n";
        $envContent .= "APP_URL=" . ($data['app_url'] ?? 'http://localhost') . "\n";
        $envContent .= "APP_TIMEZONE=UTC\n\n";
        
        $envContent .= "DB_CONNECTION={$connection}\n";
        
        if ($connection === 'sqlite') {
            // Use relative path for .env file
            $envContent .= "DB_DATABASE=database/database.sqlite\n";
        } else {
            error_log('Installer: MySQL database configuration');
            $envContent .= "DB_HOST=" . ($data['db_host'] ?? '127.0.0.1') . "\n";
            $envContent .= "DB_PORT=" . ($data['db_port'] ?? '3306') . "\n";
            $envContent .= "DB_DATABASE=" . ($data['db_database'] ?? 'photocms') . "\n";
            $envContent .= "DB_USERNAME=" . ($data['db_username'] ?? 'root') . "\n";
            $envContent .= "DB_PASSWORD=" . ($data['db_password'] ?? '') . "\n";
            $envContent .= "DB_CHARSET=" . ($data['db_charset'] ?? 'utf8mb4') . "\n";
            $envContent .= "DB_COLLATION=" . ($data['db_collation'] ?? 'utf8mb4_unicode_ci') . "\n";
        }
        
        $sessionSecret = bin2hex(random_bytes(32));
        error_log('Installer: Session secret generated: ' . $sessionSecret);
        $envContent .= "\nSESSION_SECRET=" . $sessionSecret . "\n";
        
        $envFilePath = $this->rootPath . '/.env';
        error_log('Installer: Writing .env file to: ' . $envFilePath);
        error_log('Installer: .env content: ' . $envContent);
        
        $result = file_put_contents($envFilePath, $envContent);
        if ($result === false) {
            error_log('Installer: Failed to write .env file');
            throw new RuntimeException('Failed to write .env file');
        }
        
        error_log('Installer: .env file created successfully with ' . $result . ' bytes written');
    }
}