<?php
declare(strict_types=1);

use App\Services\AlbumStorageService;
use App\Support\QueryCache;
use PHPUnit\Framework\TestCase;

final class AlbumStorageCacheTest extends TestCase
{
    public function testMediaChangesInvalidateTotalsAndExternalChangesExpire(): void
    {
        $root = sys_get_temp_dir() . '/cimaise-storage-' . bin2hex(random_bytes(8));
        foreach (['storage/originals', 'storage/protected-media', 'public/media', 'cache'] as $directory) {
            mkdir($root . '/' . $directory, 0775, true);
        }
        $property = new ReflectionProperty(QueryCache::class, 'instance');
        $oldCache = $property->getValue();
        $cache = (new ReflectionClass(QueryCache::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(QueryCache::class, 'useApcu'))->setValue($cache, false);
        (new ReflectionProperty(QueryCache::class, 'cacheDir'))->setValue($cache, $root . '/cache');
        $property->setValue(null, $cache);
        try {
            $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE TABLE images(id INTEGER PRIMARY KEY, album_id INTEGER, original_path TEXT);
                CREATE TABLE image_variants(id INTEGER PRIMARY KEY, image_id INTEGER, path TEXT, size_bytes INTEGER);
                INSERT INTO images VALUES (1,10,"/storage/originals/a.jpg"),(2,10,"/storage/originals/a.jpg"),(3,20,"/storage/originals/a.jpg");
                INSERT INTO image_variants VALUES (1,1,"/media/1_sm.jpg",3),(2,2,"/media/1_sm.jpg",3),(3,3,"/media/protected/3_sm.jpg",7),(4,1,"/media/../outside.jpg",999);');
            file_put_contents($root . '/storage/originals/a.jpg', str_repeat('a', 10));
            file_put_contents($root . '/public/media/1_sm.jpg', 'abc');
            file_put_contents($root . '/storage/protected-media/3_sm.jpg', 'abcdefg');
            $service = new AlbumStorageService($pdo, $root);
            $cold = $service->usage([10, 20, 10]);
            self::assertSame(13, $cold[10]['total_bytes'], 'Shared files count once within each album');
            self::assertSame(17, $cold[20]['total_bytes'], 'Shared originals count in each owning album');
            file_put_contents($root . '/storage/originals/a.jpg', str_repeat('b', 20));
            self::assertSame($cold, $service->usage([10, 20]), 'Warm reads avoid inspecting media files');
            self::assertSame(23, $service->usage([10], refresh: true)[10]['total_bytes']);
            self::assertSame(27, $service->usage([20], refresh: true)[20]['total_bytes']);

            file_put_contents($root . '/public/media/1_sm.jpg', 'abcde');
            foreach (glob($root . '/cache/*.cache') as $file) {
                $entry = unserialize(file_get_contents($file), ['allowed_classes' => false]);
                $entry['expires'] = 0;
                file_put_contents($file, serialize($entry));
            }
            self::assertSame(25, $service->usage([10])[10]['total_bytes'], 'Expired entries reconcile external filesystem changes');
            file_put_contents($root . '/public/media/1_sm.jpg', 'abcdefgh');
            $pdo->exec('UPDATE image_variants SET size_bytes=8 WHERE id=1');
            self::assertSame(28, $service->usage([10])[10]['total_bytes'], 'Variant metadata changes invalidate before TTL');
            $pdo->exec('DELETE FROM images WHERE album_id=10');
            self::assertSame(0, $service->usage([10])[10]['total_bytes'], 'Deleted images cannot retain cached usage');
            $pdo->exec('UPDATE images SET album_id=10 WHERE id=3');
            $moved = $service->usage([10, 20]);
            self::assertSame(27, $moved[10]['total_bytes']);
            self::assertSame(0, $moved[20]['total_bytes'], 'Moving images invalidates both albums');
            unlink($root . '/storage/originals/a.jpg');
            self::assertSame(7, $service->usage([10], refresh: true)[10]['total_bytes'], 'Missing originals are reconciled safely');
        } finally {
            $property->setValue(null, $oldCache);
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
            rmdir($root);
        }
    }
}
