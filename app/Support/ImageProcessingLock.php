<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class ImageProcessingLock
{
    public static function run(int $imageId, callable $operation): mixed
    {
        $dir = dirname(__DIR__, 2) . '/storage/image-jobs';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create image job directory');
        }
        // Never unlink lock files: replacing an inode would allow two owners.
        $lock = fopen($dir . '/' . $imageId . '.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open image processing lock');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException("Image {$imageId} is already being processed; retry later");
            }
            return $operation();
        } finally {
            fclose($lock);
        }
    }
}
