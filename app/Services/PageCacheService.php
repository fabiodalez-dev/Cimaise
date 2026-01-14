<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Logger;

/**
 * Page-level JSON caching for frontend pages (home, galleries, albums).
 *
 * Stores pre-processed page data as JSON files for fast retrieval.
 * Falls back to database queries if cache miss or expired.
 */
class PageCacheService
{
    private const CACHE_VERSION = 1;
    private const DEFAULT_TTL = 86400; // 24 hours

    private string $cacheDir;
    private bool $enabled;

    public function __construct(private SettingsService $settings)
    {
        $this->cacheDir = dirname(__DIR__, 2) . '/storage/cache/pages';
        $this->enabled = (bool) $this->settings->get('cache.pages_enabled', true);

        // Ensure cache directory exists
        if ($this->enabled && !is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
        if ($this->enabled && !is_dir($this->cacheDir . '/albums')) {
            @mkdir($this->cacheDir . '/albums', 0775, true);
        }
    }

    /**
     * Get cached page data.
     *
     * @param string $type Page type: 'home', 'galleries', or 'album:{slug}'
     * @param bool $allowStale If true, returns stale data instead of null when expired
     * @return array|null Cached data or null on miss
     */
    public function get(string $type, bool $allowStale = false): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $file = $this->getCacheFilePath($type);
        if (!file_exists($file)) {
            return null;
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $cached = json_decode($content, true);
        if (!is_array($cached) || !isset($cached['version'], $cached['expires_at'], $cached['data'])) {
            @unlink($file);
            return null;
        }

        // Check version compatibility
        if ($cached['version'] !== self::CACHE_VERSION) {
            @unlink($file);
            return null;
        }

        // Check expiration
        $isExpired = strtotime($cached['expires_at']) < time();
        if ($isExpired && !$allowStale) {
            @unlink($file);
            return null;
        }

        return $cached['data'];
    }

    /**
     * Check if cache is expired (but may still have stale data).
     *
     * @param string $type Page type
     * @return bool True if expired or missing
     */
    public function isExpired(string $type): bool
    {
        if (!$this->enabled) {
            return true;
        }

        $file = $this->getCacheFilePath($type);
        if (!file_exists($file)) {
            return true;
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return true;
        }

        $cached = json_decode($content, true);
        if (!is_array($cached) || !isset($cached['expires_at'])) {
            return true;
        }

        return strtotime($cached['expires_at']) < time();
    }

    /**
     * Set cached page data.
     *
     * @param string $type Page type: 'home', 'galleries', or 'album:{slug}'
     * @param array $data Page data to cache
     * @param int|null $ttl Time to live in seconds (null = use default)
     * @return bool Success
     */
    public function set(string $type, array $data, ?int $ttl = null): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $ttl = $ttl ?? (int) $this->settings->get('cache.pages_ttl', self::DEFAULT_TTL);

        $cached = [
            'version' => self::CACHE_VERSION,
            'generated_at' => gmdate('c'),
            'expires_at' => gmdate('c', time() + $ttl),
            'type' => $type,
            'data' => $data,
        ];

        $file = $this->getCacheFilePath($type);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // Atomic write: write to temp file first, then rename
        $tmpFile = $file . '.tmp';
        $result = @file_put_contents($tmpFile, json_encode($cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

        if ($result === false) {
            Logger::warning("Failed to write page cache: {$type}");
            return false;
        }

        return @rename($tmpFile, $file);
    }

    /**
     * Invalidate cache for specific page type.
     *
     * @param string $type Page type: 'home', 'galleries', 'album:{slug}', or 'all'
     * @return int Number of cache entries deleted
     */
    public function invalidate(string $type): int
    {
        if ($type === 'all') {
            return $this->clearAll();
        }

        $file = $this->getCacheFilePath($type);
        if (file_exists($file) && @unlink($file)) {
            return 1;
        }
        return 0;
    }

    /**
     * Invalidate all caches related to a specific album.
     * Called when album is modified/deleted.
     *
     * @param string $slug Album slug
     * @return int Number of cache entries deleted
     */
    public function invalidateAlbum(string $slug): int
    {
        $deleted = 0;

        // Delete album-specific cache
        $deleted += $this->invalidate("album:{$slug}");

        // Home and galleries may show this album
        $deleted += $this->invalidate('home');
        $deleted += $this->invalidate('galleries');

        return $deleted;
    }

    /**
     * Invalidate home-related caches.
     */
    public function invalidateHome(): int
    {
        return $this->invalidate('home');
    }

    /**
     * Invalidate galleries-related caches.
     */
    public function invalidateGalleries(): int
    {
        $deleted = 0;
        $deleted += $this->invalidate('galleries');
        // Home may also show gallery preview
        $deleted += $this->invalidate('home');
        return $deleted;
    }

    /**
     * Clear all page caches.
     *
     * @return int Number of cache entries deleted
     */
    public function clearAll(): int
    {
        $deleted = 0;

        // Clear main cache files
        foreach (['home.json', 'galleries.json'] as $file) {
            $path = $this->cacheDir . '/' . $file;
            if (file_exists($path) && @unlink($path)) {
                $deleted++;
            }
        }

        // Clear album caches
        $albumsDir = $this->cacheDir . '/albums';
        if (is_dir($albumsDir)) {
            $files = glob($albumsDir . '/*.json');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (@unlink($file)) {
                        $deleted++;
                    }
                }
            }
        }

        return $deleted;
    }

