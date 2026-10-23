<?php

declare(strict_types=1);

use App\Controllers\Admin\AlbumsController;
use App\Services\CacheTags;
use App\Services\CacheWarmService;
use App\Services\PageCacheService;
use App\Services\SettingsService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;

final class AlbumDeletionCacheTest extends TestCase
{
    private Database $db;
    private SettingsService $settings;
    private mixed $settingsSnapshot;
    private array $session;
    private string $scriptName;

    protected function setUp(): void
    {
        $this->session = $_SESSION ?? [];
        $this->scriptName = $_SERVER['SCRIPT_NAME'];
        $_SESSION = ['csrf' => 'album-delete-test'];
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $property = new ReflectionProperty(SettingsService::class, 'cache');
        $this->settingsSnapshot = $property->getValue();
        $this->db = new Database(database: ':memory:', isSqlite: true);
        $this->db->pdo()->exec((string)file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        $this->settings = new SettingsService($this->db);
        $property->setValue(null, array_replace($this->settings->defaults(), [
            'cache.auto_warm' => true, 'cache.storage_backend' => 'database',
            'seo.sitemap_enabled' => false,
        ]));
        $this->db->pdo()->exec("INSERT INTO categories(id,name,slug) VALUES(876540,'Fixture','fixture');
            INSERT INTO albums(id,title,slug,category_id,is_published) VALUES
                (876540,'Delete me','delete-me',876540,1), (876541,'Keep me','keep-me',876540,1);
            INSERT INTO album_category(album_id,category_id) VALUES(876540,876540);
            INSERT INTO images(id,album_id,original_path,file_hash,width,height,mime) VALUES
                (876540,876540,'/storage/originals/delete-test.jpg','delete-test',800,600,'image/jpeg'),
                (876541,876541,'/storage/originals/keep-test.jpg','keep-test',800,600,'image/jpeg');
            INSERT INTO image_variants(image_id,variant,format,path,width,height,size_bytes) VALUES
                (876540,'sm','jpg','/media/876540_sm.jpg',800,600,123),
                (876541,'sm','jpg','/media/876541_sm.jpg',800,600,123);
            UPDATE albums SET cover_image_id=id WHERE id IN (876540,876541);");
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(SettingsService::class, 'cache'))->setValue(null, $this->settingsSnapshot);
        $_SESSION = $this->session;
        $_SERVER['SCRIPT_NAME'] = $this->scriptName;
    }

    public function testWarmedListingContainsExplicitAndFallbackPreviews(): void
    {
        $this->db->pdo()->exec('UPDATE albums SET cover_image_id=NULL WHERE id=876541');
        $warmer = new CacheWarmService($this->db);
        self::assertTrue($warmer->warmGalleries());
        $albums = array_column($warmer->getPageCacheService()->get('galleries')['data']['albums'], null, 'id');
        self::assertSame('/media/876540_sm.jpg', $albums[876540]['cover_image']['preview_path'] ?? null);
        self::assertSame('/media/876541_sm.jpg', $albums[876541]['cover_image']['preview_path'] ?? null);
    }

    public function testDeleteRewarmsAfterMutationAndInvalidatesCategoryAndTemplateListings(): void
    {
        $cache = new PageCacheService($this->settings, $this->db);
        $cache->setWithTags('category_876540', ['data' => ['old' => true]], [CacheTags::category(876540)]);
        $cache->setWithTags('galleries_mosaic', ['data' => ['old' => true]], [CacheTags::GALLERIES]);
        (new CacheWarmService($this->db))->warmGalleries();
        $response = $this->deleteFixtureAlbum();
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(0, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM albums WHERE id=876540')->fetchColumn(), json_encode($_SESSION['flash'] ?? []));
        $listing = $cache->get('galleries');
        self::assertNotNull($listing);
        self::assertSame([876541], array_map(intval(...), array_column($listing['data']['albums'], 'id')));
        self::assertSame('/media/876541_sm.jpg', $listing['data']['albums'][0]['cover_image']['preview_path'] ?? null);
        self::assertNull($cache->get('category_876540'));
        self::assertNull($cache->get('galleries_mosaic'));
    }

    public function testDeletingAnAlbumPreservesSharedOriginalAndPreviewBytes(): void
    {
        $root = dirname(__DIR__, 2);
        $suffix = bin2hex(random_bytes(8));
        $originalPath = '/storage/originals/album-delete-shared-' . $suffix . '.jpg';
        $variantPath = '/media/876541_shared-' . $suffix . '.jpg';
        $original = $root . $originalPath;
        $variant = $root . '/public' . $variantPath;
        foreach ([$original, $variant] as $path) {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            self::assertFileDoesNotExist($path);
            file_put_contents($path, 'shared image bytes');
        }
        try {
            $this->db->pdo()->prepare('UPDATE images SET original_path=?')->execute([$originalPath]);
            $this->db->pdo()->prepare('UPDATE image_variants SET path=?')->execute([$variantPath]);
            self::assertSame(302, $this->deleteFixtureAlbum()->getStatusCode());
            self::assertSame(1, (int)$this->db->pdo()->query('SELECT COUNT(*) FROM albums WHERE id=876541')->fetchColumn());
            self::assertSame('shared image bytes', file_get_contents($original));
            self::assertSame('shared image bytes', file_get_contents($variant));
        } finally {
            @unlink($original);
            @unlink($variant);
        }
    }

    public function testWarmedListingsParticipateInAlbumTagInvalidation(): void
    {
        $warmer = new CacheWarmService($this->db);
        self::assertTrue($warmer->warmHome());
        self::assertTrue($warmer->warmGalleries());
        $cache = $warmer->getPageCacheService();
        self::assertNotNull($cache->get('home'));
        self::assertNotNull($cache->get('galleries'));
        $cache->invalidateByTags(CacheTags::albumRelated(876540));
        self::assertNull($cache->get('home'));
        self::assertNull($cache->get('galleries'));
    }

    public function testDeleteSurvivesAConcurrentBackgroundWriteAfterReadingTheAlbum(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cimaise-delete-');
        self::assertNotFalse($path);
        $db = new Database(database: $path, isSqlite: true);
        $pdo = new class('sqlite:' . $path) extends PDO {
            public ?Closure $beforeDelete = null;

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                if (str_starts_with($query, 'DELETE FROM albums') && $this->beforeDelete !== null) {
                    $callback = $this->beforeDelete;
                    $this->beforeDelete = null;
                    $callback();
                }
                return parent::prepare($query, $options);
            }
        };
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA busy_timeout=100');
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        try {
            $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
            $pdo->exec("INSERT INTO categories(id,name,slug) VALUES(876540,'Fixture','fixture');
                INSERT INTO albums(id,title,slug,category_id,is_published) VALUES
                (876540,'Delete me','delete-me',876540,1),(876541,'Keep me','keep-me',876540,1)");
            $writer = new PDO('sqlite:' . $path);
            $pdo->beforeDelete = static fn () => $writer->exec("UPDATE albums SET title='Background write' WHERE id=876541");
            self::assertSame(302, $this->deleteFixtureAlbum($db)->getStatusCode());
            self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM albums WHERE id=876540')->fetchColumn(), json_encode($_SESSION['flash'] ?? []));
            self::assertSame('Background write', $pdo->query('SELECT title FROM albums WHERE id=876541')->fetchColumn());
        } finally {
            $pdo->beforeDelete = null;
            (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $this->db->pdo());
            unset($writer, $pdo, $db);
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                @unlink($file);
            }
        }
    }

    private function deleteFixtureAlbum(?Database $db = null): \Psr\Http\Message\ResponseInterface
    {
        $controller = new class($db ?? $this->db, Twig::create(dirname(__DIR__, 2) . '/app/Views')) extends AlbumsController {
            // Preserve the fixture session; this test covers deletion, not PHP session startup.
            protected function ensureSession(): void {}
        };
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/albums/876540/delete')
            ->withParsedBody(['csrf' => 'album-delete-test']);
        return $controller->delete($request, new Response(), ['id' => 876540]);
    }
}
