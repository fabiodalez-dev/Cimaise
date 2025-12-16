<?php
declare(strict_types=1);

namespace App\Controllers\Frontend;
use App\Controllers\BaseController;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DownloadController extends BaseController
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    public function downloadImage(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) return $response->withStatus(404);
        
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT i.id, i.original_path, i.mime, a.id as album_id, a.allow_downloads, a.password_hash, a.is_nsfw
                               FROM images i JOIN albums a ON a.id = i.album_id WHERE i.id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if (!$row) return $response->withStatus(404);
        if (!(int)$row['allow_downloads']) return $response->withStatus(403);

        // Check album password if present
        if (!empty($row['password_hash'])) {
            $allowed = isset($_SESSION['album_access']) && !empty($_SESSION['album_access'][$row['album_id']]);
            if (!$allowed) return $response->withStatus(403);
        }

        // NSFW server-side enforcement: block downloads for unconfirmed NSFW albums
        // Admins bypass this check
        $isAdmin = $this->isAdmin();
        if ((bool)$row['is_nsfw'] && !$isAdmin) {
            $nsfwConfirmed = isset($_SESSION['nsfw_confirmed'][$row['album_id']]) && $_SESSION['nsfw_confirmed'][$row['album_id']] === true;
            if (!$nsfwConfirmed) {
                return $response->withStatus(403);
            }
        }
        
        $root = dirname(__DIR__, 3);
        $originalPath = (string)$row['original_path'];
        
        // SECURITY: Comprehensive path traversal prevention
        // Remove all potential traversal sequences
        $originalPath = str_replace(['../', '..\\', '/../', '\\..\\', '../', '..\\'], '', $originalPath);
        $originalPath = preg_replace('/\.{2,}/', '.', $originalPath); // Remove multiple dots
        $originalPath = ltrim($originalPath, '/');
        
        // Ensure path is properly normalized and starts with expected directory
        if (!str_starts_with($originalPath, 'storage/')) {
            $originalPath = 'storage/' . ltrim($originalPath, '/');
        }
        
        // Additional safety: ensure no traversal characters remain
        if (str_contains($originalPath, '..') || str_contains($originalPath, '\\')) {
            return $response->withStatus(403);
        }
        
        $fsPath = $root . '/' . $originalPath;
        $realPath = realpath($fsPath);
        $storageRoot = realpath($root . '/storage/');
        
        // SECURITY: Multi-layer validation
        // 1. Verify resolved path exists and is within storage directory
        if (!$realPath || !$storageRoot || !str_starts_with($realPath, $storageRoot . DIRECTORY_SEPARATOR)) {
            return $response->withStatus(403);
        }
        
        // 2. Verify it's actually a file (not directory or other)
        if (!is_file($realPath)) {
            return $response->withStatus(404);
        }
        
        // 3. Additional check: ensure it's an image file by MIME type validation
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $realPath);
        finfo_close($finfo);
        
        $allowedMimes = [
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
            'image/bmp', 'image/tiff', 'image/svg+xml'
        ];
        
        if (!in_array($detectedMime, $allowedMimes, true)) {
            return $response->withStatus(403);
        }
        
        $mime = $row['mime'] ?: 'application/octet-stream';
        
        // SECURITY: Comprehensive filename sanitization to prevent header injection
        $filename = basename($realPath);
        
        // Remove all potentially dangerous characters
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        
        // Remove control characters and line breaks that could cause header injection
        $filename = preg_replace('/[\x00-\x1F\x7F-\x9F]/', '', $filename);
        
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
            if ($chunk === false) break;
            $body->write($chunk);
        }
        fclose($stream);
        
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string)$filesize)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY');
    }
}

