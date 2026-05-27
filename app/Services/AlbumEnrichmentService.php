<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Reusable batch loaders for album-related data.
 *
 * Replaces the duplicate enrichAlbumsBatch() implementations that previously
 * lived in GalleriesController and PageController. Each method runs a single
 * "WHERE id IN (...)" batch query so callers can avoid the classic N+1 trap
 * when enriching a list of albums.
 *
 * Callers compose the output themselves — the service deliberately does NOT
 * assemble final album rows, because the two controllers expect different
 * shapes (listing vs. detail page) and merging them into one rigid output
 * would create more churn than it removes.
 */
class AlbumEnrichmentService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Build an "?,?,..." placeholder string for the given count.
     */
    private function placeholders(int $count): string
    {
        return implode(',', array_fill(0, $count, '?'));
    }

    /**
     * Load cover images (by their explicit id) and join the small + blur variants
     * used by listing pages. Returns rows keyed by image id.
     *
     * Shape per row: image columns + preview_path + blur_path.
     */
    public function loadListingCoverImages(array $imageIds): array
    {
        $imageIds = array_values(array_filter(array_map('intval', $imageIds), fn($id) => $id > 0));
        if (!$imageIds) {
            return [];
        }

        $ph = $this->placeholders(count($imageIds));
        $stmt = $this->pdo->prepare("
            SELECT i.*,
                   iv.path AS preview_path,
                   blur.path AS blur_path
            FROM images i
            LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = 'sm' AND iv.format = 'jpg'
            LEFT JOIN image_variants blur ON blur.image_id = i.id AND blur.variant = 'blur'
            WHERE i.id IN ($ph)
        ");
        $stmt->execute($imageIds);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    /**
     * For albums without an explicit cover_image_id, fetch the first image
     * (lowest sort_order, then id) using a window function — replaces the
     * old correlated subquery that ran one SELECT per candidate row.
     *
     * Returns rows keyed by album_id.
     */
    public function loadFallbackCoverImages(array $albumIds): array
    {
        $albumIds = array_values(array_filter(array_map('intval', $albumIds), fn($id) => $id > 0));
        if (!$albumIds) {
            return [];
        }

        $ph = $this->placeholders(count($albumIds));
        $stmt = $this->pdo->prepare("
            WITH ranked AS (
                SELECT id, album_id,
                       ROW_NUMBER() OVER (PARTITION BY album_id ORDER BY sort_order ASC, id ASC) AS rn
                FROM images
                WHERE album_id IN ($ph)
            )
            SELECT i.*,
                   iv.path AS preview_path,
                   blur.path AS blur_path
            FROM ranked r
            JOIN images i ON i.id = r.id
            LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = 'sm' AND iv.format = 'jpg'
            LEFT JOIN image_variants blur ON blur.image_id = i.id AND blur.variant = 'blur'
            WHERE r.rn = 1
        ");
        $stmt->execute($albumIds);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['album_id']] = $row;
        }
        return $out;
    }

    /**
     * Load full image rows for the given cover image ids, plus ALL their variants
     * grouped by image id. Used by the detail-page enrichment which needs the
     * complete variant set (avif/webp/jpg, sm/md/lg/...) for responsive picture
     * elements.
     *
     * @return array{images: array<int, array>, variants: array<int, array<int, array>>}
     */
    public function loadPageCoverImagesWithVariants(array $imageIds): array
    {
        $imageIds = array_values(array_filter(array_map('intval', $imageIds), fn($id) => $id > 0));
        if (!$imageIds) {
            return ['images' => [], 'variants' => []];
        }

        $ph = $this->placeholders(count($imageIds));

        $stmt = $this->pdo->prepare("SELECT * FROM images WHERE id IN ($ph)");
        $stmt->execute($imageIds);
        $images = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $img) {
            $images[(int) $img['id']] = $img;
        }

        $stmt = $this->pdo->prepare("
            SELECT * FROM image_variants
            WHERE image_id IN ($ph)
            ORDER BY image_id, variant ASC
        ");
        $stmt->execute($imageIds);
        $variants = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $variant) {
            $variants[(int) $variant['image_id']][] = $variant;
        }

        return ['images' => $images, 'variants' => $variants];
    }

    /**
     * Number of images per album, returned as map album_id => count.
     */
    public function loadImageCounts(array $albumIds): array
    {
        $albumIds = array_values(array_filter(array_map('intval', $albumIds), fn($id) => $id > 0));
        if (!$albumIds) {
            return [];
        }

        $ph = $this->placeholders(count($albumIds));
        $stmt = $this->pdo->prepare("
            SELECT album_id, COUNT(*) AS cnt
            FROM images
            WHERE album_id IN ($ph)
            GROUP BY album_id
        ");
        $stmt->execute($albumIds);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['album_id']] = (int) $row['cnt'];
        }
        return $out;
    }

    /**
     * Tags per album, returned as map album_id => list-of-tag-rows (album_id stripped).
     */
    public function loadTags(array $albumIds): array
    {
        $albumIds = array_values(array_filter(array_map('intval', $albumIds), fn($id) => $id > 0));
        if (!$albumIds) {
            return [];
        }

        $ph = $this->placeholders(count($albumIds));
        $stmt = $this->pdo->prepare("
            SELECT at.album_id, t.*
            FROM tags t
            JOIN album_tag at ON at.tag_id = t.id
            WHERE at.album_id IN ($ph)
            ORDER BY t.name ASC
        ");
        $stmt->execute($albumIds);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $tag) {
            $albumId = (int) $tag['album_id'];
            unset($tag['album_id']);
            $out[$albumId][] = $tag;
        }
        return $out;
    }

    /**
     * Locations per album, returned as map album_id => list-of-location-rows.
     * Best-effort: silently returns [] if the locations table is missing
     * (legacy installs).
     */
    public function loadLocations(array $albumIds): array
    {
        $albumIds = array_values(array_filter(array_map('intval', $albumIds), fn($id) => $id > 0));
        if (!$albumIds) {
            return [];
        }

        $ph = $this->placeholders(count($albumIds));
        try {
            $stmt = $this->pdo->prepare("
                SELECT al.album_id, l.id, l.name, l.slug
                FROM album_location al
                JOIN locations l ON l.id = al.location_id
                WHERE al.album_id IN ($ph)
                ORDER BY l.name
            ");
            $stmt->execute($albumIds);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $loc) {
            $albumId = (int) $loc['album_id'];
            unset($loc['album_id']);
            $out[$albumId][] = $loc;
        }
        return $out;
    }
}
