<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Logger;
use lsolesen\pel\PelJpeg;
use lsolesen\pel\PelTiff;
use lsolesen\pel\PelIfd;
use lsolesen\pel\PelTag;

class ExifService
{
    public function __construct(private Database $db) {}

    /**
     * Extract EXIF metadata from an image file.
     * Uses PEL library (pure PHP) as primary method, supplements with native exif_read_data.
     */
    public function extract(string $path): array
    {
        if (!@is_file($path)) {
            return [];
        }

        // Try PEL library first (pure PHP, more complete EXIF extraction)
        $meta = $this->extractWithPel($path);

        // Supplement with native PHP for any missing fields (PEL sometimes misses EXIF sub-IFD entries)
        if (function_exists('exif_read_data')) {
            $native = $this->extractWithNative($path);
            foreach ($native as $key => $value) {
                if ($value !== null && empty($meta[$key])) {
                    $meta[$key] = $value;
                }
            }
        }

        return $meta;
    }

    /**
     * Extract EXIF using PEL library (pure PHP, no external dependencies).
     */
    private function extractWithPel(string $path): array
    {
        $meta = [];

        try {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (in_array($ext, ['jpg', 'jpeg'])) {
                $jpeg = new PelJpeg($path);
                $exif = $jpeg->getExif();
                if (!$exif) {
                    return $meta;
                }
                $tiff = $exif->getTiff();
            } elseif (in_array($ext, ['tiff', 'tif'])) {
                $tiff = new PelTiff($path);
            } else {
                // PEL only supports JPEG and TIFF
                return $meta;
            }

            if (!$tiff) {
                return $meta;
            }

            $ifd0 = $tiff->getIfd();
            if (!$ifd0) {
                return $meta;
            }

            // Get IFD0 entries (Make, Model, Orientation)
            $meta['Make'] = $this->getPelEntryValue($ifd0, PelTag::MAKE);
            $meta['Model'] = $this->getPelEntryValue($ifd0, PelTag::MODEL);
            $meta['Orientation'] = $this->getPelEntryValue($ifd0, PelTag::ORIENTATION) ?? 1;

            // Get IFD0 metadata (Software, Artist, Copyright)
            $meta['Software'] = $this->getPelEntryValue($ifd0, PelTag::SOFTWARE);
            $meta['Artist'] = $this->getPelEntryValue($ifd0, PelTag::ARTIST);
            $meta['Copyright'] = $this->getPelEntryValue($ifd0, PelTag::COPYRIGHT);

            // Get EXIF sub-IFD entries
            $exifIfd = $ifd0->getSubIfd(PelIfd::EXIF);
            if ($exifIfd) {
                // Basic exposure settings
                $meta['ExposureTime'] = $this->getPelRationalString($exifIfd, PelTag::EXPOSURE_TIME);
                $meta['FNumber'] = $this->getPelRationalFloat($exifIfd, PelTag::FNUMBER);
                $meta['ISOSpeedRatings'] = $this->getPelEntryValue($exifIfd, PelTag::ISO_SPEED_RATINGS);
                $meta['DateTimeOriginal'] = $this->getPelEntryValue($exifIfd, PelTag::DATE_TIME_ORIGINAL);
                $meta['FocalLength'] = $this->getPelRationalFloat($exifIfd, PelTag::FOCAL_LENGTH);
                $meta['Flash'] = $this->getPelEntryValue($exifIfd, PelTag::FLASH);
                $meta['WhiteBalance'] = $this->getPelEntryValue($exifIfd, PelTag::WHITE_BALANCE);
                $meta['ColorSpace'] = $this->getPelEntryValue($exifIfd, 0xA001); // ColorSpace tag

                // LensModel tag (0xA434) - not defined as constant in PelTag
                $meta['LensModel'] = $this->getPelEntryValue($exifIfd, 0xA434);

                // Extended exposure info
                $meta['ExposureBiasValue'] = $this->getPelRationalFloat($exifIfd, PelTag::EXPOSURE_BIAS_VALUE);
                $meta['MeteringMode'] = $this->getPelEntryValue($exifIfd, PelTag::METERING_MODE);
                $meta['ExposureMode'] = $this->getPelEntryValue($exifIfd, PelTag::EXPOSURE_MODE);
                $meta['ExposureProgram'] = $this->getPelEntryValue($exifIfd, PelTag::EXPOSURE_PROGRAM);
                $meta['FocalLengthIn35mmFilm'] = $this->getPelEntryValue($exifIfd, 0xA405);

                // Image processing settings
                $meta['Contrast'] = $this->getPelEntryValue($exifIfd, PelTag::CONTRAST);
                $meta['Saturation'] = $this->getPelEntryValue($exifIfd, PelTag::SATURATION);
                $meta['Sharpness'] = $this->getPelEntryValue($exifIfd, PelTag::SHARPNESS);
                $meta['SceneCaptureType'] = $this->getPelEntryValue($exifIfd, PelTag::SCENE_CAPTURE_TYPE);

                // Additional useful fields
                $meta['SubjectDistance'] = $this->getPelRationalFloat($exifIfd, PelTag::SUBJECT_DISTANCE);
                $meta['LightSource'] = $this->getPelEntryValue($exifIfd, PelTag::LIGHT_SOURCE);
                $meta['DigitalZoomRatio'] = $this->getPelRationalFloat($exifIfd, 0xA404);
            }

            // Get GPS sub-IFD entries
            $gpsIfd = $ifd0->getSubIfd(PelIfd::GPS);
            if ($gpsIfd) {
                $meta['GPS'] = $this->extractGpsFromPel($gpsIfd);
            }

        } catch (\Throwable $e) {
            Logger::warning('ExifService: PEL extraction failed', [
                'error' => $e->getMessage(),
                'path' => $path
            ], 'upload');
        }

        return $meta;
    }

