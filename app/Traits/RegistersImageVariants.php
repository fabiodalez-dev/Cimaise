<?php

declare(strict_types=1);

namespace App\Traits;

trait RegistersImageVariants
{
    protected function registerVariantFromFile(
        \PDO $pdo,
        int $imageId,
        string $variant,
        string $format,
        string $destRelUrl,
        string $destPath,
        int $fallbackWidth
    ): void {
        $size = is_file($destPath) ? (int)filesize($destPath) : 0;
        $dims = @getimagesize($destPath) ?: [$fallbackWidth, 0];
        $pdo->prepare('REPLACE INTO image_variants(image_id, variant, format, path, width, height, size_bytes) VALUES(?,?,?,?,?,?,?)')
            ->execute([$imageId, $variant, $format, $destRelUrl, (int)$dims[0], (int)$dims[1], $size]);
    }
}
