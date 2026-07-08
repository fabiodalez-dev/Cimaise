<?php

declare(strict_types=1);

namespace App\Controllers\Frontend;

use App\Controllers\BaseController;
use App\Services\ProtectedMediaStorage;
use App\Services\UploadService;
use App\Support\BlurGenerationJob;
use App\Support\Database;
use App\Support\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves protected media files (images from password-protected or NSFW albums).
 * Validates session access before streaming files.
 */
class MediaController extends BaseController
{
    private const PUBLIC_CACHE_SECONDS = 31536000; // 1 year for public images
    private readonly ProtectedMediaStorage $protectedStorage;

    /**
     * Extension -> MIME map for images already validated by a whitelist regex.
     * Avoids a finfo_file() magic-byte read on every request when the extension
     * has already been constrained by the routing/regex check.
     */
    private const EXT_TO_MIME = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'jxl'  => 'image/jxl',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'tif'  => 'image/tiff',
        'tiff' => 'image/tiff',
    ];

    public function __construct(private readonly Database $db, private readonly UploadService $uploadService)
    {
        parent::__construct();
        $this->protectedStorage = new ProtectedMediaStorage($db);
    }

    /**
     * H2 (session lock contention): media requests run with the session either
     * never started (no session cookie => anonymous visitor) or started read-only
     * and immediately closed via session_write_close() in public/index.php.
     *
     * BaseController::ensureSession() would see session_status() === PHP_SESSION_NONE
     * after session_write_close() and re-start the session — re-acquiring the
     * exclusive session file lock and serializing the browser's parallel image
     * requests again. So for media serving this is intentionally a no-op:
     * $_SESSION is already populated (read-only) when a cookie was present, and
     * every read in BaseController is defensive (isset/??/empty), so a missing
     * session simply means "no admin, no password access, no NSFW consent".
     *
     * NOTE: this controller must never NEED to persist $_SESSION writes. The only
     * writes reachable from here (expired album_access cleanup, the
     * nsfw_confirmed_global memo derived from the signed cookie) are pure
     * in-request optimizations whose loss does not change access decisions.
     */
    protected function ensureSession(): void
    {
        // no-op by design — see docblock
    }

    /**
     * Resolve MIME from a trusted (regex-validated) file extension, falling back to
     * finfo_file() only when no mapping is available. The fallback path remains
     * available for callers that serve unconstrained file types.
     *
     * When $strict is true (defense-in-depth for DB-sourced paths), the extension-derived
     * MIME is cross-checked against finfo_file() magic-byte detection. Mismatches return
     * null so the caller can reject the request (typically 403). This guards against
     * cases where the DB stores a path with a misleading extension (e.g. a .jpg file
     * whose actual bytes are something else).
     */
    private function mimeFromExtension(string $realPath, bool $strict = false): ?string
    {
        $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $extMime = self::EXT_TO_MIME[$ext] ?? null;

        if ($extMime !== null && !$strict) {
            return $extMime;
        }

        // JPEG-XL (#109): many libmagic builds don't recognize JXL, so a
        // finfo_file() cross-check would spuriously fail the strict gate.
        // Verify the JXL magic bytes directly instead — still a real
        // magic-byte check (not extension trust), just one finfo can't do.
        if ($extMime === 'image/jxl') {
            return (!$strict || self::looksLikeJxl($realPath)) ? 'image/jxl' : null;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            // In strict mode we must be able to verify; without finfo we cannot.
            return $strict ? null : $extMime;
        }
        $detected = finfo_file($finfo, $realPath);
        finfo_close($finfo);

        if ($detected === false) {
            return $strict ? null : $extMime;
        }

        // Strict mode: require magic-byte MIME to match the extension-derived MIME.
        // image/jpeg and image/tiff have well-known aliases that finfo may emit.
        if ($strict && $extMime !== null) {
            $normalizedDetected = $detected === 'image/jpg' ? 'image/jpeg' : $detected;
            if ($normalizedDetected !== $extMime) {
                return null;
            }
            return $extMime;
        }

        return $extMime ?? $detected;
    }

    /**
     * Verify a file is a genuine JPEG-XL stream by its magic bytes, independent
     * of libmagic (which often lacks JXL support). Accepts both encodings:
     *   - bare codestream: starts with 0xFF 0x0A
     *   - ISO-BMFF container: the 12-byte "JXL " signature box
     */
    private static function looksLikeJxl(string $realPath): bool
    {
        $fh = @fopen($realPath, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = fread($fh, 12);
        fclose($fh);
        if ($head === false || strlen($head) < 2) {
            return false;
        }
        if (substr($head, 0, 2) === "\xFF\x0A") {
            return true; // bare codestream
        }
        return strlen($head) >= 12
            && $head === "\x00\x00\x00\x0C\x4A\x58\x4C\x20\x0D\x0A\x87\x0A"; // ISO-BMFF box
    }

    /**
     * Stream a file into the response body.
     *
     * Strategy in priority order:
     *   1. X-Sendfile / X-Accel-Redirect (Apache / Nginx) — kernel-level zero-copy.
     *      Enabled by setting MEDIA_XSENDFILE=apache or =nginx in env.
     *   2. PSR-7 stream wrap via fopen() — Slim writes the file straight from disk
     *      without buffering the whole body in PHP memory, so it scales to large
     *      originals (multi-MB) without RAM pressure and is faster than the old
     *      fread(8192) loop.
     */
    private function streamFile(Response $response, string $realPath, string $mime): ?Response
    {
        $filesize = filesize($realPath);
        if ($filesize === false) {
            return null;
        }

        $base = $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string)$filesize)
            ->withHeader('X-Content-Type-Options', 'nosniff');

        // Delegate streaming to the web server when configured. The web server
        // must be set up to expose the storage/ + public/media/ paths as
        // internal-only (Apache: XSendFilePath; Nginx: internal location).
        $xsendfile = strtolower((string)($_ENV['MEDIA_XSENDFILE'] ?? getenv('MEDIA_XSENDFILE') ?: ''));
        if ($xsendfile === 'apache') {
            return $base->withHeader('X-Sendfile', $realPath);
        }
        if ($xsendfile === 'nginx') {
            // Nginx X-Accel-Redirect expects an internal URI, not a filesystem path.
            // Map ROOT/public/media/foo.jpg -> /internal-media/foo.jpg and
            // ROOT/storage/originals/x.jpg -> /internal-originals/x.jpg.
            $root = dirname(__DIR__, 3);
            $publicMedia = $root . '/public/media/';
            $originals = $root . '/storage/originals/';
            if (str_starts_with($realPath, $publicMedia)) {
                return $base->withHeader('X-Accel-Redirect', '/internal-media/' . substr($realPath, strlen($publicMedia)));
            }
            if (str_starts_with($realPath, $originals)) {
                return $base->withHeader('X-Accel-Redirect', '/internal-originals/' . substr($realPath, strlen($originals)));
            }
            Logger::warning('MediaController: X-Sendfile=nginx configured but path does not match known prefixes; falling back to direct PSR-7 streaming', ['real_path' => $realPath]);
            // Path not under known roots: fall through to direct streaming
        }

        // Fallback: stream from disk via PSR-7 without buffering. Slim writes the
        // stream straight to the SAPI sink — equivalent in throughput to
        // readfile() but compatible with the middleware chain (PSR-7 contract).
        $fh = @fopen($realPath, 'rb');
        if ($fh === false) {
            return null;
        }

        return $base->withBody(new \Slim\Psr7\Stream($fh));
    }

    /**
     * Generate ETag for cache validation.
     */
    private function generateEtag(string $realPath, int $filesize): string
    {
        return '"' . md5($realPath . filemtime($realPath) . $filesize) . '"';
    }

    /**
     * Resolve the blur variant path for an image.
     * First checks database, then falls back to conventional path.
     *
     * @return string|null Filesystem path to blur file, or null if not found
     */
    private function resolveBlurPath(int $imageId): ?string
    {
        $root = dirname(__DIR__, 3);
        $pdo = $this->db->pdo();

        // Try database first
        $stmt = $pdo->prepare('SELECT path FROM image_variants WHERE image_id = :id AND variant = :variant AND format = :format');
        $stmt->execute([':id' => $imageId, ':variant' => 'blur', ':format' => 'jpg']);
        $row = $stmt->fetch();

        if ($row && !empty($row['path'])) {
            $relativePath = ltrim((string) $row['path'], '/');

            // Security: no traversal
            if (str_contains($relativePath, '..') || str_contains($relativePath, '\\')) {
                return null;
            }

            // Convert URL path to filesystem path
            $filePath = str_starts_with($relativePath, 'media/') ? "{$root}/public/{$relativePath}" : "{$root}/{$relativePath}";

            $realPath = realpath($filePath);
            if ($realPath && is_file($realPath)) {
                return $realPath;
            }
        }

        // Fallback to conventional path: public/media/{imageId}_blur.jpg
        $conventionalPath = "{$root}/public/media/{$imageId}_blur.jpg";
        if (is_file($conventionalPath)) {
            $realPath = realpath($conventionalPath);
            if ($realPath) {
                return $realPath;
            }
        }

        return null;
    }

    /**
     * Enqueue blur variant generation for protected albums.
     * Returns a placeholder URL path while the background job runs, or null on failure.
     */
    private function generateBlurOnDemand(int $imageId): ?string
    {
        $dispatched = false;
        try {
            $dispatched = BlurGenerationJob::dispatch($imageId);
        } catch (\Throwable $e) {
            Logger::warning('Failed to enqueue blur generation job', [
                'image_id' => $imageId,
                'error' => $e->getMessage(),
            ], 'media');
        }

        if (!$dispatched) {
            Logger::warning('Blur generation job dispatch failed', [
                'image_id' => $imageId,
            ], 'media');
        }

        $placeholder = $this->uploadService->ensureBlurPlaceholder();
        if ($placeholder === null) {
            Logger::warning('Failed to resolve blur placeholder', [
                'image_id' => $imageId,
            ], 'media');
        }

        return $placeholder;
    }

    /**
     * Attempt to serve blur variant as fallback for protected albums (password or NSFW).
     * Returns Response if blur served successfully, null otherwise.
     */
    private function tryServeBlurFallback(
        Request $request,
        Response $response,
        int $imageId,
        bool $isProtected,
        string $currentVariant
    ): ?Response {
        // Only for protected albums and non-blur requests
        if (!$isProtected || $currentVariant === 'blur') {
            return null;
        }

        $blurPath = $this->resolveBlurPath($imageId);
        if ($blurPath === null) {
            return null;
        }

        // Validate path is within allowed directories
        $root = dirname(__DIR__, 3);
        $storageRoot = realpath("{$root}/storage/");
        $publicRoot = realpath("{$root}/public/");

        $inStorage = $storageRoot && str_starts_with($blurPath, $storageRoot . DIRECTORY_SEPARATOR);
        $inPublicMedia = $publicRoot && str_starts_with($blurPath, $publicRoot . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR);

        if (!$inStorage && !$inPublicMedia) {
            return null;
        }

        // Check ETag BEFORE streaming (avoid writing body for 304 responses)
        $filesize = filesize($blurPath);
        if ($filesize === false) {
            return null;
        }
        $etag = $this->generateEtag($blurPath, $filesize);
        $clientEtag = $request->getHeaderLine('If-None-Match');

        // This response substitutes blur bytes UNDER THE SHARP VARIANT URL for a
        // visitor who is (currently) denied access. It must never be cached:
        // a cached (worse: immutable) copy would keep showing the blur for that
        // URL even after the visitor unlocks the album / gives NSFW consent.
        // The blur served at its own {id}_blur.jpg URL stays cacheable as usual.
        if ($clientEtag !== '' && $clientEtag === $etag) {
            return $response
                ->withStatus(304)
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'private, no-store, max-age=0')
                ->withHeader('Pragma', 'no-cache');
        }

        // Stream the blur file
        $streamed = $this->streamFile($response, $blurPath, 'image/jpeg');
        if (!$streamed instanceof \Psr\Http\Message\ResponseInterface) {
            return null;
        }

        return $streamed
            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('ETag', $etag);
    }

    /**
     * Serve a protected image variant.
     * Route: /media/protected/{id}/{variant}.{format}
     */
    public function serveProtected(Request $request, Response $response, array $args): Response
    {
        $imageId = (int)($args['id'] ?? 0);
        $variant = $args['variant'] ?? '';
        $format = $args['format'] ?? 'jpg';

        if ($imageId <= 0 || empty($variant)) {
            return $response->withStatus(404);
        }

        // Validate variant name (prevent path traversal)
        // Only allow actual generated variants: sm, md, lg, xl, xxl, preview, blur
        if (!preg_match('/^(sm|md|lg|xl|xxl|preview|blur)$/', (string) $variant)) {
            return $response->withStatus(400);
        }

        // Validate format
        if (!\in_array($format, ['jpg', 'webp', 'avif', 'jxl'], true)) {
            return $response->withStatus(400);
        }

        $pdo = $this->db->pdo();

        // Get image and album info
        $stmt = $pdo->prepare('
            SELECT i.id, i.album_id, a.password_hash, a.is_nsfw, a.is_published, a.allow_downloads
            FROM images i
            JOIN albums a ON a.id = i.album_id
            WHERE i.id = :id
        ');
        $stmt->execute([':id' => $imageId]);
        $row = $stmt->fetch();

        if (!$row || !$row['is_published']) {
            return $response->withStatus(404);
        }

        $albumId = (int)$row['album_id'];
        $isPasswordProtected = !empty($row['password_hash']);
        $isNsfw = (bool)$row['is_nsfw'];

        $accessResult = $this->validateAlbumAccess($albumId, $isPasswordProtected, $isNsfw, $variant, true);
        if ($accessResult !== true) {
            // Try blur fallback for protected albums (password or NSFW)
            $blurResponse = $this->tryServeBlurFallback($request, $response, $imageId, $isPasswordProtected || $isNsfw, $variant);
            if ($blurResponse instanceof \Psr\Http\Message\ResponseInterface) {
                return $blurResponse;
            }

            // No blur variant available: dispatch on-demand generation and serve placeholder
            // Covers BOTH 'password' AND 'nsfw' access-denial branches.
            if ($accessResult === 'password' || $accessResult === 'nsfw') {
                $placeholderUrl = $this->generateBlurOnDemand($imageId);
                if ($placeholderUrl !== null) {
                    $placeholderResponse = $this->serveStaticFile($request, $response, ltrim($placeholderUrl, '/'));
                    if ($placeholderResponse->getStatusCode() < 400) {
                        // Denial response served under the sharp variant URL:
                        // never cacheable, or the browser would keep showing the
                        // placeholder after the visitor unlocks the album.
                        return $placeholderResponse
                            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
                            ->withHeader('Pragma', 'no-cache');
                    }
                }
            }

            return $response->withStatus(403);
        }

        // Get the variant path from database
        $variantStmt = $pdo->prepare('SELECT path FROM image_variants WHERE image_id = :id AND variant = :variant AND format = :format');
        $variantStmt->execute([':id' => $imageId, ':variant' => $variant, ':format' => $format]);
        $variantRow = $variantStmt->fetch();

        // Graceful fallback: if the requested variant is missing (except blur), serve the original file
        if (!$variantRow || empty($variantRow['path'])) {
            if ($variant === 'blur') {
                // Try to generate blur on-demand for protected albums
                $blurPath = $this->generateBlurOnDemand($imageId);
                if ($blurPath !== null) {
                    $variantRow = ['path' => $blurPath];
                } else {
                    return $response->withStatus(404);
                }
            } else {
                // For non-blur variants, fallback to original — but never hand the
                // full-resolution original to a viewer of a downloads-disabled album
                // (that would bypass allow_downloads while a variant is still pending).
                if (!$this->isAdmin() && empty($row['allow_downloads'])) {
                    return $response->withStatus(403);
                }
                $origStmt = $pdo->prepare('SELECT original_path FROM images WHERE id = :id');
                $origStmt->execute([':id' => $imageId]);
                $origPath = $origStmt->fetchColumn();
                if (!$origPath) {
                    return $response->withStatus(404);
                }
                $variantRow = ['path' => $origPath];
            }
        }

        // Build file path. Sharp variants for protected albums live outside
        // public/; blur variants remain public and are safe to expose.
        $root = dirname(__DIR__, 3);
        $relativePath = ltrim((string) $variantRow['path'], '/');

        // SECURITY: Ensure path doesn't contain traversal sequences
        if (str_contains($relativePath, '..') || str_contains($relativePath, '\\')) {
            return $response->withStatus(403);
        }

        if ($variant !== 'blur' && str_starts_with($relativePath, 'media/')) {
            $realPath = $this->protectedStorage->resolveVariantPath(
                (string)$variantRow['path'],
                $isPasswordProtected || $isNsfw
            );
        } else {
            // Convert URL path to filesystem path (media/ -> public/media/)
            $filePath = str_starts_with($relativePath, 'media/')
                ? "{$root}/public/{$relativePath}"
                : "{$root}/{$relativePath}";
            $realPath = realpath($filePath) ?: false;
        }

        // Validate file exists and is within allowed directories
        $storageRoot = realpath("{$root}/storage/");
        $publicRoot = realpath("{$root}/public/");

        if (!$realPath || !is_file($realPath)) {
            // Last resort: try blur fallback for NSFW albums
            $blurResponse = $this->tryServeBlurFallback($request, $response, $imageId, $isPasswordProtected || $isNsfw, $variant);
            if ($blurResponse instanceof \Psr\Http\Message\ResponseInterface) {
                return $blurResponse;
            }
            return $response->withStatus(404);
        }

        // Allow files in storage/ or public/media/
        $inStorage = $storageRoot && str_starts_with($realPath, $storageRoot . DIRECTORY_SEPARATOR);
        $inPublicMedia = $publicRoot && str_starts_with($realPath, $publicRoot . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR);

        if (!$inStorage && !$inPublicMedia) {
            return $response->withStatus(403);
        }

        // Resolve MIME from the (regex-validated) extension. Strict mode performs
        // a finfo_file() magic-byte cross-check because the path is DB-sourced.
        $detectedMime = $this->mimeFromExtension($realPath, strict: true);

        $allowedMimes = ['image/jpeg', 'image/webp', 'image/avif', 'image/jxl', 'image/png'];
        if ($detectedMime === null || !\in_array($detectedMime, $allowedMimes, true)) {
            return $response->withStatus(403);
        }

        // Check ETag BEFORE streaming to avoid sending body on 304 responses
        $filesize = filesize($realPath);
        if ($filesize === false) {
            return $response->withStatus(500);
        }
        $etag = $this->generateEtag($realPath, $filesize);
        $clientEtag = $request->getHeaderLine('If-None-Match');
        if ($clientEtag !== '' && $clientEtag === $etag) {
            return $response
                ->withStatus(304)
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'private, no-store, max-age=0')
                ->withHeader('Pragma', 'no-cache')
                ->withHeader('X-Robots-Tag', 'noindex, noimageindex, noarchive');
        }

        $streamed = $this->streamFile($response, $realPath, $detectedMime);
        if (!$streamed instanceof \Psr\Http\Message\ResponseInterface) {
            return $response->withStatus(500);
        }

        // Never retain authorized protected bytes after the access grant expires
        // and never allow a shared cache/CDN to reuse them for another visitor.
        return $streamed
            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('X-Robots-Tag', 'noindex, noimageindex, noarchive')
            ->withHeader('ETag', $etag);
    }

    /**
     * Serve original image for protected albums.
     * Route: /media/protected/{id}/original
     */
    public function serveOriginal(Request $request, Response $response, array $args): Response
    {
        $imageId = (int)($args['id'] ?? 0);

        if ($imageId <= 0) {
            return $response->withStatus(404);
        }

        $pdo = $this->db->pdo();

        // Get image and album info
        $stmt = $pdo->prepare('
            SELECT i.id, i.original_path, i.mime, i.album_id, a.password_hash, a.is_nsfw, a.is_published, a.allow_downloads
            FROM images i
            JOIN albums a ON a.id = i.album_id
            WHERE i.id = :id
        ');
        $stmt->execute([':id' => $imageId]);
        $row = $stmt->fetch();

        if (!$row || !$row['is_published']) {
            return $response->withStatus(404);
        }

        $albumId = (int)$row['album_id'];
        $isPasswordProtected = !empty($row['password_hash']);
        $isNsfw = (bool)$row['is_nsfw'];
        $isAdmin = $this->isAdmin();

        // Check access for protected albums
        if (!$isAdmin) {
            $accessResult = $this->validateAlbumAccess($albumId, $isPasswordProtected, $isNsfw, 'original');
            if ($accessResult !== true) {
                return $response->withStatus(403);
            }

            // Check if downloads are allowed for this album
            if (!$row['allow_downloads']) {
                return $response->withStatus(403);
            }
        }

        // Build file path
        $root = dirname(__DIR__, 3);
        $originalPath = (string)$row['original_path'];

        // SECURITY: Path traversal prevention
        $originalPath = str_replace(['../', '..\\', '/../', '\\..\\'], '', $originalPath);
        if (str_contains($originalPath, '..')) {
            return $response->withStatus(403);
        }

        $filePath = "{$root}/" . ltrim($originalPath, '/');
        $realPath = realpath($filePath);

        $storageRoot = realpath("{$root}/storage/");
        if (!$realPath || !$storageRoot || !str_starts_with($realPath, $storageRoot . DIRECTORY_SEPARATOR)) {
            return $response->withStatus(403);
        }

        if (!is_file($realPath)) {
            return $response->withStatus(404);
        }

        // Validate MIME — strict mode performs a finfo_file() magic-byte cross-check
        // because the original_path is DB-sourced and the extension cannot be trusted.
        $detectedMime = $this->mimeFromExtension($realPath, strict: true);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif', 'image/tiff'];
        if ($detectedMime === null || !\in_array($detectedMime, $allowedMimes, true)) {
            return $response->withStatus(403);
        }

        // Check ETag BEFORE streaming to avoid sending body on 304 responses
        $filesize = filesize($realPath);
        if ($filesize === false) {
            return $response->withStatus(500);
        }
        $etag = $this->generateEtag($realPath, $filesize);
        $clientEtag = $request->getHeaderLine('If-None-Match');
        if ($clientEtag !== '' && $clientEtag === $etag) {
            return $response
                ->withStatus(304)
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'private, no-store, max-age=0')
                ->withHeader('Pragma', 'no-cache')
                ->withHeader('X-Robots-Tag', 'noindex, noimageindex, noarchive');
        }

        $streamed = $this->streamFile($response, $realPath, $detectedMime);
        if (!$streamed instanceof \Psr\Http\Message\ResponseInterface) {
            return $response->withStatus(500);
        }

        // Originals are never cached client-side: they may belong to protected
        // albums and access is re-checked on every request.
        return $streamed
            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('X-Robots-Tag', 'noindex, noimageindex, noarchive')
            ->withHeader('ETag', $etag);
    }

    /**
     * Serve public media files with protection check.
     * Route: /media/{path}
     *
     * This intercepts ALL /media/ requests and validates access for protected albums
     * before serving the file. For public albums, files are served directly.
     */
    public function servePublic(Request $request, Response $response, array $args): Response
    {
        $path = $args['path'] ?? '';

        if (empty($path)) {
            return $response->withStatus(404);
        }

        // SECURITY: Prevent path traversal
        if (str_contains((string) $path, '..') || str_contains((string) $path, '\\') || str_starts_with((string) $path, '/')) {
            return $response->withStatus(403);
        }

        // Parse filename to extract image ID
        // Format: {imageId}_{variant}.{format} or {imageId}_blur.{format}
        $filename = basename((string) $path);
        if (!preg_match('/^(\d+)_([a-z0-9_-]+)\.(jpg|webp|avif|jxl|png)$/i', $filename, $matches)) {
            // Any numeric-prefixed media filename could be an album variant
            // from an older/custom generator. Never let it fall through to
            // unauthenticated static serving merely because its shape changed.
            if (preg_match('/^\d+_/', $filename)) {
                return $response->withStatus(404);
            }

            // Not a variant file - could be uploads or other media
            // For non-variant files, serve directly (they're not protected)
            return $this->serveStaticFile($request, $response, $path);
        }

        $imageId = (int)$matches[1];
        $variant = strtolower($matches[2]);
        $format = strtolower($matches[3]); // regex-validated: jpg|webp|avif|jxl|png

        // Get image and album info
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('
            SELECT i.id, i.album_id, a.password_hash, a.is_nsfw, a.is_published, a.allow_downloads
            FROM images i
            JOIN albums a ON a.id = i.album_id
            WHERE i.id = :id
        ');
        $stmt->execute([':id' => $imageId]);
        $row = $stmt->fetch();

        // Numeric variant files are album media. Orphans must fail closed:
        // serving them directly would bypass publication/password/NSFW checks.
        if (!$row) {
            return $response->withStatus(404);
        }

        // Unpublished albums - 404
        if (!$row['is_published']) {
            return $response->withStatus(404);
        }

        $albumId = (int)$row['album_id'];
        $isPasswordProtected = !empty($row['password_hash']);
        $isNsfw = (bool)$row['is_nsfw'];

        $accessResult = $this->validateAlbumAccess($albumId, $isPasswordProtected, $isNsfw, $variant, true);
        if ($accessResult !== true) {
            // Try blur fallback for protected albums (password or NSFW)
            $blurResponse = $this->tryServeBlurFallback($request, $response, $imageId, $isPasswordProtected || $isNsfw, $variant);
            if ($blurResponse instanceof \Psr\Http\Message\ResponseInterface) {
                return $blurResponse;
            }

            // No blur variant available: dispatch on-demand generation and serve placeholder
            // Covers BOTH 'password' AND 'nsfw' access-denial branches so listing pages
            // (which use cover images of protected albums) never return 403.
            if ($accessResult === 'password' || $accessResult === 'nsfw') {
                $placeholderUrl = $this->generateBlurOnDemand($imageId);
                if ($placeholderUrl !== null) {
                    $placeholderResponse = $this->serveStaticFile($request, $response, ltrim($placeholderUrl, '/'));
                    if ($placeholderResponse->getStatusCode() < 400) {
                        // Denial response served under the sharp variant URL:
                        // never cacheable, or the browser would keep showing the
                        // placeholder after the visitor unlocks the album.
                        return $placeholderResponse
                            ->withHeader('Cache-Control', 'private, no-store, max-age=0')
                            ->withHeader('Pragma', 'no-cache');
                    }
                }
            }

            return $response->withStatus(403);
        }

        $isProtectedVariant = ($isPasswordProtected || $isNsfw) && $variant !== 'blur';

        if ($variant !== 'blur') {
            $variantStmt = $pdo->prepare(
                'SELECT path FROM image_variants WHERE image_id = :id AND variant = :variant AND format = :format'
            );
            $variantStmt->execute([':id' => $imageId, ':variant' => $variant, ':format' => $format]);
            $dbPath = $variantStmt->fetchColumn();
            $realPath = $dbPath
                ? $this->protectedStorage->resolveVariantPath((string)$dbPath, $isProtectedVariant)
                : null;

            // Access granted. If the variant is genuinely missing (rather than
            // merely awaiting quarantine from public/), generate it on demand.
            if ($realPath === null) {
                $this->ensureVariantGenerated($imageId, $variant, $format, $path, $isProtectedVariant);
                $variantStmt->execute([':id' => $imageId, ':variant' => $variant, ':format' => $format]);
                $dbPath = $variantStmt->fetchColumn();
                $realPath = $dbPath
                    ? $this->protectedStorage->resolveVariantPath((string)$dbPath, $isProtectedVariant)
                    : null;
            }

            if ($realPath === null) {
                return $response->withStatus(404);
            }
            return $this->serveResolvedFile($request, $response, $realPath, $isProtectedVariant);
        }

        return $this->serveStaticFile($request, $response, $path);
    }

    /** Variant sizes that can be (re)generated on demand from the original. */
    private const ON_DEMAND_VARIANTS = ['sm', 'md', 'lg', 'xl', 'xxl'];

    /**
     * Lazily generate ONLY the requested variant+format when the requested file
     * is missing (M4). A full generateVariantsForImage() run is up to 5 sizes ×
     * 3 formats (~15 encodes; AVIF alone can take seconds) — far too much work
     * inside a single image request. Full multi-variant generation still happens
     * on the upload path and via VariantMaintenanceService (cron).
     *
     * Concurrency: a per-image lock file (storage/tmp/variant-locks/{id}.lock)
     * guarded by a BLOCKING flock() prevents N parallel requests from encoding
     * the same image N times. The wait is bounded by one variant+format encode;
     * after acquiring the lock the file is re-checked, so waiters typically find
     * the file already generated and serve it directly. If the lock file cannot
     * be opened, generation proceeds unlocked (best-effort, same as before).
     *
     * Idempotent and best-effort: any failure is logged, not fatal
     * (serveStaticFile then returns its normal 404).
     */
    private function ensureVariantGenerated(
        int $imageId,
        string $variant,
        string $format,
        string $relativePath,
        bool $protected
    ): void {
        if (!\in_array($variant, self::ON_DEMAND_VARIANTS, true)) {
            return; // blur / unknown handled elsewhere
        }
        if ($this->variantFileExists($relativePath, $protected)) {
            return;
        }

        $lockDir = dirname(__DIR__, 3) . '/storage/tmp/variant-locks';
        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }
        // $imageId is an int — no traversal risk in the lock filename.
        $lockHandle = @fopen("{$lockDir}/{$imageId}.lock", 'c');
        $locked = $lockHandle !== false && flock($lockHandle, LOCK_EX);

        try {
            // Re-check under the lock: another request may have generated the
            // variant while we were waiting on flock().
            if (!$locked || !$this->variantFileExists($relativePath, $protected)) {
                $this->uploadService->generateVariantsForImage($imageId, false, $variant, $format);
            }
        } catch (\Throwable $e) {
            Logger::warning('MediaController: on-demand variant generation failed', [
                'image_id' => $imageId,
                'variant' => $variant,
                'format' => $format,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($lockHandle !== false) {
                if ($locked) {
                    flock($lockHandle, LOCK_UN);
                }
                fclose($lockHandle);
            }
        }
    }

    /**
     * True if the variant file already exists under public/media/.
     *
     * @phpstan-impure Filesystem state can change between calls (another process
     *                 may generate the variant while this request waits on flock).
     */
    private function variantFileExists(string $relativePath, bool $protected): bool
    {
        $cleanRel = ltrim($relativePath, '/');
        if (str_starts_with($cleanRel, 'media/')) {
            $cleanRel = substr($cleanRel, strlen('media/'));
        }
        // Reject traversal before touching the filesystem; treat a bad path as
        // "exists" so we never attempt generation for it.
        if ($cleanRel === '' || str_contains($cleanRel, '..')) {
            return true;
        }
        $dir = $this->protectedStorage->directoryForProtection($protected);
        return is_file($dir . '/' . $cleanRel);
    }

    private function serveResolvedFile(
        Request $request,
        Response $response,
        string $realPath,
        bool $protected
    ): Response {
        $detectedMime = $this->mimeFromExtension($realPath, strict: true);
        if ($detectedMime === null || !\in_array($detectedMime, ['image/jpeg', 'image/webp', 'image/avif', 'image/jxl', 'image/png'], true)) {
            return $response->withStatus(403);
        }

        $filesize = filesize($realPath);
        if ($filesize === false) {
            return $response->withStatus(500);
        }
        $etag = $this->generateEtag($realPath, $filesize);
        if ($request->getHeaderLine('If-None-Match') === $etag) {
            $notModified = $response->withStatus(304)->withHeader('ETag', $etag);
            return $protected
                ? $notModified
                    ->withHeader('Cache-Control', 'private, no-store, max-age=0')
                    ->withHeader('Pragma', 'no-cache')
                    ->withHeader('X-Robots-Tag', 'noindex, noimageindex, noarchive')
                : $notModified->withHeader('Cache-Control', 'public, max-age=' . self::PUBLIC_CACHE_SECONDS . ', immutable');
        }

        $streamed = $this->streamFile($response, $realPath, $detectedMime);
        if (!$streamed instanceof \Psr\Http\Message\ResponseInterface) {
            return $response->withStatus(500);
        }

        if ($protected) {
            return $streamed
                ->withHeader('Cache-Control', 'private, no-store, max-age=0')
                ->withHeader('Pragma', 'no-cache')
                ->withHeader('X-Robots-Tag', 'noindex, noimageindex, noarchive')
                ->withHeader('ETag', $etag);
        }

        return $streamed
            ->withHeader('Cache-Control', 'public, max-age=' . self::PUBLIC_CACHE_SECONDS . ', immutable')
            ->withHeader('ETag', $etag);
    }

    /**
     * Helper to serve a static file from public/media/
     */
    private function serveStaticFile(Request $request, Response $response, string $relativePath, bool $isProtected = false): Response
    {
        // Password-gated content must not be stored by SHARED caches (proxies, CDNs)
        // that would then serve it to other users without re-checking the gate.
        // Public gallery images stay `public` so a CDN can cache them aggressively.
        $cacheVisibility = $isProtected ? 'private' : 'public';
        $root = dirname(__DIR__, 3);
        // Accept either "1_blur.jpg" or "media/1_blur.jpg"
        $cleanRel = ltrim($relativePath, '/');
        if (str_starts_with($cleanRel, 'media/')) {
            $cleanRel = substr($cleanRel, strlen('media/'));
        }
        $filePath = "{$root}/public/media/{$cleanRel}";
        $realPath = realpath($filePath);

        // Validate file exists and is within public/media/
        $mediaRoot = realpath("{$root}/public/media/");
        if (!$realPath || !$mediaRoot || !str_starts_with($realPath, $mediaRoot . DIRECTORY_SEPARATOR)) {
            return $response->withStatus(404);
        }

        if (!is_file($realPath)) {
            return $response->withStatus(404);
        }

        // Validate MIME type via fast extension lookup; finfo only if unknown extension
        $detectedMime = $this->mimeFromExtension($realPath);
        $allowedMimes = ['image/jpeg', 'image/webp', 'image/avif', 'image/jxl', 'image/png', 'image/gif'];
        if ($detectedMime === null || !\in_array($detectedMime, $allowedMimes, true)) {
            return $response->withStatus(403);
        }

        $filesize = filesize($realPath);
        if ($filesize === false) {
            return $response->withStatus(500);
        }
        $etag = $this->generateEtag($realPath, $filesize);
        $clientEtag = $request->getHeaderLine('If-None-Match');
        if ($clientEtag !== '' && $clientEtag === $etag) {
            return $response
                ->withStatus(304)
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', $cacheVisibility . ', max-age=' . self::PUBLIC_CACHE_SECONDS . ', immutable');
        }

        $streamed = $this->streamFile($response, $realPath, $detectedMime);
        if (!$streamed instanceof \Psr\Http\Message\ResponseInterface) {
            return $response->withStatus(500);
        }

        // Aggressive 1-year cache (variants have unique filenames); visibility is
        // `private` for password-gated content, `public` for open gallery images.
        return $streamed
            ->withHeader('Cache-Control', $cacheVisibility . ', max-age=' . self::PUBLIC_CACHE_SECONDS . ', immutable')
            ->withHeader('ETag', $etag);
    }

}
