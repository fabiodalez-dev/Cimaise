<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Logger;
use App\Support\QueryCache;

/**
 * Settings management service with multi-level caching.
 *
 * Caching strategy:
 * 1. Static in-memory cache (request-scoped) - avoids repeated array lookups
 * 2. QueryCache (APCu/file) - persists across requests for 5 minutes
 *
 * Performance: Single DB query cached in APCu instead of query per request
 */
class SettingsService
{
    private const CACHE_KEY = 'all_settings';
    private const CACHE_TTL = 300; // 5 minutes

    /** @var array|null In-memory cache for current request */
    private static ?array $cache = null;

    public function __construct(private Database $db)
    {
    }

    /**
     * Clear all settings caches (in-memory + APCu).
     */
    public function clearCache(): void
    {
        self::$cache = null;
        QueryCache::getInstance()->forget(self::CACHE_KEY);
    }

    /**
     * Load settings into cache using QueryCache for cross-request persistence.
     */
    private function loadCache(): void
    {
        if (self::$cache !== null) {
            return;
        }

        // Try APCu/file cache first (cross-request)
        self::$cache = QueryCache::getInstance()->remember(
            self::CACHE_KEY,
            function () {
                return $this->loadFromDatabase();
            },
            self::CACHE_TTL
        );
    }

    /**
     * Load settings directly from database.
     */
    private function loadFromDatabase(): array
    {
        try {
            $stmt = $this->db->query('SELECT `key`, `value` FROM settings');
            $dbSettings = [];
            foreach ($stmt->fetchAll() as $row) {
                $dbSettings[$row['key']] = $this->decodeValue($row['value'] ?? null);
            }
            return array_merge($this->defaults(), $dbSettings);
        } catch (\Throwable $e) {
            Logger::warning('SettingsService: Failed to load settings from database', ['error' => $e->getMessage()], 'settings');
            return $this->defaults();
        }
    }

    private function decodeValue(?string $raw): mixed
    {
        $decoded = json_decode($raw ?? 'null', true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $raw;
    }

    public function all(): array
    {
        $this->loadCache();
        return self::$cache;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->loadCache();
        return self::$cache[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        // Invalidate all caches before writing
        $this->clearCache();

        $replace = $this->db->replaceKeyword();
        $now = $this->db->nowExpression();
        $stmt = $this->db->pdo()->prepare("{$replace} INTO settings(`key`,`value`,`type`,`updated_at`) VALUES(:k, :v, :t, {$now})");
        $encodedValue = json_encode($value, JSON_UNESCAPED_SLASHES);
        $type = $value === null ? 'null' : (\is_bool($value) ? 'boolean' : (\is_numeric($value) ? 'number' : 'string'));
        $stmt->execute([':k' => $key, ':v' => $encodedValue, ':t' => $type]);

        // Invalidate TwigGlobalsCache since settings affect Twig globals
        TwigGlobalsCache::invalidate();

        // Reload cache with fresh data from database
        $this->loadCache();
    }

    public function defaults(): array
    {
        return [
            'image.formats' => ['avif' => true, 'webp' => true, 'jpg' => true],
            'image.quality' => ['avif' => 50, 'webp' => 75, 'jpg' => 85],
            'image.breakpoints' => ['sm' => 768, 'md' => 1200, 'lg' => 1920, 'xl' => 2560, 'xxl' => 3840],
            'image.preview' => ['width' => 480, 'height' => null],
            'image.variants_async' => true,
            'gallery.default_template_id' => null,
            'site.title' => 'Cimaise',
            'site.logo' => null,
            'site.description' => 'Professional Photography Portfolio',
            'site.copyright' => '© {year} Photography Portfolio',
            'site.email' => '',
            'site.language' => 'en',
            'admin.language' => 'en',
            'date.format' => 'Y-m-d',
            'pagination.limit' => 12,
            'admin.debug_logs' => false,
            // Privacy & Cookie Banner
            'privacy.cookie_banner_enabled' => true,
            'privacy.custom_js_essential' => '',
            'privacy.custom_js_analytics' => '',
            'privacy.custom_js_marketing' => '',
            'cookie_banner.show_analytics' => false,
            'cookie_banner.show_marketing' => false,
            'privacy.nsfw_global_warning' => false,
            'frontend.disable_right_click' => true,
            'frontend.dark_mode' => false,
            'frontend.custom_css' => '',
            'navigation.show_tags_in_header' => false,
            // Performance & Cache Settings
            'performance.compression_enabled' => true,
            'performance.compression_type' => 'auto', // auto, brotli, gzip
            'performance.compression_level' => 6, // 0-11 for brotli, 1-9 for gzip
            'performance.cache_enabled' => true,
            'performance.static_cache_max_age' => 31536000, // 1 year for static assets
            'performance.media_cache_max_age' => 86400, // 1 day for media
            'performance.html_cache_max_age' => 300, // 5 minutes for HTML pages
        ];
    }
}
