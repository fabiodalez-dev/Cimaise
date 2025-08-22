<?php
declare(strict_types=1);

namespace App\Services;

class ImagesService
{
    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
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

