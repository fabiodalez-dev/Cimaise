<?php

declare(strict_types=1);

use App\Services\FeedService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;

/**
 * The feed must only ever syndicate publicly visible albums (published, not
 * password-protected, not NSFW), newest first, with NULL publish dates sorted
 * last rather than crashing the order.
 */
final class FeedServiceTest extends TestCase
{
    private string $dbFile;
    private Database $db;

    protected function setUp(): void
    {
        $this->dbFile = sys_get_temp_dir() . '/cimaise_feed_test_' . uniqid('', true) . '.sqlite';
        $this->db = new Database(null, null, $this->dbFile, null, null, 'utf8mb4', 'utf8mb4_unicode_ci', true);
        $this->db->pdo()->exec(
            'CREATE TABLE albums (
                id INTEGER PRIMARY KEY, title TEXT, slug TEXT, excerpt TEXT, body TEXT,
                published_at TEXT, cover_image_id INTEGER,
                is_published INTEGER DEFAULT 0, is_nsfw INTEGER DEFAULT 0, password_hash TEXT
            )'
        );
        $this->db->pdo()->exec("INSERT INTO albums (id, title, slug, published_at, is_published, is_nsfw, password_hash) VALUES
            (1, 'Newest',  'newest',  '2026-05-20 10:00:00', 1, 0, NULL),
            (2, 'Older',   'older',   '2026-01-01 10:00:00', 1, 0, NULL),
            (3, 'NoDate',  'nodate',   NULL,                 1, 0, NULL),
            (4, 'Draft',   'draft',   '2026-05-25 10:00:00', 0, 0, NULL),
            (5, 'Locked',  'locked',  '2026-05-25 10:00:00', 1, 0, 'hash'),
            (6, 'Spicy',   'spicy',   '2026-05-25 10:00:00', 1, 1, NULL)");
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

    public function testOnlyVisibleAlbumsNewestFirstNullsLast(): void
    {
        $slugs = array_map(static fn ($a) => $a['slug'], (new FeedService($this->db))->recentPublishedAlbums());

        // Draft / locked / nsfw excluded; newest first; the no-date album last.
        self::assertSame(['newest', 'older', 'nodate'], $slugs);
    }

    public function testLimitIsRespected(): void
    {
        $rows = (new FeedService($this->db))->recentPublishedAlbums(2);
        self::assertCount(2, $rows);
        self::assertSame('newest', $rows[0]['slug']);
    }

    public function testLatestPublishedAtIgnoresHiddenAlbums(): void
    {
        // Hidden albums have a newer date (2026-05-25) but must not win.
        self::assertSame('2026-05-20 10:00:00', (new FeedService($this->db))->latestPublishedAt());
    }
}
