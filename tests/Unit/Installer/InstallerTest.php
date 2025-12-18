<?php
declare(strict_types=1);

namespace Tests\Unit\Installer;

use App\Installer\Installer;
use PHPUnit\Framework\TestCase;

class InstallerTest extends TestCase
{
    private string $testRootPath;
    private Installer $installer;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create a temporary test root directory
        $this->testRootPath = sys_get_temp_dir() . '/installer_test_' . uniqid();
        mkdir($this->testRootPath, 0755, true);
        
        $this->installer = new Installer($this->testRootPath);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up test directory
        if (is_dir($this->testRootPath)) {
            $this->recursiveRemoveDirectory($this->testRootPath);
        }
    }

    private function recursiveRemoveDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursiveRemoveDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testIsInstalledReturnsFalseWhenEnvDoesNotExist(): void
    {
        $result = $this->installer->isInstalled();
        
        $this->assertFalse($result);
    }

    public function testIsInstalledReturnsFalseWhenEnvIsEmpty(): void
    {
        // Create empty .env file
        file_put_contents($this->testRootPath . '/.env', '');
        
        $result = $this->installer->isInstalled();
        
        $this->assertFalse($result);
    }

    public function testIsInstalledReturnsFalseWhenDatabaseDoesNotExist(): void
    {
        // Create .env with SQLite config but no database file
        $envContent = "DB_CONNECTION=sqlite\nDB_DATABASE={$this->testRootPath}/database/database.sqlite";
        file_put_contents($this->testRootPath . '/.env', $envContent);
        
        $result = $this->installer->isInstalled();
        
        $this->assertFalse($result);
    }

    public function testIsInstalledParsesEnvFile(): void
    {
        // Create .env with various settings
        $envContent = "DB_CONNECTION=sqlite\nDB_DATABASE={$this->testRootPath}/database/database.sqlite\nAPP_NAME=TestApp\n# Comment line\nSOME_KEY=some_value";
        file_put_contents($this->testRootPath . '/.env', $envContent);
        
        // Should parse without errors
        $result = $this->installer->isInstalled();
        
        // Will be false because DB doesn't exist, but parsing succeeded
        $this->assertFalse($result);
    }

    public function testIsInstalledHandlesSqlitePath(): void
    {
        // Create database directory and file
        $dbDir = $this->testRootPath . '/database';
        mkdir($dbDir, 0755, true);
        $dbPath = $dbDir . '/database.sqlite';
        
        // Create empty SQLite database
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT)');
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE templates (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY)');
        $pdo->exec("INSERT INTO users (role) VALUES ('admin')");
        $pdo = null;
        
        $envContent = "DB_CONNECTION=sqlite\nDB_DATABASE=database/database.sqlite";
        file_put_contents($this->testRootPath . '/.env', $envContent);
        
        $result = $this->installer->isInstalled();
        
        $this->assertTrue($result);
    }

    public function testIsInstalledHandlesAbsoluteSqlitePath(): void
    {
        // Create database directory and file
        $dbDir = $this->testRootPath . '/database';
        mkdir($dbDir, 0755, true);
        $dbPath = $dbDir . '/database.sqlite';
        
        // Create empty SQLite database with required tables
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT)');
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE templates (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY)');
        $pdo->exec("INSERT INTO users (role) VALUES ('admin')");
        $pdo = null;
        
        $envContent = "DB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}";
        file_put_contents($this->testRootPath . '/.env', $envContent);
        
        $result = $this->installer->isInstalled();
        
        $this->assertTrue($result);
    }

    public function testIsInstalledReturnsFalseWhenRequiredTablesAreMissing(): void
    {
        // Create database directory and file
        $dbDir = $this->testRootPath . '/database';
        mkdir($dbDir, 0755, true);
        $dbPath = $dbDir . '/database.sqlite';
        
        // Create SQLite database with only some tables
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT)');
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY)');
        // Missing templates and categories tables
        $pdo->exec("INSERT INTO users (role) VALUES ('admin')");
        $pdo = null;
        
        $envContent = "DB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}";
        file_put_contents($this->testRootPath . '/.env', $envContent);
        
        $result = $this->installer->isInstalled();
        
        $this->assertFalse($result);
    }

    public function testIsInstalledReturnsFalseWhenNoAdminUserExists(): void
    {
        // Create database directory and file
        $dbDir = $this->testRootPath . '/database';
        mkdir($dbDir, 0755, true);
        $dbPath = $dbDir . '/database.sqlite';
        
        // Create SQLite database with tables but no admin user
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT)');
        $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE templates (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY)');
        // Don't insert any users
        $pdo = null;
        
        $envContent = "DB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}";
        file_put_contents($this->testRootPath . '/.env', $envContent);
        
        $result = $this->installer->isInstalled();
        
        $this->assertFalse($result);
    }

    public function testIsInstalledHandlesEnvWithComments(): void
    {
        $envContent = "# This is a comment\nDB_CONNECTION=sqlite\n# Another comment\nDB_DATABASE={$this->testRootPath}/database/database.sqlite";
        file_put_contents($this->testRootPath . '/.env', $envContent);
        
        $result = $this->installer->isInstalled();
        
        // Will be false because DB doesn't exist, but parsing should work
        $this->assertFalse($result);
    }

    public function testIsInstalledHandlesExceptionGracefully(): void
    {
        // Create .env with invalid database config
        $envContent = "DB_CONNECTION=mysql\nDB_HOST=invalid-host-that-does-not-exist\nDB_PORT=3306\nDB_DATABASE=invalid_db\nDB_USERNAME=invalid_user\nDB_PASSWORD=invalid_pass";
        file_put_contents($this->testRootPath . '/.env', $envContent);
        
        $result = $this->installer->isInstalled();
        
        // Should return false instead of throwing exception
        $this->assertFalse($result);
    }

    public function testConstructorAcceptsRootPath(): void
    {
        $installer = new Installer($this->testRootPath);
        
        $this->assertInstanceOf(Installer::class, $installer);
    }
}