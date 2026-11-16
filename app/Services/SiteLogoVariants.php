<?php

declare(strict_types=1);

namespace App\Services;

final class SiteLogoVariants
{
    private const WIDTHS = [48, 96, 192, 320, 480, 640, 960];

    /**
     * Generate responsive WebP copies of a local uploaded logo. Keep the original
     * for favicon generation and for browsers that do not select a variant.
     *
     * @return array{path: string, width: int, height: int, sources: array<int, string>}|null
     */
    public function generate(?string $path): ?array
    {
        if ($path === null || !preg_match('#\A/media/logo-[a-f0-9]{40}\.(?:png|jpg|webp)\z#D', $path)
            || !function_exists('imagewebp')) {
            return null;
        }
        $original = dirname(__DIR__, 2) . '/public' . $path;
        $info = @getimagesize($original);
        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40000000) {
            return null;
        }
        [$width, $height] = $info;
        $source = null;
        $sources = [];
        $stem = substr($path, 0, strrpos($path, '.'));
        foreach (self::WIDTHS as $size) {
            if ($size >= $width) {
                break;
            }
            $url = $stem . '-w' . $size . '.webp';
            $destination = dirname(__DIR__, 2) . '/public' . $url;
            if (!is_file($destination)) {
                $source ??= @imagecreatefromstring((string) @file_get_contents($original));
                if ($source === false) {
                    return null;
                }
                $scaled = max(1, (int) round($height * $size / $width));
                $image = imagecreatetruecolor($size, $scaled);
                if (!$image) {
                    continue;
                }
                imagealphablending($image, false);
                imagesavealpha($image, true);
                $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
                imagefill($image, 0, 0, $transparent);
                imagecopyresampled($image, $source, 0, 0, 0, 0, $size, $scaled, $width, $height);
                $temporary = tempnam(dirname($destination), '.logo-');
                if ($temporary === false) {
                    continue;
                }
                try {
                    if (@imagewebp($image, $temporary, 86) && filesize($temporary) > 0) {
                        @chmod($temporary, 0644);
                        @rename($temporary, $destination);
                    }
                } finally {
                    if (is_file($temporary)) {
                        @unlink($temporary);
                    }
                }
            }
            if (is_file($destination)) {
                $sources[$size] = $url;
            }
        }
        if ($width <= max(self::WIDTHS)) {
            $sources[$width] = $path;
        }

        return ['path' => $path, 'width' => $width, 'height' => $height, 'sources' => $sources];
    }
}
