<?php
declare(strict_types=1);

namespace App\Services;

/**
 * FaviconService
 * Generates favicon files in multiple sizes and formats from a source image using GD library.
 */
class FaviconService
{
    private string $publicPath;

    public function __construct(string $publicPath)
    {
        $this->publicPath = rtrim($publicPath, '/');
    }

    /**
     * Generate all favicon sizes from source image
     *
     * @param string $sourceImagePath Absolute path to source image (PNG, JPG, WebP)
     * @return array Array of generated favicon paths or error info
     */
    public function generateFavicons(string $sourceImagePath): array
    {
        if (!file_exists($sourceImagePath) || !is_readable($sourceImagePath)) {
            return ['success' => false, 'error' => 'Source image not found or not readable'];
        }

        // Detect image type and create GD resource
        $imageInfo = getimagesize($sourceImagePath);
        if ($imageInfo === false) {
            return ['success' => false, 'error' => 'Invalid image file'];
        }

        $mimeType = $imageInfo['mime'];
        $sourceImage = $this->createImageResource($sourceImagePath, $mimeType);

        if ($sourceImage === null) {
            return ['success' => false, 'error' => 'Unsupported image format. Please use PNG, JPEG, or WebP'];
        }

        // Enable alpha channel for transparency
        imagealphablending($sourceImage, false);
        imagesavealpha($sourceImage, true);

        // Favicon sizes to generate (includes PWA manifest sizes)
        $sizes = [
            'favicon.ico' => 32,      // Standard favicon
            'favicon-16x16.png' => 16,
            'favicon-32x32.png' => 32,
            'favicon-96x96.png' => 96,
            'apple-touch-icon.png' => 180, // Apple touch icon
            'android-chrome-192x192.png' => 192,
            'android-chrome-512x512.png' => 512,
            // PWA manifest sizes
            'icon-72x72.png' => 72,
            'icon-128x128.png' => 128,
            'icon-144x144.png' => 144,
            'icon-152x152.png' => 152,
            'icon-384x384.png' => 384,
        ];

        $generated = [];
        $errors = [];

        foreach ($sizes as $filename => $size) {
            $destPath = $this->publicPath . '/' . $filename;

            // Create resized image
            $resized = imagecreatetruecolor($size, $size);
            if ($resized === false) {
                $errors[] = "Failed to create image resource for {$filename}";
                continue;
            }

            // Preserve transparency
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            if ($transparent !== false) {
                imagefill($resized, 0, 0, $transparent);
            }
            imagealphablending($resized, true);

            // Resize with high quality
            $success = imagecopyresampled(
                $resized,
                $sourceImage,
                0, 0, 0, 0,
                $size, $size,
                imagesx($sourceImage),
                imagesy($sourceImage)
            );

            if (!$success) {
                imagedestroy($resized);
                $errors[] = "Failed to resize image for {$filename}";
                continue;
            }

            // Save as PNG or ICO
            if (str_ends_with($filename, '.ico')) {
                // Save as PNG first, then rename (GD doesn't support ICO directly)
                // For production, consider using a proper ICO library or keep as PNG
                $pngPath = str_replace('.ico', '.png', $destPath);
                if (imagepng($resized, $pngPath, 9)) {
                    // For now, keep as PNG with .ico extension for compatibility
                    // Most browsers support PNG favicons even with .ico extension
                    if (file_exists($pngPath)) {
                        rename($pngPath, $destPath);
                        $generated[] = $filename;
                    }
                } else {
                    $errors[] = "Failed to save {$filename}";
                }
            } else {
                if (imagepng($resized, $destPath, 9)) {
                    $generated[] = $filename;
                } else {
                    $errors[] = "Failed to save {$filename}";
                }
            }

            imagedestroy($resized);
        }

        imagedestroy($sourceImage);

        // Note: site.webmanifest is now generated dynamically by PageController::webManifest()
        // which checks for icon existence and uses database settings for site name/colors

        return [
            'success' => count($generated) > 0,
            'generated' => $generated,
            'errors' => $errors
        ];
    }

    /**
     * Create GD image resource from file path based on MIME type
     */
    private function createImageResource(string $path, string $mimeType): ?\GdImage
    {
        return match ($mimeType) {
            'image/jpeg', 'image/jpg' => imagecreatefromjpeg($path) ?: null,
            'image/png' => imagecreatefrompng($path) ?: null,
            'image/webp' => imagecreatefromwebp($path) ?: null,
            'image/gif' => imagecreatefromgif($path) ?: null,
            default => null,
        };
    }

    /**
     * Clean up generated favicons
     */
    public function cleanupFavicons(): bool
    {
        $files = [
            'favicon.ico',
            'favicon-16x16.png',
            'favicon-32x32.png',
            'favicon-96x96.png',
            'apple-touch-icon.png',
            'android-chrome-192x192.png',
            'android-chrome-512x512.png',
            // PWA manifest icons
            'icon-72x72.png',
            'icon-128x128.png',
            'icon-144x144.png',
            'icon-152x152.png',
            'icon-384x384.png',
        ];

        $success = true;
        foreach ($files as $file) {
            $path = $this->publicPath . '/' . $file;
            if (file_exists($path)) {
                $success = $success && unlink($path);
            }
        }

        return $success;
    }
}
