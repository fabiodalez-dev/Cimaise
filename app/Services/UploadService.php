<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use finfo;
use RuntimeException;

class UploadService
{
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
            ':sh'=> $exif['ExposureTime'] ?? null,
            ':ap'=> $exif['FNumber'] ?? null,
            ':s'=>0,
        ]);
        $imageId = (int)$pdo->lastInsertId();

        // Generate preview and full variants set
        $mediaDir = dirname(__DIR__, 2) . '/public/media';
        ImagesService::ensureDir($mediaDir);
        $settings = new \App\Services\SettingsService($this->db);
        $formats = (array)$settings->get('image.formats');
        $quality = (array)$settings->get('image.quality');
        $breakpoints = (array)$settings->get('image.breakpoints');

        $previewW = (int)($settings->get('image.preview')['width'] ?? 480);
        $previewPath = $mediaDir . '/' . $imageId . '_sm.jpg';
        $preview = ImagesService::generateJpegPreview($dest, $previewPath, $previewW);
        if ($preview) {
            $relFs = str_replace(dirname(__DIR__, 2), '', $preview);
            $relUrl = preg_replace('#^/public#','', $relFs);
            $pdo->prepare('REPLACE INTO image_variants(image_id, variant, format, path, width, height, size_bytes) VALUES(?,?,?,?,?,?,?)')
                ->execute([$imageId,'sm','jpg',$relUrl,$previewW,0, (int)filesize($preview)]);
            $previewRel = $relUrl;
        } else {
            $previewRel = null;
        }

        // PERFORMANCE OPTIMIZATION: Fast Upload Mode
        // Only preview (sm.jpg) is generated during upload for immediate response
        // Full variants generated in background via: php bin/console images:generate-variants
        // This reduces upload time from ~15s to ~1s per image

        // For backward compatibility, legacy mode (all variants sync) is the default
        // Set FAST_UPLOAD=true in .env to enable fast mode (recommended for large uploads)
        $fastUploadMode = ($_ENV['FAST_UPLOAD'] ?? 'false') === 'true';

        if (!$fastUploadMode) {
            // LEGACY MODE: Generate all variants synchronously (complete but slower)
            $this->generateVariantsForImage($imageId, false);
        }

        // Set as cover if album doesn't have one yet
        $coverCheck = $pdo->prepare('SELECT cover_image_id FROM albums WHERE id = :id');
        $coverCheck->execute([':id' => $albumId]);
        $album = $coverCheck->fetch();
        
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

        // Get settings
        $settings = new \App\Services\SettingsService($this->db);
        $formats = (array)$settings->get('image.formats');
        $quality = (array)$settings->get('image.quality');
        $breakpoints = (array)$settings->get('image.breakpoints');

        $mediaDir = dirname(__DIR__, 2) . '/public/media';
        ImagesService::ensureDir($mediaDir);

        $haveImagick = class_exists(\Imagick::class);
        $stats = ['generated' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($breakpoints as $variant => $targetW) {
            $targetW = (int)$targetW;
            foreach (['avif','webp','jpg'] as $fmt) {
                if (empty($formats[$fmt])) continue;

                $destRelUrl = "/media/{$imageId}_{$variant}.{$fmt}";
                $destPath = $mediaDir . "/{$imageId}_{$variant}.{$fmt}";

                // Skip if variant already exists (unless force is enabled)
                if (is_file($destPath) && !$force) {
                    $stats['skipped']++;
                    continue;
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
                    $pdo->prepare('REPLACE INTO image_variants(image_id, variant, format, path, width, height, size_bytes) VALUES(?,?,?,?,?,?,?)')
                        ->execute([$imageId, (string)$variant, (string)$fmt, $destRelUrl, (int)$vw, (int)$vh, $size]);
                    $stats['generated']++;
                } else {
                    $stats['failed']++;
                    error_log("Failed to generate {$fmt} variant {$variant} for image {$imageId}");
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
}
