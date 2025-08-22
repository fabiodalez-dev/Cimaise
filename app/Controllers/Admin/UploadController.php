<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\UploadService;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UploadController
{
    public function __construct(private Database $db) {}

    public function uploadToAlbum(Request $request, Response $response, array $args): Response
    {
        $albumId = (int)($args['id'] ?? 0);
        // Basic CSRF header check already enforced by CsrfMiddleware
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file) {
            $response->getBody()->write(json_encode(['ok'=>false,'error'=>'No file']));
            return $response->withStatus(400)->withHeader('Content-Type','application/json');
        }
        // Convert to array for UploadService
        $fArr = [
            'tmp_name' => $file->getStream()->getMetadata('uri') ?? '',
            'error' => $file->getError(),
        ];
        try {
            $svc = new UploadService($this->db);
            $meta = $svc->ingestAlbumUpload($albumId, $fArr);
            $response->getBody()->write(json_encode(['ok'=>true,'image'=>$meta]));
            return $response->withHeader('Content-Type','application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['ok'=>false,'error'=>$e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type','application/json');
        }
    }
}

