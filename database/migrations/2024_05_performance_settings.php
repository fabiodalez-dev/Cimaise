<?php
/**
 * Migration: Add performance and cache settings
 * Enables compression (Brotli/Gzip) and HTTP caching configuration
 *
 * New settings:
 * - performance.compression_enabled: Enable/disable compression
 * - performance.compression_type: Compression algorithm (auto/brotli/gzip)
 * - performance.compression_level: Compression level (0-11)
 * - performance.cache_enabled: Enable/disable HTTP caching
 * - performance.static_cache_max_age: Cache TTL for static assets (seconds)
 * - performance.media_cache_max_age: Cache TTL for media files (seconds)
 * - performance.html_cache_max_age: Cache TTL for HTML pages (seconds)
 */

return new class {
    public function up(\PDO $pdo): void
    {
        // Settings to add with their default values
        $settings = [
            // Compression settings
            ['performance.compression_enabled', 'true', 'boolean'],
            ['performance.compression_type', 'auto', 'string'],
            ['performance.compression_level', '6', 'integer'],

            // Cache settings
            ['performance.cache_enabled', 'true', 'boolean'],
            ['performance.static_cache_max_age', '31536000', 'integer'], // 1 year
            ['performance.media_cache_max_age', '86400', 'integer'],     // 1 day
            ['performance.html_cache_max_age', '300', 'integer'],        // 5 minutes
        ];

        foreach ($settings as [$key, $value, $type]) {
            // Check if setting already exists
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE `key` = :key');
            $stmt->execute([':key' => $key]);
            $exists = (int)$stmt->fetchColumn() > 0;
            $stmt->closeCursor();

            if (!$exists) {
                $stmt = $pdo->prepare('INSERT INTO settings (`key`, `value`, `type`) VALUES (:key, :value, :type)');
                $stmt->execute([
                    ':key' => $key,
                    ':value' => $value,
                    ':type' => $type,
                ]);
            }
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec("DELETE FROM settings WHERE `key` IN (
            'performance.compression_enabled',
            'performance.compression_type',
            'performance.compression_level',
            'performance.cache_enabled',
            'performance.static_cache_max_age',
            'performance.media_cache_max_age',
            'performance.html_cache_max_age'
        )");
    }
};
