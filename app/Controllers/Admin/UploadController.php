<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Services\UploadService;
use App\Services\ImagesService;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UploadController extends BaseController
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    public function uploadToAlbum(Request $request, Response $response, array $args): Response
    {
        $albumId = (int)($args['id'] ?? 0);
        // CSRF is enforced by middleware; here we only handle the payload
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file) {
            $response->getBody()->write(json_encode(['ok'=>false,'error'=>'No file']));
            return $response->withStatus(400)->withHeader('Content-Type','application/json');
        }
        
        // Persist the uploaded stream to a secure temporary path on disk.
        // Rationale: PSR-7 UploadedFile may expose a memory stream (php://temp),
        // and UploadService expects a filesystem path.
        $tmpDir = dirname(__DIR__, 2) . '/storage/tmp';
        ImagesService::ensureDir($tmpDir);
        $clientName = $file->getClientFilename() ?: ('upload-' . time());
        $tmpPath = $tmpDir . '/' . bin2hex(random_bytes(8)) . '-' . basename($clientName);
        try {
            $file->moveTo($tmpPath);
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['ok'=>false,'error'=>'Failed to persist upload: '.$e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type','application/json');
        }

        // Prepare array compatible with UploadService
        $fArr = [ 'tmp_name' => $tmpPath, 'error' => $file->getError() ];
        try {
            $svc = new UploadService($this->db);
            $meta = $svc->ingestAlbumUpload($albumId, $fArr);
            // Also expose id at top-level for existing frontend logic
            $payload = [
                'ok' => true,
                'id' => $meta['id'] ?? null,
                'image' => $meta,
            ];
            $response->getBody()->write(json_encode($payload));
            return $response->withHeader('Content-Type','application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['ok'=>false,'error'=>$e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type','application/json');
        }
    }
}
