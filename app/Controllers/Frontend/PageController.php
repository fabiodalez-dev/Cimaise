<?php
declare(strict_types=1);

namespace App\Controllers\Frontend;
use App\Controllers\BaseController;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class PageController extends BaseController
{
    public function __construct(private Database $db, private Twig $view)
    {
        parent::__construct();
    }

    private function normalizeTemplateSettings(array $templateSettings): array
    {
        // Normalize possibly nested columns structure to flat integers
        if (isset($templateSettings['columns']) && is_array($templateSettings['columns'])) {
            $normalizedColumns = [];
            foreach (['desktop', 'tablet', 'mobile'] as $device) {
                if (isset($templateSettings['columns'][$device])) {
                    $value = $templateSettings['columns'][$device];
                    while (is_array($value) && isset($value[$device])) {
                        $value = $value[$device];
                    }
                    if (is_numeric($value) && $value > 0 && $value <= 12) {
                        $normalizedColumns[$device] = (int)$value;
                    } else {
                        $normalizedColumns[$device] = match($device) {
                            'desktop' => 3,
                            'tablet' => 2,
                            'mobile' => 1,
                            default => 3,
                        };
                    }
                }
            }
            $templateSettings['columns'] = $normalizedColumns;
        }
        return $templateSettings;
    }

    public function home(Request $request, Response $response): Response
    {
        $pdo = $this->db->pdo();
        
        // Pagination parameters
        $perPage = 12;
        
        // Get total count of published albums
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM albums a WHERE a.is_published = 1');
        $countStmt->execute();
        $totalAlbums = (int)$countStmt->fetchColumn();
        
        // Get latest published albums
        $stmt = $pdo->prepare('
            SELECT a.*, c.name as category_name, c.slug as category_slug
            FROM albums a 
            JOIN categories c ON c.id = a.category_id 
            WHERE a.is_published = 1 
            ORDER BY a.published_at DESC 
            LIMIT :limit
        ');
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->execute();
        $albums = $stmt->fetchAll();
        
        // Enrich with cover images and tags
        foreach ($albums as &$album) {
            $album = $this->enrichAlbum($album);
        }
        
        // Calculate pagination info
        $hasMore = $totalAlbums > $perPage;
        
        // Get categories for navigation with hierarchy
        $parentCategories = $this->getParentCategoriesForNavigation();
        
        // Keep flat list for backward compatibility
        $categories = [];
        foreach ($parentCategories as $parent) {
            if ($parent['albums_count'] > 0) {
                $categories[] = $parent;
            }
            foreach ($parent['children'] as $child) {
                if ($child['albums_count'] > 0) {
                    $categories[] = $child;
                }
            }
        }
        
        // Get popular tags
        $stmt = $pdo->prepare('
            SELECT t.*, COUNT(at.album_id) as albums_count
            FROM tags t 
            JOIN album_tag at ON at.tag_id = t.id
            JOIN albums a ON a.id = at.album_id AND a.is_published = 1
            GROUP BY t.id 
            ORDER BY albums_count DESC, t.name ASC 
            LIMIT 20
        ');
        $stmt->execute();
        $tags = $stmt->fetchAll();
        
        return $this->view->render($response, 'frontend/home.twig', [
            'albums' => $albums,
            'categories' => $categories,
            'parent_categories' => $parentCategories,
            'tags' => $tags,
            'has_more' => $hasMore,
            'total_albums' => $totalAlbums,
            'page_title' => 'Portfolio',
            'meta_description' => 'Photography portfolio showcasing analog and digital work'
        ]);
    }

    public function album(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        $params = $request->getQueryParams();
        $templateId = isset($params['template']) ? (int)$params['template'] : null;
        $pdo = $this->db->pdo();
        
        $stmt = $pdo->prepare('
            SELECT a.*, c.name as category_name, c.slug as category_slug,
                   t.name as template_name, t.slug as template_slug, t.settings as template_settings, t.libs as template_libs
            FROM albums a 
            JOIN categories c ON c.id = a.category_id 
            LEFT JOIN templates t ON t.id = a.template_id
            WHERE a.slug = :slug AND a.is_published = 1
        ');
        $stmt->execute([':slug' => $slug]);
        $album = $stmt->fetch();
        
        if (!$album) {
            return $this->view->render($response->withStatus(404), 'frontend/404.twig', [
                'page_title' => '404 — Album non trovato',
                'meta_description' => 'Album non trovato o non pubblicato'
            ]);
        }

        // Password protection with session timeout
        if (!empty($album['password_hash'])) {
            $allowed = false;
            if (isset($_SESSION['album_access']) && isset($_SESSION['album_access'][$album['id']])) {
                $accessTime = $_SESSION['album_access'][$album['id']];
                // Check if access is still valid (24 hour timeout)
                if (is_int($accessTime) && (time() - $accessTime) < 86400) {
                    $allowed = true;
                } else {
                    // Remove expired access
                    unset($_SESSION['album_access'][$album['id']]);
                }
            }
            
            if (!$allowed) {
                // Categories for header menu
                $navStmt = $pdo->prepare('SELECT id, name, slug FROM categories ORDER BY sort_order ASC, name ASC');
                $navStmt->execute();
                $navCategories = $navStmt->fetchAll();
                $query = $request->getQueryParams();
                $error = isset($query['error']);
                return $this->view->render($response, 'frontend/album_password.twig', [
                    'album' => $album,
                    'categories' => $navCategories,
                    'page_title' => $album['title'] . ' — Protetto',
                    'error' => $error,
                    'csrf' => $_SESSION['csrf'] ?? ''
                ]);
            }
        }

        $albumRef = $album['slug'] ?? (string)$album['id'];

        // Use selected template or album template as default
        if ($templateId === null && !empty($album['template_id'])) {
            $templateId = (int)$album['template_id'];
        }
        if (!$templateId) {
            // Use default template from settings
            $settingsService = new \App\Services\SettingsService($this->db);
            $defaultTemplateId = $settingsService->get('gallery.default_template_id');
            
            if ($defaultTemplateId) {
                // Use the predefined template from settings
                $templateId = (int)$defaultTemplateId;
                $tplStmt = $pdo->prepare('SELECT * FROM templates WHERE id = :id');
                $tplStmt->execute([':id' => $templateId]);
                $template = $tplStmt->fetch();
                
                if ($template) {
                    $templateSettings = json_decode($template['settings'] ?? '{}', true) ?: [];
                    $templateSettings = $this->normalizeTemplateSettings($templateSettings);
                } else {
                    // No template found, use basic grid fallback
                    $template = ['name' => 'Grid Semplice', 'settings' => json_encode(['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]])];
                    $templateSettings = ['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]];
                }
            } else {
                // No default template set, use basic grid fallback
                $template = ['name' => 'Grid Semplice', 'settings' => json_encode(['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]])];
                $templateSettings = ['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]];
            }
        } else {
            $tplStmt = $pdo->prepare('SELECT * FROM templates WHERE id = :id');
            $tplStmt->execute([':id' => $templateId]);
            $template = $tplStmt->fetch();
            if (!$template) {
                // Fallback to basic grid
                $template = ['name' => 'Grid Semplice', 'settings' => json_encode(['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]])];
                $templateSettings = ['layout' => 'grid', 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1]];
            } else {
                $templateSettings = json_decode($template['settings'] ?? '{}', true) ?: [];
                $templateSettings = $this->normalizeTemplateSettings($templateSettings);
            }
        }

        // Tags
        $tagsStmt = $pdo->prepare('SELECT t.* FROM tags t JOIN album_tag at ON at.tag_id = t.id WHERE at.album_id = :id ORDER BY t.name ASC');
        $tagsStmt->execute([':id' => $album['id']]);
        $tags = $tagsStmt->fetchAll();

        // Categories (multiple)
        $cats = [];
        try {
            $cstmt = $pdo->prepare('SELECT c.id, c.name, c.slug FROM categories c JOIN album_category ac ON ac.category_id = c.id WHERE ac.album_id = :id ORDER BY c.sort_order, c.name');
            $cstmt->execute([':id' => $album['id']]);
            $cats = $cstmt->fetchAll() ?: [];
        } catch (\Throwable) {}

        // Equipment (album-level pivot lists)
        $equipment = [ 'cameras'=>[], 'lenses'=>[], 'film'=>[], 'developers'=>[], 'labs'=>[], 'locations'=>[] ];
        
        // Load equipment: first try custom fields, then relationships, then images as fallback
        try {
            // Try custom equipment fields first (if present, they take priority)
            if (!empty($album['custom_cameras'])) {
                $equipment['cameras'] = array_filter(array_map('trim', explode("\n", $album['custom_cameras'])));
            } else {
                $cameraStmt = $pdo->prepare('SELECT c.make, c.model FROM cameras c JOIN album_camera ac ON c.id = ac.camera_id WHERE ac.album_id = :a');
                $cameraStmt->execute([':a' => $album['id']]);
                $cameras = $cameraStmt->fetchAll();
                $equipment['cameras'] = array_map(fn($c) => trim(($c['make'] ?? '') . ' ' . ($c['model'] ?? '')), $cameras);
            }
            
            if (!empty($album['custom_lenses'])) {
                $equipment['lenses'] = array_filter(array_map('trim', explode("\n", $album['custom_lenses'])));
            } else {
                $lensStmt = $pdo->prepare('SELECT l.brand, l.model FROM lenses l JOIN album_lens al ON l.id = al.lens_id WHERE al.album_id = :a');
                $lensStmt->execute([':a' => $album['id']]);
                $lenses = $lensStmt->fetchAll();
                $equipment['lenses'] = array_map(fn($l) => trim(($l['brand'] ?? '') . ' ' . ($l['model'] ?? '')), $lenses);
            }
            
            if (!empty($album['custom_films'])) {
                $equipment['film'] = array_filter(array_map('trim', explode("\n", $album['custom_films'])));
            } else {
                $filmStmt = $pdo->prepare('SELECT f.brand, f.name FROM films f JOIN album_film af ON f.id = af.film_id WHERE af.album_id = :a');
                $filmStmt->execute([':a' => $album['id']]);
                $films = $filmStmt->fetchAll();
                $equipment['film'] = array_map(fn($f) => trim(($f['brand'] ?? '') . ' ' . ($f['name'] ?? '')), $films);
            }
            
            if (!empty($album['custom_developers'])) {
                $equipment['developers'] = array_filter(array_map('trim', explode("\n", $album['custom_developers'])));
            } else {
                $devStmt = $pdo->prepare('SELECT d.name FROM developers d JOIN album_developer ad ON d.id = ad.developer_id WHERE ad.album_id = :a');
                $devStmt->execute([':a' => $album['id']]);
                $developers = $devStmt->fetchAll();
                $equipment['developers'] = array_map(fn($d) => $d['name'], $developers);
            }
            
            if (!empty($album['custom_labs'])) {
                $equipment['labs'] = array_filter(array_map('trim', explode("\n", $album['custom_labs'])));
            } else {
                $labStmt = $pdo->prepare('SELECT l.name FROM labs l JOIN album_lab al ON l.id = al.lab_id WHERE al.album_id = :a');
                $labStmt->execute([':a' => $album['id']]);
                $labs = $labStmt->fetchAll();
                $equipment['labs'] = array_map(fn($l) => $l['name'], $labs);
            }
            
            // Locations
            $locStmt = $pdo->prepare('SELECT l.name FROM locations l JOIN album_location al ON l.id = al.location_id WHERE al.album_id = :a ORDER BY l.name');
            $locStmt->execute([':a' => $album['id']]);
            $locations = $locStmt->fetchAll();
            $equipment['locations'] = array_map(fn($l) => $l['name'], $locations);
        } catch (\Throwable) {
            // Equipment tables might not exist or have issues, continue with empty equipment
        }
        
        // Get album images
        $stmt = $pdo->prepare('
            SELECT i.*
            FROM images i
            WHERE i.album_id = :id
            ORDER BY i.sort_order ASC, i.id ASC
        ');
        $stmt->execute([':id' => $album['id']]);
        $images = $stmt->fetchAll();
        
        // Get variants for each image
        foreach ($images as &$image) {
            $variantsStmt = $pdo->prepare('SELECT * FROM image_variants WHERE image_id = :id ORDER BY variant ASC');
            $variantsStmt->execute([':id' => $image['id']]);
            $image['variants'] = $variantsStmt->fetchAll();
            
            // Format EXIF for display
            if ($image['exif']) {
                $exif = json_decode($image['exif'], true) ?: [];
                $image['exif_display'] = $this->formatExifForDisplay($exif, $image);
            }

            // Lookup names for camera/lens/developer/lab/film/location if present
            try {
                if (!empty($image['camera_id'])) {
                    $s = $pdo->prepare('SELECT make, model FROM cameras WHERE id = :id');
                    $s->execute([':id' => $image['camera_id']]);
                    $cr = $s->fetch();
                    if ($cr) { $image['camera_name'] = trim(($cr['make'] ?? '') . ' ' . ($cr['model'] ?? '')); }
                }
                if (!empty($image['lens_id'])) {
                    $s = $pdo->prepare('SELECT brand, model FROM lenses WHERE id = :id');
                    $s->execute([':id' => $image['lens_id']]);
                    $lr = $s->fetch();
                    if ($lr) { $image['lens_name'] = trim(($lr['brand'] ?? '') . ' ' . ($lr['model'] ?? '')); }
                }
                if (!empty($image['developer_id'])) {
                    $s = $pdo->prepare('SELECT name FROM developers WHERE id = :id');
                    $s->execute([':id' => $image['developer_id']]);
                    $image['developer_name'] = $s->fetchColumn() ?: null;
                }
                if (!empty($image['lab_id'])) {
                    $s = $pdo->prepare('SELECT name FROM labs WHERE id = :id');
                    $s->execute([':id' => $image['lab_id']]);
                    $image['lab_name'] = $s->fetchColumn() ?: null;
                }
                if (!empty($image['film_id'])) {
                    $s = $pdo->prepare('SELECT brand, name FROM films WHERE id = :id');
                    $s->execute([':id' => $image['film_id']]);
                    $fr = $s->fetch();
                    if ($fr) { $image['film_name'] = trim(($fr['brand'] ?? '') . ' ' . ($fr['name'] ?? '')); }
                }
                if (!empty($image['location_id'])) {
                    $s = $pdo->prepare('SELECT name FROM locations WHERE id = :id');
                    $s->execute([':id' => $image['location_id']]);
                    $image['location_name'] = $s->fetchColumn() ?: null;
                }
            } catch (\Throwable) { /* ignore lookup errors */ }
        }

        // Compute unique filter options for Twig (avoid unsupported Twig filters like |unique)
        $processes = [];
        $cameras = [];
        foreach ($images as $img) {
            if (!empty($img['process']) && !in_array($img['process'], $processes, true)) {
                $processes[] = $img['process'];
            }
            $cam = $img['custom_camera'] ?? ($img['exif_display']['camera'] ?? null);
            if ($cam && !in_array($cam, $cameras, true)) {
                $cameras[] = $cam;
            }
        }
        
        // Get related albums (same category, excluding current)
        $stmt = $pdo->prepare('
            SELECT a.*, c.name as category_name, c.slug as category_slug
            FROM albums a 
            JOIN categories c ON c.id = a.category_id 
            WHERE a.category_id = :category_id AND a.id != :album_id AND a.is_published = 1
            ORDER BY a.published_at DESC 
            LIMIT 4
        ');
        $stmt->execute([':category_id' => $album['category_id'], ':album_id' => $album['id']]);
        $relatedAlbums = $stmt->fetchAll();
        
        foreach ($relatedAlbums as &$related) {
            $related = $this->enrichAlbum($related);
        }
        
        // Process template settings
        $templateSettings = null;
        $templateLibs = [];
        if ($album['template_settings']) {
            $templateSettings = json_decode($album['template_settings'], true) ?: null;
        }
        if ($album['template_libs']) {
            $templateLibs = json_decode($album['template_libs'], true) ?: [];
        }
        
        // Categories for header menu
        $navStmt = $pdo->prepare('SELECT id, name, slug FROM categories ORDER BY sort_order ASC, name ASC');
        $navStmt->execute();
        $navCategories = $navStmt->fetchAll();

        // Enrich images with metadata and build PhotoSwipe-compatible data
        foreach ($images as &$image) {
            // Choose best public variant for both grid and lightbox (largest available)
            $bestUrl = $image['original_path'];
            $lightboxUrl = $image['original_path'];
            try {
                // Grid: prefer largest public variant (format priority avif > webp > jpg)
                $vg = $pdo->prepare("SELECT path, width, height FROM image_variants 
                    WHERE image_id = :id AND path NOT LIKE '/storage/%' 
                    ORDER BY CASE format WHEN 'avif' THEN 1 WHEN 'webp' THEN 2 ELSE 3 END, width DESC LIMIT 1");
                $vg->execute([':id' => $image['id']]);
                if ($vgr = $vg->fetch()) { if (!empty($vgr['path'])) { $bestUrl = $vgr['path']; } }

                // Lightbox: same rule, ensure highest quality
                $vl = $pdo->prepare("SELECT path FROM image_variants 
                    WHERE image_id = :id AND path NOT LIKE '/storage/%' 
                    ORDER BY CASE format WHEN 'avif' THEN 1 WHEN 'webp' THEN 2 ELSE 3 END, width DESC LIMIT 1");
                $vl->execute([':id' => $image['id']]);
                if ($vlr = $vl->fetch()) { if (!empty($vlr['path'])) { $lightboxUrl = $vlr['path']; } }
            } catch (\Throwable $e) {
                error_log('Error fetching image variants: ' . $e->getMessage());
            }
            // Final fallback: if still pointing to /storage, use grid URL
            if (str_starts_with((string)$lightboxUrl, '/storage/')) { $lightboxUrl = $bestUrl; }

            // Build enhanced caption with equipment and location info
            $equipBits = [];
            $cameraDisp = $image['camera_name'] ?? ($image['custom_camera'] ?? null);
            $lensDisp = $image['lens_name'] ?? ($image['custom_lens'] ?? null);
            $filmDisp = $image['film_name'] ?? ($image['custom_film'] ?? null);
            
            if (!empty($cameraDisp)) { $equipBits[] = '<i class="fa-solid fa-camera mr-1"></i>' . htmlspecialchars((string)$cameraDisp, ENT_QUOTES); }
            if (!empty($lensDisp)) { $equipBits[] = '<i class="fa-solid fa-dot-circle mr-1"></i>' . htmlspecialchars((string)$lensDisp, ENT_QUOTES); }
            if (!empty($filmDisp)) { $equipBits[] = '<i class="fa-solid fa-film mr-1"></i>' . htmlspecialchars((string)$filmDisp, ENT_QUOTES); }
            
            // Add location information to PhotoSwipe caption
            if (!empty($image['location_name'])) { 
                $equipBits[] = '<i class="fa-solid fa-map-marker-alt mr-1"></i>' . htmlspecialchars((string)$image['location_name'], ENT_QUOTES); 
            }
            
            if (!empty($image['developer_name'])) { $equipBits[] = '<i class="fa-solid fa-flask mr-1"></i>' . htmlspecialchars((string)$image['developer_name'], ENT_QUOTES); }
            if (!empty($image['lab_name'])) { $equipBits[] = '<i class="fa-solid fa-industry mr-1"></i>' . htmlspecialchars((string)$image['lab_name'], ENT_QUOTES); }
            if (!empty($image['iso'])) { $equipBits[] = '<i class="fa-solid fa-signal mr-1"></i>ISO ' . (int)$image['iso']; }
            if (!empty($image['shutter_speed'])) { $equipBits[] = '<i class="fa-regular fa-clock mr-1"></i>' . htmlspecialchars((string)$image['shutter_speed'], ENT_QUOTES); }
            if (!empty($image['aperture'])) { $equipBits[] = '<i class="fa-solid fa-circle-half-stroke mr-1"></i>f/' . number_format((float)$image['aperture'], 1); }
            
            $captionHtml = '';
            if (!empty($image['caption'])) {
                $captionHtml .= '<div class="mb-2">' . htmlspecialchars((string)$image['caption'], ENT_QUOTES) . '</div>';
            }
            if ($equipBits) {
                $captionHtml .= '<div class="flex flex-wrap gap-x-3 gap-y-1 justify-center text-sm">' . implode(' ', array_map(fn($x)=>'<span class="inline-flex items-center">'.$x.'</span>', $equipBits)) . '</div>';
            }

            // Build responsive sources for <picture>
            $sources = ['avif'=>[], 'webp'=>[], 'jpg'=>[]];
            foreach (($image['variants'] ?? []) as $v) {
                if (!isset($sources[$v['format']])) continue;
                if (!empty($v['path']) && !str_starts_with((string)$v['path'], '/storage/')) {
                    $sources[$v['format']][] = $v['path'] . ' ' . (int)$v['width'] . 'w';
                }
            }

            // Update image data for PhotoSwipe compatibility
            $image['url'] = $bestUrl;
            $image['lightbox_url'] = $lightboxUrl;
            $image['caption_html'] = $captionHtml;
            $image['alt'] = $image['alt_text'] ?: $album['title'];
            $image['sources'] = $sources;
            $image['fallback_src'] = $lightboxUrl ?: $bestUrl;
        }

        // Gallery meta mapped from album for consistency with gallery view
        $galleryMeta = [
            'title' => $album['title'],
            'category' => ['name' => $album['category_name'], 'slug' => $album['category_slug']],
            'categories' => $cats,
            'excerpt' => $album['excerpt'] ?? '',
            'body' => $album['body'] ?? '',
            'shoot_date' => $album['shoot_date'] ?? '',
            'show_date' => (int)($album['show_date'] ?? 1),
            'tags' => $tags,
            'equipment' => $equipment,
            'cover' => $album['cover'] ?? null,
        ];

        // Ensure cover is available (for hero layouts and meta image)
        try {
            if (!empty($album['cover_image_id'])) {
                $stmtC = $pdo->prepare('SELECT * FROM images WHERE id = :id');
                $stmtC->execute([':id' => $album['cover_image_id']]);
                $cover = $stmtC->fetch();
                if ($cover) {
                    $variantsStmt = $pdo->prepare('SELECT * FROM image_variants WHERE image_id = :id ORDER BY variant ASC');
                    $variantsStmt->execute([':id' => $cover['id']]);
                    $cover['variants'] = $variantsStmt->fetchAll();
                    $album['cover'] = $cover;
                }
            }
        } catch (\Throwable) {}

        // Available templates for switcher
        $availableTemplates = [];
        try {
            $list = $pdo->query('SELECT id, name, slug, settings FROM templates ORDER BY name ASC')->fetchAll() ?: [];
            foreach ($list as &$tpl) { $tpl['settings'] = json_decode($tpl['settings'] ?? '{}', true) ?: []; }
            $availableTemplates = $list;
        } catch (\Throwable) { $availableTemplates = []; }

        // Choose page template (classic, hero, magazine)
        $settingsServiceForPage = new \App\Services\SettingsService($this->db);
        $pageTemplate = (string)($settingsServiceForPage->get('gallery.page_template', 'classic') ?? 'classic');
        $pageTemplate = in_array($pageTemplate, ['classic','hero','magazine'], true) ? $pageTemplate : 'classic';
        $twigTemplate = match ($pageTemplate) {
            'hero' => 'frontend/gallery_hero.twig',
            'magazine' => 'frontend/gallery_magazine.twig',
            default => 'frontend/gallery.twig',
        };

        // Use selected page template
        return $this->view->render($response, $twigTemplate, [
            'album' => $galleryMeta,
            'images' => $images,
            'template_name' => $template['name'],
            'template_settings' => $templateSettings,
            'available_templates' => $availableTemplates,
            'current_template_id' => $templateId,
            'album_ref' => $albumRef,
            'categories' => $navCategories,
            'parent_categories' => $this->getParentCategoriesForNavigation(),
            'page_title' => $galleryMeta['title'] . ' - ' . $template['name'],
            'meta_description' => $galleryMeta['excerpt'] ?: 'Photography album: ' . $galleryMeta['title'],
            'meta_image' => $album['cover']['variants'][0]['path'] ?? null,
            'current_url' => $request->getUri()->__toString()
        ]);
    }

    public function unlockAlbum(Request $request, Response $response, array $args): Response
    {
        $slug = (string)($args['slug'] ?? '');
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id, password_hash FROM albums WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
        $album = $stmt->fetch();
        if (!$album || empty($album['password_hash'])) {
            return $response->withHeader('Location', $this->redirect('/album/' . $slug))->withStatus(302);
        }
        $data = (array)($request->getParsedBody() ?? []);
        $password = (string)($data['password'] ?? '');
        if ($password !== '' && password_verify($password, (string)$album['password_hash'])) {
            // SECURITY: Regenerate session ID to prevent session fixation
            session_regenerate_id(true);
            
            if (!isset($_SESSION['album_access'])) $_SESSION['album_access'] = [];
            $_SESSION['album_access'][(int)$album['id']] = time(); // Store timestamp for potential timeout
            
            return $response->withHeader('Location', $this->redirect('/album/' . $slug))->withStatus(302);
        }
        return $response->withHeader('Location', $this->redirect('/album/' . $slug . '?error=1'))->withStatus(302);
    }

    public function albumTemplate(Request $request, Response $response, array $args): Response
    {
        try {
            // Ensure session is started for album access checks
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            
            $slug = $args['slug'] ?? '';
            $params = $request->getQueryParams();
            $templateId = isset($params['template']) ? (int)$params['template'] : null;

            if (!$templateId || !$slug) {
                $response->getBody()->write('Template or album parameter missing');
                return $response->withStatus(400);
            }

            $pdo = $this->db->pdo();

            // Resolve album (published only)
            $stmt = $pdo->prepare('SELECT a.*, a.template_id, c.name as category_name, c.slug as category_slug, t.settings as template_settings, t.name as template_name FROM albums a JOIN categories c ON c.id = a.category_id LEFT JOIN templates t ON t.id = a.template_id WHERE a.slug = :slug AND a.is_published = 1');
            $stmt->execute([':slug' => $slug]);
            
            $album = $stmt->fetch();
            if (!$album) {
                $response->getBody()->write('Album not found');
                return $response->withStatus(404);
            }
            if (!empty($album['password_hash'])) {
                $allowed = isset($_SESSION['album_access']) && !empty($_SESSION['album_access'][$album['id']]);
                if (!$allowed) {
                    $response->getBody()->write('Album locked');
                    return $response->withStatus(403);
                }
            }

            // Load template
            $tplStmt = $pdo->prepare('SELECT * FROM templates WHERE id = :id');
            $tplStmt->execute([':id' => $templateId]);
            $template = $tplStmt->fetch();
            if (!$template) {
                $response->getBody()->write('Template not found');
                return $response->withStatus(404);
            }
            
            $templateSettings = json_decode($template['settings'] ?? '{}', true) ?: [];

            // Images with per-photo metadata (similar to GalleryController->template)
            $imgStmt = $pdo->prepare('SELECT * FROM images WHERE album_id = :id ORDER BY sort_order ASC, id ASC');
            $imgStmt->execute([':id' => $album['id']]);
            $imagesRows = $imgStmt->fetchAll() ?: [];
            
            foreach ($imagesRows as &$ir) {
                try {
                    if (!empty($ir['developer_id'])) {
                        $s = $pdo->prepare('SELECT name FROM developers WHERE id = :id');
                        $s->execute([':id' => $ir['developer_id']]);
                        $ir['developer_name'] = $s->fetchColumn() ?: null;
                    }
                    if (!empty($ir['lab_id'])) {
                        $s = $pdo->prepare('SELECT name FROM labs WHERE id = :id');
                        $s->execute([':id' => $ir['lab_id']]);
                        $ir['lab_name'] = $s->fetchColumn() ?: null;
                    }
                    if (!empty($ir['film_id'])) {
                        $s = $pdo->prepare('SELECT brand, name FROM films WHERE id = :id');
                        $s->execute([':id' => $ir['film_id']]);
                        $fr = $s->fetch();
                        if ($fr) { $ir['film_name'] = trim(($fr['brand'] ?? '') . ' ' . ($fr['name'] ?? '')); }
                    }
                    if (!empty($ir['location_id'])) {
                        $s = $pdo->prepare('SELECT name FROM locations WHERE id = :id');
                        $s->execute([':id' => $ir['location_id']]);
                        $ir['location_name'] = $s->fetchColumn() ?: null;
                    }
                } catch (\Throwable $e) {
                    // Continue processing even if metadata lookup fails
                    error_log('Error fetching image metadata: ' . $e->getMessage());
                }
            }

            // Build gallery items for the template
            $images = [];
            foreach ($imagesRows as $img) {
                $bestUrl = $img['original_path'];
                $lightboxUrl = $img['original_path'];
                
                try {
                    $v = $pdo->prepare("SELECT path, width, height FROM image_variants WHERE image_id = :id AND format='jpg' ORDER BY CASE variant WHEN 'lg' THEN 1 WHEN 'md' THEN 2 WHEN 'sm' THEN 3 ELSE 9 END LIMIT 1");
                    $v->execute([':id' => $img['id']]);
                    $vr = $v->fetch();
                    if ($vr && !empty($vr['path'])) { $bestUrl = $vr['path']; }
                    
                    $lv = $pdo->prepare("SELECT path FROM image_variants WHERE image_id = :id AND format IN ('jpg','webp','avif') ORDER BY CASE variant WHEN 'xxl' THEN 1 WHEN 'xl' THEN 2 WHEN 'lg' THEN 3 WHEN 'md' THEN 4 ELSE 9 END, width DESC LIMIT 1");
                    $lv->execute([':id' => $img['id']]);
                    $lvr = $lv->fetch();
                    if ($lvr && !empty($lvr['path']) && !str_starts_with((string)$lvr['path'], '/storage/')) {
                        $lightboxUrl = $lvr['path'];
                    }
                } catch (\Throwable $e) {
                    error_log('Error fetching image variants: ' . $e->getMessage());
                }

                if (str_starts_with((string)$bestUrl, '/storage/')) {
                    $bestUrl = $img['original_path'];
                }
                if (str_starts_with((string)$lightboxUrl, '/storage/')) {
                    $lightboxUrl = $img['original_path'];
                }

                $images[] = [
                    'id' => (int)$img['id'],
                    'url' => $bestUrl,
                    'lightbox_url' => $lightboxUrl,
                    'alt' => $img['alt_text'] ?: $album['title'],
                    'width' => (int)($img['width'] ?? 1200),
                    'height' => (int)($img['height'] ?? 800),
                    'caption' => $img['caption'] ?? '',
                    'custom_camera' => $img['custom_camera'] ?? '',
                    'camera_name' => $img['camera_name'] ?? '',
                    'custom_lens' => $img['custom_lens'] ?? '',
                    'lens_name' => $img['lens_name'] ?? '',
                    'custom_film' => $img['custom_film'] ?? '',
                    'film_name' => $img['film_name'] ?? '',
                    'developer_name' => $img['developer_name'] ?? '',
                    'lab_name' => $img['lab_name'] ?? '',
                    'location_name' => $img['location_name'] ?? '',
                    'iso' => isset($img['iso']) ? (int)$img['iso'] : null,
                    'shutter_speed' => $img['shutter_speed'] ?? null,
                    'aperture' => isset($img['aperture']) ? (float)$img['aperture'] : null
                ];
            }

            // Render only the gallery part (not the full page)
            return $this->view->render($response, 'frontend/_gallery_content.twig', [
                'images' => $images,
                'template_settings' => $templateSettings
            ]);
            
        } catch (\Throwable $e) {
            error_log('Album Template API Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            $response->getBody()->write('Internal server error: ' . $e->getMessage());
            return $response->withStatus(500);
        }
    }

    public function category(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        $pdo = $this->db->pdo();
        
        // Get category
        $stmt = $pdo->prepare('SELECT * FROM categories WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
        $category = $stmt->fetch();
        
        if (!$category) {
            return $response->withStatus(404);
        }
        
        // Get albums in category
        $stmt = $pdo->prepare('
            SELECT a.*, c.name as category_name, c.slug as category_slug
            FROM albums a 
            JOIN categories c ON c.id = a.category_id 
            WHERE c.slug = :slug AND a.is_published = 1
            ORDER BY a.published_at DESC
        ');
        $stmt->execute([':slug' => $slug]);
        $albums = $stmt->fetchAll();
        
        foreach ($albums as &$album) {
            $album = $this->enrichAlbum($album);
        }
        
        // Get all categories for navigation
        $stmt = $pdo->prepare('SELECT * FROM categories ORDER BY sort_order ASC, name ASC');
        $stmt->execute();
        $categories = $stmt->fetchAll();
        
        return $this->view->render($response, 'frontend/category.twig', [
            'category' => $category,
            'albums' => $albums,
            'categories' => $categories,
            'parent_categories' => $this->getParentCategoriesForNavigation(),
            'page_title' => $category['name'] . ' - Portfolio',
            'meta_description' => 'Photography albums in category: ' . $category['name']
        ]);
    }

    public function tag(Request $request, Response $response, array $args): Response
    {
        $slug = $args['slug'] ?? '';
        $pdo = $this->db->pdo();
        
        // Get tag
        $stmt = $pdo->prepare('SELECT * FROM tags WHERE slug = :slug');
        $stmt->execute([':slug' => $slug]);
        $tag = $stmt->fetch();
        
        if (!$tag) {
            return $this->view->render($response->withStatus(404), 'frontend/404.twig', [
                'page_title' => '404 — Tag non trovato',
                'meta_description' => 'Tag non trovato'
            ]);
        }
        
        // Get albums with this tag
        $stmt = $pdo->prepare('
            SELECT a.*, c.name as category_name, c.slug as category_slug
            FROM albums a 
            JOIN categories c ON c.id = a.category_id 
            JOIN album_tag at ON at.album_id = a.id
            JOIN tags t ON t.id = at.tag_id
            WHERE t.slug = :slug AND a.is_published = 1
            ORDER BY a.published_at DESC
        ');
        $stmt->execute([':slug' => $slug]);
        $albums = $stmt->fetchAll();
        
        foreach ($albums as &$album) {
            $album = $this->enrichAlbum($album);
        }
        
        // Get popular tags for navigation
        $stmt = $pdo->prepare('
            SELECT t.*, COUNT(at.album_id) as albums_count
            FROM tags t 
            JOIN album_tag at ON at.tag_id = t.id
            JOIN albums a ON a.id = at.album_id AND a.is_published = 1
            GROUP BY t.id 
            ORDER BY albums_count DESC, t.name ASC 
            LIMIT 30
        ');
        $stmt->execute();
        $tags = $stmt->fetchAll();
        
        // Categories for header menu
        $navStmt = $pdo->prepare('SELECT id, name, slug FROM categories ORDER BY sort_order ASC, name ASC');
        $navStmt->execute();
        $navCategories = $navStmt->fetchAll();

        return $this->view->render($response, 'frontend/tag.twig', [
            'tag' => $tag,
            'albums' => $albums,
            'tags' => $tags,
            'categories' => $navCategories,
            'parent_categories' => $this->getParentCategoriesForNavigation(),
            'page_title' => '#' . $tag['name'] . ' - Portfolio',
            'meta_description' => 'Photography albums tagged with: ' . $tag['name']
        ]);
    }

    public function about(Request $request, Response $response): Response
    {
        $pdo = $this->db->pdo();
        // categories for header
        $navStmt = $pdo->prepare('SELECT id, name, slug FROM categories ORDER BY sort_order ASC, name ASC');
        $navStmt->execute();
        $navCategories = $navStmt->fetchAll();

        // settings
        $settings = new \App\Services\SettingsService($this->db);
        $aboutText = (string)($settings->get('about.text', '') ?? '');
        $aboutPhoto = (string)($settings->get('about.photo_url', '') ?? '');
        $aboutFooter = (string)($settings->get('about.footer_text', '') ?? '');
        $aboutSocials = (array)($settings->get('about.socials', []) ?? []);
        $aboutTitle = (string)($settings->get('about.title', 'About') ?? 'About');
        $aboutSubtitle = (string)($settings->get('about.subtitle', '') ?? '');
        $contactTitle = (string)($settings->get('about.contact_title', 'Contatti') ?? 'Contatti');
        $contactIntro = (string)($settings->get('about.contact_intro', '') ?? '');

        $q = $request->getQueryParams();
        $contactSent = isset($q['sent']);
        $contactError = isset($q['error']);

        return $this->view->render($response, 'frontend/about.twig', [
            'categories' => $navCategories,
            'parent_categories' => $this->getParentCategoriesForNavigation(),
            'page_title' => $aboutTitle . ' — Portfolio',
            'meta_description' => 'About the photographer',
            'about_text' => $aboutText,
            'about_photo_url' => $aboutPhoto,
            'about_footer_text' => $aboutFooter,
            'about_socials' => $aboutSocials,
            'about_title' => $aboutTitle,
            'about_subtitle' => $aboutSubtitle,
            'contact_title' => $contactTitle,
            'contact_intro' => $contactIntro,
            'contact_sent' => $contactSent,
            'contact_error' => $contactError,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function aboutContact(Request $request, Response $response): Response
    {
        $data = (array)($request->getParsedBody() ?? []);
        $name = trim((string)($data['name'] ?? ''));
        $email = trim((string)($data['email'] ?? ''));
        $message = trim((string)($data['message'] ?? ''));

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $message === '') {
            return $response->withHeader('Location', $this->redirect('/about?error=1'))->withStatus(302);
        }

        $settings = new \App\Services\SettingsService($this->db);
        $to = (string)($settings->get('about.contact_email', '') ?? '');
        if ($to === '') {
            $to = (string)(\envv('CONTACT_EMAIL', \envv('MAIL_TO', 'webmaster@localhost')));
        }
        $subjectPrefix = (string)($settings->get('about.contact_subject', 'Portfolio') ?? 'Portfolio');
        $subject = '[' . $subjectPrefix . '] Nuovo messaggio da ' . $name;
        // Basic header-safe sanitization
        $safeName = str_replace(["\r","\n"], ' ', $name);
        $safeEmail = str_replace(["\r","\n"], ' ', $email);

        $body = "Nome: {$safeName}\nEmail: {$safeEmail}\n\nMessaggio:\n{$message}\n";
        $headers = 'From: ' . $safeName . ' <' . $safeEmail . '>' . "\r\n" .
                   'Reply-To: ' . $safeEmail . "\r\n" .
                   'Content-Type: text/plain; charset=UTF-8';

        @mail($to, $subject, $body, $headers);
        $settings = new \App\Services\SettingsService($this->db);
        $slug = (string)($settings->get('about.slug', 'about') ?? 'about');
        if ($slug === '') { $slug = 'about'; }
        return $response->withHeader('Location', $this->redirect('/' . $slug . '?sent=1'))->withStatus(302);
    }

    private function enrichAlbum(array $album): array
    {
        $pdo = $this->db->pdo();
        
        // Cover image
        if ($album['cover_image_id']) {
            $stmt = $pdo->prepare('SELECT * FROM images WHERE id = :id');
            $stmt->execute([':id' => $album['cover_image_id']]);
            $cover = $stmt->fetch();
            
            if ($cover) {
                $variantsStmt = $pdo->prepare('SELECT * FROM image_variants WHERE image_id = :id ORDER BY variant ASC');
                $variantsStmt->execute([':id' => $cover['id']]);
                $cover['variants'] = $variantsStmt->fetchAll();
                $album['cover'] = $cover;
            }
        }
        
        // Tags
        $stmt = $pdo->prepare('
            SELECT t.* FROM tags t 
            JOIN album_tag at ON at.tag_id = t.id 
            WHERE at.album_id = :id 
            ORDER BY t.name ASC
        ');
        $stmt->execute([':id' => $album['id']]);
        $album['tags'] = $stmt->fetchAll();
        // Locations (if present)
        try {
            $locStmt = $pdo->prepare('SELECT l.id, l.name, l.slug FROM album_location al JOIN locations l ON l.id = al.location_id WHERE al.album_id = :id ORDER BY l.name');
            $locStmt->execute([':id' => $album['id']]);
            $album['locations'] = $locStmt->fetchAll() ?: [];
        } catch (\Throwable) {
            $album['locations'] = [];
        }
        
        // Images count
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM images WHERE album_id = :id');
        $stmt->execute([':id' => $album['id']]);
        $album['images_count'] = (int)$stmt->fetchColumn();
        
        return $album;
    }

    private function formatExifForDisplay(array $exif, array $image): array
    {
        $display = [];
        
        // Camera
        if (!empty($exif['Make']) && !empty($exif['Model'])) {
            $display['camera'] = trim($exif['Make'] . ' ' . $exif['Model']);
        } elseif ($image['custom_camera']) {
            $display['camera'] = $image['custom_camera'];
        }
        
        // Lens
        if (!empty($exif['LensModel'])) {
            $display['lens'] = $exif['LensModel'];
        } elseif ($image['custom_lens']) {
            $display['lens'] = $image['custom_lens'];
        }
        
        // Exposure
        if ($image['aperture']) {
            $display['aperture'] = 'f/' . number_format($image['aperture'], 1);
        }
        
        if ($image['shutter_speed']) {
            $display['shutter'] = $this->formatShutterSpeed($image['shutter_speed']);
        }
        
        if ($image['iso']) {
            $display['iso'] = 'ISO ' . $image['iso'];
        }
        
        // Film/Process
        if ($image['custom_film']) {
            $display['film'] = $image['custom_film'];
        }
        
        if ($image['process']) {
            $display['process'] = ucfirst($image['process']);
        }
        
        return $display;
    }

    private function formatShutterSpeed(string $speed): string
    {
        if (strpos($speed, '/') !== false) {
            return $speed;
        }
        
        $f = (float)$speed;
        if ($f >= 1) {
            return (int)$f . 's';
        } else {
            return '1/' . (int)round(1 / $f);
        }
    }
    
    private function buildCategoryHierarchy(array $allCategories): array
    {
        $byParent = [];
        foreach ($allCategories as $cat) {
            $parentId = (int)($cat['parent_id'] ?? 0);
            if (!isset($byParent[$parentId])) {
                $byParent[$parentId] = [];
            }
            $byParent[$parentId][] = $cat;
        }
        
        $parentCategories = $byParent[0] ?? [];
        
        // Add children to parent categories
        foreach ($parentCategories as &$parent) {
            $parent['children'] = $byParent[$parent['id']] ?? [];
            // Ensure children have the albums_count for filtering
            foreach ($parent['children'] as &$child) {
                if (!isset($child['albums_count'])) {
                    $child['albums_count'] = 0;
                }
            }
        }
        
        return $parentCategories;
    }
    
    /**
     * Get parent categories with album counts for navigation
     */
    private function getParentCategoriesForNavigation(): array
    {
        $pdo = $this->db->pdo();
        
        // Get categories for navigation with hierarchy (simplified query for better reliability)
        $stmt = $pdo->prepare('
            SELECT c.*, COUNT(a.id) as albums_count
            FROM categories c 
            LEFT JOIN albums a ON a.category_id = c.id AND a.is_published = 1
            GROUP BY c.id 
            ORDER BY c.sort_order ASC, c.name ASC
        ');
        $stmt->execute();
        $allCategories = $stmt->fetchAll();
        
        // Build hierarchy
        $parentCategories = $this->buildCategoryHierarchy($allCategories);
        
        // Filter out completely empty categories (no albums and no children with albums)
        $filteredParentCategories = [];
        foreach ($parentCategories as $parent) {
            // Count albums in children
            $childrenWithAlbums = 0;
            foreach ($parent['children'] as $child) {
                if ($child['albums_count'] > 0) {
                    $childrenWithAlbums++;
                }
            }
            
            // Keep parent if it has albums OR has children with albums
            if ($parent['albums_count'] > 0 || $childrenWithAlbums > 0) {
                // Filter children to only those with albums (if parent has no albums)
                if ($parent['albums_count'] == 0) {
                    $parent['children'] = array_filter($parent['children'], function($child) {
                        return $child['albums_count'] > 0;
                    });
                }
                $filteredParentCategories[] = $parent;
            }
        }
        
        return $filteredParentCategories;
    }
}
