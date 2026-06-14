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
     * Reduced from 500 to 150 to limit memory usage and improve TTFB.
     * For very large libraries, use progressive loading API instead.
     */
    private const MAX_FETCH_LIMIT = 150;

    /**
     * Default cap for the full-grid path (getAllImages) when no explicit limit
     * is configured (masonry_max_images = 0/unset). 0 used to mean "unlimited"
     * — fetching, LQIP-inlining and caching the ENTIRE library on the home
     * page. NOTE: the admin settings UI may still describe 0 as "unlimited";
     * in code 0 now means "use this sane default".
     */
    private const DEFAULT_GRID_LIMIT = 150;

    /**
     * Hard ceiling for an EXPLICIT admin-configured grid limit. Matches the
     * 0..5000 clamp applied to home.masonry_max_images in PageController /
     * CacheWarmService, so admins who deliberately set a high value get it.
     */
    private const MAX_GRID_LIMIT = 5000;

    /**
     * Diversity headroom: how many extra rows to fetch on top of the requested
     * limit so we have a small pool to enforce album-per-image variety.
     * 1.5x was chosen instead of the previous 3x — empirically a 50% overhead
     * is plenty for diversity selection on realistic libraries and avoids
     * pulling hundreds of unused rows for a 12-image initial render.
     */
    private const DIVERSITY_HEADROOM = 1.5;

    /**
     * Calculate optimal fetch limit based on requested limit.
     * Multiplier kept small to avoid reading 150 rows just to display 12.
     */
    private function calculateFetchLimit(int $requestedLimit): int
    {
        $padded = (int) ceil($requestedLimit * self::DIVERSITY_HEADROOM);
        // Always fetch at least a few extra rows to avoid degenerate cases on tiny limits
        $padded = max($padded, $requestedLimit + 6);
        return min($padded, self::MAX_FETCH_LIMIT);
    }

    /**
     * Normalize category_slugs for cross-database consistency.
     * GROUP_CONCAT order is non-deterministic (SQLite vs MySQL), so we sort and dedupe in PHP.
     * This ensures stable cache keys and ETags regardless of database driver.
     *
     * @param array $images Array of image records with category_slugs field
     * @return array Images with normalized category_slugs
     */
    private function normalizeCategorySlugs(array $images): array
    {
        foreach ($images as &$image) {
            if (!empty($image['category_slugs'])) {
                $slugs = array_unique(explode(',', $image['category_slugs']));
                sort($slugs);
                $image['category_slugs'] = implode(',', $slugs);
            }
        }
        return $images;
    }

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
        // REFACTORED: Use LEFT JOIN + GROUP BY instead of correlated subquery to avoid N+1 performance hit
        $stmt = $pdo->prepare("
            SELECT i.id, i.album_id, i.original_path, i.width, i.height, i.alt_text, i.caption,
                   a.title as album_title, a.slug as album_slug, a.excerpt as album_description,
                   GROUP_CONCAT(c.slug) as category_slugs
            FROM images i
            JOIN albums a ON a.id = i.album_id
            LEFT JOIN album_category ac ON ac.album_id = a.id
            LEFT JOIN categories c ON c.id = ac.category_id
            WHERE a.is_published = 1
              AND (:include_nsfw = 1 OR a.is_nsfw = 0)
              AND (a.password_hash IS NULL OR a.password_hash = '')
            GROUP BY i.id
            ORDER BY a.id, i.sort_order
            LIMIT :max_fetch
        ");
        $stmt->bindValue(':include_nsfw', $includeNsfw ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(':max_fetch', $this->calculateFetchLimit($limit), \PDO::PARAM_INT);
        $stmt->execute();
        $rawImages = $this->normalizeCategorySlugs($stmt->fetchAll(\PDO::FETCH_ASSOC));

        // True library size: the fetch above is LIMIT-capped, so count($rawImages) would
        // under-report the total and produce a wrong total_images / a spurious load-more
        // spinner for small libraries. Count with the same predicates, no limit.
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

        // ETag stability: a truly random shuffle made every cache regeneration
        // produce a different image order, so the page_cache data_hash (and the
        // resulting ETag) changed even when content was identical — defeating
        // 304 revalidation. Seed the global Mt19937 PRNG (which shuffle() and
        // array_rand() use) deterministically from the current UTC date plus
        // the candidate image-id set: the order is stable within a day for a
        // given content state, but still rotates daily and on content changes.
        // Randomness is restored with mt_srand() after selection (below).
        mt_srand(crc32(gmdate('Y-m-d') . ':' . implode(',', array_column($rawImages, 'id'))));

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

        // Final shuffle for visual variety (still under the deterministic seed)
        shuffle($selectedImages);

        // Restore a random seed so the deterministic sequence above does not
        // leak into other code using the global PRNG during this request.
        mt_srand();

        return [
            'images' => $selectedImages,
            'shownImageIds' => $shownImageIds,
            'shownAlbumIds' => $shownAlbumIds,
            'totalAlbums' => $totalAlbums,
            'totalImages' => $totalImages,
        ];
    }

    /**
     * Get all images for masonry-style layouts.
     * Ordered by newest albums first.
     * Implements infinite looping: if limit exceeds available images, it restarts from the beginning.
     *
     * @param int $limit Maximum images to return (0 = use DEFAULT_GRID_LIMIT)
     * @param bool $includeNsfw Include NSFW albums
     * @return array{images: array, totalImages: int, hasMore: bool}
     */
    public function getAllImages(int $limit = 0, bool $includeNsfw = false): array
    {
        // 0/unset no longer means "unlimited" (which loaded the entire library
        // unbounded); it falls back to a sane default. Explicit values are
        // honoured up to MAX_GRID_LIMIT (the admin-side 5000 clamp).
        $limit = $limit > 0 ? min($limit, self::MAX_GRID_LIMIT) : self::DEFAULT_GRID_LIMIT;
        $pdo = $this->db->pdo();

        // Base query for reuse
        $baseQuery = "
            SELECT i.id, i.album_id, i.original_path, i.width, i.height, i.alt_text, i.caption,
                   a.title as album_title, a.slug as album_slug, a.excerpt as album_description,
                   GROUP_CONCAT(c.slug) as category_slugs
            FROM images i
            JOIN albums a ON a.id = i.album_id
            LEFT JOIN album_category ac ON ac.album_id = a.id
            LEFT JOIN categories c ON c.id = ac.category_id
            WHERE a.is_published = 1
              AND (:include_nsfw = 1 OR a.is_nsfw = 0)
              AND (a.password_hash IS NULL OR a.password_hash = '')
        ";

        // Query 1: Fetch initial batch
        // REFACTORED: Removed subquery, used LEFT JOIN + GROUP BY, Sorted by Date
        $sql = $baseQuery . "
            GROUP BY i.id
            ORDER BY a.published_at DESC, a.id DESC, i.sort_order ASC
            LIMIT :max_fetch
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':include_nsfw', $includeNsfw ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(':max_fetch', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        $images = $this->normalizeCategorySlugs($stmt->fetchAll(\PDO::FETCH_ASSOC));

        // NOTE: deliberately NO wrap-around padding here. This method is the unique-image
        // path for the home grid templates (masonry/editorial/justified/bento/...), so it
        // must never repeat a photo to reach `limit`. If fewer unique images exist than
        // `limit`, callers simply get all of them. Dedup by id as a hard guarantee.
        $byId = [];
        foreach ($images as $img) {
            $byId[$img['id']] = $img;
        }
        $images = array_values($byId);

        // Total count of UNIQUE images. The fetch above is always LIMIT-capped
        // now (default or explicit), so an uncapped COUNT is required for an
        // accurate total_images value.
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

        // Spread images out so two photos from the same album are never adjacent on the
        // home grid (the DB order groups an album's images together, which looks like
        // repeated/identical photos). Applies to every home template that uses this path.
        $images = $this->interleaveByAlbum($images);

        return [
            'images' => $images,
            'totalImages' => $totalImages,
            'hasMore' => $totalImages > count($images), // more remain only if not all loaded
        ];
    }

    /**
     * Reorder images so consecutive items come from different albums where possible.
     * Greedy "most-remaining-first, never repeat the previous album" scheduling — the
     * standard way to spread a multiset apart and minimise same-album adjacency.
     *
     * @param array<int,array<string,mixed>> $images
     * @return array<int,array<string,mixed>>
     */
    private function interleaveByAlbum(array $images): array
    {
        if (count($images) < 3) {
            return $images;
        }

        /** @var array<int|string,array<int,array<string,mixed>>> $buckets */
        $buckets = [];
        foreach ($images as $img) {
            $buckets[$img['album_id'] ?? 0][] = $img;
        }
        if (count($buckets) < 2) {
            return $images;
        }

        $result = [];
        $lastAlbum = null;
        $remaining = count($images);
        while ($remaining > 0) {
            $pick = null;
            $pickCount = -1;
            foreach ($buckets as $aid => $items) {
                $n = count($items);
                if ($n === 0 || $aid === $lastAlbum) {
                    continue;
                }
                if ($n > $pickCount) {
                    $pickCount = $n;
                    $pick = $aid;
                }
            }
            // Only the previous album still has items — unavoidable, place it.
            if ($pick === null) {
                foreach ($buckets as $aid => $items) {
                    if (count($items) > 0) {
                        $pick = $aid;
                        break;
                    }
                }
            }
            $result[] = array_shift($buckets[$pick]);
            if (empty($buckets[$pick])) {
                unset($buckets[$pick]); // drop drained buckets so the scan stays ~O(n)
            }
            $lastAlbum = $pick;
            $remaining--;
        }

        return $result;
    }

    /**
     * Get more images for masonry progressive loading.
     * Ordered by newest albums first.
     * Implements infinite looping.
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
        $excludeIds = array_filter(array_map('intval', $excludeImageIds), fn ($id) => $id > 0);
        if (!empty($excludeIds)) {
            $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
            $excludeClause = " AND i.id NOT IN ({$placeholders})";
        }

        // Base Query Structure
        $baseSql = "
            SELECT i.id, i.album_id, i.original_path, i.width, i.height, i.alt_text, i.caption,
                   a.title as album_title, a.slug as album_slug, a.excerpt as album_description,
                   GROUP_CONCAT(c.slug) as category_slugs
            FROM images i
            JOIN albums a ON a.id = i.album_id
            LEFT JOIN album_category ac ON ac.album_id = a.id
            LEFT JOIN categories c ON c.id = ac.category_id
            WHERE a.is_published = 1
              AND (? = 1 OR a.is_nsfw = 0)
              AND (a.password_hash IS NULL OR a.password_hash = '')
        ";

        // 1. Try to fetch new images (respecting exclusions)
        $sql = $baseSql . "
              {$excludeClause}
            GROUP BY i.id
            ORDER BY a.published_at DESC, a.id DESC, i.sort_order ASC
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
        $images = $this->normalizeCategorySlugs($stmt->fetchAll(\PDO::FETCH_ASSOC));

        // 2. Loop logic: If we didn't get enough images, fetch from the start
        // ignoring exclusions (simulating a loop back to the top)
        if (count($images) < $limit) {
            $needed = $limit - count($images);

            $loopSql = $baseSql . "
                GROUP BY i.id
                ORDER BY a.published_at DESC, a.id DESC, i.sort_order ASC
                LIMIT ?
            ";

            $loopStmt = $pdo->prepare($loopSql);
            $loopStmt->bindValue(1, $includeNsfw ? 1 : 0, \PDO::PARAM_INT);
            $loopStmt->bindValue(2, $needed, \PDO::PARAM_INT);
            $loopStmt->execute();
            $loopImages = $this->normalizeCategorySlugs($loopStmt->fetchAll(\PDO::FETCH_ASSOC));

            $images = array_merge($images, $loopImages);

            // Edge case: If DB has very few images (fewer than needed), we might still be short.
            // Duplicate them to fill the request if necessary, or just return what we have.
            // For now, returning what we found (partial + wrap-around) is usually sufficient.
        }

        $hasMore = $limit > 0 && count($images) >= $limit;

        return [
            'images' => $images,
            'hasMore' => $hasMore,
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
        // REFACTORED: Use LEFT JOIN + GROUP BY instead of correlated subquery to avoid N+1 performance hit
        $stmt = $pdo->prepare("
            SELECT i.id, i.album_id, i.original_path, i.width, i.height, i.alt_text, i.caption,
                   a.title as album_title, a.slug as album_slug, a.excerpt as album_description,
                   GROUP_CONCAT(c.slug) as category_slugs
            FROM images i
            JOIN albums a ON a.id = i.album_id
            LEFT JOIN album_category ac ON ac.album_id = a.id
            LEFT JOIN categories c ON c.id = ac.category_id
            WHERE a.is_published = 1
              AND (:include_nsfw = 1 OR a.is_nsfw = 0)
              AND (a.password_hash IS NULL OR a.password_hash = '')
            GROUP BY i.id
            ORDER BY a.id, i.sort_order
            LIMIT :max_fetch
        ");
        $stmt->bindValue(':include_nsfw', $includeNsfw ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(':max_fetch', $this->calculateFetchLimit($limit), \PDO::PARAM_INT);
        $stmt->execute();
        $allImages = $this->normalizeCategorySlugs($stmt->fetchAll(\PDO::FETCH_ASSOC));

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
        foreach ($unprocessedNewAlbumIds as $albumId) {
            $remainingFromUnprocessed += count($newAlbumImages[$albumId] ?? []);
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
