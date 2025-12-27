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
        if ($width <= 0 || $height <= 0 || $width > 50000 || $height > 50000) {
            throw new RuntimeException('Invalid image dimensions');
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
                throw new RuntimeException('Failed to move uploaded file');
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
            @unlink($dest);
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

        // Insert DB record
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('INSERT INTO images(album_id, original_path, file_hash, width, height, mime, alt_text, caption, exif, camera_id, lens_id, iso, shutter_speed, aperture, sort_order) 
                               VALUES(:a,:p,:h,:w,:h2,:m, NULL, NULL, :exif, :cam, :lens, :iso, :sh, :ap, :s)');
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
            ':iso'=> isset($exif['ISOSpeedRatings']) ? (int)$exif['ISOSpeedRatings'] : null,
            ':sh'=> $exifSvc->formatShutterSpeed($exif['ExposureTime'] ?? null),
            ':ap'=> $exif['FNumber'] ?? null,
            ':s'=>0,
        ]);
        $imageId = (int)$pdo->lastInsertId();

        // Generate preview and full variants set
        $mediaDir = dirname(__DIR__, 2) . '/public/media';
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
            $relFs = str_replace(dirname(__DIR__, 2), '', $preview);
            $relUrl = preg_replace('#^/public#','', $relFs);
            $previewSize = @getimagesize($preview) ?: [$previewW, 0];
            $replaceKeyword = $this->db->replaceKeyword();
            $pdo->prepare(sprintf('%s INTO image_variants(image_id, variant, format, path, width, height, size_bytes) VALUES(?,?,?,?,?,?,?)', $replaceKeyword))
                ->execute([$imageId,'sm','jpg',$relUrl,$previewW,(int)($previewSize[1] ?? 0), (int)filesize($preview)]);
            $previewRel = $relUrl;
        } else {
            $previewRel = null;
        }

        // PERFORMANCE OPTIMIZATION: Async Variant Generation
        // Only preview (sm.jpg) is generated during upload for immediate response.
        // Full variants are generated after the response to keep uploads fast.
        // Set SYNC_VARIANTS_ON_UPLOAD=true to force synchronous generation.
        $variantsAsyncSetting = $settingsSvc->get('image.variants_async', $defaults['image.variants_async'] ?? true);
        if (is_string($variantsAsyncSetting)) {
            $variantsAsyncSetting = filter_var($variantsAsyncSetting, FILTER_VALIDATE_BOOLEAN);
        }
        $variantsAsync = (bool)$variantsAsyncSetting;

        $fastUploadMode = $this->envFlag('FAST_UPLOAD', $variantsAsync);
        $syncVariants = $this->envFlag('SYNC_VARIANTS_ON_UPLOAD', !$variantsAsync);

        // Fetch album settings once (cover + NSFW flag)
        $coverCheck = $pdo->prepare('SELECT cover_image_id, is_nsfw FROM albums WHERE id = :id');
        $coverCheck->execute([':id' => $albumId]);
        $album = $coverCheck->fetch();
        $isAlbumNsfw = !empty($album['is_nsfw']);

        if ($syncVariants || !$fastUploadMode) {
            $this->generateVariantsForImage($imageId, false);
            if ($isAlbumNsfw) {
                $this->generateBlurredVariant($imageId);
            }
        } else {
            // Generate variants after response flush to keep UX snappy but still complete
            // Note: We capture $this->db to check connection validity in shutdown
            $db = $this->db;
            register_shutdown_function(function () use ($imageId, $db, $isAlbumNsfw) {
                try {
                    // Check if DB connection is still valid (may be closed during shutdown)
                    $pdo = $db->pdo();
                    $pdo->query('SELECT 1');

                    // Connection still valid, generate variants
                    $uploadService = new self($db);
                    $uploadService->generateVariantsForImage($imageId, false);
                    if ($isAlbumNsfw) {
                        $uploadService->generateBlurredVariant($imageId);
                    }
                } catch (\PDOException $e) {
                    // DB connection closed - variants will be generated by cron/CLI command
                    Logger::info('UploadService: DB closed during shutdown, skipping async variant generation', [
                        'image_id' => $imageId
                    ], 'upload');
                } catch (\Throwable $e) {
                    Logger::warning('UploadService: async variant generation failed', [
                        'error' => $e->getMessage(),
                        'image_id' => $imageId
                    ], 'upload');
                }
            });
        }

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
        } catch (\Throwable) {
            return false;
        }
    }

    private function resizeWithImagickOrGd(string $src, string $dest, int $targetW, string $format, int $quality): bool
    {
        if (class_exists(\Imagick::class)) {
            return $this->resizeWithImagick($src, $dest, $targetW, $format, $quality);
        }
        // GD fallback JPEG only
        $info = @getimagesize($src);
        if (!$info) return false;
        [$w, $h] = $info;
        $ratio = $h > 0 ? $w / $h : 1;
        $newW = $targetW; $newH = (int)round($targetW / $ratio);
        $srcImg = match ($info['mime'] ?? '') {
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
        $srcImg = match ($info['mime'] ?? '') {
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
     */
    public function generateVariantsForImage(int $imageId, bool $force = false): array
    {
        $pdo = $this->db->pdo();

        // Get image details
        $stmt = $pdo->prepare('SELECT * FROM images WHERE id = ?');
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

        $mediaDir = dirname(__DIR__, 2) . '/public/media';
        ImagesService::ensureDir($mediaDir);

        $haveImagick = class_exists(\Imagick::class);
        $stats = ['generated' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($breakpoints as $variant => $targetW) {
            $targetW = max(1, (int)$targetW);
            foreach (['avif','webp','jpg'] as $fmt) {
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
                    @unlink($destPath);
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
                } elseif ($fmt === 'avif' && $haveImagick) {
                    $ok = $this->resizeWithImagick($originalPath, $destPath, $targetW, 'avif', (int)($quality['avif'] ?? 50));
                }

                if ($ok && is_file($destPath)) {
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
        $smPath = $mediaDir . "/{$imageId}_sm.jpg";

        $sourcePath = null;
        $triedPaths = [$smPath];
        if (is_file($smPath)) {
            $sourcePath = $smPath;
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
        if (class_exists(\Imagick::class)) {
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
     * Generate blur using ImageMagick (high quality)
     */
    private function generateBlurWithImagick(string $src, string $dest): bool
    {
        try {
            $im = new \Imagick($src);

            // Resize to small size first for performance
            $im->thumbnailImage(400, 0);

            // Apply heavy Gaussian blur (radius=0 means auto, sigma=30 is very blurry)
            $im->gaussianBlurImage(0, 30);

            // Reduce quality and colors to make it harder to reverse
            $im->setImageCompressionQuality(60);
            $im->posterizeImage(64, \Imagick::DITHERMETHOD_NO);

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
        $srcImg = match ($info['mime'] ?? '') {
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
                @unlink($blurPath);

                // Remove from DB only if blur variant existed
                $pdo->prepare('DELETE FROM image_variants WHERE image_id = ? AND variant = ?')
                    ->execute([$image['id'], 'blur']);
                $deleted++;
            }
        }

        return $deleted;
    }
}
