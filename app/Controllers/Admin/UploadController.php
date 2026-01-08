<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Services\UploadService;
use App\Services\ImagesService;
use App\Support\Database;
use App\Support\Logger;
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
        $albumId = (int) ($args['id'] ?? 0);
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;

        // Validate album exists
        try {
            $check = $this->db->pdo()->prepare('SELECT id FROM albums WHERE id = :id');
            $check->execute([':id' => $albumId]);
            if (!$check->fetch()) {
                Logger::warning('UploadController: Album not found', ['album_id' => $albumId], 'upload');
                $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Album not found']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }
        } catch (\Throwable $e) {
            Logger::error('UploadController: DB error checking album', ['error' => $e->getMessage()], 'upload');
        }
        // CSRF is enforced by middleware; here we only handle the payload

        if (!$file) {
            Logger::warning('UploadController: No file in request', [], 'upload');
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'No file']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
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
            Logger::error('UploadController: PHP upload error', [
                'error_code' => $uploadError,
                'error_msg' => $errorMsg,
                'file_name' => $file->getClientFilename(),
            ], 'upload');
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $errorMsg]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
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
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Failed to persist upload: ' . $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Check if album needs blur generation (NSFW or password-protected)
        $needsBlur = false;
        try {
            $albumCheck = $this->db->pdo()->prepare('SELECT is_nsfw, password_hash FROM albums WHERE id = ?');
            $albumCheck->execute([$albumId]);
            $album = $albumCheck->fetch();
            $needsBlur = !empty($album['is_nsfw']) || !empty($album['password_hash']);
        } catch (\Throwable $e) {
            // Backwards compatibility: older schemas may not include is_nsfw and/or password_hash.
            Logger::warning('UploadController: album blur check failed (missing columns?)', [
                'album_id' => $albumId,
                'columns' => ['is_nsfw', 'password_hash'],
                'error' => $e->getMessage(),
            ], 'upload');
        }

        // Prepare array compatible with UploadService
        $fArr = ['tmp_name' => $tmpPath, 'error' => $file->getError()];
        try {
            $svc = new UploadService($this->db);
            $meta = $svc->ingestAlbumUpload($albumId, $fArr);

            // Also expose id at top-level for existing frontend logic
            $payload = [
                'ok' => true,
                'id' => $meta['id'] ?? null,
                'image' => $meta,
            ];
            $json = json_encode($payload);
            $response->getBody()->write($json);
            $response = $response->withHeader('Content-Type', 'application/json');

            // FAST RESPONSE: Only generate variants in background if PHP-FPM is available
            // fastcgi_finish_request() is the ONLY reliable way to send response and continue.
            // Other approaches (flush, Connection: close) don't actually work.
            // For non-FPM environments, VariantMaintenanceService cron will generate variants.

            if (function_exists('fastcgi_finish_request') && !empty($meta['id'])) {
                // Send response to client immediately
                fastcgi_finish_request();

                // BACKGROUND WORK: Now generate variants (client already has response)
                ignore_user_abort(true);
                set_time_limit(300);

                try {
                    $svc->generateVariantsForImage((int) $meta['id'], false);
                } catch (\Throwable $variantError) {
                    Logger::warning('Failed to generate variants in background', [
                        'image_id' => $meta['id'],
                        'error' => $variantError->getMessage()
                    ], 'upload');
                }

                // Generate blur only if album is NSFW or password-protected
                if ($needsBlur) {
                    try {
                        $svc->generateBlurredVariant((int) $meta['id']);
                    } catch (\Throwable $blurError) {
                        Logger::warning('Failed to generate blur for protected album image', [
                            'image_id' => $meta['id'],
                            'error' => $blurError->getMessage()
                        ], 'upload');
                    }
                }
            }
            // For non-FPM: Response sent immediately, variants generated later by cron

            return $response;
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    public function uploadSiteLogo(Request $request, Response $response): Response
    {
        // CSRF validation
        $csrfHeader = $request->getHeaderLine('X-CSRF-Token');
        $sessionCsrf = $_SESSION['csrf'] ?? '';
        if (empty($csrfHeader) || !hash_equals($sessionCsrf, $csrfHeader)) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'CSRF validation failed']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        // Accept single file under 'file', validate image, store under /public/media/site/
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (!$file) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'No file']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        try {
            // Read stream without relying on temp rename (more robust across environments)
            $stream = $file->getStream();
            if (method_exists($stream, 'rewind')) {
                $stream->rewind();
            }
            $contents = (string) $stream->getContents();
            if ($contents === '' || strlen($contents) === 0) {
                throw new \RuntimeException('Empty upload');
            }
            // Validate using finfo + whitelist
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($contents) ?: '';
            $allowed = ['image/png' => '.png', 'image/jpeg' => '.jpg', 'image/webp' => '.webp'];
            if (!isset($allowed[$mime])) {
                throw new \RuntimeException('Unsupported file type for logo');
            }
            $info = @getimagesizefromstring($contents);
            if ($info === false)
                throw new \RuntimeException('Invalid image file');
            [$w, $h] = $info;
            if ($w <= 0 || $h <= 0 || $w > 10000 || $h > 10000)
                throw new \RuntimeException('Invalid image dimensions');

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

            // Automatically generate favicons from the uploaded logo
            $faviconResult = ['generated' => [], 'success' => false];
            try {
                $publicPath = dirname(__DIR__, 3) . '/public';
                $faviconService = new \App\Services\FaviconService($publicPath);
                $faviconResult = $faviconService->generateFavicons($destPath);
                if (!empty($faviconResult['success'])) {
                    $settings->set('pwa.existing_icons', []);
                }
            } catch (\Throwable $faviconError) {
                $faviconResult['error'] = $faviconError->getMessage();
                \App\Support\Logger::error('Favicon generation failed after logo upload', [
                    'error' => $faviconError->getMessage(),
                ], 'favicon');
            }

            $response->getBody()->write(json_encode([
                'ok' => true,
                'path' => $relUrl,
                'width' => $w,
                'height' => $h,
                'favicons' => $faviconResult
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }
}
