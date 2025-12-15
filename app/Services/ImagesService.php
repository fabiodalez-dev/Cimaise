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
     * Uses batch queries to avoid N+1 problem - fetches all related data in 6 queries total.
     * Modifies the array in place.
     *
     * @param \PDO $pdo Database connection
     * @param array &$imagesRows Array of image rows to enrich (modified by reference)
     * @param string $context Logging context identifier
     */
    public static function enrichWithMetadata(\PDO $pdo, array &$imagesRows, string $context = 'images'): void
    {
        if (empty($imagesRows)) {
            return;
        }

        try {
            // Collect all unique IDs for batch queries
            $cameraIds = [];
            $lensIds = [];
            $developerIds = [];
            $labIds = [];
            $filmIds = [];
            $locationIds = [];

            foreach ($imagesRows as $ir) {
                if (!empty($ir['camera_id'])) $cameraIds[$ir['camera_id']] = true;
                if (!empty($ir['lens_id'])) $lensIds[$ir['lens_id']] = true;
                if (!empty($ir['developer_id'])) $developerIds[$ir['developer_id']] = true;
                if (!empty($ir['lab_id'])) $labIds[$ir['lab_id']] = true;
                if (!empty($ir['film_id'])) $filmIds[$ir['film_id']] = true;
                if (!empty($ir['location_id'])) $locationIds[$ir['location_id']] = true;
            }

            // Batch fetch cameras
            $cameras = [];
            if (!empty($cameraIds)) {
                $ids = array_keys($cameraIds);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $s = $pdo->prepare("SELECT id, make, model FROM cameras WHERE id IN ($placeholders)");
                $s->execute($ids);
                foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $cameras[$row['id']] = trim(($row['make'] ?? '') . ' ' . ($row['model'] ?? ''));
                }
            }

            // Batch fetch lenses
            $lenses = [];
            if (!empty($lensIds)) {
                $ids = array_keys($lensIds);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $s = $pdo->prepare("SELECT id, brand, model FROM lenses WHERE id IN ($placeholders)");
                $s->execute($ids);
                foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $lenses[$row['id']] = trim(($row['brand'] ?? '') . ' ' . ($row['model'] ?? ''));
                }
            }

            // Batch fetch developers
            $developers = [];
            if (!empty($developerIds)) {
                $ids = array_keys($developerIds);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $s = $pdo->prepare("SELECT id, name FROM developers WHERE id IN ($placeholders)");
                $s->execute($ids);
                foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $developers[$row['id']] = $row['name'];
                }
            }

            // Batch fetch labs
            $labs = [];
            if (!empty($labIds)) {
                $ids = array_keys($labIds);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $s = $pdo->prepare("SELECT id, name FROM labs WHERE id IN ($placeholders)");
                $s->execute($ids);
                foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $labs[$row['id']] = $row['name'];
                }
            }

            // Batch fetch films
            $films = [];
            if (!empty($filmIds)) {
                $ids = array_keys($filmIds);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $s = $pdo->prepare("SELECT id, brand, name, iso, format FROM films WHERE id IN ($placeholders)");
                $s->execute($ids);
                foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $nameOnly = trim((string)($row['name'] ?? ''));
                    $brand = trim((string)($row['brand'] ?? ''));
                    $filmName = trim(($brand !== '' ? ($brand . ' ') : '') . $nameOnly);
                    $iso = isset($row['iso']) && $row['iso'] !== '' ? (string)(int)$row['iso'] : '';
                    $fmt = (string)($row['format'] ?? '');
                    $parts = [];
                    if ($iso !== '') { $parts[] = $iso; }
                    if ($fmt !== '') { $parts[] = $fmt; }
                    $suffix = count($parts) ? (' (' . implode(' - ', $parts) . ')') : '';
                    $films[$row['id']] = [
                        'film_name' => $filmName,
                        'film_display' => ($nameOnly !== '' ? $nameOnly : $filmName) . $suffix
                    ];
                }
            }

            // Batch fetch locations
            $locations = [];
            if (!empty($locationIds)) {
                $ids = array_keys($locationIds);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $s = $pdo->prepare("SELECT id, name FROM locations WHERE id IN ($placeholders)");
                $s->execute($ids);
                foreach ($s->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $locations[$row['id']] = $row['name'];
                }
            }

            // Map fetched data back to images
            foreach ($imagesRows as &$ir) {
                if (!empty($ir['camera_id']) && isset($cameras[$ir['camera_id']])) {
                    $ir['camera_name'] = $cameras[$ir['camera_id']];
                }
                if (!empty($ir['lens_id']) && isset($lenses[$ir['lens_id']])) {
                    $ir['lens_name'] = $lenses[$ir['lens_id']];
                }
                if (!empty($ir['developer_id']) && isset($developers[$ir['developer_id']])) {
                    $ir['developer_name'] = $developers[$ir['developer_id']];
                }
                if (!empty($ir['lab_id']) && isset($labs[$ir['lab_id']])) {
                    $ir['lab_name'] = $labs[$ir['lab_id']];
                }
                if (!empty($ir['film_id']) && isset($films[$ir['film_id']])) {
                    $ir['film_name'] = $films[$ir['film_id']]['film_name'];
                    $ir['film_display'] = $films[$ir['film_id']]['film_display'];
                }
                if (!empty($ir['location_id']) && isset($locations[$ir['location_id']])) {
                    $ir['location_name'] = $locations[$ir['location_id']];
                }
            }
            unset($ir); // Break reference

        } catch (\Throwable $e) {
            Logger::warning($context . ': Error fetching image metadata batch', [
                'error' => $e->getMessage()
            ], $context);
        }
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

