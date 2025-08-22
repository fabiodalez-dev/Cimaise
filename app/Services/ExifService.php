<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;

class ExifService
{
    public function __construct(private Database $db) {}

    public function extract(string $path): array
    {
        $meta = [];
        if (function_exists('exif_read_data') && @is_file($path)) {
            $ex = @exif_read_data($path, null, true) ?: [];
            
            // Basic camera info
            $meta['Make'] = $this->cleanString($ex['IFD0']['Make'] ?? $ex['EXIF']['Make'] ?? null);
            $meta['Model'] = $this->cleanString($ex['IFD0']['Model'] ?? $ex['EXIF']['Model'] ?? null);
            
            // Lens info (multiple possible tags)
            $meta['LensModel'] = $this->cleanString($ex['EXIF']['UndefinedTag:0xA434'] ?? 
                                                  $ex['EXIF']['LensModel'] ?? 
                                                  $ex['EXIF']['LensMake'] ?? 
                                                  $ex['EXIF']['LensSpecification'] ?? null);
            
            // Exposure settings
            $meta['ISOSpeedRatings'] = $ex['EXIF']['ISOSpeedRatings'] ?? $ex['EXIF']['ISO'] ?? null;
            $meta['ExposureTime'] = $ex['EXIF']['ExposureTime'] ?? null;
            $meta['ShutterSpeedValue'] = $ex['EXIF']['ShutterSpeedValue'] ?? null;
            $meta['FNumber'] = isset($ex['EXIF']['FNumber']) ? $this->rationalToFloat($ex['EXIF']['FNumber']) : null;
            $meta['ApertureValue'] = isset($ex['EXIF']['ApertureValue']) ? $this->rationalToFloat($ex['EXIF']['ApertureValue']) : null;
            $meta['FocalLength'] = isset($ex['EXIF']['FocalLength']) ? $this->rationalToFloat($ex['EXIF']['FocalLength']) : null;
            
            // Date/time
            $meta['DateTimeOriginal'] = $ex['EXIF']['DateTimeOriginal'] ?? $ex['EXIF']['DateTime'] ?? null;
            
            // Image properties
            $meta['Orientation'] = $ex['IFD0']['Orientation'] ?? 1;
            $meta['ColorSpace'] = $ex['EXIF']['ColorSpace'] ?? null;
            $meta['WhiteBalance'] = $ex['EXIF']['WhiteBalance'] ?? null;
            $meta['Flash'] = $ex['EXIF']['Flash'] ?? null;
            
            // GPS data (if available)
            if (isset($ex['GPS'])) {
                $meta['GPS'] = $this->extractGPS($ex['GPS']);
            }
        }
        return $meta;
    }

    private function cleanString(?string $str): ?string
    {
        if (!$str) return null;
        return trim(preg_replace('/\s+/', ' ', $str));
    }

    private function rationalToFloat($val): ?float
    {
        if (is_string($val) && str_contains($val, '/')) {
            [$n, $d] = array_pad(array_map('floatval', explode('/', $val, 2)), 2, 1.0);
            return $d != 0.0 ? $n / $d : null;
        }
        return is_numeric($val) ? (float)$val : null;
    }

    private function extractGPS(array $gps): ?array
    {
        if (empty($gps['GPSLatitude']) || empty($gps['GPSLongitude'])) return null;
        
        $lat = $this->convertDMSToDD($gps['GPSLatitude'], $gps['GPSLatitudeRef'] ?? 'N');
        $lng = $this->convertDMSToDD($gps['GPSLongitude'], $gps['GPSLongitudeRef'] ?? 'E');
        
        return $lat && $lng ? ['lat' => $lat, 'lng' => $lng] : null;
    }

    private function convertDMSToDD(array $dms, string $ref): ?float
    {
        if (count($dms) < 3) return null;
        
        $dd = $this->rationalToFloat($dms[0]) + 
              ($this->rationalToFloat($dms[1]) / 60) + 
              ($this->rationalToFloat($dms[2]) / 3600);
        
        if (in_array($ref, ['S', 'W'])) $dd *= -1;
        
        return $dd;
    }

    public function normalizeOrientation(string $imagePath, int $orientation): bool
    {
        if ($orientation <= 1) return true;
        
        if (!extension_loaded('gd') && !extension_loaded('imagick')) {
            return false;
        }
        
        try {
            if (extension_loaded('imagick')) {
                return $this->normalizeWithImagick($imagePath, $orientation);
            } else {
                return $this->normalizeWithGD($imagePath, $orientation);
            }
        } catch (\Throwable $e) {
            error_log("EXIF orientation fix failed: " . $e->getMessage());
            return false;
        }
    }

    private function normalizeWithImagick(string $path, int $orientation): bool
    {
        $imagick = new \Imagick($path);
        
        switch ($orientation) {
            case 2: // flip horizontal
                $imagick->flopImage();
                break;
            case 3: // rotate 180
                $imagick->rotateImage('transparent', 180);
                break;
            case 4: // flip vertical
                $imagick->flipImage();
                break;
            case 5: // rotate 90 CW + flip horizontal
                $imagick->rotateImage('transparent', 90);
                $imagick->flopImage();
                break;
            case 6: // rotate 90 CW
                $imagick->rotateImage('transparent', 90);
                break;
            case 7: // rotate 90 CCW + flip horizontal
                $imagick->rotateImage('transparent', -90);
                $imagick->flopImage();
                break;
            case 8: // rotate 90 CCW
                $imagick->rotateImage('transparent', -90);
                break;
        }
        
        $imagick->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
        return $imagick->writeImage($path);
    }

