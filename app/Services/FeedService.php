<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use PDO;

/**
 * Data for the public RSS/Atom feeds: the most recently published albums.
 *
 * Reuses the same visibility rule as collections (published, not
 * password-protected, not NSFW) so private work never appears in a feed.
 */
final class FeedService
{
    private const DEFAULT_LIMIT = 30;
    private const MAX_LIMIT = 100;

    public function __construct(private Database $db)
    {
    }

    /**
     * Recently published, publicly visible albums, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentPublishedAlbums(int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        // $limit is an int clamped above, safe to interpolate.
        $sql = "SELECT a.id, a.title, a.slug, a.excerpt, a.body, a.published_at, a.cover_image_id
                FROM albums a
                WHERE " . CollectionService::ALBUM_VISIBLE . "
                ORDER BY " . $this->db->orderByNullsLast('a.published_at') . " DESC, a.id DESC
                LIMIT " . $limit;

        return $this->db->pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Most recent publish timestamp across visible albums (for the feed's own date). */
    public function latestPublishedAt(): ?string
    {
        $val = $this->db->pdo()->query(
            "SELECT MAX(a.published_at) FROM albums a WHERE " . CollectionService::ALBUM_VISIBLE
        )->fetchColumn();
        return $val !== false && $val !== null ? (string) $val : null;
    }
}
