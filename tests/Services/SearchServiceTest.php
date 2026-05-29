<?php
declare(strict_types=1);

use App\Services\SearchIndexer;
use App\Services\SearchService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the public full-text search (gap #1).
 *
 * Runs against a real on-disk SQLite database so the FTS5 path
 * (SearchIndexer -> virtual tables + triggers -> bm25 ranking) is exercised
 * end to end, including the privacy filter (published, non password-protected
 * albums only) and photo-level matches folded into their album.
 */
final class SearchServiceTest extends TestCase
{
    private string $dbFile;
    private Database $db;

    protected function setUp(): void
    {
        SearchIndexer::resetForTesting();

        $this->dbFile = sys_get_temp_dir() . '/cimaise_search_test_' . uniqid('', true) . '.sqlite';
        $this->db = new Database(null, null, $this->dbFile, null, null, 'utf8mb4', 'utf8mb4_unicode_ci', true);

        $this->createSchema();
        $this->seed();
    }

    protected function tearDown(): void
    {
        unset($this->db);
        // Paths are derived solely from sys_get_temp_dir() + uniqid() in setUp,
        // never from external input.
        foreach ([$this->dbFile, $this->dbFile . '-wal', $this->dbFile . '-shm'] as $f) {
            if (is_file($f)) {
                @unlink($f); // nosemgrep: php.lang.security.unlink-use.unlink-use
            }
        }
        SearchIndexer::resetForTesting();
    }

    public function testFindsAlbumByBodyAndImageByCaption(): void
    {
        if (!$this->fts5Available()) {
            $this->markTestSkipped('SQLite build without FTS5');
        }

        $result = (new SearchService($this->db))->search('kodak');

        $slugs = array_column($result['albums'], 'slug');
        self::assertContains('mountain-trip', $slugs, 'album matched via body text');
        self::assertContains('city-nights', $slugs, 'album matched via image caption');
        self::assertSame(2, $result['total']);
    }

    public function testExcludesUnpublishedAndPasswordProtectedAlbums(): void
    {
        if (!$this->fts5Available()) {
            $this->markTestSkipped('SQLite build without FTS5');
        }

        $result = (new SearchService($this->db))->search('kodak');
        $slugs = array_column($result['albums'], 'slug');

        self::assertNotContains('draft-roll', $slugs, 'unpublished album must be hidden');
        self::assertNotContains('secret-album', $slugs, 'password-protected album must be hidden');
    }

    public function testImageMatchExposesMatchedImageIds(): void
    {
        if (!$this->fts5Available()) {
            $this->markTestSkipped('SQLite build without FTS5');
        }

        $result = (new SearchService($this->db))->search('neon');
        $byId = [];
        foreach ($result['albums'] as $a) {
            $byId[$a['slug']] = $a;
        }

        self::assertArrayHasKey('city-nights', $byId);
        self::assertNotEmpty($byId['city-nights']['matched_image_ids']);
    }

    public function testNoResultsForUnknownTerm(): void
    {
        $result = (new SearchService($this->db))->search('zzzznotpresent');
        self::assertSame(0, $result['total']);
        self::assertSame([], $result['albums']);
    }

    public function testShortQueryReturnsEmpty(): void
    {
        $result = (new SearchService($this->db))->search('a');
        self::assertSame(0, $result['total']);
    }

    public function testTriggerKeepsIndexInSyncAfterInsert(): void
    {
        if (!$this->fts5Available()) {
            $this->markTestSkipped('SQLite build without FTS5');
        }

        $service = new SearchService($this->db);
        // First search builds the FTS infra (tables + triggers).
        $service->search('kodak');

        // Insert a brand-new published album AFTER the index exists.
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO categories (id, name, slug) VALUES (9, 'Studio', 'studio')");
        $pdo->exec("INSERT INTO albums (id, title, slug, category_id, excerpt, body, is_published, password_hash)
                    VALUES (50, 'Hasselblad Session', 'hasselblad-session', 9, '', 'medium format study', 1, NULL)");

        $result = $service->search('hasselblad');
        self::assertContains('hasselblad-session', array_column($result['albums'], 'slug'));
    }

    // --- helpers ------------------------------------------------------------

    private function fts5Available(): bool
    {
        try {
            $probe = new PDO('sqlite::memory:');
            $probe->exec('CREATE VIRTUAL TABLE _p USING fts5(x)');
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function createSchema(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL
            );
            CREATE TABLE albums (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT NOT NULL,
                slug TEXT NOT NULL,
                category_id INTEGER NOT NULL,
                excerpt TEXT,
                body TEXT,
                cover_image_id INTEGER,
                is_published INTEGER DEFAULT 0,
                is_nsfw INTEGER DEFAULT 0,
                password_hash TEXT
            );
            CREATE TABLE images (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                album_id INTEGER NOT NULL,
                caption TEXT,
                alt_text TEXT,
                custom_camera TEXT,
                custom_lens TEXT,
                custom_film TEXT
            );'
        );
    }

    private function seed(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec("INSERT INTO categories (id, name, slug) VALUES
            (1, 'Travel', 'travel'),
            (2, 'Urban', 'urban')");

        // 1: matches via body
        $pdo->exec("INSERT INTO albums (id, title, slug, category_id, excerpt, body, is_published, password_hash) VALUES
            (1, 'Mountain Trip', 'mountain-trip', 1, 'A hike', 'Shot on Kodak Portra 400', 1, NULL)");
        // 2: matches via image caption only
        $pdo->exec("INSERT INTO albums (id, title, slug, category_id, excerpt, body, is_published, password_hash) VALUES
            (2, 'City Nights', 'city-nights', 2, 'After dark', 'Long exposures downtown', 1, NULL)");
        $pdo->exec("INSERT INTO images (id, album_id, caption, alt_text) VALUES
            (20, 2, 'Neon kodak signage reflecting on wet asphalt', 'neon sign')");
        // 3: password-protected, must be excluded even though body matches
        $pdo->exec("INSERT INTO albums (id, title, slug, category_id, excerpt, body, is_published, password_hash) VALUES
            (3, 'Secret', 'secret-album', 2, '', 'Kodak everywhere here', 1, 'somehash')");
        // 4: unpublished, must be excluded
        $pdo->exec("INSERT INTO albums (id, title, slug, category_id, excerpt, body, is_published, password_hash) VALUES
            (4, 'Draft Roll', 'draft-roll', 1, '', 'Kodak draft notes', 0, NULL)");
    }
}
