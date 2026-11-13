<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Controllers\Frontend\ApiController;
use App\Controllers\Frontend\GalleriesController;
use App\Support\Database;
use App\Support\GpsPrivacy;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\Twig;

/** Regressions for audit findings C3 (locked body search), C8 (draft taxonomy), C13 (GPS policy). */
final class FrontendDisclosureTest extends TestCase
{
    private string $dbFile;
    private Database $db;
    private ?\Closure $restoreCache = null;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Support/TempQueryCache.php';
        $this->restoreCache = \TempQueryCache::install();
        GpsPrivacy::reset();
        $_SESSION = [];
        $this->dbFile = sys_get_temp_dir() . '/cimaise_disclosure_' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->db = new Database(database: $this->dbFile, isSqlite: true);
        $pdo = $this->db->pdo();
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        $pdo->exec("INSERT INTO albums (id, category_id, title, slug, body, is_published) VALUES (1, 1, 'Public', 'public-album', 'nothing here', 1)");
        $pdo->exec("INSERT INTO albums (id, category_id, title, slug, body, is_published, password_hash) VALUES (2, 1, 'Locked', 'locked-album', 'meeting at Harbor KQVTOKEN room 7', 1, '\$2y\$10\$dummyhashdummyhashdummyhashdummyhashdummyhashdummyhas')");
        $pdo->exec("INSERT INTO albums (id, category_id, title, slug, body, is_published) VALUES (3, 1, 'Draft', 'draft-album', 'draft', 0)");
        $pdo->exec("INSERT INTO tags (id, name, slug) VALUES (60, 'drafttagvq', 'drafttagvq'), (61, 'pubtag', 'pubtag')");
        $pdo->exec('INSERT INTO album_tag (album_id, tag_id) VALUES (3, 60), (1, 61)');
        $pdo->exec("INSERT INTO images (id, album_id, original_path, file_hash, width, height, mime, gps_lat, gps_lng) VALUES (1, 1, 'storage/originals/x.jpg', 'h1', 10, 10, 'image/jpeg', 45.4371234, 12.3326789)");
    }

    protected function tearDown(): void
    {
        GpsPrivacy::reset();
        if ($this->restoreCache) {
            ($this->restoreCache)();
        }
        @unlink($this->dbFile);
    }

    private function galleries(): GalleriesController
    {
        $c = (new \ReflectionClass(GalleriesController::class))->newInstanceWithoutConstructor();
        (function (Database $db): void { $this->db = $db; })->call($c, $this->db);
        return $c;
    }

    /** @return list<string> */
    private function searchSlugs(string $term, bool $admin = false): array
    {
        $m = new \ReflectionMethod(GalleriesController::class, 'getFilteredAlbums');
        $rows = $m->invoke($this->galleries(), ['search' => $term], $admin, false);
        return array_values(array_map(static fn ($a) => (string) $a['slug'], $rows));
    }

    public function testLockedAlbumBodyDoesNotDriveAnonymousSearch(): void
    {
        self::assertSame([], $this->searchSlugs('KQVTOKEN'));
        self::assertSame([], $this->searchSlugs('Harbor K_VTOKEN'));
        self::assertContains('locked-album', $this->searchSlugs('KQVTOKEN', true));
        self::assertContains('locked-album', $this->searchSlugs('Locked')); // title still searchable
    }

    public function testLikeWildcardsAreLiteral(): void
    {
        self::assertSame([], $this->searchSlugs('%'));
        self::assertSame([], $this->searchSlugs('_'));
    }

    public function testFilterOptionsIgnoreDraftOnlyTerms(): void
    {
        $m = new \ReflectionMethod(GalleriesController::class, 'queryFilterOptions');
        $opts = $m->invoke($this->galleries());
        $names = array_column($opts['tags'], 'albums_count', 'name');
        self::assertArrayNotHasKey('drafttagvq', $names);
        self::assertSame(1, (int) ($names['pubtag'] ?? 0));
    }

    public function testExifApiRoundsGpsByDefault(): void
    {
        $api = new ApiController($this->db, Twig::create(dirname(__DIR__, 2) . '/app/Views'));
        $req = (new ServerRequestFactory())->createServerRequest('GET', '/api/image/1/exif');
        $res = $api->imageExif($req, (new ResponseFactory())->createResponse(), ['id' => '1']);
        $body = (string) $res->getBody();
        self::assertStringContainsString('45.44, 12.33', $body);
        self::assertStringNotContainsString('45.437', $body);
    }

    public function testGpsPrivacyHelper(): void
    {
        self::assertSame(45.44, GpsPrivacy::mask('45.4371234', false));
        self::assertSame(45.4371234, GpsPrivacy::mask('45.4371234', true));
        self::assertNull(GpsPrivacy::mask('', false));
        $rows = GpsPrivacy::maskRows([['gps_lat' => 1.23456, 'gps_lng' => null, 'x' => 1]], false);
        self::assertSame([['gps_lat' => 1.23, 'gps_lng' => null, 'x' => 1]], $rows);
    }

    public function testAlbumPageAndImagesApiUseTheHelper(): void
    {
        $root = dirname(__DIR__, 2) . '/app/Controllers/Frontend/';
        self::assertStringContainsString('GpsPrivacy::maskRows($stmt->fetchAll(), $this->exposeGps())', (string) file_get_contents($root . 'PageController.php'));
        self::assertStringContainsString('GpsPrivacy::maskRows($stmt->fetchAll() ?: []', (string) file_get_contents($root . 'ApiController.php'));
    }
}
