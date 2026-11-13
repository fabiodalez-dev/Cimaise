<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * Single enforcement point for the seo.expose_gps location-privacy policy:
 * unless the admin opts in, public output carries GPS rounded to 2 decimals
 * (~1 km). Every public reader of images.gps_lat/gps_lng must go through it.
 */
final class GpsPrivacy
{
    /** @var array<string, bool> */
    private static array $exposeCache = [];

    public static function exposeEnabled(Database $db): bool
    {
        $key = $db->identity();
        if (!array_key_exists($key, self::$exposeCache)) {
            $svc = new SettingsService($db);
            self::$exposeCache[$key] = SettingsService::boolean($svc->get('seo.expose_gps', false));
        }
        return self::$exposeCache[$key];
    }

    public static function mask(mixed $value, bool $expose): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return $expose ? (float) $value : round((float) $value, 2);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function maskRows(array $rows, bool $expose): array
    {
        foreach ($rows as &$row) {
            foreach (['gps_lat', 'gps_lng'] as $field) {
                if (array_key_exists($field, $row)) {
                    $row[$field] = self::mask($row[$field], $expose);
                }
            }
        }
        unset($row);
        return $rows;
    }

    /** Test helper: forget cached settings. */
    public static function reset(): void
    {
        self::$exposeCache = [];
    }
}
