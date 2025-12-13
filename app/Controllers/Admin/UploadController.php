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
        // Validate album exists
        try {
            $check = $this->db->pdo()->prepare('SELECT id FROM albums WHERE id = :id');
            $check->execute([':id'=>$albumId]);
            if (!$check->fetch()) {
                $response->getBody()->write(json_encode(['ok'=>false,'error'=>'Album not found']));
                return $response->withStatus(404)->withHeader('Content-Type','application/json');
            }
        } catch (\Throwable) {
            // Ignore DB errors here; proceed and let ingest fail if necessary
        }
        // CSRF is enforced by middleware; here we only handle the payload
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        
        if (!$file) {
            $response->getBody()->write(json_encode(['ok'=>false,'error'=>'No file']));
            return $response->withStatus(400)->withHeader('Content-Type','application/json');
        }
        
        // Check for upload errors
        $uploadError = $file->getError();
        if ($uploadError !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File too large (exceeds upload_max_filesize)',
                UPLOAD_ERR_FORM_SIZE => 'File too large (exceeds MAX_FILE_SIZE)',
                UPLOAD_ERR_PARTIAL => 'Incomplete upload',
                UPLOAD_ERR_NO_FILE => 'No file uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Disk write error',
                UPLOAD_ERR_EXTENSION => 'Upload blocked by PHP extension'
            ];
            $errorMsg = $errorMessages[$uploadError] ?? "Unknown upload error: $uploadError";
            $response->getBody()->write(json_encode(['ok'=>false,'error'=>$errorMsg]));
            return $response->withStatus(400)->withHeader('Content-Type','application/json');
        }
        
        // Persist the uploaded stream to a secure temporary path on disk.
        // Rationale: PSR-7 UploadedFile may expose a memory stream (php://temp),
        // and UploadService expects a filesystem path.
        // Use project root, not app/ subdir
        $tmpDir = dirname(__DIR__, 3) . '/storage/tmp';
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

    public function uploadSiteLogo(Request $request, Response $response): Response
    {
        // Accept single file under 'file', validate image, store under /public/media/site/
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file) {
            $response->getBody()->write(json_encode(['ok'=>false,'error'=>'No file']));
            return $response->withStatus(400)->withHeader('Content-Type','application/json');
        }
        try {
            // Read stream without relying on temp rename (more robust across environments)
            $stream = $file->getStream();
            if (method_exists($stream, 'rewind')) { $stream->rewind(); }
            $contents = (string)$stream->getContents();
            if ($contents === '' || strlen($contents) === 0) {
                throw new \RuntimeException('Empty upload');
            }
            // Validate using finfo + whitelist
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($contents) ?: '';
            $allowed = ['image/png'=>'.png','image/jpeg'=>'.jpg','image/webp'=>'.webp'];
            if (!isset($allowed[$mime])) {
                throw new \RuntimeException('Unsupported file type for logo');
            }
            $info = @getimagesizefromstring($contents);
            if ($info === false) throw new \RuntimeException('Invalid image file');
            [$w,$h] = $info;
            if ($w<=0 || $h<=0 || $w>10000 || $h>10000) throw new \RuntimeException('Invalid image dimensions');

            $hash = sha1($contents) ?: bin2hex(random_bytes(20));
            $ext = $allowed[$mime];
            // Project root/public/media
            $destDir = dirname(__DIR__, 3) . '/public/media';
            ImagesService::ensureDir($destDir);
            $destPath = $destDir . '/logo-' . $hash . $ext;
            if (@file_put_contents($destPath, $contents) === false) {
                throw new \RuntimeException('Failed to write logo file');
            }
            if (!is_file($destPath)) {
                throw new \RuntimeException('Logo save verification failed');
            }
            $relUrl = '/media/' . basename($destPath);
            // Save setting
            $settings = new \App\Services\SettingsService($this->db);
            $settings->set('site.logo', $relUrl);
            $response->getBody()->write(json_encode(['ok'=>true,'path'=>$relUrl,'width'=>$w,'height'=>$h]));
            return $response->withHeader('Content-Type','application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['ok'=>false,'error'=>$e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type','application/json');
        }
    }
}
