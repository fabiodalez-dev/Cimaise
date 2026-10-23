<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\CacheTags;
use App\Services\PageCacheService;
use App\Services\SettingsService;
use App\Support\Database;
use App\Services\ExifService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class MediaController extends BaseController
{
    public function __construct(
        private readonly Database $db,
        private readonly Twig $view,
        private readonly ExifService $exifService
    ) {
        parent::__construct();
    }

    private const PER_PAGE = 60;

    /** @param array<string, mixed> $args */
    public function variants(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $stmt = $this->db->pdo()->prepare('SELECT id, original_path, width, height FROM images WHERE id = ?');
        $stmt->execute([$id]);
        $exists = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$exists) {
            return $response->withStatus(404);
        }
        $settings = new SettingsService($this->db);
        $configuration = \App\Services\UploadService::variantConfiguration($settings);
        $stmt = $this->db->pdo()->prepare('SELECT variant, format, width, height, size_bytes FROM image_variants WHERE image_id = ?');
        $stmt->execute([$id]);
        $registered = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $registered[$row['variant'] . '.' . $row['format']] = $row;
        }
        $stmt->closeCursor();
        $storage = new \App\Services\ProtectedMediaStorage($this->db);
        $protected = $storage->isImageProtected($id);
        $directory = dirname(__DIR__, 3) . ($protected ? '/storage/protected-media/' : '/public/media/');
        $variants = [];
        foreach ($configuration['breakpoints'] as $variant => $_width) {
            foreach ($configuration['formats'] as $format) {
                $key = $variant . '.' . $format;
                // Never turn a customized setting into a filesystem path or URL.
                if (!preg_match('/^[a-zA-Z0-9_-]+\.(jpg|webp|avif|jxl)$/D', $key)) { continue; }
                $row = $registered[$key] ?? [];
                $file = $directory . $id . '_' . $key;
                $ready = $row && is_file($file) && filesize($file) > 0;
                $variants[] = ['name' => $key, 'ready' => (bool)$ready,
                    'width' => (int)($row['width'] ?? 0), 'height' => (int)($row['height'] ?? 0),
                    'bytes' => $ready ? (int)filesize($file) : 0,
                    'url' => $ready ? '/admin/media/images/' . $id . '/variants/' . $key : null];
            }
        }
        $jobs = (new \App\Services\ImageJobQueue($this->db))->status();
        $job = array_values(array_filter($jobs, static fn ($job) => $job['id'] === $id))[0] ?? null;
        $originalPath = \App\Services\OriginalImage::resolve((string)$exists['original_path']);
        $original = ['ready' => $originalPath !== null,
            'width' => (int)$exists['width'], 'height' => (int)$exists['height'],
            'bytes' => $originalPath !== null ? (int)filesize($originalPath) : 0,
            'url' => $originalPath !== null ? '/admin/media/images/' . $id . '/original' : null];
        $response->getBody()->write(json_encode(['variants' => $variants, 'original' => $original, 'job' => $job], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }

    /** AuthMiddleware protects previews, including unpublished and protected albums. */
    /** @param array<string, mixed> $args */
    public function viewVariant(Request $request, Response $response, array $args): Response
    {
        $id = (int)$args['id'];
        $variant = (string)$args['variant'];
        $format = (string)$args['format'];
        $types = ['jpg' => 'image/jpeg', 'webp' => 'image/webp', 'avif' => 'image/avif', 'jxl' => 'image/jxl'];
        if (!isset($types[$format]) || !preg_match('/^[a-zA-Z0-9_-]+$/D', $variant)) {
            return $response->withStatus(404);
        }
        $stmt = $this->db->pdo()->prepare('SELECT image_id FROM image_variants WHERE image_id = ? AND variant = ? AND format = ?');
        $stmt->execute([$id, $variant, $format]);
        $exists = $stmt->fetchColumn();
        $stmt->closeCursor();
        $protected = (new \App\Services\ProtectedMediaStorage($this->db))->isImageProtected($id);
        $directory = dirname(__DIR__, 3) . ($protected ? '/storage/protected-media/' : '/public/media/');
        $path = $directory . $id . '_' . $variant . '.' . $format;
        $real = realpath($path);
        $base = realpath($directory);
        if (!$exists || !$real || !$base || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real)) {
            return $response->withStatus(404);
        }
        $stream = @fopen($real, 'rb');
        if ($stream === false) { return $response->withStatus(404); }
        return $response->withBody(new \Slim\Psr7\Stream($stream))
            ->withHeader('Content-Type', $types[$format])->withHeader('Content-Length', (string)filesize($real))
            ->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Robots-Tag', 'noindex, noimageindex');
    }

    private function invalidateAlbumCaches(int $albumId): void
    {
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
            // Cache invalidation failure should not break admin operations
            \App\Support\Logger::warning('Cache invalidation failed', [
                'album_id' => $albumId,
                'error' => $e->getMessage()
            ], 'cache');
        }
    }

    private function getAlbumIdForImage(int $imageId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT album_id FROM images WHERE id = ?');
        $stmt->execute([$imageId]);
        return (int)($stmt->fetchColumn() ?: 0);
    }

    public function index(Request $request, Response $response): Response
    {
        $pdo = $this->db->pdo();
        $q = trim((string)($request->getQueryParams()['q'] ?? ''));
        $albumFilter = max(0, (int)($request->getQueryParams()['album'] ?? 0));
        $formatFilter = (string)($request->getQueryParams()['format'] ?? '');
        $generationFilter = (string)($request->getQueryParams()['generation'] ?? '');
        $mimeFilters = ['jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif'];
        if (!isset($mimeFilters[$formatFilter])) { $formatFilter = ''; }
        if (!in_array($generationFilter, ['active', 'retrying', 'preview', 'generated'], true)) { $generationFilter = ''; }
        $page = max(1, (int)($request->getQueryParams()['page'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;

        // Load albums list for attach action
        $albums = $pdo->query('SELECT id, title FROM albums ORDER BY created_at DESC LIMIT 500')->fetchAll() ?: [];

        // Load equipment data for sidebar
        $cameras = $pdo->query('SELECT id, make, model FROM cameras ORDER BY make, model')->fetchAll() ?: [];
        $lenses = $pdo->query('SELECT id, brand, model FROM lenses ORDER BY brand, model')->fetchAll() ?: [];
        $films = $pdo->query('SELECT id, brand, name FROM films ORDER BY brand, name')->fetchAll() ?: [];
        $developers = $pdo->query('SELECT id, name FROM developers ORDER BY name')->fetchAll() ?: [];
        $labs = $pdo->query('SELECT id, name FROM labs ORDER BY name')->fetchAll() ?: [];

        // Load locations
        $locations = [];
        try {
            $locations = $pdo->query('SELECT id, name FROM locations ORDER BY name')->fetchAll() ?: [];
        } catch (\Throwable) {
            // Locations table might not exist
        }

        // Count total images for pagination
        $countSql = 'SELECT COUNT(*) FROM images i';
        $params = [];
        $conditions = [];
        if ($q !== '') {
            $conditions[] = '(i.alt_text LIKE :q OR i.caption LIKE :q OR i.original_path LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }
        if ($albumFilter) { $conditions[] = 'i.album_id = :album'; $params[':album'] = $albumFilter; }
        if ($formatFilter) { $conditions[] = 'i.mime = :mime'; $params[':mime'] = $mimeFilters[$formatFilter]; }
        if (in_array($generationFilter, ['active', 'retrying'], true)) {
            $jobs = (new \App\Services\ImageJobQueue($this->db))->status();
            $ids = array_map(static fn(array $job): int => (int)$job['id'], array_filter($jobs,
                static fn(array $job): bool => $job['pending'] && ($generationFilter !== 'retrying' || $job['state'] === 'retrying')));
            $conditions[] = $ids ? 'i.id IN (' . implode(',', $ids) . ')' : '1 = 0';
        } elseif (in_array($generationFilter, ['preview', 'generated'], true)) {
            $conditions[] = ($generationFilter === 'preview' ? 'NOT ' : '') . "EXISTS (SELECT 1 FROM image_variants iv WHERE iv.image_id = i.id AND NOT (iv.variant = 'sm' AND iv.format = 'jpg'))";
        }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $countSql .= $where;
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalItems = (int)$countStmt->fetchColumn();
        $totalPages = (int)ceil($totalItems / self::PER_PAGE);

        // Use subquery to get exactly one preview variant per image (prefer webp > jpg > avif)
        $sql = 'SELECT i.id, i.album_id, i.original_path, i.created_at, i.width, i.height, i.alt_text, i.caption,
                       i.camera_id, i.lens_id, i.film_id, i.developer_id, i.lab_id, i.location_id,
                       i.iso, i.shutter_speed, i.aperture,
                       COALESCE((
                           SELECT iv.path FROM image_variants iv
                           WHERE iv.image_id = i.id AND iv.variant = \'sm\'
                           -- Prefer webp for admin UI performance (smaller files, faster loading)
                           ORDER BY CASE iv.format WHEN \'webp\' THEN 1 WHEN \'jpg\' THEN 2 ELSE 3 END
                           LIMIT 1
                       ), i.original_path) AS preview_path
                FROM images i';
        $sql .= $where;
        $sql .= ' ORDER BY i.id DESC LIMIT :limit OFFSET :offset';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $name => $value) { $stmt->bindValue($name, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR); }
        $stmt->bindValue(':limit', self::PER_PAGE, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll() ?: [];
        foreach ($items as &$item) {
            if (preg_match('/^' . (int)$item['id'] . '_([a-zA-Z0-9_-]+)\.(jpg|webp|avif|jxl)$/D', basename((string)$item['preview_path']), $match)) {
                $item['preview_path'] = '/admin/media/images/' . (int)$item['id'] . '/variants/' . $match[1] . '.' . $match[2];
            }
        }
        unset($item);

        $partial = (string)($request->getQueryParams()['partial'] ?? '') === '1';
        $tpl = $partial ? 'admin/media/_grid.twig' : 'admin/media/index.twig';
        return $this->view->render($response, $tpl, [
            'items' => $items,
            'albums' => $albums,
            'cameras' => $cameras,
            'lenses' => $lenses,
            'films' => $films,
            'developers' => $developers,
            'labs' => $labs,
            'locations' => $locations,
            'csrf' => $_SESSION['csrf'] ?? '',
            'filters' => ['q' => $q, 'album' => $albumFilter, 'format' => $formatFilter, 'generation' => $generationFilter],
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_items' => $totalItems,
                'per_page' => self::PER_PAGE,
                'query' => $q,
                'filters' => http_build_query(['q' => $q, 'album' => $albumFilter ?: '', 'format' => $formatFilter, 'generation' => $generationFilter])
            ]
        ]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            return $response->withStatus(400);
        }
        $pdo = $this->db->pdo();
        // Collect paths and album_id (needed for cache invalidation after delete)
        $stmt = $pdo->prepare('SELECT id, original_path, album_id FROM images WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return $response->withStatus(404);
        }
        $albumId = (int)($row['album_id'] ?? 0);
        $varStmt = $pdo->prepare('SELECT path FROM image_variants WHERE image_id = :id');
        $varStmt->execute([':id' => $id]);
        $files = [$row['original_path']];
        foreach ($varStmt->fetchAll() ?: [] as $v) {
            $files[] = $v['path'];
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM image_variants WHERE image_id = :id')->execute([':id' => $id]);
            $pdo->prepare('UPDATE albums SET cover_image_id = NULL WHERE cover_image_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM images WHERE id = :id')->execute([':id' => $id]);
            $pdo->commit();
        } catch (\Throwable) {
            $pdo->rollBack();
            return $response->withStatus(500);
        }
        // Invalidate page caches — image deleted, cover may have changed
        if ($albumId > 0) {
            $this->invalidateAlbumCaches($albumId);
        }
        $protectedStorage = new \App\Services\ProtectedMediaStorage($this->db);
        foreach ($files as $p) {
            if (str_starts_with((string)$p, '/media/')) {
                $protectedStorage->deleteVariantCopies((string)$p);
            } else {
                // Originals can be shared (sha1 dedup / attach): only unlink
                // when no other images row still references the same file.
                \App\Services\UploadService::deleteOriginalIfUnreferenced($this->db, (string)$p);
            }
        }
        $response->getBody()->write(json_encode(['ok' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            return $response->withStatus(400);
        }

        $d = (array)$request->getParsedBody();
        $pdo = $this->db->pdo();

        $albumId = $this->getAlbumIdForImage($id);
        if ($albumId <= 0) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Image not found'], 404);
        }

        $fields = [
            'alt_text' => $d['alt_text'] ?? null,
            'caption' => $d['caption'] ?? null,
            'camera_id' => ($d['camera_id'] ?? '') !== '' ? (int)$d['camera_id'] : null,
            'lens_id' => ($d['lens_id'] ?? '') !== '' ? (int)$d['lens_id'] : null,
            'film_id' => ($d['film_id'] ?? '') !== '' ? (int)$d['film_id'] : null,
            'developer_id' => ($d['developer_id'] ?? '') !== '' ? (int)$d['developer_id'] : null,
            'lab_id' => ($d['lab_id'] ?? '') !== '' ? (int)$d['lab_id'] : null,
            'location_id' => ($d['location_id'] ?? '') !== '' ? (int)$d['location_id'] : null,
            'iso' => ($d['iso'] ?? '') !== '' ? (int)$d['iso'] : null,
            'shutter_speed' => $d['shutter_speed'] ?? null,
            'aperture' => ($d['aperture'] ?? '') !== '' ? (float)$d['aperture'] : null,
            'custom_camera' => empty($d['custom_camera']) ? null : trim(substr((string)$d['custom_camera'], 0, 160)),
            'custom_lens' => empty($d['custom_lens']) ? null : trim(substr((string)$d['custom_lens'], 0, 160)),
            'custom_film' => empty($d['custom_film']) ? null : trim(substr((string)$d['custom_film'], 0, 160)),
        ];

        $setParts = [];
        $params = [':id' => $id];
        foreach ($fields as $field => $value) {
            $setParts[] = "$field = :$field";
            $params[":$field"] = $value;
        }

        // $setParts is populated from the fixed $fields map above, and the
        // function early-returns when $albumId is not positive, so both
        // conditions are guaranteed truthy here.
        $sql = 'UPDATE images SET ' . implode(', ', $setParts) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        // Invalidate page caches — image metadata changed
        $this->invalidateAlbumCaches($albumId);

        // Handle EXIF write to files if requested
        if (!empty($d['write_exif_to_file'])) {
            $exifData = $this->buildExifDataArray($d);
            $originalsDir = dirname(__DIR__, 3) . '/storage/originals';
            $mediaDir = dirname(__DIR__, 3) . '/public/media';
            $writeResult = $this->exifService->propagateExifToVariants($id, $exifData, $originalsDir, $mediaDir);

            $response->getBody()->write(json_encode([
                'ok' => true,
                'exif_write' => $writeResult
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['ok' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Get EXIF data for an image (AJAX endpoint).
     */
    public function getExif(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            $response->getBody()->write(json_encode(['error' => 'Invalid image ID']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $exifData = $this->exifService->getExifForEditor($id);
        if ($exifData === []) {
            $response->getBody()->write(json_encode(['error' => 'Image not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Add EXIF options for dropdowns
        $options = $this->exifService->getExifOptions();

        $response->getBody()->write(json_encode([
            'ok' => true,
            'data' => $exifData,
            'options' => $options
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Update EXIF data for an image.
     */
    public function updateExif(Request $request, Response $response, array $args): Response
    {
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) {
            $response->getBody()->write(json_encode(['error' => 'Invalid image ID']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $d = (array)$request->getParsedBody();
        $pdo = $this->db->pdo();

        $albumId = $this->getAlbumIdForImage($id);
        if ($albumId <= 0) {
            return $this->jsonResponse($response, ['ok' => false, 'error' => 'Image not found'], 404);
        }

        // Build EXIF fields array
        $exifFields = [
            'exif_make' => $d['exif_make'] ?? null,
            'exif_model' => $d['exif_model'] ?? null,
            'exif_lens_maker' => $d['exif_lens_maker'] ?? null,
            'exif_lens_model' => $d['exif_lens_model'] ?? null,
            'software' => $d['software'] ?? null,
            'focal_length' => ($d['focal_length'] ?? '') !== '' ? (float)$d['focal_length'] : null,
            'exposure_bias' => ($d['exposure_bias'] ?? '') !== '' ? (float)$d['exposure_bias'] : null,
            'flash' => ($d['flash'] ?? '') !== '' ? (int)$d['flash'] : null,
            'white_balance' => ($d['white_balance'] ?? '') !== '' ? (int)$d['white_balance'] : null,
            'exposure_program' => ($d['exposure_program'] ?? '') !== '' ? (int)$d['exposure_program'] : null,
            'metering_mode' => ($d['metering_mode'] ?? '') !== '' ? (int)$d['metering_mode'] : null,
            'exposure_mode' => ($d['exposure_mode'] ?? '') !== '' ? (int)$d['exposure_mode'] : null,
            'date_original' => $d['date_original'] ?? null,
            'color_space' => ($d['color_space'] ?? '') !== '' ? (int)$d['color_space'] : null,
            'contrast' => ($d['contrast'] ?? '') !== '' ? (int)$d['contrast'] : null,
            'saturation' => ($d['saturation'] ?? '') !== '' ? (int)$d['saturation'] : null,
            'sharpness' => ($d['sharpness'] ?? '') !== '' ? (int)$d['sharpness'] : null,
            'scene_capture_type' => ($d['scene_capture_type'] ?? '') !== '' ? (int)$d['scene_capture_type'] : null,
            'light_source' => ($d['light_source'] ?? '') !== '' ? (int)$d['light_source'] : null,
            'gps_lat' => ($d['gps_lat'] ?? '') !== '' ? (float)$d['gps_lat'] : null,
            'gps_lng' => ($d['gps_lng'] ?? '') !== '' ? (float)$d['gps_lng'] : null,
            'artist' => $d['artist'] ?? null,
            'copyright' => $d['copyright'] ?? null,
        ];

        // Build SET clause
        $setParts = [];
        $params = [':id' => $id];
        foreach ($exifFields as $field => $value) {
            $setParts[] = "$field = :$field";
            $params[":$field"] = $value;
        }

        $sql = 'UPDATE images SET ' . implode(', ', $setParts) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Invalidate page caches — EXIF metadata changed
        // ($albumId is guaranteed > 0 by the early return above.)
        $this->invalidateAlbumCaches($albumId);

        // Handle EXIF write to files if requested
        $writeResult = null;
        if (!empty($d['write_exif_to_file'])) {
            $originalsDir = dirname(__DIR__, 3) . '/storage/originals';
            $mediaDir = dirname(__DIR__, 3) . '/public/media';
            $writeResult = $this->exifService->propagateExifToVariants($id, $exifFields, $originalsDir, $mediaDir);
        }

        $response->getBody()->write(json_encode([
            'ok' => true,
            'exif_write' => $writeResult
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Build EXIF data array from form data.
     */
    private function buildExifDataArray(array $d): array
    {
        return [
            'exif_make' => $d['exif_make'] ?? null,
            'exif_model' => $d['exif_model'] ?? null,
            'exif_lens_maker' => $d['exif_lens_maker'] ?? null,
            'exif_lens_model' => $d['exif_lens_model'] ?? null,
            'software' => $d['software'] ?? null,
            'focal_length' => ($d['focal_length'] ?? '') !== '' ? (float)$d['focal_length'] : null,
            'exposure_bias' => ($d['exposure_bias'] ?? '') !== '' ? (float)$d['exposure_bias'] : null,
            'flash' => ($d['flash'] ?? '') !== '' ? (int)$d['flash'] : null,
            'white_balance' => ($d['white_balance'] ?? '') !== '' ? (int)$d['white_balance'] : null,
            'exposure_program' => ($d['exposure_program'] ?? '') !== '' ? (int)$d['exposure_program'] : null,
            'metering_mode' => ($d['metering_mode'] ?? '') !== '' ? (int)$d['metering_mode'] : null,
            'exposure_mode' => ($d['exposure_mode'] ?? '') !== '' ? (int)$d['exposure_mode'] : null,
            'date_original' => $d['date_original'] ?? null,
            'color_space' => ($d['color_space'] ?? '') !== '' ? (int)$d['color_space'] : null,
            'contrast' => ($d['contrast'] ?? '') !== '' ? (int)$d['contrast'] : null,
            'saturation' => ($d['saturation'] ?? '') !== '' ? (int)$d['saturation'] : null,
            'sharpness' => ($d['sharpness'] ?? '') !== '' ? (int)$d['sharpness'] : null,
            'scene_capture_type' => ($d['scene_capture_type'] ?? '') !== '' ? (int)$d['scene_capture_type'] : null,
            'light_source' => ($d['light_source'] ?? '') !== '' ? (int)$d['light_source'] : null,
            'gps_lat' => ($d['gps_lat'] ?? '') !== '' ? (float)$d['gps_lat'] : null,
            'gps_lng' => ($d['gps_lng'] ?? '') !== '' ? (float)$d['gps_lng'] : null,
            'artist' => $d['artist'] ?? null,
            'copyright' => $d['copyright'] ?? null,
        ];
    }
}
