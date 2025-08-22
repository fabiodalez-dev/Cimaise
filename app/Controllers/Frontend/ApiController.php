<?php
declare(strict_types=1);

namespace App\Controllers\Frontend;

use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class ApiController
{
    public function __construct(private Database $db, private Twig $view) {}

    public function albums(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(50, max(5, (int)($params['per_page'] ?? 12)));
        $offset = ($page - 1) * $perPage;
        
        // Filters
        $category = trim((string)($params['category'] ?? ''));
        $tags = array_filter(explode(',', trim((string)($params['tags'] ?? ''))));
        $process = trim((string)($params['process'] ?? ''));
        $camera = trim((string)($params['camera'] ?? ''));
        $film = trim((string)($params['film'] ?? ''));
        $sort = trim((string)($params['sort'] ?? 'published_desc'));
        
        $pdo = $this->db->pdo();
        
        // Build query
        $where = ['a.is_published = 1'];
        $binds = [];
        $joins = ['JOIN categories c ON c.id = a.category_id'];
        
        if ($category) {
            $where[] = 'c.slug = :category';
            $binds[':category'] = $category;
        }
        
        if (!empty($tags)) {
            $joins[] = 'LEFT JOIN album_tag at ON at.album_id = a.id';
            $joins[] = 'LEFT JOIN tags t ON t.id = at.tag_id';
            $tagPlaceholders = [];
            foreach ($tags as $i => $tag) {
                $placeholder = ":tag$i";
                $tagPlaceholders[] = $placeholder;
                $binds[$placeholder] = $tag;
            }
            $where[] = 't.slug IN (' . implode(',', $tagPlaceholders) . ')';
        }
        
        // Additional filters for album images (process, camera, film)
        if ($process || $camera || $film) {
            $joins[] = 'LEFT JOIN images img ON img.album_id = a.id';
            
            if ($process) {
                $where[] = 'img.process = :process';
                $binds[':process'] = $process;
            }
            
            if ($camera) {
                $joins[] = 'LEFT JOIN cameras cam ON cam.id = img.camera_id';
                // SQLite does not support CONCAT; use fields separately for portability
                $where[] = "(cam.make LIKE :camera OR cam.model LIKE :camera OR img.custom_camera LIKE :camera)";
                $binds[':camera'] = '%' . $camera . '%';
            }
            
            if ($film) {
                $joins[] = 'LEFT JOIN films f ON f.id = img.film_id';
                $where[] = "(f.name LIKE :film OR img.custom_film LIKE :film)";
                $binds[':film'] = '%' . $film . '%';
            }
        }
        
        $orderBy = match ($sort) {
            'published_asc' => 'a.published_at ASC',
            'shoot_date_desc' => 'a.shoot_date DESC',
            'shoot_date_asc' => 'a.shoot_date ASC',
            'title_asc' => 'a.title ASC',
            'title_desc' => 'a.title DESC',
            default => 'a.published_at DESC'
        };
        
        // Count query
        $countSql = "SELECT COUNT(DISTINCT a.id) FROM albums a " . implode(' ', $joins) . 
                   " WHERE " . implode(' AND ', $where);
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($binds);
        $total = (int)$countStmt->fetchColumn();
        
        // Data query
        $dataSql = "SELECT DISTINCT a.*, c.name as category_name, c.slug as category_slug 
                   FROM albums a " . implode(' ', $joins) . 
                   " WHERE " . implode(' AND ', $where) . 
                   " ORDER BY $orderBy 
                   LIMIT :limit OFFSET :offset";
        
        $binds[':limit'] = $perPage;
        $binds[':offset'] = $offset;
        
        $stmt = $pdo->prepare($dataSql);
        foreach ($binds as $key => $value) {
            $type = is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
        $stmt->execute();
        $albums = $stmt->fetchAll();
        
        // Enrich with cover images and tags
        foreach ($albums as &$album) {
            // Cover image
            $coverStmt = $pdo->prepare('SELECT * FROM images WHERE id = :cover_id');
            $coverStmt->execute([':cover_id' => $album['cover_image_id']]);
            $album['cover'] = $coverStmt->fetch() ?: null;
            
            if ($album['cover']) {
                // Get cover variants
                $variantsStmt = $pdo->prepare('SELECT * FROM image_variants WHERE image_id = :id');
                $variantsStmt->execute([':id' => $album['cover']['id']]);
                $album['cover']['variants'] = $variantsStmt->fetchAll();
            }
            
            // Tags
            $tagsStmt = $pdo->prepare('SELECT t.* FROM tags t JOIN album_tag at ON at.tag_id = t.id WHERE at.album_id = :id');
            $tagsStmt->execute([':id' => $album['id']]);
            $album['tags'] = $tagsStmt->fetchAll();
        }
        
        // Render cards HTML
        $itemsHtml = $this->view->fetchFromString(
            '{% for album in albums %}{% include "frontend/_album_card.twig" %}{% endfor %}',
            ['albums' => $albums]
        );
        
        $data = [
            'itemsHtml' => $itemsHtml,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => (int)ceil($total / $perPage),
                'has_next' => $page < ceil($total / $perPage),
                'has_prev' => $page > 1
            ],
            'filters' => [
                'category' => $category,
                'tags' => $tags,
                'process' => $process,
                'camera' => $camera,
                'film' => $film,
                'sort' => $sort
            ]
        ];
        
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function albumImages(Request $request, Response $response, array $args): Response
    {
        $albumId = (int)($args['id'] ?? 0);
        $params = $request->getQueryParams();
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(5, (int)($params['per_page'] ?? 24)));
        $offset = ($page - 1) * $perPage;
        
        // Filters
        $process = trim((string)($params['process'] ?? ''));
        $camera = trim((string)($params['camera'] ?? ''));
        $film = trim((string)($params['film'] ?? ''));
        $lens = trim((string)($params['lens'] ?? ''));
        
        $pdo = $this->db->pdo();
        
        // Check album exists and is published
        $albumStmt = $pdo->prepare('SELECT * FROM albums WHERE id = :id AND is_published = 1');
        $albumStmt->execute([':id' => $albumId]);
        $album = $albumStmt->fetch();
        
        if (!$album) {
            return $response->withStatus(404);
        }
        
        // Build images query
        $where = ['i.album_id = :album_id'];
        $binds = [':album_id' => $albumId];
        $joins = [];
        
        if ($process) {
            $where[] = 'i.process = :process';
            $binds[':process'] = $process;
        }
        
        if ($camera) {
            $joins[] = 'LEFT JOIN cameras cam ON cam.id = i.camera_id';
            $where[] = "(cam.make LIKE :camera OR cam.model LIKE :camera OR i.custom_camera LIKE :camera)";
            $binds[':camera'] = '%' . $camera . '%';
        }
        
        if ($film) {
            $joins[] = 'LEFT JOIN films f ON f.id = i.film_id';
            $where[] = "(f.name LIKE :film OR i.custom_film LIKE :film)";
            $binds[':film'] = '%' . $film . '%';
        }
        
        if ($lens) {
            $joins[] = 'LEFT JOIN lenses l ON l.id = i.lens_id';
            $where[] = "(l.brand LIKE :lens OR l.model LIKE :lens OR i.custom_lens LIKE :lens)";
            $binds[':lens'] = '%' . $lens . '%';
        }
        
        // Count
        $countSql = "SELECT COUNT(*) FROM images i " . implode(' ', $joins) . 
                   " WHERE " . implode(' AND ', $where);
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($binds);
        $total = (int)$countStmt->fetchColumn();
        
        // Data
        $dataSql = "SELECT i.* FROM images i " . implode(' ', $joins) . 
                  " WHERE " . implode(' AND ', $where) . 
                  " ORDER BY i.sort_order ASC, i.id ASC 
                  LIMIT :limit OFFSET :offset";
        
        $binds[':limit'] = $perPage;
        $binds[':offset'] = $offset;
        
        $stmt = $pdo->prepare($dataSql);
        foreach ($binds as $key => $value) {
            $type = is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
            $stmt->bindValue($key, $value, $type);
        }
        $stmt->execute();
        $images = $stmt->fetchAll();
        
        // Get variants for each image
        foreach ($images as &$image) {
            $variantsStmt = $pdo->prepare('SELECT * FROM image_variants WHERE image_id = :id');
            $variantsStmt->execute([':id' => $image['id']]);
            $image['variants'] = $variantsStmt->fetchAll();
        }
        
        // Render images HTML
        $itemsHtml = $this->view->fetchFromString(
            '{% for image in images %}{% include "frontend/_image_item.twig" %}{% endfor %}',
            ['images' => $images, 'album' => $album]
        );
        
        $data = [
            'itemsHtml' => $itemsHtml,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => (int)ceil($total / $perPage),
                'has_next' => $page < ceil($total / $perPage),
                'has_prev' => $page > 1
            ],
            'album' => $album,
            'filters' => [
                'process' => $process,
                'camera' => $camera,
                'film' => $film,
                'lens' => $lens
            ]
        ];
        
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
