<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\ImageJobQueue;
use App\Services\UploadService;
use App\Services\ImagesService;
use App\Services\CacheTags;
use App\Services\PageCacheService;
use App\Services\SettingsService;
use App\Support\Database;
use App\Support\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class UploadController extends BaseController
{
    public function __construct(private readonly Database $db)
    {
        parent::__construct();
    }

    /**
     * Validate and ingest an uploaded image into an album, returning a JSON
     * result (id, variants). Heavy lifting is delegated to UploadService.
     *
     * @param array<string,mixed> $args
     */
    public function uploadToAlbum(Request $request, Response $response, array $args): Response
    {
        $albumId = (int) ($args['id'] ?? 0);
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;

        // Validate album exists
        try {
            $check = $this->db->pdo()->prepare('SELECT id FROM albums WHERE id = :id');
            $check->execute([':id' => $albumId]);
            $albumExists = $check->fetch();
            $check->closeCursor();
            if (!$albumExists) {
                Logger::warning('UploadController: Album not found', ['album_id' => $albumId], 'upload');
                $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Album not found']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }
        } catch (\Throwable $e) {
            Logger::error('UploadController: DB error checking album', ['error' => $e->getMessage()], 'upload');
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Database error']));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
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
        $tmpPath = $tmpDir . '/' . bin2hex(random_bytes(8)) . '-' . basename((string) $clientName);
        try {
            $file->moveTo($tmpPath);
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Failed to persist upload: ' . $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Prepare array compatible with UploadService
        $fArr = ['tmp_name' => $tmpPath, 'error' => $file->getError()];
        // Authentication and CSRF have been checked; allow status polling and parallel uploads.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        try {
            $svc = new UploadService($this->db);
            $uploadToken = $request->getParsedBody()['upload_token'] ?? null;
            $meta = $svc->ingestAlbumUpload($albumId, $fArr, is_string($uploadToken) ? $uploadToken : null);

            // ingestAlbumUpload() only produces the small preview the admin grid
            // needs; the variant matrix, LQIP and blur go through the queue.
            if (!empty($meta['id'])) {
                $this->scheduleVariantGeneration((int) $meta['id']);
            }

            // Invalidate page caches — new image uploaded to album
            try {
                // Collect all categories from pivot table (multi-category support)
                $pivotStmt = $this->db->pdo()->prepare('SELECT category_id FROM album_category WHERE album_id = ?');
                $pivotStmt->execute([$albumId]);
                $categoryIds = array_map(intval(...), $pivotStmt->fetchAll(\PDO::FETCH_COLUMN) ?: []);
                if ($categoryIds === []) {
                    $fallbackStmt = $this->db->pdo()->prepare('SELECT category_id FROM albums WHERE id = ?');
                    $fallbackStmt->execute([$albumId]);
                    $fbId = (int)($fallbackStmt->fetchColumn() ?: 0);
                    if ($fbId > 0) {
                        $categoryIds[] = $fbId;
                    }
                }
                $tags = CacheTags::albumRelated($albumId);
                foreach ($categoryIds as $cid) {
                    if ($cid > 0) {
                        $tags[] = CacheTags::category($cid);
                    }
                }
                $settings = new SettingsService($this->db);
                $pcs = new PageCacheService($settings, $this->db);
                $pcs->invalidateByTags(array_unique($tags));
            } catch (\Throwable $e) {
                Logger::warning('UploadController: cache invalidation failed for album ' . $albumId . ': ' . $e->getMessage(), [], 'upload');
            }

            // Also expose id at top-level for existing frontend logic
            $payload = [
                'ok' => true,
                'id' => $meta['id'] ?? null,
                'image' => $meta,
            ];
            $json = json_encode($payload);
            $response->getBody()->write($json);
            $response = $response->withHeader('Content-Type', 'application/json');

            return $response;
        } catch (\Throwable $e) {
            // Don't leave the staged upload behind in storage/tmp when ingest fails.
            \App\Services\UploadService::safeUnlink($tmpPath);
            $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
    }

    /** Resume jobs persisted by ingest after the PHP-FPM response is sent. */
    private function scheduleVariantGeneration(int $imageId): void
    {
        (new ImageJobQueue($this->db))->drainAfterResponse();
    }

    /**
     * Validate and store an uploaded site logo: finfo MIME + dimension and
     * pixel-count guards, content-hashed filename under public/media, then
     * favicon generation. Returns a JSON result.
     */
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
            if ($contents === '') {
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
            if ($info === false) {
                throw new \RuntimeException('Invalid image file');
            }
            [$w, $h] = $info;
            if ($w <= 0 || $h <= 0 || $w > 10000 || $h > 10000) {
                throw new \RuntimeException('Invalid image dimensions');
            }
            // Decompression-bomb guard before favicon generation decodes the image.
            if ($w * $h > 40000000) {
                throw new \RuntimeException('Image resolution too high');
            }

            $hash = sha1($contents);
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
            $darkLogo = ($request->getQueryParams()['theme'] ?? '') === 'dark';
            $settings->set($darkLogo ? 'site.logo_dark' : 'site.logo', $relUrl);

            // Automatically generate favicons from the uploaded logo
            $faviconResult = ['generated' => [], 'success' => false];
            try {
                if (!$darkLogo) {
                    $publicPath = dirname(__DIR__, 3) . '/public';
                    $faviconService = new \App\Services\FaviconService($publicPath);
                    $faviconResult = $faviconService->generateFavicons($destPath);
                    if (!empty($faviconResult['success'])) {
                        $settings->set('pwa.existing_icons', []);
                    }
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
