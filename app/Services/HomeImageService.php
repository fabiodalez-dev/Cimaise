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
        $pdo = $this->db->pdo();

        // Fetch images from published albums with LIMIT to prevent memory issues
        // Uses ORDER BY album_id to improve album distribution within the limit
        $stmt = $pdo->prepare("
            SELECT i.*, a.title as album_title, a.slug as album_slug, a.id as album_id,
                   a.excerpt as album_description,
                   (SELECT c2.slug FROM categories c2 WHERE c2.id = a.category_id) as category_slug
            FROM images i
            JOIN albums a ON a.id = i.album_id
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
        $pdo = $this->db->pdo();

        // Fetch eligible images with LIMIT to prevent memory issues
        $stmt = $pdo->prepare("
            SELECT i.*, a.title as album_title, a.slug as album_slug, a.id as album_id,
                   a.excerpt as album_description,
                   (SELECT c2.slug FROM categories c2 WHERE c2.id = a.category_id) as category_slug
            FROM images i
            JOIN albums a ON a.id = i.album_id
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

        // Step 1: Pick one image from each new album
        $newAlbumIdList = array_keys($newAlbumImages);
        shuffle($newAlbumIdList);

        foreach ($newAlbumIdList as $albumId) {
            if (count($selectedImages) >= $limit) {
                break;
            }
            $albumImages = $newAlbumImages[$albumId];
            $randomIndex = array_rand($albumImages);
            $selectedImages[] = $albumImages[$randomIndex];
            $newAlbumIds[] = $albumId;
        }

        // Step 2: If batch not full, fill with filler images
        $currentCount = count($selectedImages);
        if ($currentCount < $limit && !empty($fillerImages)) {
            $need = $limit - $currentCount;
            shuffle($fillerImages);
            $additionalImages = array_slice($fillerImages, 0, $need);
            $selectedImages = array_merge($selectedImages, $additionalImages);
        }

        // Calculate remaining images after this batch
        $totalAvailable = count($fillerImages) + array_sum(array_map('count', $newAlbumImages));
        $totalRemaining = max(0, $totalAvailable - count($selectedImages));
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
