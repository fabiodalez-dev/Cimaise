<?php
declare(strict_types=1);

use App\Services\AlbumEnrichmentService;
use PHPUnit\Framework\TestCase;

final class AlbumEnrichmentFallbackCoverTest extends TestCase
{
    public function testFallbackCoverIsFirstImageBySortOrderThenId(): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE albums(id INTEGER PRIMARY KEY);
            CREATE TABLE images(id INTEGER PRIMARY KEY, album_id INTEGER, sort_order INTEGER);
            CREATE TABLE image_variants(id INTEGER PRIMARY KEY, image_id INTEGER, variant TEXT, format TEXT, path TEXT);
            INSERT INTO albums VALUES (1),(2),(3);
            INSERT INTO images VALUES (10,1,5),(11,1,2),(12,1,2),(20,2,0);
            INSERT INTO image_variants VALUES (1,11,"sm","jpg","/media/11_sm.jpg"),(2,11,"blur","jpg","/media/11_blur.jpg"),(3,11,"sm","webp","/media/11_sm.webp");');

        $covers = (new AlbumEnrichmentService($pdo))->loadFallbackCoverImages([1, 2, 3, 0, -5]);

        self::assertSame([1, 2], array_keys($covers), 'Albums without images get no fallback cover');
        self::assertSame(11, (int) $covers[1]['id'], 'Lowest sort_order wins, ties broken by lowest id');
        self::assertSame('/media/11_sm.jpg', $covers[1]['preview_path']);
        self::assertSame('/media/11_blur.jpg', $covers[1]['blur_path']);
        self::assertSame(20, (int) $covers[2]['id']);
        self::assertNull($covers[2]['preview_path']);
    }

    public function testQueryAvoidsSyntaxUnsupportedByMysql57(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Services/AlbumEnrichmentService.php');
        self::assertDoesNotMatchRegularExpression('/\bWITH\s+\w+\s+AS\s*\(|\bOVER\s*\(/i', $source);
    }
}
