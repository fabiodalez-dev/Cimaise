<?php
declare(strict_types=1);

use App\Support\Database;
use App\Support\Updater;
use PHPUnit\Framework\TestCase;

final class UpdaterPrereleaseMigrationTest extends TestCase
{
    public function testPrereleaseAppliesItsNumericSchemaButNotFutureMigrations(): void
    {
        $db = new Database(database: ':memory:', isSqlite: true);
        $db->pdo()->exec(file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        $directory = sys_get_temp_dir() . '/cimaise_prerelease_migrations_' . bin2hex(random_bytes(5));
        mkdir($directory);
        try {
            file_put_contents($directory . '/migrate_1.4.24_sqlite.sql', 'CREATE TABLE prerelease_schema_probe (id INTEGER PRIMARY KEY);');
            file_put_contents($directory . '/migrate_1.4.25_sqlite.sql', 'CREATE TABLE future_schema_probe (id INTEGER PRIMARY KEY);');
            $updater = new Updater($db);
            $result = $updater->runMigrations('1.4.23-test.12', '1.4.24-test.2', $directory);
            self::assertTrue($result['success'], (string)$result['error']);
            self::assertSame(['migrate_1.4.24_sqlite.sql'], $result['executed']);
            self::assertSame('prerelease_schema_probe', $db->query("SELECT name FROM sqlite_master WHERE name = 'prerelease_schema_probe'")->fetchColumn());
            self::assertFalse($db->query("SELECT name FROM sqlite_master WHERE name = 'future_schema_probe'")->fetchColumn());
            $result = $updater->runMigrations('1.4.24-test.2', '1.4.24', $directory);
            self::assertTrue($result['success']);
            self::assertSame([], $result['executed']);
        } finally {
            unlink($directory . '/migrate_1.4.24_sqlite.sql');
            unlink($directory . '/migrate_1.4.25_sqlite.sql');
            rmdir($directory);
        }
    }
}
