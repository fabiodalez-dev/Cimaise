<?php
declare(strict_types=1);

use App\Controllers\Frontend\MediaController;
use App\Services\ProtectedMediaStorage;
use App\Services\UploadService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class ProtectedMediaStorageTest extends TestCase
{
    private string $dbFile;
    private Database $db;
    private int $nextId;
    /** @var string[] */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SESSION = [];
        $this->nextId = random_int(8_000_000, 8_900_000);
        $this->dbFile = sys_get_temp_dir() . '/cimaise_protected_media_' . uniqid('', true) . '.sqlite';
        $this->db = new Database(null, null, $this->dbFile, null, null, 'utf8mb4', 'utf8mb4_unicode_ci', true);
        $this->db->pdo()->exec(
            'CREATE TABLE albums (
                id INTEGER PRIMARY KEY,
                slug TEXT,
                is_published INTEGER NOT NULL DEFAULT 1,
                is_nsfw INTEGER NOT NULL DEFAULT 0,
                password_hash TEXT,
                allow_downloads INTEGER NOT NULL DEFAULT 1
            );
            CREATE TABLE images (
                id INTEGER PRIMARY KEY,
                album_id INTEGER NOT NULL,
                original_path TEXT,
                mime TEXT
            );
            CREATE TABLE image_variants (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                image_id INTEGER NOT NULL,
                variant TEXT NOT NULL,
                format TEXT NOT NULL,
                path TEXT NOT NULL,
                width INTEGER,
                height INTEGER,
                size_bytes INTEGER
            );'
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        unset($this->db);
        foreach ([$this->dbFile, $this->dbFile . '-wal', $this->dbFile . '-shm'] as $file) {
            @unlink($file);
        }
    }

    public function testAuthorizedNsfwVariantIsQuarantinedAndNeverCacheable(): void
    {
        $imageId = $this->insertImage(nsfw: true);
        $publicPath = $this->writeVariant($imageId, 'sm', 'jpg');
        $_SESSION['nsfw_confirmed_global'] = true;

        $response = $this->controller()->servePublic(
            (new ServerRequestFactory())->createServerRequest('GET', "/media/{$imageId}_sm.jpg"),
            new Response(),
            ['path' => "{$imageId}_sm.jpg"]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('noimageindex', $response->getHeaderLine('X-Robots-Tag'));
        self::assertFileDoesNotExist($publicPath);
        self::assertFileExists($this->privatePath("{$imageId}_sm.jpg"));
    }

    public function testAuthorizedPasswordVariantIsNeverCacheable(): void
    {
        $imageId = $this->insertImage(passwordHash: password_hash('secret', PASSWORD_DEFAULT));
        $this->writeVariant($imageId, 'sm', 'jpg');
        $_SESSION['album_access'][1] = time();

        $response = $this->controller()->serveProtected(
            (new ServerRequestFactory())->createServerRequest('GET', "/media/protected/{$imageId}/sm.jpg"),
            new Response(),
            ['id' => $imageId, 'variant' => 'sm', 'format' => 'jpg']
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('no-cache', $response->getHeaderLine('Pragma'));
    }

    public function testPublicVariantRemainsPublicAndCacheable(): void
    {
        $imageId = $this->insertImage();
        $publicPath = $this->writeVariant($imageId, 'sm', 'jpg');

        $response = $this->controller()->servePublic(
            (new ServerRequestFactory())->createServerRequest('GET', "/media/{$imageId}_sm.jpg"),
            new Response(),
            ['path' => "{$imageId}_sm.jpg"]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('public,', $response->getHeaderLine('Cache-Control'));
        self::assertFileExists($publicPath);
        self::assertFileDoesNotExist($this->privatePath("{$imageId}_sm.jpg"));
    }

    public function testOrphanNumericVariantFailsClosed(): void
    {
        $imageId = $this->nextId++;
        $this->writeJpeg($this->publicPath("{$imageId}_sm.jpg"));

        $response = $this->controller()->servePublic(
            (new ServerRequestFactory())->createServerRequest('GET', "/media/{$imageId}_sm.jpg"),
            new Response(),
            ['path' => "{$imageId}_sm.jpg"]
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testCustomNumericVariantCannotFallThroughToStaticServing(): void
    {
        $imageId = $this->insertImage(nsfw: true);
        $publicPath = $this->writeVariant($imageId, 'custom-preview', 'jpg');
        $_SESSION['nsfw_confirmed_global'] = true;

        $response = $this->controller()->servePublic(
            (new ServerRequestFactory())->createServerRequest('GET', "/media/{$imageId}_custom-preview.jpg"),
            new Response(),
            ['path' => "{$imageId}_custom-preview.jpg"]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringNotContainsString('public', $response->getHeaderLine('Cache-Control'));
        self::assertFileDoesNotExist($publicPath);
        self::assertFileExists($this->privatePath("{$imageId}_custom-preview.jpg"));
    }

    public function testSharedVariantStaysPublicWhileReferencedByPublicAlbum(): void
    {
        $publicImageId = $this->insertImage();
        $protectedImageId = $this->insertImage(albumId: 2, nsfw: true);
        $dbPath = "/media/{$publicImageId}_sm.jpg";
        $publicPath = $this->writeJpeg($this->publicPath("{$publicImageId}_sm.jpg"));
        $this->createdFiles[] = $publicPath;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO image_variants (image_id, variant, format, path) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$publicImageId, 'sm', 'jpg', $dbPath]);
        $stmt->execute([$protectedImageId, 'sm', 'jpg', $dbPath]);

        (new ProtectedMediaStorage($this->db))->relocateAlbumVariants(2, true);

        self::assertFileExists($publicPath);
        self::assertFileDoesNotExist($this->privatePath(basename($dbPath)));
    }

    private function insertImage(
        int $albumId = 1,
        bool $nsfw = false,
        ?string $passwordHash = null
    ): int {
        $imageId = $this->nextId++;
        $albumStmt = $this->db->pdo()->prepare(
            'INSERT OR IGNORE INTO albums (id, slug, is_published, is_nsfw, password_hash, allow_downloads)
             VALUES (?, ?, 1, ?, ?, 1)'
        );
        $albumStmt->execute([$albumId, 'album-' . $albumId, $nsfw ? 1 : 0, $passwordHash]);

        $imageStmt = $this->db->pdo()->prepare(
            'INSERT INTO images (id, album_id, original_path, mime) VALUES (?, ?, ?, ?)'
        );
        $imageStmt->execute([$imageId, $albumId, '/storage/originals/test.jpg', 'image/jpeg']);
        return $imageId;
    }

    private function writeVariant(int $imageId, string $variant, string $format): string
    {
        $dbPath = "/media/{$imageId}_{$variant}.{$format}";
        $path = $this->writeJpeg($this->publicPath(basename($dbPath)));
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO image_variants (image_id, variant, format, path, width, height, size_bytes)
             VALUES (?, ?, ?, ?, 16, 16, ?)'
        );
        $stmt->execute([$imageId, $variant, $format, $dbPath, filesize($path)]);
        return $path;
    }

    private function writeJpeg(string $path): string
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            self::markTestSkipped('GD JPEG support is required for media streaming tests');
        }
        $image = imagecreatetruecolor(16, 16);
        $color = imagecolorallocate($image, 20, 40, 80);
        imagefilledrectangle($image, 0, 0, 15, 15, $color);
        imagejpeg($image, $path, 85);
        imagedestroy($image);
        $this->createdFiles[] = $path;
        return $path;
    }

    private function controller(): MediaController
    {
        return new MediaController($this->db, new UploadService($this->db));
    }

    private function publicPath(string $basename): string
    {
        return dirname(__DIR__, 2) . '/public/media/' . $basename;
    }

    private function privatePath(string $basename): string
    {
        $path = dirname(__DIR__, 2) . '/storage/protected-media/' . $basename;
        $this->createdFiles[] = $path;
        return $path;
    }
}
