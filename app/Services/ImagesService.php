<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Logger;

class ImagesService
{
    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * Enrich image rows with metadata from related tables (camera, lens, film, developer, lab, location).
     * Modifies the array in place.
     *
     * @param \PDO $pdo Database connection
     * @param array &$imagesRows Array of image rows to enrich (modified by reference)
     * @param string $context Logging context identifier
     */
    public static function enrichWithMetadata(\PDO $pdo, array &$imagesRows, string $context = 'images'): void
    {
        foreach ($imagesRows as &$ir) {
            try {
                // Camera lookup
                if (!empty($ir['camera_id'])) {
                    $s = $pdo->prepare('SELECT make, model FROM cameras WHERE id = :id');
                    $s->execute([':id' => $ir['camera_id']]);
                    $cr = $s->fetch();
                    if ($cr) {
                        $ir['camera_name'] = trim(($cr['make'] ?? '') . ' ' . ($cr['model'] ?? ''));
                    }
                }
                // Lens lookup
                if (!empty($ir['lens_id'])) {
                    $s = $pdo->prepare('SELECT brand, model FROM lenses WHERE id = :id');
                    $s->execute([':id' => $ir['lens_id']]);
                    $lr = $s->fetch();
                    if ($lr) {
                        $ir['lens_name'] = trim(($lr['brand'] ?? '') . ' ' . ($lr['model'] ?? ''));
                    }
                }
                // Developer lookup
                if (!empty($ir['developer_id'])) {
                    $s = $pdo->prepare('SELECT name FROM developers WHERE id = :id');
                    $s->execute([':id' => $ir['developer_id']]);
                    $ir['developer_name'] = $s->fetchColumn() ?: null;
                }
                // Lab lookup
                if (!empty($ir['lab_id'])) {
                    $s = $pdo->prepare('SELECT name FROM labs WHERE id = :id');
                    $s->execute([':id' => $ir['lab_id']]);
                    $ir['lab_name'] = $s->fetchColumn() ?: null;
                }
                // Film lookup with extended info
                if (!empty($ir['film_id'])) {
                    $s = $pdo->prepare('SELECT brand, name, iso, format FROM films WHERE id = :id');
                    $s->execute([':id' => $ir['film_id']]);
                    $fr = $s->fetch();
                    if ($fr) {
                        $nameOnly = trim((string)($fr['name'] ?? ''));
                        $brand = trim((string)($fr['brand'] ?? ''));
                        $ir['film_name'] = trim(($brand !== '' ? ($brand . ' ') : '') . $nameOnly);
                        // Build film_display with ISO and format
                        $iso = isset($fr['iso']) && $fr['iso'] !== '' ? (string)(int)$fr['iso'] : '';
                        $fmt = (string)($fr['format'] ?? '');
                        $parts = [];
                        if ($iso !== '') { $parts[] = $iso; }
                        if ($fmt !== '') { $parts[] = $fmt; }
                        $suffix = count($parts) ? (' (' . implode(' - ', $parts) . ')') : '';
                        $ir['film_display'] = ($nameOnly !== '' ? $nameOnly : $ir['film_name']) . $suffix;
                    }
                }
                // Location lookup
                if (!empty($ir['location_id'])) {
                    $s = $pdo->prepare('SELECT name FROM locations WHERE id = :id');
                    $s->execute([':id' => $ir['location_id']]);
                    $ir['location_name'] = $s->fetchColumn() ?: null;
                }
            } catch (\Throwable $e) {
                Logger::warning($context . ': Error fetching image metadata', [
                    'image_id' => $ir['id'] ?? null,
                    'error' => $e->getMessage()
                ], $context);
            }
        }
        unset($ir); // Break reference
    }

    // Minimal JPEG preview using GD; returns path or null
    public static function generateJpegPreview(string $srcPath, string $destPath, int $targetWidth): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }
        $info = @getimagesize($srcPath);
        if (!$info) return null;
        [$w, $h] = $info;
        $ratio = $h > 0 ? $w / $h : 1;
        $newW = $targetWidth;
        $newH = (int)round($targetWidth / $ratio);
        $src = null;
        switch ($info['mime'] ?? '') {
            case 'image/jpeg': $src = @imagecreatefromjpeg($srcPath); break;
            case 'image/png': $src = @imagecreatefrompng($srcPath); break;
            default: return null;
        }
        if (!$src) return null;
        $dst = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
        self::ensureDir(dirname($destPath));
        imagejpeg($dst, $destPath, 82);
        imagedestroy($src);
        imagedestroy($dst);
        return $destPath;
    }
}

