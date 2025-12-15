<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use PDO;

class SettingsService
{
    public function __construct(private Database $db) {}

    public function all(): array
    {
        $stmt = $this->db->pdo()->query('SELECT `key`, `value` FROM settings');
        $res = [];
        foreach ($stmt->fetchAll() as $row) {
            $res[$row['key']] = json_decode($row['value'] ?? 'null', true);
        }
        return $res + $this->defaults();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $stmt = $this->db->pdo()->prepare('SELECT `value` FROM settings WHERE `key`=:k');
        $stmt->execute([':k' => $key]);
        $val = $stmt->fetchColumn();
        
        // Handle case where value is the string "null"
        if ($val === 'null') {
            return $this->defaults()[$key] ?? $default;
        }
        
        return $val !== false ? json_decode((string)$val, true) : ($this->defaults()[$key] ?? $default);
    }

    public function set(string $key, mixed $value): void
    {
        $replace = $this->db->replaceKeyword();
        $now = $this->db->nowExpression();
        $stmt = $this->db->pdo()->prepare("{$replace} INTO settings(`key`,`value`,`type`,`updated_at`) VALUES(:k, :v, :t, {$now})");
        $encodedValue = json_encode($value, JSON_UNESCAPED_SLASHES);
        $type = is_null($value) ? 'null' : (is_bool($value) ? 'boolean' : (is_numeric($value) ? 'number' : 'string'));
        $stmt->execute([':k' => $key, ':v' => $encodedValue, ':t' => $type]);
    }

    public function defaults(): array
    {
        return [
            'image.formats' => ['avif' => true, 'webp' => true, 'jpg' => true],
            'image.quality' => ['avif' => 50, 'webp' => 75, 'jpg' => 85],
            'image.breakpoints' => ['sm' => 768, 'md' => 1200, 'lg' => 1920, 'xl' => 2560, 'xxl' => 3840],
            'image.preview' => ['width' => 480, 'height' => null],
            'visibility' => ['public' => true],
            'gallery.default_template_id' => null,
            'site.title' => 'Cimaise',
            'site.logo' => null,
            'site.description' => 'Professional Photography Portfolio',
            'site.copyright' => '© 2024 Photography Portfolio',
            'site.email' => '',
            'site.language' => 'en',
            'date.format' => 'Y-m-d',
            'performance.compression' => true,
            'pagination.limit' => 12,
            'cache.ttl' => 24,
            // Privacy & Cookie Banner
            'privacy.cookie_banner_enabled' => true,
            'privacy.custom_js_essential' => '',
            'privacy.custom_js_analytics' => '',
            'privacy.custom_js_marketing' => '',
            'cookie_banner.show_analytics' => false,
            'cookie_banner.show_marketing' => false,
        ];
    }
}
