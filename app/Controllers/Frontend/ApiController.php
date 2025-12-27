<?php
declare(strict_types=1);

namespace App\Controllers\Frontend;
use App\Controllers\BaseController;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class ApiController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    public function albums(Request $request, Response $response): Response
    {
        $pdo = $this->db->pdo();
        $q = $request->getQueryParams();

        $page = max(1, (int)($q['page'] ?? 1));
        $perPage = min(50, max(1, (int)($q['per_page'] ?? 12)));
        $offset = ($page - 1) * $perPage;
        $category = $q['category'] ?? null;
        $tag = $q['tag'] ?? null; // simple single tag for now
        $sort = $q['sort'] ?? 'published_desc';

        // Build base SQL
        $wheres = ['a.is_published = 1'];
        $params = [];
        $joins = ['JOIN categories c ON c.id = a.category_id'];
        if ($category) { $wheres[] = 'c.slug = :category'; $params[':category'] = $category; }
        if ($tag) {
            $joins[] = 'JOIN album_tag at ON at.album_id = a.id';
            $joins[] = 'JOIN tags t ON t.id = at.tag_id';
            $wheres[] = 't.slug = :tag';
            $params[':tag'] = $tag;
        }

        $orderBy = match ($sort) {
            'published_asc' => 'a.published_at ASC',
            'shoot_date_desc' => 'a.shoot_date DESC',
            'shoot_date_asc' => 'a.shoot_date ASC',
            'title_asc' => 'a.title ASC',
            'title_desc' => 'a.title DESC',
            default => 'a.published_at DESC',
        };

        // Count
        $sqlCount = 'SELECT COUNT(DISTINCT a.id) FROM albums a ' . implode(' ', $joins) . ' WHERE ' . implode(' AND ', $wheres);
        $stmt = $pdo->prepare($sqlCount);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        // Fetch paginated (use DISTINCT instead of GROUP BY for SQL standard compliance)
        $sql = 'SELECT DISTINCT a.*, c.name AS category_name, c.slug AS category_slug
                FROM albums a ' . implode(' ', $joins) . '
                WHERE ' . implode(' AND ', $wheres) . '
                ORDER BY ' . $orderBy . '
                LIMIT :limit OFFSET :offset';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $albums = $stmt->fetchAll();

        $isAdmin = $this->isAdmin();
        $nsfwConsent = $this->hasNsfwConsent();

        // Enrich albums minimally (cover + tags)
        $visibleAlbums = [];
        foreach ($albums as $album) {
            $this->enrichAlbum($album);
            if (!$isAdmin && !empty($album['password_hash']) && !$this->hasAlbumPasswordAccess((int)$album['id'])) {
                continue;
            }
            $album = $this->sanitizeAlbumCoverForNsfw($album, $isAdmin, $nsfwConsent);
            $visibleAlbums[] = $album;
        }
        $albums = $visibleAlbums;

        // Render itemsHtml via Twig partial
        $itemsHtml = '';
        foreach ($albums as $a) {
            $itemsHtml .= $this->view->fetch('frontend/_album_card.twig', [
                'album' => $a,
                'nsfw_consent' => $nsfwConsent,
                'is_admin' => $isAdmin
            ]);
        }

        $pages = max(1, (int)ceil($total / $perPage));
        $payload = [
            'itemsHtml' => $itemsHtml,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $pages,
                'has_next' => $page < $pages,
                'has_prev' => $page > 1,
            ],
        ];

        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function albumImages(Request $request, Response $response, array $args): Response
    {
        $pdo = $this->db->pdo();
        $albumId = (int)($args['id'] ?? 0);

        // Security: Check album exists, is published, and password access
        $stmt = $pdo->prepare('SELECT id, is_published, password_hash, is_nsfw FROM albums WHERE id = :id');
        $stmt->execute([':id' => $albumId]);
        $album = $stmt->fetch();

        if (!$album || !$album['is_published']) {
            $response->getBody()->write(json_encode(['error' => 'Album not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $isPasswordProtected = !empty($album['password_hash']);
        $isNsfw = (bool)$album['is_nsfw'];
        $accessResult = $this->validateAlbumAccess($albumId, $isPasswordProtected, $isNsfw);
        if ($accessResult !== true) {
            $error = match ($accessResult) {
                'password' => 'Album is password protected',
                'nsfw' => 'Age verification required',
                default => 'Access denied',
            };
            $response->getBody()->write(json_encode(['error' => $error]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $q = $request->getQueryParams();
        $process = $q['process'] ?? null;
        $camera = $q['camera'] ?? null; // matches custom_camera only for demo
        $page = max(1, (int)($q['page'] ?? 1));
        $perPage = min(100, max(1, (int)($q['per_page'] ?? 30)));
        $offset = ($page - 1) * $perPage;

        $wheres = ['i.album_id = :album_id'];
        $params = [':album_id' => $albumId];
        if ($process) { $wheres[] = 'i.process = :process'; $params[':process'] = $process; }
        if ($camera) { $wheres[] = 'i.custom_camera = :camera'; $params[':camera'] = $camera; }

        $sqlCount = 'SELECT COUNT(*) FROM images i WHERE ' . implode(' AND ', $wheres);
        $stmt = $pdo->prepare($sqlCount);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $sql = 'SELECT i.* FROM images i WHERE ' . implode(' AND ', $wheres) . ' ORDER BY i.sort_order ASC, i.id ASC LIMIT :limit OFFSET :offset';
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $images = $stmt->fetchAll() ?: [];

        // Batch load variants for all images (avoid N+1)
        if (!empty($images)) {
            $imageIds = array_column($images, 'id');
            $placeholders = implode(',', array_fill(0, count($imageIds), '?'));
            $vstmt = $pdo->prepare("SELECT * FROM image_variants WHERE image_id IN ($placeholders) ORDER BY image_id, variant ASC");
            $vstmt->execute($imageIds);
            $allVariants = $vstmt->fetchAll();

            // Group variants by image_id
            $variantsByImage = [];
            foreach ($allVariants as $v) {
                $variantsByImage[$v['image_id']][] = $v;
            }

            foreach ($images as &$img) {
                $img['variants'] = $variantsByImage[$img['id']] ?? [];
                if ($img['exif']) {
                    $exif = json_decode($img['exif'], true) ?: [];
                    $img['exif_display'] = $this->formatExifForDisplay($exif, $img);
                }
            }
        }

        $itemsHtml = '';
        foreach ($images as $i) {
            $itemsHtml .= $this->view->fetch('frontend/_image_item.twig', ['image' => $i]);
        }

        $pages = max(1, (int)ceil($total / $perPage));
        $payload = [
            'itemsHtml' => $itemsHtml,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => $pages,
                'has_next' => $page < $pages,
                'has_prev' => $page > 1,
            ],
        ];
        $response->getBody()->write(json_encode($payload));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function enrichAlbum(array &$album): void
    {
        $pdo = $this->db->pdo();
        if (!empty($album['cover_image_id'])) {
            $stmt = $pdo->prepare('SELECT * FROM images WHERE id = :id');
            $stmt->execute([':id' => $album['cover_image_id']]);
            $cover = $stmt->fetch();
            if ($cover) {
                $vstmt = $pdo->prepare('SELECT * FROM image_variants WHERE image_id = :id ORDER BY variant ASC');
                $vstmt->execute([':id' => $cover['id']]);
                $cover['variants'] = $vstmt->fetchAll();
                $album['cover'] = $cover;
            }
        }
        $stmt = $pdo->prepare('SELECT t.* FROM tags t JOIN album_tag at ON at.tag_id = t.id WHERE at.album_id = :id ORDER BY t.name ASC');
        $stmt->execute([':id' => $album['id']]);
        $album['tags'] = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM images WHERE album_id = :id');
        $stmt->execute([':id' => $album['id']]);
        $album['images_count'] = (int)$stmt->fetchColumn();
    }

    private function formatExifForDisplay(array $exif, array $image): array
    {
        $display = [];
        if (!empty($exif['Make']) && !empty($exif['Model'])) {
            $display['camera'] = trim($exif['Make'] . ' ' . $exif['Model']);
        } elseif (!empty($image['custom_camera'])) {
            $display['camera'] = $image['custom_camera'];
        }
        if (!empty($exif['LensModel'])) {
            $display['lens'] = $exif['LensModel'];
        } elseif (!empty($image['custom_lens'])) {
            $display['lens'] = $image['custom_lens'];
        }
        if (!empty($image['aperture'])) {
            $display['aperture'] = 'f/' . number_format((float)$image['aperture'], 1);
        }
        if (!empty($image['shutter_speed'])) {
            $display['shutter'] = $this->formatShutterSpeedForDisplay((string)$image['shutter_speed']);
        }
        if (!empty($image['iso'])) {
            $display['iso'] = 'ISO ' . (int)$image['iso'];
        }
        if (!empty($image['custom_film'])) { $display['film'] = $image['custom_film']; }
        if (!empty($image['process'])) { $display['process'] = ucfirst((string)$image['process']); }
        return $display;
    }

    /**
     * Get EXIF data for an image (extracted from original file).
     */
    public function imageExif(Request $request, Response $response, array $args): Response
    {
        $pdo = $this->db->pdo();
        $imageId = (int)($args['id'] ?? 0);

        // Get image with all EXIF fields and album info
        $stmt = $pdo->prepare('
            SELECT i.id, i.original_path, i.album_id, i.custom_camera, i.custom_lens, i.custom_film,
                   i.iso, i.shutter_speed, i.aperture,
                   i.exif_make, i.exif_model, i.exif_lens_model, i.software,
                   i.focal_length, i.exposure_bias, i.flash, i.white_balance,
                   i.exposure_program, i.metering_mode, i.exposure_mode,
                   i.date_original, i.color_space, i.contrast, i.saturation, i.sharpness,
                   i.scene_capture_type, i.light_source,
                   i.gps_lat, i.gps_lng, i.artist, i.copyright,
                   c.make as camera_make, c.model as camera_model,
                   l.brand as lens_brand, l.model as lens_model,
                   f.brand as film_brand, f.name as film_name,
                   a.is_published, a.password_hash, a.is_nsfw
            FROM images i
            JOIN albums a ON a.id = i.album_id
            LEFT JOIN cameras c ON c.id = i.camera_id
            LEFT JOIN lenses l ON l.id = i.lens_id
            LEFT JOIN films f ON f.id = i.film_id
            WHERE i.id = :id
        ');
        $stmt->execute([':id' => $imageId]);
        $row = $stmt->fetch();

        if (!$row) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Image not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        if (!$row['is_published']) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Album not published']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        // Check album access (password protection & NSFW)
        $isPasswordProtected = !empty($row['password_hash']);
        $isNsfw = (bool)$row['is_nsfw'];
        $accessResult = $this->validateAlbumAccess((int)$row['album_id'], $isPasswordProtected, $isNsfw);
        if ($accessResult !== true) {
            $error = match ($accessResult) {
                'password' => 'Album is password protected',
                'nsfw' => 'Age verification required',
                default => 'Access denied',
            };
            $response->getBody()->write(json_encode(['success' => false, 'message' => $error]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        // Build EXIF response from DATABASE values (not from file)
        $result = [
            'success' => true,
            'sections' => []
        ];

        // Equipment section
        $equipment = [];

        // Camera: prefer custom_camera, then EXIF make+model, then linked camera
        $cameraName = '';
        if (!empty($row['custom_camera'])) {
            $cameraName = $row['custom_camera'];
        } elseif (!empty($row['exif_make']) || !empty($row['exif_model'])) {
            $make = $row['exif_make'] ?? '';
            $model = $row['exif_model'] ?? '';
            // Avoid duplication if Model already contains Make
            if ($make && $model && stripos($model, $make) === 0) {
                $cameraName = $model;
            } else {
                $cameraName = trim($make . ' ' . $model);
            }
        } elseif (!empty($row['camera_make']) || !empty($row['camera_model'])) {
            $cameraName = trim(($row['camera_make'] ?? '') . ' ' . ($row['camera_model'] ?? ''));
        }
        if ($cameraName) {
            $equipment[] = ['label' => 'Camera', 'value' => $cameraName, 'icon' => 'fa-camera'];
        }

        // Lens: prefer custom_lens, then EXIF lens, then linked lens
        $lensName = '';
        if (!empty($row['custom_lens'])) {
            $lensName = $row['custom_lens'];
        } elseif (!empty($row['exif_lens_model'])) {
            $lensName = $row['exif_lens_model'];
        } elseif (!empty($row['lens_brand']) || !empty($row['lens_model'])) {
            $lensName = trim(($row['lens_brand'] ?? '') . ' ' . ($row['lens_model'] ?? ''));
        }
        if ($lensName) {
            $equipment[] = ['label' => 'Lens', 'value' => $lensName, 'icon' => 'fa-circle-dot'];
        }

        // Film
        $filmName = '';
        if (!empty($row['custom_film'])) {
            $filmName = $row['custom_film'];
        } elseif (!empty($row['film_brand']) || !empty($row['film_name'])) {
            $filmName = trim(($row['film_brand'] ?? '') . ' ' . ($row['film_name'] ?? ''));
        }
        if ($filmName) {
            $equipment[] = ['label' => 'Film', 'value' => $filmName, 'icon' => 'fa-film'];
        }

        if (!empty($equipment)) {
            $result['sections'][] = ['title' => 'Equipment', 'items' => $equipment];
        }

        // Exposure section
        $exposure = [];
        if (!empty($row['focal_length'])) {
            $exposure[] = ['label' => 'Focal Length', 'value' => $row['focal_length'] . 'mm', 'icon' => 'fa-arrows-alt-h'];
        }
        if (!empty($row['aperture'])) {
            $exposure[] = ['label' => 'Aperture', 'value' => 'f/' . $row['aperture'], 'icon' => 'fa-circle'];
        }
        if (!empty($row['shutter_speed'])) {
            $shutterDisplay = $this->formatShutterSpeedForDisplay($row['shutter_speed']);
            $exposure[] = ['label' => 'Shutter Speed', 'value' => $shutterDisplay, 'icon' => 'fa-clock'];
        }
        if (!empty($row['iso'])) {
            $exposure[] = ['label' => 'ISO', 'value' => (string)$row['iso'], 'icon' => 'fa-signal'];
        }
        if ($row['exposure_bias'] !== null && $row['exposure_bias'] != 0) {
            $exposure[] = ['label' => 'Exposure Bias', 'value' => ($row['exposure_bias'] >= 0 ? '+' : '') . $row['exposure_bias'] . ' EV', 'icon' => 'fa-adjust'];
        }
        if (!empty($exposure)) {
            $result['sections'][] = ['title' => 'Exposure', 'items' => $exposure];
        }

        // Mode section
        $mode = [];
        if ($row['exposure_program'] !== null) {
            $programLabels = [0 => 'Not Defined', 1 => 'Manual', 2 => 'Program AE', 3 => 'Aperture Priority', 4 => 'Shutter Priority', 5 => 'Creative', 6 => 'Action', 7 => 'Portrait', 8 => 'Landscape'];
            if (isset($programLabels[$row['exposure_program']])) {
                $mode[] = ['label' => 'Exposure Program', 'value' => $programLabels[$row['exposure_program']], 'icon' => 'fa-sliders-h'];
            }
        }
        if ($row['metering_mode'] !== null) {
            $meterLabels = [0 => 'Unknown', 1 => 'Average', 2 => 'Center-weighted', 3 => 'Spot', 4 => 'Multi-spot', 5 => 'Multi-segment', 6 => 'Partial'];
            if (isset($meterLabels[$row['metering_mode']])) {
                $mode[] = ['label' => 'Metering', 'value' => $meterLabels[$row['metering_mode']], 'icon' => 'fa-bullseye'];
            }
        }
        if ($row['flash'] !== null) {
            $flashFired = ($row['flash'] & 1) === 1;
            $mode[] = ['label' => 'Flash', 'value' => $flashFired ? 'Fired' : 'Not Fired', 'icon' => 'fa-bolt'];
        }
        if ($row['white_balance'] !== null) {
            $mode[] = ['label' => 'White Balance', 'value' => $row['white_balance'] == 0 ? 'Auto' : 'Manual', 'icon' => 'fa-thermometer-half'];
        }
        if (!empty($mode)) {
            $result['sections'][] = ['title' => 'Mode', 'items' => $mode];
        }

        // Details section
        $details = [];
        if (!empty($row['date_original'])) {
            $details[] = ['label' => 'Date Taken', 'value' => $row['date_original'], 'icon' => 'fa-calendar'];
        }
        if ($row['color_space'] !== null) {
            $details[] = ['label' => 'Color Space', 'value' => $row['color_space'] == 1 ? 'sRGB' : 'Adobe RGB', 'icon' => 'fa-palette'];
        }
        if (!empty($details)) {
            $result['sections'][] = ['title' => 'Details', 'items' => $details];
        }

        // Location section
        $location = [];
        if (!empty($row['gps_lat']) && !empty($row['gps_lng'])) {
            $location[] = ['label' => 'GPS', 'value' => round($row['gps_lat'], 6) . ', ' . round($row['gps_lng'], 6), 'icon' => 'fa-map-marker-alt'];
        }
        if (!empty($location)) {
            $result['sections'][] = ['title' => 'Location', 'items' => $location];
        }

        // Info section
        $info = [];
        if (!empty($row['artist'])) {
            $info[] = ['label' => 'Artist', 'value' => $row['artist'], 'icon' => 'fa-user'];
        }
        if (!empty($row['copyright'])) {
            $info[] = ['label' => 'Copyright', 'value' => $row['copyright'], 'icon' => 'fa-copyright'];
        }
        if (!empty($row['software'])) {
            $info[] = ['label' => 'Software', 'value' => $row['software'], 'icon' => 'fa-wand-magic-sparkles'];
        }
        if (!empty($info)) {
            $result['sections'][] = ['title' => 'Info', 'items' => $info];
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Format shutter speed for display, handling both raw EXIF fractions and pre-formatted values.
     * Converts raw values like "300000/10000000" to human-readable "1/33s".
     */
    private function formatShutterSpeedForDisplay(string $value): string
    {
        // Already formatted (starts with "1/" and is short, or ends with "s")
        if (preg_match('/^1\/\d{1,4}$/', $value) || preg_match('/^\d+s$/', $value)) {
            return $value;
        }

        // Raw fraction format from EXIF (e.g., "300000/10000000")
        if (str_contains($value, '/')) {
            $parts = explode('/', $value, 2);
            if (count($parts) === 2) {
                $num = (float)$parts[0];
                $den = (float)$parts[1];
                if ($den > 0) {
                    $speed = $num / $den;
                    if ($speed >= 1) {
                        return (int)round($speed) . 's';
                    } elseif ($speed > 0) {
                        return '1/' . (int)round(1 / $speed) . 's';
                    }
                }
            }
        }

        // Return as-is if we can't parse it
        return $value;
    }
}
