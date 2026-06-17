<?php

declare(strict_types=1);

use App\Controllers\Frontend\MediaController;
use App\Services\UploadService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Security regression suite for protected-media serving.
 *
 * Proves the access-control invariants for the three protection modes a
 * photographer can put on an album — NSFW-only, password-only, and both
 * combined — plus the cross-cutting boundaries (per-album scoping,
 * unpublished albums, path traversal, downloads-disabled originals).
 *
 * The invariants asserted here are the ones that must NOT regress as the
 * image pipeline (#109) and admin redesign evolve:
 *
 *   1. Sharp (non-blur) variants of a protected album are NEVER served to a
 *      visitor who has not cleared the album's gate(s). Denial returns 403
 *      (no blur, no original to fall back to) — never the real bytes.
 *   2. When access IS granted, the variant is quarantined to
 *      storage/protected-media/ (outside the web root) and served with
 *      Cache-Control: no-store + X-Robots-Tag: noimageindex — never a
 *      shared/public cache.
 *   3. Blur variants are always public (they back cover images), so a
 *      gate-less visitor still gets the blurred preview.
 *   4. Both gates are independent AND conjunctive: a "both" album requires
 *      the password session AND the NSFW consent; clearing only one is not
 *      enough.
 *   5. Access is scoped per-album: a session token for album A grants
 *      nothing for album B.
 *
 * Mirrors the in-memory-SQLite + real-controller pattern of
 * ProtectedMediaStorageTest so it exercises the actual MediaController
 * code path (no mocks of the access logic).
 */
final class MediaAccessSecurityTest extends TestCase
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
        $_COOKIE = [];
        $this->nextId = random_int(7_000_000, 7_900_000);
        $this->dbFile = sys_get_temp_dir() . '/cimaise_media_security_' . uniqid('', true) . '.sqlite';
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
        $_COOKIE = [];
        unset($this->db);
        // Single cleanup site for every path THIS test created: the test
        // media fixtures (public/media + storage/protected-media) and its own
        // temp sqlite files. None are user input.
        $cleanup = array_merge(
            $this->createdFiles,
            [$this->dbFile, $this->dbFile . '-wal', $this->dbFile . '-shm']
        );
        foreach ($cleanup as $file) {
            // nosemgrep: test-fixture cleanup; $file is a path this test itself
            // created (temp sqlite / public/media / storage/protected-media), never user input.
            @unlink($file); // nosemgrep
        }
    }

    // ── TEST 1 — NSFW-only album ──────────────────────────────────────────

    public function testNsfwOnlyAlbumGatesSharpVariantButAllowsBlurAndGrantsOnConsent(): void
    {
        $imageId = $this->insertImage(albumId: 1, nsfw: true);
        $sharpPublic = $this->writeVariant($imageId, 'sm', 'jpg');
        $sharpBytes = (string) file_get_contents($sharpPublic);

        // (a) Anonymous visitor, no consent → the sharp variant is gated. The
        // controller may answer with a generic blur placeholder (200) rather
        // than a 403, but it MUST NOT return the real sharp bytes.
        $denied = $this->servePublic($imageId, 'sm');
        $this->assertNoSharpLeak($denied, $sharpBytes, 'NSFW sharp variant without consent');

        // (b) The blur variant backs the cover image and must stay reachable
        // without consent (and stay public/cacheable).
        $this->writeVariant($imageId, 'blur', 'jpg');
        $blur = $this->servePublic($imageId, 'blur');
        self::assertSame(200, $blur->getStatusCode(), 'blur variant must be served without consent');
        self::assertStringStartsWith('public,', $blur->getHeaderLine('Cache-Control'), 'blur is a public cover asset');

        // (c) After NSFW consent, the sharp variant is served — quarantined to
        // private storage and never cacheable / never indexable.
        $_SESSION['nsfw_confirmed_global'] = true;
        $granted = $this->servePublic($imageId, 'sm');
        self::assertSame(200, $granted->getStatusCode());
        self::assertStringContainsString('no-store', $granted->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('noimageindex', $granted->getHeaderLine('X-Robots-Tag'));
        self::assertFileDoesNotExist($sharpPublic, 'sharp NSFW variant must be quarantined out of public/media');
        self::assertFileExists($this->privatePath("{$imageId}_sm.jpg"), 'sharp NSFW variant lives in protected-media');
    }

    // ── TEST 2 — password-only album ──────────────────────────────────────

    public function testPasswordOnlyAlbumGatesUntilSessionGrantedAndExpires(): void
    {
        $imageId = $this->insertImage(albumId: 1, passwordHash: password_hash('s3cret', PASSWORD_DEFAULT));
        $sharpPublic = $this->writeVariant($imageId, 'sm', 'jpg');
        $sharpBytes = (string) file_get_contents($sharpPublic);

        // (a) No unlock → gated; the real bytes are never served.
        $denied = $this->serveProtected($imageId, 'sm');
        $this->assertNoSharpLeak($denied, $sharpBytes, 'password sharp variant before unlock');

        // (b) Valid unlock session → served, private + no-store.
        $_SESSION['album_access'][1] = time();
        $granted = $this->serveProtected($imageId, 'sm');
        self::assertSame(200, $granted->getStatusCode());
        self::assertStringContainsString('no-store', $granted->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('no-cache', $granted->getHeaderLine('Pragma'));
        self::assertSame($sharpBytes, (string) $granted->getBody(), 'authorized viewer receives the real variant');

        // (c) Expired unlock (older than the 24h window) → gated again, and
        // the stale session entry is purged by the access check.
        $_SESSION['album_access'][1] = time() - 86_400 - 10;
        $expired = $this->serveProtected($imageId, 'sm');
        $this->assertNoSharpLeak($expired, $sharpBytes, 'expired unlock re-gates the album');
        self::assertArrayNotHasKey(1, $_SESSION['album_access'] ?? [], 'stale unlock entry is purged');
    }

    // ── TEST 3 — combined NSFW + password album ───────────────────────────

    public function testCombinedAlbumRequiresBothGatesConjunctively(): void
    {
        $imageId = $this->insertImage(
            albumId: 1,
            nsfw: true,
            passwordHash: password_hash('s3cret', PASSWORD_DEFAULT)
        );
        $sharpPublic = $this->writeVariant($imageId, 'sm', 'jpg');
        $sharpBytes = (string) file_get_contents($sharpPublic);

        // (a) Nothing cleared → gated, no sharp bytes.
        $this->assertNoSharpLeak($this->serveProtected($imageId, 'sm'), $sharpBytes, 'both gates closed');

        // (b) Password cleared, NSFW NOT → still gated (password is checked
        // first, then NSFW; clearing one is not enough).
        $_SESSION['album_access'][1] = time();
        $this->assertNoSharpLeak($this->serveProtected($imageId, 'sm'), $sharpBytes, 'password alone is not enough');

        // (c) NSFW cleared, password NOT → still gated.
        $_SESSION = [];
        $_SESSION['nsfw_confirmed_global'] = true;
        $this->assertNoSharpLeak($this->serveProtected($imageId, 'sm'), $sharpBytes, 'NSFW consent alone is not enough');

        // (d) Both cleared → served, private + no-store, real bytes.
        $_SESSION['album_access'][1] = time();
        $granted = $this->serveProtected($imageId, 'sm');
        self::assertSame(200, $granted->getStatusCode(), 'both gates cleared → serve');
        self::assertStringContainsString('no-store', $granted->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('noimageindex', $granted->getHeaderLine('X-Robots-Tag'));
        self::assertSame($sharpBytes, (string) $granted->getBody(), 'fully-authorized viewer receives the real variant');
    }

    // ── TEST 4 — cross-cutting security boundaries ────────────────────────

    public function testAccessBoundariesScopingUnpublishedTraversalAndDownloads(): void
    {
        // Two distinct protected albums.
        $imgA = $this->insertImage(albumId: 1, passwordHash: password_hash('a', PASSWORD_DEFAULT));
        $imgB = $this->insertImage(albumId: 2, passwordHash: password_hash('b', PASSWORD_DEFAULT));
        $sharpA = $this->writeVariant($imgA, 'sm', 'jpg');
        $sharpB = $this->writeVariant($imgB, 'sm', 'jpg');
        $sharpBytesA = (string) file_get_contents($sharpA);
        $sharpBytesB = (string) file_get_contents($sharpB);

        // (a) Per-album scoping: an unlock token for album 1 serves album 1
        // but grants NOTHING for album 2 (its sharp bytes never leak).
        $_SESSION['album_access'][1] = time();
        $servedA = $this->serveProtected($imgA, 'sm');
        self::assertSame(200, $servedA->getStatusCode(), 'album 1 token serves album 1');
        self::assertSame($sharpBytesA, (string) $servedA->getBody(), 'album 1 viewer gets album 1 bytes');
        $this->assertNoSharpLeak($this->serveProtected($imgB, 'sm'), $sharpBytesB, 'album 1 token must NOT serve album 2');

        // (b) Unpublished album → 404 even with a valid unlock token.
        $imgC = $this->insertImage(albumId: 3, passwordHash: password_hash('c', PASSWORD_DEFAULT), published: false);
        $this->writeVariant($imgC, 'sm', 'jpg');
        $_SESSION['album_access'][3] = time();
        self::assertSame(404, $this->serveProtected($imgC, 'sm')->getStatusCode(), 'unpublished album is 404 even when unlocked');

        // (c) Path traversal through the public route is rejected by the
        // filename whitelist (never resolves outside public/media).
        $traversal = $this->controller()->servePublic(
            (new ServerRequestFactory())->createServerRequest('GET', '/media/..%2f..%2f..%2fetc%2fpasswd'),
            new Response(),
            ['path' => '../../../etc/passwd']
        );
        self::assertGreaterThanOrEqual(400, $traversal->getStatusCode(), 'traversal path must not resolve to a file');

        // (d) allow_downloads=0: when the sharp variant is missing, the
        // controller must NOT hand the full-resolution original to a viewer
        // (that would bypass the downloads setting).
        $imgD = $this->insertImage(albumId: 4, passwordHash: password_hash('d', PASSWORD_DEFAULT), allowDownloads: false);
        // note: no 'lg' variant written → triggers the original-fallback path
        $_SESSION['album_access'][4] = time();
        $noDownload = $this->serveProtected($imgD, 'lg');
        self::assertSame(403, $noDownload->getStatusCode(), 'downloads-disabled album must not serve the original as a variant fallback');
    }

    // ── TEST 5 — JPEG-XL serving end-to-end (#109) ────────────────────────

    public function testJpegXlVariantServesWithImageJxlMimeAndStrictMagicCheck(): void
    {
        // (a) A genuine .jxl variant of a public album serves with the correct
        // Content-Type. The strict DB-path MIME gate must accept it via the
        // JXL magic bytes (libmagic often can't), not the extension alone.
        $imageId = $this->insertImage(albumId: 1);
        $jxlBytes = "\xFF\x0A" . str_repeat("\x00", 64); // bare JXL codestream signature
        $this->writeRawVariant($imageId, 'md', 'jxl', $jxlBytes);

        $served = $this->servePublic($imageId, 'md', 'jxl');
        self::assertSame(200, $served->getStatusCode(), 'public JPEG-XL variant must serve, not 404');
        self::assertSame('image/jxl', $served->getHeaderLine('Content-Type'), 'served with the JPEG-XL MIME type');
        self::assertSame($jxlBytes, (string) $served->getBody(), 'serves the real jxl bytes');

        // (b) A file with a .jxl extension but NON-JXL magic bytes must be
        // rejected by the strict magic-byte cross-check (extension is never
        // trusted on DB-sourced paths) → 403, not served as image/jxl.
        $imageId2 = $this->insertImage(albumId: 2);
        $this->writeRawVariant($imageId2, 'md', 'jxl', "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 64)); // JPEG magic, .jxl name
        $spoofed = $this->servePublic($imageId2, 'md', 'jxl');
        self::assertSame(403, $spoofed->getStatusCode(), 'a .jxl file whose bytes are not JXL must be rejected');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function writeRawVariant(int $imageId, string $variant, string $format, string $bytes): string
    {
        $dbPath = "/media/{$imageId}_{$variant}.{$format}";
        $path = $this->publicPath(basename($dbPath));
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $bytes);
        $this->createdFiles[] = $path;
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO image_variants (image_id, variant, format, path, width, height, size_bytes)
             VALUES (?, ?, ?, ?, 16, 16, ?)'
        );
        $stmt->execute([$imageId, $variant, $format, $dbPath, strlen($bytes)]);
        return $path;
    }

    /**
     * The core security invariant: a gated response may be a generic blur
     * placeholder (200) or a 403, but it must NEVER contain the real sharp
     * variant bytes. We compare the response body against the known sharp
     * bytes captured before the request.
     */
    private function assertNoSharpLeak(
        \Psr\Http\Message\ResponseInterface $response,
        string $sharpBytes,
        string $scenario
    ): void {
        $body = (string) $response->getBody();
        self::assertNotSame(
            $sharpBytes,
            $body,
            "{$scenario}: the real sharp variant bytes must never reach an ungated visitor"
        );
    }

    private function servePublic(int $imageId, string $variant, string $format = 'jpg')
    {
        return $this->controller()->servePublic(
            (new ServerRequestFactory())->createServerRequest('GET', "/media/{$imageId}_{$variant}.{$format}"),
            new Response(),
            ['path' => "{$imageId}_{$variant}.{$format}"]
        );
    }

    private function serveProtected(int $imageId, string $variant, string $format = 'jpg')
    {
        return $this->controller()->serveProtected(
            (new ServerRequestFactory())->createServerRequest('GET', "/media/protected/{$imageId}/{$variant}.{$format}"),
            new Response(),
            ['id' => $imageId, 'variant' => $variant, 'format' => $format]
        );
    }

    private function insertImage(
        int $albumId = 1,
        bool $nsfw = false,
        ?string $passwordHash = null,
        bool $published = true,
        bool $allowDownloads = true
    ): int {
        $imageId = $this->nextId++;
        $albumStmt = $this->db->pdo()->prepare(
            'INSERT OR IGNORE INTO albums (id, slug, is_published, is_nsfw, password_hash, allow_downloads)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $albumStmt->execute([
            $albumId,
            'album-' . $albumId,
            $published ? 1 : 0,
            $nsfw ? 1 : 0,
            $passwordHash,
            $allowDownloads ? 1 : 0,
        ]);

        // original_path points at a file that does not exist, so the denial
        // path cannot synthesize a blur on-demand and deterministically 403s.
        $imageStmt = $this->db->pdo()->prepare(
            'INSERT INTO images (id, album_id, original_path, mime) VALUES (?, ?, ?, ?)'
        );
        $imageStmt->execute([$imageId, $albumId, '/storage/originals/does-not-exist.jpg', 'image/jpeg']);
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
