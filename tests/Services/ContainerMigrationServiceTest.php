<?php

declare(strict_types=1);

use App\Services\ContainerMigrationService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

final class ContainerMigrationServiceTest extends TestCase
{
    private Database $db;
    private string $dir;

    protected function setUp(): void
    {
        $this->db = new Database(database: ':memory:', isSqlite: true);
        // Retained 1.4.23 shape, with user data and a page from the old image.
        $schema = str_replace('  show_equipment INTEGER NOT NULL DEFAULT 1,' . "\n", '',
            str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql')));
        $this->db->pdo()->exec($schema);
        $this->db->pdo()->exec("INSERT INTO categories (id,name,slug) VALUES (99999,'Review','review');
            INSERT INTO albums (id,title,slug,category_id,custom_cameras) VALUES (99999,'Review','review',99999,'Retained camera');
            INSERT INTO page_cache (cache_key,cache_type,data,data_hash,size_bytes,created_at,expires_at)
                VALUES ('album_99999','album','old','old',3,'2026-10-12','2026-10-14')");
        $this->dir = sys_get_temp_dir() . '/cimaise_migrations_' . uniqid();
        mkdir($this->dir);
        copy(dirname(__DIR__, 2) . '/database/migrations/migrate_1.4.24_sqlite.sql', $this->dir . '/migrate_1.4.24_sqlite.sql');
        // Older table rebuilds must not run on legacy Docker databases.
        file_put_contents($this->dir . '/migrate_1.4.13_sqlite.sql', 'INVALID SQL;');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function version(): mixed
    {
        return $this->db->query("SELECT value FROM settings WHERE key = 'docker.schema_version'")->fetchColumn();
    }

    public function testUpgradeAddsEquipmentFlagAndPreservesMetadata(): void
    {
        $service = new ContainerMigrationService($this->db, $this->dir);
        self::assertSame(['migrate_1.4.24_sqlite.sql'], $service->migrate('1.4.24'));
        $album = $this->db->query('SELECT show_equipment, custom_cameras FROM albums WHERE id = 99999')->fetch();
        self::assertSame(1, (int) $album['show_equipment']);
        self::assertSame('Retained camera', $album['custom_cameras']);
        $this->db->execute('UPDATE albums SET show_equipment = 0 WHERE id = 99999');
        self::assertSame(0, (int) $this->db->query('SELECT show_equipment FROM albums WHERE id = 99999')->fetchColumn());
        self::assertSame('"1.4.24"', $this->version());
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM page_cache')->fetchColumn());
    }

    public function testPrereleaseMigratesStableSchemaAndTracksFullImageVersion(): void
    {
        $service = new ContainerMigrationService($this->db, $this->dir);
        self::assertSame(['migrate_1.4.24_sqlite.sql'], $service->migrate('1.4.24-test.1'));
        self::assertSame('"1.4.24"', $this->version());
        self::assertSame('"1.4.24-test.1"', $this->db->query("SELECT value FROM settings WHERE key = 'docker.image_version'")->fetchColumn());
        self::assertSame([], $service->migrate('1.4.24-test.2'));
    }

    public function testForkEquipmentPreferencesSurviveUpgradeAndAreNotReplayed(): void
    {
        $this->db->execute('INSERT INTO settings (`key`, `value`, `type`) VALUES (?, ?, ?)',
            ['album.99999.show_equipment', 'false', 'boolean']);
        $service = new ContainerMigrationService($this->db, $this->dir);
        $service->migrate('1.4.24-test.1');
        self::assertSame(0, (int)$this->db->query('SELECT show_equipment FROM albums WHERE id = 99999')->fetchColumn());
        self::assertSame('Retained camera', $this->db->query('SELECT custom_cameras FROM albums WHERE id = 99999')->fetchColumn());
        self::assertSame('false', $this->db->query("SELECT value FROM settings WHERE key = 'album.99999.show_equipment'")->fetchColumn());
        $this->db->execute('UPDATE albums SET show_equipment = 1 WHERE id = 99999');
        $service->migrate('1.4.24-test.2');
        self::assertSame(1, (int)$this->db->query('SELECT show_equipment FROM albums WHERE id = 99999')->fetchColumn());
    }

    public function testRestartDoesNotReplayMigrationsOrClearNewCache(): void
    {
        $service = new ContainerMigrationService($this->db, $this->dir);
        $service->migrate('1.4.24');
        file_put_contents($this->dir . '/migrate_1.4.24_sqlite.sql', 'INVALID SQL;');
        $this->db->execute("INSERT INTO page_cache (cache_key,cache_type,data,data_hash,size_bytes,created_at,expires_at)
            VALUES ('home','home','new','new',3,'2026-10-13','2026-10-14')");
        self::assertSame([], $service->migrate('1.4.24'));
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM page_cache')->fetchColumn());
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) FROM migrations')->fetchColumn());
    }

    public function testExistingColumnOnFreshInstallIsTolerated(): void
    {
        $this->db->execute('ALTER TABLE albums ADD COLUMN show_equipment INTEGER NOT NULL DEFAULT 1');
        $service = new ContainerMigrationService($this->db, $this->dir);
        self::assertSame(['migrate_1.4.24_sqlite.sql'], $service->migrate('1.4.24'));
        self::assertSame('"1.4.24"', $this->version());
    }

    public function testFailedMigrationDoesNotAdvanceTheVersionAndCanBeRetried(): void
    {
        $service = new ContainerMigrationService($this->db, $this->dir);
        file_put_contents($this->dir . '/migrate_1.4.24_sqlite.sql', 'INVALID SQL;');
        try {
            $service->migrate('1.4.24');
            self::fail('Expected migration failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('Database migration failed', $e->getMessage());
        }
        self::assertFalse($this->version());
        copy(dirname(__DIR__, 2) . '/database/migrations/migrate_1.4.24_sqlite.sql', $this->dir . '/migrate_1.4.24_sqlite.sql');
        self::assertSame(['migrate_1.4.24_sqlite.sql'], $service->migrate('1.4.24'));
    }

    public function testMissingImageMigrationsFailInsteadOfMarkingTheUpgradeComplete(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Image migration directory is missing');
        (new ContainerMigrationService($this->db, $this->dir . '/missing'))->migrate('1.4.24');
    }

    public function testSchemaDowngradeIsRejected(): void
    {
        $service = new ContainerMigrationService($this->db, $this->dir);
        $service->migrate('1.4.24');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('newer than this image');
        $service->migrate('1.4.23');
    }

    public function testRollbackToAnImageWithTheSameSchemaIsAllowed(): void
    {
        // 1.4.26 ships no migration of its own: its schema is still 1.4.24's,
        // so going back to 1.4.25 must start instead of refusing to boot.
        $service = new ContainerMigrationService($this->db, $this->dir);
        $service->migrate('1.4.26');
        self::assertSame('"1.4.24"', $this->version());
        $this->db->execute("INSERT INTO page_cache (cache_key,cache_type,data,data_hash,size_bytes,created_at,expires_at)
            VALUES ('home','home','from-1.4.26','x',3,'2026-10-13','2026-10-14')");

        self::assertSame([], $service->migrate('1.4.25'));
        self::assertSame('"1.4.24"', $this->version());
        self::assertSame('"1.4.25"', $this->db->query("SELECT value FROM settings WHERE key = 'docker.image_version'")->fetchColumn());
        // Page data rendered by the other image is discarded on the switch.
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM page_cache')->fetchColumn());
    }

    public function testImageUpgradeWithoutMigrationsStillDiscardsOldPageData(): void
    {
        $service = new ContainerMigrationService($this->db, $this->dir);
        $service->migrate('1.4.24');
        $this->db->execute("INSERT INTO page_cache (cache_key,cache_type,data,data_hash,size_bytes,created_at,expires_at)
            VALUES ('home','home','from-1.4.24','x',3,'2026-10-13','2026-10-14')");
        self::assertSame([], $service->migrate('1.4.25'));
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM page_cache')->fetchColumn());
    }
}
