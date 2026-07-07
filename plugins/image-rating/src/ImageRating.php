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
    /**
     * Current schema version. Bumped when the table structure changes.
     * Matches the value written by migrateSchema() so a fresh install lands
     * directly on the target schema without a redundant migration pass.
     */
    private const SCHEMA_VERSION = '2';

    public function createTable(): void
    {
        // Schema is already at the current version — skip DDL and migration.
        // This is the hot path on every request; previously each one ran a
        // CREATE TABLE IF NOT EXISTS plus a structural check inside migrateSchema().
        if ($this->readSchemaMarker() === self::SCHEMA_VERSION) {
            return;
        }

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
                    rating INT NOT NULL CHECK(rating >= 0 AND rating <= 5),
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

        // Persist the schema marker after a successful fresh install so
        // subsequent requests take the early-return path above.
        $this->writeSchemaMarker(self::SCHEMA_VERSION);
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
            if ($marker === self::SCHEMA_VERSION) {
                return; // already migrated to current schema
            }

            if ($this->isSqlite) {
                $this->migrateSchemaSqlite();
            } else {
                $this->migrateSchemaMysql();
            }

            $this->writeSchemaMarker(self::SCHEMA_VERSION);
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

        $this->db->beginTransaction();
        try {
            // Consolidate NULL rated_by BEFORE the rebuild so the new NOT NULL column
            // accepts every row during the INSERT SELECT step. Kept INSIDE the
            // transaction so a failure in the subsequent ALTER/CREATE/INSERT can roll
            // back this normalization too - otherwise the migration would leave the
            // table partially transformed.
            $this->db->exec("UPDATE plugin_image_ratings SET rated_by = 0 WHERE rated_by IS NULL");

            // Collapse duplicate (image_id, rated_by) rows keeping the most recent rated_at.
            // Also INSIDE the transaction so rollback restores the deleted duplicates
            // if the rebuild fails downstream.
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
        // Detect the legacy schema: image_id is itself the PRIMARY KEY (no
        // separate `id` AUTO_INCREMENT column). A modern schema either has
        // `id` AUTO_INCREMENT PRIMARY KEY (existing installs) or no PK and a
        // composite UNIQUE on (image_id, rated_by) (fresh CREATE TABLE).
        $stmt = $this->db->query("
            SELECT COUNT(*) AS c
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'plugin_image_ratings'
              AND INDEX_NAME = 'PRIMARY'
              AND COLUMN_NAME = 'image_id'
              AND SEQ_IN_INDEX = 1
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

        // Detect composite UNIQUE on (image_id, rated_by) by exact column
        // coverage — projects may have named the index differently (e.g.
        // uniq_plugin_image_ratings_image_rated_by) so a literal-name match
        // is unreliable. We require the index to span EXACTLY those two
        // columns: counting only matching columns would also accept a wider
        // UNIQUE like (image_id, rated_by, foo), which has different
        // de-duplication semantics and would skip the migration when it
        // shouldn't.
        $stmt = $this->db->query("
            SELECT INDEX_NAME,
                   SUM(CASE WHEN COLUMN_NAME IN ('image_id', 'rated_by') THEN 1 ELSE 0 END) AS matching_cols,
                   COUNT(*) AS total_cols
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'plugin_image_ratings'
              AND NON_UNIQUE = 0
              AND INDEX_NAME <> 'PRIMARY'
            GROUP BY INDEX_NAME
            HAVING matching_cols = 2 AND total_cols = 2
        ");
        $hasCompositeUnique = $stmt->fetch(PDO::FETCH_ASSOC) !== false;

        if (!$hasLegacyPk && $hasCompositeUnique && !$ratedByNullable) {
            // Schema is already current: no legacy PK, composite UNIQUE present,
            // and rated_by is NOT NULL. Nothing to do.
            return;
        }
        // Otherwise fall through: when rated_by is still nullable, anonymous votes
        // can duplicate because MySQL treats NULL ≠ NULL inside a UNIQUE — so we
        // run the consolidation (NULL → 0) and tighten rated_by to NOT NULL even
        // if PK/UNIQUE are already in their modern shape. The DROP/ADD steps
        // below are individually guarded so we don't touch a structure that's
        // already in place.

        // Step (a): consolidate NULL rated_by + collapse duplicates BEFORE the
        // ALTER, otherwise strict-mode MySQL rejects the NOT NULL conversion.
        $this->db->beginTransaction();
        try {
            $this->db->exec("UPDATE plugin_image_ratings SET rated_by = 0 WHERE rated_by IS NULL");
            // Collapse duplicates - including EXACT ties on (rated_at, rating) -
            // keeping the most recent rating per (image_id, rated_by) so the
            // subsequent ADD UNIQUE KEY uniq_image_rated_by cannot fail.
            //
            // MySQL 5.7 compatibility: window functions (ROW_NUMBER) only exist
            // in MySQL 8.0+, so we dedupe with an anti-join "greatest-per-group"
            // instead. A surrogate AUTO_INCREMENT `seq` gives every row a unique
            // rank so exact ties (same image_id+rated_by+rated_at+rating, or even
            // two NULL rated_at) collapse to exactly one survivor. rated_at is
            // COALESCE'd to a floor datetime so NULLs rank as oldest rather than
            // making every comparison NULL (which would keep both tied rows).
            //
            // Two seq tables are required: MySQL cannot open the same TEMPORARY
            // table twice in one statement (error 1137 "Can't reopen table"), so
            // the self-comparison reads an independent copy (seq2). TEMPORARY
            // DDL does not trigger an implicit COMMIT, so the surrounding
            // transaction's rollback guarantee is preserved.
            $this->db->exec("CREATE TEMPORARY TABLE plugin_image_ratings_seq (
                    seq BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    image_id INT NOT NULL,
                    rating INT NOT NULL,
                    rated_at DATETIME NULL,
                    rated_by INT NOT NULL
                )");
            $this->db->exec("INSERT INTO plugin_image_ratings_seq (image_id, rating, rated_at, rated_by)
                SELECT image_id, rating, rated_at, rated_by FROM plugin_image_ratings");
            $this->db->exec("CREATE TEMPORARY TABLE plugin_image_ratings_seq2 AS
                SELECT seq, image_id, rating, rated_at, rated_by FROM plugin_image_ratings_seq");
            // A row wins its (image_id, rated_by) group when no other row in the
            // group ranks strictly higher by (rated_at, rating, seq). seq is
            // unique, so exactly one winner survives per group.
            $this->db->exec("CREATE TEMPORARY TABLE plugin_image_ratings_keep AS
                SELECT a.seq
                FROM plugin_image_ratings_seq a
                LEFT JOIN plugin_image_ratings_seq2 b
                    ON b.image_id = a.image_id
                    AND b.rated_by = a.rated_by
                    AND (
                        COALESCE(b.rated_at, '1000-01-01 00:00:00') > COALESCE(a.rated_at, '1000-01-01 00:00:00')
                        OR (COALESCE(b.rated_at, '1000-01-01 00:00:00') = COALESCE(a.rated_at, '1000-01-01 00:00:00') AND b.rating > a.rating)
                        OR (COALESCE(b.rated_at, '1000-01-01 00:00:00') = COALESCE(a.rated_at, '1000-01-01 00:00:00') AND b.rating = a.rating AND b.seq > a.seq)
                    )
                WHERE b.seq IS NULL");
            $this->db->exec("DELETE FROM plugin_image_ratings");
            $this->db->exec("INSERT INTO plugin_image_ratings (image_id, rating, rated_at, rated_by)
                SELECT s.image_id, s.rating, s.rated_at, s.rated_by
                FROM plugin_image_ratings_seq s
                JOIN plugin_image_ratings_keep k ON k.seq = s.seq");
            $this->db->exec("DROP TEMPORARY TABLE plugin_image_ratings_seq");
            $this->db->exec("DROP TEMPORARY TABLE plugin_image_ratings_seq2");
            $this->db->exec("DROP TEMPORARY TABLE plugin_image_ratings_keep");
            $this->db->commit();
        } catch (PDOException $e) {
            $this->db->rollBack();
            throw $e;
        }

        // Steps (b)+(c)+(d): combine the structural changes into a SINGLE
        // ALTER TABLE so MySQL applies them atomically. Issuing three
        // separate auto-committing DDL statements opened a race window
        // where a concurrent INSERT could land between MODIFY (rated_by
        // NOT NULL) and ADD UNIQUE — long enough on a busy install to
        // produce duplicates that the next UNIQUE attempt would reject,
        // leaving the table without PK AND without UNIQUE. A combined
        // ALTER runs as one operation, the rebind to the new schema is
        // applied or rolled back as a whole.
        // The MODIFY clause is always required; DROP PRIMARY KEY and the
        // ADD UNIQUE KEY are conditional. The compound ALTER never has an
        // empty parts list because MODIFY is unconditional.
        // INVARIANT: $alterParts always carries at least the unconditional
        // MODIFY clause, so the compound ALTER below is never syntactically
        // empty. PHPStan verifies this at level 6 — a future edit that
        // demotes MODIFY to a conditional branch will trip the alwaysTrue
        // check on the assembled list and force the author to think about
        // the empty case explicitly.
        $alterParts = ["MODIFY rated_by INT NOT NULL DEFAULT 0"];
        if ($hasLegacyPk) {
            array_unshift($alterParts, "DROP PRIMARY KEY");
        }
        if (!$hasCompositeUnique) {
            $alterParts[] = "ADD UNIQUE KEY uniq_image_rated_by (image_id, rated_by)";
        }
        $this->db->exec("ALTER TABLE plugin_image_ratings " . implode(', ', $alterParts));

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
     * With UNIQUE(image_id, rated_by) an image can have several rating rows
     * (one per rater, plus the optional rated_by=0 sentinel from upload-time
     * init). We must aggregate per image_id and apply the rating predicate to
     * the AVERAGE of real votes, not to raw rows - otherwise the result would
     * contain duplicates and would match images on the sentinel value 0.
     *
     * Sentinel rows (rated_by = 0) are excluded from the aggregate; images
     * that have ONLY sentinel rows therefore contribute no aggregate row and
     * are correctly absent from the result.
     *
     * @param int $rating Exact (rounded) average rating, or minimum if $exact = false
     * @param bool $exact Match exact rounded average rating
     * @return array Distinct image IDs
     */
    public function getImagesByRating(int $rating, bool $exact = true): array
    {
        if ($exact) {
            $sql = "
                SELECT image_id
                FROM plugin_image_ratings
                WHERE rated_by <> 0
                GROUP BY image_id
                HAVING ROUND(AVG(rating)) = ?
            ";
        } else {
            $sql = "
                SELECT image_id
                FROM plugin_image_ratings
                WHERE rated_by <> 0
                GROUP BY image_id
                HAVING AVG(rating) >= ?
            ";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$rating]);

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
     * Aggregates per image_id so an image with N raters appears exactly once,
     * ranked by its average real-vote rating (descending). Sentinel rows
     * (rated_by = 0) are excluded from the aggregate so they do not pull the
     * average toward 0; images with ONLY sentinel rows therefore do not
     * appear in the result. Ties are broken by the most recent rated_at.
     *
     * @param int $limit Number of images to return
     * @return array Distinct image IDs, highest-rated first
     */
    public function getTopRated(int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT image_id
            FROM plugin_image_ratings
            WHERE rated_by <> 0 AND rating > 0
            GROUP BY image_id
            ORDER BY AVG(rating) DESC, MAX(rated_at) DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);

        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'image_id');
    }

    /**
     * Get unrated images
     *
     * An image is "unrated" when it has NO real rating rows. Because upload-time
     * init may insert a sentinel row with rated_by = 0 alongside real votes,
     * a simple LEFT JOIN on plugin_image_ratings would (a) emit duplicates for
     * images with multiple raters and (b) misclassify an image that has both
     * the sentinel AND a real vote as "unrated" (the sentinel row has rating = 0
     * which used to satisfy `r.rating IS NULL OR r.rating = 0`).
     *
     * The correct definition is "no row where rated_by <> 0 with rating > 0"
     * - i.e., no real positive vote.
     *
     * @return array Distinct image IDs that have not been rated by any real user
     */
    public function getUnratedImages(?int $albumId = null): array
    {
        $sql = "
            SELECT i.id
            FROM images i
            WHERE NOT EXISTS (
                SELECT 1
                FROM plugin_image_ratings r
                WHERE r.image_id = i.id
                  AND r.rated_by <> 0
                  AND r.rating > 0
            )
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
