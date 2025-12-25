<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Support\Database;
use App\Services\CustomFieldService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AlbumsController extends BaseController
{
    private ?CustomFieldService $customFieldService = null;

    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
        try {
            $this->customFieldService = new CustomFieldService($this->db->pdo());
        } catch (\Throwable) {
            // Service unavailable, continue without custom fields
        }
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
        $stmt = $pdo->prepare("SELECT a.id, a.title, a.slug, a.is_published, a.published_at, c.name AS category,
                               COALESCE(iv.path, i.original_path) AS cover_path
                               FROM albums a JOIN categories c ON c.id = a.category_id
                               LEFT JOIN images i ON i.id = a.cover_image_id
                               LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = 'sm' AND iv.format = 'jpg'
                               ORDER BY {$orderBy}
                               LIMIT :limit OFFSET :offset");
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
        $cats = $pdo->query('SELECT id, name, slug FROM categories ORDER BY COALESCE(parent_id, 0), sort_order, name')->fetchAll();
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
        
        // Load locations (optional table)
        try {
            $locations = $pdo->query('SELECT id, name FROM locations ORDER BY name')->fetchAll();
        } catch (\Throwable) {
            $locations = [];
        }

        // Load custom field types and values
        $customFieldTypes = [];
        $customFieldValues = [];
        if ($this->customFieldService) {
            try {
                $customFieldTypes = $this->customFieldService->getFieldTypes(includeSystem: false);
                foreach ($customFieldTypes as $type) {
                    $customFieldValues[$type['id']] = $this->customFieldService->getFieldValues((int)$type['id']);
                }
            } catch (\Throwable) {
                // Custom fields table may not exist yet
            }
        }

        return $this->view->render($response, 'admin/albums/create.twig', [
            'categories' => $cats,
            'tags' => $tags,
            'templates' => $templates,
            'cameras' => $cameras,
            'lenses' => $lenses,
            'films' => $films,
            'developers' => $developers,
            'labs' => $labs,
            'locations' => $locations,
            'customFieldTypes' => $customFieldTypes,
            'customFieldValues' => $customFieldValues,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $accept = $request->getHeaderLine('Accept');
            if (str_contains($accept, 'application/json')) {
                return $this->csrfErrorJson($response);
            }
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->redirect('/admin/albums/create'))->withStatus(302);
        }

        $d = (array)$request->getParsedBody();
        $title = trim((string)($d['title'] ?? ''));
        $slug = trim((string)($d['slug'] ?? ''));
        $categoryIds = array_map('intval', (array)($d['categories'] ?? []));
        $category_id = (int)($d['category_id'] ?? ($categoryIds[0] ?? 0));
        $excerpt = trim(strip_tags((string)($d['excerpt'] ?? ''))) ?: null;
        $bodyRaw = trim((string)($d['body'] ?? '')) ?: null;
        $body = $bodyRaw ? \App\Support\Sanitizer::html($bodyRaw) : null;
        $shoot_date = (string)($d['shoot_date'] ?? '') ?: null;
        $show_date = isset($d['show_date']) ? 1 : 0;
        $is_published = isset($d['is_published']) ? 1 : 0;
        $sort_order = (int)($d['sort_order'] ?? 0);
        $template_id = (int)($d['template_id'] ?? 0) ?: null;
        $tagIds = array_map('intval', (array)($d['tags'] ?? []));
        $allow_downloads = isset($d['allow_downloads']) ? 1 : 0;
        $is_nsfw = isset($d['is_nsfw']) ? 1 : 0;
        $passwordRaw = (string)($d['password'] ?? '');
        $password_hash = $passwordRaw !== '' ? password_hash($passwordRaw, PASSWORD_ARGON2ID) : null;
        $cameraIds = array_map('intval', (array)($d['cameras'] ?? []));
        $lensIds = array_map('intval', (array)($d['lenses'] ?? []));
        $filmIds = array_map('intval', (array)($d['films'] ?? []));
        $developerIds = array_map('intval', (array)($d['developers'] ?? []));
        $labIds = array_map('intval', (array)($d['labs'] ?? []));
        $locationIds = array_map('intval', (array)($d['locations'] ?? []));

        // SEO fields for new albums (set defaults)
        $seoTitle = trim((string)($d['seo_title'] ?? '')) ?: null;
        $seoDescription = trim((string)($d['seo_description'] ?? '')) ?: null;
        $seoKeywords = trim((string)($d['seo_keywords'] ?? '')) ?: null;
        $ogTitle = trim((string)($d['og_title'] ?? '')) ?: null;
        $ogDescription = trim((string)($d['og_description'] ?? '')) ?: null;
        $ogImagePath = trim((string)($d['og_image_path'] ?? '')) ?: null;
        $schemaType = trim((string)($d['schema_type'] ?? 'ImageGallery'));
        $schemaData = trim((string)($d['schema_data'] ?? '')) ?: null;
        $canonicalUrl = trim((string)($d['canonical_url'] ?? '')) ?: null;
        $robotsIndex = isset($d['robots_index']) ? 1 : 0;
        $robotsFollow = isset($d['robots_follow']) ? 1 : 0;
        
        // Custom equipment fields
        $customCameras = trim((string)($d['custom_cameras'] ?? '')) ?: null;
        $customLenses = trim((string)($d['custom_lenses'] ?? '')) ?: null;
        $customFilms = trim((string)($d['custom_films'] ?? '')) ?: null;
        $customDevelopers = trim((string)($d['custom_developers'] ?? '')) ?: null;
        $customLabs = trim((string)($d['custom_labs'] ?? '')) ?: null;
        
        if ($title === '' || $category_id <= 0) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.title_category_required')];
            return $response->withHeader('Location', $this->redirect('/admin/albums/create'))->withStatus(302);
        }
        $slug = $slug !== '' ? \App\Support\Str::slug($slug) : \App\Support\Str::slug($title);
        $published_at = $is_published ? date('Y-m-d H:i:s') : null;
        $pdo = $this->db->pdo();

        // Ensure unique slug by appending numeric suffix if needed
        $baseSlug = $slug;
        $counter = 2;
        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM albums WHERE slug = :s');
        $checkStmt->execute([':s' => $slug]);
        while ((int)$checkStmt->fetchColumn() > 0) {
            $slug = $baseSlug . '-' . $counter++;
            $checkStmt->execute([':s' => $slug]);
        }

        // Try with template_id, custom equipment fields, and SEO fields
        try {
            $stmt = $pdo->prepare('INSERT INTO albums(title, slug, category_id, excerpt, body, shoot_date, show_date, is_published, published_at, sort_order, template_id, custom_cameras, custom_lenses, custom_films, custom_developers, custom_labs, allow_downloads, is_nsfw, password_hash, seo_title, seo_description, seo_keywords, og_title, og_description, og_image_path, schema_type, schema_data, canonical_url, robots_index, robots_follow) VALUES(:t,:s,:c,:e,:b,:sd,:sh,:p,:pa,:o,:ti,:cc,:cl,:cf,:cd,:clab,:dl,:nsfw,:ph,:seo_title,:seo_desc,:seo_kw,:og_title,:og_desc,:og_img,:schema_type,:schema_data,:canonical_url,:robots_index,:robots_follow)');
            $stmt->execute([
                ':t'=>$title,':s'=>$slug,':c'=>$category_id,':e'=>$excerpt,':b'=>$body,':sd'=>$shoot_date,':sh'=>$show_date,':p'=>$is_published,':pa'=>$published_at,':o'=>$sort_order,':ti'=>$template_id,':cc'=>$customCameras,':cl'=>$customLenses,':cf'=>$customFilms,':cd'=>$customDevelopers,':clab'=>$customLabs, ':dl'=>$allow_downloads, ':nsfw'=>$is_nsfw, ':ph'=>$password_hash,
                ':seo_title'=>$seoTitle, ':seo_desc'=>$seoDescription, ':seo_kw'=>$seoKeywords,
                ':og_title'=>$ogTitle, ':og_desc'=>$ogDescription, ':og_img'=>$ogImagePath,
                ':schema_type'=>$schemaType, ':schema_data'=>$schemaData, ':canonical_url'=>$canonicalUrl,
                ':robots_index'=>$robotsIndex, ':robots_follow'=>$robotsFollow
            ]);
        } catch (\Throwable $e) {
            // Fallback for old DB schema without custom fields
            try {
                $stmt = $pdo->prepare('INSERT INTO albums(title, slug, category_id, excerpt, body, shoot_date, show_date, is_published, published_at, sort_order, template_id, allow_downloads, is_nsfw, password_hash) VALUES(:t,:s,:c,:e,:b,:sd,:sh,:p,:pa,:o,:ti,:dl,:nsfw,:ph)');
                $stmt->execute([':t'=>$title,':s'=>$slug,':c'=>$category_id,':e'=>$excerpt,':b'=>$body,':sd'=>$shoot_date,':sh'=>$show_date,':p'=>$is_published,':pa'=>$published_at,':o'=>$sort_order,':ti'=>$template_id, ':dl'=>$allow_downloads, ':nsfw'=>$is_nsfw, ':ph'=>$password_hash]);
            } catch (\Throwable $e2) {
                // Final fallback
                $stmt = $pdo->prepare('INSERT INTO albums(title, slug, category_id, excerpt, body, shoot_date, show_date, is_published, published_at, sort_order, allow_downloads) VALUES(:t,:s,:c,:e,:b,:sd,:sh,:p,:pa,:o,:dl)');
                $stmt->execute([':t'=>$title,':s'=>$slug,':c'=>$category_id,':e'=>$excerpt,':b'=>$body,':sd'=>$shoot_date,':sh'=>$show_date,':p'=>$is_published,':pa'=>$published_at,':o'=>$sort_order, ':dl'=>$allow_downloads]);
            }
        }
        try {
            $albumId = (int)$pdo->lastInsertId();
            // sync categories (pivot)
            if ($category_id > 0 || !empty($categoryIds)) {
                $cats = array_unique(array_filter(array_map('intval', array_merge([$category_id], $categoryIds))));
                if ($cats) {
                    $sql = $this->db->insertIgnoreKeyword() . ' INTO album_category(album_id, category_id) VALUES(:a,:c)';
                    $catStmt = $pdo->prepare($sql);
                    foreach ($cats as $cid) { $catStmt->execute([':a'=>$albumId, ':c'=>$cid]); }
                }
            }
            if ($tagIds) {
                $tagSql = $this->db->insertIgnoreKeyword() . ' INTO album_tag(album_id, tag_id) VALUES (:a, :t)';
                $tagStmt = $pdo->prepare($tagSql);
                foreach (array_unique($tagIds) as $tid) {
                    $tagStmt->execute([':a'=>$albumId, ':t'=>$tid]);
                }
            }
            
            // Store equipment associations
            try {
                if ($cameraIds) {
                    $cameraSql = $this->db->insertIgnoreKeyword() . ' INTO album_camera(album_id, camera_id) VALUES (:a, :c)';
                    $cameraStmt = $pdo->prepare($cameraSql);
                    foreach (array_unique($cameraIds) as $cid) {
                        $cameraStmt->execute([':a'=>$albumId, ':c'=>$cid]);
                    }
                }
                
                if ($lensIds) {
                    $lensSql = $this->db->insertIgnoreKeyword() . ' INTO album_lens(album_id, lens_id) VALUES (:a, :l)';
                    $lensStmt = $pdo->prepare($lensSql);
                    foreach (array_unique($lensIds) as $lid) {
                        $lensStmt->execute([':a'=>$albumId, ':l'=>$lid]);
                    }
                }
                
                if ($filmIds) {
                    $filmSql = $this->db->insertIgnoreKeyword() . ' INTO album_film(album_id, film_id) VALUES (:a, :f)';
                    $filmStmt = $pdo->prepare($filmSql);
                    foreach (array_unique($filmIds) as $fid) {
                        $filmStmt->execute([':a'=>$albumId, ':f'=>$fid]);
                    }
                }
                
                if ($developerIds) {
                    $developerSql = $this->db->insertIgnoreKeyword() . ' INTO album_developer(album_id, developer_id) VALUES (:a, :d)';
                    $developerStmt = $pdo->prepare($developerSql);
                    foreach (array_unique($developerIds) as $did) {
                        $developerStmt->execute([':a'=>$albumId, ':d'=>$did]);
                    }
                }
                
                if ($labIds) {
                    $labSql = $this->db->insertIgnoreKeyword() . ' INTO album_lab(album_id, lab_id) VALUES (:a, :l)';
                    $labStmt = $pdo->prepare($labSql);
                    foreach (array_unique($labIds) as $lid) {
                        $labStmt->execute([':a'=>$albumId, ':l'=>$lid]);
                    }
                }
                // Locations pivot
                if ($locationIds) {
                    $locSql = $this->db->insertIgnoreKeyword() . ' INTO album_location(album_id, location_id) VALUES (:a, :l)';
                    $locStmt = $pdo->prepare($locSql);
                    foreach (array_unique($locationIds) as $lid) {
                        $locStmt->execute([':a'=>$albumId, ':l'=>$lid]);
                    }
                }
            } catch (\Throwable $e) {
                // Equipment tables might not exist yet, continue without error
            }

            // Save custom field data
            if ($this->customFieldService) {
                try {
                    $customFields = (array)($d['custom_fields'] ?? []);
                    foreach ($customFields as $typeId => $values) {
                        // Filter empty values, keep both numeric IDs and string values
                        $values = array_filter((array)$values, fn($v) => $v !== '' && $v !== null);
                        if (!empty($values)) {
                            $this->customFieldService->setAlbumMetadata($albumId, (int)$typeId, array_values($values));
                        }
                    }
                } catch (\Throwable) {
                    // Custom fields table may not exist yet
                }
            }

            // If client expects JSON, return album id for AJAX flows (e.g., upload on create)
            $accept = $request->getHeaderLine('Accept');
            if (str_contains($accept, 'application/json')) {
                $payload = json_encode(['ok'=>true,'id'=>$albumId,'redirect'=>$this->redirect("/admin/albums/{$albumId}/edit")], JSON_UNESCAPED_SLASHES);
                $response->getBody()->write($payload);
                return $response->withHeader('Content-Type','application/json');
            }
            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.album_created')];
            return $response->withHeader('Location', $this->redirect('/admin/albums'))->withStatus(302);
        } catch (\Throwable $e) {
            $accept = $request->getHeaderLine('Accept');
            if (str_contains($accept, 'application/json')) {
                $response->getBody()->write(json_encode(['ok'=>false,'error'=>$e->getMessage()]));
                return $response->withStatus(400)->withHeader('Content-Type','application/json');
            }
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Error: '.$e->getMessage()];
            return $response->withHeader('Location', $this->redirect('/admin/albums/create'))->withStatus(302);
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
            $response->getBody()->write(trans('admin.flash.album_not_found'));
            return $response->withStatus(404);
        }
        // Add password flag for template (checks password_hash existence)
        $item['password'] = !empty($item['password_hash']);

        $cats = $pdo->query('SELECT id, name FROM categories ORDER BY COALESCE(parent_id, 0), sort_order, name')->fetchAll();
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
        
        // Load locations
        try {
            $locations = $pdo->query('SELECT id, name FROM locations ORDER BY name')->fetchAll();
        } catch (\Throwable) { 
            $locations = []; 
        }
        
        $curTags = $pdo->prepare('SELECT tag_id FROM album_tag WHERE album_id = :a');
        $curTags->execute([':a'=>$id]);
        $tagIds = array_map('intval', array_column($curTags->fetchAll(), 'tag_id'));
        $curCatsStmt = $pdo->prepare('SELECT category_id FROM album_category WHERE album_id = :a');
        $curCatsStmt->execute([':a'=>$id]);
        $baseCats = $item['category_id'] ? [(int)$item['category_id']] : [];
        $additionalCats = array_column($curCatsStmt->fetchAll() ?: [], 'category_id');
        $categoryIds = array_values(array_unique(array_map('intval', array_merge($baseCats, $additionalCats))));
        
        // Load current equipment associations
        $cameraIds = [];
        $lensIds = [];
        $filmIds = [];
        $developerIds = [];
        $labIds = [];
        $locationIds = [];
        
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
            // Locations
            try {
                $locStmt = $pdo->prepare('SELECT location_id FROM album_location WHERE album_id = :a');
                $locStmt->execute([':a'=>$id]);
                $locationIds = array_map('intval', array_column($locStmt->fetchAll(), 'location_id'));
            } catch (\Throwable) { $locationIds = []; }
        } catch (\Throwable $e) {
            // Equipment tables might not exist yet
        }

        // Load custom field types and values
        $customFieldTypes = [];
        $customFieldValues = [];
        $albumCustomFields = [];
        if ($this->customFieldService) {
            try {
                $customFieldTypes = $this->customFieldService->getFieldTypes(includeSystem: false);
                foreach ($customFieldTypes as $type) {
                    $customFieldValues[$type['id']] = $this->customFieldService->getFieldValues((int)$type['id']);
                }
                // Load current album's custom field data
                $albumCustomFields = $this->customFieldService->getAlbumMetadata($id);
            } catch (\Throwable) {
                // Custom fields table may not exist yet
            }
        }

        $imgsStmt = $pdo->prepare("SELECT i.id, i.original_path, i.created_at, i.sort_order,
                                   i.alt_text, i.caption, i.width, i.height,
                                   i.camera_id, i.lens_id, i.film_id, i.developer_id, i.lab_id, i.location_id,
                                   i.custom_camera, i.custom_lens, i.custom_film,
                                   i.iso, i.shutter_speed, i.aperture,
                                   COALESCE(iv.path, i.original_path) AS preview_path
                                   FROM images i
                                   LEFT JOIN image_variants iv ON iv.image_id = i.id AND iv.variant = 'sm' AND iv.format = 'jpg'
                                   WHERE i.album_id=:a
                                   ORDER BY i.sort_order ASC, i.id ASC");
        $imgsStmt->execute([':a'=>$id]);
        $images = $imgsStmt->fetchAll();
        
        // Add base path to preview paths for subdirectory installations
        foreach ($images as &$image) {
            if (isset($image['preview_path']) && str_starts_with($image['preview_path'], '/')) {
                $image['preview_path'] = $this->basePath . $image['preview_path'];
            }
            if (isset($image['original_path']) && str_starts_with($image['original_path'], '/')) {
                $image['original_path'] = $this->basePath . $image['original_path'];
            }
        }
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
            'locations' => $locations,
            'tagIds' => $tagIds,
            'categoryIds' => $categoryIds,
            'cameraIds' => $cameraIds,
            'lensIds' => $lensIds,
            'filmIds' => $filmIds,
            'developerIds' => $developerIds,
            'labIds' => $labIds,
            'locationIds' => $locationIds,
            'images' => $images,
            'customFieldTypes' => $customFieldTypes,
            'customFieldValues' => $customFieldValues,
            'albumCustomFields' => $albumCustomFields,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function updateImageMeta(Request $request, Response $response, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $accept = $request->getHeaderLine('Accept');
            if (str_contains($accept, 'application/json')) {
                return $this->csrfErrorJson($response);
            }
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->redirect('/admin/albums'))->withStatus(302);
        }

        $albumId = (int)($args['id'] ?? 0);
        $imageId = (int)($args['imageId'] ?? 0);
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
            'custom_camera' => trim((string)($d['custom_camera'] ?? '')) ?: null,
            'custom_lens' => trim((string)($d['custom_lens'] ?? '')) ?: null,
            'custom_film' => trim((string)($d['custom_film'] ?? '')) ?: null,
            'iso' => ($d['iso'] ?? '') !== '' ? (int)$d['iso'] : null,
            'shutter_speed' => $d['shutter_speed'] ?? null,
            'aperture' => ($d['aperture'] ?? '') !== '' ? (float)$d['aperture'] : null,
        ];

        $setParts = [];
        $params = [];
        foreach ($fields as $col => $val) {
            $setParts[] = "$col = :$col";
            $params[":$col"] = $val;
        }
        $params[':id'] = $imageId;
        $params[':album'] = $albumId;
        $sql = 'UPDATE images SET ' . implode(', ', $setParts) . ' WHERE id = :id AND album_id = :album';
        $pdo->prepare($sql)->execute($params);

        $accept = $request->getHeaderLine('Accept');
        if (str_contains($accept, 'application/json')) {
            $response->getBody()->write(json_encode(['ok' => true]));
            return $response->withHeader('Content-Type','application/json');
        }
        $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.image_updated')];
        return $response->withHeader('Location',$this->basePath . '/admin/albums/'.$albumId.'/edit')->withStatus(302);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int)($args['id'] ?? 0);

        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->redirect('/admin/albums/'.$id.'/edit'))->withStatus(302);
        }

        // Get old album data to detect NSFW changes
        $pdo = $this->db->pdo();
        $oldAlbum = $pdo->prepare('SELECT is_nsfw FROM albums WHERE id = ?');
        $oldAlbum->execute([$id]);
        $oldNsfw = (int)($oldAlbum->fetchColumn() ?: 0);

        $d = (array)$request->getParsedBody();
        $title = trim((string)($d['title'] ?? ''));
        $slug = trim((string)($d['slug'] ?? ''));
        $categoryIds = array_map('intval', (array)($d['categories'] ?? []));
        $category_id = (int)($d['category_id'] ?? ($categoryIds[0] ?? 0));
        $excerpt = trim(strip_tags((string)($d['excerpt'] ?? ''))) ?: null;
        $bodyRaw = trim((string)($d['body'] ?? '')) ?: null;
        $body = $bodyRaw ? \App\Support\Sanitizer::html($bodyRaw) : null;
        $shoot_date = (string)($d['shoot_date'] ?? '') ?: null;
        $show_date = isset($d['show_date']) ? 1 : 0;
        $is_published = isset($d['is_published']) ? 1 : 0;
        $sort_order = (int)($d['sort_order'] ?? 0);
        $template_id = (int)($d['template_id'] ?? 0) ?: null;
        $allow_downloads = isset($d['allow_downloads']) ? 1 : 0;
        $is_nsfw = isset($d['is_nsfw']) ? 1 : 0;
        $passwordRaw = (string)($d['password'] ?? '');
        $clearPassword = !empty($d['password_clear']);
        $tagIds = array_map('intval', (array)($d['tags'] ?? []));
        $cameraIds = array_map('intval', (array)($d['cameras'] ?? []));
        $lensIds = array_map('intval', (array)($d['lenses'] ?? []));
        $filmIds = array_map('intval', (array)($d['films'] ?? []));
        $developerIds = array_map('intval', (array)($d['developers'] ?? []));
        $labIds = array_map('intval', (array)($d['labs'] ?? []));
        $locationIds = array_map('intval', (array)($d['locations'] ?? []));

        // SEO fields
        $seoTitle = trim((string)($d['seo_title'] ?? '')) ?: null;
        $seoDescription = trim((string)($d['seo_description'] ?? '')) ?: null;
        $seoKeywords = trim((string)($d['seo_keywords'] ?? '')) ?: null;
        $ogTitle = trim((string)($d['og_title'] ?? '')) ?: null;
        $ogDescription = trim((string)($d['og_description'] ?? '')) ?: null;
        $ogImagePath = trim((string)($d['og_image_path'] ?? '')) ?: null;
        $schemaType = trim((string)($d['schema_type'] ?? 'ImageGallery'));
        $schemaData = trim((string)($d['schema_data'] ?? '')) ?: null;
        $canonicalUrl = trim((string)($d['canonical_url'] ?? '')) ?: null;
        $robotsIndex = isset($d['robots_index']) ? 1 : 0;
        $robotsFollow = isset($d['robots_follow']) ? 1 : 0;
        
        // Custom equipment fields  
        $customCameras = trim((string)($d['custom_cameras'] ?? '')) ?: null;
        $customLenses = trim((string)($d['custom_lenses'] ?? '')) ?: null;
        $customFilms = trim((string)($d['custom_films'] ?? '')) ?: null;
        $customDevelopers = trim((string)($d['custom_developers'] ?? '')) ?: null;
        $customLabs = trim((string)($d['custom_labs'] ?? '')) ?: null;
        
        if ($title === '' || $category_id <= 0) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.title_category_required')];
            return $response->withHeader('Location', $this->redirect('/admin/albums/'.$id.'/edit'))->withStatus(302);
        }
        $slug = $slug !== '' ? \App\Support\Str::slug($slug) : \App\Support\Str::slug($title);
        $published_at = $is_published ? (date('Y-m-d H:i:s')) : null;
        $pdo = $this->db->pdo();

        // Ensure unique slug by appending numeric suffix if needed (exclude current album)
        $baseSlug = $slug;
        $counter = 2;
        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM albums WHERE slug = :s AND id != :id');
        $checkStmt->execute([':s' => $slug, ':id' => $id]);
        while ((int)$checkStmt->fetchColumn() > 0) {
            $slug = $baseSlug . '-' . $counter++;
            $checkStmt->execute([':s' => $slug, ':id' => $id]);
        }

        // Try with template_id, custom equipment fields, and SEO fields
        try {
            $stmt = $pdo->prepare('UPDATE albums SET title=:t, slug=:s, category_id=:c, excerpt=:e, body=:b, shoot_date=:sd, show_date=:sh, is_published=:p, published_at=:pa, sort_order=:o, template_id=:ti, custom_cameras=:cc, custom_lenses=:cl, custom_films=:cf, custom_developers=:cd, custom_labs=:clab, seo_title=:seo_title, seo_description=:seo_desc, seo_keywords=:seo_kw, og_title=:og_title, og_description=:og_desc, og_image_path=:og_img, schema_type=:schema_type, schema_data=:schema_data, canonical_url=:canonical_url, robots_index=:robots_index, robots_follow=:robots_follow WHERE id=:id');
            $stmt->execute([
                ':t'=>$title,':s'=>$slug,':c'=>$category_id,':e'=>$excerpt,':b'=>$body,':sd'=>$shoot_date,':sh'=>$show_date,':p'=>$is_published,':pa'=>$published_at,':o'=>$sort_order,':ti'=>$template_id,':cc'=>$customCameras,':cl'=>$customLenses,':cf'=>$customFilms,':cd'=>$customDevelopers,':clab'=>$customLabs, ':id'=>$id,
                ':seo_title'=>$seoTitle, ':seo_desc'=>$seoDescription, ':seo_kw'=>$seoKeywords,
                ':og_title'=>$ogTitle, ':og_desc'=>$ogDescription, ':og_img'=>$ogImagePath,
                ':schema_type'=>$schemaType, ':schema_data'=>$schemaData, ':canonical_url'=>$canonicalUrl,
                ':robots_index'=>$robotsIndex, ':robots_follow'=>$robotsFollow
            ]);
        } catch (\Throwable $e) {
            // Fallback for old DB schema without custom fields and SEO fields
            try {
                $stmt = $pdo->prepare('UPDATE albums SET title=:t, slug=:s, category_id=:c, excerpt=:e, body=:b, shoot_date=:sd, show_date=:sh, is_published=:p, published_at=:pa, sort_order=:o, template_id=:ti WHERE id=:id');
                $stmt->execute([':t'=>$title,':s'=>$slug,':c'=>$category_id,':e'=>$excerpt,':b'=>$body,':sd'=>$shoot_date,':sh'=>$show_date,':p'=>$is_published,':pa'=>$published_at,':o'=>$sort_order,':ti'=>$template_id, ':id'=>$id]);
            } catch (\Throwable $e2) {
                // Final fallback
                $stmt = $pdo->prepare('UPDATE albums SET title=:t, slug=:s, category_id=:c, excerpt=:e, body=:b, shoot_date=:sd, show_date=:sh, is_published=:p, published_at=:pa, sort_order=:o WHERE id=:id');
                $stmt->execute([':t'=>$title,':s'=>$slug,':c'=>$category_id,':e'=>$excerpt,':b'=>$body,':sd'=>$shoot_date,':sh'=>$show_date,':p'=>$is_published,':pa'=>$published_at,':o'=>$sort_order, ':id'=>$id]);
            }
            
            // Try to update SEO fields separately if main query failed
            try {
                $seoStmt = $pdo->prepare('UPDATE albums SET seo_title=:seo_title, seo_description=:seo_desc, seo_keywords=:seo_kw, og_title=:og_title, og_description=:og_desc, og_image_path=:og_img, schema_type=:schema_type, schema_data=:schema_data, canonical_url=:canonical_url, robots_index=:robots_index, robots_follow=:robots_follow WHERE id=:id');
                $seoStmt->execute([
                    ':seo_title'=>$seoTitle, ':seo_desc'=>$seoDescription, ':seo_kw'=>$seoKeywords,
                    ':og_title'=>$ogTitle, ':og_desc'=>$ogDescription, ':og_img'=>$ogImagePath,
                    ':schema_type'=>$schemaType, ':schema_data'=>$schemaData, ':canonical_url'=>$canonicalUrl,
                    ':robots_index'=>$robotsIndex, ':robots_follow'=>$robotsFollow, ':id'=>$id
                ]);
            } catch (\Throwable $e3) {
                // SEO fields don't exist yet, continue without error
            }
        }
        // Try to update downloads, NSFW and password when columns exist
        try {
            $set = 'allow_downloads = :dl, is_nsfw = :nsfw';
            $params = [':dl'=>$allow_downloads, ':nsfw'=>$is_nsfw, ':id'=>$id];
            if ($clearPassword) {
                $set .= ', password_hash = NULL';
            } elseif ($passwordRaw !== '') {
                $set .= ', password_hash = :ph';
                $params[':ph'] = password_hash($passwordRaw, PASSWORD_ARGON2ID);
            }
            $pdo->prepare('UPDATE albums SET '.$set.' WHERE id=:id')->execute($params);
        } catch (\Throwable $e) {
            // ignore if columns not present
        }
        try {
            // sync tags
            $pdo->prepare('DELETE FROM album_tag WHERE album_id=:a')->execute([':a'=>$id]);
            if ($tagIds) {
                $tagSql = $this->db->insertIgnoreKeyword() . ' INTO album_tag(album_id, tag_id) VALUES (:a, :t)';
                $tagStmt = $pdo->prepare($tagSql);
                foreach (array_unique($tagIds) as $tid) {
                    $tagStmt->execute([':a'=>$id, ':t'=>$tid]);
                }
            }
            // sync categories pivot
            $pdo->prepare('DELETE FROM album_category WHERE album_id=:a')->execute([':a'=>$id]);
            if ($category_id > 0 || !empty($categoryIds)) {
                $cats = array_unique(array_filter(array_map('intval', array_merge([$category_id], $categoryIds))));
                if ($cats) {
                    $sql = $this->db->insertIgnoreKeyword() . ' INTO album_category(album_id, category_id) VALUES(:a,:c)';
                    $catStmt = $pdo->prepare($sql);
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
                    $cameraSql = $this->db->insertIgnoreKeyword() . ' INTO album_camera(album_id, camera_id) VALUES (:a, :c)';
                    $cameraStmt = $pdo->prepare($cameraSql);
                    foreach (array_unique($cameraIds) as $cid) {
                        $cameraStmt->execute([':a'=>$id, ':c'=>$cid]);
                    }
                }
                
                if ($lensIds) {
                    $lensSql = $this->db->insertIgnoreKeyword() . ' INTO album_lens(album_id, lens_id) VALUES (:a, :l)';
                    $lensStmt = $pdo->prepare($lensSql);
                    foreach (array_unique($lensIds) as $lid) {
                        $lensStmt->execute([':a'=>$id, ':l'=>$lid]);
                    }
                }
                
                if ($filmIds) {
                    $filmSql = $this->db->insertIgnoreKeyword() . ' INTO album_film(album_id, film_id) VALUES (:a, :f)';
                    $filmStmt = $pdo->prepare($filmSql);
                    foreach (array_unique($filmIds) as $fid) {
                        $filmStmt->execute([':a'=>$id, ':f'=>$fid]);
                    }
                }
                
                if ($developerIds) {
                    $developerSql = $this->db->insertIgnoreKeyword() . ' INTO album_developer(album_id, developer_id) VALUES (:a, :d)';
                    $developerStmt = $pdo->prepare($developerSql);
                    foreach (array_unique($developerIds) as $did) {
                        $developerStmt->execute([':a'=>$id, ':d'=>$did]);
                    }
                }
                
                if ($labIds) {
                    $labSql = $this->db->insertIgnoreKeyword() . ' INTO album_lab(album_id, lab_id) VALUES (:a, :l)';
                    $labStmt = $pdo->prepare($labSql);
                    foreach (array_unique($labIds) as $lid) {
                        $labStmt->execute([':a'=>$id, ':l'=>$lid]);
                    }
                }
                // Sync locations (if table exists)
                try { $pdo->prepare('DELETE FROM album_location WHERE album_id=:a')->execute([':a'=>$id]); } catch (\Throwable) {}
                if ($locationIds) {
                    $locSql = $this->db->insertIgnoreKeyword() . ' INTO album_location(album_id, location_id) VALUES (:a, :l)';
                    $locStmt = $pdo->prepare($locSql);
                    foreach (array_unique($locationIds) as $lid) {
                        $locStmt->execute([':a'=>$id, ':l'=>$lid]);
                    }
                }
            } catch (\Throwable $e) {
                // Equipment tables might not exist yet, continue without error
            }

            // Sync custom field data
            // Note: We don't call clearAlbumMetadata() because setAlbumMetadata() already
            // handles removal of non-auto-added values per field type. This preserves
            // auto_added values that were propagated from images by plugins.
            if ($this->customFieldService) {
                try {
                    $customFields = (array)($d['custom_fields'] ?? []);
                    foreach ($customFields as $typeId => $values) {
                        // Filter empty values, keep both numeric IDs and string values
                        $values = array_filter((array)$values, fn($v) => $v !== '' && $v !== null);
                        // Always call setAlbumMetadata even with empty values to clear manual entries
                        // while preserving auto_added values from plugins
                        $this->customFieldService->setAlbumMetadata($id, (int)$typeId, array_values($values));
                    }
                } catch (\Throwable) {
                    // Custom fields table may not exist yet
                }
            }

            // Handle NSFW blur generation when flag changes
            if ($is_nsfw !== $oldNsfw) {
                try {
                    $uploadService = new \App\Services\UploadService($this->db);
                    if ($is_nsfw === 1) {
                        // Album became NSFW - generate blurred variants
                        $uploadService->generateBlurredVariantsForAlbum($id);
                    } else {
                        // Album is no longer NSFW - optionally delete blurred variants
                        $uploadService->deleteBlurredVariantsForAlbum($id);
                    }
                } catch (\Throwable $blurError) {
                    // Log but don't fail the update
                    \App\Support\Logger::warning('Failed to process NSFW blur variants', [
                        'album_id' => $id,
                        'error' => $blurError->getMessage()
                    ], 'admin');
                }
            }

            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.album_updated')];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Error: '.$e->getMessage()];
        }
        return $response->withHeader('Location', $this->redirect('/admin/albums'))->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->redirect('/admin/albums'))->withStatus(302);
        }

        $id = (int)($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('DELETE FROM albums WHERE id=:id');
        try {
            $stmt->execute([':id'=>$id]);
            $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.album_deleted')];
        } catch (\Throwable $e) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => 'Error: '.$e->getMessage()];
        }
        return $response->withHeader('Location', $this->redirect('/admin/albums'))->withStatus(302);
    }

    public function publish(Request $request, Response $response, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->redirect('/admin/albums'))->withStatus(302);
        }

        $id = (int)($args['id'] ?? 0);
        // Use portable CURRENT_TIMESTAMP instead of MySQL-specific NOW()
        $stmt = $this->db->pdo()->prepare('UPDATE albums SET is_published=1, published_at=CURRENT_TIMESTAMP WHERE id=:id');
        $stmt->execute([':id'=>$id]);
        $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.album_published')];
        return $response->withHeader('Location', $this->redirect('/admin/albums'))->withStatus(302);
    }

    public function unpublish(Request $request, Response $response, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->redirect('/admin/albums'))->withStatus(302);
        }

        $id = (int)($args['id'] ?? 0);
        $stmt = $this->db->pdo()->prepare('UPDATE albums SET is_published=0, published_at=NULL WHERE id=:id');
        $stmt->execute([':id'=>$id]);
        $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.album_unpublished')];
        return $response->withHeader('Location', $this->redirect('/admin/albums'))->withStatus(302);
    }

    public function setCover(Request $request, Response $response, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            $accept = $request->getHeaderLine('Accept');
            if (str_contains($accept, 'application/json')) {
                return $this->csrfErrorJson($response);
            }
            $_SESSION['flash'][] = ['type' => 'danger', 'message' => trans('admin.flash.csrf_invalid')];
            return $response->withHeader('Location', $this->redirect('/admin/albums'))->withStatus(302);
        }

        $albumId = (int)($args['id'] ?? 0);
        $imageId = (int)($args['imageId'] ?? 0);
        // ensure image belongs to album
        $check = $this->db->pdo()->prepare('SELECT 1 FROM images WHERE id=:img AND album_id=:a');
        $check->execute([':img'=>$imageId, ':a'=>$albumId]);
        if (!$check->fetchColumn()) {
            $_SESSION['flash'][] = ['type'=>'danger','message'=>trans('admin.flash.image_not_in_album')];
            return $response->withHeader('Location', $this->redirect('/admin/albums/'.$albumId.'/edit'))->withStatus(302);
        }
        $stmt = $this->db->pdo()->prepare('UPDATE albums SET cover_image_id=:img WHERE id=:id');
        $stmt->execute([':img'=>$imageId, ':id'=>$albumId]);
        $accept = $request->getHeaderLine('Accept');
        if (str_contains($accept, 'application/json')) {
            $response->getBody()->write(json_encode(['ok'=>true]));
            return $response->withHeader('Content-Type','application/json');
        }
        $_SESSION['flash'][] = ['type' => 'success', 'message' => trans('admin.flash.cover_updated')];
        return $response->withHeader('Location', $this->redirect('/admin/albums/'.$albumId.'/edit'))->withStatus(302);
    }

    public function reorderImages(Request $request, Response $response, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

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
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

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
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

        $albumId = (int)($args['id'] ?? 0);
        $data = json_decode((string)$request->getBody(), true) ?: [];
        $tagIds = array_map('intval', (array)($data['tags'] ?? []));
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM album_tag WHERE album_id=:a')->execute([':a'=>$albumId]);
            if ($tagIds) {
                $ins = $this->db->isMySQL()
                    ? 'INSERT IGNORE INTO album_tag(album_id, tag_id) VALUES (:a, :t)'
                    : 'INSERT OR IGNORE INTO album_tag(album_id, tag_id) VALUES (:a, :t)';
                $stmt = $pdo->prepare($ins);
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
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

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
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

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

    public function attachExisting(Request $request, Response $response, array $args): Response
    {
        // CSRF validation
        if (!$this->validateCsrf($request)) {
            return $this->csrfErrorJson($response);
        }

        $albumId = (int)($args['id'] ?? 0);
        $d = (array)$request->getParsedBody();
        $sourceId = (int)($d['image_id'] ?? 0);
        if ($albumId <= 0 || $sourceId <= 0) {
            return $response->withStatus(400);
        }
        $pdo = $this->db->pdo();

        // Get source image
        $rowStmt = $pdo->prepare('SELECT * FROM images WHERE id = :id');
        $rowStmt->execute([':id'=>$sourceId]);
        $src = $rowStmt->fetch();
        if (!$src) return $response->withStatus(404);

        // Check for duplicates: same file_hash already in this album
        $dupStmt = $pdo->prepare('SELECT id FROM images WHERE album_id = :album AND file_hash = :hash LIMIT 1');
        $dupStmt->execute([':album' => $albumId, ':hash' => $src['file_hash']]);
        if ($dupStmt->fetch()) {
            $response->getBody()->write(json_encode(['ok' => false, 'error' => trans('admin.flash.image_already_in_album')]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
        }

        // Duplicate row for target album
        $ins = $pdo->prepare('INSERT INTO images(album_id, original_path, file_hash, width, height, mime, alt_text, caption, exif, camera_id, lens_id, film_id, developer_id, lab_id, custom_camera, custom_lens, custom_film, custom_development, custom_lab, custom_scanner, scan_resolution_dpi, scan_bit_depth, process, development_date, iso, shutter_speed, aperture, sort_order)
                              VALUES(:album,:p,:h,:w,:hh,:m,:alt,:cap,:ex,:cam,:lens,:film,:dev,:lab,:ccam,:clens,:cfilm,:cdev,:clab,:cscan,:dpi,:bit,:proc,:ddate,:iso,:sh,:ap,:sort)');
        $ins->execute([
            ':album'=>$albumId,
            ':p'=>$src['original_path'],
            ':h'=>$src['file_hash'],
            ':w'=>$src['width'],
            ':hh'=>$src['height'],
            ':m'=>$src['mime'],
            ':alt'=>$src['alt_text'],
            ':cap'=>$src['caption'],
            ':ex'=>$src['exif'],
            ':cam'=>$src['camera_id'],
            ':lens'=>$src['lens_id'],
            ':film'=>$src['film_id'],
            ':dev'=>$src['developer_id'],
            ':lab'=>$src['lab_id'],
            ':ccam'=>$src['custom_camera'],
            ':clens'=>$src['custom_lens'],
            ':cfilm'=>$src['custom_film'],
            ':cdev'=>$src['custom_development'],
            ':clab'=>$src['custom_lab'],
            ':cscan'=>$src['custom_scanner'],
            ':dpi'=>$src['scan_resolution_dpi'],
            ':bit'=>$src['scan_bit_depth'],
            ':proc'=>$src['process'],
            ':ddate'=>$src['development_date'],
            ':iso'=>$src['iso'],
            ':sh'=>$src['shutter_speed'],
            ':ap'=>$src['aperture'],
            ':sort'=>0,
        ]);
        $newId = (int)$pdo->lastInsertId();

        // Copy image variants from source to new image
        $variantsStmt = $pdo->prepare('SELECT * FROM image_variants WHERE image_id = :source_id');
        $variantsStmt->execute([':source_id' => $sourceId]);
        $variants = $variantsStmt->fetchAll();

        if ($variants && count($variants) > 0) {
            $insertVariant = $pdo->prepare('INSERT INTO image_variants (image_id, variant, format, path, width, height, size, created_at) VALUES (:image_id, :variant, :format, :path, :width, :height, :size, :created_at)');
            foreach ($variants as $v) {
                $insertVariant->execute([
                    ':image_id' => $newId,
                    ':variant' => $v['variant'],
                    ':format' => $v['format'],
                    ':path' => $v['path'],
                    ':width' => $v['width'],
                    ':height' => $v['height'],
                    ':size' => $v['size'],
                    ':created_at' => $v['created_at'] ?? date('Y-m-d H:i:s'),
                ]);
            }
        }

        $response->getBody()->write(json_encode(['ok'=>true,'id'=>$newId]));
        return $response->withHeader('Content-Type','application/json');
    }
}
