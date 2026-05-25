<?php
/**
 * Image Rating Service
 *
 * Handles all rating operations: get, set, search, statistics
 */
class ImageRating
{
    private PDO $db;
    private bool $isSqlite;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->isSqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    /**
     * Create rating table if not exists.
     *
     * NOTE: user_id = 0 is the reserved sentinel for the anonymous / system rater.
     * Real users are expected to have id > 0. Storing 0 (instead of NULL) ensures
     * the composite UNIQUE(image_id, rated_by) constraint actually deduplicates
     * anonymous votes - SQL standard treats every NULL as distinct in UNIQUE
     * indexes, which would let anonymous rows grow unbounded.
     */
    public function createTable(): void
    {
        if ($this->isSqlite) {
            // rated_by INTEGER NOT NULL DEFAULT 0 - 0 = reserved anonymous/system sentinel
            $sql = "
                CREATE TABLE IF NOT EXISTS plugin_image_ratings (
                    image_id INTEGER NOT NULL,
                    rating INTEGER NOT NULL CHECK(rating >= 0 AND rating <= 5),
                    rated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    rated_by INTEGER NOT NULL DEFAULT 0,
                    UNIQUE(image_id, rated_by),
                    FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
                )
            ";
        } else {
            // rated_by INT NOT NULL DEFAULT 0 - 0 = reserved anonymous/system sentinel
            $sql = "
                CREATE TABLE IF NOT EXISTS plugin_image_ratings (
                    image_id INT NOT NULL,
                    rating INT NOT NULL,
                    rated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    rated_by INT NOT NULL DEFAULT 0,
                    UNIQUE KEY uniq_image_rated_by (image_id, rated_by),
                    FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ";
        }

        try {
            $this->db->exec($sql);
            error_log("Image Rating: Table created successfully");
        } catch (PDOException $e) {
            error_log("Image Rating: Error creating table: " . $e->getMessage());
        }

        // Migrate older installs that still have the legacy schema
        // (single-column PRIMARY KEY on image_id, or nullable rated_by).
        $this->migrateSchema();
    }

    /**
     * Migrate legacy schema to the per-user rating schema.
     *
     * Detects pre-existing installs where plugin_image_ratings had a single-column
     * PRIMARY KEY on image_id (or a nullable rated_by) and rebuilds the table with
     * a composite UNIQUE(image_id, rated_by) and rated_by NOT NULL DEFAULT 0.
     *
     * Idempotent: a schema version marker is stored in the settings table so the
     * migration only runs once per install. The structural checks below ALSO make
     * the migration safe to re-run even without the marker (no-op when the schema
     * is already current).
     */
    private function migrateSchema(): void
    {
        try {
            $marker = $this->readSchemaMarker();
            if ($marker === '2') {
                return; // already migrated to v2 schema
            }

            if ($this->isSqlite) {
                $this->migrateSchemaSqlite();
            } else {
                $this->migrateSchemaMysql();
            }

            $this->writeSchemaMarker('2');
        } catch (PDOException $e) {
            error_log("Image Rating: Schema migration error: " . $e->getMessage());
        }
    }

    /**
     * SQLite migration path. Uses the rename -> create new -> INSERT SELECT ->
     * drop pattern because SQLite cannot DROP PRIMARY KEY / MODIFY COLUMN.
     */
    private function migrateSchemaSqlite(): void
    {
        $cols = $this->db->query("PRAGMA table_info(plugin_image_ratings)")->fetchAll(PDO::FETCH_ASSOC);
        if (!$cols) {
            return; // fresh install - CREATE TABLE already handled it
        }

        $hasLegacyPk = false;
        $ratedByNullable = false;
        foreach ($cols as $c) {
            if ((int)$c['pk'] !== 0) {
                $hasLegacyPk = true;
            }
            if ($c['name'] === 'rated_by' && (int)$c['notnull'] === 0) {
                $ratedByNullable = true;
            }
        }

        $hasCompositeUnique = false;
        $idx = $this->db->query("PRAGMA index_list(plugin_image_ratings)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($idx as $i) {
            if ((int)$i['unique'] !== 1) {
                continue;
            }
            $info = $this->db->query("PRAGMA index_info(" . $this->db->quote($i['name']) . ")")->fetchAll(PDO::FETCH_ASSOC);
            $names = array_column($info, 'name');
            sort($names);
            if ($names === ['image_id', 'rated_by']) {
                $hasCompositeUnique = true;
                break;
            }
        }

        if (!$hasLegacyPk && !$ratedByNullable && $hasCompositeUnique) {
            return; // already current
        }

        // Consolidate NULL rated_by BEFORE the rebuild so the new NOT NULL column
        // accepts every row during the INSERT SELECT step.
        $this->db->exec("UPDATE plugin_image_ratings SET rated_by = 0 WHERE rated_by IS NULL");

        // Collapse duplicate (image_id, rated_by) rows keeping the most recent rated_at.
        $this->db->exec("
            DELETE FROM plugin_image_ratings
            WHERE rowid NOT IN (
                SELECT rowid FROM (
                    SELECT rowid,
                           ROW_NUMBER() OVER (
                               PARTITION BY image_id, rated_by
                               ORDER BY rated_at DESC, rowid DESC
                           ) AS rn
                    FROM plugin_image_ratings
                )
                WHERE rn = 1
            )
        ");

        $this->db->beginTransaction();
        try {
            $this->db->exec("ALTER TABLE plugin_image_ratings RENAME TO plugin_image_ratings_old");
            // FK ON DELETE CASCADE on images(id) is preserved in the rebuild.
            $this->db->exec("
                CREATE TABLE plugin_image_ratings (
                    image_id INTEGER NOT NULL,
                    rating INTEGER NOT NULL CHECK(rating >= 0 AND rating <= 5),
                    rated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    rated_by INTEGER NOT NULL DEFAULT 0,
                    UNIQUE(image_id, rated_by),
                    FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
                )
            ");
            $this->db->exec("
                INSERT INTO plugin_image_ratings (image_id, rating, rated_at, rated_by)
                SELECT image_id, rating, rated_at, COALESCE(rated_by, 0)
                FROM plugin_image_ratings_old
            ");
            $this->db->exec("DROP TABLE plugin_image_ratings_old");
            $this->db->commit();
            error_log("Image Rating: SQLite schema migrated to v2");
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * MySQL migration path. Drops legacy PRIMARY KEY, tightens rated_by, adds
     * composite UNIQUE. The pre-DDL UPDATE that consolidates NULLs runs FIRST
     * (and wrapped in a transaction) so the subsequent ALTER ... MODIFY ...
     * NOT NULL does not fail on strict-mode MySQL.
     */
    private function migrateSchemaMysql(): void
    {
        $stmt = $this->db->query("
            SELECT COUNT(*) AS c
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'plugin_image_ratings'
              AND INDEX_NAME = 'PRIMARY'
        ");
        $hasLegacyPk = ((int)($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0)) > 0;

        $stmt = $this->db->query("
            SELECT IS_NULLABLE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'plugin_image_ratings'
              AND COLUMN_NAME = 'rated_by'
        ");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return; // table not present
        }
        $ratedByNullable = strtoupper($row['IS_NULLABLE']) === 'YES';

        $stmt = $this->db->query("
            SELECT COUNT(*) AS c
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'plugin_image_ratings'
              AND INDEX_NAME = 'uniq_image_rated_by'
        ");
        $hasCompositeUnique = ((int)($stmt->fetch(PDO::FETCH_ASSOC)['c'] ?? 0)) > 0;

        if (!$hasLegacyPk && !$ratedByNullable && $hasCompositeUnique) {
            return; // already current
        }

        // Step (a): consolidate NULL rated_by + collapse duplicates BEFORE the
        // ALTER, otherwise strict-mode MySQL rejects the NOT NULL conversion.
        $this->db->beginTransaction();
        try {
            $this->db->exec("UPDATE plugin_image_ratings SET rated_by = 0 WHERE rated_by IS NULL");
            $this->db->exec("
                DELETE r1 FROM plugin_image_ratings r1
                INNER JOIN plugin_image_ratings r2
                    ON r1.image_id = r2.image_id
                   AND r1.rated_by = r2.rated_by
                   AND (r1.rated_at < r2.rated_at
                        OR (r1.rated_at = r2.rated_at AND r1.rating < r2.rating))
            ");
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Step (b): drop legacy PRIMARY KEY, step (c): tighten column,
        // step (d): add composite UNIQUE. (MySQL DDL is auto-committed.)
        if ($hasLegacyPk) {
            $this->db->exec("ALTER TABLE plugin_image_ratings DROP PRIMARY KEY");
        }
        $this->db->exec("ALTER TABLE plugin_image_ratings MODIFY rated_by INT NOT NULL DEFAULT 0");
        if (!$hasCompositeUnique) {
            $this->db->exec("ALTER TABLE plugin_image_ratings ADD UNIQUE KEY uniq_image_rated_by (image_id, rated_by)");
        }

        error_log("Image Rating: MySQL schema migrated to v2");
    }

    /**
     * Read the schema version marker from the settings table (best effort).
     */
    private function readSchemaMarker(): string
    {
        try {
            $stmt = $this->db->prepare("SELECT value FROM settings WHERE `key` = ?");
            $stmt->execute(['plugin_image_ratings_schema']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row && isset($row['value']) ? (string)$row['value'] : '';
        } catch (PDOException $e) {
            return ''; // settings table missing - rely on structural checks
        }
    }

    /**
     * Persist the schema version marker (best effort).
     */
    private function writeSchemaMarker(string $version): void
    {
        try {
            if ($this->isSqlite) {
                $stmt = $this->db->prepare("INSERT OR REPLACE INTO settings (`key`, value) VALUES (?, ?)");
            } else {
                $stmt = $this->db->prepare("INSERT INTO settings (`key`, value) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE value = VALUES(value)");
            }
            $stmt->execute(['plugin_image_ratings_schema', $version]);
        } catch (PDOException $e) {
            // settings table not available - silent skip
        }
    }

    /**
     * Get rating for an image.
     *
     * Returns the most recent rating across all raters for this image; for
     * per-user lookup add a userId parameter and filter by rated_by.
     *
     * @return int Rating (0-5), 0 if not rated
     */
    public function getRating(int $imageId): int
    {
        $stmt = $this->db->prepare(
            "SELECT rating FROM plugin_image_ratings WHERE image_id = ? ORDER BY rated_at DESC LIMIT 1"
        );
        $stmt->execute([$imageId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? (int)$result['rating'] : 0;
    }

    /**
     * Check whether any rating row exists for this image (regardless of rater).
     * Used by upload-time initialization to avoid clobbering an existing rating.
     */
    public function hasAnyRating(int $imageId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM plugin_image_ratings WHERE image_id = ? LIMIT 1"
        );
        $stmt->execute([$imageId]);
        return (bool)$stmt->fetchColumn();
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

        // Coerce anonymous/system raters to the sentinel value 0. Required because
        // SQL UNIQUE indexes treat every NULL as distinct (per the SQL standard),
        // which would let anonymous votes grow unbounded under
        // UNIQUE(image_id, rated_by).
        $userId = $userId ?? 0;

        try {
            if ($this->isSqlite) {
                $sql = "INSERT OR REPLACE INTO plugin_image_ratings (image_id, rating, rated_by, rated_at)
                    VALUES (?, ?, ?, datetime('now'))";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$imageId, $rating, $userId]);
            } else {
                $sql = "INSERT INTO plugin_image_ratings (image_id, rating, rated_by, rated_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE rating = VALUES(rating), rated_by = VALUES(rated_by), rated_at = NOW()";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$imageId, $rating, $userId]);
            }

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
            'average' => round((float)($stats['avg'] ?? 0), 2),
            'total_rated' => (int)($stats['total'] ?? 0),
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
