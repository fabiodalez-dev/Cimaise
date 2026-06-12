<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Logger;
use App\Traits\RegistersImageVariants;
use finfo;
use RuntimeException;

class UploadService
{
    use RegistersImageVariants;

    /** Maximum total pixel count accepted (decompression-bomb guard, ~40 megapixel). */
    private const MAX_IMAGE_PIXELS = 40000000;

    private array $allowed = ['image/jpeg'=>'.jpg','image/png'=>'.png', 'image/webp'=>'.webp'];
    
    // Magic number signatures for image validation
    private array $magicNumbers = [
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png' => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"],
        'image/webp' => ["RIFF", "WEBP"], // RIFF...WEBP
        'image/gif' => ["GIF87a", "GIF89a"]
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * Apply process-global Imagick resource limits so a crafted image cannot
     * exhaust host memory/disk during decode. Static + idempotent; safe to call
     * before every Imagick instantiation. No-op when the extension is absent.
     */
    public static function applyImagickLimits(): void
    {
        if (!class_exists('\Imagick')) {
            return;
        }
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 512 * 1024 * 1024);   // 512 MB
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, 1024 * 1024 * 1024);     // 1 GB
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_AREA, self::MAX_IMAGE_PIXELS);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_DISK, 2 * 1024 * 1024 * 1024); // 2 GB
    }

    /**
     * Delete a file only when it resolves inside an allowed base directory
     * (storage/, system temp, or the public media tree). Prevents a tainted
     * path from ever reaching unlink() outside these roots (CWE-22 hardening).
     */
    public static function safeUnlink(string $path): bool
    {
        $real = realpath($path);
        if ($real === false) {
            return false;
        }

        $allowedRoots = [
            realpath(dirname(__DIR__, 2) . '/storage') ?: '',
            realpath(dirname(__DIR__, 2) . '/public/media') ?: '',
            realpath(sys_get_temp_dir()) ?: '',
        ];

        foreach ($allowedRoots as $root) {
            if ($root !== '' && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                return @unlink($real);
            }
        }

        Logger::warning('UploadService: refused unlink outside allowed roots', ['path' => $path], 'security');
        return false;
    }
    
    /**
     * Validates file using both MIME type detection and magic number verification
     */
    private function validateImageFile(string $filePath): string
    {
        // 1. Check if file exists and is readable
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('File not accessible');
        }
        
        // 2. Check file size (prevent DoS attacks)
        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize > 50 * 1024 * 1024) { // 50MB limit
            throw new RuntimeException('File too large');
        }
        
        if ($fileSize < 12) { // Minimum size for valid image headers
            throw new RuntimeException('File too small to be a valid image');
        }
        
        // 3. Detect MIME type using fileinfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        
        if (!$detectedMime || !isset($this->allowed[$detectedMime])) {
            throw new RuntimeException('Unsupported file type: ' . ($detectedMime ?: 'unknown'));
        }
        
        // 4. Validate magic numbers (file header signatures)
        $fileHeader = file_get_contents($filePath, false, null, 0, 12);
        if ($fileHeader === false) {
            throw new RuntimeException('Cannot read file header');
        }
        
        $isValidMagic = false;
        if (isset($this->magicNumbers[$detectedMime])) {
            foreach ($this->magicNumbers[$detectedMime] as $signature) {
                if ($detectedMime === 'image/webp') {
                    // WebP has RIFF at start and WEBP at offset 8
                    if (str_starts_with($fileHeader, 'RIFF') && str_contains($fileHeader, 'WEBP')) {
                        $isValidMagic = true;
                        break;
                    }
                } else {
                    if (str_starts_with($fileHeader, $signature)) {
                        $isValidMagic = true;
                        break;
                    }
                }
            }
        }
        
        if (!$isValidMagic) {
            throw new RuntimeException('File header does not match expected format');
        }
        
        // 5. Additional validation: try to get image dimensions
        $imageInfo = getimagesize($filePath);
        if ($imageInfo === false) {
            throw new RuntimeException('Invalid image file - cannot read dimensions');
        }
        
        // 6. Validate image dimensions (prevent processing of malicious files)
        [$width, $height] = $imageInfo;
        if ($width <= 0 || $height <= 0 || $width > 20000 || $height > 20000) {
            throw new RuntimeException('Invalid image dimensions');
        }

        // 6b. Decompression-bomb guard: cap total pixel count before any GD/Imagick
        // decode runs. A small, highly-compressible file can declare huge dimensions
        // and exhaust memory when decoded. 40 MP is generous for photography.
        if ($width * $height > self::MAX_IMAGE_PIXELS) {
            throw new RuntimeException('Image resolution too high');
        }

        return $detectedMime;
    }

    public function ingestAlbumUpload(int $albumId, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload error: ' . $this->getUploadErrorMessage($file['error'] ?? UPLOAD_ERR_NO_FILE));
        }
        
        $tmp = $file['tmp_name'];
        if (empty($tmp)) {
            throw new RuntimeException('No temporary file provided');
        }
        
        // SECURITY: Comprehensive file validation with magic number check
        $mime = $this->validateImageFile($tmp);
        
        $hash = sha1_file($tmp) ?: bin2hex(random_bytes(20));
        $ext = $this->allowed[$mime];
        $storageDir = dirname(__DIR__, 2) . '/storage/originals';
        ImagesService::ensureDir($storageDir);
        $dest = $storageDir . '/' . $hash . $ext;
        
        if (!@move_uploaded_file($tmp, $dest)) {
            // Fallback for CLI env
            if (!@rename($tmp, $dest)) {
                // Final fallback: copy+unlink (works across filesystems)
                if (!@copy($tmp, $dest)) {
                    throw new RuntimeException('Failed to move uploaded file');
                }
                if (!self::safeUnlink($tmp)) {
                    Logger::warning('UploadService: Failed to cleanup temp file after copy', [
                        'tmp' => $tmp,
                        'dest' => $dest,
                    ], 'upload');
                }
            }
        }
        
        // Verify file was moved successfully and re-validate
        if (!is_file($dest)) {
            throw new RuntimeException('File upload verification failed');
        }
        
        // Re-validate the moved file for additional security
        try {
            $this->validateImageFile($dest);
        } catch (RuntimeException $e) {
            // Clean up the invalid file
            self::safeUnlink($dest);
            throw new RuntimeException('File validation failed after upload: ' . $e->getMessage());
        }
        
        [$width, $height] = getimagesize($dest) ?: [0,0];
        // Extract EXIF and map lookups (best effort)
        $exifSvc = new \App\Services\ExifService($this->db);
        $exif = $exifSvc->extract($dest);
        $map = $exifSvc->mapToLookups($exif);
        
        // Normalize image orientation if needed
        if (isset($exif['Orientation']) && $exif['Orientation'] > 1) {
            $exifSvc->normalizeOrientation($dest, (int)$exif['Orientation']);
            // Re-read dimensions after rotation
            $size = getimagesize($dest);
            if ($size) {
                $width = $size[0];
                $height = $size[1];
            }
        }

        // Insert DB record with EXIF editor fields
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('INSERT INTO images(
            album_id, original_path, file_hash, width, height, mime, alt_text, caption, exif,
            camera_id, lens_id, iso, shutter_speed, aperture, sort_order,
            exif_make, exif_model, exif_lens_maker, exif_lens_model, software,
            focal_length, exposure_bias, flash, white_balance, exposure_program,
            metering_mode, exposure_mode, date_original, color_space, contrast,
            saturation, sharpness, scene_capture_type, light_source,
            gps_lat, gps_lng, artist, copyright
        ) VALUES(
            :a, :p, :h, :w, :h2, :m, NULL, NULL, :exif,
            :cam, :lens, :iso, :sh, :ap, :s,
            :exif_make, :exif_model, :exif_lens_maker, :exif_lens_model, :software,
            :focal_length, :exposure_bias, :flash, :white_balance, :exposure_program,
            :metering_mode, :exposure_mode, :date_original, :color_space, :contrast,
            :saturation, :sharpness, :scene_capture_type, :light_source,
            :gps_lat, :gps_lng, :artist, :copyright
        )');

        // Extract GPS coordinates if available
        $gpsLat = null;
        $gpsLng = null;
        if (!empty($exif['GPS'])) {
            $gpsLat = $exif['GPS']['lat'] ?? null;
            $gpsLng = $exif['GPS']['lng'] ?? null;
        }

        $stmt->execute([
            ':a'=>$albumId,
            ':p'=>str_replace(dirname(__DIR__, 2), '', $dest),
            ':h'=>$hash,
            ':w'=>$width,
            ':h2'=>$height,
            ':m'=>$mime,
            ':exif'=> json_encode($exif, JSON_UNESCAPED_SLASHES),
            ':cam'=> $map['camera_id'],
            ':lens'=> $map['lens_id'],
            ':iso'=> isset($exif['ISOSpeedRatings']) ? (int)(is_array($exif['ISOSpeedRatings']) ? ($exif['ISOSpeedRatings'][0] ?? 0) : $exif['ISOSpeedRatings']) : null,
            ':sh'=> $exifSvc->formatShutterSpeed($exif['ExposureTime'] ?? null),
            ':ap'=> $exif['FNumber'] ?? null,
            ':s'=>0,
            ':exif_make' => $exif['Make'] ?? null,
            ':exif_model' => $exif['Model'] ?? null,
            ':exif_lens_maker' => $exif['LensMake'] ?? null,
            ':exif_lens_model' => $exif['LensModel'] ?? null,
            ':software' => $exif['Software'] ?? null,
            ':focal_length' => isset($exif['FocalLength']) ? (float)$exif['FocalLength'] : null,
            ':exposure_bias' => isset($exif['ExposureBiasValue']) ? (float)$exif['ExposureBiasValue'] : null,
            ':flash' => isset($exif['Flash']) ? (int)$exif['Flash'] : null,
            ':white_balance' => isset($exif['WhiteBalance']) ? (int)$exif['WhiteBalance'] : null,
            ':exposure_program' => isset($exif['ExposureProgram']) ? (int)$exif['ExposureProgram'] : null,
            ':metering_mode' => isset($exif['MeteringMode']) ? (int)$exif['MeteringMode'] : null,
            ':exposure_mode' => isset($exif['ExposureMode']) ? (int)$exif['ExposureMode'] : null,
            ':date_original' => $exif['DateTimeOriginal'] ?? null,
            ':color_space' => isset($exif['ColorSpace']) ? (int)$exif['ColorSpace'] : null,
            ':contrast' => isset($exif['Contrast']) ? (int)$exif['Contrast'] : null,
            ':saturation' => isset($exif['Saturation']) ? (int)$exif['Saturation'] : null,
            ':sharpness' => isset($exif['Sharpness']) ? (int)$exif['Sharpness'] : null,
            ':scene_capture_type' => isset($exif['SceneCaptureType']) ? (int)$exif['SceneCaptureType'] : null,
            ':light_source' => isset($exif['LightSource']) ? (int)$exif['LightSource'] : null,
            ':gps_lat' => $gpsLat,
            ':gps_lng' => $gpsLng,
            ':artist' => $exif['Artist'] ?? null,
            ':copyright' => $exif['Copyright'] ?? null,
        ]);
        $imageId = (int)$pdo->lastInsertId();

        // Generate preview outside the web root when the album is protected.
        $albumFlagsStmt = $pdo->prepare('SELECT is_nsfw, password_hash FROM albums WHERE id = :id');
        $albumFlagsStmt->execute([':id' => $albumId]);
        $albumFlags = $albumFlagsStmt->fetch() ?: [];
        $isProtectedAlbum = (int)($albumFlags['is_nsfw'] ?? 0) === 1 || !empty($albumFlags['password_hash']);
        $protectedStorage = new ProtectedMediaStorage($this->db);
        $mediaDir = $protectedStorage->directoryForProtection($isProtectedAlbum);
        ImagesService::ensureDir($mediaDir);
        $settingsSvc = new \App\Services\SettingsService($this->db);
        $defaults = $settingsSvc->defaults();

        $previewSettings = $settingsSvc->get('image.preview', $defaults['image.preview']);
        if (!is_array($previewSettings)) {
            $previewSettings = $defaults['image.preview'];
        }
        $previewW = (int)($previewSettings['width'] ?? 480);
        $previewPath = $mediaDir . '/' . $imageId . '_sm.jpg';
        $preview = ImagesService::generateJpegPreview($dest, $previewPath, $previewW);
        if ($preview) {
            // The URL stays stable; MediaController maps it to public/ or
            // storage/protected-media after checking album access.
            $relUrl = "/media/{$imageId}_sm.jpg";
            $previewSize = @getimagesize($preview) ?: [$previewW, 0];
            $replaceKeyword = $this->db->replaceKeyword();
            $pdo->prepare(sprintf('%s INTO image_variants(image_id, variant, format, path, width, height, size_bytes) VALUES(?,?,?,?,?,?,?)', $replaceKeyword))
                ->execute([$imageId,'sm','jpg',$relUrl,$previewW,(int)$previewSize[1], (int)filesize($preview)]);
            $previewRel = $relUrl;
        } else {
            $previewRel = null;
        }

        // PERFORMANCE: Variant generation moved to controller after response flush.
        // ingestAlbumUpload() only generates sm preview for immediate UI feedback.
        // Full variants are generated:
        // - After fastcgi_finish_request() in FPM environments
        // - By VariantMaintenanceService cron in non-FPM environments

        // Fetch album settings for cover check
        $coverCheck = $pdo->prepare('SELECT cover_image_id FROM albums WHERE id = :id');
        $coverCheck->execute([':id' => $albumId]);
        $album = $coverCheck->fetch();

        // Set as cover if album doesn't have one yet
        if ($album && !$album['cover_image_id']) {
            $pdo->prepare('UPDATE albums SET cover_image_id = :imageId WHERE id = :albumId')
                ->execute([':imageId' => $imageId, ':albumId' => $albumId]);
        }

        return ['id'=>$imageId,'path'=>$dest,'mime'=>$mime,'width'=>$width,'height'=>$height,'preview_url'=>$previewRel];
    }

    private function resizeWithImagick(string $src, string $dest, int $targetW, string $format, int $quality): bool
    {
        try {
            self::applyImagickLimits();
            $im = new \Imagick($src);
            $im->setImageColorspace(\Imagick::COLORSPACE_RGB);
            $im->setInterlaceScheme(\Imagick::INTERLACE_JPEG);
            $im->thumbnailImage($targetW, 0);
            $im->setImageFormat($format);
            if ($format === 'webp' || $format === 'jpeg') {
                $im->setImageCompressionQuality($quality);
            } elseif ($format === 'avif') {
                $im->setOption('heic:quality', (string)$quality);
            }
            // Strip EXIF/metadata for privacy protection on generated variants
            // Original file keeps EXIF for archival purposes
            if ($this->envFlag('STRIP_EXIF', true)) {
                $im->stripImage();
            }
            @mkdir(dirname($dest), 0775, true);
            $ok = $im->writeImage($dest);
            $im->clear();
            return (bool)$ok;
        } catch (\Throwable $e) {
            Logger::warning('UploadService: Imagick resize failed', [
                'src' => $src,
                'format' => $format,
                'error' => $e->getMessage()
            ], 'upload');
            return false;
        }
    }

    private function resizeWithImagickOrGd(string $src, string $dest, int $targetW, string $format, int $quality): bool
    {
        if (class_exists(\Imagick::class) && !$this->imagickDisabled()) {
            return $this->resizeWithImagick($src, $dest, $targetW, $format, $quality);
        }
        // GD fallback JPEG only
        $info = @getimagesize($src);
        if (!$info) return false;
        [$w, $h] = $info;
        $ratio = $h > 0 ? $w / $h : 1;
        $newW = $targetW; $newH = (int)round($targetW / $ratio);
        $srcImg = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png' => @imagecreatefrompng($src),
            default => null,
        };
        if (!$srcImg) return false;
        $dst = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $srcImg, 0,0,0,0, $newW,$newH, $w,$h);
        @mkdir(dirname($dest), 0775, true);
        $ok = imagejpeg($dst, $dest, $quality);
        imagedestroy($srcImg); imagedestroy($dst);
        return (bool)$ok;
    }

    private function resizeWithGdWebp(string $src, string $dest, int $targetW, int $quality): bool
    {
        // GD WebP generation
        $info = @getimagesize($src);
        if (!$info) return false;
        [$w, $h] = $info;
        $ratio = $h > 0 ? $w / $h : 1;
        $newW = $targetW; $newH = (int)round($targetW / $ratio);
        $srcImg = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png' => @imagecreatefrompng($src),
            default => null,
        };
        if (!$srcImg) return false;
        $dst = imagecreatetruecolor($newW, $newH);
        
        // Preserve transparency for PNG sources
        if ($info['mime'] === 'image/png') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        
        imagecopyresampled($dst, $srcImg, 0,0,0,0, $newW,$newH, $w,$h);
        @mkdir(dirname($dest), 0775, true);
        $ok = imagewebp($dst, $dest, $quality);
        imagedestroy($srcImg); imagedestroy($dst);
        return (bool)$ok;
    }
    
    /**
     * Generate variants for an image that was uploaded in fast mode
     * Returns array with statistics: ['generated' => int, 'failed' => int, 'skipped' => int]
     * @param bool $force Force regeneration of existing variants
     * @param string|null $onlyVariant Restrict generation to a single breakpoint (e.g. 'md').
     *                                 Used by MediaController's on-demand path so a request
     *                                 never pays for the full 5-sizes × 3-formats matrix.
     * @param string|null $onlyFormat  Restrict generation to a single format ('jpg'|'webp'|'avif').
     *                                 Upload/cron callers omit both and keep full generation.
     */
    public function generateVariantsForImage(int $imageId, bool $force = false, ?string $onlyVariant = null, ?string $onlyFormat = null): array
    {
        $pdo = $this->db->pdo();

        // Get image details
        $stmt = $pdo->prepare(
            'SELECT i.*, a.is_nsfw AS album_is_nsfw, a.password_hash AS album_password_hash
             FROM images i
             JOIN albums a ON a.id = i.album_id
             WHERE i.id = ?'
        );
        $stmt->execute([$imageId]);
        $image = $stmt->fetch();

        if (!$image) {
            throw new RuntimeException("Image {$imageId} not found");
        }

        // Try multiple possible locations for the source file
        $dbPath = $image['original_path'];
        $possiblePaths = [
            dirname(__DIR__, 2) . $dbPath,           // /media/originals/...
            dirname(__DIR__, 2) . '/public' . $dbPath, // /public/media/originals/...
            dirname(__DIR__, 2) . '/storage/originals/' . basename($dbPath), // /storage/originals/...
        ];

        $originalPath = null;
        foreach ($possiblePaths as $path) {
            if (is_file($path)) {
                $originalPath = $path;
                break;
            }
        }

        if (!$originalPath) {
            throw new RuntimeException("Original file not found. Tried: " . implode(', ', $possiblePaths));
        }

        $existingStmt = $pdo->prepare('SELECT variant, format, path FROM image_variants WHERE image_id = ?');
        $existingStmt->execute([$imageId]);
        $existingVariants = [];
        foreach ($existingStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $key = (string)$row['variant'] . '|' . (string)$row['format'];
            $existingVariants[$key] = (string)($row['path'] ?? '');
        }

        // Get settings
        $settings = new \App\Services\SettingsService($this->db);
        $defaults = $settings->defaults();

        $formats = $settings->get('image.formats', $defaults['image.formats']);
        if (!is_array($formats) || !$formats) {
            $formats = $defaults['image.formats'];
        }
        $quality = $settings->get('image.quality', $defaults['image.quality']);
        if (!is_array($quality) || !$quality) {
            $quality = $defaults['image.quality'];
        }
        $breakpoints = $settings->get('image.breakpoints', $defaults['image.breakpoints']);
        if (!is_array($breakpoints) || !$breakpoints) {
            $breakpoints = $defaults['image.breakpoints'];
        }

        $isProtectedAlbum = (int)($image['album_is_nsfw'] ?? 0) === 1 || !empty($image['album_password_hash']);
        $protectedStorage = new ProtectedMediaStorage($this->db);
        $mediaDir = $protectedStorage->directoryForProtection($isProtectedAlbum);
        ImagesService::ensureDir($mediaDir);

        $haveImagick = class_exists(\Imagick::class) && !$this->imagickDisabled();
        $stats = ['generated' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($breakpoints as $variant => $targetW) {
            if ($onlyVariant !== null && (string)$variant !== $onlyVariant) {
                continue;
            }
            $targetW = max(1, (int)$targetW);
            foreach (['avif','webp','jpg'] as $fmt) {
                if ($onlyFormat !== null && $fmt !== $onlyFormat) {
                    continue;
                }
                $enabled = $formats[$fmt] ?? false;
                if (is_string($enabled)) {
                    $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
                }
                if (!$enabled) {
                    continue;
                }

                $destRelUrl = "/media/{$imageId}_{$variant}.{$fmt}";
                $destPath = $mediaDir . "/{$imageId}_{$variant}.{$fmt}";
                $key = (string)$variant . '|' . (string)$fmt;

                // Check if variant already exists in DB
                $existsInDb = isset($existingVariants[$key]);

                // Skip regeneration only if:
                // 1. force is false AND
                // 2. DB record exists AND
                // 3. file exists on disk
                if (!$force && $existsInDb && is_file($destPath)) {
                    $stats['skipped']++;
                    continue;
                }

                // If file exists but NOT in DB (orphan file), delete it first
                if (is_file($destPath) && !$existsInDb) {
                    self::safeUnlink($destPath);
                }

                @mkdir(dirname($destPath), 0775, true);
                $ok = false;

                // Generate based on format
                if ($fmt === 'jpg') {
                    $ok = $this->resizeWithImagickOrGd($originalPath, $destPath, $targetW, 'jpeg', (int)($quality['jpg'] ?? 85));
                } elseif ($fmt === 'webp') {
                    if ($haveImagick) {
                        $ok = $this->resizeWithImagick($originalPath, $destPath, $targetW, 'webp', (int)($quality['webp'] ?? 75));
                    } else {
                        if (function_exists('imagewebp')) {
                            $ok = $this->resizeWithGdWebp($originalPath, $destPath, $targetW, (int)($quality['webp'] ?? 75));
                        }
                    }
                } elseif ($haveImagick) {
                    // Remaining format: 'avif' (narrowed by the if/elseif chain
                    // above). Only Imagick can produce AVIF in this codebase.
                    $ok = $this->resizeWithImagick($originalPath, $destPath, $targetW, 'avif', (int)($quality['avif'] ?? 50));
                }

                if ($ok && is_file($destPath)) {
                    $oppositeDir = $protectedStorage->directoryForProtection(!$isProtectedAlbum);
                    @unlink($oppositeDir . "/{$imageId}_{$variant}.{$fmt}");
                    $size = (int)filesize($destPath);
                    [$vw, $vh] = getimagesize($destPath) ?: [$targetW, 0];
                    $replaceKeyword = $this->db->replaceKeyword();
                    $pdo->prepare(sprintf('%s INTO image_variants(image_id, variant, format, path, width, height, size_bytes) VALUES(?,?,?,?,?,?,?)', $replaceKeyword))
                        ->execute([$imageId, (string)$variant, (string)$fmt, $destRelUrl, (int)$vw, (int)$vh, $size]);
                    $stats['generated']++;
                } else {
                    $stats['failed']++;
                    Logger::warning("UploadService: Failed to generate variant", [
                        'format' => $fmt,
                        'variant' => $variant,
                        'image_id' => $imageId
                    ], 'upload');
                }
            }
        }

        return $stats;
    }

    /**
     * Get human-readable upload error message
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        return match($errorCode) {
            UPLOAD_ERR_OK => 'No error',
            UPLOAD_ERR_INI_SIZE => 'File too large (exceeds upload_max_filesize)',
            UPLOAD_ERR_FORM_SIZE => 'File too large (exceeds MAX_FILE_SIZE)',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
            default => 'Unknown upload error (' . $errorCode . ')'
        };
    }

    /**
     * Imagick is opt-out via CIMAISE_DISABLE_IMAGICK=1 — set this when running
     * under a php-fpm whose Imagick build segfaults on real upload payloads
     * (macOS / Apple Silicon + ImageMagick 7.1.x is the known offender). GD is
     * always used as the fallback.
     */
    private function imagickDisabled(): bool
    {
        return $this->envFlag('CIMAISE_DISABLE_IMAGICK', false);
    }

    /**
     * Helper to parse boolean flags from environment with sane defaults.
     */
    private function envFlag(string $key, bool $default = false): bool
    {
        $fallback = $default ? 'true' : 'false';
        $raw = function_exists('envv') ? envv($key, $fallback) : ($_ENV[$key] ?? $fallback);
        if (is_bool($raw)) {
            return $raw;
        }
        return filter_var((string)$raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Generate a heavily blurred variant of an image for NSFW protection
     * This creates a server-side blur that cannot be bypassed with CSS tricks
     */
    public function generateBlurredVariant(int $imageId, bool $force = false): ?string
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare('SELECT * FROM images WHERE id = ?');
        $stmt->execute([$imageId]);
        $image = $stmt->fetch();

        if (!$image) {
            return null;
        }

        // Find source file - prefer sm variant, fallback to original
        $mediaDir = dirname(__DIR__, 2) . '/public/media';
        $protectedStorage = new ProtectedMediaStorage($this->db);
        $smPath = $mediaDir . "/{$imageId}_sm.jpg";
        $privateSmPath = $protectedStorage->directoryForProtection(true) . "/{$imageId}_sm.jpg";

        $sourcePath = null;
        $triedPaths = [$smPath, $privateSmPath];
        if (is_file($smPath)) {
            $sourcePath = $smPath;
        } elseif (is_file($privateSmPath)) {
            $sourcePath = $privateSmPath;
        } else {
            // Try to find original
            $dbPath = $image['original_path'];
            $possiblePaths = [
                dirname(__DIR__, 2) . $dbPath,
                dirname(__DIR__, 2) . '/public' . $dbPath,
                dirname(__DIR__, 2) . '/storage/originals/' . basename($dbPath),
            ];
            $triedPaths = array_merge($triedPaths, $possiblePaths);
            foreach ($possiblePaths as $path) {
                if (is_file($path)) {
                    $sourcePath = $path;
                    break;
                }
            }
        }

        if (!$sourcePath) {
            Logger::warning('UploadService: Source file not found for blur generation', [
                'image_id' => $imageId,
                'tried_paths' => $triedPaths
            ], 'upload');
            return null;
        }

        $destPath = $mediaDir . "/{$imageId}_blur.jpg";
        $destRelUrl = "/media/{$imageId}_blur.jpg";

        // Skip if exists and not forcing
        if (is_file($destPath) && !$force) {
            return $destRelUrl;
        }

        ImagesService::ensureDir($mediaDir);

        // Generate blurred image
        $ok = false;
        if (class_exists(\Imagick::class) && !$this->imagickDisabled()) {
            $ok = $this->generateBlurWithImagick($sourcePath, $destPath);
        } else {
            $ok = $this->generateBlurWithGd($sourcePath, $destPath);
        }

        if ($ok && is_file($destPath)) {
            [$w, $h] = getimagesize($destPath) ?: [0, 0];
            $size = (int)filesize($destPath);

            // Store as blur variant
            $replaceKeyword = $this->db->replaceKeyword();
            $pdo->prepare(sprintf('%s INTO image_variants(image_id, variant, format, path, width, height, size_bytes) VALUES(?,?,?,?,?,?,?)', $replaceKeyword))
                ->execute([$imageId, 'blur', 'jpg', $destRelUrl, $w, $h, $size]);

            return $destRelUrl;
        }

        return null;
    }

    /**
     * Ensure a generic blur placeholder exists for protected media fallbacks.
     *
     * Returns the public URL path on success, or null if GD is unavailable
     * or the placeholder could not be allocated/written. The installer requires
     * ext-gd as a core extension, but this runtime guard prevents fatal errors
     * in misconfigured environments (e.g. Imagick-only PHP builds).
     */
    public function ensureBlurPlaceholder(): ?string
    {
        $mediaDir = dirname(__DIR__, 2) . '/public/media';
        ImagesService::ensureDir($mediaDir);

        $placeholderPath = $mediaDir . '/blur-placeholder.jpg';
        if (!is_file($placeholderPath)) {
            // Runtime GD guard: ensure the extension and required functions are loaded.
            // If GD is missing, gracefully return null so callers can degrade (CR-12).
            if (!extension_loaded('gd')
                || !function_exists('imagecreatetruecolor')
                || !function_exists('imagecolorallocate')
                || !function_exists('imagefilledrectangle')
                || !function_exists('imagejpeg')
                || !function_exists('imagedestroy')
            ) {
                Logger::warning('UploadService: GD extension unavailable, cannot create blur placeholder', [
                    'path' => $placeholderPath,
                ], 'upload');
                return null;
            }

            $image = imagecreatetruecolor(8, 8);
            if ($image === false) {
                Logger::warning('UploadService: Failed to allocate blur placeholder image', [], 'upload');
                return null;
            }

            $color = imagecolorallocate($image, 120, 120, 120);
            if ($color !== false) {
                imagefilledrectangle($image, 0, 0, 7, 7, $color);
            }

            $saved = imagejpeg($image, $placeholderPath, 60);
            imagedestroy($image);

            if (!$saved) {
                Logger::warning('UploadService: Failed to write blur placeholder image', [
                    'path' => $placeholderPath,
                ], 'upload');
                return null;
            }
        }

        return '/media/blur-placeholder.jpg';
    }

    /**
     * Generate blur using ImageMagick (high quality)
     */
    private function generateBlurWithImagick(string $src, string $dest): bool
    {
        try {
            self::applyImagickLimits();
            $im = new \Imagick($src);

            // Resize to small size first for performance
            $im->thumbnailImage(400, 0);

            // Apply heavy Gaussian blur (radius=0 means auto, sigma=30 is very blurry)
            $im->gaussianBlurImage(0, 30);

            // Reduce quality and colors to make it harder to reverse
            $im->setImageCompressionQuality(60);
            // Disable dithering (posterizeImage expects bool, not DitherMethod constant)
            $im->posterizeImage(64, false);

            // Apply slight pixelation for extra obscuring
            $origW = $im->getImageWidth();
            $origH = $im->getImageHeight();
            $im->scaleImage(40, 0);
            $im->scaleImage($origW, $origH);

            // Final blur pass
            $im->gaussianBlurImage(0, 15);

            // Strip EXIF/metadata for privacy protection
            if ($this->envFlag('STRIP_EXIF', true)) {
                $im->stripImage();
            }

            $im->setImageFormat('jpeg');
            $ok = $im->writeImage($dest);
            $im->clear();

            return (bool)$ok;
        } catch (\Throwable $e) {
            Logger::warning('UploadService: Imagick blur failed', ['error' => $e->getMessage()], 'upload');
            return false;
        }
    }

    /**
     * Generate blur using GD (fallback)
     */
    private function generateBlurWithGd(string $src, string $dest): bool
    {
        $info = @getimagesize($src);
        if (!$info) {
            return false;
        }

        [$w, $h] = $info;
        $srcImg = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png' => @imagecreatefrompng($src),
            'image/webp' => @imagecreatefromwebp($src),
            default => null,
        };

        if (!$srcImg) {
            return false;
        }

        // Resize to small for processing
        $smallW = min(400, $w);
        $smallH = (int)round($smallW * ($h / $w));
        $small = imagecreatetruecolor($smallW, $smallH);
        imagecopyresampled($small, $srcImg, 0, 0, 0, 0, $smallW, $smallH, $w, $h);
        imagedestroy($srcImg);

        // Apply pixelation by scaling down and up
        $pixelSize = 8;
        $pixelW = (int)ceil($smallW / $pixelSize);
        $pixelH = (int)ceil($smallH / $pixelSize);

        $pixelated = imagecreatetruecolor($pixelW, $pixelH);
        imagecopyresampled($pixelated, $small, 0, 0, 0, 0, $pixelW, $pixelH, $smallW, $smallH);

        $blurred = imagecreatetruecolor($smallW, $smallH);
        imagecopyresampled($blurred, $pixelated, 0, 0, 0, 0, $smallW, $smallH, $pixelW, $pixelH);

        imagedestroy($small);
        imagedestroy($pixelated);

        // Apply multiple Gaussian blur passes
        for ($i = 0; $i < 20; $i++) {
            imagefilter($blurred, IMG_FILTER_GAUSSIAN_BLUR);
        }

        // Reduce colors
        imagefilter($blurred, IMG_FILTER_SMOOTH, 10);

        $ok = imagejpeg($blurred, $dest, 60);
        imagedestroy($blurred);

        return (bool)$ok;
    }

    /**
     * Generate blurred variants for all images in an album
     */
    public function generateBlurredVariantsForAlbum(int $albumId, bool $force = false): array
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare('SELECT id FROM images WHERE album_id = ?');
        $stmt->execute([$albumId]);
        $images = $stmt->fetchAll();

        $stats = ['generated' => 0, 'failed' => 0, 'skipped' => 0];

        $mediaDir = dirname(__DIR__, 2) . '/public/media';

        foreach ($images as $image) {
            // Check if blur file existed BEFORE generation to track stats correctly
            $existedBefore = is_file($mediaDir . "/{$image['id']}_blur.jpg");

            $blurPath = $this->generateBlurredVariant((int)$image['id'], $force);
            if ($blurPath !== null) {
                if (!$force && $existedBefore) {
                    $stats['skipped']++;
                } else {
                    $stats['generated']++;
                }
            } else {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * Backfill blur variants for all password-protected and NSFW albums that lack them.
     *
     * Walks every image belonging to a password-protected or NSFW (published) album and,
     * if no `blur` variant row exists in image_variants, dispatches generateBlurredVariant
     * to create one. Idempotent: existing blur variants are skipped (unless $force=true).
     *
     * Intended to be wired to a CLI command (e.g. `bin/console images:backfill-blur`) or
     * an admin-only maintenance route. Not exposed via routing here — see TODO below.
     *
     * @param bool $force Regenerate blur variants even if they already exist
     * @return int Number of blur variants actually generated by this call
     */
    public function backfillBlurForProtectedAlbums(bool $force = false): int
    {
        // TODO: wire this method to a CLI command (e.g. bin/console images:backfill-blur)
        // and/or an admin maintenance route so operators can run it on demand after upgrade.
        $pdo = $this->db->pdo();

        // When $force is true, process every image in protected/NSFW albums so that
        // existing blur variants can be rebuilt. The non-force path keeps the original
        // `iv.id IS NULL` filter for an idempotent backfill (CR-11).
        $sql = $force
            ? "
                SELECT i.id AS image_id
                FROM images i
                JOIN albums a ON a.id = i.album_id
                WHERE a.is_published = 1
                  AND ((a.password_hash IS NOT NULL AND a.password_hash <> '') OR a.is_nsfw = 1)
            "
            : "
                SELECT i.id AS image_id
                FROM images i
                JOIN albums a ON a.id = i.album_id
                LEFT JOIN image_variants iv
                  ON iv.image_id = i.id AND iv.variant = 'blur'
                WHERE a.is_published = 1
                  AND ((a.password_hash IS NOT NULL AND a.password_hash <> '') OR a.is_nsfw = 1)
                  AND iv.id IS NULL
            ";
        $stmt = $pdo->query($sql);

        if ($stmt === false) {
            Logger::warning('UploadService: backfillBlurForProtectedAlbums query failed', [], 'upload');
            return 0;
        }

        $generated = 0;
        foreach ($stmt as $row) {
            $imageId = (int)($row['image_id'] ?? 0);
            if ($imageId <= 0) {
                continue;
            }

            try {
                $result = $this->generateBlurredVariant($imageId, $force);
                if ($result !== null) {
                    $generated++;
                }
            } catch (\Throwable $e) {
                Logger::warning('UploadService: backfill blur generation failed', [
                    'image_id' => $imageId,
                    'error' => $e->getMessage(),
                ], 'upload');
            }
        }

        return $generated;
    }

    /**
     * Delete blurred variants for all images in an album (when removing NSFW flag)
     */
    public function deleteBlurredVariantsForAlbum(int $albumId): int
    {
        $pdo = $this->db->pdo();
        $mediaDir = dirname(__DIR__, 2) . '/public/media';

        $stmt = $pdo->prepare('SELECT id FROM images WHERE album_id = ?');
        $stmt->execute([$albumId]);
        $images = $stmt->fetchAll();

        $deleted = 0;
        foreach ($images as $image) {
            $blurPath = $mediaDir . "/{$image['id']}_blur.jpg";
            $fileExisted = is_file($blurPath);

            if ($fileExisted) {
                self::safeUnlink($blurPath);

                // Remove from DB only if blur variant existed
                $pdo->prepare('DELETE FROM image_variants WHERE image_id = ? AND variant = ?')
                    ->execute([$image['id'], 'blur']);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Generate LQIP (Low Quality Image Placeholder) for instant perceived loading
     * SECURITY: Only for public albums (no password, no NSFW)
     * PERFORMANCE: Tiny 40x30px with light blur (1-2KB for inline base64)
     *
     * @param int $imageId Image ID
     * @param bool $force Force regeneration even if exists
     * @return string|null Relative URL to LQIP or null on failure
     */
    public function generateLQIP(int $imageId, bool $force = false): ?string
    {
        $pdo = $this->db->pdo();

        // SECURITY: Check if image belongs to protected album
        $stmt = $pdo->prepare('
            SELECT a.password_hash, a.is_nsfw
            FROM images i
            JOIN albums a ON i.album_id = a.id
            WHERE i.id = ?
        ');
        $stmt->execute([$imageId]);
        $album = $stmt->fetch();

        if (!$album) {
            Logger::warning('UploadService: Image not found for LQIP generation', [
                'image_id' => $imageId
            ], 'upload');
            return null;
        }

        // SECURITY: Skip LQIP for protected albums (password or NSFW)
        // Protected albums use blur variant for privacy, not LQIP for performance
        if (!empty($album['password_hash']) || !empty($album['is_nsfw'])) {
            Logger::debug('UploadService: Skipping LQIP for protected album', [
                'image_id' => $imageId,
                'has_password' => !empty($album['password_hash']),
                'is_nsfw' => !empty($album['is_nsfw'])
            ], 'upload');
            return null;
        }

        // Find source file (prefer md variant for speed, fallback to original)
        $variantStmt = $pdo->prepare('SELECT path FROM image_variants WHERE image_id = ? AND variant = ? LIMIT 1');
        $variantStmt->execute([$imageId, 'md']);
        $mdPath = $variantStmt->fetchColumn();

        $sourcePath = null;
        $triedPaths = [];
        $root = dirname(__DIR__, 2);

        if ($mdPath) {
            $tryPath = $root . '/public/' . ltrim($mdPath, '/');
            $triedPaths[] = $tryPath;
            if (is_file($tryPath)) {
                $sourcePath = $tryPath;
            }
        }

        if (!$sourcePath) {
            $imgStmt = $pdo->prepare('SELECT original_path FROM images WHERE id = ?');
            $imgStmt->execute([$imageId]);
            $origPath = $imgStmt->fetchColumn();

            if ($origPath) {
                if (str_starts_with($origPath, '/storage/originals/')) {
                    $tryPath = $root . $origPath;
                    $triedPaths[] = $tryPath;
                    if (is_file($tryPath)) {
                        $sourcePath = $tryPath;
                    }
                }
            }
        }

        if (!$sourcePath) {
            Logger::warning('UploadService: Source file not found for LQIP generation', [
                'image_id' => $imageId,
                'tried_paths' => $triedPaths
            ], 'upload');
            return null;
        }

        $mediaDir = $root . '/public/media';
        $destPath = $mediaDir . "/{$imageId}_lqip.jpg";
        $destRelUrl = "/media/{$imageId}_lqip.jpg";

        // Ensure media directory exists before creating lock file
        try {
            ImagesService::ensureDir($mediaDir);
        } catch (\Throwable $e) {
            Logger::error('UploadService: Failed to create media directory for LQIP', [
                'image_id' => $imageId,
                'media_dir' => $mediaDir,
                'error' => $e->getMessage()
            ], 'upload');
            return null;
        }

        // PERFORMANCE: Use file locking to prevent race condition when multiple processes
        // try to generate LQIP for the same image simultaneously
        $lockFile = $destPath . '.lock';
        $lockHandle = fopen($lockFile, 'c+');

        if (!$lockHandle) {
            Logger::warning('UploadService: Failed to create lock file for LQIP', [
                'image_id' => $imageId,
                'lock_file' => $lockFile
            ], 'upload');
            return null;
        }

        // Try to acquire exclusive lock (non-blocking)
        if (flock($lockHandle, LOCK_EX | LOCK_NB)) {
            try {
                // Check again after acquiring lock (another process may have generated it)
                if (is_file($destPath) && !$force) {
                    return $destRelUrl;
                }

                // Generate LQIP (tiny with light blur)
                $ok = false;
                if (class_exists(\Imagick::class) && !$this->imagickDisabled()) {
                    $ok = $this->generateLQIPWithImagick($sourcePath, $destPath);
                } else {
                    $ok = $this->generateLQIPWithGd($sourcePath, $destPath);
                }

                if ($ok && is_file($destPath)) {
                    [$w, $h] = getimagesize($destPath) ?: [0, 0];
                    $size = (int)filesize($destPath);

                    // Store as lqip variant
                    $replaceKeyword = $this->db->replaceKeyword();
                    $pdo->prepare(sprintf('%s INTO image_variants(image_id, variant, format, path, width, height, size_bytes) VALUES(?,?,?,?,?,?,?)', $replaceKeyword))
                        ->execute([$imageId, 'lqip', 'jpg', $destRelUrl, $w, $h, $size]);

                    Logger::debug('UploadService: LQIP generated successfully', [
                        'image_id' => $imageId,
                        'size_bytes' => $size,
                        'dimensions' => "{$w}x{$h}"
                    ], 'upload');

                    return $destRelUrl;
                }

                Logger::warning('UploadService: LQIP generation failed', [
                    'image_id' => $imageId
                ], 'upload');

                return null;
            } finally {
                // Always release lock and cleanup
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
                self::safeUnlink($lockFile);
            }
        } else {
            // Another process is generating LQIP, wait briefly and return existing file
            fclose($lockHandle);
            usleep(100000); // Wait 100ms for other process to complete

            if (is_file($destPath)) {
                Logger::debug('UploadService: LQIP already being generated by another process', [
                    'image_id' => $imageId
                ], 'upload');
                return $destRelUrl;
            }

            Logger::warning('UploadService: LQIP generation locked by another process', [
                'image_id' => $imageId
            ], 'upload');
            return null;
        }
    }

    /**
     * Generate LQIP using ImageMagick (high quality)
     * Creates tiny 40x30px with light artistic blur
     */
    private function generateLQIPWithImagick(string $src, string $dest): bool
    {
        try {
            self::applyImagickLimits();
            $im = new \Imagick($src);

            // Resize to tiny dimensions (40x30px target)
            $im->thumbnailImage(40, 30, true);

            // Apply light Gaussian blur for artistic effect (sigma=3)
            $im->gaussianBlurImage(0, 3);

            // High compression for minimum file size (target: 1-2KB)
            $im->setImageCompressionQuality(75);

            // Strip EXIF/metadata to reduce size
            if ($this->envFlag('STRIP_EXIF', true)) {
                $im->stripImage();
            }

            $im->setImageFormat('jpeg');
            $ok = $im->writeImage($dest);
            $im->clear();

            return (bool)$ok;
        } catch (\Throwable $e) {
            Logger::warning('UploadService: Imagick LQIP failed', ['error' => $e->getMessage()], 'upload');
            return false;
        }
    }

    /**
     * Generate LQIP using GD (fallback)
     * Creates tiny 40x30px with light blur
     */
    private function generateLQIPWithGd(string $src, string $dest): bool
    {
        $info = @getimagesize($src);
        if (!$info) {
            return false;
        }

        [$w, $h] = $info;
        $srcImg = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png' => @imagecreatefrompng($src),
            'image/webp' => @imagecreatefromwebp($src),
            default => null,
        };

        if (!$srcImg) {
            return false;
        }

        // Validate dimensions (prevent division by zero)
        if ($w <= 0 || $h <= 0) {
            imagedestroy($srcImg);
            return false;
        }

        // Calculate dimensions maintaining aspect ratio (max 40x30)
        $maxW = 40;
        $maxH = 30;
        $ratio = $w / $h;

        if ($ratio > $maxW / $maxH) {
            // Width is limiting factor
            $targetW = $maxW;
            $targetH = (int)round($maxW / $ratio);
        } else {
            // Height is limiting factor
            $targetH = $maxH;
            $targetW = (int)round($maxH * $ratio);
        }

        // Create tiny image
        $tiny = imagecreatetruecolor($targetW, $targetH);
        imagecopyresampled($tiny, $srcImg, 0, 0, 0, 0, $targetW, $targetH, $w, $h);
        imagedestroy($srcImg);

        // Apply light Gaussian blur (3 passes for aesthetic effect)
        for ($i = 0; $i < 3; $i++) {
            imagefilter($tiny, IMG_FILTER_GAUSSIAN_BLUR);
        }

        // Light smoothing
        imagefilter($tiny, IMG_FILTER_SMOOTH, 5);

        // Save with high compression (quality 75 = ~1-2KB)
        $ok = imagejpeg($tiny, $dest, 75);
        imagedestroy($tiny);

        return (bool)$ok;
    }
}
