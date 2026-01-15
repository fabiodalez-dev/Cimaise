<?php
/**
 * Migration: Cache Tags for Tag-Based Invalidation
 * Creates junction table for associating page cache entries with tags
 *
 * Benefits:
 * - Tag-based cache invalidation (e.g., invalidate all album-related caches)
 * - Efficient bulk invalidation via indexed tag lookups
 * - Supports cascading invalidation patterns
 */

return new class {
    public function up(\PDO $pdo): void
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `cache_tags` (
                    `cache_key` VARCHAR(255) NOT NULL,
                    `tag` VARCHAR(100) NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`cache_key`, `tag`),
                    KEY `idx_tag` (`tag`),
                    CONSTRAINT `fk_cache_tags_key` FOREIGN KEY (`cache_key`)
                        REFERENCES `page_cache` (`cache_key`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } else {
            // SQLite
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS cache_tags (
                    cache_key VARCHAR(255) NOT NULL,
                    tag VARCHAR(100) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (cache_key, tag),
                    FOREIGN KEY (cache_key) REFERENCES page_cache(cache_key) ON DELETE CASCADE
                )
            ");

            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cache_tags_tag ON cache_tags(tag)");
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DROP TABLE IF EXISTS cache_tags");
    }
};
