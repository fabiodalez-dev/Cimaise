<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * ImageVariantsService - Eager Loading for Image Variants
 *
 * Solves N+1 query problem by loading all variants at once
 * instead of querying per image in a loop.
 *
 * Before: 150+ queries (1 for images + 50 images × 3 queries each)
 * After: 2 queries (1 for images + 1 for all variants)
 *
 * Performance: ~30x faster for 50 images
 */
class ImageVariantsService
{
    /**
     * Eager load all variants for multiple images
     *
     * @param PDO $pdo Database connection
     * @param array $imageIds Array of image IDs
     * @return array Variants grouped by image_id
     */
    public static function eagerLoadVariants(PDO $pdo, array $imageIds): array
    {
        if (empty($imageIds)) {
            return [];
        }

        // Build placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($imageIds), '?'));

        // Single query to fetch ALL variants for ALL images
        $stmt = $pdo->prepare("
            SELECT
                image_id,
                variant,
                format,
                path,
                width,
                height
            FROM image_variants
            WHERE image_id IN ($placeholders)
                AND path NOT LIKE '/storage/%'
            ORDER BY image_id, width DESC
        ");

        $stmt->execute($imageIds);
        $allVariants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group variants by image_id for easy lookup
        $variantsByImage = [];
        foreach ($allVariants as $variant) {
            $imageId = (int)$variant['image_id'];
            if (!isset($variantsByImage[$imageId])) {
                $variantsByImage[$imageId] = [];
            }
            $variantsByImage[$imageId][] = $variant;
        }

        return $variantsByImage;
    }

    /**
     * Get best grid variant from pre-loaded variants
     *
     * @param array $variants All variants for an image
     * @return array|null Best variant for grid display
     */
    public static function getBestGridVariant(array $variants): ?array
    {
        if (empty($variants)) {
            return null;
        }

        // Prefer: largest public variant (avif > webp > jpg)
        $best = null;
        $bestScore = -1;

        foreach ($variants as $variant) {
            // Skip storage paths
            if (str_starts_with($variant['path'], '/storage/')) {
                continue;
            }

            // Calculate score: format preference + size
            $formatScore = match($variant['format']) {
                'avif' => 3000000,
                'webp' => 2000000,
                'jpg', 'jpeg' => 1000000,
                default => 0
            };

            $sizeScore = (int)($variant['width'] ?? 0);
            $score = $formatScore + $sizeScore;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $variant;
            }
        }

        return $best;
    }

    /**
     * Get best lightbox variant from pre-loaded variants
     *
     * @param array $variants All variants for an image
     * @return array|null Best variant for lightbox display
     */
    public static function getBestLightboxVariant(array $variants): ?array
    {
        if (empty($variants)) {
            return null;
        }

        // Prefer: largest available variant (size first, then format)
        $best = null;
        $bestWidth = 0;

        foreach ($variants as $variant) {
            // Skip storage paths
            if (str_starts_with($variant['path'], '/storage/')) {
                continue;
            }

            $width = (int)($variant['width'] ?? 0);

            // Prefer larger images
            if ($width > $bestWidth) {
                $bestWidth = $width;
                $best = $variant;
            } elseif ($width === $bestWidth && $best) {
                // Same width, prefer better format
                $currentFormatScore = match($variant['format']) {
                    'avif' => 3,
                    'webp' => 2,
                    'jpg', 'jpeg' => 1,
                    default => 0
                };
                $bestFormatScore = match($best['format']) {
                    'avif' => 3,
                    'webp' => 2,
                    'jpg', 'jpeg' => 1,
                    default => 0
                };

                if ($currentFormatScore > $bestFormatScore) {
                    $best = $variant;
                }
            }
        }

        return $best;
    }

    /**
     * Build responsive sources for <picture> element
     *
     * @param array $variants All variants for an image
     * @return array Sources grouped by format ['avif' => [], 'webp' => [], 'jpg' => []]
     */
    public static function buildResponsiveSources(array $variants): array
    {
        $sources = ['avif' => [], 'webp' => [], 'jpg' => []];

        foreach ($variants as $variant) {
            // Skip storage paths
            if (str_starts_with($variant['path'], '/storage/')) {
                continue;
            }

            $format = $variant['format'];
            $width = (int)($variant['width'] ?? 0);

            if (isset($sources[$format])) {
                $sources[$format][] = $variant['path'] . ' ' . $width . 'w';
            }
        }

        return $sources;
    }

