<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use PDO;

/**
 * Data access for public curated collections, with the privacy rule in one
 * place: a photo is only ever surfaced when its source album is published,
 * not password-protected and not NSFW. Centralising the rule here keeps the
 * controller thin and makes the (security-relevant) filter unit-testable.
 */
final readonly class CollectionService
{
    /** Album visibility predicate, reused by every public query. */
    public const ALBUM_VISIBLE = "a.is_published = 1 AND (a.password_hash IS NULL OR a.password_hash = '') AND a.is_nsfw = 0";

    public function __construct(private Database $db)
    {
    }

    /**
     * Published collections that contain at least one publicly visible photo,
     * with the visible-photo count.
     *
     * @return array<int, array<string, mixed>>
     */
    public function publishedCollections(): array
    {
        return $this->db->pdo()->query(
            "SELECT c.*, COUNT(ci.image_id) AS image_count
             FROM collections c
             JOIN collection_images ci ON ci.collection_id = c.id
             JOIN images i ON i.id = ci.image_id
             JOIN albums a ON a.id = i.album_id
             WHERE c.is_published = 1 AND " . self::ALBUM_VISIBLE . "
             GROUP BY c.id
             ORDER BY c.sort_order ASC, c.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function findPublishedBySlug(string $slug): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM collections WHERE slug = ? AND is_published = 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Visible photos of a collection (raw rows, before variant shaping),
     * in curator order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visibleImageRows(int $collectionId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT i.id, i.album_id, i.width, i.height, i.alt_text, i.caption, a.slug AS album_slug
             FROM collection_images ci
             JOIN images i ON i.id = ci.image_id
             JOIN albums a ON a.id = i.album_id
             WHERE ci.collection_id = :c AND " . self::ALBUM_VISIBLE . "
             ORDER BY ci.sort_order ASC, i.id ASC"
        );
        $stmt->execute([':c' => $collectionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
