<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Logger;
use PDO;

class SettingsService
{
    private static ?array $cache = null;

    public function __construct(private Database $db)
    {
    }

    public function clearCache(): void
    {
        self::$cache = null;
    }

    private function loadCache(): void
    {
        if (self::$cache !== null) {
            return;
        }

        try {
            $stmt = $this->db->query('SELECT `key`, `value` FROM settings');
            $dbSettings = [];
            foreach ($stmt->fetchAll() as $row) {
                $dbSettings[$row['key']] = json_decode($row['value'] ?? 'null', true);
            }
            self::$cache = array_merge($this->defaults(), $dbSettings);
        } catch (\Throwable $e) {
            Logger::warning('SettingsService: Failed to load settings cache', ['error' => $e->getMessage()], 'settings');
            self::$cache = $this->defaults();
        }
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
        // Invalidate cache before writing to ensure fresh data on next get()
        self::$cache = null;

        $replace = $this->db->replaceKeyword();
        $now = $this->db->nowExpression();
        $stmt = $this->db->pdo()->prepare("{$replace} INTO settings(`key`,`value`,`type`,`updated_at`) VALUES(:k, :v, :t, {$now})");
        $encodedValue = json_encode($value, JSON_UNESCAPED_SLASHES);
        $type = is_null($value) ? 'null' : (is_bool($value) ? 'boolean' : (is_numeric($value) ? 'number' : 'string'));
        $stmt->execute([':k' => $key, ':v' => $encodedValue, ':t' => $type]);

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