    /**
     * Eager load equipment for multiple albums
     *
     * Solves N+1 for equipment queries (cameras, lenses, etc.)
     *
     * @param PDO $pdo Database connection
     * @param array $albumIds Array of album IDs
     * @return array Equipment grouped by album_id
     */
    public static function eagerLoadEquipment(PDO $pdo, array $albumIds): array
    {
        if (empty($albumIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($albumIds), '?'));
        $equipmentByAlbum = [];

        // Initialize structure
        foreach ($albumIds as $albumId) {
            $equipmentByAlbum[$albumId] = [
                'cameras' => [],
                'lenses' => [],
                'film' => [],
                'developers' => [],
                'labs' => [],
                'locations' => []
            ];
        }

        // Detect database driver for cross-database compatibility
        // SQLite uses || for concatenation, MySQL uses CONCAT()
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isSqlite = $driver === 'sqlite';

        // Build concatenation expressions based on database type
        $cameraConcatExpr = $isSqlite
            ? "(c.make || ' ' || c.model)"
            : "CONCAT(c.make, ' ', c.model)";
        $lensConcatExpr = $isSqlite
            ? "(l.brand || ' ' || l.model)"
            : "CONCAT(l.brand, ' ', l.model)";
        $filmConcatExpr = $isSqlite
            ? "(f.brand || ' ' || f.name)"
            : "CONCAT(f.brand, ' ', f.name)";

        // Load all equipment in one query with UNION ALL
        try {
            $stmt = $pdo->prepare("
                SELECT album_id, 'camera' as type, {$cameraConcatExpr} as name
                FROM album_camera ac
                JOIN cameras c ON c.id = ac.camera_id
                WHERE ac.album_id IN ($placeholders)

                UNION ALL

                SELECT album_id, 'lens' as type, {$lensConcatExpr} as name
                FROM album_lens al
                JOIN lenses l ON l.id = al.lens_id
                WHERE al.album_id IN ($placeholders)

                UNION ALL

                SELECT album_id, 'film' as type, {$filmConcatExpr} as name
                FROM album_film af
                JOIN films f ON f.id = af.film_id
                WHERE af.album_id IN ($placeholders)

                UNION ALL

                SELECT album_id, 'developer' as type, d.name
                FROM album_developer ad
                JOIN developers d ON d.id = ad.developer_id
                WHERE ad.album_id IN ($placeholders)

                UNION ALL

                SELECT album_id, 'lab' as type, l.name
                FROM album_lab al
                JOIN labs l ON l.id = al.lab_id
                WHERE al.album_id IN ($placeholders)

                UNION ALL

                SELECT album_id, 'location' as type, l.name
                FROM album_location al
                JOIN locations l ON l.id = al.location_id
                WHERE al.album_id IN ($placeholders)
            ");

            // Execute with repeated album IDs for each UNION
            $params = array_merge($albumIds, $albumIds, $albumIds, $albumIds, $albumIds, $albumIds);
            $stmt->execute($params);
            $allEquipment = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group by album and type
            foreach ($allEquipment as $item) {
                $albumId = (int)$item['album_id'];
                $type = $item['type'];
                $name = trim($item['name']);

                $key = match($type) {
                    'camera' => 'cameras',
                    'lens' => 'lenses',
                    'film' => 'film',
                    'developer' => 'developers',
                    'lab' => 'labs',
                    'location' => 'locations',
                    default => null
                };

                if ($key && isset($equipmentByAlbum[$albumId])) {
                    $equipmentByAlbum[$albumId][$key][] = $name;
                }
            }
        } catch (\Throwable) {
            // Equipment tables might not exist, return empty
        }

        return $equipmentByAlbum;
    }

    /**
     * Eager load tags for multiple albums
     *
     * @param PDO $pdo Database connection
     * @param array $albumIds Array of album IDs
     * @return array Tags grouped by album_id
     */
    public static function eagerLoadTags(PDO $pdo, array $albumIds): array
    {
        if (empty($albumIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($albumIds), '?'));

        $stmt = $pdo->prepare("
            SELECT at.album_id, t.*
            FROM tags t
            JOIN album_tag at ON at.tag_id = t.id
            WHERE at.album_id IN ($placeholders)
            ORDER BY t.name ASC
        ");

        $stmt->execute($albumIds);
        $allTags = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by album_id
        $tagsByAlbum = [];
        foreach ($allTags as $tag) {
            $albumId = (int)$tag['album_id'];
            unset($tag['album_id']); // Remove join field

            if (!isset($tagsByAlbum[$albumId])) {
                $tagsByAlbum[$albumId] = [];
            }
            $tagsByAlbum[$albumId][] = $tag;
        }

        return $tagsByAlbum;
    }
}
