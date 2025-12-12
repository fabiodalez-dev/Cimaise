<?php
/**
 * Image Rating Service
 *
 * Handles all rating operations: get, set, search, statistics
 */
class ImageRating
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Create rating table if not exists
     */
    public function createTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS plugin_image_ratings (
                image_id INTEGER PRIMARY KEY,
                rating INTEGER NOT NULL CHECK(rating >= 0 AND rating <= 5),
                rated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                rated_by INTEGER NULL,
                FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
            )
        ";

        try {
            $this->db->exec($sql);
            error_log("Image Rating: Table created successfully");
        } catch (PDOException $e) {
            error_log("Image Rating: Error creating table: " . $e->getMessage());
        }
    }

    /**
     * Get rating for an image
     *
     * @return int Rating (0-5), 0 if not rated
     */
    public function getRating(int $imageId): int
    {
        $stmt = $this->db->prepare("SELECT rating FROM plugin_image_ratings WHERE image_id = ?");
        $stmt->execute([$imageId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? (int)$result['rating'] : 0;
    }

    /**
     * Set rating for an image
     */
    public function setRating(int $imageId, int $rating, ?int $userId = null): bool
    {
        if ($rating < 0 || $rating > 5) {
            error_log("Image Rating: Invalid rating value {$rating}");
            return false;
        }

        try {
            $sql = "
                INSERT OR REPLACE INTO plugin_image_ratings (image_id, rating, rated_by, rated_at)
                VALUES (?, ?, ?, datetime('now'))
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$imageId, $rating, $userId]);

            return true;
        } catch (PDOException $e) {
            error_log("Image Rating: Error setting rating: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get images by rating
     *
     * @param int $rating Exact rating or minimum if $exact = false
     * @param bool $exact Match exact rating
     * @return array Image IDs
     */
    public function getImagesByRating(int $rating, bool $exact = true): array
    {
        if ($exact) {
            $sql = "SELECT image_id FROM plugin_image_ratings WHERE rating = ?";
            $params = [$rating];
        } else {
            $sql = "SELECT image_id FROM plugin_image_ratings WHERE rating >= ?";
            $params = [$rating];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'image_id');
    }

    /**
     * Get rating statistics
     *
     * @return array [avg, total, distribution]
     */
    public function getStatistics(): array
    {
        // Average rating
        $stmt = $this->db->query("SELECT AVG(rating) as avg, COUNT(*) as total FROM plugin_image_ratings WHERE rating > 0");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Distribution
        $stmt = $this->db->query("
            SELECT rating, COUNT(*) as count
            FROM plugin_image_ratings
            WHERE rating > 0
            GROUP BY rating
            ORDER BY rating DESC
        ");
        $distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'average' => round($stats['avg'] ?? 0, 2),
            'total_rated' => $stats['total'] ?? 0,
            'distribution' => $distribution
        ];
    }

    /**
     * Bulk set rating for multiple images
     */
    public function bulkSetRating(array $imageIds, int $rating, ?int $userId = null): int
    {
        if (empty($imageIds) || $rating < 0 || $rating > 5) {
            return 0;
        }

        $success = 0;

        foreach ($imageIds as $imageId) {
            if ($this->setRating($imageId, $rating, $userId)) {
                $success++;
            }
        }

        return $success;
    }

    /**
     * Delete rating for image
     */
    public function deleteRating(int $imageId): bool
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM plugin_image_ratings WHERE image_id = ?");
            $stmt->execute([$imageId]);
            return true;
        } catch (PDOException $e) {
            error_log("Image Rating: Error deleting rating: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get top rated images
     *
     * @param int $limit Number of images to return
     * @return array Image IDs
     */
    public function getTopRated(int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT image_id
            FROM plugin_image_ratings
            WHERE rating > 0
            ORDER BY rating DESC, rated_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'image_id');
    }

    /**
     * Get unrated images
     */
    public function getUnratedImages(?int $albumId = null): array
    {
        $sql = "
            SELECT i.id
            FROM images i
            LEFT JOIN plugin_image_ratings r ON i.id = r.image_id
            WHERE (r.rating IS NULL OR r.rating = 0)
        ";

        $params = [];

        if ($albumId) {
            $sql .= " AND i.album_id = ?";
            $params[] = $albumId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
    }
}
