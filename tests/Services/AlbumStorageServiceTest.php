<?php

declare(strict_types=1);

use App\Services\AlbumStorageService;
use PHPUnit\Framework\TestCase;

final class AlbumStorageServiceTest extends TestCase
{
    public function testCountsExistingFilesWithoutDuplicatingSharedOriginalsOrUsingStaleSizes(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE images (id INTEGER, album_id INTEGER, original_path TEXT);
                    CREATE TABLE image_variants (image_id INTEGER, path TEXT, size_bytes INTEGER);');
        $root = dirname(__DIR__, 2);
        $id = random_int(91000000, 99000000);
        $original = '/storage/originals/album-storage-' . $id . '.jpg';
        $public = '/public/media/' . $id . '_sm.jpg';
        $private = '/storage/protected-media/' . $id . '_lg.webp';
        $files = [];
        try {
            foreach ([$original => 1234, $public => 56, $private => 789] as $path => $bytes) {
                if (!is_dir(dirname($root . $path))) {
                    mkdir(dirname($root . $path), 0775, true);
                }
                $files[] = $root . $path;
                file_put_contents($root . $path, str_repeat('x', $bytes));
            }
            $insert = $pdo->prepare('INSERT INTO images VALUES (?, ?, ?)');
            $insert->execute([1, 1, $original]);
            $insert->execute([2, 1, $original]);
            $insert->execute([3, 2, $original]);
            $insert->execute([4, 1, '/storage/originals/missing-' . $id . '.jpg']);
            $insert = $pdo->prepare('INSERT INTO image_variants VALUES (?, ?, ?)');
            $insert->execute([1, '/media/' . $id . '_sm.jpg', 999999]);
            $insert->execute([2, '/media/' . $id . '_sm.jpg', 999999]);
            $insert->execute([2, '/media/protected/' . $id . '_lg.webp', 999999]);
            $insert->execute([1, '/media/' . $id . '_missing.jpg', 999999]);
            $insert->execute([1, '/media/../' . $id . '_lg.webp', 999999]);
            $service = new AlbumStorageService($pdo);
            $this->assertSame([], $service->usage([]));
            $this->assertSame([
                1 => ['original_bytes' => 1234, 'variant_bytes' => 845, 'total_bytes' => 2079],
                2 => ['original_bytes' => 1234, 'variant_bytes' => 0, 'total_bytes' => 1234],
                5 => ['original_bytes' => 0, 'variant_bytes' => 0, 'total_bytes' => 0],
            ], $service->usage([1, 2, 5, 1]));
            $this->assertSame([2], array_keys($service->usage([2])));
            $this->assertFileExists($root . $private, 'Reading totals must not move protected media');
        } finally {
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }
}
