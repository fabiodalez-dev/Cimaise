<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AlbumsController
{
    public function __construct(private Database $db, private Twig $view)
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $page = max(1, (int)($request->getQueryParams()['page'] ?? 1));
        $perPage = 10;
        $offset = ($page - 1) * $perPage;

        $pdo = $this->db->pdo();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM albums')->fetchColumn();
        $orderParam = strtolower((string)($request->getQueryParams()['order'] ?? 'manual'));
        $orderParam = in_array($orderParam, ['manual','date'], true) ? $orderParam : 'manual';
        $orderBy = $orderParam === 'date'
            ? ($this->db->orderByNullsLast('a.published_at') . ' DESC, a.sort_order ASC')
            : ('a.sort_order ASC, ' . $this->db->orderByNullsLast('a.published_at') . ' DESC');
        $stmt = $pdo->prepare('SELECT a.id, a.title, a.slug, a.is_published, a.published_at, c.name AS category,
                               COALESCE(iv.path, i.original_path) AS cover_path
                               FROM albums a JOIN categories c ON c.id = a.category_id
                               LEFT JOIN images i ON i.id = a.cover_image_id
                               LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = \'sm\' AND iv.format = \'jpg\'
                               ORDER BY ' . $orderBy . '
                               LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $pages = (int)ceil(($total ?: 0) / $perPage);
        $pagination = [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => $pages,
            'has_next' => $page < $pages,
            'has_prev' => $page > 1,
        ];
        return $this->view->render($response, 'admin/albums/index.twig', [
            'items' => $rows,
            'page' => $page,
            'pages' => $pages,
            'pagination' => $pagination,
            'order_mode' => $orderParam,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $pdo = $this->db->pdo();
        $cats = $pdo->query('SELECT id, name FROM categories ORDER BY sort_order, name')->fetchAll();
        $tags = $pdo->query('SELECT id, name FROM tags ORDER BY name')->fetchAll();
        
        // Load templates if table exists
        $templates = [];
        try {
            $templates = $pdo->query('SELECT id, name FROM templates ORDER BY name')->fetchAll();
        } catch (\Throwable $e) {
            // Templates table doesn't exist yet, continue without templates
        }
        
        // Load equipment data
        $cameras = $pdo->query('SELECT id, make, model FROM cameras ORDER BY make, model')->fetchAll();
        $lenses = $pdo->query('SELECT id, brand, model FROM lenses ORDER BY brand, model')->fetchAll();
        $films = $pdo->query('SELECT id, brand, name FROM films ORDER BY brand, name')->fetchAll();
        $developers = $pdo->query('SELECT id, name FROM developers ORDER BY name')->fetchAll();
        $labs = $pdo->query('SELECT id, name FROM labs ORDER BY name')->fetchAll();
        
        return $this->view->render($response, 'admin/albums/create.twig', [
            'categories' => $cats,
            'tags' => $tags,
            'templates' => $templates,
            'cameras' => $cameras,
            'lenses' => $lenses,
            'films' => $films,
            'developers' => $developers,
            'labs' => $labs,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $d = (array)$request->getParsedBody();
        $title = trim((string)($d['title'] ?? ''));
        $slug = trim((string)($d['slug'] ?? ''));
        $categoryIds = array_map('intval', (array)($d['categories'] ?? []));
        $category_id = (int)($d['category_id'] ?? ($categoryIds[0] ?? 0));
        $excerpt = trim((string)($d['excerpt'] ?? '')) ?: null;
        $shoot_date = (string)($d['shoot_date'] ?? '') ?: null;
        $show_date = isset($d['show_date']) ? 1 : 0;
        $is_published = isset($d['is_published']) ? 1 : 0;
        $sort_order = (int)($d['sort_order'] ?? 0);
        $template_id = (int)($d['template_id'] ?? 0) ?: null;
        $tagIds = array_map('intval', (array)($d['tags'] ?? []));
        $cameraIds = array_map('intval', (array)($d['cameras'] ?? []));
        $lensIds = array_map('intval', (array)($d['lenses'] ?? []));
        $filmIds = array_map('intval', (array)($d['films'] ?? []));
        $developerIds = array_map('intval', (array)($d['developers'] ?? []));
        $labIds = array_map('intval', (array)($d['labs'] ?? []));
        
        if ($title === '' || $category_id <= 0) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Titolo e categoria sono obbligatori'];
            return $response->withHeader('Location', '/admin/albums/create')->withStatus(302);
        }
        $slug = $slug !== '' ? \App\Support\Str::slug($slug) : \App\Support\Str::slug($title);
        $published_at = $is_published ? date('Y-m-d H:i:s') : null;
        $pdo = $this->db->pdo();
        // Try with template_id first, fallback without it if column doesn't exist
        try {
            $stmt = $pdo->prepare('INSERT INTO albums(title, slug, category_id, excerpt, shoot_date, show_date, is_published, published_at, sort_order, template_id) VALUES(:t,:s,:c,:e,:sd,:sh,:p,:pa,:o,:ti)');
            $stmt->execute([':t'=>$title,':s'=>$slug,':c'=>$category_id,':e'=>$excerpt,':sd'=>$shoot_date,':sh'=>$show_date,':p'=>$is_published,':pa'=>$published_at,':o'=>$sort_order,':ti'=>$template_id]);
        } catch (\Throwable $e) {
            // Fallback for old DB schema without template_id column
            $stmt = $pdo->prepare('INSERT INTO albums(title, slug, category_id, excerpt, shoot_date, show_date, is_published, published_at, sort_order) VALUES(:t,:s,:c,:e,:sd,:sh,:p,:pa,:o)');
            $stmt->execute([':t'=>$title,':s'=>$slug,':c'=>$category_id,':e'=>$excerpt,':sd'=>$shoot_date,':sh'=>$show_date,':p'=>$is_published,':pa'=>$published_at,':o'=>$sort_order]);
        }
        try {
            $albumId = (int)$pdo->lastInsertId();
            // sync categories (pivot)
            if ($category_id > 0 || !empty($categoryIds)) {
                $cats = array_unique(array_filter(array_map('intval', array_merge([$category_id], $categoryIds))));
                if ($cats) {
                    $catStmt = $pdo->prepare('INSERT OR IGNORE INTO album_category(album_id, category_id) VALUES(:a,:c)');
                    if ($this->db->isMySQL()) {
                        $catStmt = $pdo->prepare('INSERT IGNORE INTO album_category(album_id, category_id) VALUES(:a,:c)');
                    }
                    foreach ($cats as $cid) { $catStmt->execute([':a'=>$albumId, ':c'=>$cid]); }
                }
            }
            if ($tagIds) {
                $tagStmt = $pdo->prepare('INSERT IGNORE INTO album_tag(album_id, tag_id) VALUES (:a, :t)');
                foreach (array_unique($tagIds) as $tid) {
                    $tagStmt->execute([':a'=>$albumId, ':t'=>$tid]);
                }
            }
            
            // Store equipment associations
            try {
                if ($cameraIds) {
                    $cameraStmt = $pdo->prepare('INSERT IGNORE INTO album_camera(album_id, camera_id) VALUES (:a, :c)');
                    foreach (array_unique($cameraIds) as $cid) {
                        $cameraStmt->execute([':a'=>$albumId, ':c'=>$cid]);
                    }
                }
                
                if ($lensIds) {
                    $lensStmt = $pdo->prepare('INSERT IGNORE INTO album_lens(album_id, lens_id) VALUES (:a, :l)');
                    foreach (array_unique($lensIds) as $lid) {
                        $lensStmt->execute([':a'=>$albumId, ':l'=>$lid]);
                    }
                }
                
                if ($filmIds) {
                    $filmStmt = $pdo->prepare('INSERT IGNORE INTO album_film(album_id, film_id) VALUES (:a, :f)');
                    foreach (array_unique($filmIds) as $fid) {
                        $filmStmt->execute([':a'=>$albumId, ':f'=>$fid]);
                    }
                }
                
                if ($developerIds) {
                    $developerStmt = $pdo->prepare('INSERT IGNORE INTO album_developer(album_id, developer_id) VALUES (:a, :d)');
                    foreach (array_unique($developerIds) as $did) {
                        $developerStmt->execute([':a'=>$albumId, ':d'=>$did]);
                    }
                }
                
                if ($labIds) {
                    $labStmt = $pdo->prepare('INSERT IGNORE INTO album_lab(album_id, lab_id) VALUES (:a, :l)');
                    foreach (array_unique($labIds) as $lid) {
                        $labStmt->execute([':a'=>$albumId, ':l'=>$lid]);
                    }
                }
            } catch (\Throwable $e) {
                // Equipment tables might not exist yet, continue without error
            }
            // If client expects JSON, return album id for AJAX flows (e.g., upload on create)
            $accept = $request->getHeaderLine('Accept');
            if (str_contains($accept, 'application/json')) {
                $payload = json_encode(['ok'=>true,'id'=>$albumId,'redirect'=>"/admin/albums/{$albumId}/edit"], JSON_UNESCAPED_SLASHES);
                $response->getBody()->write($payload);
                return $response->withHeader('Content-Type','application/json');
            }
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Album creato'];
            return $response->withHeader('Location', '/admin/albums')->withStatus(302);
        } catch (\Throwable $e) {
            $accept = $request->getHeaderLine('Accept');
            if (str_contains($accept, 'application/json')) {
                $response->getBody()->write(json_encode(['ok'=>false,'error'=>$e->getMessage()]));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Errore: '.$e->getMessage()];
            return $response->withHeader('Location', '/admin/albums/create')->withStatus(302);
        }
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT * FROM albums WHERE id=:id');
        $stmt->execute([':id'=>$id]);
        $item = $stmt->fetch();
        if (!$item) {
            $response->getBody()->write('Album non trovato');
            return $response->withStatus(404);
        }
        $cats = $pdo->query('SELECT id, name FROM categories ORDER BY sort_order, name')->fetchAll();
        $tags = $pdo->query('SELECT id, name FROM tags ORDER BY name')->fetchAll();
        
        // Load templates if table exists
        $templates = [];
        try {
            $templates = $pdo->query('SELECT id, name FROM templates ORDER BY name')->fetchAll();
        } catch (\Throwable $e) {
            // Templates table doesn't exist yet, continue without templates
        }
        
        // Load equipment data
        $cameras = $pdo->query('SELECT id, make, model FROM cameras ORDER BY make, model')->fetchAll();
        $lenses = $pdo->query('SELECT id, brand, model FROM lenses ORDER BY brand, model')->fetchAll();
        $films = $pdo->query('SELECT id, brand, name FROM films ORDER BY brand, name')->fetchAll();
        $developers = $pdo->query('SELECT id, name FROM developers ORDER BY name')->fetchAll();
        $labs = $pdo->query('SELECT id, name FROM labs ORDER BY name')->fetchAll();
        
        $curTags = $pdo->prepare('SELECT tag_id FROM album_tag WHERE album_id = :a');
        $curTags->execute([':a'=>$id]);
        $tagIds = array_map('intval', array_column($curTags->fetchAll(), 'tag_id'));
        $curCatsStmt = $pdo->prepare('SELECT category_id FROM album_category WHERE album_id = :a');
        $curCatsStmt->execute([':a'=>$id]);
        $categoryIds = array_unique(array_map('intval', array_merge([$item['category_id']], array_column($curCatsStmt->fetchAll() ?: [], 'category_id'))));
        
        // Load current equipment associations
        $cameraIds = [];
        $lensIds = [];
        $filmIds = [];
        $developerIds = [];
        $labIds = [];
        
        try {
            $cameraStmt = $pdo->prepare('SELECT camera_id FROM album_camera WHERE album_id = :a');
            $cameraStmt->execute([':a'=>$id]);
            $cameraIds = array_map('intval', array_column($cameraStmt->fetchAll(), 'camera_id'));
            
            $lensStmt = $pdo->prepare('SELECT lens_id FROM album_lens WHERE album_id = :a');
            $lensStmt->execute([':a'=>$id]);
            $lensIds = array_map('intval', array_column($lensStmt->fetchAll(), 'lens_id'));
            
            $filmStmt = $pdo->prepare('SELECT film_id FROM album_film WHERE album_id = :a');
            $filmStmt->execute([':a'=>$id]);
            $filmIds = array_map('intval', array_column($filmStmt->fetchAll(), 'film_id'));
            
            $developerStmt = $pdo->prepare('SELECT developer_id FROM album_developer WHERE album_id = :a');
            $developerStmt->execute([':a'=>$id]);
            $developerIds = array_map('intval', array_column($developerStmt->fetchAll(), 'developer_id'));
            
            $labStmt = $pdo->prepare('SELECT lab_id FROM album_lab WHERE album_id = :a');
            $labStmt->execute([':a'=>$id]);
            $labIds = array_map('intval', array_column($labStmt->fetchAll(), 'lab_id'));
        } catch (\Throwable $e) {
            // Equipment tables might not exist yet
        }
        
        $imgsStmt = $pdo->prepare('SELECT i.id, i.original_path, i.created_at, i.sort_order,
                                   COALESCE(iv.path, i.original_path) AS preview_path
                                   FROM images i
                                   LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = "sm" AND iv.format = "jpg"
                                   WHERE i.album_id=:a
                                   ORDER BY i.sort_order ASC, i.id ASC');
        $imgsStmt->execute([':a'=>$id]);
        $images = $imgsStmt->fetchAll();
        return $this->view->render($response, 'admin/albums/edit.twig', [
            'item' => $item,
            'categories' => $cats,
            'tags' => $tags,
            'templates' => $templates,
            'cameras' => $cameras,
            'lenses' => $lenses,
            'films' => $films,
            'developers' => $developers,
            'labs' => $labs,
            'tagIds' => $tagIds,
            'categoryIds' => $categoryIds,
            'cameraIds' => $cameraIds,
            'lensIds' => $lensIds,
            'filmIds' => $filmIds,
            'developerIds' => $developerIds,
            'labIds' => $labIds,
            'images' => $images,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $d = (array)$request->getParsedBody();
        $title = trim((string)($d['title'] ?? ''));
        $slug = trim((string)($d['slug'] ?? ''));
        $categoryIds = array_map('intval', (array)($d['categories'] ?? []));
        $category_id = (int)($d['category_id'] ?? ($categoryIds[0] ?? 0));
        $excerpt = trim((string)($d['excerpt'] ?? '')) ?: null;
        $shoot_date = (string)($d['shoot_date'] ?? '') ?: null;
        $show_date = isset($d['show_date']) ? 1 : 0;
        $is_published = isset($d['is_published']) ? 1 : 0;
        $sort_order = (int)($d['sort_order'] ?? 0);
        $template_id = (int)($d['template_id'] ?? 0) ?: null;
        $tagIds = array_map('intval', (array)($d['tags'] ?? []));
        $cameraIds = array_map('intval', (array)($d['cameras'] ?? []));
        $lensIds = array_map('intval', (array)($d['lenses'] ?? []));
        $filmIds = array_map('intval', (array)($d['films'] ?? []));
        $developerIds = array_map('intval', (array)($d['developers'] ?? []));
        $labIds = array_map('intval', (array)($d['labs'] ?? []));
        
        if ($title === '' || $category_id <= 0) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Titolo e categoria sono obbligatori'];
            return $response->withHeader('Location', '/admin/albums/'.$id.'/edit')->withStatus(302);
        }
        $slug = $slug !== '' ? \App\Support\Str::slug($slug) : \App\Support\Str::slug($title);
        $published_at = $is_published ? (date('Y-m-d H:i:s')) : null;
        $pdo = $this->db->pdo();
        // Try with template_id first, fallback without it if column doesn't exist
        try {
            $stmt = $pdo->prepare('UPDATE albums SET title=:t, slug=:s, category_id=:c, excerpt=:e, shoot_date=:sd, show_date=:sh, is_published=:p, published_at=:pa, sort_order=:o, template_id=:ti WHERE id=:id');
            $stmt->execute([':t'=>$title,':s'=>$slug,':c'=>$category_id,':e'=>$excerpt,':sd'=>$shoot_date,':sh'=>$show_date,':p'=>$is_published,':pa'=>$published_at,':o'=>$sort_order,':ti'=>$template_id, ':id'=>$id]);
        } catch (\Throwable $e) {
            // Fallback for old DB schema without template_id column
            $stmt = $pdo->prepare('UPDATE albums SET title=:t, slug=:s, category_id=:c, excerpt=:e, shoot_date=:sd, show_date=:sh, is_published=:p, published_at=:pa, sort_order=:o WHERE id=:id');
            $stmt->execute([':t'=>$title,':s'=>$slug,':c'=>$category_id,':e'=>$excerpt,':sd'=>$shoot_date,':sh'=>$show_date,':p'=>$is_published,':pa'=>$published_at,':o'=>$sort_order, ':id'=>$id]);
        }
        try {
            // sync tags
            $pdo->prepare('DELETE FROM album_tag WHERE album_id=:a')->execute([':a'=>$id]);
            if ($tagIds) {
                $tagStmt = $pdo->prepare('INSERT IGNORE INTO album_tag(album_id, tag_id) VALUES (:a, :t)');
                foreach (array_unique($tagIds) as $tid) {
                    $tagStmt->execute([':a'=>$id, ':t'=>$tid]);
                }
            }
            // sync categories pivot
            $pdo->prepare('DELETE FROM album_category WHERE album_id=:a')->execute([':a'=>$id]);
            if ($category_id > 0 || !empty($categoryIds)) {
                $cats = array_unique(array_filter(array_map('intval', array_merge([$category_id], $categoryIds))));
                if ($cats) {
                    $catStmt = $pdo->prepare('INSERT OR IGNORE INTO album_category(album_id, category_id) VALUES(:a,:c)');
                    if ($this->db->isMySQL()) {
                        $catStmt = $pdo->prepare('INSERT IGNORE INTO album_category(album_id, category_id) VALUES(:a,:c)');
                    }
                    foreach ($cats as $cid) { $catStmt->execute([':a'=>$id, ':c'=>$cid]); }
                }
            }
            
            // Sync equipment associations
            try {
                // Delete existing equipment associations
                $pdo->prepare('DELETE FROM album_camera WHERE album_id=:a')->execute([':a'=>$id]);
                $pdo->prepare('DELETE FROM album_lens WHERE album_id=:a')->execute([':a'=>$id]);
                $pdo->prepare('DELETE FROM album_film WHERE album_id=:a')->execute([':a'=>$id]);
                $pdo->prepare('DELETE FROM album_developer WHERE album_id=:a')->execute([':a'=>$id]);
                $pdo->prepare('DELETE FROM album_lab WHERE album_id=:a')->execute([':a'=>$id]);
                
                // Insert new equipment associations
                if ($cameraIds) {
                    $cameraStmt = $pdo->prepare('INSERT IGNORE INTO album_camera(album_id, camera_id) VALUES (:a, :c)');
                    foreach (array_unique($cameraIds) as $cid) {
                        $cameraStmt->execute([':a'=>$id, ':c'=>$cid]);
                    }
                }
                
                if ($lensIds) {
                    $lensStmt = $pdo->prepare('INSERT IGNORE INTO album_lens(album_id, lens_id) VALUES (:a, :l)');
                    foreach (array_unique($lensIds) as $lid) {
                        $lensStmt->execute([':a'=>$id, ':l'=>$lid]);
                    }
                }
                
                if ($filmIds) {
                    $filmStmt = $pdo->prepare('INSERT IGNORE INTO album_film(album_id, film_id) VALUES (:a, :f)');
                    foreach (array_unique($filmIds) as $fid) {
                        $filmStmt->execute([':a'=>$id, ':f'=>$fid]);
                    }
                }
                
                if ($developerIds) {
                    $developerStmt = $pdo->prepare('INSERT IGNORE INTO album_developer(album_id, developer_id) VALUES (:a, :d)');
                    foreach (array_unique($developerIds) as $did) {
                        $developerStmt->execute([':a'=>$id, ':d'=>$did]);
                    }
                }
                
                if ($labIds) {
                    $labStmt = $pdo->prepare('INSERT IGNORE INTO album_lab(album_id, lab_id) VALUES (:a, :l)');
                    foreach (array_unique($labIds) as $lid) {
                        $labStmt->execute([':a'=>$id, ':l'=>$lid]);
                    }
                }
            } catch (\Throwable $e) {
                // Equipment tables might not exist yet, continue without error
            }
            
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Album aggiornato'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Errore: '.$e->getMessage()];
        }
        return $response->withHeader('Location', '/admin/albums')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('DELETE FROM albums WHERE id=:id');
        try {
            $stmt->execute([':id'=>$id]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Album eliminato'];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Errore: '.$e->getMessage()];
        }
        return $response->withHeader('Location', '/admin/albums')->withStatus(302);
    }

    public function publish(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        // Use portable CURRENT_TIMESTAMP instead of MySQL-specific NOW()
        $stmt = $this->db->pdo()->prepare('UPDATE albums SET is_published=1, published_at=CURRENT_TIMESTAMP WHERE id=:id');
        $stmt->execute([':id'=>$id]);
        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Album pubblicato'];
        return $response->withHeader('Location', '/admin/albums')->withStatus(302);
    }

    public function unpublish(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('UPDATE albums SET is_published=0, published_at=NULL WHERE id=:id');
        $stmt->execute([':id'=>$id]);
        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Album in bozza'];
        return $response->withHeader('Location', '/admin/albums')->withStatus(302);
    }

    public function setCover(Request $request, Response $response, array $args): Response
    {
        $albumId = (int)($args['id'] ?? 0);
        $imageId = (int)($args['imageId'] ?? 0);
        // ensure image belongs to album
        $check = $this->db->pdo()->prepare('SELECT 1 FROM images WHERE id=:img AND album_id=:a');
        $check->execute([':img'=>$imageId, ':a'=>$albumId]);
        if (!$check->fetchColumn()) {
            $_SESSION['flash'][] = ['type'=>'danger','message'=>'Immagine non appartiene a questo album'];
            return $response->withHeader('Location', '/admin/albums/'.$albumId.'/edit')->withStatus(302);
        }
        $stmt = $this->db->pdo()->prepare('UPDATE albums SET cover_image_id=:img WHERE id=:id');
        $stmt->execute([':img'=>$imageId, ':id'=>$albumId]);
        $accept = $request->getHeaderLine('Accept');
        if (str_contains($accept, 'application/json')) {
            $response->getBody()->write(json_encode(['ok'=>true]));
            return $response->withHeader('Content-Type','application/json');
        }
        $_SESSION['flash'][] = ['type' => 'success', 'message' => 'Cover aggiornata'];
        return $response->withHeader('Location', '/admin/albums/'.$albumId.'/edit')->withStatus(302);
    }

    public function reorderImages(Request $request, Response $response, array $args): Response
    {
        $albumId = (int)($args['id'] ?? 0);
        $data = (array)$request->getParsedBody();
        $ids = (array)($data['order'] ?? []);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $sort = 0;
            $stmt = $pdo->prepare('UPDATE images SET sort_order=:s WHERE id=:id AND album_id=:a');
            foreach ($ids as $imageId) {
                $stmt->execute([':s'=>$sort++, ':id'=>(int)$imageId, ':a'=>$albumId]);
            }
            $pdo->commit();
            $payload = json_encode(['ok'=>true]);
            $response->getBody()->write($payload);
            return $response->withHeader('Content-Type','application/json');
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $response->getBody()->write(json_encode(['ok'=>false,'error'=>$e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type','application/json');
        }
    }

    public function reorderList(Request $request, Response $response): Response
    {
        $data = json_decode((string)$request->getBody(), true) ?: [];
        $ids = array_map('intval', (array)($data['order'] ?? []));
        if (!$ids) {
            $response->getBody()->write(json_encode(['ok'=>false,'error'=>'No IDs']));
            return $response->withStatus(400)->withHeader('Content-Type','application/json');
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $sort = 0;
            $stmt = $pdo->prepare('UPDATE albums SET sort_order=:s WHERE id=:id');
            foreach ($ids as $id) { $stmt->execute([':s'=>$sort++, ':id'=>$id]); }
            $pdo->commit();
            $response->getBody()->write(json_encode(['ok'=>true]));
            return $response->withHeader('Content-Type','application/json');
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $response->getBody()->write(json_encode(['ok'=>false,'error'=>$e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type','application/json');
        }
    }

    public function updateTags(Request $request, Response $response, array $args): Response
    {
        $albumId = (int)($args['id'] ?? 0);
        $data = json_decode((string)$request->getBody(), true) ?: [];
        $tagIds = array_map('intval', (array)($data['tags'] ?? []));
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM album_tag WHERE album_id=:a')->execute([':a'=>$albumId]);
            if ($tagIds) {
                $stmt = $pdo->prepare('INSERT IGNORE INTO album_tag(album_id, tag_id) VALUES (:a, :t)');
                foreach (array_unique($tagIds) as $tid) {
                    $stmt->execute([':a'=>$albumId, ':t'=>$tid]);
                }
            }
            $pdo->commit();
            $response->getBody()->write(json_encode(['ok'=>true]));
            return $response->withHeader('Content-Type','application/json');
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $response->getBody()->write(json_encode(['ok'=>false,'error'=>$e->getMessage()]));
            return $response->withStatus(400)->withHeader('Content-Type','application/json');
        }
    }

    public function deleteImage(Request $request, Response $response, array $args): Response
    {
        $albumId = (int)($args['id'] ?? 0);
        $imageId = (int)($args['imageId'] ?? 0);
        $pdo = $this->db->pdo();
        // ensure image belongs to album
        $img = $pdo->prepare('SELECT id, original_path FROM images WHERE id=:img AND album_id=:a');
        $img->execute([':img'=>$imageId, ':a'=>$albumId]);
        $row = $img->fetch();
        if (!$row) {
            return $response->withStatus(404);
        }
        // collect variants
        $vars = $pdo->prepare('SELECT path FROM image_variants WHERE image_id=:img');
        $vars->execute([':img'=>$imageId]);
        $variantPaths = array_column($vars->fetchAll() ?: [], 'path');
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM image_variants WHERE image_id=:img')->execute([':img'=>$imageId]);
            $pdo->prepare('UPDATE albums SET cover_image_id=NULL WHERE id=:a AND cover_image_id=:img')->execute([':a'=>$albumId, ':img'=>$imageId]);
            $pdo->prepare('DELETE FROM images WHERE id=:img AND album_id=:a')->execute([':img'=>$imageId, ':a'=>$albumId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return $response->withStatus(500);
        }
        // try unlink files (best-effort)
        $root = dirname(__DIR__, 2);
        @unlink($root . $row['original_path']);
        foreach ($variantPaths as $p) {
            $abs = str_starts_with((string)$p, '/media/') ? ($root . '/public' . $p) : ($root . $p);
            @unlink($abs);
        }
        $response->getBody()->write(json_encode(['ok'=>true]));
        return $response->withHeader('Content-Type','application/json');
    }

    public function bulkDeleteImages(Request $request, Response $response, array $args): Response
    {
        $albumId = (int)($args['id'] ?? 0);
        $data = json_decode((string)$request->getBody(), true) ?: [];
        $ids = array_map('intval', (array)($data['ids'] ?? []));
        if (!$ids) return $response->withStatus(400);
        $pdo = $this->db->pdo();
        // fetch originals and variant paths
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, original_path FROM images WHERE album_id = ? AND id IN ($in)");
        $stmt->execute(array_merge([$albumId], $ids));
        $rows = $stmt->fetchAll() ?: [];
        $variantStmt = $pdo->prepare("SELECT path FROM image_variants WHERE image_id = ?");
        $files = [];
        foreach ($rows as $r) {
            $files[] = $r['original_path'];
            $variantStmt->execute([(int)$r['id']]);
            foreach ($variantStmt->fetchAll() ?: [] as $v) { $files[] = $v['path']; }
        }
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM image_variants WHERE image_id IN (' . $in . ')')->execute($ids);
            $pdo->prepare('UPDATE albums SET cover_image_id=NULL WHERE id=? AND cover_image_id IN (' . $in . ')')
                ->execute(array_merge([$albumId], $ids));
            $pdo->prepare('DELETE FROM images WHERE album_id=? AND id IN (' . $in . ')')->execute(array_merge([$albumId], $ids));
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
}
