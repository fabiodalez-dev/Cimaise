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
        int $fallbackWidth,
        string $replaceKeyword = 'REPLACE',
        ?\PDOStatement $stmt = null
    ): void {
        $size = 0;
        if (is_file($destPath)) {
            $filesize = filesize($destPath);
            $size = ($filesize !== false) ? (int)$filesize : 0;
        }
        $dims = @getimagesize($destPath) ?: [$fallbackWidth, 0];
        if ($stmt === null) {
            $stmt = $pdo->prepare(sprintf(
                '%s INTO image_variants(image_id, variant, format, path, width, height, size_bytes) VALUES(?,?,?,?,?,?,?)',
                $replaceKeyword
            ));
        }
        $stmt->execute([$imageId, $variant, $format, $destRelUrl, (int)$dims[0], (int)$dims[1], $size]);
    }
}
