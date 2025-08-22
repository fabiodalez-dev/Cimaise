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
        return $val !== false ? json_decode((string)$val, true) : ($this->defaults()[$key] ?? $default);
    }

    public function set(string $key, mixed $value): void
    {
        $stmt = $this->db->pdo()->prepare('REPLACE INTO settings(`key`,`value`) VALUES(:k, :v)');
        $stmt->execute([':k' => $key, ':v' => json_encode($value, JSON_UNESCAPED_SLASHES)]);
    }

    public function defaults(): array
    {
        return [
            'image.formats' => ['avif' => true, 'webp' => true, 'jpg' => true],
            'image.quality' => ['avif' => 50, 'webp' => 75, 'jpg' => 85],
            'image.breakpoints' => ['xs' => 480, 'sm' => 768, 'md' => 1024],
            'image.preview' => ['width' => 480, 'height' => null],
            'visibility' => ['public' => true],
        ];
    }
}

