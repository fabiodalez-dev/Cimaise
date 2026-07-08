<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Proves the upgrade-migration convention works end to end:
 *
 *  - every database/migrations/migrate_<ver>_sqlite.sql applies cleanly onto a
 *    pre-feature schema, using the SAME naive (strip comments, split on ';')
 *    parser as App\Support\Updater::runMigrations();
 *  - the changes they introduce (analytics indexes, plugin/search settings, the
 *    collections tables) actually exist afterwards;
 *  - re-applying every migration is idempotent (no error, no duplicate rows);
 *  - each SQLite migration ships a MySQL counterpart (and vice-versa).
 *
 * Self-contained: it seeds only the prerequisite tables the migrations touch,
 * so it never depends on the full schema file or a live server.
 */
final class MigrationsTest extends TestCase
{
    private string $migrationsDir;

    protected function setUp(): void
    {
        $this->migrationsDir = dirname(__DIR__, 2) . '/database/migrations';
    }

    /** Apply each statement the way the production runner does. */
    private function applyMigrationFile(PDO $db, string $file): int
    {
        $sql = (string) file_get_contents($file);
        $lines = array_filter(explode("\n", $sql), static fn ($l) => !preg_match('/^\s*--/', (string) $l));
        $sql = implode("\n", $lines);
        $statements = array_filter(array_map(trim(...), explode(';', $sql)), static fn ($s) => $s !== '');
        foreach ($statements as $s) {
            $db->exec($s);
        }
        return count($statements);
    }

    /** @return string[] sqlite migration files, ordered by version */
    private function sqliteMigrations(): array
    {
        $files = glob($this->migrationsDir . '/migrate_*_sqlite.sql') ?: [];
        usort($files, static function (string $a, string $b): int {
            preg_match('/migrate_(.+)_sqlite\.sql$/', basename($a), $ma);
            preg_match('/migrate_(.+)_sqlite\.sql$/', basename($b), $mb);
            return version_compare($ma[1], $mb[1]);
        });
        return $files;
    }

