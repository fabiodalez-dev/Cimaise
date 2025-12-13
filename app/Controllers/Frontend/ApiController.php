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

        // Enrich albums minimally (cover + tags)
        foreach ($albums as &$album) {
            $this->enrichAlbum($album);
        }

        // Render itemsHtml via Twig partial
        $itemsHtml = '';
        foreach ($albums as $a) {
            $itemsHtml .= $this->view->fetch('frontend/_album_card.twig', ['album' => $a]);
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
        $stmt = $pdo->prepare('SELECT id, is_published, password_hash FROM albums WHERE id = :id');
        $stmt->execute([':id' => $albumId]);
        $album = $stmt->fetch();

        if (!$album || !$album['is_published']) {
            $response->getBody()->write(json_encode(['error' => 'Album not found']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Check password protection (use same session key as PageController::unlockAlbum)
        if (!empty($album['password_hash'])) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            if (empty($_SESSION['album_access'][$albumId])) {
                $response->getBody()->write(json_encode(['error' => 'Album is password protected']));
                return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
            }
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
            $display['shutter'] = (string)$image['shutter_speed'];
        }
        if (!empty($image['iso'])) {
            $display['iso'] = 'ISO ' . (int)$image['iso'];
        }
        if (!empty($image['custom_film'])) { $display['film'] = $image['custom_film']; }
        if (!empty($image['process'])) { $display['process'] = ucfirst((string)$image['process']); }
        return $display;
    }
}