    /**
     * Get a simple value from a PEL IFD entry.
     */
    private function getPelEntryValue(PelIfd $ifd, int $tag): mixed
    {
        $entry = $ifd->getEntry($tag);
        if (!$entry) {
            return null;
        }

        $value = $entry->getValue();

        // Handle arrays (return first element for simple values)
        if (is_array($value)) {
            return $value[0] ?? null;
        }

        // Clean strings
        if (is_string($value)) {
            return $this->cleanString($value);
        }

        return $value;
    }

    /**
     * Get a rational value as float from a PEL IFD entry.
     */
    private function getPelRationalFloat(PelIfd $ifd, int $tag): ?float
    {
        $entry = $ifd->getEntry($tag);
        if (!$entry) {
            return null;
        }

        $value = $entry->getValue();
        if (is_array($value) && count($value) >= 2) {
            // Rational format: [numerator, denominator]
            $num = (float)$value[0];
            $den = (float)$value[1];
            return $den != 0 ? $num / $den : null;
        }

        return is_numeric($value) ? (float)$value : null;
    }

    /**
     * Get a rational value as original fraction string from a PEL IFD entry.
     */
    private function getPelRationalString(PelIfd $ifd, int $tag): ?string
    {
        $entry = $ifd->getEntry($tag);
        if (!$entry) {
            return null;
        }

        $value = $entry->getValue();
        if (is_array($value) && count($value) >= 2) {
            return $value[0] . '/' . $value[1];
        }

        return is_string($value) ? $value : (string)$value;
    }

    /**
     * Extract GPS coordinates from a PEL GPS IFD.
     */
    private function extractGpsFromPel(PelIfd $gpsIfd): ?array
    {
        $latEntry = $gpsIfd->getEntry(PelTag::GPS_LATITUDE);
        $lonEntry = $gpsIfd->getEntry(PelTag::GPS_LONGITUDE);

        if (!$latEntry || !$lonEntry) {
            return null;
        }

        $latRef = $this->getPelEntryValue($gpsIfd, PelTag::GPS_LATITUDE_REF) ?? 'N';
        $lonRef = $this->getPelEntryValue($gpsIfd, PelTag::GPS_LONGITUDE_REF) ?? 'E';

        $lat = $this->convertPelGpsToDd($latEntry->getValue(), $latRef);
        $lng = $this->convertPelGpsToDd($lonEntry->getValue(), $lonRef);

        return ($lat !== null && $lng !== null) ? ['lat' => $lat, 'lng' => $lng] : null;
    }