    private function normalizeWithGD(string $path, int $orientation): bool
    {
        $info = getimagesize($path);
        if (!$info) return false;
        
        $image = match ($info['mime']) {
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/png' => imagecreatefrompng($path),
            default => null
        };
        
        if (!$image) return false;
        
        $rotated = match ($orientation) {
            2 => imageflip($image, IMG_FLIP_HORIZONTAL) ? $image : null,
            3 => imagerotate($image, 180, 0),
            4 => imageflip($image, IMG_FLIP_VERTICAL) ? $image : null,
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image
        };
        
        if (!$rotated) {
            imagedestroy($image);
            return false;
        }
        
        $success = match ($info['mime']) {
            'image/jpeg' => imagejpeg($rotated, $path, 92),
            'image/png' => imagepng($rotated, $path, 6),
            default => false
        };
        
        imagedestroy($rotated);
        if ($rotated !== $image) imagedestroy($image);
        
        return $success;
    }

    public function mapToLookups(array $meta): array
    {
        $pdo = $this->db->pdo();
        $mapped = ['camera_id' => null, 'lens_id' => null];
        
        // Camera mapping with fuzzy matching
        $make = $meta['Make'] ?? null;
        $model = $meta['Model'] ?? null;
        if ($make && $model) {
            $mapped['camera_id'] = $this->findOrCreateCamera($pdo, $make, $model);
        }
        
        // Lens mapping
        $lens = $meta['LensModel'] ?? null;
        if ($lens) {
            $mapped['lens_id'] = $this->findOrCreateLens($pdo, $lens);
        }
        
        return $mapped;
    }

    private function findOrCreateCamera(\PDO $pdo, string $make, string $model): ?int
    {
        $cleanMake = $this->normalizeBrand($make);
        $cleanModel = $this->normalizeModel($model);
        
        // Exact match first
        $stmt = $pdo->prepare('SELECT id FROM cameras WHERE make = :make AND model = :model LIMIT 1');
        $stmt->execute([':make' => $cleanMake, ':model' => $cleanModel]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
        
        // Fuzzy match on model (same make)
        $stmt = $pdo->prepare('SELECT id FROM cameras WHERE make = :make AND SOUNDEX(model) = SOUNDEX(:model) LIMIT 1');
        $stmt->execute([':make' => $cleanMake, ':model' => $cleanModel]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
        
        // Create new camera
        try {
            $stmt = $pdo->prepare('INSERT INTO cameras (make, model) VALUES (:make, :model)');
            $stmt->execute([':make' => $cleanMake, ':model' => $cleanModel]);
            return (int)$pdo->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }

    private function findOrCreateLens(\PDO $pdo, string $lensModel): ?int
    {
        $parts = $this->parseLensModel($lensModel);
        if (!$parts) return null;
        
        ['brand' => $brand, 'model' => $model] = $parts;
        
        // Exact match
        $stmt = $pdo->prepare('SELECT id FROM lenses WHERE brand = :brand AND model = :model LIMIT 1');
        $stmt->execute([':brand' => $brand, ':model' => $model]);
        $id = $stmt->fetchColumn();
        if ($id) return (int)$id;
        
        // Create new lens
        try {
            $stmt = $pdo->prepare('INSERT INTO lenses (brand, model) VALUES (:brand, :model)');
            $stmt->execute([':brand' => $brand, ':model' => $model]);
            return (int)$pdo->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeBrand(string $brand): string
    {
        $normalized = ucwords(strtolower(trim($brand)));
        
        // Common brand normalizations
        $brandMap = [
            'Nikon Corporation' => 'Nikon',
            'Canon Inc.' => 'Canon',
            'Sony Corporation' => 'Sony',
            'Fujifilm' => 'Fuji',
            'Olympus Corporation' => 'Olympus',
            'Panasonic Corporation' => 'Panasonic',
        ];
        
        return $brandMap[$normalized] ?? $normalized;
    }

    private function normalizeModel(string $model): string
    {
        return trim(preg_replace('/\s+/', ' ', $model));
    }

    private function parseLensModel(string $lensModel): ?array
    {
        $clean = trim($lensModel);
        if (!$clean) return null;
        
        // Try to extract brand from common patterns
        $patterns = [
            '/^(Canon|Nikon|Sony|Sigma|Tamron|Tokina|Zeiss|Leica|Fuji|Olympus)\s+(.+)$/i',
            '/^(\w+)\s+(.+)$/i' // Fallback: first word as brand
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $clean, $matches)) {
                return [
                    'brand' => ucwords(strtolower($matches[1])),
                    'model' => trim($matches[2])
                ];
            }
        }
        
        // If no brand detected, use full string as model with "Unknown" brand
        return [
            'brand' => 'Unknown',
            'model' => $clean
        ];
    }

    public function formatShutterSpeed(?string $exposureTime): ?string
    {
        if (!$exposureTime) return null;
        
        $speed = $this->rationalToFloat($exposureTime);
        if (!$speed) return $exposureTime;
        
        if ($speed >= 1) {
            return (int)$speed . 's';
        } else {
            return '1/' . (int)round(1 / $speed);
        }
    }

    public function formatAperture(?float $fnumber): ?string
    {
        return $fnumber ? 'f/' . number_format($fnumber, 1) : null;
    }
}

