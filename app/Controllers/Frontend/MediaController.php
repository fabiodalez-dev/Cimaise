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
        $isAdmin = $this->isAdmin();

        // Check access for protected albums
        if (!$isAdmin) {
            // Password-protected album check (session stores timestamp, valid for 24h)
            // Blur variant is always allowed for preview purposes
            if ($isPasswordProtected && $variant !== 'blur') {
                $accessTime = $_SESSION['album_access'][$albumId] ?? null;
                $hasAccess = $accessTime !== null && time() - (int)$accessTime < 86400;
                if (!$hasAccess) {
                    return $response->withStatus(403);
                }
            }

            // NSFW album check (blur variant is always allowed)
            if ($isNsfw && $variant !== 'blur') {
                $nsfwConfirmed = isset($_SESSION['nsfw_confirmed'][$albumId]) && $_SESSION['nsfw_confirmed'][$albumId] === true;
                if (!$nsfwConfirmed) {
                    return $response->withStatus(403);
                }
            }
        }

        // Get the variant path from database
        $variantStmt = $pdo->prepare('
            SELECT path FROM image_variants
            WHERE image_id = :id AND variant = :variant AND format = :format
        ');
        $variantStmt->execute([':id' => $imageId, ':variant' => $variant, ':format' => $format]);
        $variantRow = $variantStmt->fetch();

        if (!$variantRow || empty($variantRow['path'])) {
            return $response->withStatus(404);
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

        // Cache headers for variants: shorter cache with ETag validation
        $filesize = filesize($realPath);
        return $streamed
            ->withHeader('Cache-Control', 'private, max-age=3600, must-revalidate')
            ->withHeader('ETag', $this->generateEtag($realPath, $filesize));
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

        // Check if downloads are allowed for this album
        if (!(int)$row['allow_downloads']) {
            return $response->withStatus(403);
        }

        $albumId = (int)$row['album_id'];
        $isPasswordProtected = !empty($row['password_hash']);
        $isNsfw = (bool)$row['is_nsfw'];
        $isAdmin = $this->isAdmin();

        // Check access for protected albums
        if (!$isAdmin) {
            // Password-protected album check (session stores timestamp, valid for 24h)
            if ($isPasswordProtected) {
                $accessTime = $_SESSION['album_access'][$albumId] ?? null;
                $hasAccess = $accessTime !== null && time() - (int)$accessTime < 86400;
                if (!$hasAccess) {
                    return $response->withStatus(403);
                }
            }

            if ($isNsfw) {
                $nsfwConfirmed = isset($_SESSION['nsfw_confirmed'][$albumId]) && $_SESSION['nsfw_confirmed'][$albumId] === true;
                if (!$nsfwConfirmed) {
                    return $response->withStatus(403);
                }
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

        // Cache headers for originals: long cache (originals are immutable)
        return $streamed
            ->withHeader('Cache-Control', 'private, max-age=31536000, immutable');
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
            return $this->serveStaticFile($response, $path);
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
            return $this->serveStaticFile($response, $path);
        }

        // Unpublished albums - 404
        if (!$row['is_published']) {
            return $response->withStatus(404);
        }

        $albumId = (int)$row['album_id'];
        $isPasswordProtected = !empty($row['password_hash']);
        $isNsfw = (bool)$row['is_nsfw'];
        $isAdmin = $this->isAdmin();

        // Check access for protected albums
        if (!$isAdmin) {
            // Password-protected album check (session stores timestamp, valid for 24h)
            // Blur variant is always allowed for preview purposes
            if ($isPasswordProtected && $variant !== 'blur') {
                $accessTime = $_SESSION['album_access'][$albumId] ?? null;
                $hasAccess = $accessTime !== null && time() - (int)$accessTime < 86400;
                if (!$hasAccess) {
                    return $response->withStatus(403);
                }
            }

            // NSFW album check (blur variant is always allowed for preview)
            if ($isNsfw && $variant !== 'blur') {
                $nsfwConfirmed = isset($_SESSION['nsfw_confirmed'][$albumId]) && $_SESSION['nsfw_confirmed'][$albumId] === true;
                if (!$nsfwConfirmed) {
                    return $response->withStatus(403);
                }
            }
        }

        // Access granted - serve the file
        return $this->serveStaticFile($response, $path);
    }

    /**
     * Helper to serve a static file from public/media/
     */
    private function serveStaticFile(Response $response, string $relativePath): Response
    {
        $root = dirname(__DIR__, 3);
        $filePath = "{$root}/public/media/{$relativePath}";
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

        $streamed = $this->streamFile($response, $realPath, $detectedMime);
        if ($streamed === null) {
            return $response->withStatus(500);
        }

        // Cache headers for public variants: use ETag for revalidation
        $filesize = filesize($realPath);
        return $streamed
            ->withHeader('Cache-Control', 'public, max-age=86400, must-revalidate')
            ->withHeader('ETag', $this->generateEtag($realPath, $filesize));
    }
}
