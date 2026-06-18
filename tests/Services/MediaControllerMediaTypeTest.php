<?php

declare(strict_types=1);

use App\Controllers\Frontend\MediaController;
use App\Services\UploadService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Media-type / format-whitelist coverage for MediaController (#109): JPEG-XL
 * is served end-to-end with the correct MIME, the strict DB-path magic-byte
 * gate verifies the JXL signature directly (libmagic-independent), spoofed or
 * truncated .jxl bytes are rejected, and unknown extensions never resolve.
 * Real controller + in-memory SQLite — no mocks of the access/serve logic.
 */
final class MediaControllerMediaTypeTest extends TestCase
{
    private string $dbFile;
    private Database $db;
    private int $nextId;
    /** @var string[] */
    private array $createdFiles = [];
    /** Saved MEDIA_XSENDFILE so streamFile() streams the body deterministically. */
    private mixed $origXsendfileEnv = null;
    private string|false $origXsendfileGetenv = false;

    /** Bare JPEG-XL codestream signature + filler. */
    private const JXL_CODESTREAM = "\xFF\x0A" . "\x00\x00\x00\x00\x00\x00";
    /** ISO-BMFF "JXL " box signature + filler. */
    private const JXL_CONTAINER = "\x00\x00\x00\x0C\x4A\x58\x4C\x20\x0D\x0A\x87\x0A" . "\x00\x00\x00\x00";

    protected function setUp(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SESSION = [];
        // Isolate MEDIA_XSENDFILE: when set (apache/nginx) streamFile() delegates
        // to the web server and returns an EMPTY body, which would break the exact
        // -body assertions below. Save it (from both $_ENV and getenv) and clear it
        // so the test always exercises the direct PSR-7 streaming path.
        $this->origXsendfileEnv = $_ENV['MEDIA_XSENDFILE'] ?? null;
        $this->origXsendfileGetenv = getenv('MEDIA_XSENDFILE');
        unset($_ENV['MEDIA_XSENDFILE']);
        putenv('MEDIA_XSENDFILE');
        $this->nextId = random_int(6_100_000, 6_900_000);
        $this->dbFile = sys_get_temp_dir() . '/cimaise_mediatype_' . uniqid('', true) . '.sqlite';
        $this->db = new Database(null, null, $this->dbFile, null, null, 'utf8mb4', 'utf8mb4_unicode_ci', true);
        $this->db->pdo()->exec(
            'CREATE TABLE albums (id INTEGER PRIMARY KEY, slug TEXT, is_published INTEGER NOT NULL DEFAULT 1,
                is_nsfw INTEGER NOT NULL DEFAULT 0, password_hash TEXT, allow_downloads INTEGER NOT NULL DEFAULT 1);
             CREATE TABLE images (id INTEGER PRIMARY KEY, album_id INTEGER NOT NULL, original_path TEXT, mime TEXT);
             CREATE TABLE image_variants (id INTEGER PRIMARY KEY AUTOINCREMENT, image_id INTEGER NOT NULL,
                variant TEXT NOT NULL, format TEXT NOT NULL, path TEXT NOT NULL, width INTEGER, height INTEGER, size_bytes INTEGER);'
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        // Restore MEDIA_XSENDFILE to whatever it was before this test.
        if ($this->origXsendfileEnv !== null) {
            $_ENV['MEDIA_XSENDFILE'] = $this->origXsendfileEnv;
        } else {
            unset($_ENV['MEDIA_XSENDFILE']);
        }
        if ($this->origXsendfileGetenv !== false) {
            putenv('MEDIA_XSENDFILE=' . $this->origXsendfileGetenv);
        } else {
            putenv('MEDIA_XSENDFILE');
        }
        unset($this->db);
        foreach (array_merge($this->createdFiles, [$this->dbFile, $this->dbFile . '-wal', $this->dbFile . '-shm']) as $f) {
            // nosemgrep: test-fixture cleanup; $f is a path this test created.
            @unlink($f); // nosemgrep
        }
    }

    public function testJpegVariantServedAsImageJpeg(): void
    {
        $id = $this->insertImage();
        $this->writeJpegVariant($id, 'sm');
        $r = $this->servePublic($id, 'sm', 'jpg');
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('image/jpeg', $r->getHeaderLine('Content-Type'));
    }

    public function testJxlVariantServedAsImageJxl(): void
    {
        $id = $this->insertImage();
        $bytes = $this->writeRawVariant($id, 'md', 'jxl', self::JXL_CODESTREAM);
        $r = $this->servePublic($id, 'md', 'jxl');
        self::assertSame(200, $r->getStatusCode(), 'a real .jxl must serve, not 404');
        self::assertSame('image/jxl', $r->getHeaderLine('Content-Type'));
        self::assertSame($bytes, (string) $r->getBody());
    }

    public function testJxlContainerSignatureIsAccepted(): void
    {
        $id = $this->insertImage();
        $this->writeRawVariant($id, 'md', 'jxl', self::JXL_CONTAINER);
        $r = $this->servePublic($id, 'md', 'jxl');
        self::assertSame(200, $r->getStatusCode(), 'ISO-BMFF "JXL " box must be accepted too');
        self::assertSame('image/jxl', $r->getHeaderLine('Content-Type'));
    }

    public function testSpoofedJxlBytesRejected(): void
    {
        // .jxl extension but JPEG magic bytes → strict gate must reject (the
        // extension is never trusted on DB-sourced paths).
        $id = $this->insertImage();
        $this->writeRawVariant($id, 'md', 'jxl', "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 32));
        self::assertSame(403, $this->servePublic($id, 'md', 'jxl')->getStatusCode());
    }

    public function testTruncatedJxlBytesRejected(): void
    {
        // A 1-byte file can't carry either JXL signature → reject, never 200.
        $id = $this->insertImage();
        $this->writeRawVariant($id, 'md', 'jxl', "\xFF");
        self::assertSame(403, $this->servePublic($id, 'md', 'jxl')->getStatusCode());
    }

    public function testEmptyJxlFileRejected(): void
    {
        $id = $this->insertImage();
        $this->writeRawVariant($id, 'md', 'jxl', '');
        // Empty file carries no JXL signature → same strict 403 gate as the
        // spoofed/truncated cases above.
        self::assertSame(403, $this->servePublic($id, 'md', 'jxl')->getStatusCode());
    }

    public function testUnknownExtensionDoesNotResolve(): void
    {
        // The public /media/{path} filename whitelist is jpg|webp|avif|jxl|png;
        // a .gif (or anything else) must 404, never stream.
        $id = $this->insertImage();
        $r = $this->controller()->servePublic(
            (new ServerRequestFactory())->createServerRequest('GET', "/media/{$id}_sm.gif"),
            new Response(),
            ['path' => "{$id}_sm.gif"]
        );
        self::assertSame(404, $r->getStatusCode());
    }

    public function testPublicJxlVariantIsCacheable(): void
    {
        $id = $this->insertImage();
        $this->writeRawVariant($id, 'md', 'jxl', self::JXL_CODESTREAM);
        $r = $this->servePublic($id, 'md', 'jxl');
        self::assertSame(200, $r->getStatusCode());
        self::assertStringStartsWith('public,', $r->getHeaderLine('Cache-Control'), 'public-album jxl is cacheable');
    }

    public function testProtectedJxlVariantQuarantinedAndNeverCacheable(): void
    {
        $id = $this->insertImage(nsfw: true);
        $this->writeRawVariant($id, 'md', 'jxl', self::JXL_CODESTREAM);
        $_SESSION['nsfw_confirmed_global'] = true;
        $r = $this->servePublic($id, 'md', 'jxl');
        self::assertSame(200, $r->getStatusCode());
        self::assertSame('image/jxl', $r->getHeaderLine('Content-Type'));
        self::assertStringContainsString('no-store', $r->getHeaderLine('Cache-Control'));
        self::assertFileExists($this->privatePath("{$id}_md.jxl"), 'protected jxl must be quarantined to protected-media');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function servePublic(int $id, string $variant, string $format)
    {
        return $this->controller()->servePublic(
            (new ServerRequestFactory())->createServerRequest('GET', "/media/{$id}_{$variant}.{$format}"),
            new Response(),
            ['path' => "{$id}_{$variant}.{$format}"]
        );
    }

    private function insertImage(int $albumId = 1, bool $nsfw = false): int
    {
        $id = $this->nextId++;
        $this->db->pdo()->prepare(
            'INSERT OR IGNORE INTO albums (id, slug, is_published, is_nsfw, password_hash, allow_downloads) VALUES (?,?,1,?,NULL,1)'
        )->execute([$albumId, 'album-' . $albumId, $nsfw ? 1 : 0]);
        $this->db->pdo()->prepare('INSERT INTO images (id, album_id, original_path, mime) VALUES (?,?,?,?)')
            ->execute([$id, $albumId, '/storage/originals/x.jpg', 'image/jpeg']);
        return $id;
    }

    private function writeRawVariant(int $id, string $variant, string $format, string $bytes): string
    {
        $dbPath = "/media/{$id}_{$variant}.{$format}";
        $path = $this->publicPath(basename($dbPath));
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $bytes);
        $this->createdFiles[] = $path;
        $this->db->pdo()->prepare(
            'INSERT INTO image_variants (image_id, variant, format, path, width, height, size_bytes) VALUES (?,?,?,?,16,16,?)'
        )->execute([$id, $variant, $format, $dbPath, strlen($bytes)]);
        return $bytes;
    }

    private function writeJpegVariant(int $id, string $variant): void
    {
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('imagejpeg')) {
            self::markTestSkipped('GD JPEG support required.');
        }
        $path = $this->publicPath("{$id}_{$variant}.jpg");
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $im = imagecreatetruecolor(16, 16);
        imagefilledrectangle($im, 0, 0, 15, 15, imagecolorallocate($im, 10, 20, 30));
        imagejpeg($im, $path, 85);
        imagedestroy($im);
        $this->createdFiles[] = $path;
        $this->db->pdo()->prepare(
            'INSERT INTO image_variants (image_id, variant, format, path, width, height, size_bytes) VALUES (?,?,?,?,16,16,?)'
        )->execute([$id, $variant, 'jpg', "/media/{$id}_{$variant}.jpg", filesize($path)]);
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
        $p = dirname(__DIR__, 2) . '/storage/protected-media/' . $basename;
        $this->createdFiles[] = $p;
        return $p;
    }
}
