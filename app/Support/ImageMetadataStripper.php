<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Re-encodes a small, directly served image (site logo, favicon source,
 * category cover, about photo) without its metadata.
 *
 * Those files are written straight into public/ and served as uploaded, so
 * EXIF (GPS position, camera serial, timestamps), XMP and IPTC would reach
 * every visitor. Album photos already go through UploadService, which strips
 * on its own; this covers the side uploads that bypass it.
 *
 * The pixel cap is checked from the header BEFORE any decode, so a
 * decompression bomb is rejected without being expanded in memory.
 */
final class ImageMetadataStripper
{
    /** Same ceiling as UploadService::MAX_IMAGE_PIXELS (40 MP). */
    public const MAX_PIXELS = 40000000;

    /** True when the header dimensions are sane and within the pixel cap. */
    public static function withinPixelCap(string $path): bool
    {
        $info = @getimagesize($path);
        if ($info === false) {
            return false;
        }
        [$w, $h] = $info;
        return $w > 0 && $h > 0 && $w * $h <= self::MAX_PIXELS;
    }

    /**
     * Strip metadata in place. Returns false when the file is not a supported
     * image, exceeds the pixel cap or cannot be re-encoded; callers must then
     * discard the upload rather than publish it with its metadata.
     */
    public static function stripInPlace(string $path): bool
    {
        if (!is_file($path) || !self::withinPixelCap($path)) {
            return false;
        }
        $mime = (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '');
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return false;
        }

        if (extension_loaded('imagick')) {
            try {
                return self::stripWithImagick($path);
            } catch (\Throwable $e) {
                Logger::warning('Imagick metadata strip failed, falling back to GD', [
                    'error' => $e->getMessage(),
                ], 'upload');
            }
        }
        return self::stripWithGd($path, $mime);
    }

    private static function stripWithImagick(string $path): bool
    {
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_AREA, self::MAX_PIXELS);
        $im = new \Imagick($path);
        try {
            $im->autoOrient();
            // Keep the colour profile (it changes how the image looks), drop
            // everything else (EXIF incl. GPS, XMP, IPTC, comments).
            $icc = null;
            try {
                $icc = $im->getImageProfile('icc');
            } catch (\ImagickException) {
                $icc = null;
            }
            $im->stripImage();
            if (is_string($icc) && $icc !== '') {
                $im->profileImage('icc', $icc);
            }
            return self::atomicWrite($path, $im->getImagesBlob());
        } finally {
            $im->clear();
        }
    }

    private static function stripWithGd(string $path, string $mime): bool
    {
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            default => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        };
        if ($img === false) {
            return false;
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            // GD ignores the orientation tag; apply it before the tag is dropped.
            $exif = @exif_read_data($path);
            $oriented = self::applyOrientation($img, (int) ($exif['Orientation'] ?? 1));
            if ($oriented === false) {
                imagedestroy($img);
                return false;
            }
            $img = $oriented;
        }
        if ($mime !== 'image/jpeg') {
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }
        ob_start();
        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($img, null, 90),
            'image/png' => imagepng($img, null, 9),
            default => function_exists('imagewebp') && imagewebp($img, null, 90),
        };
        $blob = (string) ob_get_clean();
        imagedestroy($img);
        return $ok && $blob !== '' && self::atomicWrite($path, $blob);
    }

    /**
     * Apply an EXIF Orientation value (1-8) to the pixels, including the
     * mirrored ones (2, 4, 5, 7). Rotations are clockwise as the tag defines
     * them; imagerotate() turns counter-clockwise, hence the negative angles.
     *
     * @return \GdImage|false the oriented image (the input may be destroyed)
     */
    public static function applyOrientation(\GdImage $img, int $orientation): \GdImage|false
    {
        $flip = in_array($orientation, [2, 4, 5, 7], true);
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $rotated = imagerotate($img, $angle, 0);
            if ($rotated === false) {
                return false;
            }
            imagedestroy($img);
            $img = $rotated;
        }
        if ($flip && !imageflip($img, IMG_FLIP_HORIZONTAL)) {
            return false;
        }
        return $img;
    }

    private static function atomicWrite(string $path, string $blob): bool
    {
        $tmp = $path . '.strip-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $blob) === false) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }
}