    private function freshBaseDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA foreign_keys = ON');
        // Only the prerequisite tables the migrations reference, in their
        // pre-1.2.0 shape (no analytics composite indexes, no collections).
        $db->exec(
            'CREATE TABLE settings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key TEXT NOT NULL UNIQUE, value TEXT, type TEXT DEFAULT "string"
            );
            CREATE TABLE analytics_pageviews (id INTEGER PRIMARY KEY, page_type TEXT, viewed_at TEXT);
            CREATE TABLE analytics_events (id INTEGER PRIMARY KEY, event_type TEXT, occurred_at TEXT);
            CREATE TABLE albums (id INTEGER PRIMARY KEY, title TEXT);
            -- original_path present since the beginning; 1.4.19 indexes it.
            CREATE TABLE images (id INTEGER PRIMARY KEY, album_id INTEGER, original_path TEXT);
            -- image_variants in its pre-1.4.13 shape (format CHECK without
            -- jxl); the 1.4.13 migration rebuilds it to widen the CHECK.
            CREATE TABLE image_variants (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                image_id INTEGER NOT NULL,
                variant TEXT NOT NULL,
                format TEXT NOT NULL CHECK(format IN ("avif", "webp", "jpg")),
                path TEXT NOT NULL,
                width INTEGER NOT NULL,
                height INTEGER NOT NULL,
                size_bytes INTEGER NOT NULL,
                UNIQUE(image_id, variant, format),
                FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
            );
            -- plugin_status as seeded on pre-1.4.18 installs: the 1.4.18
            -- migration deactivates the retired bundled plugins in it.
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
                installed_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            INSERT INTO plugin_status (slug, name, version, path) VALUES
                ("analytics-logger", "Analytics Logger", "1.0.0", "plugins/analytics-logger"),
                ("image-rating", "Image Rating", "1.0.0", "plugins/image-rating");'
        );
        return $db;
    }

    public function testMigrationsDirectoryHasFiles(): void
    {
        self::assertNotEmpty($this->sqliteMigrations(), 'expected at least one SQLite migration');
    }

    public function testEverySqliteMigrationHasMysqlCounterpart(): void
    {
        foreach ($this->sqliteMigrations() as $sqlite) {
            $mysql = str_replace('_sqlite.sql', '_mysql.sql', $sqlite);
            self::assertFileExists($mysql, 'missing MySQL counterpart for ' . basename($sqlite));
        }
        // ...and no orphan MySQL migration without a SQLite one.
        foreach (glob($this->migrationsDir . '/migrate_*_mysql.sql') ?: [] as $mysql) {
            $sqlite = str_replace('_mysql.sql', '_sqlite.sql', $mysql);
            self::assertFileExists($sqlite, 'missing SQLite counterpart for ' . basename($mysql));
        }
    }

    public function testAllSqliteMigrationsApplyInOrder(): void
    {
        $db = $this->freshBaseDb();
        foreach ($this->sqliteMigrations() as $file) {
            $count = $this->applyMigrationFile($db, $file);
            self::assertGreaterThan(0, $count, 'no statements in ' . basename($file));
        }

        // 1.2.0 — analytics composite indexes.
        $idx = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND name IN ('idx_analytics_pageviews_type_viewed','idx_analytics_events_type_occurred')")->fetchColumn();
        self::assertSame(2, (int) $idx, '1.2.0 analytics indexes');

        // 1.2.0 / 1.3.0 — marker settings. The retired-plugin markers
        // (plugin_image_ratings_schema, plugin_analytics_logger_schema) are
        // inserted by 1.2.0 and then DELETED again by 1.4.18, so after the
        // full chain only these two must remain.
        $settings = $db->query("SELECT COUNT(*) FROM settings WHERE key IN ('plugin_analytics_pro_schema','search_fts_schema')")->fetchColumn();
        self::assertSame(2, (int) $settings, '1.2.0/1.3.0 settings');
        $retired = $db->query("SELECT COUNT(*) FROM settings WHERE key IN ('plugin_image_ratings_schema','plugin_analytics_logger_schema')")->fetchColumn();
        self::assertSame(0, (int) $retired, '1.4.18 must remove the retired-plugin schema markers');

        // 1.4.18 — retired bundled plugins deactivated (rows kept, flags off).
        $retiredPlugins = $db->query("SELECT COUNT(*) FROM plugin_status WHERE slug IN ('analytics-logger','image-rating') AND is_active = 0 AND is_installed = 0")->fetchColumn();
        self::assertSame(2, (int) $retiredPlugins, '1.4.18 must deactivate the retired bundled plugins');

        // 1.4.0 — collections tables.
        $tables = $db->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('collections','collection_images')")->fetchColumn();
        self::assertSame(2, (int) $tables, '1.4.0 collections tables');

        // 1.4.13 — image_variants.format CHECK widened to admit 'jxl'.
        // Pre-migration the row would violate the CHECK; post-migration it
        // must insert cleanly. A seeded image FK target is required.
        $db->exec("INSERT INTO images(id, album_id) VALUES (1, NULL)");
        $db->exec("INSERT INTO image_variants(image_id, variant, format, path, width, height, size_bytes) VALUES (1, 'md', 'jxl', '/m/1_md.jxl', 1200, 800, 1234)");
        $jxl = $db->query("SELECT COUNT(*) FROM image_variants WHERE format='jxl'")->fetchColumn();
        self::assertSame(1, (int) $jxl, "1.4.13 must allow format='jxl' in image_variants");
    }

    public function testMigrationsAreIdempotent(): void
    {
        $db = $this->freshBaseDb();
        // Apply twice; the second pass must not error or duplicate seed rows.
        foreach ([1, 2] as $pass) {
            foreach ($this->sqliteMigrations() as $file) {
                $this->applyMigrationFile($db, $file);
            }
        }
        $dupes = $db->query("SELECT COUNT(*) FROM settings WHERE key = 'search_fts_schema'")->fetchColumn();
        self::assertSame(1, (int) $dupes, 'INSERT OR IGNORE must not duplicate the marker');
    }
}
