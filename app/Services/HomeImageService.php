<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;

/**
 * Service for progressive image loading with album diversity priority.
 *
 * This service ensures that images from all albums are represented before
 * showing multiple images from the same album. Useful for home pages that
 * need to showcase portfolio variety.
 *
 * Algorithm:
 * 1. Initial load: 1 image per album (up to limit), fill remainder if needed
 * 2. Subsequent loads: prioritize albums not yet shown, then fill with any remaining
 */
class HomeImageService
{
    private const DEFAULT_INITIAL_LIMIT = 30;
    private const DEFAULT_BATCH_LIMIT = 20;

    /**
     * Maximum images to fetch from database per query.
     * Limits memory usage while still allowing album diversity for most portfolios.
     * For very large libraries, some albums may not be represented in initial load.
     */
    private const MAX_FETCH_LIMIT = 500;

    public function __construct(private Database $db)
    {
    }

    /**
     * Get initial batch of images ensuring album diversity.
     * Returns 1 image per album, then fills to reach limit.
     *
     * @param int $limit Maximum images to return
     * @param bool $includeNsfw Include NSFW albums
     * @return array{images: array, shownImageIds: int[], shownAlbumIds: int[], totalAlbums: int, totalImages: int}
     */
    public function getInitialImages(int $limit = self::DEFAULT_INITIAL_LIMIT, bool $includeNsfw = false): array
    {
        $limit = max(1, min($limit, self::MAX_FETCH_LIMIT));
        $pdo = $this->db->pdo();

        // Fetch images from published albums with LIMIT to prevent memory issues
        // Uses ORDER BY album_id to improve album distribution within the limit
        $stmt = $pdo->prepare("
            SELECT i.id, i.album_id, i.original_path, i.width, i.height, i.alt_text, i.caption,
                   a.title as album_title, a.slug as album_slug, a.excerpt as album_description,
                   c.slug as category_slug
            FROM images i
            JOIN albums a ON a.id = i.album_id
            LEFT JOIN categories c ON c.id = a.category_id
            WHERE a.is_published = 1
              AND (:include_nsfw = 1 OR a.is_nsfw = 0)
              AND (a.password_hash IS NULL OR a.password_hash = '')
            ORDER BY a.id, i.sort_order
            LIMIT :max_fetch
        ");
        $stmt->bindValue(':include_nsfw', $includeNsfw ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(':max_fetch', self::MAX_FETCH_LIMIT, \PDO::PARAM_INT);
        $stmt->execute();
        $rawImages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $totalImages = count($rawImages);

        // Group by album
        $imagesByAlbum = [];
        foreach ($rawImages as $image) {
            $albumId = (int) $image['album_id'];
            if (!isset($imagesByAlbum[$albumId])) {
                $imagesByAlbum[$albumId] = [];
            }
            $imagesByAlbum[$albumId][] = $image;
        }

        $totalAlbums = count($imagesByAlbum);

        // Step 1: Pick one random image per album
        $selectedImages = [];
        $shownImageIds = [];
        $shownAlbumIds = [];

        // Shuffle album order for variety
        $albumIds = array_keys($imagesByAlbum);
        shuffle($albumIds);

        foreach ($albumIds as $albumId) {
            if (count($selectedImages) >= $limit) {
                break;
            }
            $albumImages = $imagesByAlbum[$albumId];
            $randomIndex = array_rand($albumImages);
            $selectedImage = $albumImages[$randomIndex];
            $selectedImages[] = $selectedImage;
            $shownImageIds[] = (int) $selectedImage['id'];
            $shownAlbumIds[] = $albumId;
        }

        // Step 2: If we need more images, fill from remaining pool
        $currentCount = count($selectedImages);
        if ($currentCount < $limit) {
            $need = $limit - $currentCount;
            $shownImageSet = array_flip($shownImageIds);
            $remainingPool = [];

            foreach ($rawImages as $image) {
                if (!isset($shownImageSet[(int) $image['id']])) {
                    $remainingPool[] = $image;
                }
            }

            if (!empty($remainingPool)) {
                shuffle($remainingPool);
                $additionalImages = array_slice($remainingPool, 0, $need);
                foreach ($additionalImages as $img) {
                    $selectedImages[] = $img;
                    $shownImageIds[] = (int) $img['id'];
                    // Don't add to shownAlbumIds here - these are filler images from already-shown albums
                }
            }
        }

        // Final shuffle for visual variety
        shuffle($selectedImages);

        return [
            'images' => $selectedImages,
            'shownImageIds' => $shownImageIds,
            'shownAlbumIds' => $shownAlbumIds,
            'totalAlbums' => $totalAlbums,
            'totalImages' => $totalImages,
        ];
    }

    /**
     * Get all images for masonry-style layouts (no album diversity, load all).
     * Returns all available images shuffled for visual variety.
     *
     * @param int $limit Maximum images to return (0 = all up to MAX_FETCH_LIMIT)
     * @param bool $includeNsfw Include NSFW albums
     * @return array{images: array, totalImages: int, hasMore: bool}
     */
    public function getAllImages(int $limit = 0, bool $includeNsfw = false): array
    {
        $limit = $limit > 0 ? min($limit, self::MAX_FETCH_LIMIT) : self::MAX_FETCH_LIMIT;
        $pdo = $this->db->pdo();

        // Fetch ALL images from published albums
        $stmt = $pdo->prepare("
            SELECT i.id, i.album_id, i.original_path, i.width, i.height, i.alt_text, i.caption,
                   a.title as album_title, a.slug as album_slug, a.excerpt as album_description,
                   c.slug as category_slug
            FROM images i
            JOIN albums a ON a.id = i.album_id
            LEFT JOIN categories c ON c.id = a.category_id
            WHERE a.is_published = 1
              AND (:include_nsfw = 1 OR a.is_nsfw = 0)
              AND (a.password_hash IS NULL OR a.password_hash = '')
            ORDER BY RANDOM()
            LIMIT :max_fetch
        ");
        $stmt->bindValue(':include_nsfw', $includeNsfw ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(':max_fetch', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $images = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Get total count
        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM images i
            JOIN albums a ON a.id = i.album_id
            WHERE a.is_published = 1
              AND (:include_nsfw = 1 OR a.is_nsfw = 0)
              AND (a.password_hash IS NULL OR a.password_hash = '')
        ");
        $countStmt->bindValue(':include_nsfw', $includeNsfw ? 1 : 0, \PDO::PARAM_INT);
        $countStmt->execute();
        $totalImages = (int) $countStmt->fetchColumn();

        return [
            'images' => $images,
            'totalImages' => $totalImages,
            'hasMore' => $totalImages > count($images),
        ];
    }

    /**
     * Get more images for masonry progressive loading.
     *
     * @param array $excludeImageIds Image IDs already shown
     * @param int $limit Maximum images to return
     * @param bool $includeNsfw Include NSFW albums
     * @return array{images: array, hasMore: bool}
     */
    public function getMoreMasonryImages(
        array $excludeImageIds = [],
        int $limit = self::DEFAULT_BATCH_LIMIT,
        bool $includeNsfw = false
    ): array {
        $limit = max(1, min($limit, self::MAX_FETCH_LIMIT));
        $pdo = $this->db->pdo();

        // Build exclude clause
        $excludeClause = '';
        $excludeIds = array_filter(array_map('intval', $excludeImageIds), fn($id) => $id > 0);
        if (!empty($excludeIds)) {
            $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $excludeClause = " AND i.id NOT IN ({$placeholders})";
        }

        // Fetch more images excluding already shown
        $sql = "
            SELECT i.id, i.album_id, i.original_path, i.width, i.height, i.alt_text, i.caption,
                   a.title as album_title, a.slug as album_slug, a.excerpt as album_description,
                   c.slug as category_slug
            FROM images i
            JOIN albums a ON a.id = i.album_id
            LEFT JOIN categories c ON c.id = a.category_id
            WHERE a.is_published = 1
              AND (? = 1 OR a.is_nsfw = 0)
              AND (a.password_hash IS NULL OR a.password_hash = '')
              {$excludeClause}
            ORDER BY RANDOM()
            LIMIT ?
        ";

        $stmt = $pdo->prepare($sql);
        $paramIndex = 1;
        $stmt->bindValue($paramIndex++, $includeNsfw ? 1 : 0, \PDO::PARAM_INT);
        foreach ($excludeIds as $id) {
            $stmt->bindValue($paramIndex++, $id, \PDO::PARAM_INT);
        }
        $stmt->bindValue($paramIndex, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $images = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Check if there are more images
        $countSql = "
            SELECT COUNT(*)
            FROM images i
            JOIN albums a ON a.id = i.album_id
            WHERE a.is_published = 1
              AND (? = 1 OR a.is_nsfw = 0)
              AND (a.password_hash IS NULL OR a.password_hash = '')
              {$excludeClause}
        ";
        $countStmt = $pdo->prepare($countSql);
        $paramIndex = 1;
        $countStmt->bindValue($paramIndex++, $includeNsfw ? 1 : 0, \PDO::PARAM_INT);
        foreach ($excludeIds as $id) {
            $countStmt->bindValue($paramIndex++, $id, \PDO::PARAM_INT);
        }
        $countStmt->execute();
        $remainingCount = (int) $countStmt->fetchColumn();

        return [
            'images' => $images,
            'hasMore' => $remainingCount > count($images),
        ];
    }

    /**
     * Get next batch of images prioritizing unrepresented albums.
     *
     * Algorithm:
     * 1. First, get images from albums NOT in excludeAlbumIds
     * 2. If batch not full, fill with random images excluding excludeImageIds
     *
     * @param array $excludeImageIds Image IDs already shown
     * @param array $excludeAlbumIds Album IDs already represented
     * @param int $limit Maximum images to return
     * @param bool $includeNsfw Include NSFW albums
     * @return array{images: array, newAlbumIds: int[], hasMore: bool}
     */
    public function getMoreImages(
        array $excludeImageIds = [],
        array $excludeAlbumIds = [],
        int $limit = self::DEFAULT_BATCH_LIMIT,
        bool $includeNsfw = false
    ): array {
        $limit = max(1, min($limit, self::MAX_FETCH_LIMIT));
        $pdo = $this->db->pdo();

        // Fetch eligible images with LIMIT to prevent memory issues
        $stmt = $pdo->prepare("
            SELECT i.id, i.album_id, i.original_path, i.width, i.height, i.alt_text, i.caption,
                   a.title as album_title, a.slug as album_slug, a.excerpt as album_description,
                   c.slug as category_slug
            FROM images i
            JOIN albums a ON a.id = i.album_id
            LEFT JOIN categories c ON c.id = a.category_id
            WHERE a.is_published = 1
              AND (:include_nsfw = 1 OR a.is_nsfw = 0)
              AND (a.password_hash IS NULL OR a.password_hash = '')
            ORDER BY a.id, i.sort_order
            LIMIT :max_fetch
        ");
        $stmt->bindValue(':include_nsfw', $includeNsfw ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(':max_fetch', self::MAX_FETCH_LIMIT, \PDO::PARAM_INT);
        $stmt->execute();
        $allImages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $excludeImageSet = array_flip(array_map('intval', $excludeImageIds));
        $excludeAlbumSet = array_flip(array_map('intval', $excludeAlbumIds));

        // Separate images into two pools:
        // 1. Images from NEW albums (priority)
        // 2. Images from already-shown albums (filler)
        $newAlbumImages = [];
        $fillerImages = [];

        foreach ($allImages as $image) {
            $imageId = (int) $image['id'];
            $albumId = (int) $image['album_id'];

            // Skip already shown images
            if (isset($excludeImageSet[$imageId])) {
                continue;
            }

            if (!isset($excludeAlbumSet[$albumId])) {
                // Image from a NEW album - priority
                if (!isset($newAlbumImages[$albumId])) {
                    $newAlbumImages[$albumId] = [];
                }
                $newAlbumImages[$albumId][] = $image;
            } else {
                // Image from already-shown album - filler
                $fillerImages[] = $image;
            }
        }

        $selectedImages = [];
        $newAlbumIds = [];
        $remainingNewImages = [];
        $unprocessedNewAlbumIds = [];

        // Step 1: Pick one image from each new album
        $newAlbumIdList = array_keys($newAlbumImages);
        shuffle($newAlbumIdList);

        foreach ($newAlbumIdList as $index => $albumId) {
            if (count($selectedImages) >= $limit) {
                $unprocessedNewAlbumIds = array_slice($newAlbumIdList, $index);
                break;
            }
            $albumImages = $newAlbumImages[$albumId];
            $randomIndex = array_rand($albumImages);
            $selectedImages[] = $albumImages[$randomIndex];
            $newAlbumIds[] = $albumId;
            unset($albumImages[$randomIndex]);
            if (!empty($albumImages)) {
                $remainingNewImages = array_merge($remainingNewImages, array_values($albumImages));
            }
        }

        // Step 2: If batch not full, fill with remaining new-album images then filler images
        $currentCount = count($selectedImages);
        $fillPool = array_merge($remainingNewImages, $fillerImages);
        $usedFromFillPool = 0;
        if ($currentCount < $limit && !empty($fillPool)) {
            $need = $limit - $currentCount;
            shuffle($fillPool);
            $additionalImages = array_slice($fillPool, 0, $need);
            $usedFromFillPool = count($additionalImages);
            $selectedImages = array_merge($selectedImages, $additionalImages);
        }

        // Calculate remaining images after this batch
        // Include any unprocessed new-album images when we hit the limit early
        $remainingFromUnprocessed = 0;
        if (!empty($unprocessedNewAlbumIds)) {
            foreach ($unprocessedNewAlbumIds as $albumId) {
                $remainingFromUnprocessed += count($newAlbumImages[$albumId] ?? []);
            }
        }
        $totalRemaining = max(0, $remainingFromUnprocessed + count($fillPool) - $usedFromFillPool);
        $hasMore = $totalRemaining > 0;

        // Final shuffle for visual variety
        shuffle($selectedImages);

        return [
            'images' => $selectedImages,
            'newAlbumIds' => $newAlbumIds,
            'hasMore' => $hasMore,
        ];
    }
}