    /**
     * Warm cache by pre-generating specified pages.
     *
     * @param callable $homeBuilder Callback that returns home page data
     * @param callable $galleriesBuilder Callback that returns galleries page data
     * @param callable|null $albumBuilder Callback(slug) that returns album page data
     * @param array $albumSlugs List of album slugs to warm
     * @return array Stats: ['home' => bool, 'galleries' => bool, 'albums' => int]
     */
    public function warm(
        callable $homeBuilder,
        callable $galleriesBuilder,
        ?callable $albumBuilder = null,
        array $albumSlugs = []
    ): array {
        $stats = [
            'home' => false,
            'galleries' => false,
            'albums' => 0,
        ];

        // Warm home
        try {
            $homeData = $homeBuilder();
            if ($this->set('home', $homeData)) {
                $stats['home'] = true;
            }
        } catch (\Throwable $e) {
            Logger::warning("Cache warm failed for home: " . $e->getMessage());
        }

        // Warm galleries
        try {
            $galleriesData = $galleriesBuilder();
            if ($this->set('galleries', $galleriesData)) {
                $stats['galleries'] = true;
            }
        } catch (\Throwable $e) {
            Logger::warning("Cache warm failed for galleries: " . $e->getMessage());
        }

        // Warm albums
        if ($albumBuilder !== null) {
            foreach ($albumSlugs as $slug) {
                try {
                    $albumData = $albumBuilder($slug);
                    if ($albumData !== null && $this->set("album:{$slug}", $albumData)) {
                        $stats['albums']++;
                    }
                } catch (\Throwable $e) {
                    Logger::warning("Cache warm failed for album {$slug}: " . $e->getMessage());
                }
            }
        }

        return $stats;
    }

    /**
     * Get cache statistics.
     *
     * @return array Stats: entries, total_size, oldest, newest
     */
    public function getStats(): array
    {
        $stats = [
            'enabled' => $this->enabled,
            'entries' => 0,
            'total_size' => 0,
            'items' => [],
        ];

        if (!is_dir($this->cacheDir)) {
            return $stats;
        }

        // Main cache files
        foreach (['home', 'galleries'] as $type) {
            $file = $this->cacheDir . '/' . $type . '.json';
            if (file_exists($file)) {
                $stat = @stat($file);
                $content = @file_get_contents($file);
                $cached = $content ? json_decode($content, true) : null;

                $stats['entries']++;
                $stats['total_size'] += $stat ? $stat['size'] : 0;
                $stats['items'][] = [
                    'type' => $type,
                    'size' => $stat ? $stat['size'] : 0,
                    'generated_at' => $cached['generated_at'] ?? null,
                    'expires_at' => $cached['expires_at'] ?? null,
                    'expired' => isset($cached['expires_at']) && strtotime($cached['expires_at']) < time(),
                ];
            }
        }

        // Album cache files
        $albumsDir = $this->cacheDir . '/albums';
        if (is_dir($albumsDir)) {
            $files = glob($albumsDir . '/*.json');
            if ($files !== false) {
                foreach ($files as $file) {
                    $slug = basename($file, '.json');
                    $stat = @stat($file);
                    $content = @file_get_contents($file);
                    $cached = $content ? json_decode($content, true) : null;

                    $stats['entries']++;
                    $stats['total_size'] += $stat ? $stat['size'] : 0;
                    $stats['items'][] = [
                        'type' => 'album:' . $slug,
                        'slug' => $slug,
                        'size' => $stat ? $stat['size'] : 0,
                        'generated_at' => $cached['generated_at'] ?? null,
                        'expires_at' => $cached['expires_at'] ?? null,
                        'expired' => isset($cached['expires_at']) && strtotime($cached['expires_at']) < time(),
                    ];
                }
            }
        }

        return $stats;
    }

    /**
     * Check if caching is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get cache file path for a page type.
     * Public for ETag generation in CacheMiddleware.
     */
    public function getCacheFilePath(string $type, ?string $slug = null): string
    {
        // Handle album type with separate slug parameter
        if ($type === 'album' && $slug !== null) {
            $safeSlug = preg_replace('/[^a-z0-9_-]/i', '_', $slug);
            return $this->cacheDir . '/albums/' . $safeSlug . '.json';
        }

        // Handle album type with inline slug (album:slug-name)
        if (str_starts_with($type, 'album:')) {
            $slug = substr($type, 6);
            $safeSlug = preg_replace('/[^a-z0-9_-]/i', '_', $slug);
            return $this->cacheDir . '/albums/' . $safeSlug . '.json';
        }

        return $this->cacheDir . '/' . $type . '.json';
    }
}