    /**
     * Convert PEL GPS format (array of rationals) to decimal degrees.
     */
    private function convertPelGpsToDd(array $dms, string $ref): ?float
    {
        if (count($dms) < 3) {
            return null;
        }

        // Each DMS component is [numerator, denominator]
        $degrees = is_array($dms[0]) ? ($dms[0][0] / $dms[0][1]) : (float)$dms[0];
        $minutes = is_array($dms[1]) ? ($dms[1][0] / $dms[1][1]) : (float)$dms[1];
        $seconds = is_array($dms[2]) ? ($dms[2][0] / $dms[2][1]) : (float)$dms[2];

        $dd = $degrees + ($minutes / 60) + ($seconds / 3600);

        if (in_array(strtoupper($ref), ['S', 'W'])) {
            $dd *= -1;
        }

        return $dd;
    }

    /**
     * Fallback: Extract EXIF using native PHP exif_read_data function.
     */
    private function extractWithNative(string $path): array
    {
        $meta = [];

        try {
            $ex = @exif_read_data($path, null, true) ?: [];

            // Basic camera info
            $meta['Make'] = $this->cleanString($ex['IFD0']['Make'] ?? $ex['EXIF']['Make'] ?? null);
            $meta['Model'] = $this->cleanString($ex['IFD0']['Model'] ?? $ex['EXIF']['Model'] ?? null);

            // IFD0 metadata
            $meta['Software'] = $this->cleanString($ex['IFD0']['Software'] ?? null);
            $meta['Artist'] = $this->cleanString($ex['IFD0']['Artist'] ?? null);
            $meta['Copyright'] = $this->cleanString($ex['IFD0']['Copyright'] ?? null);

            // Lens info (multiple possible tags)
            $meta['LensModel'] = $this->cleanString($ex['EXIF']['UndefinedTag:0xA434'] ??
                                                  $ex['EXIF']['LensModel'] ??
                                                  $ex['EXIF']['LensMake'] ??
                                                  $ex['EXIF']['LensSpecification'] ?? null);

            // Basic exposure settings
            $meta['ISOSpeedRatings'] = $ex['EXIF']['ISOSpeedRatings'] ?? $ex['EXIF']['ISO'] ?? null;
            $meta['ExposureTime'] = $ex['EXIF']['ExposureTime'] ?? null;
            $meta['ShutterSpeedValue'] = $ex['EXIF']['ShutterSpeedValue'] ?? null;
            $meta['FNumber'] = isset($ex['EXIF']['FNumber']) ? $this->rationalToFloat($ex['EXIF']['FNumber']) : null;
            $meta['ApertureValue'] = isset($ex['EXIF']['ApertureValue']) ? $this->rationalToFloat($ex['EXIF']['ApertureValue']) : null;
            $meta['FocalLength'] = isset($ex['EXIF']['FocalLength']) ? $this->rationalToFloat($ex['EXIF']['FocalLength']) : null;

            // Extended exposure info
            $meta['ExposureBiasValue'] = isset($ex['EXIF']['ExposureBiasValue']) ? $this->rationalToFloat($ex['EXIF']['ExposureBiasValue']) : null;
            $meta['MeteringMode'] = $ex['EXIF']['MeteringMode'] ?? null;
            $meta['ExposureMode'] = $ex['EXIF']['ExposureMode'] ?? null;
            $meta['ExposureProgram'] = $ex['EXIF']['ExposureProgram'] ?? null;
            $meta['FocalLengthIn35mmFilm'] = $ex['EXIF']['FocalLengthIn35mmFilm'] ?? $ex['EXIF']['FocalLengthIn35mmFormat'] ?? null;

            // Image processing settings
            $meta['Contrast'] = $ex['EXIF']['Contrast'] ?? null;
            $meta['Saturation'] = $ex['EXIF']['Saturation'] ?? null;
            $meta['Sharpness'] = $ex['EXIF']['Sharpness'] ?? null;
            $meta['SceneCaptureType'] = $ex['EXIF']['SceneCaptureType'] ?? null;

            // Additional useful fields
            $meta['SubjectDistance'] = isset($ex['EXIF']['SubjectDistance']) ? $this->rationalToFloat($ex['EXIF']['SubjectDistance']) : null;
            $meta['LightSource'] = $ex['EXIF']['LightSource'] ?? null;
            $meta['DigitalZoomRatio'] = isset($ex['EXIF']['DigitalZoomRatio']) ? $this->rationalToFloat($ex['EXIF']['DigitalZoomRatio']) : null;

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
        } catch (\Throwable $e) {
            Logger::warning('ExifService: Native extraction failed', [
                'error' => $e->getMessage(),
                'path' => $path
            ], 'upload');
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
            Logger::warning('ExifService: EXIF orientation fix failed', ['error' => $e->getMessage(), 'path' => $imagePath], 'upload');
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
        
        // Fuzzy match on model (same make) - use LIKE for SQLite compatibility
        $stmt = $pdo->prepare('SELECT id FROM cameras WHERE make = :make AND LOWER(model) LIKE LOWER(:model) LIMIT 1');
        $stmt->execute([':make' => $cleanMake, ':model' => '%' . $cleanModel . '%']);
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

    /**
     * Format exposure bias value with +/- prefix.
     */
    public function formatExposureBias(?float $bias): ?string
    {
        if ($bias === null) return null;
        if ($bias == 0) return '±0 EV';
        $sign = $bias > 0 ? '+' : '';
        return $sign . number_format($bias, 1) . ' EV';
    }

    /**
     * Get human-readable metering mode name.
     */
    private function getMeteringModeName(?int $mode): ?string
    {
        if ($mode === null) return null;
        return match ($mode) {
            0 => 'Unknown',
            1 => 'Average',
            2 => 'Center-weighted',
            3 => 'Spot',
            4 => 'Multi-spot',
            5 => 'Multi-segment',
            6 => 'Partial',
            255 => 'Other',
            default => null
        };
    }

    /**
     * Get human-readable exposure program name.
     */
    private function getExposureProgramName(?int $program): ?string
    {
        if ($program === null) return null;
        return match ($program) {
            0 => 'Unknown',
            1 => 'Manual',
            2 => 'Program (Auto)',
            3 => 'Aperture Priority',
            4 => 'Shutter Priority',
            5 => 'Creative',
            6 => 'Action',
            7 => 'Portrait',
            8 => 'Landscape',
            default => null
        };
    }

    /**
     * Get human-readable exposure mode name.
     */
    private function getExposureModeName(?int $mode): ?string
    {
        if ($mode === null) return null;
        return match ($mode) {
            0 => 'Auto',
            1 => 'Manual',
            2 => 'Auto Bracket',
            default => null
        };
    }

    /**
     * Get human-readable scene capture type.
     */
    private function getSceneCaptureTypeName(?int $type): ?string
    {
        if ($type === null) return null;
        return match ($type) {
            0 => 'Standard',
            1 => 'Landscape',
            2 => 'Portrait',
            3 => 'Night Scene',
            default => null
        };
    }

    /**
     * Get human-readable light source name.
     */
    private function getLightSourceName(?int $source): ?string
    {
        if ($source === null) return null;
        return match ($source) {
            0 => 'Unknown',
            1 => 'Daylight',
            2 => 'Fluorescent',
            3 => 'Tungsten',
            4 => 'Flash',
            9 => 'Fine Weather',
            10 => 'Cloudy',
            11 => 'Shade',
            12 => 'Daylight Fluorescent',
            13 => 'Day White Fluorescent',
            14 => 'Cool White Fluorescent',
            15 => 'White Fluorescent',
            17 => 'Standard Light A',
            18 => 'Standard Light B',
            19 => 'Standard Light C',
            20 => 'D55',
            21 => 'D65',
            22 => 'D75',
            23 => 'D50',
            24 => 'ISO Studio Tungsten',
            default => null
        };
    }

    /**
     * Extract and format EXIF data for lightbox display.
     * Returns a structured array ready for JSON output.
     *
     * @param string $path Absolute path to the image file
     * @return array Formatted EXIF data with categories
     */
    public function extractForLightbox(string $path): array
    {
        $meta = $this->extract($path);
        $result = [
            'success' => true,
            'sections' => []
        ];

        // Equipment section (Camera, Lens)
        $equipment = [];
        if (!empty($meta['Make']) || !empty($meta['Model'])) {
            $make = $meta['Make'] ?? '';
            $model = $meta['Model'] ?? '';
            // Avoid duplication if Model already contains Make
            if ($make && $model && stripos($model, $make) === 0) {
                $cameraName = $model;
            } else {
                $cameraName = trim($make . ' ' . $model);
            }
            if ($cameraName) {
                $equipment[] = ['label' => 'Camera', 'value' => $cameraName, 'icon' => 'fa-camera'];
            }
        }
        if (!empty($meta['LensModel'])) {
            $equipment[] = ['label' => 'Lens', 'value' => $meta['LensModel'], 'icon' => 'fa-circle-dot'];
        }
        if (!empty($equipment)) {
            $result['sections'][] = ['title' => 'Equipment', 'items' => $equipment];
        }

        // Exposure section (basic shooting parameters)
        $exposure = [];
        if (!empty($meta['ExposureTime'])) {
            $shutter = $this->formatShutterSpeed($meta['ExposureTime']);
            if ($shutter) {
                $exposure[] = ['label' => 'Shutter', 'value' => $shutter, 'icon' => 'fa-clock'];
            }
        }
        if (!empty($meta['FNumber'])) {
            $aperture = $this->formatAperture($meta['FNumber']);
            if ($aperture) {
                $exposure[] = ['label' => 'Aperture', 'value' => $aperture, 'icon' => 'fa-aperture'];
            }
        }
        if (!empty($meta['ISOSpeedRatings'])) {
            $iso = \is_array($meta['ISOSpeedRatings']) ? $meta['ISOSpeedRatings'][0] : $meta['ISOSpeedRatings'];
            $exposure[] = ['label' => 'ISO', 'value' => (string)$iso, 'icon' => 'fa-gauge-high'];
        }
        if (!empty($meta['FocalLength'])) {
            $focalValue = round($meta['FocalLength']) . 'mm';
            // Add 35mm equivalent if available and different
            if (!empty($meta['FocalLengthIn35mmFilm'])) {
                $equiv = (int)$meta['FocalLengthIn35mmFilm'];
                if ($equiv !== (int)round($meta['FocalLength'])) {
                    $focalValue .= ' (' . $equiv . 'mm eq.)';
                }
            }
            $exposure[] = ['label' => 'Focal Length', 'value' => $focalValue, 'icon' => 'fa-ruler-horizontal'];
        }
        if (isset($meta['ExposureBiasValue']) && $meta['ExposureBiasValue'] !== null) {
            $bias = $this->formatExposureBias($meta['ExposureBiasValue']);
            if ($bias) {
                $exposure[] = ['label' => 'Exp. Comp.', 'value' => $bias, 'icon' => 'fa-sliders'];
            }
        }
        if (!empty($exposure)) {
            $result['sections'][] = ['title' => 'Exposure', 'items' => $exposure];
        }

        // Camera Mode section (program, metering, exposure mode)
        $mode = [];
        if (isset($meta['ExposureProgram']) && $meta['ExposureProgram'] !== null) {
            $programName = $this->getExposureProgramName($meta['ExposureProgram']);
            if ($programName) {
                $mode[] = ['label' => 'Program', 'value' => $programName, 'icon' => 'fa-dial'];
            }
        }
        if (isset($meta['MeteringMode']) && $meta['MeteringMode'] !== null) {
            $meteringName = $this->getMeteringModeName($meta['MeteringMode']);
            if ($meteringName) {
                $mode[] = ['label' => 'Metering', 'value' => $meteringName, 'icon' => 'fa-bullseye'];
            }
        }
        if (isset($meta['ExposureMode']) && $meta['ExposureMode'] !== null) {
            $modeName = $this->getExposureModeName($meta['ExposureMode']);
            if ($modeName) {
                $mode[] = ['label' => 'Exp. Mode', 'value' => $modeName, 'icon' => 'fa-gear'];
            }
        }
        if (!empty($mode)) {
            $result['sections'][] = ['title' => 'Mode', 'items' => $mode];
        }

        // Date & Settings section
        $settings = [];
        if (!empty($meta['DateTimeOriginal'])) {
            $dateValue = $meta['DateTimeOriginal'];
            $date = null;

            // Handle Unix timestamp (from PEL library)
            if (is_numeric($dateValue)) {
                $date = new \DateTime();
                $date->setTimestamp((int)$dateValue);
            } else {
                // Handle EXIF string format "Y:m:d H:i:s" (from native PHP)
                $date = \DateTime::createFromFormat('Y:m:d H:i:s', $dateValue);
            }

            if ($date) {
                $settings[] = ['label' => 'Date', 'value' => $date->format('d M Y, H:i'), 'icon' => 'fa-calendar'];
            }
        }
        if (isset($meta['Flash']) && $meta['Flash'] !== null) {
            $flashFired = ($meta['Flash'] & 1) ? 'Yes' : 'No';
            $settings[] = ['label' => 'Flash', 'value' => $flashFired, 'icon' => 'fa-bolt'];
        }
        if (isset($meta['WhiteBalance']) && $meta['WhiteBalance'] !== null) {
            $wb = $meta['WhiteBalance'] == 0 ? 'Auto' : 'Manual';
            $settings[] = ['label' => 'White Balance', 'value' => $wb, 'icon' => 'fa-temperature-half'];
        }
        if (isset($meta['LightSource']) && $meta['LightSource'] !== null && $meta['LightSource'] != 0) {
            $lightName = $this->getLightSourceName($meta['LightSource']);
            if ($lightName) {
                $settings[] = ['label' => 'Light Source', 'value' => $lightName, 'icon' => 'fa-sun'];
            }
        }
        if (isset($meta['SceneCaptureType']) && $meta['SceneCaptureType'] !== null) {
            $sceneName = $this->getSceneCaptureTypeName($meta['SceneCaptureType']);
            if ($sceneName) {
                $settings[] = ['label' => 'Scene', 'value' => $sceneName, 'icon' => 'fa-image'];
            }
        }
        if (!empty($settings)) {
            $result['sections'][] = ['title' => 'Details', 'items' => $settings];
        }

        // GPS section
        if (!empty($meta['GPS']) && isset($meta['GPS']['lat']) && isset($meta['GPS']['lng'])) {
            $gps = [];
            $lat = round($meta['GPS']['lat'], 6);
            $lng = round($meta['GPS']['lng'], 6);
            $gps[] = ['label' => 'Coordinates', 'value' => "{$lat}, {$lng}", 'icon' => 'fa-location-dot'];
            // Add Google Maps link
            $gps[] = ['label' => 'Map', 'value' => "https://www.google.com/maps?q={$lat},{$lng}", 'icon' => 'fa-map', 'isLink' => true];
            $result['sections'][] = ['title' => 'Location', 'items' => $gps];
        }

        // Metadata section (software, artist, copyright)
        $metadata = [];
        if (!empty($meta['Software'])) {
            $metadata[] = ['label' => 'Software', 'value' => $meta['Software'], 'icon' => 'fa-wand-magic-sparkles'];
        }
        if (!empty($meta['Artist'])) {
            $metadata[] = ['label' => 'Artist', 'value' => $meta['Artist'], 'icon' => 'fa-user'];
        }
        if (!empty($meta['Copyright'])) {
            $metadata[] = ['label' => 'Copyright', 'value' => $meta['Copyright'], 'icon' => 'fa-copyright'];
        }
        if (!empty($metadata)) {
            $result['sections'][] = ['title' => 'Info', 'items' => $metadata];
        }

        // If no EXIF data found
        if (empty($result['sections'])) {
            $result['success'] = false;
            $result['message'] = 'No EXIF data available';
        }

        return $result;
    }
}

