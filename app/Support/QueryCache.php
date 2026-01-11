<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Simple query result caching using APCu or file cache
 * Prevents redundant database queries for frequently accessed data
 */
class QueryCache
{
    private const DEFAULT_TTL = 300; // 5 minutes
    private const CACHE_PREFIX = 'cimaise_qcache_';

    private static ?self $instance = null;
    private bool $useApcu;
    private string $cacheDir;

    private function __construct()
    {
        // Check if APCu is available and enabled
        $this->useApcu = extension_loaded('apcu') && ini_get('apc.enabled');

        // Fallback to file cache
        $this->cacheDir = dirname(__DIR__, 2) . '/storage/tmp/query_cache';
        if (!$this->useApcu && !is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Remember query result with automatic caching
     *
     * @param string $key Unique cache key
     * @param callable $callback Query callback that returns data
     * @param int $ttl Time to live in seconds
     * @return mixed Cached or fresh result
     */
    public function remember(string $key, callable $callback, int $ttl = self::DEFAULT_TTL): mixed
    {
        $cached = $this->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $result = $callback();
        $this->set($key, $result, $ttl);
        return $result;
    }

    /**
     * Get cached value
     */
    public function get(string $key): mixed
    {
        $cacheKey = self::CACHE_PREFIX . $key;

        if ($this->useApcu) {
            $result = apcu_fetch($cacheKey, $success);
            return $success ? $result : null;
        }

        // File cache fallback
        $file = $this->getCacheFilePath($key);
        if (!file_exists($file)) {
            return null;
        }

        $data = @file_get_contents($file);
        if ($data === false) {
            return null;
        }

        $cached = @unserialize($data, ['allowed_classes' => false]);
        if (!is_array($cached) || !isset($cached['expires'], $cached['data'])) {
            return null;
        }

        // Check expiration
        if ($cached['expires'] < time()) {
            @unlink($file);
            return null;
        }

        return $cached['data'];
    }

    /**
     * Set cached value
     */
    public function set(string $key, mixed $value, int $ttl = self::DEFAULT_TTL): bool
    {
        $cacheKey = self::CACHE_PREFIX . $key;

        if ($this->useApcu) {
            return apcu_store($cacheKey, $value, $ttl);
        }

        // File cache fallback
        $file = $this->getCacheFilePath($key);
        $data = serialize([
            'expires' => time() + $ttl,
            'data' => $value
        ]);

        return @file_put_contents($file, $data, LOCK_EX) !== false;
    }

    /**
     * Delete cached value
     */
    public function forget(string $key): bool
    {
        $cacheKey = self::CACHE_PREFIX . $key;

        if ($this->useApcu) {
            return apcu_delete($cacheKey);
        }

        // File cache fallback
        $file = $this->getCacheFilePath($key);
        if (file_exists($file)) {
            return @unlink($file);
        }

        return true;
    }

    /**
     * Clear all query cache
     */
    public function flush(): bool
    {
        if ($this->useApcu) {
            // Clear only our keys
            $iterator = new \APCUIterator('/^' . preg_quote(self::CACHE_PREFIX, '/') . '/');
            return apcu_delete($iterator);
        }

        // File cache fallback - delete all files in cache dir
        if (!is_dir($this->cacheDir)) {
            return true;
        }

        $files = glob($this->cacheDir . '/*');
        if ($files === false) {
            return false;
        }

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        return true;
    }

    /**
     * Get cache file path for a key
     */
    private function getCacheFilePath(string $key): string
    {
        $hash = hash('xxh3', $key);
        return $this->cacheDir . '/' . $hash . '.cache';
    }

    /**
     * Clean up expired file cache entries (maintenance)
     */
    public function cleanupExpired(): int
    {
        if ($this->useApcu) {
            return 0; // APCu handles expiration automatically
        }

        if (!is_dir($this->cacheDir)) {
            return 0;
        }

        $deleted = 0;
        $files = glob($this->cacheDir . '/*.cache');
        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $data = @file_get_contents($file);
            if ($data === false) {
                continue;
            }

            $cached = @unserialize($data, ['allowed_classes' => false]);
            if (!is_array($cached) || !isset($cached['expires'])) {
                @unlink($file);
                $deleted++;
                continue;
            }

            if ($cached['expires'] < time()) {
                @unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Check if APCu is being used
     */
    public function isUsingApcu(): bool
    {
        return $this->useApcu;
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        if ($this->useApcu) {
            $info = apcu_cache_info(true);
            return [
                'backend' => 'APCu',
                'entries' => $info['num_entries'] ?? 0,
                'hits' => $info['num_hits'] ?? 0,
                'misses' => $info['num_misses'] ?? 0,
                'memory_used' => $info['mem_size'] ?? 0,
            ];
        }

        // File cache stats
        $files = glob($this->cacheDir . '/*.cache');
        return [
            'backend' => 'File',
            'entries' => $files ? count($files) : 0,
            'hits' => 0, // Not tracked for file cache
            'misses' => 0,
            'memory_used' => 0,
        ];
    }
}
