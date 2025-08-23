<?php
declare(strict_types=1);

namespace App\Controllers\Frontend;

use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class DownloadController
{
    public function __construct(private Database $db) {}

    public function downloadImage(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) return $response->withStatus(404);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT i.id, i.original_path, i.mime, a.id as album_id, a.allow_downloads, a.password_hash
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
        $root = dirname(__DIR__, 3);
        $fsPath = $root . $row['original_path'];
        if (!is_file($fsPath)) return $response->withStatus(404);
        $mime = $row['mime'] ?: 'application/octet-stream';
        $filename = basename($fsPath);
        $stream = fopen($fsPath, 'rb');
        $body = $response->getBody();
        while (!feof($stream)) {
            $body->write(fread($stream, 8192));
        }
        fclose($stream);
        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string)filesize($fsPath));
    }
}

