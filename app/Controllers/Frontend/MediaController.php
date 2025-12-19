<?php
declare(strict_types=1);

namespace App\Controllers\Frontend;

use App\Controllers\BaseController;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves protected media files (images from password-protected or NSFW albums).
 * Validates session access before streaming files.
 */
class MediaController extends BaseController
{
    private const BLUR_CACHE_SECONDS = 3600; // 1 hour for blur variants

    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    /**
     * Stream a file to the response body and set common headers.
     * Returns null on failure (caller should return 500 response).
     */
    private function streamFile(Response $response, string $realPath, string $mime): ?Response
    {
        $filesize = filesize($realPath);
        $stream = fopen($realPath, 'rb');

        if (!$stream) {
            return null;
        }

        $body = $response->getBody();
        while (!feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                break;
            }
            $body->write($chunk);
        }
        fclose($stream);

        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Length', (string)$filesize)
            ->withHeader('X-Content-Type-Options', 'nosniff');
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
            $relativePath = ltrim($row['path'], '/');

            // Security: no traversal
            if (str_contains($relativePath, '..') || str_contains($relativePath, '\\')) {
                return null;
            }

            // Convert URL path to filesystem path
            if (str_starts_with($relativePath, 'media/')) {
                $filePath = "{$root}/public/{$relativePath}";
            } else {
                $filePath = "{$root}/{$relativePath}";
            }

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
     * Attempt to serve blur variant as fallback for NSFW albums.
     * Returns Response if blur served successfully, null otherwise.
     */
    private function tryServeBlurFallback(
        Request $request,
        Response $response,
        int $imageId,
        bool $isNsfw,
        string $currentVariant
    ): ?Response {
        // Only for NSFW albums and non-blur requests
        if (!$isNsfw || $currentVariant === 'blur') {
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

        // Stream the blur file
        $streamed = $this->streamFile($response, $blurPath, 'image/jpeg');
        if ($streamed === null) {
            return null;
        }

        // Use private cache for NSFW blur content (not public CDN cacheable)
        $filesize = filesize($blurPath);
        $etag = $this->generateEtag($blurPath, $filesize);
        $clientEtag = $request->getHeaderLine('If-None-Match');

        if ($clientEtag !== '' && $clientEtag === $etag) {
            return $response
                ->withStatus(304)
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'private, max-age=' . self::BLUR_CACHE_SECONDS . ', must-revalidate');
        }

        return $streamed
            ->withHeader('Cache-Control', 'private, max-age=' . self::BLUR_CACHE_SECONDS . ', must-revalidate')
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
        if (!preg_match('/^(sm|md|lg|xl|xxl|blur|thumb)$/', $variant)) {
            return $response->withStatus(400);
        }

        // Validate format
        if (!\in_array($format, ['jpg', 'webp', 'avif'], true)) {
            return $response->withStatus(400);
        }

        $pdo = $this->db->pdo();

        // Get image and album info
        $stmt = $pdo->prepare('
            SELECT i.id, i.album_id, a.password_hash, a.is_nsfw, a.is_published
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

        if (!$this->validateAlbumAccess($albumId, $isPasswordProtected, $isNsfw, $variant, true)) {
            return $response->withStatus(403);
        }

        // Get the variant path from database
        $variantStmt = $pdo->prepare('SELECT path FROM image_variants WHERE image_id = :id AND variant = :variant AND format = :format');
        $variantStmt->execute([':id' => $imageId, ':variant' => $variant, ':format' => $format]);
        $variantRow = $variantStmt->fetch();

        // Graceful fallback: if the requested variant is missing (except blur), serve the original file
        if (!$variantRow || empty($variantRow['path'])) {
            if ($variant === 'blur') {
                return $response->withStatus(404);
            }
            $origStmt = $pdo->prepare('SELECT original_path FROM images WHERE id = :id');
            $origStmt->execute([':id' => $imageId]);
            $origPath = $origStmt->fetchColumn();
            if (!$origPath) {
                return $response->withStatus(404);
            }
            $variantRow = ['path' => $origPath];
        }

        // Build file path - DB stores URL paths like /media/... which map to public/media/
        $root = dirname(__DIR__, 3);
        $relativePath = ltrim($variantRow['path'], '/');

        // SECURITY: Ensure path doesn't contain traversal sequences
        if (str_contains($relativePath, '..') || str_contains($relativePath, '\\')) {
            return $response->withStatus(403);
        }

        // Convert URL path to filesystem path (media/ -> public/media/)
        if (str_starts_with($relativePath, 'media/')) {
            $filePath = "{$root}/public/{$relativePath}";
        } else {
            $filePath = "{$root}/{$relativePath}";
        }
        $realPath = realpath($filePath);

        // Validate file exists and is within allowed directories
        $storageRoot = realpath("{$root}/storage/");
        $publicRoot = realpath("{$root}/public/");

        if (!$realPath || !is_file($realPath)) {
            // Last resort: try blur fallback for NSFW albums
            $blurResponse = $this->tryServeBlurFallback($request, $response, $imageId, $isNsfw, $variant);
            if ($blurResponse !== null) {
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

        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $realPath);
        finfo_close($finfo);

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/png' => 'png',
        ];

        if (!isset($allowedMimes[$detectedMime])) {
            return $response->withStatus(403);
        }

        $streamed = $this->streamFile($response, $realPath, $detectedMime);
        if ($streamed === null) {
            return $response->withStatus(500);
        }

        $filesize = filesize($realPath);
        $etag = $this->generateEtag($realPath, $filesize);
        $clientEtag = $request->getHeaderLine('If-None-Match');
        if ($clientEtag !== '' && $clientEtag === $etag) {
            return $response
                ->withStatus(304)
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'private, max-age=3600, must-revalidate');
        }

        // Cache headers for variants: shorter cache with ETag validation
        return $streamed
            ->withHeader('Cache-Control', 'private, max-age=3600, must-revalidate')
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
            // Password-protected album check (session stores timestamp, valid for 24h)
            if ($isPasswordProtected) {
                if (!$this->hasAlbumPasswordAccess($albumId)) {
                    return $response->withStatus(403);
                }
            }

            if ($isNsfw) {
                if (!$this->hasNsfwAlbumConsent($albumId)) {
                    return $response->withStatus(403);
                }
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

        // Validate MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $realPath);
        finfo_close($finfo);

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif', 'image/tiff'];
        if (!\in_array($detectedMime, $allowedMimes, true)) {
            return $response->withStatus(403);
        }

        $streamed = $this->streamFile($response, $realPath, $detectedMime);
        if ($streamed === null) {
            return $response->withStatus(500);
        }

        $filesize = filesize($realPath);
        $etag = $this->generateEtag($realPath, $filesize);
        $clientEtag = $request->getHeaderLine('If-None-Match');
        if ($clientEtag !== '' && $clientEtag === $etag) {
            return $response
                ->withStatus(304)
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'private, max-age=31536000, immutable');
        }

        // Cache headers for originals: long cache (originals are immutable)
        return $streamed
            ->withHeader('Cache-Control', 'private, max-age=31536000, immutable')
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
        if (str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return $response->withStatus(403);
        }

        // Parse filename to extract image ID
        // Format: {imageId}_{variant}.{format} or {imageId}_blur.{format}
        $filename = basename($path);
        if (!preg_match('/^(\d+)_([a-z]+)\.(jpg|webp|avif|png)$/i', $filename, $matches)) {
            // Not a variant file - could be uploads or other media
            // For non-variant files, serve directly (they're not protected)
            return $this->serveStaticFile($request, $response, $path);
        }

        $imageId = (int)$matches[1];
        $variant = $matches[2];
        // Note: $matches[3] contains format but is validated by regex pattern

        // Get image and album info
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('
            SELECT i.id, i.album_id, a.password_hash, a.is_nsfw, a.is_published
            FROM images i
            JOIN albums a ON a.id = i.album_id
            WHERE i.id = :id
        ');
        $stmt->execute([':id' => $imageId]);
        $row = $stmt->fetch();

        // If image not found in DB, serve static file (might be orphan or non-album media)
        if (!$row) {
            return $this->serveStaticFile($request, $response, $path);
        }

        // Unpublished albums - 404
        if (!$row['is_published']) {
            return $response->withStatus(404);
        }

        $albumId = (int)$row['album_id'];
        $isPasswordProtected = !empty($row['password_hash']);
        $isNsfw = (bool)$row['is_nsfw'];

        if (!$this->validateAlbumAccess($albumId, $isPasswordProtected, $isNsfw, $variant, true)) {
            return $response->withStatus(403);
        }

        // Access granted - serve the file
        return $this->serveStaticFile($request, $response, $path);
    }

    /**
     * Helper to serve a static file from public/media/
     */
    private function serveStaticFile(Request $request, Response $response, string $relativePath): Response
    {
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

        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $realPath);
        finfo_close($finfo);

        $allowedMimes = [
            'image/jpeg', 'image/webp', 'image/avif', 'image/png', 'image/gif',
        ];

        if (!\in_array($detectedMime, $allowedMimes, true)) {
            return $response->withStatus(403);
        }

        $filesize = filesize($realPath);
        $etag = $this->generateEtag($realPath, $filesize);
        $clientEtag = $request->getHeaderLine('If-None-Match');
        if ($clientEtag !== '' && $clientEtag === $etag) {
            return $response
                ->withStatus(304)
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'public, max-age=86400, must-revalidate');
        }

        $streamed = $this->streamFile($response, $realPath, $detectedMime);
        if ($streamed === null) {
            return $response->withStatus(500);
        }

        // Cache headers for public variants: use ETag for revalidation
        return $streamed
            ->withHeader('Cache-Control', 'public, max-age=86400, must-revalidate')
            ->withHeader('ETag', $etag);
    }

}
