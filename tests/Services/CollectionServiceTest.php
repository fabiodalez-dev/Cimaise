<?php

declare(strict_types=1);

use App\Services\CollectionService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

/**
 * The security-relevant contract of curated collections: a photo is surfaced
 * publicly only when its source album is published, not password-protected and
 * not NSFW. A photo whose album is private must never leak through a collection.
 */
final class CollectionServiceTest extends TestCase
{
    private string $dbFile;
    private Database $db;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/cimaise_coll_test_' . uniqid('', true) . '.sqlite';
        $this->db = new Database(null, null, $this->dbFile, null, null, 'utf8mb4', 'utf8mb4_unicode_ci', true);
        $this->createSchema();
        $this->seed();
    }

    protected function tearDown(): void
    {
        unset($this->db);
        // Paths derive only from sys_get_temp_dir() + uniqid(), never external input.
        foreach ([$this->dbFile, $this->dbFile . '-wal', $this->dbFile . '-shm'] as $f) {
            if (is_file($f)) {
                @unlink($f); // nosemgrep
            }
        }
    }

    public function testVisibleImageRowsExcludesHiddenAlbums(): void
    {
        $rows = (new CollectionService($this->db))->visibleImageRows(1);
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);

        self::assertContains(10, $ids, 'photo from a public album is visible');
        self::assertNotContains(20, $ids, 'photo from an unpublished album must be hidden');
        self::assertNotContains(30, $ids, 'photo from a password-protected album must be hidden');
        self::assertNotContains(40, $ids, 'photo from an NSFW album must be hidden');
        self::assertCount(1, $rows);
    }

    public function testVisibleImageRowsKeepsCuratorOrder(): void
    {
        // Add a second visible photo ordered before the first.
        $this->db->pdo()->exec("INSERT INTO images (id, album_id, width, height) VALUES (11, 1, 100, 100)");
        $this->db->pdo()->exec("INSERT INTO collection_images (collection_id, image_id, sort_order) VALUES (1, 11, -1)");

        $rows = (new CollectionService($this->db))->visibleImageRows(1);
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        self::assertSame([11, 10], $ids, 'rows follow collection sort_order');
    }

    public function testPublishedCollectionsRequireAtLeastOneVisiblePhoto(): void
    {
        // Collection 2 is published but contains only a hidden (unpublished) photo.
        $this->db->pdo()->exec("INSERT INTO collections (id, title, slug, is_published) VALUES (2, 'Hidden Only', 'hidden-only', 1)");
        $this->db->pdo()->exec("INSERT INTO collection_images (collection_id, image_id, sort_order) VALUES (2, 20, 0)");

        $slugs = array_map(static fn ($c) => $c['slug'], (new CollectionService($this->db))->publishedCollections());
        self::assertContains('public-coll', $slugs);
        self::assertNotContains('hidden-only', $slugs, 'a collection with no visible photo is not listed');
    }

    public function testFindPublishedBySlugIgnoresDrafts(): void
    {
        $svc = new CollectionService($this->db);
        self::assertNotNull($svc->findPublishedBySlug('public-coll'));

        $this->db->pdo()->exec("INSERT INTO collections (id, title, slug, is_published) VALUES (3, 'Draft', 'draft-coll', 0)");
        self::assertNull($svc->findPublishedBySlug('draft-coll'));
        self::assertNull($svc->findPublishedBySlug('nope'));
    }

    private function createSchema(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE albums (
                id INTEGER PRIMARY KEY, title TEXT, slug TEXT, category_id INTEGER DEFAULT 1,
                is_published INTEGER DEFAULT 0, is_nsfw INTEGER DEFAULT 0, password_hash TEXT
            );
            CREATE TABLE images (
                id INTEGER PRIMARY KEY, album_id INTEGER NOT NULL,
                width INTEGER DEFAULT 0, height INTEGER DEFAULT 0, alt_text TEXT, caption TEXT
            );
            CREATE TABLE collections (
                id INTEGER PRIMARY KEY, title TEXT, slug TEXT, description TEXT,
                cover_image_id INTEGER, is_published INTEGER DEFAULT 0, sort_order INTEGER DEFAULT 0
            );
            CREATE TABLE collection_images (
                collection_id INTEGER, image_id INTEGER, sort_order INTEGER DEFAULT 0,
                PRIMARY KEY (collection_id, image_id)
            );'
        );
    }

    private function seed(): void
    {
        $pdo = $this->db->pdo();
        // Albums: 1 visible, 2 unpublished, 3 password, 4 nsfw.
        $pdo->exec("INSERT INTO albums (id, title, slug, is_published, is_nsfw, password_hash) VALUES
            (1, 'Public',    'public',    1, 0, NULL),
            (2, 'Draft',     'draft',     0, 0, NULL),
            (3, 'Locked',    'locked',    1, 0, 'hash'),
            (4, 'Sensitive', 'sensitive', 1, 1, NULL)");
        $pdo->exec("INSERT INTO images (id, album_id, width, height) VALUES
            (10, 1, 1600, 1067),
            (20, 2, 1600, 1067),
            (30, 3, 1600, 1067),
            (40, 4, 1600, 1067)");
        $pdo->exec("INSERT INTO collections (id, title, slug, is_published) VALUES (1, 'Public Coll', 'public-coll', 1)");
        // One photo from each album, so only #10 should ever be visible.
        $pdo->exec("INSERT INTO collection_images (collection_id, image_id, sort_order) VALUES
            (1, 10, 0), (1, 20, 1), (1, 30, 2), (1, 40, 3)");
    }
}
