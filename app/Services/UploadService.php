<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use finfo;
use RuntimeException;

class UploadService
{
    private array $allowed = ['image/jpeg'=>'.jpg','image/png'=>'.png'];

    public function __construct(private Database $db)
    {
    }

    public function ingestAlbumUpload(int $albumId, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload error');
        }
        $tmp = $file['tmp_name'];
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = $fi->file($tmp) ?: '';
        if (!isset($this->allowed[$mime])) {
            throw new RuntimeException('MIME non consentito');
        }
        $hash = sha1_file($tmp) ?: bin2hex(random_bytes(20));
        $ext = $this->allowed[$mime];
        $storageDir = dirname(__DIR__, 2) . '/storage/originals';
        ImagesService::ensureDir($storageDir);
        $dest = $storageDir . '/' . $hash . $ext;
        if (!@move_uploaded_file($tmp, $dest)) {
            // Fallback for CLI env
            @rename($tmp, $dest);
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

        // Minimal preview variant
        $mediaDir = dirname(__DIR__, 2) . '/public/media';
        ImagesService::ensureDir($mediaDir);
        // get preview width from settings
        $settings = new \App\Services\SettingsService($this->db);
        $previewW = (int)($settings->get('image.preview')['width'] ?? 480);
        $previewPath = $mediaDir . '/' . $imageId . '_sm.jpg';
        $preview = ImagesService::generateJpegPreview($dest, $previewPath, $previewW);
        if ($preview) {
            $relFs = str_replace(dirname(__DIR__, 2), '', $preview); // e.g. /public/media/1_sm.jpg
            $relUrl = preg_replace('#^/public#','', $relFs); // e.g. /media/1_sm.jpg
            $pdo->prepare('INSERT INTO image_variants(image_id, variant, format, path, width, height, size_bytes) VALUES(?,?,?,?,?,?,?)')
                ->execute([$imageId,'sm','jpg',$relUrl,$previewW,0, (int)filesize($preview)]);
            $previewRel = $relUrl;
        } else {
            $previewRel = null;
        }

        return ['id'=>$imageId,'path'=>$dest,'mime'=>$mime,'width'=>$width,'height'=>$height,'preview_url'=>$previewRel];
    }
}
