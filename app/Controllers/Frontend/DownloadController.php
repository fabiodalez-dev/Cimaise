<?php

declare(strict_types=1);

namespace App\Controllers\Frontend;

use App\Controllers\BaseController;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DownloadController extends BaseController
{
    public function __construct(private readonly Database $db)
    {
        parent::__construct();
    }

    /**
     * Stream an original image as a download: resolve and confine the path under
     * storage/, validate it is an allowed raster type via finfo, sanitize the
     * filename, and emit it as a nosniff attachment with the detected MIME.
     *
     * @param array<string,mixed> $args
     */
    public function downloadImage(Request $request, Response $response, array $args, bool $adminDownload = false): Response
    {
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            return $response->withStatus(404);
        }

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT i.id, i.original_path, i.mime, a.id as album_id, a.allow_downloads, a.password_hash, a.is_nsfw, a.is_published
                               FROM images i JOIN albums a ON a.id = i.album_id WHERE i.id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return $response->withStatus(404);
        }
        // Unpublished albums must not leak their originals via image-id enumeration.
        $adminDownload = $adminDownload && $this->isAdmin();
        if (!$adminDownload && !(int)$row['is_published']) {
            return $response->withStatus(404);
        }
        if (!$adminDownload && !(int)$row['allow_downloads']) {
            return $response->withStatus(403);
        }

        // Check album password if present
        if (!$adminDownload && !empty($row['password_hash']) && !$this->hasAlbumPasswordAccess((int)$row['album_id'], (string)$row['password_hash'])) {
            return $response->withStatus(403);
        }

        // NSFW server-side enforcement: block downloads for unconfirmed NSFW albums
        // Admins bypass this check
        $isAdmin = $this->isAdmin();
        if ((bool)$row['is_nsfw'] && !$isAdmin && !$this->hasNsfwAlbumConsent((int)$row['album_id'])) {
            return $response->withStatus(403);
        }

        // Confined to the originals directories, wherever the stored path points.
        $realPath = \App\Services\OriginalImage::resolve((string)$row['original_path']);
        if ($realPath === null) {
            return $response->withStatus(404);
        }

        // Ensure it's an image file by MIME type validation
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $realPath);
        finfo_close($finfo);

        // Only raster formats the upload pipeline can actually produce. SVG is
        // intentionally excluded: it is script-capable and would enable stored
        // XSS if ever served inline. BMP/TIFF dropped as unsupported.
        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif',
        ];

        if (!in_array($detectedMime, $allowedMimes, true)) {
            return $response->withStatus(403);
        }

        // Emit the finfo-detected MIME, never the untrusted DB `mime` column,
        // so the Content-Type can never disagree with the validated file type.
        $mime = $detectedMime;

        // SECURITY: Comprehensive filename sanitization to prevent header injection
        $filename = basename($realPath);

        // Remove all potentially dangerous characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        // Remove control characters and line breaks that could cause header injection
        $filename = preg_replace('/[\x00-\x1F\x7F-\x9F]/', '', (string) $filename);

        // Remove quotes and other header-breaking characters
        $filename = str_replace(['"', "'", '\\', '\r', '\n', '\t'], '_', $filename);

        // Ensure filename is not empty and has reasonable length
        if (empty($filename) || strlen($filename) > 255) {
            $filename = 'download_' . $id . '.jpg'; // Default safe filename
        }

        // Additional safety: escape for HTTP header use
        $filename = addcslashes($filename, '"\\');

        // Validate final filename doesn't contain dangerous sequences
        if (str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\')) {
            $filename = 'secure_download_' . $id;
        }

        // Use safe streaming approach
        $filesize = filesize($realPath);
        $stream = fopen($realPath, 'rb');

        if (!$stream) {
            return $response->withStatus(500);
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

        $result = $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string)$filesize)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY');

        if ($adminDownload || !empty($row['password_hash']) || !empty($row['is_nsfw'])) {
            return $result
                ->withHeader('Cache-Control', 'private, no-store, max-age=0')
                ->withHeader('Pragma', 'no-cache')
                ->withHeader('X-Robots-Tag', 'noindex, noimageindex, noarchive');
        }

        return $result;
    }
}
