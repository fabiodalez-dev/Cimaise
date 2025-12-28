<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Support\Database;
use App\Services\ExifService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class MediaController extends BaseController
{
    public function __construct(
        private Database $db,
        private Twig $view,
        private ExifService $exifService
    ) {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        $pdo = $this->db->pdo();
        $q = trim((string)($request->getQueryParams()['q'] ?? ''));
        
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
        
        $sql = 'SELECT i.id, i.album_id, i.original_path, i.created_at, i.width, i.height, i.alt_text, i.caption,
                       i.camera_id, i.lens_id, i.film_id, i.developer_id, i.lab_id, i.location_id,
                       i.iso, i.shutter_speed, i.aperture,
                       COALESCE(iv.path, i.original_path) AS preview_path
                FROM images i
                LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = \'sm\'';
        $params = [];
        if ($q !== '') {
            $sql .= ' WHERE i.alt_text LIKE :q OR i.caption LIKE :q OR i.original_path LIKE :q';
            $params[':q'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY i.id DESC LIMIT 200';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll() ?: [];

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
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) return $response->withStatus(400);
        $pdo = $this->db->pdo();
        // Collect paths
        $stmt = $pdo->prepare('SELECT id, original_path FROM images WHERE id = :id');
        $stmt->execute([':id'=>$id]);
        $row = $stmt->fetch();
        if (!$row) return $response->withStatus(404);
        $varStmt = $pdo->prepare('SELECT path FROM image_variants WHERE image_id = :id');
        $varStmt->execute([':id'=>$id]);
        $files = [$row['original_path']];
        foreach ($varStmt->fetchAll() ?: [] as $v) { $files[] = $v['path']; }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM image_variants WHERE image_id = :id')->execute([':id'=>$id]);
            $pdo->prepare('UPDATE albums SET cover_image_id = NULL WHERE cover_image_id = :id')->execute([':id'=>$id]);
            $pdo->prepare('DELETE FROM images WHERE id = :id')->execute([':id'=>$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return $response->withStatus(500);
        }
        $root = dirname(__DIR__, 2);
        foreach ($files as $p) {
            $abs = str_starts_with((string)$p, '/media/') ? ($root . '/public' . $p) : ($root . $p);
            @unlink($abs);
        }
        $response->getBody()->write(json_encode(['ok'=>true]));
        return $response->withHeader('Content-Type','application/json');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

        $id = (int)($args['id'] ?? 0);
        if ($id <= 0) return $response->withStatus(400);

        $d = (array)$request->getParsedBody();
        $pdo = $this->db->pdo();

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
            'custom_camera' => !empty($d['custom_camera']) ? trim(substr((string)$d['custom_camera'], 0, 160)) : null,
            'custom_lens' => !empty($d['custom_lens']) ? trim(substr((string)$d['custom_lens'], 0, 160)) : null,
            'custom_film' => !empty($d['custom_film']) ? trim(substr((string)$d['custom_film'], 0, 160)) : null,
        ];

        $setParts = [];
        $params = [':id' => $id];
        foreach ($fields as $field => $value) {
            $setParts[] = "$field = :$field";
            $params[":$field"] = $value;
        }

        if (!empty($setParts)) {
            $sql = 'UPDATE images SET ' . implode(', ', $setParts) . ' WHERE id = :id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

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
        if (empty($exifData)) {
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
